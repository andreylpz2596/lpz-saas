<?php

namespace ByLopez\Cargues\Extensiones\ProductosCamisetas;

use ByLopez\Cargues\Contracts\ExtensionTipo;
use ByLopez\Cargues\Extensiones\Concerns\AplicaPorFila;
use ByLopez\Cargues\Models\Carga;
use ByLopez\Cargues\Services\Configuracion\ConexionTipo;
use ByLopez\Cargues\Support\Estados;
use Closure;
use Illuminate\Database\DetectsLostConnections;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;
use Webkul\Product\Repositories\ProductRepository;

/**
 * Extension del tipo «Productos — Camisetas piel de durazno» (docs/17).
 *
 *  derivar(): al PREPARAR la carga (solo lectura) analiza el archivo COMPLETO por producto base (AnalizadorCamisetas): opciones del
 *             catalogo, producto existente, conflictos y datos pendientes. Deja por fila el plan, el bloqueo y las observaciones. Las filas
 *             rechazadas por el motor tambien cuentan: una sola bloquea su producto (no se crea incompleto).
 *  aplicar(): tras el plan del procedimiento, UNA TRANSACCION POR PRODUCTO BASE (padre + variantes + marcas de TODAS sus filas). Dentro de
 *             ella vuelve a analizar el grupo con el catalogo actual y escribe por los repositorios y eventos de Bagisto (CreadorCamisetas).
 *             Un fallo revierte el grupo entero. Repetible: un SKU ya creado da «sin cambio», nunca un duplicado.
 *
 * NO crea stock, movimientos de inventario, precios por grupo, opciones de atributos ni categorias; no toca productos existentes salvo
 * agregarles variantes nuevas compatibles; nunca borra variantes.
 */
class ProductosCamisetasExtension implements ExtensionTipo
{
    use AplicaPorFila, DetectsLostConnections;

    /** @var array<int, array<int, array<string,mixed>>> carga_id => (carga_dato_id => derivados) */
    protected array $analisis = [];

    public function __construct(protected ConexionTipo $conexiones) {}

    // ---------------------------------------------------------------------------------------------------------------------
    // Derivar (solo lectura)
    // ---------------------------------------------------------------------------------------------------------------------

    public function derivar(Carga $carga, array $filas): array
    {
        // El analisis es del archivo COMPLETO (un grupo puede repartirse entre lotes); se calcula una vez por preparacion.
        $this->analisis[$carga->id] ??= $this->analizarCarga($carga);

        return array_intersect_key($this->analisis[$carga->id], $filas);
    }

    /** @return array<int, array<string,mixed>> */
    protected function analizarCarga(Carga $carga): array
    {
        $datos = DB::table('bylopez_cg_carga_datos')->where('carga_id', $carga->id)
            ->whereIn('estado_final', [Estados::FILA_PENDIENTE, Estados::FILA_RECHAZADA])->orderBy('fila_origen')
            ->get(['id', 'fila_origen', 'estado_final', 'datos_normalizados']);
        $motivos = DB::table('bylopez_cg_carga_incidencias')->where('carga_id', $carga->id)->where('severidad', 'error')
            ->whereIn('dato_id', $datos->where('estado_final', Estados::FILA_RECHAZADA)->pluck('id'))->orderBy('id')
            ->get(['dato_id', 'motivo'])->groupBy('dato_id');
        $filas = [];

        foreach ($datos as $d) {
            $filas[] = [
                'id' => (int) $d->id, 'fila' => (int) $d->fila_origen,
                'v' => (array) (json_decode((string) $d->datos_normalizados, true)['valores'] ?? []),
                'rechazo' => $d->estado_final === Estados::FILA_RECHAZADA ? 'fila '.$d->fila_origen.': '.(string) (($motivos->get($d->id) ?? collect())->first()->motivo ?? 'rechazada por la validación') : null,
            ];
        }

        $catalogo = new CatalogoCamisetas;

        // Doc. 33: el código del producto y de la variante lo pone el patrón (115D-DZ / 115D-DZ-M), no el archivo. Vista previa: sin escribir.
        return (new AnalizadorCamisetas($catalogo))->analizar((new SkuCamisetas($catalogo))->reescribir($filas, false));
    }

    // ---------------------------------------------------------------------------------------------------------------------
    // Aplicar (repositorios y eventos de Bagisto, una transaccion por producto base)
    // ---------------------------------------------------------------------------------------------------------------------

    public function aplicar(Carga $carga, array $map, Closure $progreso): void
    {
        $cfg = (array) $carga->config_congelada;
        $tabla = (string) $map['tabla'];
        $this->exigirMismaBase((string) $cfg['conexion']);

        $total = (int) DB::table($tabla)->where('cg_carga_id', $carga->id)->whereNull('cg_estado')->count();
        $hechas = 0;
        $progreso(0, $total);

        $grupos = DB::table($tabla)->where('cg_carga_id', $carga->id)->whereNull('cg_estado')
            ->selectRaw('grupo_clave, MIN(cg_fila_origen) AS primera')->groupBy('grupo_clave')->orderBy('primera')->pluck('grupo_clave');

        foreach ($grupos as $clave) {
            $filas = DB::table($tabla)->where('cg_carga_id', $carga->id)->whereNull('cg_estado')->where('grupo_clave', $clave)->orderBy('cg_fila_origen')->get();

            try {
                DB::transaction(fn () => $this->aplicarGrupo($carga, $tabla, $filas->all()));
            } catch (Throwable $e) {
                $this->clasificarFallo($e, $tabla, $filas->all());
            }

            $hechas += $filas->count();
            $progreso($hechas, $total);
        }
    }

    /**
     * Un grupo que falla se revierte entero. Si el fallo es de la CONEXION con la base (la transaccion ya se deshizo en el servidor), el
     * error sube: el motor deja la carga reanudable y este grupo SIN marca, para aplicarlo al ejecutar de nuevo. Cualquier otro fallo
     * rechaza las filas del grupo con su motivo (sin padre incompleto ni variantes sueltas) y se sigue con el siguiente grupo.
     */
    protected function clasificarFallo(Throwable $e, string $tabla, array $filas): void
    {
        if ($this->causedByLostConnection($e)) {
            throw $e;
        }

        if ($e instanceof RechazoGrupo) {
            foreach ($e->porFila as $cgId => [$codigo, $mensaje]) {
                $this->marcar($tabla, (int) $cgId, Estados::FILA_RECHAZADA, null, $codigo, $mensaje);
            }

            return;
        }

        if ($e instanceof QueryException && (int) ($e->errorInfo[1] ?? 0) === 1062) {
            $this->rechazarTodas($tabla, $filas, 'CONCURRENCIA', 'Otro proceso creó un producto o SKU de este grupo mientras se aplicaba: no se aplicó nada de este producto. Cargue el archivo de nuevo para ver su estado actual.');

            return;
        }

        report($e);
        $this->rechazarTodas($tabla, $filas, $e instanceof QueryException ? 'ERROR_BD' : 'ERROR_INESPERADO', 'Error '.($e instanceof QueryException ? 'de base de datos' : 'inesperado').' al crear el producto ('.class_basename($e).': '.mb_substr($e instanceof QueryException ? (string) ($e->errorInfo[2] ?? $e->getMessage()) : $e->getMessage(), 0, 200).'): no se aplicó nada de este producto (ni el padre ni sus variantes).');
    }

    /** Dentro de la transaccion del grupo: vuelve a analizar con el catalogo actual, escribe y marca todas las filas. */
    protected function aplicarGrupo(Carga $carga, string $tabla, array $filas): void
    {
        $catalogo = new CatalogoCamisetas;
        $campos = array_column((array) ($carga->config_congelada['map']['campos'] ?? []), 'nombre');
        $entrada = array_map(fn ($f) => ['id' => (int) $f->cg_id, 'fila' => (int) $f->cg_fila_origen, 'v' => array_intersect_key((array) $f, array_flip($campos)), 'rechazo' => null], $filas);
        // Doc. 33: códigos del patrón (un color nuevo recibe aquí su número definitivo).
        $entrada = (new SkuCamisetas($catalogo))->reescribir($entrada, true);
        $skuVariante = array_column(array_map(fn ($e) => ['id' => $e['id'], 'sku' => trim((string) ($e['v']['sku_variante'] ?? ''))], $entrada), 'sku', 'id');
        $base = trim((string) $entrada[0]['v']['codigo_base']);
        $existente = DB::table('products')->where('sku', $base)->lockForUpdate()->first(['id']);   // serializa a quien agregue al mismo padre
        $plan = (new AnalizadorCamisetas($catalogo))->analizar($entrada);
        $primero = $plan[(int) $filas[0]->cg_id];

        if ($primero['grupo_estado'] === AnalizadorCamisetas::BLOQUEADO) {
            throw new RechazoGrupo(array_map(fn ($r) => [(string) $r['bloqueo_codigo'], 'Al ejecutar: '.(string) $r['bloqueo_mensaje']], $plan));
        }

        $cfg = (array) config('cargues.productos_camisetas');
        $attr = (array) $cfg['atributos'];
        $creador = new CreadorCamisetas(app(ProductRepository::class), $catalogo);
        $activar = $carga->accion === 'crear_activos';
        $publico = $this->publico((string) $filas[0]->publico, $cfg);
        $ejeTalla = (string) ($publico['atributo_talla'] ?? $attr['talla']);        // adulto `size`; niño `grupo_de_edad_ninos`
        [$categorias, $faltan] = $catalogo->categorias((array) $publico['categorias']);
        $hoja = $categorias === [] ? [] : [end($categorias)];
        $extra = [];                                                // cg_id => observaciones adicionales del momento de aplicar

        if ($primero['grupo_estado'] === AnalizadorCamisetas::NUEVO) {
            $conPrecio = array_reduce($filas, fn ($ok, $f) => $ok && $f->precio !== null, true);
            $activo = $activar && $conPrecio;
            $atributosPadre = [
                $attr['manga'] => $primero['manga_id'], $attr['cuello'] => $primero['cuello_id'], $attr['tipo_tela'] => $primero['tela_id'],
                $attr['composicion'] => $primero['composicion_id'], $attr['marca'] => $primero['marca_id'],
            ];
            [$corta, $larga] = $this->descripciones($filas[0], $publico, $atributosPadre, $catalogo, $attr);
            $padreId = $creador->crearPadre([
                'sku' => $base, 'nombre' => trim((string) $filas[0]->nombre), 'familia_id' => (int) $primero['familia_id'],
                'activo' => $activo, 'categorias' => $categorias, 'atributos' => $atributosPadre, 'eje_talla' => $ejeTalla,
                'descripcion_corta' => $corta, 'descripcion' => $larga,
            ]);
            $detalle = 'producto_creado';
            $activo || $extra[(int) $filas[0]->cg_id][] = 'PRODUCTO_INACTIVO';
        } else {
            $padreId = (int) $existente->id;
            $guardado = $catalogo->valores([$padreId], [$attr['manga'], $attr['cuello'], $attr['tipo_tela'], $attr['composicion'], $attr['marca']])[$padreId] ?? [];
            // La variante nueva hereda lo que el producto YA tiene (el analisis comprobo que el archivo no lo contradice).
            $atributosPadre = [
                $attr['manga'] => $guardado[$attr['manga']] ?? $primero['manga_id'], $attr['cuello'] => $guardado[$attr['cuello']] ?? $primero['cuello_id'],
                $attr['tipo_tela'] => $guardado[$attr['tipo_tela']] ?? $primero['tela_id'], $attr['composicion'] => $guardado[$attr['composicion']] ?? $primero['composicion_id'],
                $attr['marca'] => $guardado[$attr['marca']] ?? $primero['marca_id'],
            ];
            $activo = null;
            $detalle = 'variante_agregada';
            // La variante nueva lleva las descripciones que el producto YA tiene; si no tiene, las del archivo o las armadas con datos confirmados.
            $textos = $catalogo->valores([$padreId], ['short_description', 'description'])[$padreId] ?? [];
            [$corta, $larga] = $this->descripciones($filas[0], $publico, $atributosPadre, $catalogo, $attr);
            $corta = trim((string) ($textos['short_description'] ?? '')) !== '' ? $textos['short_description'] : $corta;
            $larga = trim((string) ($textos['description'] ?? '')) !== '' ? $textos['description'] : $larga;
        }

        $faltan === [] || $extra[(int) $filas[0]->cg_id][] = 'CATEGORIA_NO_RESUELTA';
        $creadas = 0;

        foreach ($filas as $f) {
            $r = $plan[(int) $f->cg_id];

            if ($r['fila_plan'] !== AnalizadorCamisetas::CREAR_VARIANTE) {
                continue;
            }

            // Producto NUEVO: la visibilidad la decide el padre; sus variantes con precio quedan activas para que activar el producto en el
            // formulario baste para venderlo. Variante agregada a un producto EXISTENTE (que puede estar a la venta): activa solo con «crear_activos».
            $activa = $f->precio !== null && ($activo !== null || $activar);
            $activo === null && ! $activa && $extra[(int) $f->cg_id][] = 'VARIANTE_INACTIVA';
            $creador->crearVariante($padreId, (int) $r['familia_id'], [
                'sku' => $skuVariante[(int) $f->cg_id] ?? trim((string) $f->sku_variante), 'nombre' => $this->nombreVariante((string) $f->nombre, (string) $f->color, (string) $f->talla),
                'precio' => $f->precio === null ? null : (float) $f->precio, 'costo' => $f->costo === null ? null : (float) $f->costo,
                'peso' => $f->peso === null ? null : rtrim(rtrim((string) $f->peso, '0'), '.'), 'activo' => $activa,
                'color' => (int) $r['color_id'], 'talla' => (int) $r['talla_id'], 'eje_talla' => $ejeTalla, 'atributos' => $atributosPadre, 'categorias' => $hoja,
                'descripcion_corta' => $corta, 'descripcion' => $larga,
            ]);
            $creadas++;
        }

        if ($creadas > 0) {
            $creador->reindexar($padreId);
        }

        foreach ($filas as $f) {
            $r = $plan[(int) $f->cg_id];
            $codigos = array_values(array_unique([...array_filter(explode(',', (string) $r['observaciones'])), ...($extra[(int) $f->cg_id] ?? [])]));
            $mensaje = $codigos === [] ? null : implode(' | ', array_map([AnalizadorCamisetas::class, 'mensajeObservacion'], $codigos));

            $r['fila_plan'] === AnalizadorCamisetas::CREAR_VARIANTE
                // Un codigo por fila (el resumen agrupa por codigo); con varias observaciones, DATOS_PENDIENTES y el mensaje las enumera todas.
                ? $this->marcar($tabla, (int) $f->cg_id, Estados::FILA_APLICADA, $detalle, match (count($codigos)) {
                    0 => null, 1 => $codigos[0], default => 'DATOS_PENDIENTES'
                }, $mensaje)
                : $this->marcar($tabla, (int) $f->cg_id, Estados::FILA_SIN_CAMBIO, 'ya_registrado', null, null);
        }
    }

    /** «Camiseta … Negro Hombre» + talla; el color se agrega solo si el nombre no lo trae ya (convencion del catalogo). */
    protected function nombreVariante(string $nombre, string $color, string $talla): string
    {
        $nombre = trim($nombre);
        $conColor = str_contains(' '.CatalogoCamisetas::clave($nombre).' ', ' '.CatalogoCamisetas::clave($color).' ');

        return trim($nombre.($conColor ? '' : ' '.trim($color)).' '.trim($talla));
    }

    protected function publico(string $texto, array $cfg): array
    {
        $codigo = CatalogoCamisetas::clave($texto);
        $codigo = $cfg['sinonimos_publico'][$codigo] ?? $codigo;

        return (array) $cfg['publicos'][$codigo] + ['codigo' => $codigo];
    }

    /**
     * Descripcion corta y descripcion del producto (Bagisto las exige para guardar desde su formulario). Las del archivo si vienen; si no,
     * se arman SOLO con datos confirmados: el nombre del archivo, el publico y los textos de las opciones del catalogo que el producto
     * realmente lleva (tela, manga, cuello, composicion, marca). Nunca gramaje, propiedades ni frases comerciales.
     *
     * @return array{0:string, 1:string}
     */
    protected function descripciones(object $fila, array $publico, array $atributos, CatalogoCamisetas $catalogo, array $attr): array
    {
        $nombre = trim((string) $fila->nombre);
        $corta = trim((string) ($fila->descripcion_corta ?? ''));
        $larga = trim((string) ($fila->descripcion ?? ''));
        $datos = ['Público' => ['hombre' => 'Hombre', 'dama' => 'Dama', 'nino' => 'Niño'][$publico['codigo']] ?? null];

        foreach (['tipo_tela' => 'Tela', 'manga' => 'Manga', 'cuello' => 'Cuello', 'composicion' => 'Composición', 'marca' => 'Marca'] as $campo => $etiqueta) {
            $id = $atributos[$attr[$campo]] ?? null;
            $datos[$etiqueta] = $id === null ? null : $catalogo->textoOpcion($attr[$campo], (int) $id);
        }

        $items = implode('', array_map(fn ($k, $v) => '<li>'.e($k).': '.e($v).'</li>', array_keys(array_filter($datos)), array_filter($datos)));

        return [
            $corta !== '' ? $corta : '<p>'.e($nombre).'</p>',
            $larga !== '' ? $larga : '<p>'.e($nombre).'</p>'.($items === '' ? '' : '<ul>'.$items.'</ul>'),
        ];
    }

    protected function rechazarTodas(string $tabla, array $filas, string $codigo, string $mensaje): void
    {
        foreach ($filas as $f) {
            $this->marcar($tabla, (int) $f->cg_id, Estados::FILA_RECHAZADA, null, $codigo, $mensaje);
        }
    }
}
