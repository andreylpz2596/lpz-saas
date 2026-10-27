<?php

namespace ByLopez\CorteConfeccion\Services;

use ByLopez\CorteConfeccion\Enums\AdvertenciaResolucionVarianteCorte;
use ByLopez\CorteConfeccion\Enums\ErrorResolucionVarianteCorte;
use ByLopez\CorteConfeccion\Exceptions\ConfiguracionCatalogoCorteException;
use ByLopez\CorteConfeccion\Support\ResolucionVarianteCorte;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Servicio de SOLO LECTURA que traduce una seleccion de Orden de Corte
 * (tipo de tela + linea + color + valor del eje 2) al simple exacto de Bagisto.
 *
 * Es el unico punto del modulo nuevo de Orden de Corte que conoce como esta
 * modelado el catalogo (products / product_super_attributes /
 * product_attribute_values): ningun Controller, Blade, Request u otro Service
 * debe consultar esas tablas para esto. Nunca escribe, nunca reindexa, nunca
 * toca packages/Webkul.
 *
 * Estructura real del catalogo (docs/ORDEN_CORTE_CREACION_V2_AUDITORIA_02.md):
 * un configurable = linea x UN color; la "linea" es la AttributeFamily. Por eso
 * la resolucion ocurre en DOS niveles, ambos con unicidad obligatoria:
 *
 *   (tipo de tela, linea, color) -> exactamente 1 configurable
 *   (configurable, color, eje 2) -> exactamente 1 simple
 *
 * Reglas de diseno:
 *  - Atributos siempre por attributes.code, nunca por ID. Si falta uno
 *    estructural se lanza ConfiguracionCatalogoCorteException (falla cerrado).
 *  - Color y eje 2 siempre por attribute_options.id, nunca por texto.
 *  - El tipo de tela sale del atributo `tipo_tela` del configurable.
 *  - "Linea" hoy = attribute_family_id; los `filtros` opcionales
 *    (['codigo_atributo' => option_id], p. ej. ['cuello' => 12]) ya estan
 *    soportados para que una futura linea sea familia + filtros estructurados.
 *  - El estado (`status`) se lee del EAV por canal por defecto, NUNCA de
 *    product_flat (puede estar desalineado). Un producto inactivo en tienda
 *    puede seguir siendo valido para produccion: solo genera advertencia.
 *    Stock y vendibilidad no son criterio.
 *  - Consultas por lote (cantidad fija por llamada, independiente de cuantas
 *    selecciones se pidan) y resolucion en memoria: sin N+1. Cada llamada
 *    publica lee el catalogo de nuevo; el servicio no guarda cache.
 */
class ResolvedorVarianteCorteService
{
    public const ATRIBUTO_COLOR = 'color';

    public const ATRIBUTO_TIPO_TELA = 'tipo_tela';

    public const ATRIBUTO_STATUS = 'status';

    public const SEVERIDAD_ERROR = 'error';

    public const SEVERIDAD_ADVERTENCIA = 'advertencia';

    // Codigos de diagnostico propios (los que coinciden con un error o
    // advertencia de resolucion reutilizan el valor de su enum).
    public const DIAG_CONFIGURABLE_MULTICOLOR = 'configurable_multicolor';

    public const DIAG_CONFIGURABLE_SIN_TIPO_TELA = 'configurable_sin_tipo_tela';

    public const DIAG_SIMPLE_SIN_COLOR = 'simple_sin_color';

    public const DIAG_SIMPLE_SIN_EJE = 'simple_sin_eje';

    public const DIAG_SIMPLE_VALOR_MULTIPLE = 'simple_valor_multiple';

    public const DIAG_SIMPLE_FAMILIA_DISTINTA = 'simple_familia_distinta_de_padre';

    public const DIAG_STATUS_DESALINEADO = 'status_desalineado_eav_flat';

    private const MENSAJES_DIAGNOSTICO = [
        'color_ambiguo' => 'La misma linea (familia), tela y color aparece en mas de un configurable.',
        self::DIAG_CONFIGURABLE_MULTICOLOR => 'El configurable tiene hijos de mas de un color.',
        self::DIAG_CONFIGURABLE_SIN_TIPO_TELA => 'El configurable no tiene tipo_tela: no es candidato para produccion (puede ser intencional si no es un producto textil).',
        'ejes_no_soportados' => 'El configurable no tiene exactamente color + un eje adicional; falla cerrado.',
        self::DIAG_SIMPLE_SIN_COLOR => 'El simple no tiene valor de color.',
        self::DIAG_SIMPLE_SIN_EJE => 'El simple no tiene valor del eje 2 (talla/grupo de edad).',
        self::DIAG_SIMPLE_VALOR_MULTIPLE => 'El simple tiene mas de un valor guardado para color o eje 2.',
        self::DIAG_SIMPLE_FAMILIA_DISTINTA => 'El simple pertenece a una familia distinta a la de su configurable.',
        'variante_duplicada' => 'Mas de un simple con el mismo configurable, color y valor del eje 2.',
        self::DIAG_STATUS_DESALINEADO => 'El status EAV (fuente) difiere de product_flat (indice). Informativo: la resolucion usa el EAV.',
        'configurable_inactivo_en_tienda' => 'Configurable inactivo en tienda. No es un error de produccion.',
        'variante_inactiva_en_tienda' => 'Simple inactivo en tienda. No es un error de produccion.',
    ];

    // ---------------------------------------------------------------------
    // API publica
    // ---------------------------------------------------------------------

    /**
     * Lineas (familias) que tienen al menos un configurable soportado asociado
     * a la tela, derivadas del catalogo (sin tabla ni config propia).
     *
     * @return list<array{attribute_family_id: int, codigo: ?string, nombre: ?string, configurables: int, configurables_no_soportados: int, ejes: list<array{attribute_id: int, code: string, etiqueta: ?string}>}>
     */
    public function lineasPorTipoTela(int $tipoTelaOptionId): array
    {
        $catalogo = $this->cargarCatalogo($tipoTelaOptionId);

        return collect($catalogo['configurables'])
            ->groupBy('familia_id')
            ->map(function (Collection $grupo, $familiaId) use ($catalogo) {
                $soportados = $grupo->where('soportado', true);

                if ($soportados->isEmpty()) {
                    return null;
                }

                return [
                    'attribute_family_id' => (int) $familiaId,
                    'codigo' => $catalogo['familias'][$familiaId]['codigo'] ?? null,
                    'nombre' => $catalogo['familias'][$familiaId]['nombre'] ?? null,
                    'configurables' => $soportados->count(),
                    'configurables_no_soportados' => $grupo->count() - $soportados->count(),
                    'ejes' => $soportados->pluck('eje2')->unique('attribute_id')->values()->all(),
                ];
            })
            ->filter()
            ->sortBy([['nombre', 'asc'], ['attribute_family_id', 'asc']])
            ->values()
            ->all();
    }

    /**
     * Opciones REALES del eje 2 (talla, grupo de edad...) para una linea y un
     * color: solo las que tienen al menos un hijo, ordenadas por
     * attribute_options.sort_order. Ver opcionesEjeLote() para la forma.
     */
    public function opcionesEje(int $tipoTelaOptionId, int $attributeFamilyId, int $colorOptionId, array $filtros = []): array
    {
        return $this->opcionesEjeLote($tipoTelaOptionId, [[
            'attribute_family_id' => $attributeFamilyId,
            'color_option_id' => $colorOptionId,
            'filtros' => $filtros,
        ]])[0];
    }

    /**
     * Version por lote de opcionesEje() para la futura matriz linea x color:
     * el catalogo se lee una sola vez sin importar cuantas selecciones haya.
     * Cada seleccion: attribute_family_id, color_option_id, filtros? (opcional).
     * Conserva las claves de la entrada.
     *
     * Cada resultado:
     *   error         ?ErrorResolucionVarianteCorte (null si resolvio el configurable)
     *   detalle       contexto del error (candidatos, SKUs, IDs)
     *   configurable  ?{id, sku, nombre, activo}
     *   linea         {attribute_family_id, codigo, nombre}
     *   eje           ?{attribute_id, code, etiqueta}
     *   opciones      list<{option_id, label, sort_order, variantes}>
     *   advertencias  list<AdvertenciaResolucionVarianteCorte>
     *
     * @return array<int|string, array<string, mixed>>
     */
    public function opcionesEjeLote(int $tipoTelaOptionId, iterable $selecciones): array
    {
        $selecciones = $this->normalizarSelecciones($selecciones);
        $catalogo = $this->cargarCatalogo($tipoTelaOptionId, $this->codigosFiltro($selecciones));

        return array_map(fn (array $seleccion) => $this->opcionesDeSeleccion($catalogo, $seleccion), $selecciones);
    }

    /**
     * Resuelve (tela, linea, color, valor del eje 2) al simple exacto.
     * Nunca devuelve un configurable.
     */
    public function resolver(int $tipoTelaOptionId, int $attributeFamilyId, int $colorOptionId, int $ejeOptionId, array $filtros = []): ResolucionVarianteCorte
    {
        return $this->resolverLote($tipoTelaOptionId, [[
            'attribute_family_id' => $attributeFamilyId,
            'color_option_id' => $colorOptionId,
            'eje_option_id' => $ejeOptionId,
            'filtros' => $filtros,
        ]])[0];
    }

    /**
     * Version por lote de resolver(): una sola lectura del catalogo para toda
     * la matriz. Cada combinacion: attribute_family_id, color_option_id,
     * eje_option_id, filtros? (opcional). Conserva las claves de la entrada.
     *
     * @return array<int|string, ResolucionVarianteCorte>
     */
    public function resolverLote(int $tipoTelaOptionId, iterable $combinaciones): array
    {
        $combinaciones = $this->normalizarSelecciones($combinaciones);
        $catalogo = $this->cargarCatalogo($tipoTelaOptionId, $this->codigosFiltro($combinaciones));

        return array_map(fn (array $combinacion) => $this->resolverSeleccion($catalogo, $combinacion), $combinaciones);
    }

    /**
     * Diagnostico de catalogo, de SOLO LECTURA. Sin $tipoTelaOptionId revisa
     * todos los configurables del catalogo (incluye los que no tienen tela).
     *
     * @return array{alcance: array, totales: array, por_codigo: array<string, int>, hallazgos: list<array<string, mixed>>, configurables_afectados: list<array<string, mixed>>}
     */
    public function diagnosticarCatalogo(?int $tipoTelaOptionId = null): array
    {
        $catalogo = $this->cargarCatalogo($tipoTelaOptionId);
        $estadosFlat = $this->estadosProductFlat($catalogo);

        $hallazgos = [];

        foreach ($catalogo['configurables'] as $configurable) {
            array_push($hallazgos, ...$this->hallazgosDeConfigurable($catalogo, $configurable, $estadosFlat));
        }

        array_push($hallazgos, ...$this->hallazgosColorAmbiguo($catalogo));

        // Errores primero; dentro de cada severidad, por codigo, familia y SKU.
        $orden = fn (array $h) => [
            $h['severidad'] === self::SEVERIDAD_ERROR ? 0 : 1,
            $h['codigo'],
            (string) $h['familia'],
            $h['configurables'][0]['sku'] ?? '',
            $h['simples'][0]['sku'] ?? '',
        ];

        usort($hallazgos, fn (array $a, array $b) => $orden($a) <=> $orden($b));

        $porCodigo = array_count_values(array_column($hallazgos, 'codigo'));
        ksort($porCodigo);

        return [
            'alcance' => [
                'tipo_tela_option_id' => $tipoTelaOptionId,
                'canal' => $catalogo['canal'],
            ],
            'totales' => [
                'configurables' => count($catalogo['configurables']),
                'simples' => array_sum(array_map(fn (array $k) => count($k['hijos']), $catalogo['configurables'])),
                'errores' => count(array_filter($hallazgos, fn (array $h) => $h['severidad'] === self::SEVERIDAD_ERROR)),
                'advertencias' => count(array_filter($hallazgos, fn (array $h) => $h['severidad'] === self::SEVERIDAD_ADVERTENCIA)),
            ],
            'por_codigo' => $porCodigo,
            'hallazgos' => $hallazgos,
            'configurables_afectados' => $this->configurablesAfectados($hallazgos),
        ];
    }

    // ---------------------------------------------------------------------
    // Resolucion en memoria
    // ---------------------------------------------------------------------

    protected function resolverSeleccion(array $catalogo, array $seleccion): ResolucionVarianteCorte
    {
        [$configurable, $error, $detalle] = $this->resolverConfigurable($catalogo, $seleccion);

        if ($error) {
            return ResolucionVarianteCorte::conError($error, $detalle, $configurable ? $this->resumenConfigurable($configurable) : null);
        }

        $resumen = $this->resumenConfigurable($configurable);

        $coincidentes = array_values(array_filter(
            $configurable['hijos'],
            fn (array $hijo) => $hijo['color'] === $seleccion['color_option_id'] && $hijo['eje'] === $seleccion['eje_option_id']
        ));

        if (count($coincidentes) === 0) {
            return ResolucionVarianteCorte::conError(ErrorResolucionVarianteCorte::TallaNoDisponible, [
                'eje_option_id' => $seleccion['eje_option_id'],
            ], $resumen);
        }

        if (count($coincidentes) > 1) {
            return ResolucionVarianteCorte::conError(ErrorResolucionVarianteCorte::VarianteDuplicada, [
                'simples' => array_map(fn (array $hijo) => ['id' => $hijo['id'], 'sku' => $hijo['sku']], $coincidentes),
            ], $resumen);
        }

        $hijo = $coincidentes[0];

        // Postcondiciones defensivas: el simple debe ser hijo del configurable
        // (garantizado por la consulta) y de la misma familia que la linea.
        if ($hijo['familia_id'] !== $seleccion['attribute_family_id']) {
            return ResolucionVarianteCorte::conError(ErrorResolucionVarianteCorte::PostcondicionIncumplida, [
                'simple' => ['id' => $hijo['id'], 'sku' => $hijo['sku']],
                'attribute_family_id_simple' => $hijo['familia_id'],
                'attribute_family_id_esperada' => $seleccion['attribute_family_id'],
            ], $resumen);
        }

        $advertencias = [];

        if (! $configurable['activo']) {
            $advertencias[] = AdvertenciaResolucionVarianteCorte::ConfigurableInactivoEnTienda;
        }

        if (! $hijo['activo']) {
            $advertencias[] = AdvertenciaResolucionVarianteCorte::VarianteInactivaEnTienda;
        }

        return ResolucionVarianteCorte::conExito($hijo['id'], $hijo['sku'], $resumen, [
            'attribute_family_id' => $seleccion['attribute_family_id'],
            'linea' => $catalogo['familias'][$seleccion['attribute_family_id']]['nombre'] ?? null,
            'color_option_id' => $seleccion['color_option_id'],
            'color' => $this->etiquetaOpcion($catalogo, $seleccion['color_option_id']),
            'eje_attribute_id' => $configurable['eje2']['attribute_id'],
            'eje_code' => $configurable['eje2']['code'],
            'eje_etiqueta' => $configurable['eje2']['etiqueta'],
            'eje_option_id' => $seleccion['eje_option_id'],
            'eje_valor' => $this->etiquetaOpcion($catalogo, $seleccion['eje_option_id']),
        ], $advertencias);
    }

    /**
     * Nivel 1: (tela, linea, color) -> configurable unico. Se consideran TODOS
     * los hijos de cada candidato (activos o no) porque la ambiguedad es
     * estructural. Devuelve [configurable|null, error|null, detalle].
     */
    protected function resolverConfigurable(array $catalogo, array $seleccion): array
    {
        $candidatos = $this->candidatosDeLinea($catalogo, $seleccion['attribute_family_id'], $seleccion['filtros']);

        $conColor = array_values(array_filter(
            $candidatos,
            fn (array $configurable) => in_array($seleccion['color_option_id'], array_column($configurable['hijos'], 'color'), true)
        ));

        if (count($conColor) === 0) {
            return [null, ErrorResolucionVarianteCorte::SinProductoParaColor, ['configurables_candidatos' => count($candidatos)]];
        }

        if (count($conColor) > 1) {
            return [null, ErrorResolucionVarianteCorte::ColorAmbiguo, [
                'configurables' => array_map(fn (array $k) => $this->resumenConfigurable($k), $conColor),
            ]];
        }

        $configurable = $conColor[0];

        if (! $configurable['soportado']) {
            return [$configurable, ErrorResolucionVarianteCorte::EjesNoSoportados, [
                'motivo' => $configurable['motivo_no_soportado'],
                'ejes' => array_column($configurable['ejes'], 'code'),
            ]];
        }

        return [$configurable, null, []];
    }

    protected function opcionesDeSeleccion(array $catalogo, array $seleccion): array
    {
        $familia = $catalogo['familias'][$seleccion['attribute_family_id']] ?? null;

        $resultado = [
            'error' => null,
            'detalle' => [],
            'configurable' => null,
            'linea' => [
                'attribute_family_id' => $seleccion['attribute_family_id'],
                'codigo' => $familia['codigo'] ?? null,
                'nombre' => $familia['nombre'] ?? null,
            ],
            'eje' => null,
            'opciones' => [],
            'advertencias' => [],
        ];

        [$configurable, $error, $detalle] = $this->resolverConfigurable($catalogo, $seleccion);

        $resultado['error'] = $error;
        $resultado['detalle'] = $detalle;
        $resultado['configurable'] = $configurable ? $this->resumenConfigurable($configurable) : null;

        if ($error) {
            return $resultado;
        }

        $resultado['eje'] = $configurable['eje2'];

        if (! $configurable['activo']) {
            $resultado['advertencias'][] = AdvertenciaResolucionVarianteCorte::ConfigurableInactivoEnTienda;
        }

        $variantesPorOpcion = [];

        foreach ($configurable['hijos'] as $hijo) {
            if ($hijo['color'] === $seleccion['color_option_id'] && $hijo['eje'] !== null) {
                $variantesPorOpcion[$hijo['eje']] = ($variantesPorOpcion[$hijo['eje']] ?? 0) + 1;
            }
        }

        $opciones = [];

        foreach ($variantesPorOpcion as $opcionId => $variantes) {
            $opciones[] = [
                'option_id' => $opcionId,
                'label' => $this->etiquetaOpcion($catalogo, $opcionId),
                'sort_order' => $catalogo['opciones'][$opcionId]['sort_order'] ?? null,
                'variantes' => $variantes,
            ];
        }

        usort($opciones, fn (array $a, array $b) => [$a['sort_order'] ?? PHP_INT_MAX, $a['option_id']] <=> [$b['sort_order'] ?? PHP_INT_MAX, $b['option_id']]);

        $resultado['opciones'] = $opciones;

        return $resultado;
    }

    /**
     * Configurables candidatos de una linea: misma familia y, si hay filtros
     * opcionales, con el valor exacto de cada atributo filtrado.
     */
    protected function candidatosDeLinea(array $catalogo, int $familiaId, array $filtros): array
    {
        $candidatos = [];

        foreach ($catalogo['por_familia'][$familiaId] ?? [] as $configurableId) {
            $configurable = $catalogo['configurables'][$configurableId];

            foreach ($filtros as $codigo => $opcionId) {
                if (($configurable['filtros'][$codigo] ?? null) !== $opcionId) {
                    continue 2;
                }
            }

            $candidatos[] = $configurable;
        }

        return $candidatos;
    }

    /**
     * @return array<int|string, array{attribute_family_id: int, color_option_id: int, eje_option_id: int, filtros: array<string, int>}>
     */
    protected function normalizarSelecciones(iterable $selecciones): array
    {
        $normalizadas = [];

        foreach ($selecciones as $clave => $seleccion) {
            $normalizadas[$clave] = [
                'attribute_family_id' => (int) ($seleccion['attribute_family_id'] ?? 0),
                'color_option_id' => (int) ($seleccion['color_option_id'] ?? 0),
                'eje_option_id' => (int) ($seleccion['eje_option_id'] ?? 0),
                'filtros' => array_map('intval', (array) ($seleccion['filtros'] ?? [])),
            ];
        }

        return $normalizadas;
    }

    /**
     * Codigos de atributo usados como filtro en alguna seleccion: hay que
     * cargar su valor para los configurables del lote.
     *
     * @return list<string>
     */
    protected function codigosFiltro(array $selecciones): array
    {
        $codigos = [];

        foreach ($selecciones as $seleccion) {
            foreach (array_keys($seleccion['filtros']) as $codigo) {
                $codigos[(string) $codigo] = true;
            }
        }

        return array_map('strval', array_keys($codigos));
    }

    // ---------------------------------------------------------------------
    // Lectura del catalogo (unico lugar que toca tablas de Bagisto)
    // ---------------------------------------------------------------------

    /**
     * Lee el catalogo relevante en un numero FIJO de consultas y lo devuelve
     * como estructura en memoria. Con $tipoTelaOptionId solo trae los
     * configurables con esa tela; sin el, trae todos (uso: diagnostico).
     *
     * @return array{atributos: array<string, int>, canal: string, configurables: array<int, array>, por_familia: array<int, list<int>>, opciones: array<int, array>, familias: array<int, array>}
     */
    protected function cargarCatalogo(?int $tipoTelaOptionId, array $codigosFiltro = []): array
    {
        $atributos = $this->resolverAtributos($codigosFiltro);

        $catalogo = [
            'atributos' => $atributos,
            'canal' => $this->resolverCanal(),
            'configurables' => [],
            'por_familia' => [],
            'opciones' => [],
            'familias' => [],
        ];

        $filas = $this->consultarConfigurables($atributos, $tipoTelaOptionId);

        if ($filas->isEmpty()) {
            return $catalogo;
        }

        $configurableIds = $filas->pluck('id')->map(fn ($id) => (int) $id)->all();
        $ejesPorConfigurable = $this->consultarEjes($configurableIds);

        $hijos = DB::table('products')
            ->whereIn('parent_id', $configurableIds)
            ->where('type', 'simple')
            ->orderBy('id')
            ->get(['id', 'sku', 'parent_id', 'attribute_family_id']);

        foreach ($filas as $fila) {
            $ejes = $ejesPorConfigurable->get($fila->id, collect())
                ->map(fn ($eje) => [
                    'attribute_id' => (int) $eje->attribute_id,
                    'code' => $eje->code,
                    'etiqueta' => $eje->admin_name,
                ])
                ->values()
                ->all();

            [$eje2, $motivo] = $this->evaluarEjes($ejes);

            $catalogo['configurables'][(int) $fila->id] = [
                'id' => (int) $fila->id,
                'sku' => $fila->sku,
                'nombre' => null,
                'familia_id' => (int) $fila->attribute_family_id,
                'activo' => false,
                'tipo_tela' => null,
                'filtros' => [],
                'ejes' => $ejes,
                'eje2' => $eje2,
                'soportado' => $eje2 !== null,
                'motivo_no_soportado' => $motivo,
                'hijos' => [],
            ];

            $catalogo['por_familia'][(int) $fila->attribute_family_id][] = (int) $fila->id;
        }

        $valores = $this->consultarValores($catalogo, $hijos);

        foreach ($catalogo['configurables'] as $id => &$configurable) {
            $configurable['activo'] = $valores['estados'][$id] ?? false;
            [$configurable['tipo_tela']] = $this->valorUnico($valores['enteros'], $id, $atributos[self::ATRIBUTO_TIPO_TELA]);

            foreach ($codigosFiltro as $codigo) {
                [$configurable['filtros'][$codigo]] = $this->valorUnico($valores['enteros'], $id, $atributos[$codigo]);
            }
        }
        unset($configurable);

        foreach ($hijos as $hijo) {
            $padre = (int) $hijo->parent_id;
            $eje2Id = $catalogo['configurables'][$padre]['eje2']['attribute_id'] ?? null;

            [$color, $colorMultiple] = $this->valorUnico($valores['enteros'], (int) $hijo->id, $atributos[self::ATRIBUTO_COLOR]);
            [$eje, $ejeMultiple] = $eje2Id ? $this->valorUnico($valores['enteros'], (int) $hijo->id, $eje2Id) : [null, false];

            $catalogo['configurables'][$padre]['hijos'][(int) $hijo->id] = [
                'id' => (int) $hijo->id,
                'sku' => $hijo->sku,
                'familia_id' => (int) $hijo->attribute_family_id,
                'activo' => $valores['estados'][(int) $hijo->id] ?? false,
                'color' => $color,
                'eje' => $eje,
                'color_multiple' => $colorMultiple,
                'eje_multiple' => $ejeMultiple,
            ];
        }

        $catalogo['opciones'] = $this->consultarOpciones($catalogo);
        $catalogo['familias'] = $this->consultarFamilias($catalogo);

        $nombres = DB::table('product_flat')
            ->whereIn('product_id', $configurableIds)
            ->where('locale', app()->getLocale())
            ->pluck('name', 'product_id');

        foreach ($catalogo['configurables'] as $id => &$configurable) {
            $configurable['nombre'] = $nombres->get($id);
        }
        unset($configurable);

        return $catalogo;
    }

    /**
     * @return array<string, int> code => attributes.id
     */
    protected function resolverAtributos(array $codigosFiltro): array
    {
        $codigos = array_values(array_unique(array_merge(
            [self::ATRIBUTO_COLOR, self::ATRIBUTO_TIPO_TELA, self::ATRIBUTO_STATUS],
            $codigosFiltro
        )));

        $atributos = DB::table('attributes')
            ->whereIn('code', $codigos)
            ->pluck('id', 'code')
            ->map(fn ($id) => (int) $id)
            ->all();

        $faltantes = array_values(array_diff($codigos, array_keys($atributos)));

        if ($faltantes) {
            throw new ConfiguracionCatalogoCorteException(sprintf(
                'Configuracion de catalogo incompleta para Orden de Corte: no existe en Bagisto el atributo con code [%s]. '
                .'Se buscan por code (attributes.code), nunca por ID: creelos o corrijalos en Catalogo > Atributos.',
                implode(', ', $faltantes)
            ));
        }

        return $atributos;
    }

    /**
     * Se resuelve el canal por defecto (determinista), nunca el "actual", que
     * depende del Host HTTP (ver docs/confeccion/04A_STOCK_VARIANTE_DISENO.md §6).
     */
    protected function resolverCanal(): string
    {
        $codigo = core()->getDefaultChannel()?->code;

        if (! $codigo) {
            throw new ConfiguracionCatalogoCorteException(
                'No existe un canal por defecto en Bagisto: no se puede leer el status de los productos por canal.'
            );
        }

        return $codigo;
    }

    protected function consultarConfigurables(array $atributos, ?int $tipoTelaOptionId): Collection
    {
        return DB::table('products')
            ->where('products.type', 'configurable')
            ->when($tipoTelaOptionId !== null, function ($query) use ($atributos, $tipoTelaOptionId) {
                $query->whereExists(function ($subquery) use ($atributos, $tipoTelaOptionId) {
                    $subquery->select(DB::raw(1))
                        ->from('product_attribute_values as pav_tela')
                        ->whereColumn('pav_tela.product_id', 'products.id')
                        ->where('pav_tela.attribute_id', $atributos[self::ATRIBUTO_TIPO_TELA])
                        ->where('pav_tela.integer_value', $tipoTelaOptionId);
                });
            })
            ->orderBy('products.id')
            ->get(['products.id', 'products.sku', 'products.attribute_family_id']);
    }

    protected function consultarEjes(array $configurableIds): Collection
    {
        return DB::table('product_super_attributes')
            ->join('attributes', 'attributes.id', '=', 'product_super_attributes.attribute_id')
            ->whereIn('product_super_attributes.product_id', $configurableIds)
            ->orderBy('attributes.id')
            ->get([
                'product_super_attributes.product_id as configurable_id',
                'attributes.id as attribute_id',
                'attributes.code',
                'attributes.admin_name',
            ])
            ->groupBy('configurable_id');
    }

    /**
     * Valores EAV de todos los productos del lote en UNA consulta. Los atributos
     * select (color, tela, ejes, filtros) no son por canal: se guardan todos los
     * enteros distintos para detectar valores multiples. El status si es por
     * canal: solo cuenta la fila del canal por defecto.
     *
     * @return array{enteros: array<int, array<int, array<int, true>>>, estados: array<int, bool>}
     */
    protected function consultarValores(array $catalogo, Collection $hijos): array
    {
        $eje2Ids = collect($catalogo['configurables'])->pluck('eje2.attribute_id')->filter()->all();
        $atributoIds = array_values(array_unique(array_merge(array_values($catalogo['atributos']), $eje2Ids)));
        $productIds = array_merge(array_keys($catalogo['configurables']), $hijos->pluck('id')->map(fn ($id) => (int) $id)->all());
        $statusId = $catalogo['atributos'][self::ATRIBUTO_STATUS];

        $enteros = [];
        $estados = [];

        $filas = DB::table('product_attribute_values')
            ->whereIn('product_id', $productIds)
            ->whereIn('attribute_id', $atributoIds)
            ->get(['product_id', 'attribute_id', 'integer_value', 'boolean_value', 'channel']);

        foreach ($filas as $fila) {
            if ((int) $fila->attribute_id === $statusId) {
                if ($fila->channel === $catalogo['canal']) {
                    $estados[(int) $fila->product_id] = (bool) $fila->boolean_value;
                }

                continue;
            }

            if ($fila->integer_value !== null) {
                $enteros[(int) $fila->product_id][(int) $fila->attribute_id][(int) $fila->integer_value] = true;
            }
        }

        return ['enteros' => $enteros, 'estados' => $estados];
    }

    /**
     * Opciones (label + sort_order) de todos los option_id que aparecen en el
     * catalogo cargado, en una sola consulta.
     */
    protected function consultarOpciones(array $catalogo): array
    {
        $opcionIds = [];

        foreach ($catalogo['configurables'] as $configurable) {
            $opcionIds[] = $configurable['tipo_tela'];
            array_push($opcionIds, ...array_values($configurable['filtros']));

            foreach ($configurable['hijos'] as $hijo) {
                $opcionIds[] = $hijo['color'];
                $opcionIds[] = $hijo['eje'];
            }
        }

        $opcionIds = array_values(array_unique(array_filter($opcionIds, fn ($id) => $id !== null)));

        if (! $opcionIds) {
            return [];
        }

        return DB::table('attribute_options')
            ->whereIn('id', $opcionIds)
            ->get(['id', 'attribute_id', 'admin_name', 'sort_order'])
            ->mapWithKeys(fn ($opcion) => [(int) $opcion->id => [
                'id' => (int) $opcion->id,
                'attribute_id' => (int) $opcion->attribute_id,
                'label' => $opcion->admin_name ?: 'No definido',
                'sort_order' => $opcion->sort_order === null ? null : (int) $opcion->sort_order,
            ]])
            ->all();
    }

    protected function consultarFamilias(array $catalogo): array
    {
        $familiaIds = array_keys($catalogo['por_familia']);

        foreach ($catalogo['configurables'] as $configurable) {
            foreach ($configurable['hijos'] as $hijo) {
                $familiaIds[] = $hijo['familia_id'];
            }
        }

        return DB::table('attribute_families')
            ->whereIn('id', array_values(array_unique($familiaIds)))
            ->get(['id', 'code', 'name'])
            ->mapWithKeys(fn ($familia) => [(int) $familia->id => [
                'id' => (int) $familia->id,
                'codigo' => $familia->code,
                'nombre' => $familia->name,
            ]])
            ->all();
    }

    /**
     * Un configurable es soportado si tiene exactamente 2 super atributos y uno
     * es `color`; el otro es el eje 2 (size, grupo_de_edad_ninos...). Cualquier
     * otra forma NO se adivina: falla cerrado con un motivo.
     *
     * @return array{0: ?array, 1: ?string} [eje2, motivo]
     */
    protected function evaluarEjes(array $ejes): array
    {
        $codigos = array_column($ejes, 'code');

        if (! in_array(self::ATRIBUTO_COLOR, $codigos, true)) {
            return [null, 'El configurable no tiene el super atributo color.'];
        }

        if (count($ejes) !== 2) {
            return [null, sprintf('Tiene %d super atributos (%s); solo se soporta color + un eje adicional.', count($ejes), implode(', ', $codigos))];
        }

        $eje2 = collect($ejes)->first(fn (array $eje) => $eje['code'] !== self::ATRIBUTO_COLOR);

        return [$eje2, null];
    }

    /**
     * @return array{0: ?int, 1: bool} [valor unico|null, hay mas de un valor distinto]
     */
    protected function valorUnico(array $enteros, int $productId, int $attributeId): array
    {
        $valores = array_keys($enteros[$productId][$attributeId] ?? []);

        return match (count($valores)) {
            0 => [null, false],
            1 => [$valores[0], false],
            default => [null, true],
        };
    }

    protected function etiquetaOpcion(array $catalogo, ?int $opcionId): ?string
    {
        return $opcionId === null ? null : ($catalogo['opciones'][$opcionId]['label'] ?? null);
    }

    protected function resumenConfigurable(array $configurable): array
    {
        return [
            'id' => $configurable['id'],
            'sku' => $configurable['sku'],
            'nombre' => $configurable['nombre'],
            'activo' => $configurable['activo'],
        ];
    }

    // ---------------------------------------------------------------------
    // Diagnostico
    // ---------------------------------------------------------------------

    /**
     * Estado de product_flat (canal por defecto) por producto: SOLO para
     * detectar desalineacion contra el EAV, nunca como fuente de decision.
     *
     * @return array<int, array<string, bool>> product_id => [locale => status]
     */
    protected function estadosProductFlat(array $catalogo): array
    {
        $productIds = array_keys($catalogo['configurables']);

        foreach ($catalogo['configurables'] as $configurable) {
            array_push($productIds, ...array_keys($configurable['hijos']));
        }

        if (! $productIds) {
            return [];
        }

        $estados = [];

        $filas = DB::table('product_flat')
            ->whereIn('product_id', $productIds)
            ->where('channel', $catalogo['canal'])
            ->whereNotNull('status')
            ->get(['product_id', 'locale', 'status']);

        foreach ($filas as $fila) {
            $estados[(int) $fila->product_id][(string) $fila->locale] = (bool) $fila->status;
        }

        return $estados;
    }

    protected function hallazgosDeConfigurable(array $catalogo, array $configurable, array $estadosFlat): array
    {
        $hallazgos = [];

        if ($configurable['tipo_tela'] === null) {
            $hallazgos[] = $this->hallazgo($catalogo, self::DIAG_CONFIGURABLE_SIN_TIPO_TELA, self::SEVERIDAD_ADVERTENCIA, $configurable);
        }

        if (! $configurable['soportado']) {
            $hallazgos[] = $this->hallazgo($catalogo, ErrorResolucionVarianteCorte::EjesNoSoportados->value, self::SEVERIDAD_ERROR, $configurable, [], [
                'motivo' => $configurable['motivo_no_soportado'],
            ]);
        }

        if (! $configurable['activo']) {
            $hallazgos[] = $this->hallazgo($catalogo, AdvertenciaResolucionVarianteCorte::ConfigurableInactivoEnTienda->value, self::SEVERIDAD_ADVERTENCIA, $configurable);
        }

        $desalineacion = $this->desalineacionFlat($configurable['activo'], $estadosFlat[$configurable['id']] ?? []);

        if ($desalineacion) {
            $hallazgos[] = $this->hallazgo($catalogo, self::DIAG_STATUS_DESALINEADO, self::SEVERIDAD_ADVERTENCIA, $configurable, [], $desalineacion);
        }

        $colores = array_values(array_unique(array_filter(array_column($configurable['hijos'], 'color'), fn ($c) => $c !== null)));

        if (count($colores) > 1) {
            $hallazgos[] = $this->hallazgo($catalogo, self::DIAG_CONFIGURABLE_MULTICOLOR, self::SEVERIDAD_ERROR, $configurable, [], [
                'colores' => array_map(fn (int $id) => $this->etiquetaOpcion($catalogo, $id), $colores),
            ]);
        }

        foreach ($configurable['hijos'] as $hijo) {
            array_push($hallazgos, ...$this->hallazgosDeSimple($catalogo, $configurable, $hijo, $estadosFlat));
        }

        array_push($hallazgos, ...$this->hallazgosVarianteDuplicada($catalogo, $configurable));

        return $hallazgos;
    }

    protected function hallazgosDeSimple(array $catalogo, array $configurable, array $hijo, array $estadosFlat): array
    {
        $hallazgos = [];

        if ($hijo['color'] === null) {
            $codigo = $hijo['color_multiple'] ? self::DIAG_SIMPLE_VALOR_MULTIPLE : self::DIAG_SIMPLE_SIN_COLOR;
            $hallazgos[] = $this->hallazgo($catalogo, $codigo, self::SEVERIDAD_ERROR, $configurable, [$hijo], ['atributo' => self::ATRIBUTO_COLOR]);
        }

        // El eje 2 solo se conoce en configurables soportados.
        if ($configurable['soportado'] && $hijo['eje'] === null) {
            $codigo = $hijo['eje_multiple'] ? self::DIAG_SIMPLE_VALOR_MULTIPLE : self::DIAG_SIMPLE_SIN_EJE;
            $hallazgos[] = $this->hallazgo($catalogo, $codigo, self::SEVERIDAD_ERROR, $configurable, [$hijo], ['atributo' => $configurable['eje2']['code']]);
        }

        if ($hijo['familia_id'] !== $configurable['familia_id']) {
            $hallazgos[] = $this->hallazgo($catalogo, self::DIAG_SIMPLE_FAMILIA_DISTINTA, self::SEVERIDAD_ERROR, $configurable, [$hijo], [
                'attribute_family_id_simple' => $hijo['familia_id'],
                'attribute_family_id_configurable' => $configurable['familia_id'],
            ]);
        }

        if (! $hijo['activo']) {
            $hallazgos[] = $this->hallazgo($catalogo, AdvertenciaResolucionVarianteCorte::VarianteInactivaEnTienda->value, self::SEVERIDAD_ADVERTENCIA, $configurable, [$hijo]);
        }

        $desalineacion = $this->desalineacionFlat($hijo['activo'], $estadosFlat[$hijo['id']] ?? []);

        if ($desalineacion) {
            $hallazgos[] = $this->hallazgo($catalogo, self::DIAG_STATUS_DESALINEADO, self::SEVERIDAD_ADVERTENCIA, $configurable, [$hijo], $desalineacion);
        }

        return $hallazgos;
    }

    protected function hallazgosVarianteDuplicada(array $catalogo, array $configurable): array
    {
        $grupos = [];

        foreach ($configurable['hijos'] as $hijo) {
            if ($hijo['color'] !== null && $hijo['eje'] !== null) {
                $grupos[$hijo['color'].'|'.$hijo['eje']][] = $hijo;
            }
        }

        $hallazgos = [];

        foreach ($grupos as $simples) {
            if (count($simples) > 1) {
                $hallazgos[] = $this->hallazgo($catalogo, ErrorResolucionVarianteCorte::VarianteDuplicada->value, self::SEVERIDAD_ERROR, $configurable, $simples);
            }
        }

        return $hallazgos;
    }

    /**
     * (familia, tela, color) con mas de un configurable: la misma llave que usa
     * el nivel 1 del resolvedor, con todos los hijos considerados. Los
     * configurables sin tela no son candidatos de ninguna tela y se omiten.
     */
    protected function hallazgosColorAmbiguo(array $catalogo): array
    {
        $grupos = [];

        foreach ($catalogo['configurables'] as $configurable) {
            if ($configurable['tipo_tela'] === null) {
                continue;
            }

            $colores = array_unique(array_filter(array_column($configurable['hijos'], 'color'), fn ($c) => $c !== null));

            foreach ($colores as $colorId) {
                $grupos[$configurable['familia_id'].'|'.$configurable['tipo_tela'].'|'.$colorId][] = $configurable;
            }
        }

        $hallazgos = [];

        foreach ($grupos as $llave => $configurables) {
            if (count($configurables) < 2) {
                continue;
            }

            [$familiaId, $telaId, $colorId] = array_map('intval', explode('|', $llave));

            $hallazgos[] = $this->hallazgo($catalogo, ErrorResolucionVarianteCorte::ColorAmbiguo->value, self::SEVERIDAD_ERROR, $configurables, [], [
                'tipo_tela' => $this->etiquetaOpcion($catalogo, $telaId),
                'color_option_id' => $colorId,
                'color' => $this->etiquetaOpcion($catalogo, $colorId),
            ], $familiaId);
        }

        return $hallazgos;
    }

    /**
     * @return array<string, mixed>|null null si el EAV y product_flat coinciden (o no hay fila flat)
     */
    protected function desalineacionFlat(bool $activoEav, array $estadosFlat): ?array
    {
        $distintos = array_filter($estadosFlat, fn (bool $flat) => $flat !== $activoEav);

        return $distintos ? ['status_eav' => $activoEav, 'status_product_flat' => $distintos] : null;
    }

    /**
     * @param  array|list<array>  $configurables  un configurable o una lista de ellos
     * @param  list<array>  $simples
     */
    protected function hallazgo(array $catalogo, string $codigo, string $severidad, array $configurables, array $simples = [], array $detalle = [], ?int $familiaId = null): array
    {
        if (isset($configurables['id'])) {
            $configurables = [$configurables];
        }

        $familiaId ??= $configurables[0]['familia_id'];

        return [
            'codigo' => $codigo,
            'severidad' => $severidad,
            'mensaje' => self::MENSAJES_DIAGNOSTICO[$codigo],
            'attribute_family_id' => $familiaId,
            'familia' => $catalogo['familias'][$familiaId]['codigo'] ?? null,
            'configurables' => array_map(fn (array $k) => $this->describirConfigurable($catalogo, $k), $configurables),
            'simples' => array_map(fn (array $hijo) => $this->describirSimple($catalogo, $configurables[0], $hijo), $simples),
            'detalle' => $detalle,
        ];
    }

    protected function describirConfigurable(array $catalogo, array $configurable): array
    {
        return [
            'id' => $configurable['id'],
            'sku' => $configurable['sku'],
            'nombre' => $configurable['nombre'],
            'attribute_family_id' => $configurable['familia_id'],
            'familia' => $catalogo['familias'][$configurable['familia_id']]['codigo'] ?? null,
            'activo' => $configurable['activo'],
            'tipo_tela' => $this->etiquetaOpcion($catalogo, $configurable['tipo_tela']),
            'ejes' => array_column($configurable['ejes'], 'code'),
            'hijos' => array_values(array_map(fn (array $hijo) => $this->describirSimple($catalogo, $configurable, $hijo), $configurable['hijos'])),
        ];
    }

    protected function describirSimple(array $catalogo, array $configurable, array $hijo): array
    {
        return [
            'id' => $hijo['id'],
            'sku' => $hijo['sku'],
            'configurable_sku' => $configurable['sku'],
            'color' => $this->etiquetaOpcion($catalogo, $hijo['color']),
            'eje' => $this->etiquetaOpcion($catalogo, $hijo['eje']),
            'activo' => $hijo['activo'],
        ];
    }

    /**
     * Configurables que aparecen en algun hallazgo, con los codigos de error y
     * advertencia que los afectan (los simples cuentan para su configurable).
     *
     * @return list<array<string, mixed>>
     */
    protected function configurablesAfectados(array $hallazgos): array
    {
        $afectados = [];

        foreach ($hallazgos as $hallazgo) {
            $clave = $hallazgo['severidad'] === self::SEVERIDAD_ERROR ? 'errores' : 'advertencias';

            foreach ($hallazgo['configurables'] as $configurable) {
                $afectados[$configurable['id']] ??= ['configurable' => $configurable, 'errores' => [], 'advertencias' => []];
                $afectados[$configurable['id']][$clave][$hallazgo['codigo']] = true;
            }
        }

        $afectados = array_map(function (array $afectado) {
            $afectado['errores'] = array_keys($afectado['errores']);
            $afectado['advertencias'] = array_keys($afectado['advertencias']);

            return $afectado;
        }, $afectados);

        usort($afectados, fn (array $a, array $b) => $a['configurable']['sku'] <=> $b['configurable']['sku']);

        return $afectados;
    }
}
