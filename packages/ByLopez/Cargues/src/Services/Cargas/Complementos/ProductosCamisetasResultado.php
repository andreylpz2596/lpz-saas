<?php

namespace ByLopez\Cargues\Services\Cargas\Complementos;

use ByLopez\Cargues\Contracts\AgrupaResultado;
use ByLopez\Cargues\Contracts\ComplementaResultado;
use ByLopez\Cargues\Extensiones\ProductosCamisetas\AnalizadorCamisetas;
use ByLopez\Cargues\Extensiones\ProductosCamisetas\CatalogoCamisetas;
use ByLopez\Cargues\Extensiones\ProductosCamisetas\SkuCamisetas;
use ByLopez\Cargues\Models\Carga;
use ByLopez\Cargues\Support\Estados;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Complemento del tipo «Productos — Camisetas piel de durazno» (docs/17): SOLO LEE lo persistido (filas del archivo, derivados de la
 * intermedia, incidencias y, tras ejecutar, el producto que quedo en el catalogo por su SKU). Da:
 *  - por fila: producto base, plan de la variante y producto/variante en el catalogo;
 *  - la revision AGRUPADA (producto → variantes) de la validacion y del resultado, con conteos, motivos, pendientes y enlaces.
 */
class ProductosCamisetasResultado implements AgrupaResultado, ComplementaResultado
{
    public const TABLA = 'bylopez_cgi_productos_camisetas';

    public const MAX_GRUPOS = 300;

    public function complementar(Carga $carga, array $filas): array
    {
        if ($filas === [] || ! Schema::hasTable(self::TABLA)) {
            return [];
        }

        $inter = DB::table(self::TABLA)->where('cg_carga_id', $carga->id)->whereIn('cg_fila_origen', array_keys($filas))->get()->keyBy('cg_fila_origen');
        $mapa = $this->codigosDelPatron($carga, 'fila_origen');

        foreach ($filas as $n => &$fila) {
            isset($mapa[$n]) && $fila['valores'] = array_replace((array) ($fila['valores'] ?? []), $mapa[$n]);
        }
        unset($fila);

        $skus = [];

        foreach ($filas as $f) {
            $skus[] = (string) ($f['valores']['codigo_base'] ?? '');
            $skus[] = (string) ($f['valores']['sku_variante'] ?? '');
        }

        $productos = $this->productosPorSku($skus);
        $salida = [];

        foreach ($filas as $n => $f) {
            $i = $inter->get($n);
            $base = (string) ($f['valores']['codigo_base'] ?? '');
            $pares = [];

            if ($i && $i->grupo_estado) {
                $pares[] = ['etiqueta' => 'Producto base', 'valor' => '«'.$base.'» — '.$this->textoGrupo((string) $i->grupo_estado).($i->producto_id ? ' (#'.$i->producto_id.')' : '')];
            }

            if ($i && $i->fila_plan) {
                $pares[] = ['etiqueta' => 'Variante', 'valor' => ($i->fila_plan === AnalizadorCamisetas::CREAR_VARIANTE ? 'nueva' : 'ya existe (#'.$i->variante_id.')')];
            }

            if ($carga->estado === Estados::COMPLETADA && ($p = $productos[mb_strtolower((string) ($f['valores']['sku_variante'] ?? ''))] ?? null) && $f['estado'] !== Estados::FILA_RECHAZADA) {
                $pares[] = ['etiqueta' => 'En el catálogo', 'valor' => 'Variante #'.$p->id.' del producto #'.$p->parent_id];
            }

            if ($pares !== []) {
                $salida[$n] = $pares;
            }
        }

        return $salida;
    }

    public function agrupar(Carga $carga): array
    {
        $ejecutada = $carga->estado === Estados::COMPLETADA;
        $datos = DB::table('bylopez_cg_carga_datos')->where('carga_id', $carga->id)->where('estado_final', '!=', Estados::FILA_VACIA)
            ->orderBy('fila_origen')->get(['id', 'fila_origen', 'estado_final', 'detalle', 'datos_normalizados']);
        $inter = Schema::hasTable(self::TABLA) ? DB::table(self::TABLA)->where('cg_carga_id', $carga->id)->get()->keyBy('cg_carga_dato_id') : collect();
        $incidencias = DB::table('bylopez_cg_carga_incidencias')->where('carga_id', $carga->id)->orderBy('id')
            ->get(['dato_id', 'severidad', 'codigo', 'motivo'])->groupBy('dato_id');

        $grupos = [];
        $mapa = $this->codigosDelPatron($carga, 'id');

        foreach ($datos as $d) {
            $v = array_replace((array) (json_decode((string) $d->datos_normalizados, true)['valores'] ?? []), $mapa[(int) $d->id] ?? []);
            $base = trim((string) ($v['codigo_base'] ?? ''));
            $grupos[$base === '' ? '' : mb_strtolower($base)][] = ['d' => $d, 'v' => $v, 'i' => $inter->get($d->id), 'inc' => $incidencias->get($d->id) ?? collect()];
        }

        $productos = $this->productosPorSku(array_merge(...array_map(fn ($g) => [trim((string) ($g[0]['v']['codigo_base'] ?? ''))], array_values($grupos))));
        $n = ['nuevo' => 0, 'agregar' => 0, 'sin_cambio' => 0, 'bloqueado' => 0, 'variantes_nuevas' => 0, 'variantes_sin_cambio' => 0, 'creado' => 0, 'agregado' => 0, 'no_cargado' => 0, 'variantes_creadas' => 0, 'con_pendientes' => 0];
        $desconocidas = [];
        $pendientes = [];
        $salida = [];

        foreach ($grupos as $clave => $filas) {
            $estado = $ejecutada ? $this->estadoEjecutado($filas) : $this->estadoValidado($filas);
            $n[$estado]++;
            $elementos = [];
            $motivos = [];
            $avisos = [];

            foreach ($filas as $f) {
                [$texto, $tono] = $this->estadoFila($f, $ejecutada);
                $elementos[] = ['texto' => trim(($f['v']['color'] ?? '—').' / '.($f['v']['talla'] ?? '—')).' · '.($f['v']['sku_variante'] ?? '—').' · fila '.$f['d']->fila_origen, 'estado_texto' => $texto, 'tono' => $tono];

                if ($ejecutada) {
                    $f['d']->estado_final === Estados::FILA_APLICADA && $n['variantes_creadas']++;
                    $f['d']->estado_final === Estados::FILA_SIN_CAMBIO && $n['variantes_sin_cambio']++;
                } elseif ($f['i'] && $f['i']->bloqueo_codigo === null) {
                    $f['i']->fila_plan === AnalizadorCamisetas::CREAR_VARIANTE ? $n['variantes_nuevas']++ : $n['variantes_sin_cambio']++;
                }

                foreach ($f['inc'] as $inc) {
                    if ($inc->severidad === 'error' && ! in_array($inc->codigo, ['GRUPO_BLOQUEADO'], true)) {
                        $motivos[] = 'Fila '.$f['d']->fila_origen.' · '.$inc->motivo;
                    } elseif ($inc->severidad !== 'error') {
                        $avisos[] = $inc->motivo;
                    }
                }

                if (! $ejecutada && $f['i']) {
                    if ($f['i']->bloqueo_codigo !== null && ! in_array($f['i']->bloqueo_codigo, ['GRUPO_BLOQUEADO', 'FILA_RECHAZADA'], true)) {
                        $motivos[] = 'Fila '.$f['d']->fila_origen.' · '.$f['i']->bloqueo_mensaje;
                    }

                    foreach (array_filter(explode(',', (string) $f['i']->observaciones)) as $c) {
                        $avisos[] = AnalizadorCamisetas::mensajeObservacion($c);
                        $pendientes[$c] = ($pendientes[$c] ?? 0) + 1;
                    }
                }

                foreach (array_filter(explode('|', (string) ($f['i']->desconocidas ?? ''))) as $u) {
                    $desconocidas[$u][] = $f['d']->fila_origen;
                }
            }

            if (! $ejecutada && $estado !== AnalizadorCamisetas::BLOQUEADO && $carga->accion !== 'crear_activos' && $estado === AnalizadorCamisetas::NUEVO) {
                $avisos[] = AnalizadorCamisetas::mensajeObservacion('PRODUCTO_INACTIVO');
            }

            $avisos = array_values(array_unique($avisos));
            $avisos !== [] && $estado !== 'bloqueado' && $estado !== 'no_cargado' && $n['con_pendientes']++;
            $primera = $filas[0]['v'];
            $p = $clave === '' ? null : ($productos[$clave] ?? null);

            $salida[] = [
                'titulo' => ($primera['nombre'] ?? '—').' · '.($primera['codigo_base'] ?? 'sin código base'),
                'subtitulo' => trim(($primera['publico'] ?? '').' · '.count($filas).' '.(count($filas) === 1 ? 'variante' : 'variantes'), ' ·'),
                'estado' => $estado, 'estado_texto' => $this->textoGrupo($estado), 'tono' => $this->tonoGrupo($estado),
                'enlace' => $p && $p->type === 'configurable' && in_array($estado, ['creado', 'agregado', 'sin_cambio', 'agregar'], true) ? route('admin.catalog.products.edit', $p->id) : null,
                'enlace_texto' => $p ? 'Abrir producto #'.$p->id : null,
                'elementos' => $elementos,
                'motivos' => array_values(array_unique($motivos)),
                'avisos' => $estado === 'bloqueado' || $estado === 'no_cargado' ? [] : $avisos,
            ];
        }

        $orden = ['bloqueado' => 0, 'no_cargado' => 0, 'nuevo' => 1, 'creado' => 1, 'agregar' => 2, 'agregado' => 2, 'sin_cambio' => 3];
        usort($salida, fn ($a, $b) => [$orden[$a['estado']] ?? 9, $a['titulo']] <=> [$orden[$b['estado']] ?? 9, $b['titulo']]);

        $indicadores = $ejecutada ? [
            ['etiqueta' => 'Productos creados', 'n' => $n['creado']],
            ['etiqueta' => 'Productos existentes con variantes nuevas', 'n' => $n['agregado']],
            ['etiqueta' => 'Variantes creadas', 'n' => $n['variantes_creadas']],
            ['etiqueta' => 'Variantes sin cambio', 'n' => $n['variantes_sin_cambio']],
            ['etiqueta' => 'Productos no cargados', 'n' => $n['no_cargado']],
            ['etiqueta' => 'Productos con datos pendientes', 'n' => $n['con_pendientes']],
        ] : [
            ['etiqueta' => 'Productos nuevos', 'n' => $n['nuevo']],
            ['etiqueta' => 'Productos existentes con variantes nuevas', 'n' => $n['agregar']],
            ['etiqueta' => 'Variantes nuevas', 'n' => $n['variantes_nuevas']],
            ['etiqueta' => 'Variantes sin cambio', 'n' => $n['variantes_sin_cambio']],
            ['etiqueta' => 'Productos bloqueados', 'n' => $n['bloqueado']],
            ['etiqueta' => 'Opciones desconocidas', 'n' => count($desconocidas)],
        ];

        $secciones = [];

        if ($desconocidas !== []) {
            $secciones[] = ['titulo' => 'Opciones que no existen en el catálogo (créelas en Catálogo › Atributos o corrija el archivo; el cargue nunca las crea)', 'items' => array_map(
                fn ($k, $filas) => str_replace('=', ' «', $k).'» — '.(count(array_unique($filas)) === 1 ? 'fila ' : 'filas ').implode(', ', array_unique($filas)), array_keys($desconocidas), $desconocidas,
            )];
        }

        if (! $ejecutada && $pendientes !== []) {
            $secciones[] = ['titulo' => 'Datos pendientes (no bloquean; quedan para completar en Catálogo)', 'items' => array_map(
                fn ($c, $k) => AnalizadorCamisetas::mensajeObservacion($c).' — '.$k.' '.($k === 1 ? 'fila' : 'filas'), array_keys($pendientes), $pendientes,
            )];
        }

        return [
            'titulo' => $ejecutada ? 'Resultado por producto' : 'Revisión por producto (nada se ha aplicado todavía)',
            'nota' => $ejecutada ? null : 'Cada producto base se crea completo o no se crea: un producto bloqueado no deja un padre incompleto ni variantes sueltas, y no impide cargar los demás.',
            'indicadores' => $indicadores,
            'secciones' => $secciones,
            'grupos' => array_slice($salida, 0, self::MAX_GRUPOS),
            'truncado' => count($salida) > self::MAX_GRUPOS,
            'filtros' => array_values(array_map(fn ($e) => ['estado' => $e, 'etiqueta' => $this->textoGrupo($e)], array_unique(array_column($salida, 'estado')))),
        ];
    }

    protected function estadoValidado(array $filas): string
    {
        foreach ($filas as $f) {
            if ($f['d']->estado_final === Estados::FILA_RECHAZADA || ! $f['i'] || $f['i']->bloqueo_codigo !== null) {
                return AnalizadorCamisetas::BLOQUEADO;
            }
        }

        return (string) $filas[0]['i']->grupo_estado;
    }

    protected function estadoEjecutado(array $filas): string
    {
        $estados = array_map(fn ($f) => $f['d']->estado_final, $filas);

        if (in_array(Estados::FILA_RECHAZADA, $estados, true) || in_array(Estados::FILA_NO_EJECUTADA, $estados, true)) {
            return 'no_cargado';
        }

        $detalles = array_map(fn ($f) => $f['d']->detalle, $filas);

        return match (true) {
            in_array('producto_creado', $detalles, true) => 'creado',
            in_array('variante_agregada', $detalles, true) => 'agregado',
            default => 'sin_cambio',
        };
    }

    /** @return array{0:string, 1:string} */
    protected function estadoFila(array $f, bool $ejecutada): array
    {
        if ($ejecutada) {
            return match ($f['d']->estado_final) {
                Estados::FILA_APLICADA => ['Creada', 'verde'],
                Estados::FILA_SIN_CAMBIO => ['Sin cambio', 'gris'],
                default => ['No cargada', 'rojo'],
            };
        }

        if ($f['d']->estado_final === Estados::FILA_RECHAZADA || ! $f['i'] || $f['i']->bloqueo_codigo !== null) {
            return ['Bloqueada', 'rojo'];
        }

        return $f['i']->fila_plan === AnalizadorCamisetas::CREAR_VARIANTE ? ['Variante nueva', 'azul'] : ['Sin cambio', 'gris'];
    }

    protected function textoGrupo(string $estado): string
    {
        return match ($estado) {
            AnalizadorCamisetas::NUEVO => 'Producto nuevo',
            AnalizadorCamisetas::AGREGAR => 'Agregar variantes',
            AnalizadorCamisetas::SIN_CAMBIO => 'Sin cambio',
            AnalizadorCamisetas::BLOQUEADO => 'Bloqueado',
            'creado' => 'Producto creado',
            'agregado' => 'Variantes agregadas',
            'no_cargado' => 'No cargado',
            default => $estado,
        };
    }

    protected function tonoGrupo(string $estado): string
    {
        return match ($estado) {
            'nuevo', 'creado' => 'verde',
            'agregar', 'agregado' => 'azul',
            'bloqueado', 'no_cargado' => 'rojo',
            default => 'gris',
        };
    }

    /**
     * Doc. 33: códigos del patrón (115D-DZ / 115D-DZ-M) de las filas del archivo, como los usa la extensión. Solo lee.
     *
     * @return array<int, array{codigo_base:string, sku_variante:string}> fila_origen|id => códigos
     */
    protected function codigosDelPatron(Carga $carga, string $clave): array
    {
        if (! SkuCamisetas::activo()) {
            return [];
        }

        $filas = DB::table('bylopez_cg_carga_datos')->where('carga_id', $carga->id)->where('estado_final', '!=', Estados::FILA_VACIA)
            ->get(['id', 'fila_origen', 'datos_normalizados'])
            ->map(fn ($d) => ['id' => (int) $d->{$clave}, 'v' => (array) (json_decode((string) $d->datos_normalizados, true)['valores'] ?? [])])->all();

        return (new SkuCamisetas(new CatalogoCamisetas))->mapa($filas, false);
    }

    /** @return array<string, object> */
    protected function productosPorSku(array $skus): array
    {
        $skus = array_values(array_unique(array_filter(array_map('trim', $skus), fn ($s) => $s !== '')));
        $r = [];

        foreach (array_chunk($skus, 500) as $lote) {
            foreach (DB::table('products')->whereIn('sku', $lote)->get(['id', 'sku', 'type', 'parent_id']) as $p) {
                $r[mb_strtolower((string) $p->sku)] = $p;
            }
        }

        return $r;
    }
}
