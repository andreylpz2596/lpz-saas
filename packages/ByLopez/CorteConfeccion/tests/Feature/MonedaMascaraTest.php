<?php

use ByLopez\CorteConfeccion\Models\CompraTela;
use ByLopez\CorteConfeccion\Services\CompraTelaService;
use ByLopez\CorteConfeccion\Services\ProveedorTelaService;
use ByLopez\Logistica\Support\Moneda;
use Illuminate\Support\Facades\Blade;
use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Models\AttributeOption;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

/*
| Mascara monetaria VISUAL en CorteConfeccion: lo que el usuario ve y escribe cambia ($ 7.300); lo que se guarda no. Se prueba lo que
| entrega el servidor (vistas y GUARDADO con los valores crudos que produce la mascara, con su ida y vuelta) y se ejecutan las pruebas
| de logica/DOM del navegador (tests/js). Solo lpz_saas_testing.
*/

function mm_admin(): Admin
{
    return Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'all'])->id]);
}

function mm_opcion(string $code, string $nombre): AttributeOption
{
    $atributo = Attribute::firstWhere('code', $code)
        ?? Attribute::factory()->create(['code' => $code, 'type' => 'select', 'admin_name' => $nombre]);

    return AttributeOption::factory()->for($atributo, 'attribute')->create(['admin_name' => $nombre.' '.uniqid()]);
}

/** Proveedor con una referencia (precio 20.000/kg) y una compra de 2 rollos con descuento 100.000 e impuesto 19.000,50. */
function mm_escenario(): array
{
    $tela = mm_opcion('tipo_tela', 'Tela MM');
    $rojo = mm_opcion('color', 'Rojo MM');

    $proveedor = app(ProveedorTelaService::class)->create([
        'nombre' => 'Proveedor MM '.uniqid(), 'nit' => (string) random_int(800000000, 899999999), 'telefono' => '3000000000',
        'contacto' => 'QA', 'direccion' => 'Calle QA', 'tipo_tela_ids' => [$tela->id], 'estado' => 'activo', 'observacion' => null,
        'referencias' => [[
            'tipo_tela_id' => $tela->id, 'color_option_id' => $rojo->id, 'color' => 'ignorado', 'referencia' => 'REF-MM',
            'gramaje' => '180', 'valor_kilo_referencia' => 20000, 'estado' => 'activo',
        ]],
    ]);

    $referencia = $proveedor->referenciasTela()->firstOrFail();

    $compra = app(CompraTelaService::class)->create([
        'codigo' => 'CT-MM-'.uniqid(), 'proveedor_id' => $proveedor->id, 'numero_factura' => null, 'fecha_compra' => now()->toDateString(),
        'descuento' => 100000, 'impuesto' => 19000.5, 'observacion' => null,
        'rollos' => collect([1, 2])->map(fn ($i) => [
            'codigo' => 'R-MM-'.$i.'-'.uniqid(), 'referencia_tela_id' => $referencia->id, 'tipo_tela_id' => $tela->id, 'tipo_tela' => $tela->admin_name,
            'color' => 'x', 'referencia' => 'REF-MM', 'gramaje' => '180', 'peso_inicial' => 10, 'valor_kilo' => 20000,
        ])->all(),
    ]);

    return ['tela' => $tela, 'proveedor' => $proveedor, 'referencia' => $referencia, 'compra' => $compra, 'rollos' => $compra->rollos()->orderBy('id')->get()];
}

/** El PUT que enviaria el formulario de edicion: valores crudos de los campos (hidden / v-moneda) tal cual. */
function mm_payload(CompraTela $compra, $rollos, array $compraCambios = [], array $rolloCambios = []): array
{
    $c = $compra->fresh();

    return array_merge([
        'codigo' => $c->codigo, 'proveedor_id' => $c->proveedor_id, 'numero_factura' => $c->numero_factura, 'fecha_compra' => $c->fecha_compra->toDateString(),
        'descuento' => (string) $c->descuento, 'impuesto' => (string) $c->impuesto, 'observacion' => $c->observacion,
        'rollos' => collect($rollos)->map(function ($rollo, $i) use ($rolloCambios) {
            $r = $rollo->fresh();

            return array_merge([
                'id' => $r->id, 'codigo' => $r->codigo, 'tipo_tela_id' => $r->tipo_tela_id, 'referencia_tela_id' => $r->proveedor_tela_referencia_id,
                'tipo_tela' => $r->tipo_tela, 'color' => $r->color, 'referencia' => $r->referencia, 'gramaje' => $r->gramaje,
                'peso_inicial' => (string) $r->peso_inicial, 'valor_kilo' => (string) $r->valor_kilo, 'observacion' => $r->observacion,
            ], $rolloCambios[$i] ?? []);
        })->all(),
    ], $compraCambios);
}

function mm_json(string $html, string $atributo): array
{
    preg_match('/'.preg_quote($atributo, '/').'=\'([^\']*)\'/', $html, $m);

    return json_decode(html_entity_decode($m[1] ?? '[]', ENT_QUOTES), true) ?: [];
}

test('MM-1 el parcial compartido se incluye una sola vez, con el simbolo de la moneda (si Bagisto devuelve el codigo, «$»)', function () {
    $html = Blade::render("@include('corteconfeccion::admin.partials.moneda')\n@include('corteconfeccion::admin.partials.moneda')\n@stack('scripts')");

    // Misma regla que Logistica\Support\Moneda::prefijo(): «COP» (el codigo) se muestra «$»; un simbolo real se respeta.
    $simbolo = trim((string) core()->currencySymbol(core()->getBaseCurrencyCode()));
    $esperado = ($simbolo === '' || $simbolo === (string) core()->getBaseCurrencyCode()) ? '$' : $simbolo;

    expect(substr_count($html, 'var PREFIJO'))->toBe(1)
        ->and(substr_count($html, "app.component('v-moneda'"))->toBe(1)
        ->and($html)->toContain('var PREFIJO = "'.$esperado.' "')
        ->and(Moneda::prefijo().' ')->toBe($esperado.' ');
});

test('MM-2 formulario de compra (crear): descuento, impuesto y valor kilo estan enmascarados y el valor que se envia sigue siendo el crudo', function () {
    $html = $this->actingAs(mm_admin(), 'admin')->get(route('admin.corte_confeccion.compras_tela.create'))->assertOk()->getContent();

    // Valor kilo (fila y lote): componente, sin `name` en el campo visible (el valor viaja por los hidden de siempre).
    expect($html)->toMatch('/<v-moneda\s+numero\s+sin-prefijo\s+class="[^"]*"\s+v-model="fila\.valor_kilo"\s+:readonly="fila\.bloqueado"\s*><\/v-moneda>/s')
        ->and($html)->toMatch('/<v-moneda numero data-testid="lote-valor-kilo"[^>]*v-model="loteDraft\.valor_kilo"><\/v-moneda>/')
        ->and($html)->toContain(':name="\'rollos[\' + i + \'][valor_kilo]\'" :value="fila.valor_kilo"')
        // Descuento e impuesto: el campo visible NO lleva name; el valor crudo viaja en un hidden con el MISMO name de siempre.
        ->and($html)->toContain('<v-moneda numero sin-prefijo v-model="descuento"></v-moneda><input type="hidden" name="descuento" :value="descuento">')
        ->and($html)->toContain('<v-moneda numero sin-prefijo v-model="impuesto"></v-moneda><input type="hidden" name="impuesto" :value="impuesto">')
        // Ningun campo enmascarado puede llevar `name`: enviaria el texto formateado.
        ->and($html)->not->toMatch('/<v-moneda[^>]*\sname=/')
        ->and($html)->not->toMatch('/<input[^>]*type="number"[^>]*name="(descuento|impuesto)"/')
        // El simbolo $ ya esta junto a estos campos: la mascara no lo repite.
        ->and($html)->toContain('<span class="ce-currency">$</span><v-moneda');
});

test('MM-3 edicion de compra: ida y vuelta de descuento, impuesto y valor kilo (sin tocar, cambiar y volver a abrir)', function () {
    $e = mm_escenario();
    $admin = mm_admin();
    $abrir = fn () => $this->actingAs($admin, 'admin')->get(route('admin.corte_confeccion.compras_tela.edit', $e['compra']->id))->assertOk()->getContent();

    // 1) Al abrir: la pagina entrega los valores EXACTOS de la BD (la mascara solo los muestra como «$ 100.000»).
    $html = $abrir();

    expect($html)->toContain('descuento-inicial="100000.00"')
        ->and($html)->toContain('impuesto-inicial="19000.50"')
        ->and(collect(mm_json($html, ':rollos-iniciales'))->pluck('valor_kilo')->map(fn ($v) => (float) $v)->all())->toBe([20000.0, 20000.0])
        // Campos enmascarados en el panel y el pie de la edicion, sin `name` en el visible.
        ->and(substr_count($html, '<v-moneda numero sin-prefijo v-model="descuento"'))->toBe(2)
        ->and(substr_count($html, '<v-moneda numero sin-prefijo v-model="impuesto"'))->toBe(2)
        ->and($html)->toContain('<input type="hidden" name="descuento" :value="descuento">')
        ->and($html)->not->toMatch('/<v-moneda[^>]*\sname=/');

    // 2) Guardar SIN tocar nada: se envian los valores iniciales tal cual y la BD no cambia.
    $antes = [
        'descuento' => (string) $e['compra']->fresh()->descuento, 'impuesto' => (string) $e['compra']->fresh()->impuesto,
        'valores' => $e['rollos']->map(fn ($r) => (string) $r->fresh()->valor_kilo)->all(),
    ];

    $this->actingAs($admin, 'admin')->put(route('admin.corte_confeccion.compras_tela.update', $e['compra']->id), mm_payload($e['compra'], $e['rollos']))
        ->assertSessionHasNoErrors();

    expect((string) $e['compra']->fresh()->descuento)->toBe($antes['descuento'])
        ->and((string) $e['compra']->fresh()->impuesto)->toBe($antes['impuesto'])
        ->and($e['rollos']->map(fn ($r) => (string) $r->fresh()->valor_kilo)->all())->toBe($antes['valores']);

    // 3) Cambiar: «$ 120.000», «$ 19.000,5» y valor kilo «$ 14.500,5» → se envia 120000, 19000.5, 14500.5 (lo que producen los campos).
    $this->actingAs($admin, 'admin')->put(route('admin.corte_confeccion.compras_tela.update', $e['compra']->id), mm_payload(
        $e['compra'], $e['rollos'], ['descuento' => '120000', 'impuesto' => '19000.5'], [0 => ['valor_kilo' => '14500.5'], 1 => ['valor_kilo' => '14500']],
    ))->assertSessionHasNoErrors();

    expect((float) $e['compra']->fresh()->descuento)->toBe(120000.0)
        ->and((float) $e['compra']->fresh()->impuesto)->toBe(19000.5)
        ->and($e['rollos']->map(fn ($r) => (float) $r->fresh()->valor_kilo)->all())->toBe([14500.5, 14500.0]);

    // 4) Volver a abrir: la pagina entrega lo guardado (que la mascara mostrara de nuevo como «$ 120.000»).
    $html = $abrir();

    expect($html)->toContain('descuento-inicial="120000.00"')
        ->and($html)->toContain('impuesto-inicial="19000.50"')
        ->and(collect(mm_json($html, ':rollos-iniciales'))->pluck('valor_kilo')->map(fn ($v) => (float) $v)->all())->toBe([14500.5, 14500.0]);

    // 5) Texto con simbolo o coma sigue rechazado por el servidor. (Ojo: "120.000" SI lo acepta como 120: por eso la mascara
    //    nunca envia puntos de miles; eso lo prueban tests/js/moneda*.mjs.)
    foreach (['$ 120.000', '120,000'] as $malo) {
        $this->actingAs($admin, 'admin')->put(route('admin.corte_confeccion.compras_tela.update', $e['compra']->id), mm_payload($e['compra'], $e['rollos'], ['descuento' => $malo]))
            ->assertSessionHasErrors('descuento');
    }

    expect((float) $e['compra']->fresh()->descuento)->toBe(120000.0);
});

test('MM-4 referencia de proveedor: el precio por kilo es un campo mejorable, conserva su valor de la BD y se guarda crudo', function () {
    $e = mm_escenario();
    $admin = mm_admin();

    $html = $this->actingAs($admin, 'admin')
        ->get(route('admin.corte_confeccion.proveedores_tela.referencia.edit', $e['referencia']->id))
        ->assertOk()->getContent();

    // El input conserva su name, el valor inicial sigue siendo el de la BD (el v-field de Bagisto lo inyecta) y queda marcado para la mascara.
    preg_match('/<input[^>]*name="referencias\[0\]\[valor_kilo_referencia\]"[^>]*>/s', $html, $campo);
    preg_match('/<v-field[^>]*value="([^"]*)"[^>]*name="referencias\[0\]\[valor_kilo_referencia\]"/s', $html, $inicial);

    expect($campo[0] ?? '')->toContain('data-moneda')
        ->and($inicial[1] ?? null)->toBe('20000.00')
        ->and($html)->toContain("app.component('v-moneda'");

    // Guardar con lo que produce la mascara («$ 25.000,5» → "25000.5").
    app(ProveedorTelaService::class)->update($e['proveedor']->fresh(), [
        'nombre' => $e['proveedor']->nombre, 'nit' => $e['proveedor']->nit, 'telefono' => '3000000000', 'contacto' => 'QA', 'direccion' => 'Calle QA',
        'tipo_tela_ids' => [$e['tela']->id], 'estado' => 'activo', 'observacion' => null,
        'referencias' => [[
            'id' => $e['referencia']->id, 'tipo_tela_id' => $e['tela']->id, 'color_option_id' => $e['referencia']->color_option_id, 'color' => 'ignorado',
            'referencia' => 'REF-MM', 'gramaje' => '180', 'valor_kilo_referencia' => '25000.5', 'estado' => 'activo',
        ]],
    ]);

    expect((float) $e['referencia']->fresh()->valor_kilo_referencia)->toBe(25000.5);

    $html = $this->actingAs($admin, 'admin')
        ->get(route('admin.corte_confeccion.proveedores_tela.referencia.edit', $e['referencia']->id))->assertOk()->getContent();

    preg_match('/<v-field[^>]*value="([^"]*)"[^>]*name="referencias\[0\]\[valor_kilo_referencia\]"/s', $html, $inicial);

    expect($inicial[1] ?? null)->toBe('25000.50');
});

test('MM-5 los campos que NO se enmascararon siguen intactos (representacion especial o sin ruta)', function () {
    $vistas = base_path('packages/ByLopez/CorteConfeccion/src/Resources/views/admin/');

    // Modal de rollos (VeeValidate + v-model) y modal/filas de referencias (HTML generado por JS): sin cambios.
    expect(file_get_contents($vistas.'rollos/index.blade.php'))->not->toContain('v-moneda')->not->toContain('data-moneda')
        ->and(file_get_contents($vistas.'proveedores-tela/show.blade.php'))->not->toContain('v-moneda')->not->toContain('data-moneda')
        ->and(file_get_contents($vistas.'proveedores-tela/form.blade.php'))->not->toContain('v-moneda')->not->toContain('data-moneda');
});

test('MM-6 logica de la mascara: formato, interpretacion de lo escrito e ida y vuelta (Node)', function () {
    exec('node -v 2>&1', $version, $codigo);

    if ($codigo !== 0) {
        $this->markTestSkipped('Node no esta disponible en este entorno.');
    }

    foreach (['moneda.mjs', 'moneda_dom.mjs'] as $prueba) {
        $salida = [];

        exec(
            'node '.escapeshellarg(base_path('packages/ByLopez/CorteConfeccion/tests/js/'.$prueba)).' '
            .escapeshellarg(base_path('packages/ByLopez/CorteConfeccion/src/Resources/views/admin/partials/moneda.blade.php')).' 2>&1',
            $salida,
            $codigo,
        );

        expect(implode("\n", $salida))->toContain('JS-OK')->and($codigo)->toBe(0);
    }
});
