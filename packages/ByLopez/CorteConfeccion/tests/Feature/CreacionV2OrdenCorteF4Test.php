<?php

use ByLopez\CorteConfeccion\Enums\EstadoOrdenCorte;
use ByLopez\CorteConfeccion\Enums\EstadoRollo;
use ByLopez\CorteConfeccion\Models\Cortador;
use ByLopez\CorteConfeccion\Models\OrdenCorte;
use ByLopez\CorteConfeccion\Models\OrdenCorteDetalle;
use ByLopez\CorteConfeccion\Models\Rollo;
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
| F4 - Creacion V2 de Orden de Corte (rollos -> colores reales -> lineas ->
| tallas reales -> resolverLote -> simples exactos). Fixtures de catalogo en
| tests/Support/CatalogoCorteFixtures.php (F2). Helpers con prefijo f4_.
|--------------------------------------------------------------------------
*/

function f4_admin(string $rol, string $permissionType = 'all'): Admin
{
    if (strtolower(trim($rol)) === 'cortador') {
        $admin = Admin::whereHas('role', fn ($q) => $q->where('name', 'CORTADOR'))->first();

        if (! $admin) {
            $role = Role::factory()->create(['name' => 'CORTADOR', 'permission_type' => $permissionType]);
            $admin = Admin::factory()->create(['role_id' => $role->id]);
        }

        Cortador::query()->firstOrCreate(
            ['usuario_id' => $admin->id],
            ['nombre' => 'Cortador F4 '.$admin->id, 'estado' => 'activo']
        );

        return $admin;
    }

    $role = Role::factory()->create(['name' => $rol, 'permission_type' => $permissionType]);

    return Admin::factory()->create(['role_id' => $role->id]);
}

function f4_rollo(CatalogoCorteFixtures $fx, AttributeOption $color, array $extra = []): Rollo
{
    return Rollo::create(array_merge([
        'codigo' => 'R-F4-'.uniqid(),
        'proveedor' => 'Proveedor F4',
        'color' => $color->admin_name,
        'color_option_id' => $color->id,
        'tipo_tela' => $fx->tela->admin_name,
        'tipo_tela_id' => $fx->tela->id,
        'referencia' => 'REF-F4',
        'gramaje' => '180',
        'peso_inicial' => 25,
        'peso_disponible' => 25,
        'estado' => EstadoRollo::Disponible->value,
    ], $extra));
}

/**
 * Catalogo: Hombre (Negro, Rojo x S M L XL), Dama (Negro, Verde Cali x M L XL),
 * Nino (Rojo x 4 grupos de edad). Tela unica del escenario.
 */
function f4_catalogo(): array
{
    $fx = CatalogoCorteFixtures::nuevo();

    $negro = $fx->opcion($fx->color, 'Negro');
    $rojo = $fx->opcion($fx->color, 'Rojo');
    $verde = $fx->opcion($fx->color, 'Verde Cali');
    $tallas = $fx->tallas(['S', 'M', 'L', 'XL', 'XXL']);
    $grupoEdad = $fx->atributo('grupo_de_edad_ninos', 'grupo de edad (niños)');
    $grupos = ['2-4' => $fx->opcion($grupoEdad, '2-4', 1), '6-8' => $fx->opcion($grupoEdad, '6-8', 2), '10-12' => $fx->opcion($grupoEdad, '10-12', 3), '14-16' => $fx->opcion($grupoEdad, '14-16', 4)];

    $hombre = $fx->familia('Camiseta Hombre  DURZ');   // doble espacio real del catalogo
    $dama = $fx->familia('Camiseta Mujer DURZ');
    $nino = $fx->familia('Camisetas Niño DURZ');

    $sinXxl = fn (array $t) => array_intersect_key($t, array_flip(['S', 'M', 'L', 'XL']));
    $damaTallas = array_intersect_key($tallas, array_flip(['M', 'L', 'XL']));

    return [
        'fx' => $fx, 'negro' => $negro, 'rojo' => $rojo, 'verde' => $verde, 'tallas' => $tallas, 'grupos' => $grupos,
        'hombre' => $hombre, 'dama' => $dama, 'nino' => $nino,
        'hombreNegro' => $fx->configurableConEje($hombre, $negro, $sinXxl($tallas), ['sku' => 'F4-H-NEGRO', 'nombre' => 'Camiseta Negro Hombre']),
        'hombreRojo' => $fx->configurableConEje($hombre, $rojo, $sinXxl($tallas), ['sku' => 'F4-H-ROJO', 'nombre' => 'Camiseta Rojo Hombre']),
        'damaNegro' => $fx->configurableConEje($dama, $negro, $damaTallas, ['sku' => 'F4-D-NEGRO', 'nombre' => 'Camiseta Negro Mujer']),
        'damaVerde' => $fx->configurableConEje($dama, $verde, $damaTallas, ['sku' => 'F4-D-VERDE', 'nombre' => 'Camiseta Verde Cali Mujer']),
        'ninoRojo' => $fx->configurableConEje($nino, $rojo, $grupos, ['sku' => 'F4-N-ROJO', 'nombre' => 'Camiseta Rojo Nino', 'ejes' => [$fx->color, $grupoEdad]]),
    ];
}

function f4_sel(AttributeFamily|int $familia, AttributeOption|int $color, AttributeOption|int $eje, array $filtros = []): array
{
    $id = fn ($v) => is_int($v) ? $v : $v->id;

    return ['attribute_family_id' => is_int($familia) ? $familia : $familia->id, 'color_option_id' => $id($color), 'eje_option_id' => $id($eje), 'filtros' => $filtros];
}

function f4_payload(array $rollos, array $seleccion, array $extra = []): array
{
    return array_merge([
        'rollo_ids' => collect($rollos)->pluck('id')->all(),
        'cortador_id' => Admin::whereHas('role', fn ($q) => $q->where('name', 'CORTADOR'))->first()?->id ?? f4_admin('CORTADOR')->id,
        'observacion' => null,
        'seleccion' => $seleccion,
    ], $extra);
}

function f4_crear(array $rollos, array $seleccion, array $extra = []): OrdenCorte
{
    return app(OrdenCorteService::class)->crearDesdeSeleccion(f4_payload($rollos, $seleccion, $extra));
}

/** Estado completo de "algo se creo" para comprobar rollback total. */
function f4_huella(array $rollos): array
{
    return [
        'ordenes' => OrdenCorte::count(),
        'pivotes' => DB::table('bylopez_cc_orden_corte_rollos')->count(),
        'detalles' => OrdenCorteDetalle::count(),
        'rollos' => collect($rollos)->map(fn ($r) => $r->fresh()->only(['estado', 'peso_disponible']))->all(),
    ];
}

beforeEach(function () {
    Auth::guard('admin')->setUser(f4_admin('Bodega QA F4 '.uniqid()));
    $this->c = f4_catalogo();
});

/*
| Creacion y detalles
*/

test('CASOS 1, 12-15, 27, 31 - un rollo Negro + Hombre M crea el simple exacto con snapshots correctos', function () {
    $c = $this->c;
    $rollo = f4_rollo($c['fx'], $c['negro']);

    $orden = f4_crear([$rollo], [f4_sel($c['hombre'], $c['negro'], $c['tallas']['M'])]);
    $detalle = $orden->detalles()->sole();

    expect($detalle->product_id)->toBe($c['hombreNegro']['hijos']['M']->id)
        ->and($detalle->producto_sku)->toBe('F4-H-NEGRO-M')
        ->and($detalle->producto_nombre)->toBe('Camiseta Negro Hombre')
        ->and($detalle->genero_linea)->toBe('Camiseta Hombre DURZ')   // espacios normalizados
        ->and($detalle->color)->toBe('Negro')
        ->and($detalle->talla)->toBe('M')
        ->and($detalle->cantidad_proyectada)->toBe(0)
        ->and($detalle->cantidad_real_cortada)->toBe(0)
        ->and($detalle->es_adicional)->toBeFalse()
        ->and($detalle->variant_id)->toBeNull()
        // Nunca el configurable: siempre un simple.
        ->and(Product::find($detalle->product_id)->type)->toBe('simple')
        ->and($detalle->product_id)->not->toBe($c['hombreNegro']['configurable']->id);
});

test('CASOS 2, 3, 7, 8, 36 - mismo color, colores distintos, gramajes y referencias distintas de la misma tela: permitido', function () {
    $c = $this->c;
    $rollos = [
        f4_rollo($c['fx'], $c['negro'], ['gramaje' => '170', 'referencia' => 'REF-A', 'peso_disponible' => 20, 'peso_inicial' => 20]),
        f4_rollo($c['fx'], $c['negro'], ['gramaje' => '190', 'referencia' => 'REF-B', 'proveedor' => 'Otro proveedor']),
        f4_rollo($c['fx'], $c['rojo'], ['gramaje' => '180']),
    ];

    $orden = f4_crear($rollos, [f4_sel($c['hombre'], $c['negro'], $c['tallas']['S']), f4_sel($c['hombre'], $c['rojo'], $c['tallas']['M'])]);

    expect($orden->rollosCorte()->count())->toBe(3)
        ->and((float) $orden->peso_total_rollos)->toBe(70.0)
        ->and($orden->color)->toBe('Negro, Rojo')                      // resumen de los rollos, no "el primero"
        ->and($orden->detalles()->count())->toBe(2);

    foreach ($rollos as $rollo) {
        expect($rollo->fresh()->estado)->toBe(EstadoRollo::ReservadoEnCorte->value)
            ->and($rollo->fresh()->estaVinculadoAOrdenCorte())->toBeTrue();   // PRE-F4: la compra ya no puede tocarlos
    }
});

test('CASOS 4, 5, 6 - otra tela, sin tipo_tela_id o sin color_option_id: bloquea y no reserva nada', function () {
    $c = $this->c;
    $otraTela = $c['fx']->opcion($c['fx']->tipoTela, 'Otra tela F4');
    $ok = f4_rollo($c['fx'], $c['negro']);
    $casos = [
        'otra tela' => f4_rollo($c['fx'], $c['negro'], ['tipo_tela_id' => $otraTela->id]),
        'sin tipo_tela_id' => f4_rollo($c['fx'], $c['negro'], ['tipo_tela_id' => null]),
        'sin color_option_id' => f4_rollo($c['fx'], $c['negro'], ['color_option_id' => null]),
    ];

    foreach ($casos as $nombre => $malo) {
        $antes = f4_huella([$ok, $malo]);

        expect(fn () => f4_crear([$ok, $malo], [f4_sel($c['hombre'], $c['negro'], $c['tallas']['M'])]))
            ->toThrow(ValidationException::class);

        expect(f4_huella([$ok, $malo]))->toBe($antes, $nombre);
    }
});

test('CASOS 9, 10, 11, 18 - Hombre S/M/L/XL, Dama + Nino y las tres lineas juntas', function () {
    $c = $this->c;
    $t = $c['tallas'];

    $rollos = fn () => [f4_rollo($c['fx'], $c['negro']), f4_rollo($c['fx'], $c['rojo']), f4_rollo($c['fx'], $c['verde'])];

    $hombre = f4_crear($rollos(), collect(['S', 'M', 'L', 'XL'])->map(fn ($x) => f4_sel($c['hombre'], $c['negro'], $t[$x]))->all());

    expect($hombre->detalles()->pluck('talla')->all())->toBe(['S', 'M', 'L', 'XL']);

    $damaNino = f4_crear($rollos(), array_merge(
        collect(['M', 'L', 'XL'])->map(fn ($x) => f4_sel($c['dama'], $c['verde'], $t[$x]))->all(),
        collect($c['grupos'])->map(fn ($g) => f4_sel($c['nino'], $c['rojo'], $g))->values()->all(),
    ));

    expect($damaNino->detalles()->count())->toBe(7)
        ->and($damaNino->detalles()->where('genero_linea', 'Camisetas Niño DURZ')->pluck('talla')->all())->toBe(['2-4', '6-8', '10-12', '14-16']);

    $todas = f4_crear($rollos(), array_merge(
        collect(['S', 'M', 'L', 'XL'])->map(fn ($x) => f4_sel($c['hombre'], $c['negro'], $t[$x]))->all(),
        collect(['M', 'L', 'XL'])->map(fn ($x) => f4_sel($c['dama'], $c['negro'], $t[$x]))->all(),
        collect($c['grupos'])->map(fn ($g) => f4_sel($c['nino'], $c['rojo'], $g))->values()->all(),
    ));

    expect($todas->detalles()->count())->toBe(11)
        ->and($todas->genero_linea)->toBe('Camiseta Hombre DURZ, Camiseta Mujer DURZ, Camisetas Niño DURZ')
        // Cada detalle apunta a un simple distinto y valido.
        ->and($todas->detalles()->pluck('product_id')->unique()->count())->toBe(11)
        ->and(Product::whereIn('id', $todas->detalles()->pluck('product_id'))->where('type', '!=', 'simple')->count())->toBe(0);
});

test('CASO 16 - un product_id manipulado desde el navegador se ignora (HTTP)', function () {
    $c = $this->c;
    $rollo = f4_rollo($c['fx'], $c['negro']);
    $ajeno = Product::factory()->simple()->create();
    $cortador = f4_admin('CORTADOR');

    $payload = f4_payload([$rollo], [array_merge(f4_sel($c['hombre'], $c['negro'], $c['tallas']['M']), ['product_id' => $ajeno->id])], [
        'cortador_id' => $cortador->id,
        'detalles' => [['product_id' => $ajeno->id, 'cantidad_proyectada' => 99, 'talla' => 'XXL']],
    ]);

    $this->actingAs(f4_admin('Bodega QA HTTP F4'), 'admin')
        ->post(route('admin.corte_confeccion.ordenes_corte.store'), $payload)
        ->assertSessionHasNoErrors();

    $ordenId = DB::table('bylopez_cc_orden_corte_rollos')
        ->where('rollo_id', $rollo->id)
        ->value('orden_corte_id');

    $detalle = OrdenCorteDetalle::where('orden_corte_id', $ordenId)->sole();

    expect($detalle->product_id)->toBe($c['hombreNegro']['hijos']['M']->id)
        ->and($detalle->product_id)->not->toBe($ajeno->id)
        ->and($detalle->cantidad_proyectada)->toBe(0)
        ->and($detalle->talla)->toBe('M');
});

test('CASO 17 - un color que no pertenece a los rollos bloquea (aunque exista en el catalogo)', function () {
    $c = $this->c;
    $rollo = f4_rollo($c['fx'], $c['negro']);
    $antes = f4_huella([$rollo]);

    // Rojo existe para Hombre en el catalogo, pero ningun rollo es Rojo.
    expect(fn () => f4_crear([$rollo], [f4_sel($c['hombre'], $c['rojo'], $c['tallas']['M'])]))
        ->toThrow(ValidationException::class, 'no pertenece a los rollos seleccionados');

    expect(f4_huella([$rollo]))->toBe($antes);
});

test('CASO 21 y seleccion vacia - selecciones duplicadas o ausentes bloquean', function () {
    $c = $this->c;
    $rollo = f4_rollo($c['fx'], $c['negro']);
    $m = f4_sel($c['hombre'], $c['negro'], $c['tallas']['M']);

    expect(fn () => f4_crear([$rollo], [$m, $m]))->toThrow(ValidationException::class, 'repetida')
        ->and(fn () => f4_crear([$rollo], []))->toThrow(ValidationException::class, 'al menos una variante');

    $ordenesDelRollo = OrdenCorte::query()
        ->where('rollo_id', $rollo->id)
        ->orWhereIn('id', DB::table('bylopez_cc_orden_corte_rollos')->where('rollo_id', $rollo->id)->select('orden_corte_id'));

    expect($ordenesDelRollo->count())->toBe(0)
        ->and(DB::table('bylopez_cc_orden_corte_rollos')->where('rollo_id', $rollo->id)->count())->toBe(0)
        ->and($rollo->fresh()->estado)->toBe(EstadoRollo::Disponible->value);
});

/*
| Errores del resolvedor: rollback total
*/

test('CASOS 18-20, 29, 41 - color ambiguo, talla inexistente y variante duplicada revierten TODO', function () {
    $c = $this->c;
    $fx = $c['fx'];
    $t = $c['tallas'];

    // Color ambiguo: dos configurables de Dama en Negro.
    $fx->configurableConEje($c['dama'], $c['negro'], array_intersect_key($t, array_flip(['M'])), ['sku' => 'F4-D-NEGRO-BIS']);
    // Variante duplicada: Hombre Rojo tiene 2 simples talla L.
    $fx->hijo($c['hombreRojo']['configurable'], $c['rojo'], $t['L'], ['sku' => 'F4-H-ROJO-L-DUP']);

    $negro = f4_rollo($fx, $c['negro']);
    $rojo = f4_rollo($fx, $c['rojo']);
    $bueno = f4_sel($c['hombre'], $c['negro'], $t['M']);

    $casos = [
        'color_ambiguo' => [f4_sel($c['dama'], $c['negro'], $t['M']), 'Camiseta Mujer DURZ'],
        'talla_no_disponible' => [f4_sel($c['hombre'], $c['negro'], $t['XXL']), 'talla'],
        'variante_duplicada' => [f4_sel($c['hombre'], $c['rojo'], $t['L']), 'mas de una variante'],
    ];

    foreach ($casos as $nombre => [$malo, $mensaje]) {
        $antes = f4_huella([$negro, $rojo]);

        // La fila buena va ANTES: si algo se creara "a medias", quedaria rastro.
        expect(fn () => f4_crear([$negro, $rojo], [$bueno, $malo]), $nombre)->toThrow(ValidationException::class, $mensaje);

        expect(f4_huella([$negro, $rojo]))->toBe($antes, "quedo algo creado en: $nombre");
    }

    // Los errores nombran linea/color/talla y los SKU en conflicto, nunca IDs.
    try {
        f4_crear([$negro, $rojo], [f4_sel($c['dama'], $c['negro'], $t['M'])]);
    } catch (ValidationException $e) {
        expect($e->errors()['seleccion'][0])->toContain('F4-D-NEGRO')->toContain('F4-D-NEGRO-BIS')->toContain('Negro');
    }
});

test('CASOS 22, 23 - configurable o simple inactivo: se crea y la resolucion trae la advertencia', function () {
    $c = $this->c;
    $fx = $c['fx'];
    $inactivoC = $fx->configurableConEje($c['hombre'], $fx->opcion($fx->color, 'Azul Inactivo'), ['M' => $c['tallas']['M']], ['sku' => 'F4-INACT-C', 'activo' => false]);
    $azul = AttributeOption::find($inactivoC['hijos']['M']->getAttribute('id') ? DB::table('product_attribute_values')->where('product_id', $inactivoC['hijos']['M']->id)->where('attribute_id', $fx->color->id)->value('integer_value') : 0);
    DB::table('product_attribute_values')->where('product_id', $c['hombreNegro']['hijos']['S']->id)->where('attribute_id', $fx->status->id)->update(['boolean_value' => 0]);

    $rollos = [f4_rollo($fx, $c['negro']), f4_rollo($fx, $azul)];
    $seleccion = [f4_sel($c['hombre'], $c['negro'], $c['tallas']['S']), f4_sel($c['hombre'], $azul, $c['tallas']['M'])];

    $preview = app(SeleccionOrdenCorteService::class)->resolver(collect($rollos), $seleccion);

    expect($preview['errores'])->toBe([])
        ->and($preview['filas'])->toHaveCount(2)
        ->and(collect($preview['filas'])->pluck('advertencias')->flatten()->unique()->count())->toBe(2);   // simple y configurable

    expect(f4_crear($rollos, $seleccion)->detalles()->count())->toBe(2);
});

/*
| Matriz de opciones
*/

test('CASOS 24, 25, 30 - la matriz solo ofrece tallas reales (sin XXL), el eje de Nino es grupo de edad y el label renombrado no afecta', function () {
    $c = $this->c;
    $rollos = collect([f4_rollo($c['fx'], $c['negro']), f4_rollo($c['fx'], $c['rojo'])]);

    DB::table('attribute_options')->where('id', $c['negro']->id)->update(['admin_name' => 'Negro Azabache']);

    $matriz = app(SeleccionOrdenCorteService::class)->matriz($rollos);
    $lineas = collect($matriz['lineas'])->keyBy('nombre');
    $celda = fn ($linea, $color) => collect($lineas[$linea]['colores'])->firstWhere('color_option_id', $color->id);

    expect(array_column($celda('Camiseta Hombre DURZ', $c['rojo'])['opciones'], 'label'))->toBe(['S', 'M', 'L', 'XL'])   // sin XXL
        ->and(array_column($celda('Camiseta Hombre DURZ', $c['negro'])['opciones'], 'label'))->toBe(['S', 'M', 'L', 'XL'])
        ->and($lineas['Camisetas Niño DURZ']['eje_etiqueta'])->toBe($c['fx']->atributo('grupo_de_edad_ninos', 'x')->admin_name)
        ->and(array_column($celda('Camisetas Niño DURZ', $c['rojo'])['opciones'], 'label'))->toBe(['2-4', '6-8', '10-12', '14-16'])
        // Nino no tiene Negro: se informa, no se inventa la combinacion.
        ->and($celda('Camisetas Niño DURZ', $c['negro'])['error'])->toBe('sin_producto_para_color')
        ->and($celda('Camisetas Niño DURZ', $c['negro'])['opciones'])->toBe([])
        ->and(array_column($matriz['colores'], 'label'))->toEqualCanonicalizing(['Negro', 'Rojo']);   // colores = solo los de los rollos (snapshot)

    // El color renombrado en Bagisto sigue resolviendo (por ID).
    $orden = f4_crear($rollos->all(), [f4_sel($c['hombre'], $c['negro'], $c['tallas']['M'])]);

    expect($orden->detalles()->sole()->product_id)->toBe($c['hombreNegro']['hijos']['M']->id)
        ->and($orden->detalles()->sole()->color)->toBe('Negro Azabache');   // snapshot con la etiqueta vigente al crear
});

test('CASO 26 y rendimiento - la matriz y la resolucion cargan el catalogo UNA vez, sin importar cuantas celdas', function () {
    $c = $this->c;
    $servicio = app(SeleccionOrdenCorteService::class);
    $rollos = collect([f4_rollo($c['fx'], $c['negro']), f4_rollo($c['fx'], $c['rojo']), f4_rollo($c['fx'], $c['verde'])]);
    $t = $c['tallas'];

    $pocas = [f4_sel($c['hombre'], $c['negro'], $t['S'])];
    $muchas = collect(['S', 'M', 'L', 'XL'])->flatMap(fn ($x) => [f4_sel($c['hombre'], $c['negro'], $t[$x]), f4_sel($c['hombre'], $c['rojo'], $t[$x])])
        ->merge(collect(['M', 'L', 'XL'])->flatMap(fn ($x) => [f4_sel($c['dama'], $c['negro'], $t[$x]), f4_sel($c['dama'], $c['verde'], $t[$x])]))
        ->merge(collect($c['grupos'])->map(fn ($g) => f4_sel($c['nino'], $c['rojo'], $g)))
        ->values()->all();

    $servicio->resolver($rollos, $pocas);   // calentamiento

    DB::enableQueryLog();
    DB::flushQueryLog();
    $servicio->resolver($rollos, $pocas);
    $queriesPocas = count(DB::getQueryLog());
    DB::flushQueryLog();
    $resuelta = $servicio->resolver($rollos, $muchas);
    $queriesMuchas = count(DB::getQueryLog());
    DB::flushQueryLog();
    $servicio->matriz($rollos);
    $queriesMatriz = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($resuelta['errores'])->toBe([])
        ->and($resuelta['filas'])->toHaveCount(count($muchas))
        ->and($queriesMuchas)->toBe($queriesPocas)
        ->and($queriesMuchas)->toBeLessThanOrEqual(14)
        ->and($queriesMatriz)->toBeLessThanOrEqual(24);   // 2 cargas de catalogo (lineas + opciones) + rollos/etiquetas
});

/*
| Reserva, transaccion y concurrencia
*/

test('CASO 42 - dos solicitudes no reservan el mismo rollo (bloqueo de filas y segunda solicitud rechazada)', function () {
    $c = $this->c;
    $rollo = f4_rollo($c['fx'], $c['negro']);
    $seleccion = [f4_sel($c['hombre'], $c['negro'], $c['tallas']['M'])];

    DB::enableQueryLog();
    DB::flushQueryLog();
    $primera = f4_crear([$rollo], $seleccion);
    $consultas = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    // El rollo se lee con FOR UPDATE dentro de la transaccion (serializa solicitudes concurrentes).
    expect($consultas->contains(fn ($q) => str_contains($q, 'bylopez_cc_rollos') && str_contains($q, 'for update')))->toBeTrue();

    expect(fn () => f4_crear([$rollo], $seleccion))->toThrow(ValidationException::class);

    $ordenesDelRollo = DB::table('bylopez_cc_orden_corte_rollos')->where('rollo_id', $rollo->id)->pluck('orden_corte_id')->unique();

    expect(OrdenCorte::query()->whereIn('id', $ordenesDelRollo)->count())->toBe(1)
        ->and(DB::table('bylopez_cc_orden_corte_rollos')->where('rollo_id', $rollo->id)->count())->toBe(1)
        ->and($primera->rollosCorte()->count())->toBe(1);
});

/*
| Vistas
*/

test('CASOS 32, 33 - show de una orden V2: tela, rollos, linea/color/talla/SKU y sin cantidad proyectada; el cortador ve filas exactas', function () {
    $c = $this->c;
    $rollos = [f4_rollo($c['fx'], $c['negro']), f4_rollo($c['fx'], $c['rojo'])];
    $servicio = app(OrdenCorteService::class);
    $orden = f4_crear($rollos, [f4_sel($c['hombre'], $c['negro'], $c['tallas']['S']), f4_sel($c['dama'], $c['negro'], $c['tallas']['M']), f4_sel($c['nino'], $c['rojo'], $c['grupos']['6-8'])]);

    $orden = $servicio->entregar($orden);
    $orden = $servicio->cambiarEstado($orden, EstadoOrdenCorte::EntregadoACortador->value, EstadoOrdenCorte::EnCorte->value);

    $html = $this->actingAs(f4_admin('Admin QA Show F4'), 'admin')
        ->get(route('admin.corte_confeccion.ordenes_corte.show', $orden->id))
        ->assertOk()
        ->getContent();

    expect($html)->toContain($c['fx']->tela->admin_name)
        ->toContain('Negro, Rojo')
        ->toContain('Camiseta Hombre DURZ')->toContain('Camisetas Niño DURZ')
        ->toContain('F4-H-NEGRO-S')->toContain('F4-D-NEGRO-M')->toContain('F4-N-ROJO-6-8')
        ->toContain('6-8')
        ->not->toContain('Cantidad total solicitada')
        ->not->toContain('agregar-producto-orden-form')   // no se agregan productos a mano a una orden V2 (los adicionales son F5)
        ->not->toContain('>Solicitado<');
});

test('CASO 34 - la recepcion sigue por orden_corte_detalle_id y muestra linea, color, talla y SKU', function () {
    $c = $this->c;
    $rollos = [f4_rollo($c['fx'], $c['negro']), f4_rollo($c['fx'], $c['rojo'])];
    $servicio = app(OrdenCorteService::class);
    $orden = f4_crear($rollos, [f4_sel($c['hombre'], $c['negro'], $c['tallas']['S']), f4_sel($c['hombre'], $c['rojo'], $c['tallas']['M'])]);
    $orden = $servicio->cambiarEstado($servicio->entregar($orden), EstadoOrdenCorte::EntregadoACortador->value, EstadoOrdenCorte::EnCorte->value);

    Auth::guard('admin')->setUser(f4_admin('CORTADOR'));
    $detalles = $orden->detalles()->orderBy('id')->get();
    $servicio->reportarResultado($orden, ['resultados' => $detalles->map(fn ($d, $i) => ['detalle_id' => $d->id, 'cantidad_real_cortada' => 10 + $i])->all()]);

    Auth::guard('admin')->setUser(f4_admin('Bodega QA Recepcion F4'));
    $recepcion = app(RecepcionCorteService::class)->create($orden->fresh(), [
        'fecha_recepcion' => now()->toDateString(), 'merma_total' => 0, 'recibido_por' => 'Bodega QA', 'observacion' => null,
        // Orden inverso: la identidad es el ID del detalle, no la posicion.
        'detalles' => $detalles->reverse()->values()->map(fn ($d) => ['orden_corte_detalle_id' => $d->id, 'cantidad_recibida' => (int) $d->fresh()->cantidad_real_cortada, 'cantidad_defectuosa' => 0])->all(),
    ]);

    foreach ($detalles as $detalle) {
        $fila = $recepcion->detalles->firstWhere('orden_corte_detalle_id', $detalle->id);

        expect($fila->product_id)->toBe($detalle->product_id)
            ->and($fila->producto_sku)->toBe($detalle->producto_sku)
            ->and($fila->talla)->toBe($detalle->talla);
    }

    $html = $this->actingAs(f4_admin('Admin QA Recepcion F4'), 'admin')
        ->get(route('admin.corte_confeccion.ordenes_corte.show', $orden->id))->assertOk()->getContent();

    expect($html)->toContain('F4-H-NEGRO-S')->toContain('F4-H-ROJO-M')->toContain('Negro')->toContain('Rojo');
});

test('pantalla de creacion y endpoints - rollos primero, matriz JSON, previsualizacion y ACL', function () {
    $c = $this->c;
    $negro = f4_rollo($c['fx'], $c['negro']);
    $rojo = f4_rollo($c['fx'], $c['rojo']);
    $admin = f4_admin('Bodega QA Pantalla F4');

    $html = $this->actingAs($admin, 'admin')->get(route('admin.corte_confeccion.ordenes_corte.create'))->assertOk()->getContent();

    expect($html)->toContain('v-orden-corte-form')->toContain($negro->codigo)
        ->not->toContain('ordenes_corte.productos')->not->toContain('cantidad_proyectada');

    $opciones = $this->actingAs($admin, 'admin')
        ->getJson(route('admin.corte_confeccion.ordenes_corte.opciones', ['rollo_ids' => [$negro->id, $rojo->id]]))
        ->assertOk()->json('data');

    expect(array_column($opciones['colores'], 'label'))->toEqualCanonicalizing(['Negro', 'Rojo'])
        ->and(array_column($opciones['lineas'], 'nombre'))->toContain('Camiseta Hombre DURZ');

    $seleccion = [f4_sel($c['hombre'], $c['negro'], $c['tallas']['M'])];

    $preview = $this->actingAs($admin, 'admin')
        ->postJson(route('admin.corte_confeccion.ordenes_corte.previsualizar'), ['rollo_ids' => [$negro->id], 'seleccion' => $seleccion])
        ->assertOk()->json('data');

    expect($preview['errores'])->toBe([])->and($preview['filas'][0]['sku'])->toBe('F4-H-NEGRO-M');

    // Rollos de otra tela o estructura invalida: 422 claro, nunca un 500.
    $this->actingAs($admin, 'admin')->getJson(route('admin.corte_confeccion.ordenes_corte.opciones'))->assertStatus(422);
    $this->actingAs($admin, 'admin')->post(route('admin.corte_confeccion.ordenes_corte.store'), ['rollo_ids' => [$negro->id], 'cortador_id' => 1])->assertSessionHasErrors('seleccion');

    // Sin permiso de crear: no entra a los endpoints.
    $sinPermiso = Role::factory()->create(['name' => 'BODEGA', 'permission_type' => 'custom', 'permissions' => ['corte_confeccion.ordenes_corte']]);
    $this->actingAs(Admin::factory()->create(['role_id' => $sinPermiso->id]), 'admin')
        ->getJson(route('admin.corte_confeccion.ordenes_corte.opciones', ['rollo_ids' => [$negro->id]]))
        ->assertStatus(401);
});

test('CASO 40 - una orden historica (contrato legacy con cantidad proyectada) sigue visible con su solicitud', function () {
    $c = $this->c;
    $rollo = f4_rollo($c['fx'], $c['negro']);
    $producto = Product::factory()->simple()->create();

    $orden = app(OrdenCorteService::class)->create([
        'rollo_ids' => [$rollo->id], 'cortador_id' => f4_admin('CORTADOR')->id, 'observacion' => null,
        'detalles' => [['product_id' => $producto->id, 'genero_linea' => 'Hombre', 'talla' => 'M', 'cantidad_proyectada' => 30]],
    ]);

    expect($orden->detalles()->sole()->color)->toBeNull();

    $this->actingAs(f4_admin('Admin QA Historica F4'), 'admin')
        ->get(route('admin.corte_confeccion.ordenes_corte.show', $orden->id))
        ->assertOk()
        ->assertSee('Cantidad total solicitada')
        ->assertSee('Solicitado')
        ->assertSee('agregar-producto-orden-form', false);
});

/*
| Interaccion con F3, PRE-F4 y PRE-F4.1
*/

test('CASOS 35, 36 - multi-color y multi-gramaje no rompen F3: una sola merma, ningun rollo recupera peso', function () {
    $c = $this->c;
    $rollos = [
        f4_rollo($c['fx'], $c['negro'], ['gramaje' => '170', 'peso_inicial' => 20, 'peso_disponible' => 20]),
        f4_rollo($c['fx'], $c['rojo'], ['gramaje' => '190', 'peso_inicial' => 25, 'peso_disponible' => 25]),
        f4_rollo($c['fx'], $c['verde'], ['gramaje' => '175', 'peso_inicial' => 22, 'peso_disponible' => 22]),
    ];
    $servicio = app(OrdenCorteService::class);
    $orden = f4_crear($rollos, [f4_sel($c['hombre'], $c['negro'], $c['tallas']['M']), f4_sel($c['dama'], $c['verde'], $c['tallas']['L'])]);
    $orden = $servicio->cambiarEstado($servicio->entregar($orden), EstadoOrdenCorte::EntregadoACortador->value, EstadoOrdenCorte::EnCorte->value);

    Auth::guard('admin')->setUser(f4_admin('CORTADOR'));
    $servicio->reportarResultado($orden, ['resultados' => $orden->detalles()->get()->map(fn ($d) => ['detalle_id' => $d->id, 'cantidad_real_cortada' => 12])->all()]);

    Auth::guard('admin')->setUser(f4_admin('Bodega QA F3 F4'));
    app(RecepcionCorteService::class)->create($orden->fresh(), [
        'fecha_recepcion' => now()->toDateString(), 'merma_total' => 4.8, 'recibido_por' => 'Bodega QA', 'observacion' => null,
        'detalles' => $orden->detalles()->get()->map(fn ($d) => ['orden_corte_detalle_id' => $d->id, 'cantidad_recibida' => 12, 'cantidad_defectuosa' => 0])->all(),
    ]);
    $servicio->cerrar($orden->fresh());

    $orden = $orden->fresh();

    expect($orden->merma_unica)->toBeTrue()
        ->and((float) $orden->merma_total)->toBe(4.8)
        ->and((float) $orden->peso_sobrante_total)->toBe(0.0)
        ->and((float) $orden->peso_consumido_total)->toBe(62.2)
        ->and((float) $orden->rollosCorte()->sum('peso_sobrante'))->toBe(0.0);

    foreach ($rollos as $rollo) {
        expect((float) $rollo->fresh()->peso_disponible)->toBe(0.0)
            ->and($rollo->fresh()->estado)->toBe(EstadoRollo::Agotado->value)
            ->and($rollo->fresh()->only(['color', 'gramaje', 'tipo_tela_id']))->toBe($rollo->only(['color', 'gramaje', 'tipo_tela_id']));
    }
});

test('CASOS 37-39 - PRE-F4 y PRE-F4.1 se conservan sobre ordenes V2', function () {
    $c = $this->c;
    $rollos = [f4_rollo($c['fx'], $c['negro']), f4_rollo($c['fx'], $c['rojo'])];
    $libre = f4_rollo($c['fx'], $c['verde']);
    $servicio = app(OrdenCorteService::class);
    $orden = f4_crear($rollos, [f4_sel($c['hombre'], $c['negro'], $c['tallas']['M'])]);

    // PRE-F4: los rollos reservados quedan vinculados (Compra de Tela no puede tocarlos).
    expect($rollos[1]->fresh()->vinculoConOrdenCorte())->toBe(Rollo::VINCULO_RESERVADA);

    // Antes de en_corte se agrega y se retira (compatibilidad: solo tipo de tela).
    $servicio->agregarRollo($orden, $libre->id);
    expect($orden->rollosCorte()->count())->toBe(3);
    $servicio->retirarRollo($orden, $libre->id);
    expect($orden->rollosCorte()->count())->toBe(2);

    $orden = $servicio->cambiarEstado($servicio->entregar($orden), EstadoOrdenCorte::EntregadoACortador->value, EstadoOrdenCorte::EnCorte->value);

    // PRE-F4.1: desde en_corte no se agrega, retira ni anula.
    expect(fn () => $servicio->agregarRollo($orden, $libre->id))->toThrow(ValidationException::class)
        ->and(fn () => $servicio->retirarRollo($orden, $rollos[1]->id))->toThrow(ValidationException::class)
        ->and(fn () => $servicio->cancel($orden))->toThrow(ValidationException::class, 'despues de iniciar el corte');

    expect($orden->fresh()->estado)->toBe(EstadoOrdenCorte::EnCorte->value)
        ->and($orden->rollosCorte()->count())->toBe(2)
        ->and($libre->fresh()->estado)->toBe(EstadoRollo::Disponible->value);
});

test('agregar rollo a una orden respeta la nueva compatibilidad: otro color y gramaje si, otra tela no', function () {
    $c = $this->c;
    $base = f4_rollo($c['fx'], $c['negro']);
    $servicio = app(OrdenCorteService::class);
    $orden = f4_crear([$base], [f4_sel($c['hombre'], $c['negro'], $c['tallas']['M'])]);

    $otraTela = $c['fx']->opcion($c['fx']->tipoTela, 'Otra tela agregar F4');
    $ajeno = f4_rollo($c['fx'], $c['negro'], ['tipo_tela_id' => $otraTela->id]);
    $otroColor = f4_rollo($c['fx'], $c['rojo'], ['gramaje' => '200']);

    expect(fn () => $servicio->agregarRollo($orden, $ajeno->id))->toThrow(ValidationException::class);

    $servicio->agregarRollo($orden, $otroColor->id);

    expect($orden->rollosCorte()->count())->toBe(2);
});
