<?php

namespace ByLopez\VentasAsesor\Services\Cupo;

use ByLopez\VentasAsesor\Exceptions\OperacionCupoInvalidaException as Invalida;
use ByLopez\VentasAsesor\Models\ClienteCupoCartera;
use ByLopez\VentasAsesor\Models\ClienteCupoCarteraHistorial as Historial;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use Illuminate\Support\Facades\DB;
use Webkul\User\Contracts\Admin as AdminContract;

/**
 * Administra la POLITICA del cupo de cartera (Doc. 35): fijar el limite y bloquear/desbloquear, con historial completo. Es el UNICO
 * escritor de `bylopez_va_clientes_cupo_cartera` y de su historial.
 *
 * - Permiso en el servidor: la hoja ACL + ser el administrador autorizado (un rol `all` cualquiera no basta). No depende de botones.
 * - Toma el MISMO bloqueo que el cerco de ventas (`customers … FOR UPDATE`): un cambio de cupo y una confirmacion de venta del mismo
 *   cliente se serializan, asi que una venta ve el cupo anterior o el nuevo, nunca uno a medias.
 * - Politica e historial se escriben en UNA transaccion: no hay cambio sin historia ni historia sin cambio.
 * - Control optimista opcional (`$cupoEsperado` / `$estadoEsperado`): si otra persona cambio el cupo mientras se editaba, se rechaza en
 *   vez de pisar su cambio.
 * - Bajar el cupo por debajo de la deuda NO toca ningun pedido: solo cambia la politica (el cliente queda `excedido` y sin ventas nuevas).
 * - Nunca se borra nada: «quitar» el cupo es dejarlo en 0 o bloqueado.
 */
class GestionCupoCarteraService
{
    public const MOTIVO_MAX = 500;

    public function __construct(
        protected AutorizacionVentasAsesor $autorizacion,
        protected CupoCarteraClienteService $cupo,
    ) {}

    /**
     * @param  string|int|float  $cupoAutorizado  monto no negativo, hasta 2 decimales
     * @return array{accion: string, cupo: array<string, mixed>, anterior: array{cupo: ?float, estado: ?string}, excede_exposicion: bool}
     *
     * @throws Invalida
     */
    public function establecer(
        AdminContract $actor,
        int $customerId,
        string|int|float $cupoAutorizado,
        string $estado,
        string $motivo,
        ?string $cupoEsperado = null,
        ?string $estadoEsperado = null,
    ): array {
        if (! $this->autorizacion->puedeCupo($actor, AutorizacionVentasAsesor::PERMISO_CUPO_EDITAR)) {
            throw new Invalida(Invalida::SIN_PERMISO, 'No tienes permiso para administrar el cupo de cartera.');
        }

        $monto = $this->normalizarMonto($cupoAutorizado);
        $estado = strtolower(trim($estado));
        $motivo = trim($motivo);

        if (! in_array($estado, ClienteCupoCartera::ESTADOS, true)) {
            throw new Invalida(Invalida::ESTADO_INVALIDO, 'El estado debe ser «activo» o «bloqueado».');
        }

        if ($motivo === '' || mb_strlen($motivo) > self::MOTIVO_MAX) {
            throw new Invalida(Invalida::MOTIVO_REQUERIDO, 'El motivo es obligatorio (máximo '.self::MOTIVO_MAX.' caracteres).');
        }

        return DB::transaction(function () use ($actor, $customerId, $monto, $estado, $motivo, $cupoEsperado, $estadoEsperado) {
            // El MISMO bloqueo que toma el cerco de ventas: serializa contra confirmaciones del cliente y contra otros cambios de cupo.
            if (DB::table('customers')->where('id', $customerId)->lockForUpdate()->value('id') === null) {
                throw new Invalida(Invalida::CLIENTE_INEXISTENTE, 'El cliente no existe.');
            }

            $actual = ClienteCupoCartera::query()->where('customer_id', $customerId)->lockForUpdate()->first();
            $cupoActual = $actual === null ? null : round((float) $actual->cupo_autorizado, 2);
            $estadoActual = $actual?->estado;

            if (($cupoEsperado !== null || $estadoEsperado !== null)
                && ($this->centavos($cupoActual ?? 0.0) !== $this->centavos((float) ($cupoEsperado ?? ($cupoActual ?? 0.0)))
                    || ($estadoEsperado !== null && ($estadoActual ?? ClienteCupoCartera::ESTADO_ACTIVO) !== $estadoEsperado))) {
                throw new Invalida(Invalida::CAMBIO_CONCURRENTE, 'El cupo cambió mientras lo editabas. Recarga la pantalla y vuelve a intentarlo.', [
                    'cupo_actual' => $cupoActual ?? 0.0, 'estado_actual' => $estadoActual ?? ClienteCupoCartera::ESTADO_ACTIVO,
                ]);
            }

            $mismoCupo = $this->centavos($cupoActual ?? 0.0) === $this->centavos($monto);
            $mismoEstado = ($estadoActual ?? ClienteCupoCartera::ESTADO_ACTIVO) === $estado;

            // Sin politica equivale a «cupo 0 y activo»: repetirlo no es un cambio.
            if ($mismoCupo && $mismoEstado) {
                throw new Invalida(Invalida::SIN_CAMBIOS, 'No hay cambios: el cupo y el estado ya son esos.');
            }

            // Foto informativa de la exposicion al cambiar (lectura bajo el bloqueo del cliente; jamas se usa para calcular).
            $exposicion = $this->cupo->delCliente($customerId)->exposicion;
            $ahora = now();

            if ($actual === null) {
                $actual = ClienteCupoCartera::query()->create([
                    'customer_id' => $customerId, 'cupo_autorizado' => $monto, 'estado' => $estado, 'vigencia_desde' => $ahora,
                    'motivo' => $motivo, 'creado_por' => (int) $actor->id, 'actualizado_por' => (int) $actor->id,
                ]);
                $accion = Historial::ASIGNACION;
            } else {
                $actual->forceFill([
                    'cupo_autorizado' => $monto, 'estado' => $estado, 'motivo' => $motivo, 'actualizado_por' => (int) $actor->id,
                    'vigencia_desde' => $mismoCupo ? $actual->vigencia_desde : $ahora,
                ])->save();
                $accion = match (true) {
                    ! $mismoCupo && ! $mismoEstado => Historial::CAMBIO_CUPO_Y_ESTADO,
                    ! $mismoCupo => Historial::CAMBIO_CUPO,
                    default => Historial::CAMBIO_ESTADO,
                };
            }

            Historial::query()->create([
                'customer_id' => $customerId, 'accion' => $accion,
                'cupo_anterior' => $cupoActual, 'cupo_nuevo' => $monto,
                'estado_anterior' => $estadoActual, 'estado_nuevo' => $estado,
                'motivo' => $motivo, 'actor_admin_id' => (int) $actor->id, 'actor_nombre' => $actor->name,
                'exposicion_al_cambiar' => $exposicion,
            ]);

            $nuevo = $this->cupo->delCliente($customerId);

            return [
                'accion' => $accion,
                'cupo' => $nuevo->toArray(),
                'anterior' => ['cupo' => $cupoActual, 'estado' => $estadoActual],
                // Bajar el cupo por debajo de la deuda deja al cliente excedido; los pedidos existentes no se tocan.
                'excede_exposicion' => $nuevo->exceso > 0,
            ];
        });
    }

    /**
     * Historial completo del cliente, del mas reciente al mas antiguo. Solo con la hoja `cupo.historial` (y la regla de `all`).
     *
     * @return list<array<string, mixed>>
     *
     * @throws Invalida
     */
    public function historial(AdminContract $actor, int $customerId, int $limite = 100): array
    {
        if (! $this->autorizacion->puedeCupo($actor, AutorizacionVentasAsesor::PERMISO_CUPO_HISTORIAL)) {
            throw new Invalida(Invalida::SIN_PERMISO, 'No tienes permiso para ver el historial del cupo.');
        }

        return DB::table('bylopez_va_clientes_cupo_cartera_historial')
            ->where('customer_id', $customerId)
            ->orderByDesc('id')->limit(max(1, min(500, $limite)))
            ->get()
            ->map(fn ($f) => [
                'id' => (int) $f->id,
                'accion' => $f->accion,
                'cupo_anterior' => $f->cupo_anterior === null ? null : (float) $f->cupo_anterior,
                'cupo_nuevo' => (float) $f->cupo_nuevo,
                'estado_anterior' => $f->estado_anterior,
                'estado_nuevo' => $f->estado_nuevo,
                'motivo' => $f->motivo,
                'actor' => $f->actor_nombre,
                'actor_admin_id' => (int) $f->actor_admin_id,
                'exposicion_al_cambiar' => $f->exposicion_al_cambiar === null ? null : (float) $f->exposicion_al_cambiar,
                'fecha' => $f->created_at,
            ])->all();
    }

    /**
     * Monto entero o con hasta 2 decimales, no negativo y dentro de rango (decimal(14,2)). Falla cerrado ante cualquier otra forma
     * (notacion cientifica, signo, separadores, texto).
     *
     * @throws Invalida
     */
    protected function normalizarMonto(string|int|float $valor): float
    {
        $texto = is_float($valor) ? rtrim(rtrim(number_format($valor, 2, '.', ''), '0'), '.') : trim((string) $valor);

        if (! preg_match('/^\d{1,12}(\.\d{1,2})?$/', $texto)) {
            throw new Invalida(Invalida::MONTO_INVALIDO, 'El cupo debe ser un monto sin signo, de hasta 12 dígitos y 2 decimales.');
        }

        return round((float) $texto, 2);
    }

    protected function centavos(float $monto): int
    {
        return (int) round($monto * 100);
    }
}
