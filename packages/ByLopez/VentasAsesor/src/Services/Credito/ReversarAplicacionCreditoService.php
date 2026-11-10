<?php

namespace ByLopez\VentasAsesor\Services\Credito;

use ByLopez\VentasAsesor\Enums\EstadoPago;
use ByLopez\VentasAsesor\Enums\TipoEventoPago;
use ByLopez\VentasAsesor\Exceptions\OperacionCreditoInvalidaException as Rechazo;
use ByLopez\VentasAsesor\Models\AplicacionPago;
use ByLopez\VentasAsesor\Models\CreditoClienteMovimiento as Mov;
use ByLopez\VentasAsesor\Models\Pago;
use ByLopez\VentasAsesor\Models\PagoEvento;
use ByLopez\VentasAsesor\Services\Cartera\CarteraConsultaService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use ByLopez\VentasAsesor\Support\Cartera\FechaCorteCartera;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Webkul\User\Contracts\Admin as AdminContract;

/**
 * D10 V1 / PAG-I — REVERSAR una aplicacion de credito. Nada se borra ni se edita (salvo el estado del Pago, transicion ya permitida
 * `confirmado → reversado`):
 *
 *  - El Pago original PERMANECE (monto intacto) y pasa a `reversado` con su motivo.
 *  - Su aplicacion deja de contar (`anulada_en`, `anulada_por_admin_id`).
 *  - Evento inmutable `reversado` con motivo; el ledger recibe OTRA fila `reverso_aplicacion` que apunta a la original.
 *  - El credito vuelve a estar disponible en el origen y el destino recupera su deuda; la factura vuelve a `pending` si dejo de estar
 *    satisfecha. Caja y C6 = $0 (nunca hubo dinero ni Transaction). Sin Refund. Sin doble credito: `revierte_id` es UNIQUE.
 *
 * Motivo obligatorio. Reversar un REEMBOLSO no existe en V1: representaria dinero que vuelve a ByLopez y no hay un flujo que lo pruebe.
 */
class ReversarAplicacionCreditoService extends OperacionCredito
{
    public function __construct(
        AutorizacionVentasAsesor $autorizacion,
        CarteraConsultaService $cartera,
        CreditoClienteService $credito,
        FechaCorteCartera $fechaCorte,
        protected SatisfaccionFacturaService $facturas,
    ) {
        parent::__construct($autorizacion, $cartera, $credito, $fechaCorte);
    }

    /**
     * @return array{reverso_id: int, movimiento_id: int, pago_id: int, monto: float, duplicado: bool}
     *
     * @throws Rechazo
     */
    public function reversar(AdminContract $actor, int $movimientoId, string $motivo, string $idempotencyKey): array
    {
        $this->exigir($actor, AutorizacionVentasAsesor::PERMISO_CREDITO_REVERSAR);

        $clave = 'rev:'.$this->clave($idempotencyKey);
        $motivo = $this->motivo($motivo, true);
        $huella = $this->huella(['mov' => $movimientoId]);

        if ($previo = $this->repeticion($clave, $huella)) {
            return $this->estable($previo);
        }

        $fila = DB::table('bylopez_va_creditos_cliente_movimientos')->where('id', $movimientoId)->first(['id', 'order_origen_id', 'order_destino_id']);

        if ($fila === null) {
            throw new Rechazo(Rechazo::MOVIMIENTO_INEXISTENTE, 'El movimiento de credito no existe.');
        }

        try {
            return DB::transaction(fn () => $this->bajoBloqueo($actor, $fila, $motivo, $clave, $huella), self::INTENTOS);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'Duplicate entry') && ($previo = $this->repeticion($clave, $huella))) {
                return $this->estable($previo);
            }

            throw $e;
        }
    }

    protected function bajoBloqueo(AdminContract $actor, object $fila, string $motivo, string $clave, string $huella): array
    {
        $this->bloquearPedidos(array_filter([(int) $fila->order_origen_id, (int) $fila->order_destino_id]));

        if ($previo = $this->repeticion($clave, $huella, true)) {
            return $this->estable($previo);
        }

        $mov = Mov::query()->lockForUpdate()->find($fila->id);

        if ($mov === null || $mov->tipo !== Mov::APLICACION) {
            throw new Rechazo(Rechazo::NO_REVERSABLE, 'Solo se puede reversar una aplicacion de credito (un reembolso no se reversa).');
        }

        if (DB::table('bylopez_va_creditos_cliente_movimientos')->where('revierte_id', $mov->id)->sharedLock()->exists()) {
            throw new Rechazo(Rechazo::YA_REVERSADO, 'Esta aplicacion de credito ya fue reversada.');
        }

        $pago = Pago::query()->lockForUpdate()->findOrFail($mov->pago_id);

        if (! $pago->estado->puedeTransicionarA(EstadoPago::REVERSADO)) {
            throw new Rechazo(Rechazo::NO_REVERSABLE, 'El pago de esta aplicacion no se puede reversar.', ['estado' => $pago->estado->value]);
        }

        $pago->forceFill(['estado' => EstadoPago::REVERSADO->value, 'motivo_revision' => mb_substr($motivo, 0, 500)])->save();

        $anuladas = [];

        AplicacionPago::query()->where('pago_id', $pago->id)->whereNull('anulada_en')->lockForUpdate()->get()->each(function (AplicacionPago $a) use ($actor, &$anuladas) {
            $a->forceFill(['anulada_en' => now(), 'anulada_por_admin_id' => (int) $actor->id])->save();
            $anuladas[] = ['order_id' => (int) $a->order_id, 'monto' => (float) $a->monto];
        });

        PagoEvento::query()->create([
            'pago_id' => $pago->id, 'tipo' => TipoEventoPago::REVERSADO->value, 'actor_admin_id' => (int) $actor->id,
            'mecanismo' => 'ventas_asesor', 'motivo' => $motivo,
            'datos' => ['compensacion' => true, 'movimiento_id' => (int) $mov->id, 'aplicaciones_anuladas' => $anuladas, 'order_origen_id' => (int) $mov->order_origen_id],
        ]);

        $reverso = $this->registrarMovimiento([
            'customer_id' => $mov->customer_id, 'tipo' => Mov::REVERSO_APLICACION, 'order_origen_id' => $mov->order_origen_id, 'order_destino_id' => $mov->order_destino_id,
            'monto' => $mov->monto, 'pago_id' => $pago->id, 'aplicacion_pago_id' => $mov->aplicacion_pago_id, 'revierte_id' => $mov->id,
            'motivo' => $motivo, 'actor_admin_id' => (int) $actor->id, 'actor_nombre' => $this->nombre($actor),
            'idempotency_key' => $clave, 'huella_solicitud' => $huella, 'datos' => ['aplicaciones_anuladas' => $anuladas],
        ]);

        $this->facturas->sincronizar((int) $mov->order_destino_id);

        return ['reverso_id' => (int) $reverso->id, 'movimiento_id' => (int) $mov->id, 'pago_id' => (int) $pago->id, 'monto' => (float) $mov->monto, 'duplicado' => false];
    }

    /** @return array{reverso_id: int, movimiento_id: int, pago_id: int, monto: float, duplicado: bool} */
    protected function estable(object $r): array
    {
        return ['reverso_id' => (int) $r->id, 'movimiento_id' => (int) $r->revierte_id, 'pago_id' => (int) $r->pago_id, 'monto' => (float) $r->monto, 'duplicado' => true];
    }
}
