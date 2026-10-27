<?php

use ByLopez\CorteConfeccion\Enums\EstadoOrdenCorte;
use ByLopez\CorteConfeccion\Enums\EstadoRollo;
use ByLopez\CorteConfeccion\Models\Cortador;
use ByLopez\CorteConfeccion\Models\OrdenCorte;
use ByLopez\CorteConfeccion\Models\OrdenCorteDetalle;
use ByLopez\CorteConfeccion\Models\Rollo;
use ByLopez\CorteConfeccion\Services\DisponibilidadConfeccionService;
use ByLopez\CorteConfeccion\Services\OrdenCorteService;
use ByLopez\CorteConfeccion\Services\RecepcionCorteService;
use ByLopez\CorteConfeccion\Services\SeleccionOrdenCorteService;
use ByLopez\CorteConfeccion\Tests\Support\CatalogoCorteFixtures;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Webkul\Attribute\Models\AttributeFamily;
use Webkul\Attribute\Models\AttributeOption;
use Webkul\Product\Models\Product;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

/*
|--------------------------------------------------------------------------
| F5 - Adicionales del cortador por resolvedor (linea + color + talla ->
| simple exacto). Nunca un product_id del navegador. Helpers con prefijo f5_.
|--------------------------------------------------------------------------
*/

function f5_admin(string $rol, string $permissionType = 'all'): Admin
{
    if (strtolower(trim($rol)) === 'cortador') {
        $admin = Admin::whereHas('role', fn ($q) => $q->where('name', 'CORTADOR'))->first();

        if (! $admin) {
            $role = Role::factory()->create(['name' => 'CORTADOR', 'permission_type' => $permissionType]);
            $admin = Admin::factory()->create(['role_id' => $role->id]);
        }

        Cortador::query()->firstOrCreate(
            ['usuario_id' => $admin->id],
            ['nombre' => 'Cortador F5 '.$admin->id, 'estado' => 'activo']
        );

        return $admin;
    }

    $role = Role::factory()->create(['name' => $rol, 'permission_type' => $permissionType]);

    return Admin::factory()->create(['role_id' => $role->id]);
}

function f5_rollo(CatalogoCorteFixtures $fx, AttributeOption $color): Rollo
{
    return Rollo::create([
        'codigo' => 'R-F5-'.uniqid(),
        'proveedor' => 'Proveedor F5',
        'color' => $color->admin_name,
        'color_option_id' => $color->id,
        'tipo_tela' => $fx->tela->admin_name,
        'tipo_tela_id' => $fx->tela->id,
        'referencia' => 'REF-F5',
        'gramaje' => '180',
        'peso_inicial' => 25,
        'peso_disponible' => 25,
        'estado' => EstadoRollo::Disponible->value,
    ]);
}

function f5_catalogo(): array
{
    $fx = CatalogoCorteFixtures::nuevo();

    $negro = $fx->opcion($fx->color, 'Negro');
    $rojo = $fx->opcion($fx->color, 'Rojo');
    $verde = $fx->opcion($fx->color, 'Verde Cali');
    $tallas = $fx->tallas(['S', 'M', 'L', 'XL', 'XXL']);
    $grupoEdad = $fx->atributo('grupo_de_edad_ninos', 'grupo de edad (niños)');
    $grupos = ['2-4' => $fx->opcion($grupoEdad, '2-4', 1), '6-8' => $fx->opcion($grupoEdad, '6-8', 2)];

    $hombre = $fx->familia('Camiseta Hombre  DURZ');
    $dama = $fx->familia('Camiseta Mujer DURZ');
    $nino = $fx->familia('Camisetas Niño DURZ');

    $sinXxl = array_intersect_key($tallas, array_flip(['S', 'M', 'L', 'XL']));

    return [
        'fx' => $fx, 'negro' => $negro, 'rojo' => $rojo, 'verde' => $verde, 'tallas' => $tallas, 'grupos' => $grupos,
        'hombre' => $hombre, 'dama' => $dama, 'nino' => $nino,
        'hombreNegro' => $fx->configurableConEje($hombre, $negro, $sinXxl, ['sku' => 'F5-H-NEGRO', 'nombre' => 'Camiseta Negro Hombre']),
        'hombreRojo' => $fx->configurableConEje($hombre, $rojo, $sinXxl, ['sku' => 'F5-H-ROJO', 'nombre' => 'Camiseta Rojo Hombre']),
        'damaNegro' => $fx->configurableConEje($dama, $negro, array_intersect_key($tallas, array_flip(['M', 'L'])), ['sku' => 'F5-D-NEGRO', 'nombre' => 'Camiseta Negro Mujer']),
        'ninoRojo' => $fx->configurableConEje($nino, $rojo, $grupos, ['sku' => 'F5-N-ROJO', 'nombre' => 'Camiseta Rojo Nino', 'ejes' => [$fx->color, $grupoEdad]]),
    ];
}

function f5_sel(AttributeFamily $familia, AttributeOption $color, AttributeOption $eje, int $cantidad, array $extra = []): array
{
    return array_merge([
        'attribute_family_id' => $familia->id,
        'color_option_id' => $color->id,
        'eje_option_id' => $eje->id,
        'cantidad_real_cortada' => $cantidad,
        'filtros' => [],
    ], $extra);
}

/** Orden V2 en corte con un detalle solicitado Hombre Negro M (y opcionalmente otros). */
function f5_orden(array $c, array $rollos, ?array $seleccion = null): OrdenCorte
{
    $servicio = app(OrdenCorteService::class);
    $seleccion ??= [['attribute_family_id' => $c['hombre']->id, 'color_option_id' => $c['negro']->id, 'eje_option_id' => $c['tallas']['M']->id, 'filtros' => []]];

    $orden = $servicio->crearDesdeSeleccion([
        'rollo_ids' => collect($rollos)->pluck('id')->all(),
        'cortador_id' => (Admin::whereHas('role', fn ($q) => $q->where('name', 'CORTADOR'))->first() ?? f5_admin('CORTADOR'))->id,
        'observacion' => null,
        'seleccion' => $seleccion,
    ]);

    return $servicio->cambiarEstado($servicio->entregar($orden), EstadoOrdenCorte::EntregadoACortador->value, EstadoOrdenCorte::EnCorte->value);
}

function f5_resultados(OrdenCorte $orden, int $cantidad): array
{
    return $orden->detalles()->orderBy('id')->get()->map(fn ($d) => ['detalle_id' => $d->id, 'cantidad_real_cortada' => $cantidad])->all();
}

/** Todo lo que un reporte fallido NO debe haber tocado. */
function f5_huella(OrdenCorte $orden): array
{
    return [
        'estado' => $orden->fresh()->estado,
        'reportado_por' => $orden->fresh()->reportado_por_id,
        'detalles' => OrdenCorteDetalle::where('orden_corte_id', $orden->id)->orderBy('id')->get(['id', 'cantidad_real_cortada', 'es_adicional'])->toArray(),
        'auditoria' => DB::table('bylopez_cc_ordenes_corte_detalles')->count(),
    ];
}

beforeEach(function () {
    Auth::guard('admin')->setUser(f5_admin('Bodega QA F5 '.uniqid()));
    $this->c = f5_catalogo();
    $this->servicio = app(OrdenCorteService::class);
});

/*
| Resolucion y snapshots
*/

test('CASOS 1, 16-20, 22, 26 - Hombre Negro M resuelve el simple exacto con snapshots F4, proyectada 0 y es_adicional', function () {
    $c = $this->c;
    $orden = f5_orden($c, [f5_rollo($c['fx'], $c['negro'])]);
    $solicitado = $orden->detalles()->sole();

    Auth::guard('admin')->setUser(f5_admin('CORTADOR'));
    $orden = $this->servicio->reportarResultado($orden, [
        'resultados' => [['detalle_id' => $solicitado->id, 'cantidad_real_cortada' => 20]],
        'adicionales' => [f5_sel($c['hombre'], $c['negro'], $c['tallas']['L'], 6)],
    ]);

    $adicional = $orden->detalles->firstWhere('es_adicional', true);

    expect($orden->estado)->toBe(EstadoOrdenCorte::ReportadoPorCortador->value)
        ->and($orden->detalles)->toHaveCount(2)
        ->and($adicional->product_id)->toBe($c['hombreNegro']['hijos']['L']->id)
        ->and($adicional->product_id)->not->toBe($c['hombreNegro']['configurable']->id)   // nunca el configurable
        ->and($adicional->producto_sku)->toBe('F5-H-NEGRO-L')                              // SKU exacto
        ->and($adicional->producto_nombre)->toBe('Camiseta Negro Hombre')
        ->and($adicional->genero_linea)->toBe('Camiseta Hombre DURZ')
        ->and($adicional->color)->toBe('Negro')
        ->and($adicional->talla)->toBe('L')
        ->and($adicional->cantidad_proyectada)->toBe(0)
        ->and($adicional->cantidad_real_cortada)->toBe(6)
        ->and($adicional->es_adicional)->toBeTrue()
        ->and($orden->detalles->firstWhere('id', $solicitado->id)->cantidad_real_cortada)->toBe(20);
});

test('CASO 2 - Nino Rojo 6-8 usa el eje dinamico grupo de edad', function () {
    $c = $this->c;
    $orden = f5_orden($c, [f5_rollo($c['fx'], $c['negro']), f5_rollo($c['fx'], $c['rojo'])]);

    Auth::guard('admin')->setUser(f5_admin('CORTADOR'));
    $orden = $this->servicio->reportarResultado($orden, [
        'resultados' => f5_resultados($orden, 5),
        'adicionales' => [f5_sel($c['nino'], $c['rojo'], $c['grupos']['6-8'], 4)],
    ]);

    $adicional = $orden->detalles->firstWhere('es_adicional', true);

    expect($adicional->producto_sku)->toBe('F5-N-ROJO-6-8')
        ->and($adicional->talla)->toBe('6-8')
        ->and($adicional->genero_linea)->toBe('Camisetas Niño DURZ');
});

test('CASOS 3, 4 - solo se permiten los colores de los rollos de la orden', function () {
    $c = $this->c;
    $orden = f5_orden($c, [f5_rollo($c['fx'], $c['negro']), f5_rollo($c['fx'], $c['rojo'])]);

    // Los colores permitidos salen de los rollos (Negro y Rojo).
    $matriz = app(SeleccionOrdenCorteService::class)->matriz($this->servicio->rollosDeOrden($orden));
    expect(array_column($matriz['colores'], 'label'))->toEqualCanonicalizing(['Negro', 'Rojo']);

    Auth::guard('admin')->setUser(f5_admin('CORTADOR'));
    $antes = f5_huella($orden);

    // Verde Cali existe en el catalogo pero ningun rollo de la orden lo tiene.
    $verde = $c['fx']->configurableConEje($c['dama'], $c['verde'], array_intersect_key($c['tallas'], array_flip(['M'])), ['sku' => 'F5-D-VERDE']);

    expect(fn () => $this->servicio->reportarResultado($orden, [
        'resultados' => f5_resultados($orden, 9),
        'adicionales' => [f5_sel($c['dama'], $c['verde'], $c['tallas']['M'], 3)],
    ]))->toThrow(ValidationException::class, 'no pertenece a los rollos');

    expect(f5_huella($orden))->toBe($antes);
});

test('CASO 5 - un product_id manipulado desde el navegador se ignora (HTTP)', function () {
    $c = $this->c;
    $orden = f5_orden($c, [f5_rollo($c['fx'], $c['negro'])]);
    $ajeno = Product::factory()->simple()->create();

    $this->actingAs(f5_admin('CORTADOR HTTP'), 'admin')
        ->post(route('admin.corte_confeccion.ordenes_corte.reportar', $orden->id), [
            'resultados' => f5_resultados($orden, 10),
            'adicionales' => [f5_sel($c['hombre'], $c['negro'], $c['tallas']['S'], 2, ['product_id' => $ajeno->id, 'producto_sku' => 'HACK', 'talla' => 'XXL'])],
        ])
        ->assertSessionHasNoErrors();

    $adicional = OrdenCorteDetalle::where('orden_corte_id', $orden->id)->where('es_adicional', true)->sole();

    expect($adicional->product_id)->toBe($c['hombreNegro']['hijos']['S']->id)
        ->and($adicional->product_id)->not->toBe($ajeno->id)
        ->and($adicional->producto_sku)->toBe('F5-H-NEGRO-S')
        ->and($adicional->talla)->toBe('S');
});

test('CASOS 6, 7 - cantidad cero o negativa se rechaza (servicio y HTTP) sin descartar filas en silencio', function () {
    $c = $this->c;
    $orden = f5_orden($c, [f5_rollo($c['fx'], $c['negro'])]);
    $antes = f5_huella($orden);

    Auth::guard('admin')->setUser(f5_admin('CORTADOR'));

    foreach ([0, -3] as $cantidad) {
        expect(fn () => $this->servicio->reportarResultado($orden, [
            'resultados' => f5_resultados($orden, 10),
            'adicionales' => [f5_sel($c['hombre'], $c['negro'], $c['tallas']['S'], $cantidad)],
        ]), "cantidad $cantidad")->toThrow(ValidationException::class);
    }

    expect(f5_huella($orden))->toBe($antes);

    foreach ([0, -3] as $cantidad) {
        $this->actingAs(f5_admin('CORTADOR HTTP'), 'admin')
            ->post(route('admin.corte_confeccion.ordenes_corte.reportar', $orden->id), [
                'resultados' => f5_resultados($orden, 10),
                'adicionales' => [f5_sel($c['hombre'], $c['negro'], $c['tallas']['S'], $cantidad)],
            ])
            ->assertSessionHasErrors('adicionales.0.cantidad_real_cortada');
    }

    expect(f5_huella($orden))->toBe($antes);
});

test('CASOS 8, 9 - simple o configurable inactivo se permite y muestra la advertencia', function () {
    $c = $this->c;
    $fx = $c['fx'];

    DB::table('product_attribute_values')->where('product_id', $c['hombreNegro']['hijos']['XL']->id)->where('attribute_id', $fx->status->id)->update(['boolean_value' => 0]);

    $inactivo = $fx->configurableConEje($c['hombre'], $fx->opcion($fx->color, 'Azul Inactivo'), ['M' => $c['tallas']['M']], ['sku' => 'F5-INACT-C', 'activo' => false]);
    $azul = AttributeOption::find(DB::table('product_attribute_values')->where('product_id', $inactivo['hijos']['M']->id)->where('attribute_id', $fx->color->id)->value('integer_value'));

    $orden = f5_orden($c, [f5_rollo($fx, $c['negro']), f5_rollo($fx, $azul)]);
    $adicionales = [f5_sel($c['hombre'], $c['negro'], $c['tallas']['XL'], 1), f5_sel($c['hombre'], $azul, $c['tallas']['M'], 2)];

    $preview = app(SeleccionOrdenCorteService::class)->resolver($this->servicio->rollosDeOrden($orden), $adicionales);

    expect($preview['errores'])->toBe([])
        ->and(collect($preview['filas'])->pluck('advertencias')->flatten()->unique()->count())->toBe(2);

    Auth::guard('admin')->setUser(f5_admin('CORTADOR'));
    $orden = $this->servicio->reportarResultado($orden, ['resultados' => f5_resultados($orden, 3), 'adicionales' => $adicionales]);

    expect($orden->detalles->where('es_adicional', true))->toHaveCount(2);
});

/*
| Errores del resolvedor y atomicidad
*/

test('CASOS 10, 11 - un error del resolvedor revierte TODO: cantidades existentes, adicionales, estado y auditoria', function () {
    $c = $this->c;
    $fx = $c['fx'];

    // Color ambiguo (dos configurables Dama Negro) y variante duplicada (dos simples Hombre Rojo L).
    $fx->configurableConEje($c['dama'], $c['negro'], array_intersect_key($c['tallas'], array_flip(['M'])), ['sku' => 'F5-D-NEGRO-BIS']);
    $fx->hijo($c['hombreRojo']['configurable'], $c['rojo'], $c['tallas']['L'], ['sku' => 'F5-H-ROJO-L-DUP']);

    $orden = f5_orden($c, [f5_rollo($fx, $c['negro']), f5_rollo($fx, $c['rojo'])]);
    Auth::guard('admin')->setUser(f5_admin('CORTADOR'));

    $bueno = f5_sel($c['hombre'], $c['negro'], $c['tallas']['S'], 3);
    $malos = [
        'color_ambiguo' => [f5_sel($c['dama'], $c['negro'], $c['tallas']['M'], 2), 'Camiseta Mujer DURZ'],
        'talla_no_disponible' => [f5_sel($c['hombre'], $c['negro'], $c['tallas']['XXL'], 2), 'talla'],
        'variante_duplicada' => [f5_sel($c['hombre'], $c['rojo'], $c['tallas']['L'], 2), 'mas de una variante'],
        'sin_producto_para_color' => [f5_sel($c['dama'], $c['rojo'], $c['tallas']['M'], 2), 'Camiseta Mujer DURZ'],
    ];

    foreach ($malos as $nombre => [$malo, $mensaje]) {
        $antes = f5_huella($orden);

        // La fila valida va PRIMERO y ademas se intenta cambiar una cantidad existente.
        expect(fn () => $this->servicio->reportarResultado($orden, [
            'resultados' => f5_resultados($orden, 77),
            'adicionales' => [$bueno, $malo],
        ]), $nombre)->toThrow(ValidationException::class, $mensaje);

        expect(f5_huella($orden))->toBe($antes, "quedo algo escrito en: $nombre");
    }

    expect($orden->fresh()->estado)->toBe(EstadoOrdenCorte::EnCorte->value)
        ->and($orden->detalles()->count())->toBe(1)
        ->and($orden->detalles()->sole()->cantidad_real_cortada)->toBe(0);
});

test('CASOS 12, 13, 21, 24, 25 - varios adicionales, multi-color y multi-linea; mismo product_id que el solicitado queda SEPARADO', function () {
    $c = $this->c;
    $orden = f5_orden($c, [f5_rollo($c['fx'], $c['negro']), f5_rollo($c['fx'], $c['rojo'])]);
    $solicitado = $orden->detalles()->sole();   // Hombre Negro M

    Auth::guard('admin')->setUser(f5_admin('CORTADOR'));
    $orden = $this->servicio->reportarResultado($orden, [
        'resultados' => [['detalle_id' => $solicitado->id, 'cantidad_real_cortada' => 20]],
        'adicionales' => [
            f5_sel($c['hombre'], $c['negro'], $c['tallas']['M'], 6),    // MISMO simple que el solicitado
            f5_sel($c['hombre'], $c['rojo'], $c['tallas']['S'], 2),     // otro color
            f5_sel($c['dama'], $c['negro'], $c['tallas']['L'], 3),      // otra linea
            f5_sel($c['nino'], $c['rojo'], $c['grupos']['2-4'], 1),     // eje dinamico
        ],
    ]);

    $adicionales = $orden->detalles->where('es_adicional', true);
    $mismo = $adicionales->firstWhere('producto_sku', 'F5-H-NEGRO-M');

    expect($orden->detalles)->toHaveCount(5)
        ->and($adicionales)->toHaveCount(4)
        ->and($mismo->product_id)->toBe($solicitado->product_id)
        ->and($mismo->id)->not->toBe($solicitado->id)
        ->and($mismo->cantidad_real_cortada)->toBe(6)
        ->and($orden->detalles->firstWhere('id', $solicitado->id)->cantidad_real_cortada)->toBe(20)   // sin fusionar
        ->and($adicionales->pluck('producto_sku')->all())->toEqualCanonicalizing(['F5-H-NEGRO-M', 'F5-H-ROJO-S', 'F5-D-NEGRO-L', 'F5-N-ROJO-2-4'])
        ->and($adicionales->pluck('genero_linea')->unique())->toHaveCount(3);
});

test('CASO 23 - dos adicionales identicos en el mismo envio se rechazan (decision: no se unifican) y no queda nada', function () {
    $c = $this->c;
    $orden = f5_orden($c, [f5_rollo($c['fx'], $c['negro'])]);
    $antes = f5_huella($orden);

    Auth::guard('admin')->setUser(f5_admin('CORTADOR'));

    expect(fn () => $this->servicio->reportarResultado($orden, [
        'resultados' => f5_resultados($orden, 10),
        'adicionales' => [f5_sel($c['hombre'], $c['negro'], $c['tallas']['S'], 2), f5_sel($c['hombre'], $c['negro'], $c['tallas']['S'], 4)],
    ]))->toThrow(ValidationException::class, 'repetida');

    expect(f5_huella($orden))->toBe($antes);
});

test('CASO 28 - resolverLote: una carga de catalogo sin importar cuantos adicionales', function () {
    $c = $this->c;
    $orden = f5_orden($c, [f5_rollo($c['fx'], $c['negro']), f5_rollo($c['fx'], $c['rojo'])]);
    $t = $c['tallas'];

    $uno = [f5_sel($c['hombre'], $c['negro'], $t['S'], 1)];
    $muchos = [
        f5_sel($c['hombre'], $c['negro'], $t['S'], 1), f5_sel($c['hombre'], $c['negro'], $t['L'], 1), f5_sel($c['hombre'], $c['rojo'], $t['S'], 1),
        f5_sel($c['hombre'], $c['rojo'], $t['XL'], 1), f5_sel($c['dama'], $c['negro'], $t['M'], 1), f5_sel($c['nino'], $c['rojo'], $c['grupos']['6-8'], 1),
    ];

    $this->servicio->resolverAdicionales($orden, $uno);   // calentamiento

    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->servicio->resolverAdicionales($orden, $uno);
    $queriesUno = count(DB::getQueryLog());
    DB::flushQueryLog();
    $filas = $this->servicio->resolverAdicionales($orden, $muchos);
    $queriesMuchos = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($filas)->toHaveCount(6)->and($queriesMuchos)->toBe($queriesUno);
});

/*
| Estado
*/

test('CASOS 27, 29 - no se agregan adicionales tras reportar, recibir o cerrar (maquina de estados)', function () {
    $c = $this->c;
    $orden = f5_orden($c, [f5_rollo($c['fx'], $c['negro'])]);

    Auth::guard('admin')->setUser(f5_admin('CORTADOR'));
    $adicional = f5_sel($c['hombre'], $c['negro'], $c['tallas']['S'], 2);
    $orden = $this->servicio->reportarResultado($orden, ['resultados' => f5_resultados($orden, 10)]);

    // Ya reportada.
    expect(fn () => $this->servicio->reportarResultado($orden, ['resultados' => f5_resultados($orden, 10), 'adicionales' => [$adicional]]))
        ->toThrow(ValidationException::class)
        ->and(fn () => $this->servicio->rollosParaAdicionales($orden->fresh()))->toThrow(ValidationException::class);

    // Recibida en bodega.
    Auth::guard('admin')->setUser(f5_admin('Bodega QA Recepcion F5'));
    $detalle = $orden->detalles()->sole();
    app(RecepcionCorteService::class)->create($orden->fresh(), [
        'fecha_recepcion' => now()->toDateString(), 'merma_total' => 0, 'recibido_por' => 'Bodega QA', 'observacion' => null,
        'detalles' => [['orden_corte_detalle_id' => $detalle->id, 'cantidad_recibida' => 10, 'cantidad_defectuosa' => 0]],
    ]);

    expect($orden->fresh()->estado)->toBe(EstadoOrdenCorte::RecibidoBodega->value)
        ->and(fn () => $this->servicio->reportarResultado($orden->fresh(), ['adicionales' => [$adicional]]))->toThrow(ValidationException::class)
        ->and($orden->detalles()->count())->toBe(1);
});

/*
| Recepcion, confeccion, historicos
*/

test('CASOS 30-32 - recepcion por orden_corte_detalle_id: Bodega ve solicitado y adicional por separado y confeccion suma 20 + 6', function () {
    $c = $this->c;
    $orden = f5_orden($c, [f5_rollo($c['fx'], $c['negro'])]);
    $solicitado = $orden->detalles()->sole();

    Auth::guard('admin')->setUser(f5_admin('CORTADOR'));
    $orden = $this->servicio->reportarResultado($orden, [
        'resultados' => [['detalle_id' => $solicitado->id, 'cantidad_real_cortada' => 20]],
        'adicionales' => [f5_sel($c['hombre'], $c['negro'], $c['tallas']['M'], 6)],
    ]);
    $adicional = $orden->detalles->firstWhere('es_adicional', true);

    Auth::guard('admin')->setUser(f5_admin('Bodega QA Recepcion F5'));
    $recepcion = app(RecepcionCorteService::class)->create($orden->fresh(), [
        'fecha_recepcion' => now()->toDateString(), 'merma_total' => 0, 'recibido_por' => 'Bodega QA', 'observacion' => null,
        'detalles' => [
            ['orden_corte_detalle_id' => $adicional->id, 'cantidad_recibida' => 6, 'cantidad_defectuosa' => 0],
            ['orden_corte_detalle_id' => $solicitado->id, 'cantidad_recibida' => 20, 'cantidad_defectuosa' => 0],
        ],
    ]);

    $filaSolicitado = $recepcion->detalles->firstWhere('orden_corte_detalle_id', $solicitado->id);
    $filaAdicional = $recepcion->detalles->firstWhere('orden_corte_detalle_id', $adicional->id);

    expect($recepcion->detalles)->toHaveCount(2)
        ->and($filaSolicitado->cantidad_recibida)->toBe(20)
        ->and($filaAdicional->cantidad_recibida)->toBe(6)
        ->and($filaAdicional->product_id)->toBe($solicitado->product_id);

    // Confeccion agrega por product_id: 20 + 6.
    expect(app(DisponibilidadConfeccionService::class)->disponiblePara($orden->fresh(), $solicitado->product_id))->toBe(26);

    $html = $this->actingAs(f5_admin('Admin QA Show F5'), 'admin')
        ->get(route('admin.corte_confeccion.ordenes_corte.show', $orden->id))->assertOk()->getContent();

    expect($html)->toContain('F5-H-NEGRO-M')->toContain('Adicional');
});

test('CASO 33 - una orden historica (rollos sin color estructurado) sigue funcionando y no puede agregar adicionales estructurados', function () {
    $c = $this->c;
    $rollo = f5_rollo($c['fx'], $c['negro']);
    $orden = f5_orden($c, [$rollo]);
    $rollo->update(['color_option_id' => null]);   // como un rollo previo a la estructura de color
    Auth::guard('admin')->setUser(f5_admin('CORTADOR'));

    // Sin adicionales: el reporte normal no se ve afectado.
    $reportada = $this->servicio->reportarResultado($orden, ['resultados' => f5_resultados($orden, 10)]);
    expect($reportada->estado)->toBe(EstadoOrdenCorte::ReportadoPorCortador->value);

    // Con adicionales estructurados sobre rollos sin color: se bloquea con un mensaje claro y sin cambios.
    $otra = f5_orden($c, [f5_rollo($c['fx'], $c['negro'])]);
    DB::table('bylopez_cc_rollos')->whereIn('id', $otra->rollosCorte()->pluck('rollo_id'))->update(['color_option_id' => null]);
    $antes = f5_huella($otra);

    expect(fn () => $this->servicio->reportarResultado($otra, [
        'resultados' => f5_resultados($otra, 10),
        'adicionales' => [f5_sel($c['hombre'], $c['negro'], $c['tallas']['S'], 2)],
    ]))->toThrow(ValidationException::class);

    expect(f5_huella($otra))->toBe($antes);
});

/*
| UI, endpoints y ACL
*/

test('CASOS 34, 35 - la tarjeta del cortador ya no tiene buscador libre y expone los endpoints estructurados; ACL', function () {
    $c = $this->c;
    $orden = f5_orden($c, [f5_rollo($c['fx'], $c['negro']), f5_rollo($c['fx'], $c['rojo'])]);

    $html = $this->actingAs(f5_admin('Admin QA UI F5'), 'admin')
        ->get(route('admin.corte_confeccion.ordenes_corte.show', $orden->id))->assertOk()->getContent();

    expect($html)->toContain('+ Agregar adicional')
        ->toContain(route('admin.corte_confeccion.ordenes_corte.adicionales.opciones', $orden->id, false))
        ->not->toContain('product-search-url')   // el layout admin trae su propio buscador global
        ->not->toContain('buscarProducto')->not->toContain('seleccionarProducto')
        ->not->toContain('Buscar por nombre o SKU')
        ->not->toContain('[product_id]');

    $operador = f5_admin('CORTADOR ACL', 'custom');
    $operador->role->update(['permissions' => ['corte_confeccion.ordenes_corte.operar']]);

    $opciones = $this->actingAs($operador->fresh(), 'admin')
        ->getJson(route('admin.corte_confeccion.ordenes_corte.adicionales.opciones', $orden->id))
        ->assertOk()->json('data');

    expect(array_column($opciones['colores'], 'label'))->toEqualCanonicalizing(['Negro', 'Rojo']);

    $preview = $this->actingAs($operador->fresh(), 'admin')
        ->postJson(route('admin.corte_confeccion.ordenes_corte.adicionales.previsualizar', $orden->id), ['adicionales' => [f5_sel($c['hombre'], $c['rojo'], $c['tallas']['L'], 3)]])
        ->assertOk()->json('data');

    expect($preview['errores'])->toBe([])->and($preview['filas'][0]['sku'])->toBe('F5-H-ROJO-L');

    // Sin el permiso "operar": no entra a los endpoints.
    $sinPermiso = f5_admin('BODEGA ACL', 'custom');
    $sinPermiso->role->update(['permissions' => ['corte_confeccion.ordenes_corte']]);

    $this->actingAs($sinPermiso->fresh(), 'admin')
        ->getJson(route('admin.corte_confeccion.ordenes_corte.adicionales.opciones', $orden->id))
        ->assertStatus(401);
    $this->actingAs($sinPermiso->fresh(), 'admin')
        ->postJson(route('admin.corte_confeccion.ordenes_corte.adicionales.previsualizar', $orden->id), ['adicionales' => []])
        ->assertStatus(401);
});
