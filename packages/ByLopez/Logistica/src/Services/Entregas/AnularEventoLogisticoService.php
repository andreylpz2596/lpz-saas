<?php

namespace ByLopez\Logistica\Services\Entregas;

use ByLopez\Logistica\Contracts\GuardaAnulacionRecepcion;
use ByLopez\Logistica\Enums\EstadoEventoLogistico as Estado;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Models\Entrega;
use ByLopez\Logistica\Models\RecepcionDevolucion;
use ByLopez\Logistica\Models\RecepcionDevolucionItem;
use ByLopez\Logistica\Models\Retorno;
use ByLopez\Logistica\Models\ValidacionEntrega;
use ByLopez\Logistica\Services\Cuentas\GeneradorMovimientosCuenta;
use ByLopez\Logistica\Support\AutorizacionLogistica;
use Illuminate\Support\Facades\DB;
use Webkul\User\Contracts\Admin;

/**
 * Anulacion POR CORRECCION (bloque 19): un registro equivocado no se edita ni se borra. Se marca `anulado` con
 * motivo obligatorio, quien y cuando; sus unidades vuelven a estar pendientes y se puede registrar el evento correcto.
 * Solo ADMIN / SUPERVISION (`logistica.entregas.anular`).
 */
class AnularEventoLogisticoService
{
    public function __construct(protected AutorizacionLogistica $autorizacion, protected GeneradorMovimientosCuenta $cuentas) {}

    public function anularEntrega(?Admin $actor, int $entregaId, ?string $motivo): Entrega
    {
        return $this->anular($actor, Entrega::class, $entregaId, $motivo);
    }

    public function anularRetorno(?Admin $actor, int $retornoId, ?string $motivo): Retorno
    {
        return $this->anular($actor, Retorno::class, $retornoId, $motivo);
    }

    /**
     * Anula una RECEPCION de devolucion. Solo mientras ninguna de sus unidades haya vuelto al inventario: una recepcion con
     * unidades ya reintegradas NO se anula (restar stock en silencio seria peligroso; si hace falta revertir, es un flujo
     * administrativo aparte con su propia auditoria).
     */
    public function anularRecepcion(?Admin $actor, int $recepcionId, ?string $motivo): RecepcionDevolucion
    {
        return $this->anular($actor, RecepcionDevolucion::class, $recepcionId, $motivo);
    }

    /** @param  class-string<Entrega|Retorno|RecepcionDevolucion>  $modelo */
    private function anular(?Admin $actor, string $modelo, int $id, ?string $motivo): Entrega|Retorno|RecepcionDevolucion
    {
        if (! $this->autorizacion->tienePermiso($actor, AutorizacionLogistica::PERMISO_ANULAR)) {
            throw new Invalida(Invalida::SIN_PERMISO);
        }

        $motivo = trim((string) $motivo);

        if ($motivo === '') {
            throw new Invalida(Invalida::MOTIVO_REQUERIDO);
        }

        return DB::transaction(function () use ($actor, $modelo, $id, $motivo) {
            $registro = $modelo::query()->lockForUpdate()->find($id) ?? throw new Invalida(Invalida::REGISTRO_INEXISTENTE);

            if (! $registro->vigente()) {
                throw new Invalida(Invalida::YA_ANULADO, ['id' => $id]);
            }

            // Con la entrega VALIDADA nada de lo que la sostiene se anula (la comision depende de ella): primero se anula la validacion.
            if (ValidacionEntrega::query()->where('shipment_id', $registro->shipment_id)->where('estado', Estado::CONFIRMADO->value)->exists()) {
                throw new Invalida(Invalida::ENVIO_VALIDADO, ['id' => $id]);
            }

            // Una devolucion con recepcion vigente no se anula directamente: primero hay que anular la recepcion (y solo se puede si no hubo reingreso).
            if ($registro instanceof Retorno
                && RecepcionDevolucion::query()->where('retorno_id', $id)->where('estado', Estado::CONFIRMADO->value)->exists()) {
                throw new Invalida(Invalida::RETORNO_CON_RECEPCION, ['id' => $id]);
            }

            if ($registro instanceof RecepcionDevolucion
                && RecepcionDevolucionItem::query()->where('recepcion_id', $id)->where('reingreso_estado', RecepcionDevolucionItem::REINGRESADO)->exists()) {
                throw new Invalida(Invalida::RECEPCION_YA_REINGRESADA, ['id' => $id]);
            }

            // D10: la recepcion es una fuente de credito del cliente; se bloquea el pedido antes de escribir (mismo orden que el credito).
            $guardaCredito = $registro instanceof RecepcionDevolucion ? app(GuardaAnulacionRecepcion::class) : null;
            $guardaCredito?->antes((int) $registro->order_id);

            $registro->forceFill([
                'estado' => Estado::ANULADO,
                'motivo_anulacion' => mb_substr($motivo, 0, 500),
                'anulada_por_admin_id' => (int) $actor->id,
                'anulada_por_name_snapshot' => mb_substr((string) $actor->name, 0, 191),
                'anulada_en' => now(),
            ])->save();

            // Con la recepcion ya anulada (sin commit): si el credito consumido pierde su respaldo, se rechaza y todo se deshace.
            $guardaCredito?->despues((int) $registro->order_id);

            // Cuenta corriente: si el envio deja de estar entregado, lo generado se REVIERTE (nunca se edita ni se borra).
            if (! $registro instanceof RecepcionDevolucion) {
                $this->cuentas->sincronizarEnvio((int) $registro->shipment_id, $actor);
            }

            return $registro;
        });
    }
}
