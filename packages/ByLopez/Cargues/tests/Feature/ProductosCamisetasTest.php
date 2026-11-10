<?php

use ByLopez\Cargues\Extensiones\ProductosCamisetas\ProductosCamisetasExtension;
use ByLopez\Cargues\Services\Cargas\CargasService;
use ByLopez\Cargues\Services\Cargas\OutputRechazadasService;
use ByLopez\Cargues\Tests\CarguesTestCase;
use ByLopez\VentasAsesor\Services\Venta\CatalogoVentaService;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/../helpers.php';
require_once __DIR__.'/../helpersProductos.php';

uses(CarguesTestCase::class);

const PC_CUENTA = 'cg_validar';

beforeEach(function () {
    cg_limpiar();
    pc_limpiar();
    $c = cg_conn();
    $c->unprepared('DROP USER IF EXISTS `'.PC_CUENTA.'`@`127.0.0.1`');
    $c->unprepared('CREATE USER `'.PC_CUENTA."`@`127.0.0.1` IDENTIFIED BY 'cg_validar_pw'");
    config([
        'cargues.cola.conexion' => 'sync',
        'cargues.conexiones_autorizadas' => ['cargues_tipo_prueba', 'mysql'],
        'cargues.validar.exigir_cuenta_restringida' => true,
        'cargues.validar.conceder_al_activar' => true,
        'cargues.validar.cuentas.mysql' => ['usuario' => PC_CUENTA, 'clave' => 'cg_validar_pw'],
    ]);
    pc_catalogo();
    PcExtensionQueSeCae::$caerEnGrupo = null;
});

afterEach(function () {
    cg_limpiar();
    pc_limpiar();
    cg_conn()->unprepared('DROP USER IF EXISTS `'.PC_CUENTA.'`@`127.0.0.1`');
});

/** Extension que simula que se pierde la conexion con la base al empezar el grupo N (como si el proceso se cayera a mitad). */
class PcExtensionQueSeCae extends ProductosCamisetasExtension
{
    public static ?int $caerEnGrupo = null;

    protected int $grupo = 0;

    protected function aplicarGrupo($carga, string $tabla, array $filas): void
    {
        if (++$this->grupo === static::$caerEnGrupo) {
            throw new QueryException('mysql', 'select 1', [], new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'));
        }

        parent::aplicarGrupo($carga, $tabla, $filas);
    }
}

test('el tipo se activa con SP, map y extensión versionados: el SP solo escribe la intermedia y la intermedia tiene los derivados', function () {
    [, $v, , $revision] = pc_tipo();

    expect($v->estado)->toBe('activa')->and(collect($revision['hallazgos'])->where('nivel', 'error')->all())->toBe([])
        ->and($revision['tablas_escritas'])->toBe([PC_TABLA])
        ->and(DB::select('SHOW COLUMNS FROM '.PC_TABLA." LIKE 'grupo_estado'"))->not->toBeEmpty()
        ->and(app(CargasService::class)->tiposActivos()[0])->toMatchArray(['plantillas' => ['xlsx', 'csv'], 'agrupado' => true, 'acciones' => ['crear_inactivos', 'crear_activos']]);
});

test('crea el producto y EXACTAMENTE sus variantes (Negro/S, Negro/M, Blanco/L), con precio, costo, atributos, índices y sin stock; VALIDAR no escribe', function () {
    [$tipo, , $admin] = pc_tipo();
    $antes = pc_conteos();
    $filas = [
        ['b' => 'CGT-H01', 's' => 'CGT-H01-NEG-S', 'c' => 'CGT Negro', 't' => 'S'],
        ['b' => 'CGT-H01', 's' => 'CGT-H01-NEG-M', 'c' => 'CGT Negro', 't' => 'M'],
        ['b' => 'CGT-H01', 's' => 'CGT-H01-BLA-L', 'c' => 'cgt  blanco', 't' => 'L', 'cos' => '', 'pr' => '9.900,50'],
    ];

    $carga = pc_validar($tipo, $admin, $filas, 'crear_activos');
    $agrupado = app(CargasService::class)->agrupado($carga);

    expect($carga->estado)->toBe('validada')->and(pc_conteos())->toBe($antes)
        ->and(collect($agrupado['indicadores'])->pluck('n', 'etiqueta')->all())->toMatchArray(['Productos nuevos' => 1, 'Variantes nuevas' => 3, 'Productos bloqueados' => 0])
        ->and($agrupado['grupos'][0]['estado'])->toBe('nuevo')->and($agrupado['grupos'][0]['elementos'])->toHaveCount(3);

    $carga = pc_ejecutar($carga, $admin);
    $padre = DB::table('products')->where('sku', 'CGT-H01')->first();
    $hijos = DB::table('products')->where('parent_id', $padre->id)->orderBy('sku')->pluck('sku')->all();

    expect($carga->estado)->toBe('completada')->and(pc_resultado($carga))->toBe([2 => 'aplicada:producto_creado/DESCRIPCION_GENERADA', 3 => 'aplicada:producto_creado/-', 4 => 'aplicada:producto_creado/COSTO_PENDIENTE'])
        ->and($padre->type)->toBe('configurable')->and($hijos)->toBe(['CGT-H01-BLA-L', 'CGT-H01-NEG-M', 'CGT-H01-NEG-S'])        // 3, no 2 colores × 3 tallas
        ->and(DB::table('product_super_attributes as s')->join('attributes as a', 'a.id', '=', 's.attribute_id')->where('s.product_id', $padre->id)->orderBy('a.code')->pluck('a.code')->all())->toBe(['color', 'size'])
        ->and(pc_valor('CGT-H01-NEG-S', 'price'))->toBe(8500.0)->and(pc_valor('CGT-H01-NEG-S', 'cost'))->toBe(5500.0)
        ->and(pc_valor('CGT-H01-BLA-L', 'price'))->toBe(9900.5)->and(pc_valor('CGT-H01-BLA-L', 'cost'))->toBeNull()        // costo desconocido: NULL, nunca 0
        ->and(pc_valor('CGT-H01-BLA-L', 'color'))->toBe(pc_opcion('color', 'CGT Blanco'))->and(pc_valor('CGT-H01-BLA-L', 'size'))->toBe(pc_opcion('size', 'L'))
        ->and(pc_valor('CGT-H01', 'cgt_manga'))->toBe(pc_opcion('cgt_manga', 'CGT Manga Corta'))->and(pc_valor('CGT-H01-NEG-S', 'cgt_manga'))->toBe(pc_opcion('cgt_manga', 'CGT Manga Corta'))
        ->and(pc_valor('CGT-H01', 'tipo_tela'))->toBe(pc_opcion('tipo_tela', 'CGT Piel Durazno'))                              // valor comun del tipo
        ->and(pc_valor('CGT-H01-NEG-S', 'name'))->toBe('Camiseta CGT Hombre CGT Negro S')->and(pc_valor('CGT-H01-NEG-S', 'weight'))->toBe('0.17')
        ->and(pc_valor('CGT-H01', 'status'))->toBeTrue()->and(pc_valor('CGT-H01-NEG-S', 'visible_individually'))->toBeFalse()
        ->and(DB::table('product_categories')->where('product_id', $padre->id)->count())->toBe(1)
        ->and(DB::table('product_flat')->whereIn('sku', ['CGT-H01', ...$hijos])->count())->toBe(4)
        ->and(DB::table('product_price_indices')->where('product_id', $padre->id)->exists())->toBeTrue()
        ->and(pc_conteos()['inventarios'])->toBe(0)                                                                              // nunca stock inventado
        ->and(collect(pc_conteos())->only(['pedidos', 'reservas', 'opciones'])->all())->toBe(collect($antes)->only(['pedidos', 'reservas', 'opciones'])->all());

    $vendibles = collect(app(CatalogoVentaService::class)->buscarProductos('CGT-H01'))->pluck('sku')->all();
    expect($vendibles)->toContain('CGT-H01');                                                                                       // activo y con precio: aparece en Nueva venta

    $grupo = app(CargasService::class)->agrupado($carga->fresh())['grupos'][0];
    expect($grupo['estado'])->toBe('creado')->and($grupo['enlace'])->toContain('/catalog/products/edit/'.$padre->id);
});

test('«Crear inactivos» y variantes sin precio dejan el producto INACTIVO (no sale en Nueva venta) y lo reportan como pendiente', function () {
    [$tipo, , $admin] = pc_tipo();

    $carga = pc_ejecutar(pc_validar($tipo, $admin, [
        ['b' => 'CGT-D01', 's' => 'CGT-D01-ROS-S', 'n' => 'Camiseta CGT Dama CGT Rosado', 'p' => 'Dama', 'c' => 'CGT Rosado', 't' => 'S', 'pr' => '', 'co' => '', 'cu' => ''],
        ['b' => 'CGT-H02', 's' => 'CGT-H02-NEG-S'],
    ], 'crear_activos'), $admin);
    $inactivo = pc_ejecutar(pc_validar($tipo, $admin, [['b' => 'CGT-H03', 's' => 'CGT-H03-NEG-S']]), $admin);

    expect(pc_valor('CGT-D01', 'status'))->toBeFalse()->and(pc_valor('CGT-D01-ROS-S', 'price'))->toBeNull()
        ->and(pc_valor('CGT-D01-ROS-S', 'name'))->toBe('Camiseta CGT Dama CGT Rosado S')                                       // el nombre ya trae el color
        ->and(pc_valor('CGT-D01', 'cgt_composicion'))->toBeNull()->and(pc_valor('CGT-D01', 'cgt_cuello'))->toBeNull()          // dato pendiente: no se inventa
        ->and(pc_valor('CGT-H02', 'status'))->toBeTrue()
        ->and(pc_valor('CGT-H03', 'status'))->toBeFalse()->and(pc_valor('CGT-H03-NEG-S', 'status'))->toBeTrue()->and(pc_valor('CGT-D01-ROS-S', 'status'))->toBeFalse()   // el padre decide; variante con precio activa, sin precio inactiva
        ->and(collect(app(CatalogoVentaService::class)->buscarProductos('CGT'))->pluck('sku')->filter(fn ($s) => in_array($s, ['CGT-D01', 'CGT-H02', 'CGT-H03'], true))->values()->all())->toBe(['CGT-H02'])
        ->and(pc_resultado($carga)[2])->toBe('aplicada:producto_creado/DATOS_PENDIENTES')
        ->and(DB::table('bylopez_cg_carga_incidencias')->where('carga_id', $carga->id)->where('fila_origen', 2)->value('motivo'))->toContain('inactivo')
        ->and(pc_resultado($inactivo)[2])->toBe('aplicada:producto_creado/DATOS_PENDIENTES');
});

test('bloquea el producto entero con su motivo (color vacío, opción desconocida, talla, SKU ajeno, precio 0, familia sin talla, filas inconsistentes) y carga los demás', function () {
    [$tipo, , $admin] = pc_tipo();
    $ajeno = DB::table('products')->insertGetId(['sku' => 'CGT-AJENO', 'type' => 'simple', 'attribute_family_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $antes = pc_conteos();

    $carga = pc_validar($tipo, $admin, [
        ['b' => 'CGT-B1', 's' => 'CGT-B1-S', 'c' => ''],                                                    // 2  color vacío
        ['b' => 'CGT-B1', 's' => 'CGT-B1-M', 't' => 'M'],                                                   // 3  mismo grupo: bloqueado con él
        ['b' => 'CGT-B2', 's' => 'CGT-B2-S', 'c' => 'CGT Verde Lima'],                                      // 4  color desconocido
        ['b' => 'CGT-B3', 's' => 'CGT-B3-XXL', 't' => 'XXL'],                                               // 5  talla que no existe en el catálogo
        ['b' => 'CGT-B4', 's' => 'CGT-AJENO'],                                                              // 6  SKU de otro producto
        ['b' => 'CGT-B5', 's' => 'CGT-B5-S', 'pr' => '0'],                                                  // 7  precio 0
        ['b' => 'CGT-B6', 's' => 'CGT-B6-4', 'p' => 'Niño', 't' => '4'],                                    // 8  familia de niño sin talla
        ['b' => 'CGT-B7', 's' => 'CGT-B7-S'],                                                               // 9  nombres distintos en el grupo
        ['b' => 'CGT-B7', 's' => 'CGT-B7-M', 't' => 'M', 'n' => 'Otra camiseta'],                           // 10
        ['b' => 'CGT-B8', 's' => 'CGT-B8-S'],                                                               // 11 combinación repetida
        ['b' => 'CGT-B8', 's' => 'CGT-B8-S2'],                                                              // 12
        ['b' => 'CGT-B9', 's' => 'CGT-B9-S', 't' => 'XL', 'p' => 'Marciano'],                               // 13 público desconocido
        ['b' => 'CGT-OK', 's' => 'CGT-OK-S'],                                                               // 14 válido: se carga
    ]);

    expect(pc_conteos())->toBe($antes)->and(pc_resultado($carga))->toMatchArray([
        2 => 'rechazada:RECHAZADA_POR_PROCEDIMIENTO/COLOR_VACIO', 3 => 'rechazada:RECHAZADA_POR_PROCEDIMIENTO/GRUPO_BLOQUEADO', 4 => 'rechazada:RECHAZADA_POR_PROCEDIMIENTO/OPCION_DESCONOCIDA',
        5 => 'rechazada:RECHAZADA_POR_PROCEDIMIENTO/OPCION_DESCONOCIDA', 6 => 'rechazada:RECHAZADA_POR_PROCEDIMIENTO/SKU_DE_OTRO_PRODUCTO', 7 => 'rechazada:RECHAZADA_POR_PROCEDIMIENTO/PRECIO_CERO',
        8 => 'rechazada:RECHAZADA_POR_PROCEDIMIENTO/FAMILIA_SIN_ATRIBUTO', 9 => 'rechazada:RECHAZADA_POR_PROCEDIMIENTO/GRUPO_INCONSISTENTE', 10 => 'rechazada:RECHAZADA_POR_PROCEDIMIENTO/GRUPO_BLOQUEADO',
        11 => 'rechazada:RECHAZADA_POR_PROCEDIMIENTO/GRUPO_BLOQUEADO', 12 => 'rechazada:RECHAZADA_POR_PROCEDIMIENTO/COMBINACION_REPETIDA', 13 => 'rechazada:RECHAZADA_POR_PROCEDIMIENTO/PUBLICO_DESCONOCIDO',
        14 => 'pendiente:-/-',
    ]);

    $agrupado = app(CargasService::class)->agrupado($carga);
    $desconocidas = collect($agrupado['secciones'])->firstWhere(fn ($s) => str_contains($s['titulo'], 'no existen'))['items'];
    expect(collect($agrupado['indicadores'])->pluck('n', 'etiqueta')->all())->toMatchArray(['Productos nuevos' => 1, 'Productos bloqueados' => 9, 'Opciones desconocidas' => 2])
        ->and($desconocidas)->toBe(['Color «CGT Verde Lima» — fila 4', 'Talla «XXL» — fila 5'])
        ->and(DB::table('attribute_options')->where('admin_name', 'CGT Verde Lima')->exists())->toBeFalse();                  // nunca se crea la opción

    ob_start();
    app(OutputRechazadasService::class)->csv($carga)->sendContent();
    $csv = (string) ob_get_clean();
    expect($csv)->toContain('CGT Verde Lima')->toContain('OPCION_DESCONOCIDA');                                               // filas no cargadas con su original y motivo

    $carga = pc_ejecutar($carga, $admin);
    expect(DB::table('products')->where('sku', 'like', 'CGT-%')->where('sku', '!=', 'CGT-AJENO')->pluck('sku')->sort()->values()->all())->toBe(['CGT-OK', 'CGT-OK-S'])
        ->and(DB::table('products')->where('id', $ajeno)->value('parent_id'))->toBeNull();                                       // el SKU ajeno no se reasigna
});

test('agrega una variante a un producto existente sin tocar las demás; repetir el archivo no duplica; datos distintos son conflicto sin sobrescribir', function () {
    [$tipo, , $admin] = pc_tipo();
    pc_ejecutar(pc_validar($tipo, $admin, [['b' => 'CGT-H01', 's' => 'CGT-H01-NEG-S'], ['b' => 'CGT-H01', 's' => 'CGT-H01-NEG-M', 't' => 'M']]), $admin);
    $padre = (int) DB::table('products')->where('sku', 'CGT-H01')->value('id');
    $nombreM = pc_valor('CGT-H01-NEG-M', 'name');

    // Solo trae S (igual) y una variante nueva L: M no aparece y NO se borra.
    $archivo = [['b' => 'CGT-H01', 's' => 'CGT-H01-NEG-S'], ['b' => 'CGT-H01', 's' => 'CGT-H01-BLA-L', 'c' => 'CGT Blanco', 't' => 'L']];
    $carga = pc_validar($tipo, $admin, $archivo);
    expect(app(CargasService::class)->agrupado($carga)['grupos'][0]['estado'])->toBe('agregar');
    $carga = pc_ejecutar($carga, $admin);

    expect(pc_resultado($carga))->toBe([2 => 'sin_cambio:ya_registrado/-', 3 => 'aplicada:variante_agregada/VARIANTE_INACTIVA'])
        ->and(DB::table('products')->where('parent_id', $padre)->orderBy('sku')->pluck('sku')->all())->toBe(['CGT-H01-BLA-L', 'CGT-H01-NEG-M', 'CGT-H01-NEG-S'])
        ->and(pc_valor('CGT-H01-NEG-M', 'name'))->toBe($nombreM)
        ->and(pc_valor('CGT-H01-BLA-L', 'cgt_manga'))->toBe(pc_valor('CGT-H01', 'cgt_manga'));                                  // hereda lo que el padre ya tiene

    // Repetir el MISMO archivo: todo sin cambio, nada nuevo.
    $antes = pc_conteos();
    $otra = pc_validar($tipo, $admin, $archivo);
    expect(app(CargasService::class)->agrupado($otra)['grupos'][0]['estado'])->toBe('sin_cambio')
        ->and(pc_resultado(pc_ejecutar($otra, $admin)))->toBe([2 => 'sin_cambio:ya_registrado/-', 3 => 'sin_cambio:ya_registrado/-'])
        ->and(pc_conteos())->toBe($antes);

    // Precio distinto en una variante existente y una combinación que ya existe con otro SKU: conflicto, nada se escribe.
    $conflicto = pc_validar($tipo, $admin, [['b' => 'CGT-H01', 's' => 'CGT-H01-NEG-S', 'pr' => '9.000'], ['b' => 'CGT-H01', 's' => 'CGT-H01-OTRO-M', 't' => 'M']]);
    expect(pc_resultado($conflicto))->toBe([2 => 'rechazada:RECHAZADA_POR_PROCEDIMIENTO/CONFLICTO_DATOS', 3 => 'rechazada:RECHAZADA_POR_PROCEDIMIENTO/COMBINACION_YA_EXISTE'])
        ->and(DB::table('bylopez_cg_carga_incidencias')->where('carga_id', $conflicto->id)->where('fila_origen', 2)->value('motivo'))->toContain('$ 9.000 frente a $ 8.500')
        ->and(pc_valor('CGT-H01-NEG-S', 'price'))->toBe(8500.0)->and(pc_conteos())->toBe($antes);

    // Nombre o público distinto del producto existente: conflicto del grupo.
    $otroNombre = pc_validar($tipo, $admin, [['b' => 'CGT-H01', 's' => 'CGT-H01-NEG-XL', 't' => 'XL', 'n' => 'Camiseta renombrada']]);
    expect(pc_resultado($otroNombre)[2])->toBe('rechazada:RECHAZADA_POR_PROCEDIMIENTO/CONFLICTO_DATOS');
});

test('un fallo a mitad de un producto lo revierte entero (sin padre ni variantes sueltas) y los demás se cargan; el mismo archivo después lo crea', function () {
    [$tipo, , $admin] = pc_tipo();
    $falla = true;
    Event::listen('catalog.product.update.after', function ($p) use (&$falla) {
        if ($falla && $p->sku === 'CGT-F2') {
            throw new RuntimeException('fallo simulado del reindexado');
        }
    });
    $archivo = [['b' => 'CGT-F1', 's' => 'CGT-F1-S'], ['b' => 'CGT-F2', 's' => 'CGT-F2-S'], ['b' => 'CGT-F2', 's' => 'CGT-F2-M', 't' => 'M'], ['b' => 'CGT-F3', 's' => 'CGT-F3-S']];

    $carga = pc_ejecutar(pc_validar($tipo, $admin, $archivo), $admin);
    expect($carga->estado)->toBe('completada')->and(pc_resultado($carga))->toMatchArray([3 => 'rechazada:-/ERROR_INESPERADO', 4 => 'rechazada:-/ERROR_INESPERADO'])
        ->and(DB::table('products')->where('sku', 'like', 'CGT-F2%')->count())->toBe(0)
        ->and(DB::table('products')->where('sku', 'like', 'CGT-F%')->pluck('sku')->sort()->values()->all())->toBe(['CGT-F1', 'CGT-F1-S', 'CGT-F3', 'CGT-F3-S']);

    $falla = false;
    $otra = pc_ejecutar(pc_validar($tipo, $admin, $archivo), $admin);
    expect(pc_resultado($otra))->toBe([2 => 'sin_cambio:ya_registrado/-', 3 => 'aplicada:producto_creado/DATOS_PENDIENTES', 4 => 'aplicada:producto_creado/-', 5 => 'sin_cambio:ya_registrado/-'])
        ->and(DB::table('products')->where('sku', 'like', 'CGT-F%')->count())->toBe(7);
});

test('si se cae la conexión a mitad de la ejecución, lo aplicado queda y al ejecutar de nuevo solo se procesa lo pendiente, sin duplicar', function () {
    config(['cargues.extensiones.productos_camisetas' => PcExtensionQueSeCae::class]);
    PcExtensionQueSeCae::$caerEnGrupo = 2;
    [$tipo, , $admin] = pc_tipo();

    $carga = pc_ejecutar(pc_validar($tipo, $admin, [['b' => 'CGT-R1', 's' => 'CGT-R1-S'], ['b' => 'CGT-R2', 's' => 'CGT-R2-S'], ['b' => 'CGT-R3', 's' => 'CGT-R3-S']]), $admin);
    expect($carga->estado)->toBe('fallida_ejecucion')->and($carga->mensaje)->toContain('APLICACION_INTERRUMPIDA')
        ->and(DB::table('products')->where('sku', 'like', 'CGT-R%')->pluck('sku')->sort()->values()->all())->toBe(['CGT-R1', 'CGT-R1-S'])
        ->and(DB::table(PC_TABLA)->where('cg_carga_id', $carga->id)->whereNull('cg_estado')->count())->toBe(2);

    PcExtensionQueSeCae::$caerEnGrupo = null;
    $carga = pc_ejecutar($carga->fresh(), $admin);
    expect($carga->estado)->toBe('completada')
        ->and(pc_resultado($carga))->toBe([2 => 'aplicada:producto_creado/DATOS_PENDIENTES', 3 => 'aplicada:producto_creado/DATOS_PENDIENTES', 4 => 'aplicada:producto_creado/DATOS_PENDIENTES'])
        ->and(DB::table('products')->where('sku', 'like', 'CGT-R%')->count())->toBe(6);
});

test('plantilla descargable XLSX y CSV: la CSV de la plantilla se valida con sus encabezados reconocidos solos', function () {
    [$tipo, , $admin] = pc_tipo();
    [$ruta] = app(CargasService::class)->plantilla($tipo->id, 'csv');
    $contenido = str_replace(['Negro', 'Blanco', 'Rosado', '80% poliéster / 20% spandex', 'Manga Corta', 'Redondo', 'Cuello-V', 'Piel Durazno', 'EJEMPLO-'], ['CGT Negro', 'CGT Blanco', 'CGT Rosado', 'CGT 80/20', 'CGT Manga Corta', 'CGT Redondo', 'CGT Redondo', 'CGT Piel Durazno', 'CGT-'], file_get_contents($ruta));
    $servicio = app(CargasService::class);
    $carga = $servicio->crear($admin, ['tipo_id' => $tipo->id, 'archivo' => true], UploadedFile::fake()->createWithContent('plantilla.csv', $contenido));
    $sugeridas = array_filter((array) $carga->encabezados['sugerencias'], fn ($i) => $i !== null);

    expect(count($sugeridas))->toBe(15)
        ->and(app(CargasService::class)->plantilla($tipo->id, 'xlsx')[1])->toBe('Plantilla_Productos_Camisetas_Piel_Durazno.xlsx');

    $carga = $servicio->validar($admin, $carga, ['relaciones' => (array) $carga->encabezados['sugerencias']])->fresh();
    expect(collect(app(CargasService::class)->agrupado($carga)['indicadores'])->pluck('n', 'etiqueta')->all())->toMatchArray(['Productos nuevos' => 2, 'Variantes nuevas' => 5]);
});

test('niño usa SU eje de talla (grupo de edad, como el catálogo real): crea color × grupo de edad y nunca acepta tallas de adulto', function () {
    pc_atributo('cgt_edad', 'Grupo de edad', ['2-4', '6-8']);                          // 10-12 y 14-16 no existen en este catálogo de prueba
    pc_familia('cgt_nino_edad', ['sku', 'name', 'url_key', 'new', 'featured', 'visible_individually', 'status', 'short_description', 'description', 'price', 'cost', 'weight',
        'guest_checkout', 'manage_stock', 'color', 'brand', 'tipo_tela', 'cgt_manga', 'cgt_cuello', 'cgt_composicion', 'cgt_edad']);
    config(['cargues.productos_camisetas.publicos.nino' => ['familia' => 'cgt_nino_edad', 'categorias' => [], 'tallas' => 'nino', 'atributo_talla' => 'cgt_edad'],
        'cargues.productos_camisetas.tallas.nino' => ['2-4', '6-8', '10-12', '14-16']]);
    [$tipo, , $admin] = pc_tipo();

    $carga = pc_ejecutar(pc_validar($tipo, $admin, [
        ['b' => 'CGT-N01', 's' => 'CGT-N01-2-4', 'n' => 'Camiseta CGT Niño', 'p' => 'Niño', 't' => '2-4'],
        ['b' => 'CGT-N01', 's' => 'CGT-N01-6-8', 'n' => 'Camiseta CGT Niño', 'p' => 'Niño', 't' => '6-8'],
        ['b' => 'CGT-N02', 's' => 'CGT-N02-S', 'n' => 'Camiseta CGT Niño 2', 'p' => 'Niño', 't' => 'S'],          // talla de adulto
        ['b' => 'CGT-N03', 's' => 'CGT-N03-10-12', 'n' => 'Camiseta CGT Niño 3', 'p' => 'Niño', 't' => '10-12'],  // de la lista, sin opción en el catálogo
    ]), $admin);
    $padre = (int) DB::table('products')->where('sku', 'CGT-N01')->value('id');

    expect(pc_resultado($carga))->toMatchArray([2 => 'aplicada:producto_creado/DATOS_PENDIENTES', 3 => 'aplicada:producto_creado/-',
        4 => 'rechazada:RECHAZADA_POR_PROCEDIMIENTO/TALLA_NO_CORRESPONDE', 5 => 'rechazada:RECHAZADA_POR_PROCEDIMIENTO/OPCION_DESCONOCIDA'])
        ->and(DB::table('product_super_attributes as s')->join('attributes as a', 'a.id', '=', 's.attribute_id')->where('s.product_id', $padre)->orderBy('a.code')->pluck('a.code')->all())->toBe(['cgt_edad', 'color'])
        ->and(pc_valor('CGT-N01-6-8', 'cgt_edad'))->toBe(pc_opcion('cgt_edad', '6-8'))->and(pc_valor('CGT-N01-6-8', 'size'))->toBeNull()
        ->and(DB::table('attribute_options')->where('admin_name', '10-12')->exists())->toBeFalse();
});

test('descripciones: las del archivo se guardan tal cual; sin ellas se arman SOLO con datos confirmados (nada de gramaje ni frases comerciales)', function () {
    [$tipo, , $admin] = pc_tipo();
    $carga = pc_ejecutar(pc_validar($tipo, $admin, [
        ['b' => 'CGT-T1', 's' => 'CGT-T1-S', 'dc' => 'Resumen del archivo', 'd' => 'Texto largo del archivo'],
        ['b' => 'CGT-T2', 's' => 'CGT-T2-S', 'n' => 'Camiseta CGT Dos', 'co' => ''],
    ]), $admin);

    expect(pc_valor('CGT-T1', 'short_description'))->toBe('Resumen del archivo')->and(pc_valor('CGT-T1', 'description'))->toBe('Texto largo del archivo')
        ->and(pc_valor('CGT-T1-S', 'short_description'))->toBe('Resumen del archivo')
        ->and(pc_valor('CGT-T2', 'short_description'))->toBe('<p>Camiseta CGT Dos</p>')
        ->and(pc_valor('CGT-T2', 'description'))->toBe('<p>Camiseta CGT Dos</p><ul><li>Público: Hombre</li><li>Tela: CGT Piel Durazno</li><li>Manga: CGT Manga Corta</li><li>Cuello: CGT Redondo</li><li>Marca: CGT BYLOPEZ</li></ul>')   // sin composición: no venía
        ->and(pc_valor('CGT-T2-S', 'description'))->toBe(pc_valor('CGT-T2', 'description'))
        // T2 es Negro como T1 en la misma línea: Nueva venta solo muestra uno por línea y color → se avisa (no bloquea)
        ->and(DB::table('bylopez_cg_carga_incidencias')->where('carga_id', $carga->id)->where('fila_origen', 3)->value('motivo'))->toContain('UN producto por línea y color')
        ->and(DB::table('bylopez_cg_carga_incidencias')->where('carga_id', $carga->id)->where('fila_origen', 2)->value('motivo'))->not->toContain('UN producto por línea y color');
});
