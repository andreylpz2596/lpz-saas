<?php

namespace ByLopez\Logistica\Services\Despachos;

use ByLopez\Logistica\Enums\TipoDisposicionCobro as Tipo;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Models\DisposicionCobro;
use ByLopez\Logistica\Support\AutorizacionLogistica;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\User\Contracts\Admin;

/**
 * F6 (regla aprobada 2026-10-26): un pedido que NO es contra entrega y aun tiene dinero sin confirmar no sale en silencio con
 * «no cobrar nada». Mientras no haya una decision vigente el despacho queda bloqueado (A: esperar el pago confirmado); se puede
 * resolver con B (`cobrar_al_entregar`: la guia lleva el saldo como recaudo, regla C1) o C (`credito_autorizado`: sale sin
 * recaudo, solo con `logistica.despachos.autorizar_credito` y motivo).
 *
 *  - «Por confirmar» = saldo segun Cartera + lo reportado sin revisar: un pago reportado NO es dinero verificado.
 *  - La decision es del PEDIDO (historial, solo inserciones): la vigente es la ultima y vale mientras el pago siga como estaba
 *    al decidir. Un pago nuevo, un rechazo o una devolucion recibida cambian el saldo y obligan a decidir otra vez; si no
 *    cambio nada, no se vuelve a pedir (sirve para todas las guias del pedido).
 *  - Contra entrega no necesita decision: el metodo del pedido ya la tomo (cobra quien entrega).
 *  - La regla vive aqui y la aplica el servidor al despachar (DespachoService la revalida bajo el bloqueo del pedido); la
 *    interfaz solo la refleja. El metodo de pago del pedido no se cambia.
 */
class DisposicionCobroService
{
    private const MOTIVO_MINIMO = 5;

    public function __construct(
        protected CondicionDespacho $condicion,
        protected AutorizacionLogistica $autorizacion,
    ) {}

    /**
     * @return array{requiere: bool, resuelta: bool, por_confirmar: float, vigente: ?array<string, mixed>, anterior: ?array<string, mixed>,
     *               permisos: array{cobrar_al_entregar: bool, credito: bool}}
     */
    public function situacion(int $orderId, ?Admin $actor = null): array
    {
        $pago = $this->condicion->pagoPendiente($orderId, false);
        $porConfirmar = $pago['saldo'] === null ? 0.0 : round((float) $pago['saldo'] + (float) $pago['sin_revisar'], 2);
        $requiere = self::activa() && $pago['saldo'] !== null && $porConfirmar > 0.001 && ! $this->condicion->cobraAlEntregar($orderId);

        $ultima = DisposicionCobro::query()->where('order_id', $orderId)->orderByDesc('id')->first();
        $vigente = $requiere && $ultima !== null && $this->sigueVigente($ultima, $pago) ? $ultima : null;

        return [
            'requiere' => $requiere,
            'resuelta' => ! $requiere || $vigente !== null,
            'por_confirmar' => $porConfirmar,
            'vigente' => $vigente ? $this->fila($vigente) : null,
            // La ultima decision ya no vale (el pago cambio): se muestra para que se vea por que hay que decidir de nuevo.
            'anterior' => $requiere && $ultima !== null && $vigente === null ? $this->fila($ultima) : null,
            'permisos' => [
                'cobrar_al_entregar' => $this->autorizacion->tienePermiso($actor, AutorizacionLogistica::PERMISO_DESPACHAR),
                'credito' => $this->autorizacion->tienePermiso($actor, AutorizacionLogistica::PERMISO_AUTORIZAR_CREDITO),
            ],
        ];
    }

    /** @throws Invalida */
    public function registrar(?Admin $actor, int $orderId, string $tipo, ?string $motivo): DisposicionCobro
    {
        $elegido = Tipo::tryFrom(trim($tipo)) ?? throw new Invalida(Invalida::DISPOSICION_COBRO_INVALIDA);
        $motivo = trim((string) $motivo);
        $motivo = $motivo === '' ? null : mb_substr($motivo, 0, 500);

        $permiso = $elegido === Tipo::CREDITO_AUTORIZADO ? AutorizacionLogistica::PERMISO_AUTORIZAR_CREDITO : AutorizacionLogistica::PERMISO_DESPACHAR;

        if (! $this->autorizacion->tienePermiso($actor, $permiso)) {
            throw new Invalida(Invalida::SIN_PERMISO);
        }

        if ($elegido === Tipo::CREDITO_AUTORIZADO && mb_strlen((string) $motivo) < self::MOTIVO_MINIMO) {
            throw new Invalida(Invalida::MOTIVO_CREDITO_REQUERIDO);
        }

        return DB::transaction(function () use ($actor, $orderId, $elegido, $motivo) {
            if (! DB::table('orders')->where('id', $orderId)->lockForUpdate()->exists()) {
                throw new Invalida(Invalida::PEDIDO_INEXISTENTE);
            }

            $s = $this->situacion($orderId, $actor);

            if (! $s['requiere']) {
                throw new Invalida(Invalida::DISPOSICION_NO_APLICA);
            }

            $pago = $this->condicion->pagoPendiente($orderId, true);

            // Cobrar al entregar con un pago reportado sin confirmar: el valor a cobrar es incierto (mismo criterio del despacho).
            if ($elegido === Tipo::COBRAR_AL_ENTREGAR && $pago['bloquea']) {
                throw new Invalida(Invalida::PAGO_PENDIENTE_CONFIRMAR, ['monto' => $pago['sin_revisar']]);
            }

            // Doble clic: la misma decision vigente no se repite.
            $vigente = $s['vigente'];

            if ($vigente !== null && $vigente['tipo'] === $elegido->value && $vigente['motivo'] === $motivo) {
                return DisposicionCobro::query()->findOrFail($vigente['id']);
            }

            return DisposicionCobro::query()->create([
                'order_id' => $orderId,
                'tipo' => $elegido->value,
                'motivo' => $motivo,
                'saldo_referencia' => (float) $pago['saldo'],
                'sin_revisar_referencia' => (float) $pago['sin_revisar'],
                'actor_admin_id' => (int) $actor->id,
                'actor_name_snapshot' => mb_substr((string) $actor->name, 0, 191),
            ]);
        });
    }

    /**
     * Para el despacho, DENTRO de su transaccion y con el pedido ya bloqueado: devuelve la decision vigente (o null si el pedido no
     * la necesita) y rechaza si falta o quedo vieja.
     *
     * @throws Invalida
     */
    public function exigirResuelta(int $orderId): ?DisposicionCobro
    {
        $s = $this->situacion($orderId);

        if (! $s['requiere']) {
            return null;
        }

        if ($s['vigente'] === null) {
            throw new Invalida(Invalida::DISPOSICION_COBRO_REQUERIDA, [
                'por_confirmar' => $s['por_confirmar'],
                'anterior' => $s['anterior']['tipo'] ?? null,
            ]);
        }

        return DisposicionCobro::query()->findOrFail($s['vigente']['id']);
    }

    /**
     * Entorno sin la migracion de F6 (2026_10_27_100019): la regla no se aplica y el despacho se comporta como antes. Asi el mismo
     * codigo no rompe una base que aun no la tiene; en produccion se despliega junto con la migracion. En cache por proceso.
     */
    public static function activa(): bool
    {
        static $activa = null;

        return $activa ??= Schema::hasTable('bylopez_lg_disposiciones_cobro');
    }

    /** @param  array{sin_revisar: float, saldo: ?float}  $pago */
    private function sigueVigente(DisposicionCobro $d, array $pago): bool
    {
        return abs((float) $d->saldo_referencia - (float) $pago['saldo']) < 0.01
            && abs((float) $d->sin_revisar_referencia - (float) $pago['sin_revisar']) < 0.01;
    }

    /** @return array<string, mixed> */
    private function fila(DisposicionCobro $d): array
    {
        return [
            'id' => (int) $d->id,
            'tipo' => $d->tipo->value,
            'motivo' => $d->motivo,
            'saldo_referencia' => round((float) $d->saldo_referencia, 2),
            'actor' => $d->actor_name_snapshot,
            'fecha' => $d->created_at?->toDateTimeString(),
        ];
    }
}
