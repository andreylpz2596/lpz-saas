<?php

use ByLopez\Cargues\Models\Carga;
use ByLopez\Cargues\Services\Cargas\CargasService;
use ByLopez\Cargues\Services\Cargas\ResumenService;
use ByLopez\Cargues\Services\Configuracion\ActivarTipoService;
use ByLopez\Cargues\Services\Configuracion\TiposService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/*
| Ayudantes de las pruebas del tipo «Productos — Camisetas piel de durazno» (docs/17). Solo instancia AISLADA (CarguesTestCase). El
| catalogo es SINTETICO y propio de estas pruebas: atributos `cgt_*`, opciones «CGT …», familias `cgt_*` y productos con SKU `CGT-…`;
| todo se borra al terminar. Color y talla son los atributos reales `color` y `size` (con opciones sinteticas o las S/M/L/XL de la base).
*/

const PC_PROCEDIMIENTO = 'sp_cg_productos_camisetas_v1';
const PC_TABLA = 'bylopez_cgi_productos_camisetas';
const PC_ENCABEZADOS = ['Código del producto base', 'SKU de la variante', 'Nombre del producto', 'Público (Hombre, Dama o Niño)', 'Color', 'Talla', 'Composición', 'Manga', 'Cuello', 'Tipo de tela', 'Costo', 'Precio de venta', 'Peso (kg)', 'Descripción corta', 'Descripción'];

function pc_ruta(string $archivo): string
{
    return dirname(__DIR__).'/src/Database/Procedures/'.$archivo;
}

function pc_instalar_sp(): void
{
    $sql = file_get_contents(pc_ruta(PC_PROCEDIMIENTO.'.sql'));
    $sql = str_replace('END$$', 'END', preg_replace('/^DELIMITER.*$/m', '', $sql));
    $cuerpo = trim(preg_split('/^DROP PROCEDURE IF EXISTS '.PC_PROCEDIMIENTO.';/m', $sql)[1]);

    DB::unprepared('DROP PROCEDURE IF EXISTS '.PC_PROCEDIMIENTO);
    DB::unprepared($cuerpo);
}

function pc_atributo(string $codigo, string $nombre, array $opciones): int
{
    $id = DB::table('attributes')->where('code', $codigo)->value('id');

    if (! $id) {
        $id = DB::table('attributes')->insertGetId([
            'code' => $codigo, 'admin_name' => $nombre, 'type' => 'select', 'position' => 90, 'is_required' => 0, 'is_unique' => 0, 'is_filterable' => 0,
            'is_comparable' => 0, 'is_configurable' => 0, 'is_user_defined' => 1, 'is_visible_on_front' => 0, 'value_per_locale' => 0, 'value_per_channel' => 0,
            'enable_wysiwyg' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    foreach ($opciones as $i => $o) {
        DB::table('attribute_options')->insert(['attribute_id' => $id, 'admin_name' => $o, 'sort_order' => 100 + $i]);
    }

    return (int) $id;
}

function pc_familia(string $codigo, array $atributos): int
{
    $f = DB::table('attribute_families')->insertGetId(['code' => $codigo, 'name' => 'CGT '.$codigo, 'status' => 1, 'is_user_defined' => 1]);
    $g = DB::table('attribute_groups')->insertGetId(['code' => 'general', 'attribute_family_id' => $f, 'name' => 'General', 'column' => 1, 'position' => 1, 'is_user_defined' => 0]);

    foreach (DB::table('attributes')->whereIn('code', $atributos)->pluck('id') as $i => $a) {
        DB::table('attribute_group_mappings')->insert(['attribute_id' => $a, 'attribute_group_id' => $g, 'position' => $i + 1]);
    }

    return $f;
}

/** Catalogo sintetico y configuracion del tipo apuntando a el (nunca a ids). */
function pc_catalogo(): void
{
    pc_atributo('color', 'Color', ['CGT Negro', 'CGT Blanco', 'CGT Rosado']);
    pc_atributo('tipo_tela', 'Tipo de tela', ['CGT Piel Durazno']);
    pc_atributo('brand', 'Marca', ['CGT BYLOPEZ']);
    pc_atributo('cgt_manga', 'Manga', ['CGT Manga Corta']);
    pc_atributo('cgt_cuello', 'Cuello', ['CGT Redondo']);
    pc_atributo('cgt_composicion', 'Composición', ['CGT 80/20']);

    $comunes = ['sku', 'name', 'url_key', 'tax_category_id', 'new', 'featured', 'visible_individually', 'status', 'short_description', 'description', 'price', 'cost', 'weight',
        'guest_checkout', 'product_number', 'manage_stock', 'color', 'brand', 'tipo_tela', 'cgt_manga', 'cgt_cuello', 'cgt_composicion'];
    pc_familia('cgt_hombre', [...$comunes, 'size']);
    pc_familia('cgt_dama', [...$comunes, 'size']);
    pc_familia('cgt_nino', $comunes);                                       // como la real de niño: SIN talla

    config([
        'cargues.productos_camisetas.publicos' => [
            'hombre' => ['familia' => 'cgt_hombre', 'categorias' => ['Raíz'], 'tallas' => 'adulto'],
            'dama' => ['familia' => 'cgt_dama', 'categorias' => ['Raíz', 'CGT categoria inexistente'], 'tallas' => 'adulto'],
            'nino' => ['familia' => 'cgt_nino', 'categorias' => [], 'tallas' => 'nino'],
        ],
        'cargues.productos_camisetas.tallas' => ['adulto' => ['S', 'M', 'L', 'XL', 'XXL'], 'nino' => ['4', '6']],
        'cargues.productos_camisetas.atributos' => ['color' => 'color', 'talla' => 'size', 'manga' => 'cgt_manga', 'cuello' => 'cgt_cuello', 'tipo_tela' => 'tipo_tela', 'composicion' => 'cgt_composicion', 'marca' => 'brand'],
        'cargues.productos_camisetas.por_defecto' => ['tipo_tela' => 'CGT Piel Durazno', 'marca' => 'CGT BYLOPEZ', 'manga' => null, 'cuello' => null, 'composicion' => null],
    ]);
}

function pc_limpiar(): void
{
    $ids = DB::table('products')->where('sku', 'like', 'CGT-%')->pluck('id');
    DB::table('product_inventories')->whereIn('product_id', $ids)->delete();
    DB::table('products')->whereIn('id', $ids)->whereNotNull('parent_id')->delete();
    DB::table('products')->whereIn('id', $ids)->delete();
    DB::table('url_rewrites')->where('request_path', 'like', '%cgt%')->delete();
    $familias = DB::table('attribute_families')->where('code', 'like', 'cgt\_%')->pluck('id');
    DB::table('attribute_group_mappings')->whereIn('attribute_group_id', DB::table('attribute_groups')->whereIn('attribute_family_id', $familias)->pluck('id'))->delete();
    DB::table('attribute_groups')->whereIn('attribute_family_id', $familias)->delete();
    DB::table('attribute_families')->whereIn('id', $familias)->delete();
    DB::table('attribute_options')->where('admin_name', 'like', 'CGT %')->delete();
    $atributos = DB::table('attributes')->where('code', 'like', 'cgt\_%')->pluck('id');
    DB::table('product_attribute_values')->whereIn('attribute_id', $atributos)->delete();
    DB::table('attributes')->whereIn('id', $atributos)->delete();
    DB::statement('DROP TABLE IF EXISTS `'.PC_TABLA.'`');
    DB::unprepared('DROP PROCEDURE IF EXISTS '.PC_PROCEDIMIENTO);
}

/** Fila de la plantilla. Claves: b (codigo base), s (SKU), n (nombre), p (publico), c (color), t (talla), m (manga), cu (cuello), co (composicion), te (tela), cos (costo), pr (precio), pe (peso), dc (descripcion corta), d (descripcion). */
function pc_fila(array $o): array
{
    $o += ['n' => 'Camiseta CGT Hombre', 'p' => 'Hombre', 'c' => 'CGT Negro', 't' => 'S', 'co' => 'CGT 80/20', 'm' => 'CGT Manga Corta', 'cu' => 'CGT Redondo', 'te' => '', 'cos' => '5.500', 'pr' => '8.500', 'pe' => '0,17', 'dc' => '', 'd' => ''];

    return [$o['b'], $o['s'], $o['n'], $o['p'], $o['c'], $o['t'], $o['co'], $o['m'], $o['cu'], $o['te'], $o['cos'], $o['pr'], $o['pe'], $o['dc'], $o['d']];
}

function pc_csv(array $filas, string $nombre = 'camisetas_sinteticas.csv'): UploadedFile
{
    $h = fopen('php://temp', 'r+');
    fputcsv($h, PC_ENCABEZADOS, ';', '"', '');

    foreach ($filas as $f) {
        fputcsv($h, pc_fila($f), ';', '"', '');
    }

    rewind($h);

    return UploadedFile::fake()->createWithContent($nombre, stream_get_contents($h));
}

function pc_tipo(): array
{
    pc_instalar_sp();
    $admin = cg_admin();
    $tipos = app(TiposService::class);
    $tipo = $tipos->crear($admin, [
        'nombre' => 'Productos — Camisetas piel de durazno (prueba)', 'descripcion' => 'Prueba sintética', 'conexion' => 'mysql', 'procedimiento' => PC_PROCEDIMIENTO,
        'accion' => 'crear_inactivos', 'politica_errores' => 'procesar_validas', 'limites' => null,
        'map' => json_decode(file_get_contents(pc_ruta(PC_PROCEDIMIENTO.'.map.json')), true),
    ]);
    $v = $tipo->versiones()->first();
    $revision = $tipos->confirmarContrato($admin, $v);
    $v = app(ActivarTipoService::class)->activar($admin, $v->fresh());

    return [$tipo->fresh(), $v, $admin, $revision];
}

function pc_validar($tipo, $admin, array $filas, string $accion = 'crear_inactivos'): Carga
{
    $servicio = app(CargasService::class);
    $carga = $servicio->crear($admin, ['tipo_id' => $tipo->id, 'archivo' => true], pc_csv($filas));

    return $servicio->validar($admin, $carga, ['relaciones' => (array) $carga->encabezados['sugerencias'], 'accion' => $accion])->fresh();
}

function pc_ejecutar(Carga $carga, $admin): Carga
{
    $r = app(ResumenService::class)->calcular($carga);

    return app(CargasService::class)->ejecutar($admin, $carga, $r['confirmar_rechazadas'] ? ['confirmar_rechazadas' => $r['confirmar_rechazadas']] : [])->fresh();
}

/** fila_origen => estado:detalle/codigo (lo persistido). */
function pc_resultado(Carga $carga): array
{
    $codigos = DB::table('bylopez_cg_carga_incidencias')->where('carga_id', $carga->id)->orderBy('id')->get()->groupBy('fila_origen')->map(fn ($g) => $g->first()->codigo);

    return DB::table('bylopez_cg_carga_datos')->where('carga_id', $carga->id)->orderBy('fila_origen')->get()
        ->mapWithKeys(fn ($d) => [$d->fila_origen => $d->estado_final.':'.($d->detalle ?? '-').'/'.($codigos[$d->fila_origen] ?? '-')])->all();
}

/** Conteos de lo que el cargue PUEDE escribir (y de lo que NUNCA debe tocar). */
function pc_conteos(): array
{
    $ids = DB::table('products')->where('sku', 'like', 'CGT-%')->pluck('id');

    return [
        'productos' => $ids->count(),
        'configurables' => DB::table('products')->whereIn('id', $ids)->where('type', 'configurable')->count(),
        'variantes' => DB::table('products')->whereIn('id', $ids)->whereNotNull('parent_id')->count(),
        'inventarios' => DB::table('product_inventories')->whereIn('product_id', $ids)->count(),
        'productos_total' => DB::table('products')->count(),
        'opciones' => DB::table('attribute_options')->count(),
        'pedidos' => DB::table('orders')->count(), 'reservas' => DB::table('product_ordered_inventories')->count(),
    ];
}

/** Valor guardado de un atributo de un producto por SKU. */
function pc_valor(string $sku, string $codigo): mixed
{
    $v = DB::table('product_attribute_values as v')->join('attributes as a', 'a.id', '=', 'v.attribute_id')->join('products as p', 'p.id', '=', 'v.product_id')
        ->where('p.sku', $sku)->where('a.code', $codigo)->first(['a.type', 'v.text_value', 'v.integer_value', 'v.float_value', 'v.boolean_value']);

    return $v === null ? null : match ($v->type) {
        'select' => $v->integer_value, 'price' => $v->float_value === null ? null : (float) $v->float_value, 'boolean' => (bool) $v->boolean_value, default => $v->text_value,
    };
}

function pc_opcion(string $codigo, string $texto): int
{
    return (int) DB::table('attribute_options as o')->join('attributes as a', 'a.id', '=', 'o.attribute_id')->where('a.code', $codigo)->where('o.admin_name', $texto)->value('o.id');
}
