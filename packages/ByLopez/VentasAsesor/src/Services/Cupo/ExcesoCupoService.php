<?php

namespace ByLopez\VentasAsesor\Services\Cupo;

use ByLopez\VentasAsesor\DataObjects\EvaluacionCupo;
use ByLopez\VentasAsesor\Exceptions\OperacionCupoInvalidaException as Invalida;
use ByLopez\VentasAsesor\Models\ClienteCupoCartera;
use ByLopez\VentasAsesor\Models\ClienteCupoCarteraExceso as Exceso;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use Illuminate\Support\Facades\DB;
use Webkul\User\Contracts\Admin as AdminContract;

/**
 * Excepcion «Autorizar exceso» del cupo de cartera (Doc. 35 §8): un administrador autorizado permite que UNA venta de un cliente supere
 * su cupo (o se cree sin cupo) hasta `exceso_autorizado`.
 *
 * - Solo el administrador autorizado (`puedeCupo`: la hoja `autorizar_exceso` + ser ese rol; un rol `all` cualquiera no basta). Motivo
 *   y monto obligatorios. Un asesor corriente nunca puede.
 * - NO anula un bloqueo: un cliente `bloqueado` no admite excepcion (primero se desbloquea con un cambio de cupo auditado), ni cubre
 *   «Cartera inactiva» (no verificable).
 * - UNA sola autorizacion vigente por cliente (UNIQUE en base), de un solo uso y con vencimiento. Se CONSUME dentro de la transaccion del
 *   pedido (cerco), bajo el bloqueo del cliente: dos ventas que compiten por la misma autorizacion no pueden usarla las dos.
 * - Cada fila es su auditoria: quien, cuanto, por que, cuando, la exposicion y el cupo al consumirla, el exceso real y el pedido.
 */
class ExcesoCupoService
{
    public const MOTIVO_MAX = 500;

    public function __construct(
        protected AutorizacionVentasAsesor $autorizacion,
        protected CupoCarteraClienteService $cupo,
    ) {}

    /**
     * @param  string|int|float  $exceso  monto positivo (hasta 2 decimales): cuanto PUEDE pasarse del cupo la venta autorizada
     * @return array<string, mixed>
     *
     * @throws Invalida
     */
    public function autorizar(AdminContract $actor, int $customerId, string|int|float $exceso, string $motivo): array
    {
        $this->exigirPermiso($actor);
        $monto = $this->montoPositivo($exceso);
        $motivo = $this->motivoValido($motivo);

        return DB::transaction(function () use ($actor, $customerId, $monto, $motivo) {
            // El MISMO bloqueo del cliente que toma el cerco de ventas y el cambio de cupo.
            if (DB::table('customers')->where('id', $customerId)->lockForUpdate()->value('id') === null) {
                throw new Invalida(Invalida::CLIENTE_INEXISTENTE, 'El cliente no existe.');
            }

            if (ClienteCupoCartera::query()->where('customer_id', $customerId)->lockForUpdate()->value('estado') === ClienteCupoCartera::ESTADO_BLOQUEADO) {
                throw new Invalida(Invalida::CLIENTE_BLOQUEADO, 'El cupo del cliente está bloqueado: desbloquéalo primero (cambio de cupo) antes de autorizar un exceso.');
            }

            // Las vencidas dejan de ocupar el lugar de la unica vigente.
            Exceso::query()->where('customer_id', $customerId)->where('estado', Exceso::VIGENTE)->where('vence_en', '<=', now())->update(['estado' => Exceso::VENCIDA, 'updated_at' => now()]);

            $vigente = Exceso::query()->where('customer_id', $customerId)->where('estado', Exceso::VIGENTE)->lockForUpdate()->first();

            if ($vigente !== null) {
                throw new Invalida(Invalida::EXCESO_VIGENTE_EXISTENTE, 'Ya hay una autorización de exceso vigente para este cliente: se usa o se anula antes de dar otra.', ['exceso_id' => (int) $vigente->id]);
            }

            $fila = Exceso::query()->create([
                'customer_id' => $customerId, 'exceso_autorizado' => $monto, 'motivo' => $motivo, 'estado' => Exceso::VIGENTE,
                'vence_en' => now()->addDays(max(1, (int) config('ventasasesor.cupo.dias_vigencia_excepcion', 2))),
                'autorizado_por' => (int) $actor->id, 'autorizado_nombre' => $actor->name,
            ]);

            return $this->fila($fila->fresh());
        });
    }

    /**
     * Anula una autorizacion VIGENTE no consumida (motivo obligatorio). La consumida es historia y no se toca.
     *
     * @return array<string, mixed>
     *
     * @throws Invalida
     */
    public function anular(AdminContract $actor, int $excesoId, string $motivo): array
    {
        $this->exigirPermiso($actor);
        $motivo = $this->motivoValido($motivo);

        return DB::transaction(function () use ($actor, $excesoId, $motivo) {
            $clienteId = Exceso::query()->whereKey($excesoId)->value('customer_id');

            if ($clienteId === null) {
                throw new Invalida(Invalida::EXCESO_INEXISTENTE, 'La autorización de exceso no existe.');
            }

            DB::table('customers')->where('id', $clienteId)->lockForUpdate()->value('id');
            $fila = Exceso::query()->whereKey($excesoId)->lockForUpdate()->firstOrFail();

            if ($fila->estado !== Exceso::VIGENTE) {
                throw new Invalida(Invalida::EXCESO_NO_ANULABLE, 'Solo se puede anular una autorización vigente (esta ya está '.$fila->estado.').');
            }

            $fila->forceFill([
                'estado' => Exceso::ANULADA, 'anulada_en' => now(), 'anulada_por' => (int) $actor->id, 'anulada_motivo' => $motivo,
            ])->save();

            return $this->fila($fila->fresh());
        });
    }

    /**
     * CONSUMO atomico, solo desde el cerco de creacion de pedidos: la transaccion del pedido y el bloqueo del cliente ya estan tomados.
     * Relee la autorizacion con FOR UPDATE (la decision no depende de lecturas anteriores) y la usa solo si alcanza para el exceso real.
     *
     * @return ?int id de la autorizacion consumida, o null si no hay una vigente que alcance
     */
    public function consumir(int $customerId, EvaluacionCupo $evaluacion, float $cupoAutorizado, float $exposicion): ?int
    {
        $fila = Exceso::query()
            ->where('customer_id', $customerId)->where('estado', Exceso::VIGENTE)->where('vence_en', '>', now())
            ->lockForUpdate()->first();

        if ($fila === null || $this->centavos((float) $fila->exceso_autorizado) < $this->centavos($evaluacion->exceso)) {
            return null;
        }

        $fila->forceFill([
            'estado' => Exceso::CONSUMIDA, 'consumida_en' => now(), 'exposicion_antes' => $exposicion, 'cupo_al_consumir' => $cupoAutorizado,
            'nueva_obligacion' => $evaluacion->nuevaObligacion, 'exceso_consumido' => $evaluacion->exceso,
        ])->save();

        return (int) $fila->id;
    }

    /** Liga la autorizacion consumida al pedido que la uso (mismo intento, misma transaccion). */
    public function vincularPedido(int $excesoId, int $orderId): void
    {
        DB::table('bylopez_va_clientes_cupo_cartera_excesos')
            ->where('id', $excesoId)->where('estado', Exceso::CONSUMIDA)->whereNull('order_id')
            ->update(['order_id' => $orderId, 'updated_at' => now()]);
    }

    /**
     * Autorizaciones del cliente, la mas reciente primero. Solo con la hoja `historial` o la de autorizar (la auditoria no es del asesor).
     *
     * @return list<array<string, mixed>>
     *
     * @throws Invalida
     */
    public function deCliente(AdminContract $actor, int $customerId, int $limite = 50): array
    {
        if (! $this->autorizacion->puedeCupo($actor, AutorizacionVentasAsesor::PERMISO_CUPO_HISTORIAL)
            && ! $this->autorizacion->puedeCupo($actor, AutorizacionVentasAsesor::PERMISO_CUPO_AUTORIZAR_EXCESO)) {
            throw new Invalida(Invalida::SIN_PERMISO, 'No tienes permiso para ver las autorizaciones de exceso.');
        }

        return Exceso::query()->where('customer_id', $customerId)->orderByDesc('id')->limit(max(1, min(200, $limite)))->get()
            ->map(fn ($f) => $this->fila($f))->all();
    }

    /** @return array<string, mixed> */
    protected function fila(Exceso $f): array
    {
        $vencida = $f->estado === Exceso::VIGENTE && $f->vence_en !== null && $f->vence_en->lessThanOrEqualTo(now());

        return [
            'id' => (int) $f->id,
            'customer_id' => (int) $f->customer_id,
            'estado' => $vencida ? Exceso::VENCIDA : $f->estado,
            'exceso_autorizado' => (float) $f->exceso_autorizado,
            'motivo' => $f->motivo,
            'vence_en' => $f->vence_en?->toDateTimeString(),
            'autorizado_por' => $f->autorizado_nombre,
            'autorizado_por_id' => (int) $f->autorizado_por,
            'creada' => $f->created_at?->toDateTimeString(),
            'consumida_en' => $f->consumida_en?->toDateTimeString(),
            'order_id' => $f->order_id === null ? null : (int) $f->order_id,
            'increment_id' => $f->order_id === null ? null : DB::table('orders')->where('id', $f->order_id)->value('increment_id'),
            'exposicion_antes' => $f->exposicion_antes === null ? null : (float) $f->exposicion_antes,
            'cupo_al_consumir' => $f->cupo_al_consumir === null ? null : (float) $f->cupo_al_consumir,
            'nueva_obligacion' => $f->nueva_obligacion === null ? null : (float) $f->nueva_obligacion,
            'exceso_consumido' => $f->exceso_consumido === null ? null : (float) $f->exceso_consumido,
            'anulada_en' => $f->anulada_en?->toDateTimeString(),
            'anulada_motivo' => $f->anulada_motivo,
        ];
    }

    protected function exigirPermiso(AdminContract $actor): void
    {
        if (! $this->autorizacion->puedeCupo($actor, AutorizacionVentasAsesor::PERMISO_CUPO_AUTORIZAR_EXCESO)) {
            throw new Invalida(Invalida::SIN_PERMISO, 'No tienes permiso para autorizar excesos de cupo.');
        }
    }

    /** @throws Invalida */
    protected function montoPositivo(string|int|float $valor): float
    {
        $texto = is_float($valor) ? rtrim(rtrim(number_format($valor, 2, '.', ''), '0'), '.') : trim((string) $valor);

        if (! preg_match('/^\d{1,12}(\.\d{1,2})?$/', $texto) || (float) $texto <= 0) {
            throw new Invalida(Invalida::MONTO_INVALIDO, 'El exceso debe ser un monto mayor que cero, sin signo, de hasta 12 dígitos y 2 decimales.');
        }

        return round((float) $texto, 2);
    }

    /** @throws Invalida */
    protected function motivoValido(string $motivo): string
    {
        $motivo = trim($motivo);

        if ($motivo === '' || mb_strlen($motivo) > self::MOTIVO_MAX) {
            throw new Invalida(Invalida::MOTIVO_REQUERIDO, 'El motivo es obligatorio (máximo '.self::MOTIVO_MAX.' caracteres).');
        }

        return $motivo;
    }

    protected function centavos(float $monto): int
    {
        return (int) round($monto * 100);
    }
}
