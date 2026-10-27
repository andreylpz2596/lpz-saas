<?php

namespace ByLopez\CorteConfeccion\Services;

use ByLopez\CorteConfeccion\Enums\AdvertenciaResolucionVarianteCorte;
use ByLopez\CorteConfeccion\Enums\ErrorResolucionVarianteCorte;
use ByLopez\CorteConfeccion\Exceptions\ConfiguracionCatalogoCorteException;
use ByLopez\CorteConfeccion\Support\CompatibilidadRollosCorte;
use ByLopez\CorteConfeccion\Support\ResolucionVarianteCorte;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * F4: semantica de la seleccion de una Orden de Corte V2 (SOLO LECTURA).
 *
 * Pasa de "rollos elegidos" a "simples exactos" apoyandose EXCLUSIVAMENTE en
 * ResolvedorVarianteCorteService (F2): lineasPorTipoTela(), opcionesEjeLote() y
 * resolverLote() (una carga de catalogo por operacion, sin resolver celda por
 * celda). Aqui no se consulta el catalogo Bagisto directamente salvo las
 * etiquetas legibles (color/linea) para mensajes de error.
 *
 * Los colores permitidos salen UNICAMENTE de Rollo.color_option_id de los rollos
 * elegidos. El navegador nunca envia (ni se confia en) un product_id.
 */
class SeleccionOrdenCorteService
{
    public function __construct(protected ResolvedorVarianteCorteService $resolvedor) {}

    /**
     * Tela y colores de los rollos (valida tipo_tela_id/color_option_id y que
     * compartan tipo de tela).
     *
     * @return array{tipo_tela_id: int, tipo_tela: string, colores: array<int, string>, peso_total: float, rollos: int}
     */
    public function contexto(Collection $rollos): array
    {
        $compatibilidad = CompatibilidadRollosCorte::validar($rollos);

        return [
            'tipo_tela_id' => $compatibilidad['tipo_tela_id'],
            'tipo_tela' => (string) $rollos->first()->tipo_tela,
            'colores' => $compatibilidad['colores'],
            'peso_total' => round((float) $rollos->sum(fn ($rollo) => (float) $rollo->peso_disponible), 3),
            'rollos' => $rollos->count(),
        ];
    }

    /**
     * Matriz linea x color de los rollos, solo con combinaciones
     * estructuralmente reales: cada celda trae las opciones del eje 2 que
     * tienen hijos, o el motivo por el que el color no se puede cortar en esa
     * linea (sin producto, ambiguo, ejes no soportados...). Dos cargas de
     * catalogo en total, sin importar cuantas lineas o colores haya.
     */
    public function matriz(Collection $rollos): array
    {
        $contexto = $this->contexto($rollos);
        $lineas = $this->resolvedor->lineasPorTipoTela($contexto['tipo_tela_id']);

        $consultas = [];

        foreach ($lineas as $linea) {
            foreach ($contexto['colores'] as $colorId => $etiqueta) {
                $consultas[$linea['attribute_family_id'].':'.$colorId] = [
                    'attribute_family_id' => $linea['attribute_family_id'],
                    'color_option_id' => $colorId,
                ];
            }
        }

        $opciones = $consultas ? $this->resolvedor->opcionesEjeLote($contexto['tipo_tela_id'], $consultas) : [];

        return [
            'tela' => ['nombre' => $contexto['tipo_tela'], 'peso_total' => $contexto['peso_total'], 'rollos' => $contexto['rollos']],
            'colores' => collect($contexto['colores'])->map(fn ($etiqueta, $id) => ['option_id' => $id, 'label' => $etiqueta])->values()->all(),
            'lineas' => collect($lineas)->map(fn (array $linea) => [
                'attribute_family_id' => $linea['attribute_family_id'],
                'nombre' => $this->limpiar($linea['nombre'] ?? $linea['codigo']),
                'eje_etiqueta' => $linea['ejes'][0]['etiqueta'] ?? null,
                'colores' => collect($contexto['colores'])->map(function ($etiqueta, $colorId) use ($linea, $opciones) {
                    return $this->celda($etiqueta, $colorId, $opciones[$linea['attribute_family_id'].':'.$colorId]);
                })->values()->all(),
            ])->all(),
        ];
    }

    /**
     * Resuelve TODA la seleccion con resolverLote() (una sola carga de
     * catalogo). No lanza por errores de la seleccion: los devuelve legibles
     * para que la previsualizacion los muestre y el create los bloquee.
     *
     * @param  list<array<string, mixed>>  $seleccion  attribute_family_id, color_option_id, eje_option_id, filtros?
     * @return array{filas: list<array<string, mixed>>, errores: list<string>}
     */
    public function resolver(Collection $rollos, array $seleccion): array
    {
        $contexto = $this->contexto($rollos);
        $seleccion = array_values($seleccion);
        $errores = [];

        if ($seleccion === []) {
            return ['filas' => [], 'errores' => [trans('corteconfeccion::app.errors.seleccion-vacia')]];
        }

        $etiquetas = $this->etiquetas($seleccion);
        $validas = [];
        $vistas = [];

        foreach ($seleccion as $i => $fila) {
            $normalizada = [
                'attribute_family_id' => (int) ($fila['attribute_family_id'] ?? 0),
                'color_option_id' => (int) ($fila['color_option_id'] ?? 0),
                'eje_option_id' => (int) ($fila['eje_option_id'] ?? 0),
                'filtros' => array_map('intval', (array) ($fila['filtros'] ?? [])),
            ];

            $nombre = $this->nombreFila($normalizada, $etiquetas);

            if (! $normalizada['attribute_family_id'] || ! $normalizada['color_option_id'] || ! $normalizada['eje_option_id']) {
                $errores[] = trans('corteconfeccion::app.errors.seleccion-fila-invalida', ['fila' => $i + 1]);

                continue;
            }

            if (! array_key_exists($normalizada['color_option_id'], $contexto['colores'])) {
                $errores[] = $nombre.': '.trans('corteconfeccion::app.errors.seleccion-color-ajeno');

                continue;
            }

            $llave = json_encode([$normalizada['attribute_family_id'], $normalizada['color_option_id'], $normalizada['eje_option_id'], $normalizada['filtros']]);

            if (isset($vistas[$llave])) {
                $errores[] = $nombre.': '.trans('corteconfeccion::app.errors.seleccion-duplicada');

                continue;
            }

            $vistas[$llave] = true;
            $validas[$i] = $normalizada;
        }

        try {
            $resultados = $validas ? $this->resolvedor->resolverLote($contexto['tipo_tela_id'], $validas) : [];
        } catch (ConfiguracionCatalogoCorteException $e) {
            return ['filas' => [], 'errores' => array_merge($errores, [$e->getMessage()])];
        }

        $filas = [];

        foreach ($resultados as $i => $resultado) {
            if (! $resultado->resuelta()) {
                $errores[] = $this->nombreFila($validas[$i], $etiquetas).': '.$this->mensajeError($resultado);

                continue;
            }

            $filas[] = ['indice' => $i] + $this->fila($resultado);
        }

        return ['filas' => $filas, 'errores' => $errores];
    }

    /** @return array<string, mixed> */
    protected function celda(string $color, int $colorId, array $opciones): array
    {
        $error = $opciones['error'];

        return [
            'color_option_id' => $colorId,
            'color' => $color,
            'error' => $error?->value,
            'mensaje' => $error ? $this->mensajeCelda($error, $opciones['detalle']) : null,
            'advertencias' => array_map(fn (AdvertenciaResolucionVarianteCorte $a) => $a->mensaje(), $opciones['advertencias']),
            'opciones' => array_map(fn (array $o) => ['option_id' => $o['option_id'], 'label' => $o['label']], $opciones['opciones']),
        ];
    }

    /** @return array<string, mixed> */
    protected function fila(ResolucionVarianteCorte $resultado): array
    {
        $snapshot = $resultado->snapshot;

        return [
            'product_id' => $resultado->productId,
            'sku' => $resultado->sku,
            'nombre' => $resultado->configurable['nombre'] ?: $resultado->sku,
            'linea' => $this->limpiar($snapshot['linea']),
            'color' => $snapshot['color'],
            'talla' => $snapshot['eje_valor'],
            'eje_etiqueta' => $snapshot['eje_etiqueta'],
            'advertencias' => array_map(fn (AdvertenciaResolucionVarianteCorte $a) => $a->mensaje(), $resultado->advertencias),
        ];
    }

    protected function mensajeError(ResolucionVarianteCorte $resultado): string
    {
        return $this->mensajeCelda($resultado->error, $resultado->detalle);
    }

    /** Mensaje comprensible; los SKU de los productos en conflicto ayudan a corregir el catalogo. */
    protected function mensajeCelda(ErrorResolucionVarianteCorte $error, array $detalle): string
    {
        $mensaje = $error->mensaje();

        $skus = collect($detalle['configurables'] ?? [])->pluck('sku')
            ->merge(collect($detalle['simples'] ?? [])->pluck('sku'))
            ->filter()
            ->implode(', ');

        return $skus !== '' ? $mensaje.' ('.$skus.')' : $mensaje;
    }

    protected function nombreFila(array $fila, array $etiquetas): string
    {
        return implode(' · ', [
            $etiquetas['familias'][$fila['attribute_family_id']] ?? 'Linea',
            $etiquetas['opciones'][$fila['color_option_id']] ?? 'Color',
            $etiquetas['opciones'][$fila['eje_option_id']] ?? 'Talla',
        ]);
    }

    /**
     * Etiquetas legibles de las opciones y familias de la seleccion (2
     * consultas): solo para mensajes; nunca participan en la resolucion.
     *
     * @return array{opciones: array<int, string>, familias: array<int, string>}
     */
    protected function etiquetas(array $seleccion): array
    {
        $opcionIds = collect($seleccion)->flatMap(fn ($f) => [(int) ($f['color_option_id'] ?? 0), (int) ($f['eje_option_id'] ?? 0)])->filter()->unique()->all();
        $familiaIds = collect($seleccion)->map(fn ($f) => (int) ($f['attribute_family_id'] ?? 0))->filter()->unique()->all();

        return [
            'opciones' => $opcionIds ? DB::table('attribute_options')->whereIn('id', $opcionIds)->pluck('admin_name', 'id')->all() : [],
            'familias' => $familiaIds ? DB::table('attribute_families')->whereIn('id', $familiaIds)->pluck('name', 'id')->map(fn ($n) => $this->limpiar($n))->all() : [],
        ];
    }

    protected function limpiar(?string $texto): string
    {
        return trim((string) preg_replace('/\s+/', ' ', (string) $texto));
    }
}
