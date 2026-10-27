<?php

namespace ByLopez\Logistica\Services\Stock;

use ByLopez\Logistica\Models\CatalogoDefecto;
use ByLopez\Logistica\Models\UnidadDefectuosa;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Consulta de las unidades DEFECTUOSAS (inventario fisico NO VENDIBLE). Solo lectura. Cuenta unicamente las de recepciones
 * CONFIRMADAS: anular una recepcion las saca de aqui (y de Cartera). No suma al stock vendible: ese stock vive en
 * `product_inventories` y esta consulta no lo toca.
 */
class ConsultaDefectuosasService
{
    public const POR_PAGINA = 25;

    /**
     * @param  array{desde: ?string, hasta: ?string, motivo: ?string, origen: ?string, estado: ?string}  $filtros
     */
    public function listar(array $filtros, int $pagina = 1): LengthAwarePaginator
    {
        $nombres = $this->nombres();

        $paginador = $this->base($filtros)
            ->join('products as p', 'p.id', '=', 'u.product_id')
            ->join('order_items as oi', 'oi.id', '=', 'u.order_item_id')
            ->orderByDesc('u.created_at')->orderByDesc('u.id')
            ->select(['u.id', 'u.created_at', 'u.cantidad', 'u.motivo_codigo', 'u.origen_codigo', 'u.detalle', 'u.observacion', 'u.estado',
                'u.actor_name_snapshot', 'u.recepcion_id', 'r.retorno_id', 'r.shipment_id', 'o.id as order_id', 'o.increment_id',
                'p.sku as sku_variante', 'oi.name as producto', 'oi.additional'])
            ->paginate(self::POR_PAGINA, ['*'], 'page', max(1, $pagina));

        $paginador->setCollection($paginador->getCollection()->map(function ($f) use ($nombres) {
            $atributos = (array) (((array) json_decode((string) $f->additional, true))['attributes'] ?? []);
            $f->color = $atributos['color']['option_label'] ?? null;
            $f->talla = collect($atributos)->except('color')->pluck('option_label')->filter()->implode(' · ');
            $f->motivo = $nombres['motivo'][$f->motivo_codigo] ?? $f->motivo_codigo;
            $f->origen = $nombres['origen'][$f->origen_codigo] ?? $f->origen_codigo;

            return $f;
        }));

        return $paginador;
    }

    /**
     * Cuantas unidades defectuosas EXISTEN fisicamente hoy (pendientes de revision) y por que motivo.
     *
     * @return array{pendientes: int, total: int, por_motivo: array<string, int>}
     */
    public function resumen(): array
    {
        $nombres = $this->nombres();
        $filas = $this->base([])->groupBy('u.estado', 'u.motivo_codigo')->selectRaw('u.estado, u.motivo_codigo, SUM(u.cantidad) AS n')->get();
        $porMotivo = [];

        foreach ($filas->where('estado', UnidadDefectuosa::PENDIENTE_REVISION) as $f) {
            $porMotivo[$nombres['motivo'][$f->motivo_codigo] ?? $f->motivo_codigo] = (int) $f->n;
        }

        return [
            'pendientes' => (int) $filas->where('estado', UnidadDefectuosa::PENDIENTE_REVISION)->sum('n'),
            'total' => (int) $filas->sum('n'),
            'por_motivo' => $porMotivo,
        ];
    }

    /** @return array{motivos: list<array<string, mixed>>, origenes: list<array<string, mixed>>} */
    public function catalogos(): array
    {
        return ['motivos' => CatalogoDefecto::activos(CatalogoDefecto::MOTIVO), 'origenes' => CatalogoDefecto::activos(CatalogoDefecto::ORIGEN)];
    }

    private function base(array $filtros): Builder
    {
        $c = DB::table('bylopez_lg_unidades_defectuosas as u')
            ->join('bylopez_lg_recepciones_devolucion as r', 'r.id', '=', 'u.recepcion_id')
            ->join('orders as o', 'o.id', '=', 'u.order_id')
            ->where('r.estado', 'confirmado');

        if (! empty($filtros['desde'])) {
            $c->whereDate('u.created_at', '>=', $filtros['desde']);
        }

        if (! empty($filtros['hasta'])) {
            $c->whereDate('u.created_at', '<=', $filtros['hasta']);
        }

        foreach (['motivo' => 'motivo_codigo', 'origen' => 'origen_codigo', 'estado' => 'estado'] as $filtro => $columna) {
            if (! empty($filtros[$filtro])) {
                $c->where('u.'.$columna, $filtros[$filtro]);
            }
        }

        return $c;
    }

    /** @return array{motivo: array<string, string>, origen: array<string, string>} codigo → nombre (incluye inactivos: lo historico no se pierde) */
    private function nombres(): array
    {
        $todos = CatalogoDefecto::query()->get();

        return [
            'motivo' => $todos->where('tipo', CatalogoDefecto::MOTIVO)->pluck('nombre', 'codigo')->all(),
            'origen' => $todos->where('tipo', CatalogoDefecto::ORIGEN)->pluck('nombre', 'codigo')->all(),
        ];
    }
}