<?php

namespace ByLopez\CorteConfeccion\Services;

use ByLopez\CorteConfeccion\Enums\EstadoOrdenConfeccionRetorno;
use ByLopez\CorteConfeccion\Models\StockMinimo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sugerencia de prioridad POR STOCK de una variante (docs/confeccion/09_CONSOLIDADO_PRIORIDADES.md §9). Solo lectura:
 * nunca cambia prioridades asignadas, cantidades, ordenes ni notificaciones. La prioridad asignada por el administrador
 * manda; la sugerencia solo ayuda a ordenar trabajos con la misma prioridad asignada.
 *
 * Reglas aprobadas (V1, por disponibilidad, no es un pronostico de ventas):
 *   - Disponible <= 0                         -> Urgente («Sin disponible» / «Disponible negativo»).
 *   - Disponible > 0 y < minimo               -> Alta («Bajo el mínimo»).
 *   - Disponible >= minimo (incluido minimo 0) -> Normal («Cubre el mínimo»).
 *   - Disponible > 0 sin minimo configurado    -> «Sin mínimo configurado».
 *   - Sin indice de inventario o consulta fallida -> «Sin información». Nunca se asume cero.
 *
 * Disponible = VarianteConsultaService::stockVendible() (indice de Bagisto del canal por defecto: fisico de las fuentes
 * activas menos reservas). Las reservas ya vienen descontadas: aqui no se vuelven a restar. Las prendas terminadas
 * pendientes de recibir NO se suman: solo producen la ayuda «Priorizar recepción».
 *
 * La sugerencia es GLOBAL por variante: si la misma variante esta en varios talleres se repite en cada uno; no es una
 * necesidad adicional por taller. Se calcula en cada consulta (sin cache) y lleva la hora del calculo.
 */
class SugerenciaStockService
{
    public const URGENTE = 'urgente';

    public const ALTA = 'alta';

    public const NORMAL = 'normal';

    public const SIN_MINIMO = 'sin_minimo';

    public const SIN_INFORMACION = 'sin_informacion';

    /** Orden para atender trabajos con la MISMA prioridad asignada. */
    public const PESO = [self::URGENTE => 0, self::ALTA => 1, self::NORMAL => 2, self::SIN_MINIMO => 3, self::SIN_INFORMACION => 4];

    public function __construct(protected VarianteConsultaService $varianteConsultaService) {}

    public static function etiqueta(string $nivel): string
    {
        return match ($nivel) {
            self::URGENTE => 'Urgente',
            self::ALTA => 'Alta',
            self::NORMAL => 'Normal',
            self::SIN_MINIMO => 'Sin mínimo',
            default => 'Sin información',
        };
    }

    /** @return array<string, string> nivel => etiqueta para filtros */
    public static function opciones(): array
    {
        return [
            self::URGENTE => 'Urgente',
            self::ALTA => 'Alta',
            self::NORMAL => 'Normal',
            self::SIN_MINIMO => 'Sin mínimo configurado',
            self::SIN_INFORMACION => 'Sin información de inventario',
        ];
    }

    /**
     * Sugerencia de cada variante. `motivo` no lleva cifras (lo puede ver un taller); `detalle` lleva disponible y
     * minimo (solo personal con acceso a inventario). `pendiente_recibir` se limita a los talleres de $alcance
     * (null = todos): un taller solo ve sus propias prendas pendientes.
     *
     * @param  array<int, int>|null  $alcance
     * @return Collection<int, array{nivel: string, etiqueta: string, motivo: string, detalle: string, disponible: ?int, minimo: ?int, pendiente_recibir: int, priorizar_recepcion: bool, calculada_en: Carbon}>
     */
    public function paraProductos(iterable $productIds, ?array $alcance = null): Collection
    {
        $ids = collect($productIds)->map(fn ($id) => (int) $id)->filter(fn ($id) => $id > 0)->unique()->values();
        $ahora = Carbon::now();

        if ($ids->isEmpty()) {
            return collect();
        }

        try {
            $stock = $this->varianteConsultaService->stockVendible($ids);
            $stockFallo = false;
        } catch (Throwable $e) {
            report($e);
            $stock = collect();
            $stockFallo = true;
        }

        try {
            $minimos = StockMinimo::query()->whereIn('product_id', $ids)->pluck('minimo', 'product_id')->map(fn ($m) => (int) $m);
            $minimosFallo = false;
        } catch (Throwable $e) {
            report($e);
            $minimos = collect();
            $minimosFallo = true;
        }

        $pendientes = $this->pendienteRecibir($ids, $alcance);

        return $ids->mapWithKeys(function (int $id) use ($stock, $stockFallo, $minimos, $minimosFallo, $pendientes, $ahora) {
            $disponible = $stock->has($id) ? (int) $stock->get($id) : null;
            $minimo = $minimos->has($id) ? (int) $minimos->get($id) : null;

            [$nivel, $motivo, $detalle] = $this->clasificar($disponible, $minimo, $stockFallo, $minimosFallo);
            $pendiente = (int) ($pendientes[$id] ?? 0);

            return [$id => [
                'nivel' => $nivel,
                'etiqueta' => self::etiqueta($nivel),
                'motivo' => $motivo,
                'detalle' => $detalle,
                'disponible' => $disponible,
                'minimo' => $minimo,
                'pendiente_recibir' => $pendiente,
                // Falta disponibilidad y hay prendas terminadas sin recibir: recibirlas ayuda. No se suman al disponible.
                'priorizar_recepcion' => in_array($nivel, [self::URGENTE, self::ALTA], true) && $pendiente > 0,
                'calculada_en' => $ahora,
            ]];
        });
    }

    /** @return array{0: string, 1: string, 2: string} nivel, motivo sin cifras, detalle con cifras */
    protected function clasificar(?int $disponible, ?int $minimo, bool $stockFallo, bool $minimosFallo): array
    {
        if ($stockFallo) {
            return [self::SIN_INFORMACION, 'Sin información de inventario', 'No se pudo consultar el inventario'];
        }

        if ($disponible === null) {
            return [self::SIN_INFORMACION, 'Sin información de inventario', 'La variante no tiene índice de inventario en el canal'];
        }

        if ($disponible <= 0) {
            return $disponible === 0
                ? [self::URGENTE, 'Sin disponible', 'Disponible 0'.($minimo !== null ? ' · mínimo '.$minimo : '')]
                : [self::URGENTE, 'Disponible negativo', 'Disponible '.$disponible.' (reservas superan existencias)'];
        }

        if ($minimosFallo) {
            return [self::SIN_INFORMACION, 'Sin información del mínimo', 'Disponible '.$disponible.' · no se pudo leer el mínimo'];
        }

        if ($minimo === null) {
            return [self::SIN_MINIMO, 'Sin mínimo configurado', 'Disponible '.$disponible.' · sin mínimo'];
        }

        return $disponible < $minimo
            ? [self::ALTA, 'Bajo el mínimo', 'Disponible '.$disponible.' de mínimo '.$minimo]
            : [self::NORMAL, 'Cubre el mínimo', 'Disponible '.$disponible.' · mínimo '.$minimo];
    }

    /**
     * Prendas de la variante reportadas como terminadas que bodega aun no recibe (retornos pendiente_bodega), sumadas
     * una sola vez por detalle de reporte. Limitadas a los talleres de $alcance.
     *
     * @return Collection<int, int>
     */
    protected function pendienteRecibir(Collection $ids, ?array $alcance): Collection
    {
        if ($alcance === []) {
            return collect();
        }

        $consulta = DB::table('bylopez_cc_ordenes_confeccion_retorno_detalles as rd')
            ->join('bylopez_cc_ordenes_confeccion_retornos as r', 'r.id', '=', 'rd.retorno_id')
            ->where('r.estado', EstadoOrdenConfeccionRetorno::PendienteBodega->value)
            ->whereIn('rd.product_id', $ids)
            ->groupBy('rd.product_id')
            ->selectRaw('rd.product_id, SUM(rd.cantidad_reportada) as pendiente');

        if ($alcance !== null) {
            $consulta->whereIn('r.taller_id', $alcance);
        }

        return $consulta->pluck('pendiente', 'product_id')->mapWithKeys(fn ($q, $id) => [(int) $id => (int) $q]);
    }
}
