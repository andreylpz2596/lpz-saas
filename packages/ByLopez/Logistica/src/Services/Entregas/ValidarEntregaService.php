<?php

namespace ByLopez\Logistica\Services\Entregas;

use ByLopez\Logistica\Enums\EstadoEventoLogistico as Estado;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Models\RecepcionDevolucion;
use ByLopez\Logistica\Models\Retorno;
use ByLopez\Logistica\Models\ValidacionEntrega;
use ByLopez\Logistica\Support\AutorizacionLogistica;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Webkul\User\Contracts\Admin;

/**
 * VALIDACION DE ENTREGA (Entregas · Fase D): el administrador ratifica que el envio quedo entregado.
 *
 *   entrega REGISTRADA (Bodega / domiciliario)  ≠  entrega VALIDADA (esta operacion)  ≠  PAGO (Cartera)
 *
 * Es el hito que habilita la comision del asesor y el cierre del pedido. NO escribe pagos, facturas ni el estado del
 * pedido: la factura pasa a pagada y el pedido a completado SOLO cuando Cartera confirma el dinero (via oficial de
 * Bagisto). Validar no se salta ese control.
 *
 * Condiciones (revalidadas en el servidor bajo bloqueo del Shipment): al menos una unidad entregada · ninguna unidad
 * pendiente (todo lo despachado quedo entregado o devuelto) · ninguna devolucion vigente sin recibir en Bodega.
 * Idempotente por `idempotency_key` + huella y por UNIQUE de validacion vigente por envio.
 */
class ValidarEntregaService
{
    public function __construct(
        protected AutorizacionLogistica $autorizacion,
        protected ConsultaEntregaService $consulta,
    ) {}

    /** @return array{validacion: ValidacionEntrega, ya_existia: bool} */
    public function validar(?Admin $actor, int $shipmentId, string $idempotencyKey, ?string $observacion = null): array
    {
        $this->exigirPermiso($actor);

        $clave = trim($idempotencyKey);

        if (! preg_match(RegistrarEventoEnvio::PATRON_IDEMPOTENCIA, $clave)) {
            throw new Invalida(Invalida::IDEMPOTENCIA_INVALIDA);
        }

        $observacion = $this->texto($observacion);
        $huella = hash('sha256', json_encode(['validacion', $shipmentId, $observacion]));

        if ($previo = $this->previo($clave, $huella)) {
            return ['validacion' => $previo, 'ya_existia' => true];
        }

        try {
            return DB::transaction(function () use ($actor, $shipmentId, $clave, $huella, $observacion) {
                // Mismo orden de bloqueo que los demas eventos del envio: el Shipment primero.
                $envio = DB::table('shipments')->where('id', $shipmentId)->lockForUpdate()->first()
                    ?? throw new Invalida(Invalida::ENVIO_INEXISTENTE);

                if ($previo = $this->previo($clave, $huella)) {
                    return ['validacion' => $previo, 'ya_existia' => true];
                }

                if (ValidacionEntrega::query()->where('shipment_id', $shipmentId)->where('estado', Estado::CONFIRMADO->value)->exists()) {
                    throw new Invalida(Invalida::VALIDACION_YA_REGISTRADA, ['envio' => $shipmentId]);
                }

                $saldos = $this->consulta->saldosDeEnvio($shipmentId);
                $entregadas = array_sum(array_column($saldos, 'entregadas'));
                $devueltas = array_sum(array_column($saldos, 'retornadas'));

                if ($entregadas <= 0) {
                    throw new Invalida(Invalida::VALIDACION_SIN_ENTREGAS);
                }

                if (array_sum(array_column($saldos, 'pendientes')) > 0) {
                    throw new Invalida(Invalida::VALIDACION_CON_PENDIENTES);
                }

                $sinRecibir = Retorno::query()->where('shipment_id', $shipmentId)->where('estado', Estado::CONFIRMADO->value)
                    ->whereNotIn('id', RecepcionDevolucion::query()->where('estado', Estado::CONFIRMADO->value)->select('retorno_id'))
                    ->exists();

                if ($sinRecibir) {
                    throw new Invalida(Invalida::VALIDACION_DEVOLUCION_SIN_RECIBIR);
                }

                $validacion = ValidacionEntrega::query()->create([
                    'shipment_id' => (int) $envio->id,
                    'order_id' => (int) $envio->order_id,
                    'observacion' => $observacion,
                    'actor_admin_id' => (int) $actor->id,
                    'actor_name_snapshot' => mb_substr((string) $actor->name, 0, 191),
                    'entregadas' => $entregadas,
                    'devueltas' => $devueltas,
                    'estado' => Estado::CONFIRMADO,
                    'idempotency_key' => $clave,
                    'huella' => $huella,
                ]);

                return ['validacion' => $validacion, 'ya_existia' => false];
            });
        } catch (UniqueConstraintViolationException) {
            $previo = $this->previo($clave, $huella);

            return $previo ? ['validacion' => $previo, 'ya_existia' => true]
                : throw new Invalida(Invalida::VALIDACION_YA_REGISTRADA, ['envio' => $shipmentId]);
        }
    }

    /** Anulacion por correccion, auditada y con motivo. Solo quien puede validar. El envio vuelve a «por validar». */
    public function anular(?Admin $actor, int $validacionId, ?string $motivo): ValidacionEntrega
    {
        $this->exigirPermiso($actor);

        $motivo = $this->texto($motivo) ?? throw new Invalida(Invalida::MOTIVO_REQUERIDO);

        return DB::transaction(function () use ($actor, $validacionId, $motivo) {
            $v = ValidacionEntrega::query()->lockForUpdate()->find($validacionId) ?? throw new Invalida(Invalida::REGISTRO_INEXISTENTE);

            if (! $v->vigente()) {
                throw new Invalida(Invalida::YA_ANULADO, ['id' => $validacionId]);
            }

            $v->forceFill([
                'estado' => Estado::ANULADO,
                'motivo_anulacion' => mb_substr($motivo, 0, 500),
                'anulada_por_admin_id' => (int) $actor->id,
                'anulada_por_name_snapshot' => mb_substr((string) $actor->name, 0, 191),
                'anulada_en' => now(),
            ])->save();

            return $v;
        });
    }

    private function previo(string $clave, string $huella): ?ValidacionEntrega
    {
        $previo = ValidacionEntrega::query()->where('idempotency_key', $clave)->first();

        if ($previo === null) {
            return null;
        }

        if (! hash_equals($previo->huella, $huella)) {
            throw new Invalida(Invalida::IDEMPOTENCIA_CONFLICTO, ['id' => $previo->id]);
        }

        return $previo;
    }

    private function exigirPermiso(?Admin $actor): void
    {
        if (! $this->autorizacion->tienePermiso($actor, AutorizacionLogistica::PERMISO_VALIDAR)) {
            throw new Invalida(Invalida::SIN_PERMISO);
        }
    }

    private function texto(?string $valor): ?string
    {
        $valor = trim((string) $valor);

        return $valor === '' ? null : mb_substr($valor, 0, 500);
    }
}
