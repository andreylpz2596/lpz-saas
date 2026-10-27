<?php

namespace ByLopez\VentasAsesor\Services\Cartera;

use ByLopez\VentasAsesor\DataObjects\ResultadoRevisionPago;
use ByLopez\VentasAsesor\Enums\EstadoPago;
use ByLopez\VentasAsesor\Enums\MecanismoCreacion;
use ByLopez\VentasAsesor\Enums\TipoEventoPago;
use ByLopez\VentasAsesor\Exceptions\RevisionPagoInvalidaException as Rechazo;
use ByLopez\VentasAsesor\Models\PagoEvento;
use Illuminate\Support\Facades\DB;
use Webkul\User\Contracts\Admin as AdminContract;

/**
 * Rechazar un pago `sin_revisar` (PAG-E, D-59 / Doc. 05 §42.4 y §42.5): el ingreso nunca fue valido. SOLO
 * `sin_revisar → rechazado`; rechazar un pago ya confirmado seria un REVERSO (PAG-I) y se niega.
 *
 * Efecto: sus aplicaciones se ANULAN de forma logica (`anulada_en` + quien) y por eso dejan de contar; la deuda
 * vuelve a subir por derivacion (no hay saldo guardado). No se borra ni el pago, ni sus aplicaciones, ni sus eventos.
 * NO crea Transactions y NO toca Transactions, Invoice ni Order: nunca hubo dinero verificado en Bagisto.
 *
 * Motivo obligatorio: una categoria de `ventasasesor.pagos.motivos_rechazo` MAS un detalle en texto libre; el evento
 * `rechazado` guarda actor, mecanismo, detalle y categoria. Idempotente: un pago ya rechazado devuelve su resultado
 * estable sin escribir otro evento.
 */
class RechazarPagoService extends RevisionPagoService
{
    private const MOTIVO_MIN = 5;

    private const MOTIVO_MAX = 500;

    /**
     * @throws Rechazo
     */
    public function rechazar(
        AdminContract $actor,
        int $pagoId,
        string $categoria,
        string $motivo,
        string $mecanismo = MecanismoCreacion::VENTAS_ASESOR->value,
    ): ResultadoRevisionPago {
        $this->exigirRevisor($actor);

        $categoria = trim($categoria);
        $motivo = trim($motivo);

        if (! array_key_exists($categoria, (array) config('ventasasesor.pagos.motivos_rechazo'))) {
            throw new Rechazo(Rechazo::CATEGORIA_INVALIDA, 'Elige una categoria de rechazo valida.');
        }

        if (mb_strlen($motivo) < self::MOTIVO_MIN || mb_strlen($motivo) > self::MOTIVO_MAX) {
            throw new Rechazo(Rechazo::MOTIVO_OBLIGATORIO, 'Escribe el motivo del rechazo ('.self::MOTIVO_MIN.' a '.self::MOTIVO_MAX.' caracteres).');
        }

        return DB::transaction(fn () => $this->rechazarBajoBloqueo($actor, $pagoId, $categoria, $motivo, $mecanismo), self::REINTENTOS);
    }

    protected function rechazarBajoBloqueo(AdminContract $actor, int $pagoId, string $categoria, string $motivo, string $mecanismo): ResultadoRevisionPago
    {
        $pago = $this->bloquearPago($actor, $pagoId);

        if ($pago->estado === EstadoPago::RECHAZADO) {
            return new ResultadoRevisionPago((int) $pago->id, EstadoPago::RECHAZADO->value, true, [], $pago->motivo_revision);
        }

        if ($pago->estado === EstadoPago::CONFIRMADO) {
            throw new Rechazo(Rechazo::PAGO_CONFIRMADO_NO_RECHAZABLE, 'Un pago confirmado no se rechaza: corregirlo es un reverso (otra operacion, aun no disponible).');
        }

        if (! $pago->estado->puedeTransicionarA(EstadoPago::RECHAZADO)) {
            throw new Rechazo(Rechazo::ESTADO_NO_REVISABLE, 'Solo se puede rechazar un pago sin revisar.', ['estado' => $pago->estado->value]);
        }

        $aplicaciones = $this->aplicacionesActivasBloqueadas($pago->id);

        // Un pago sin revisar jamas debe tener una Transaction enlazada (solo confirmar la crea).
        if ($aplicaciones->contains(fn ($a) => $a->order_transaction_id !== null)) {
            throw new Rechazo(Rechazo::APLICACIONES_INCONSISTENTES, 'El pago tiene aplicaciones con Transaction enlazada: no es rechazable.');
        }

        $anuladas = [];

        foreach ($aplicaciones as $aplicacion) {
            $aplicacion->forceFill(['anulada_en' => now(), 'anulada_por_admin_id' => (int) $actor->id])->save();

            $anuladas[] = ['aplicacion_id' => (int) $aplicacion->id, 'order_id' => (int) $aplicacion->order_id, 'monto' => (float) $aplicacion->monto];
        }

        $pago->forceFill([
            'estado' => EstadoPago::RECHAZADO->value,
            'revisado_por_admin_id' => (int) $actor->id,
            'revisado_en' => now(),
            'motivo_revision' => $motivo,
        ])->save();

        PagoEvento::query()->create([
            'pago_id' => $pago->id,
            'tipo' => TipoEventoPago::RECHAZADO->value,
            'actor_admin_id' => (int) $actor->id,
            'mecanismo' => $mecanismo,
            'motivo' => $motivo,
            'datos' => ['categoria' => $categoria, 'aplicaciones_anuladas' => $anuladas],
        ]);

        return new ResultadoRevisionPago((int) $pago->id, EstadoPago::RECHAZADO->value, false, [], $motivo);
    }
}
