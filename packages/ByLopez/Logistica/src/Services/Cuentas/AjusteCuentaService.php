<?php

namespace ByLopez\Logistica\Services\Cuentas;

use ByLopez\Logistica\Enums\ConceptoMovimientoCuenta as Concepto;
use ByLopez\Logistica\Enums\EstadoEventoLogistico as Estado;
use ByLopez\Logistica\Enums\EstadoMovimientoCuenta;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Models\Guia;
use ByLopez\Logistica\Models\MovimientoCuenta;
use ByLopez\Logistica\Models\Transportadora;
use ByLopez\Logistica\Services\Entregas\RegistrarEventoEnvio;
use ByLopez\Logistica\Support\AutorizacionLogistica;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Webkul\User\Contracts\Admin;

/**
 * Movimientos MANUALES de la cuenta corriente (solo ADMIN, `logistica.cuentas.ajustar`). Nunca se edita un saldo ni un
 * movimiento: todo es un movimiento NUEVO, con motivo obligatorio, quien y cuando, que entra en la cuenta abierta (si la
 * cuenta ya se liquido, en la siguiente liquidacion).
 *
 * - registrarAjuste(): a favor de ByLopez (+) o del transportador (-). Tambien el COSTO DE DEVOLUCION (-), que no tiene
 *   tarifa aprobada: existe solo por este registro explicito, ligado a una guia de esa transportadora con devolucion.
 * - confirmarRevision(): resuelve un envio «Requiere revision» registrando el valor REAL del recaudo o del servicio
 *   (puede ser 0: queda la decision). Usa la misma clave del envio que el generador automatico: nunca se duplica.
 */
class AjusteCuentaService
{
    public const MAX_MONTO = 99999999.99;

    public const A_FAVOR_BYLOPEZ = 'a_favor_bylopez';

    public const A_FAVOR_TRANSPORTADOR = 'a_favor_transportador';

    public function __construct(
        protected AutorizacionLogistica $autorizacion,
        protected GeneradorMovimientosCuenta $generador,
    ) {}

    /** @param  array{tipo?: mixed, concepto?: mixed, valor?: mixed, motivo?: mixed, referencia?: mixed, guia_id?: mixed, idempotency_key?: mixed}  $datos */
    public function registrarAjuste(?Admin $actor, int $transportadoraId, array $datos): MovimientoCuenta
    {
        $this->exigirPermiso($actor);

        $clave = $this->clave($datos['idempotency_key'] ?? null);
        $transportadora = $this->cuenta($transportadoraId);
        $concepto = Concepto::tryFrom((string) ($datos['concepto'] ?? Concepto::AJUSTE->value));

        if (! in_array($concepto, [Concepto::AJUSTE, Concepto::COSTO_DEVOLUCION], true)) {
            throw new Invalida(Invalida::DATOS_INVALIDOS);
        }

        // Un costo de devolucion siempre es a favor del transportador.
        $tipo = $concepto === Concepto::COSTO_DEVOLUCION ? self::A_FAVOR_TRANSPORTADOR : (string) ($datos['tipo'] ?? '');

        if (! in_array($tipo, [self::A_FAVOR_BYLOPEZ, self::A_FAVOR_TRANSPORTADOR], true)) {
            throw new Invalida(Invalida::DATOS_INVALIDOS);
        }

        $valor = $this->monto($datos['valor'] ?? null, false);
        $motivo = $this->texto($datos['motivo'] ?? null, 500) ?? throw new Invalida(Invalida::MOTIVO_REQUERIDO);
        $referencia = $this->texto($datos['referencia'] ?? null, 120);
        $guia = $this->guiaDeLaCuenta($datos['guia_id'] ?? null, $transportadora, $concepto === Concepto::COSTO_DEVOLUCION);
        $importe = $tipo === self::A_FAVOR_BYLOPEZ ? $valor : -$valor;

        $huella = hash('sha256', json_encode(['ajuste', $transportadora->id, $concepto->value, $importe, $motivo, $referencia, $guia?->id]));
        $claveMovimiento = 'ajuste:'.$clave;

        if ($previo = $this->previo($claveMovimiento, $huella)) {
            return $previo;
        }

        try {
            return MovimientoCuenta::query()->create([
                'transportadora_id' => (int) $transportadora->id,
                'order_id' => $guia?->order_id,
                'shipment_id' => $guia?->shipment_id,
                'guia_id' => $guia?->id,
                'concepto' => $concepto,
                'importe' => $importe,
                'origen_tipo' => MovimientoCuenta::ORIGEN_AJUSTE,
                'origen_id' => null,
                'fecha_operativa' => now()->toDateString(),
                'descripcion' => mb_substr($motivo, 0, 255),
                'motivo' => $motivo,
                'referencia' => $referencia,
                'estado' => EstadoMovimientoCuenta::ABIERTO,
                'idempotency_key' => $claveMovimiento,
                'huella' => $huella,
                'actor_admin_id' => (int) $actor->id,
                'actor_name_snapshot' => mb_substr((string) $actor->name, 0, 191),
            ]);
        } catch (UniqueConstraintViolationException $e) {
            return $this->previo($claveMovimiento, $huella) ?? throw $e;
        }
    }

    public function confirmarRevision(?Admin $actor, int $shipmentId, string $concepto, mixed $valor, mixed $motivo, mixed $idempotencyKey): MovimientoCuenta
    {
        $this->exigirPermiso($actor);

        $clave = $this->clave($idempotencyKey);
        $concepto = Concepto::tryFrom($concepto);

        if (! in_array($concepto, [Concepto::RECAUDO, Concepto::SERVICIO], true)) {
            throw new Invalida(Invalida::DATOS_INVALIDOS);
        }

        $valor = $this->monto($valor, true);
        $motivo = $this->texto($motivo, 500);
        $huella = hash('sha256', json_encode(['revision', $clave, $shipmentId, $concepto->value, $valor, $motivo]));

        return DB::transaction(function () use ($actor, $shipmentId, $concepto, $valor, $motivo, $huella) {
            if (! DB::table('shipments')->where('id', $shipmentId)->lockForUpdate()->exists()) {
                throw new Invalida(Invalida::ENVIO_INEXISTENTE);
            }

            // Reintento de la MISMA confirmacion (doble clic, timeout): devuelve la ya registrada.
            if ($previo = MovimientoCuenta::query()->where('shipment_id', $shipmentId)->where('concepto', $concepto->value)
                ->where('origen_tipo', MovimientoCuenta::ORIGEN_REVISION)->where('huella', $huella)->first()) {
                return $previo;
            }

            $guia = Guia::query()->where('shipment_id', $shipmentId)->first() ?? throw new Invalida(Invalida::GUIA_INEXISTENTE);
            $transportadoraId = (int) Transportadora::query()->where('codigo', $guia->transportadora_codigo)->value('id');
            $pendiente = collect($this->generador->revisiones([$transportadoraId]))->first(fn ($r) => $r['shipment_id'] === $shipmentId && $r['concepto'] === $concepto->value);

            if ($pendiente === null) {
                throw new Invalida(Invalida::REVISION_NO_APLICA);
            }

            if ($concepto === Concepto::RECAUDO && $valor > (float) $guia->recaudo_esperado + 0.004) {
                throw new Invalida(Invalida::VALOR_INVALIDO, ['maximo' => (float) $guia->recaudo_esperado]);
            }

            // Igual al recaudo sugerido (devolucion valorada con el pedido) = basta confirmar; cualquier otro valor exige motivo.
            $sugerido = $pendiente['recaudo_sugerido'] ?? null;
            $coincide = $sugerido !== null && abs($valor - $sugerido) < 0.005;

            if ($motivo === null && ! $coincide) {
                throw new Invalida(Invalida::MOTIVO_REQUERIDO);
            }

            $descripcion = $motivo ?? (string) trans('logistica::app.cuentas.revision.confirmado_sugerido');

            return MovimientoCuenta::query()->create([
                'transportadora_id' => $pendiente['transportadora_id'],
                'order_id' => (int) $guia->order_id,
                'shipment_id' => $shipmentId,
                'guia_id' => (int) $guia->id,
                'concepto' => $concepto,
                'importe' => $concepto === Concepto::RECAUDO ? $valor : -$valor,
                'origen_tipo' => MovimientoCuenta::ORIGEN_REVISION,
                'origen_id' => $shipmentId,
                'fecha_operativa' => now()->toDateString(),
                'descripcion' => mb_substr($descripcion, 0, 255),
                'motivo' => $motivo,
                'estado' => EstadoMovimientoCuenta::ABIERTO,
                'idempotency_key' => GeneradorMovimientosCuenta::clave($shipmentId, $concepto, GeneradorMovimientosCuenta::siguienteNumero($shipmentId, $concepto)),
                'huella' => $huella,
                'actor_admin_id' => (int) $actor->id,
                'actor_name_snapshot' => mb_substr((string) $actor->name, 0, 191),
            ]);
        });
    }

    /** Transportadora con cuenta: liquida hoy o tiene movimientos (una cuenta que dejo de liquidar se puede seguir cerrando). */
    public function cuenta(int $transportadoraId): Transportadora
    {
        $t = Transportadora::query()->find($transportadoraId);

        if ($t === null || (! $t->liquida_cuenta_corriente && ! MovimientoCuenta::query()->where('transportadora_id', $t->id)->exists())) {
            throw new Invalida(Invalida::CUENTA_INEXISTENTE);
        }

        return $t;
    }

    private function guiaDeLaCuenta(mixed $guiaId, Transportadora $transportadora, bool $exigeDevolucion): ?Guia
    {
        if ($guiaId === null || $guiaId === '') {
            if ($exigeDevolucion) {
                throw new Invalida(Invalida::GUIA_NO_DE_TRANSPORTADORA);
            }

            return null;
        }

        $guia = Guia::query()->find((int) $guiaId);

        if ($guia === null || $guia->transportadora_codigo !== $transportadora->codigo) {
            throw new Invalida(Invalida::GUIA_NO_DE_TRANSPORTADORA);
        }

        if ($exigeDevolucion && ! DB::table('bylopez_lg_retornos')->where('shipment_id', $guia->shipment_id)->where('estado', Estado::CONFIRMADO->value)->exists()) {
            throw new Invalida(Invalida::GUIA_NO_DE_TRANSPORTADORA, ['motivo' => 'sin_devolucion']);
        }

        return $guia;
    }

    private function previo(string $clave, string $huella): ?MovimientoCuenta
    {
        $previo = MovimientoCuenta::query()->where('idempotency_key', $clave)->first();

        if ($previo === null) {
            return null;
        }

        if (! hash_equals((string) $previo->huella, $huella)) {
            throw new Invalida(Invalida::IDEMPOTENCIA_CONFLICTO, ['id' => $previo->id]);
        }

        return $previo;
    }

    private function exigirPermiso(?Admin $actor): void
    {
        if (! $this->autorizacion->tienePermiso($actor, AutorizacionLogistica::PERMISO_CUENTAS_AJUSTAR)) {
            throw new Invalida(Invalida::SIN_PERMISO);
        }
    }

    private function clave(mixed $valor): string
    {
        $clave = trim((string) $valor);

        if (! preg_match(RegistrarEventoEnvio::PATRON_IDEMPOTENCIA, $clave)) {
            throw new Invalida(Invalida::IDEMPOTENCIA_INVALIDA);
        }

        return $clave;
    }

    /** Monto con punto decimal (el navegador envia el valor crudo de la mascara, nunca el texto formateado). */
    private function monto(mixed $valor, bool $permiteCero): float
    {
        $texto = trim((string) $valor);

        if (! preg_match('/^\d{1,8}(\.\d{1,2})?$/', $texto)) {
            throw new Invalida(Invalida::VALOR_INVALIDO);
        }

        $numero = round((float) $texto, 2);

        if ($numero > self::MAX_MONTO || (! $permiteCero && $numero <= 0)) {
            throw new Invalida(Invalida::VALOR_INVALIDO);
        }

        return $numero;
    }

    private function texto(mixed $valor, int $max): ?string
    {
        $texto = trim((string) $valor);

        return $texto === '' ? null : mb_substr($texto, 0, $max);
    }
}
