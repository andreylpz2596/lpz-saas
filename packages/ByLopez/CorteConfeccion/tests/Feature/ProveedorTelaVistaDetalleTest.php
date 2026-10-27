<?php

use ByLopez\CorteConfeccion\DataGrids\CompraTelaDataGrid;
use ByLopez\CorteConfeccion\DataGrids\ProveedorTelaReferenciaDataGrid;
use ByLopez\CorteConfeccion\Models\ProveedorTela;
use ByLopez\CorteConfeccion\Models\Rollo;
use ByLopez\CorteConfeccion\Services\CompraTelaService;
use ByLopez\CorteConfeccion\Services\ProveedorTelaService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Models\AttributeOption;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

/*
|--------------------------------------------------------------------------
| Vista detalle (show) de Proveedor de tela. Helpers con prefijo ptv_ para no
| chocar con las funciones globales de otros archivos de test.
|--------------------------------------------------------------------------
|
| Distincion que gobierna estas pruebas: el proveedor OFRECE referencias
| comerciales (catalogo); una compra RECIBE rollos fisicos. Varias pruebas
| verifican explicitamente que crear/editar una referencia jamas toca
| bylopez_cc_rollos.
*/

function ptv_crearAdmin(string $nombreRol = 'Admin PTV', string $permissionType = 'all', array $permisos = []): Admin
{
    $role = Role::factory()->create(['name' => $nombreRol.' '.uniqid(), 'permission_type' => $permissionType, 'permissions' => $permisos]);

    return Admin::factory()->create(['role_id' => $role->id]);
}

function ptv_tipoTelaOption(?string $nombre = null): AttributeOption
{
    $atributo = Attribute::firstWhere('code', 'tipo_tela')
        ?? Attribute::factory()->create(['code' => 'tipo_tela', 'type' => 'select', 'admin_name' => 'Tipo de tela']);

    return AttributeOption::factory()->for($atributo, 'attribute')->create(['admin_name' => $nombre ?? 'Tela PTV '.uniqid()]);
}

function ptv_colorOption(string $nombre = 'Blanco'): AttributeOption
{
    $atributo = Attribute::firstWhere('code', 'color')
        ?? Attribute::factory()->create(['code' => 'color', 'type' => 'select', 'admin_name' => 'Color']);

    return AttributeOption::factory()->for($atributo, 'attribute')->create(['admin_name' => $nombre]);
}

function ptv_crearProveedor(array $overrides = []): ProveedorTela
{
    $option = $overrides['tipo_tela_option'] ?? ptv_tipoTelaOption();
    $color = $overrides['color_option'] ?? ptv_colorOption('Blanco');
    unset($overrides['tipo_tela_option']);
    unset($overrides['color_option']);

    return app(ProveedorTelaService::class)->create(array_merge([
        'nombre' => 'Proveedor PTV '.uniqid(),
        'nit' => (string) random_int(900000000, 999999999),
        'telefono' => '3000000000',
        'contacto' => 'Responsable PTV',
        'direccion' => 'Calle PTV',
        'tipo_tela_ids' => [$option->id],
        'referencias' => [
            ['tipo_tela_id' => $option->id, 'color_option_id' => $color->id, 'color' => 'Blanco', 'referencia' => 'PTV-'.uniqid(), 'gramaje' => '180', 'valor_kilo_referencia' => 20000, 'estado' => 'activo'],
        ],
        'estado' => 'activo',
        'observacion' => null,
    ], $overrides));
}

function ptv_crearCompra(ProveedorTela $proveedor, int $cantidadRollos = 2): void
{
    $referencia = $proveedor->referenciasTela()->first();

    $rollos = [];
    for ($i = 0; $i < $cantidadRollos; $i++) {
        $rollos[] = [
            'codigo' => 'R-PTV-'.uniqid(),
            'tipo_tela_id' => $referencia->tipo_tela_id,
            'referencia_tela_id' => $referencia->id,
            'color' => 'Blanco',
            'peso_inicial' => 10,
            'valor_kilo' => 20000,
        ];
    }

    app(CompraTelaService::class)->create([
        'codigo' => 'CT-PTV-'.uniqid(),
        'proveedor_id' => $proveedor->id,
        'numero_factura' => null,
        'fecha_compra' => now()->toDateString(),
        'descuento' => 0,
        'impuesto' => 0,
        'observacion' => null,
        'rollos' => $rollos,
    ]);
}

test('la vista show existe y muestra los datos del proveedor', function () {
    $admin = ptv_crearAdmin();
    Auth::guard('admin')->setUser($admin);

    $proveedor = ptv_crearProveedor(['nombre' => 'Oasis Importacion PTV']);

    $response = $this->actingAs($admin, 'admin')->get(route('admin.corte_confeccion.proveedores_tela.show', $proveedor->id));

    $response->assertOk();
    $response->assertSee('Oasis Importacion PTV', false);
    $response->assertSee($proveedor->nit, false);
    $response->assertSee('Responsable PTV', false);
    $response->assertSee('3000000000', false);
    $response->assertSee('Calle PTV', false);
});

test('el resumen superior muestra compras, total comprado, rollos y referencias activas reales', function () {
    $admin = ptv_crearAdmin();
    Auth::guard('admin')->setUser($admin);

    $proveedor = ptv_crearProveedor();
    ptv_crearCompra($proveedor, 2);
    ptv_crearCompra($proveedor, 3);

    $response = $this->actingAs($admin, 'admin')->get(route('admin.corte_confeccion.proveedores_tela.show', $proveedor->id));

    $response->assertOk();
    // 2 compras, 5 rollos en total; la referencia inicial sigue activa.
    $response->assertSee('"total_compras":2', false);
    $response->assertSee('"total_rollos_comprados":5', false);
    $response->assertSee('"referencias_activas":1', false);
});

test('el DataGrid de compras filtra por proveedor_id igual que ordenes_confeccion filtra por taller_id', function () {
    $proveedorA = ptv_crearProveedor();
    $proveedorB = ptv_crearProveedor();
    ptv_crearCompra($proveedorA, 1);
    ptv_crearCompra($proveedorB, 1);

    request()->merge(['proveedor_id' => $proveedorA->id]);

    $nombresProveedor = app(CompraTelaDataGrid::class)->prepareQueryBuilder()->pluck('proveedor_nombre')->unique();

    expect($nombresProveedor->all())->toBe([$proveedorA->nombre]);
});

test('el DataGrid de referencias solo devuelve las del proveedor solicitado y nunca toca rollos', function () {
    $proveedorA = ptv_crearProveedor();
    $proveedorB = ptv_crearProveedor();

    request()->merge(['proveedor_id' => $proveedorA->id]);

    $query = app(ProveedorTelaReferenciaDataGrid::class)->prepareQueryBuilder();

    expect($query->pluck('id')->all())->toBe([$proveedorA->referenciasTela()->first()->id])
        ->and($query->toSql())->not->toContain('bylopez_cc_rollos');
});

test('agregar una referencia nueva no crea ningun rollo y respeta los demas campos del proveedor', function () {
    $admin = ptv_crearAdmin('Admin PTV Crear Ref', 'all');
    Auth::guard('admin')->setUser($admin);

    $proveedor = ptv_crearProveedor(['nombre' => 'Proveedor Crear Ref PTV']);
    $tipoTelaId = $proveedor->tiposTela()->first()->id;
    $color = ptv_colorOption('Verde');
    $rollosAntes = Rollo::query()->count();

    $response = $this->actingAs($admin, 'admin')->put(route('admin.corte_confeccion.proveedores_tela.update', $proveedor->id), [
        'redirect_to' => 'show',
        'nombre' => $proveedor->nombre,
        'nit' => $proveedor->nit,
        'telefono' => $proveedor->telefono,
        'contacto' => $proveedor->contacto,
        'direccion' => $proveedor->direccion,
        'estado' => $proveedor->estado,
        'observacion' => $proveedor->observacion,
        'tipo_tela_ids' => [$tipoTelaId],
        'referencias' => [
            ['tipo_tela_id' => $tipoTelaId, 'color_option_id' => $color->id, 'color' => 'Texto ignorado', 'referencia' => 'NUEVA-PTV', 'gramaje' => '150', 'valor_kilo_referencia' => 18000, 'estado' => 'activo'],
        ],
    ]);

    $response->assertRedirect(route('admin.corte_confeccion.proveedores_tela.show', $proveedor->id));
    $response->assertSessionHasNoErrors();

    expect(Rollo::query()->count())->toBe($rollosAntes)
        ->and($proveedor->referenciasTela()->count())->toBe(2)
        ->and($proveedor->refresh()->nombre)->toBe('Proveedor Crear Ref PTV');
});

test('editar una referencia existente no crea ningun rollo', function () {
    $admin = ptv_crearAdmin('Admin PTV Editar Ref', 'all');
    Auth::guard('admin')->setUser($admin);

    $proveedor = ptv_crearProveedor();
    $referencia = $proveedor->referenciasTela()->first();
    $tipoTelaId = $proveedor->tiposTela()->first()->id;
    $color = ptv_colorOption('Azul');
    $rollosAntes = Rollo::query()->count();

    $response = $this->actingAs($admin, 'admin')->put(route('admin.corte_confeccion.proveedores_tela.update', $proveedor->id), [
        'redirect_to' => 'show',
        'nombre' => $proveedor->nombre,
        'nit' => $proveedor->nit,
        'telefono' => $proveedor->telefono,
        'contacto' => $proveedor->contacto,
        'direccion' => $proveedor->direccion,
        'estado' => $proveedor->estado,
        'observacion' => $proveedor->observacion,
        'tipo_tela_ids' => [$tipoTelaId],
        'referencias' => [
            ['id' => $referencia->id, 'tipo_tela_id' => $tipoTelaId, 'color_option_id' => $color->id, 'color' => 'Texto ignorado', 'referencia' => $referencia->referencia, 'gramaje' => $referencia->gramaje, 'valor_kilo_referencia' => 21000, 'estado' => 'activo'],
        ],
    ]);

    $response->assertRedirect(route('admin.corte_confeccion.proveedores_tela.show', $proveedor->id));
    $response->assertSessionHasNoErrors();

    expect(Rollo::query()->count())->toBe($rollosAntes)
        ->and($referencia->refresh()->color)->toBe('Azul');
});

test('las paginas de agregar y editar referencia renderizan con el catalogo de tipos del proveedor', function () {
    $admin = ptv_crearAdmin();
    Auth::guard('admin')->setUser($admin);

    $proveedor = ptv_crearProveedor();
    $referencia = $proveedor->referenciasTela()->first();

    $this->actingAs($admin, 'admin')
        ->get(route('admin.corte_confeccion.proveedores_tela.referencia.create', $proveedor->id))
        ->assertOk()
        ->assertSee('Agregar referencia', false);

    $this->actingAs($admin, 'admin')
        ->get(route('admin.corte_confeccion.proveedores_tela.referencia.edit', $referencia->id))
        ->assertOk()
        ->assertSee('Editar referencia', false)
        ->assertSee('value="'.$referencia->color_option_id.'"', false);
});

test('F1-B: crear referencia muestra placeholder de catalogo y no ofrece sin vincular', function () {
    $admin = ptv_crearAdmin();
    Auth::guard('admin')->setUser($admin);

    $proveedor = ptv_crearProveedor();

    $response = $this->actingAs($admin, 'admin')
        ->get(route('admin.corte_confeccion.proveedores_tela.referencia.create', $proveedor->id));

    $response->assertOk();
    $response->assertSee('Seleccionar color del catálogo', false);
    $response->assertDontSee('__sin_vincular__', false);
    $response->assertDontSee('El color no existe todavia en el catalogo', false);
    $response->assertDontSee('Nombre del color', false);
});

test('F1-B: editar referencia vinculada muestra seleccionada su opcion actual', function () {
    $admin = ptv_crearAdmin();
    Auth::guard('admin')->setUser($admin);

    $color = ptv_colorOption('Azul Profundo PTV');
    $proveedor = ptv_crearProveedor(['color_option' => $color]);
    $referencia = $proveedor->referenciasTela()->first();

    $response = $this->actingAs($admin, 'admin')
        ->get(route('admin.corte_confeccion.proveedores_tela.referencia.edit', $referencia->id));

    $response->assertOk();
    $response->assertSee('Azul Profundo PTV', false);
    $response->assertDontSee('__sin_vincular__', false);
    expect($response->getContent())->toMatch('/<option[^>]+value="'.$color->id.'"[^>]+selected/s');
});

test('F1-B: editar referencia historica sin vincular muestra aviso y snapshot historico', function () {
    $admin = ptv_crearAdmin();
    Auth::guard('admin')->setUser($admin);

    $tipoTela = ptv_tipoTelaOption();
    $proveedor = app(ProveedorTelaService::class)->create([
        'nombre' => 'Proveedor Historico PTV '.uniqid(),
        'nit' => (string) random_int(600000000, 699999999),
        'telefono' => '3000000000',
        'contacto' => null,
        'direccion' => null,
        'tipo_tela_ids' => [$tipoTela->id],
        'referencias' => [],
        'estado' => 'activo',
        'observacion' => null,
    ]);

    $referencia = $proveedor->referenciasTela()->create([
        'tipo_tela_id' => $tipoTela->id,
        'color_option_id' => null,
        'color' => 'Verde botella',
        'referencia' => 'HIST-PTV',
        'gramaje' => '180',
        'valor_kilo_referencia' => 20000,
        'estado' => 'activo',
    ]);

    $response = $this->actingAs($admin, 'admin')
        ->get(route('admin.corte_confeccion.proveedores_tela.referencia.edit', $referencia->id));

    $response->assertOk();
    $response->assertSee('Color sin vincular al catálogo', false);
    $response->assertSee('Color histórico: Verde botella', false);
    $response->assertSee('Seleccionar color del catálogo', false);
    $response->assertDontSee('__sin_vincular__', false);
});

test('F1-B: guardar referencia historica exige seleccionar color real', function () {
    $admin = ptv_crearAdmin();
    Auth::guard('admin')->setUser($admin);

    $tipoTela = ptv_tipoTelaOption();
    $proveedor = app(ProveedorTelaService::class)->create([
        'nombre' => 'Proveedor Historico Guardar PTV '.uniqid(),
        'nit' => (string) random_int(500000000, 599999999),
        'telefono' => '3000000000',
        'contacto' => null,
        'direccion' => null,
        'tipo_tela_ids' => [$tipoTela->id],
        'referencias' => [],
        'estado' => 'activo',
        'observacion' => null,
    ]);

    $referencia = $proveedor->referenciasTela()->create([
        'tipo_tela_id' => $tipoTela->id,
        'color_option_id' => null,
        'color' => 'Verde botella',
        'referencia' => 'HIST-SAVE-PTV',
        'gramaje' => '180',
        'valor_kilo_referencia' => 20000,
        'estado' => 'activo',
    ]);

    $response = $this->actingAs($admin, 'admin')->put(route('admin.corte_confeccion.proveedores_tela.update', $proveedor->id), [
        'redirect_to' => 'show',
        'nombre' => $proveedor->nombre,
        'nit' => $proveedor->nit,
        'telefono' => $proveedor->telefono,
        'contacto' => $proveedor->contacto,
        'direccion' => $proveedor->direccion,
        'estado' => $proveedor->estado,
        'observacion' => $proveedor->observacion,
        'tipo_tela_ids' => [$tipoTela->id],
        'referencias' => [
            ['id' => $referencia->id, 'tipo_tela_id' => $tipoTela->id, 'referencia' => $referencia->referencia, 'gramaje' => $referencia->gramaje, 'valor_kilo_referencia' => 21000, 'estado' => 'activo'],
        ],
    ]);

    $response->assertSessionHasErrors('referencias.0.color_option_id');
    expect($referencia->refresh()->color_option_id)->toBeNull();
});

test('la ficha del proveedor no muestra la matriz de checkboxes de tipos de tela', function () {
    $admin = ptv_crearAdmin();
    Auth::guard('admin')->setUser($admin);

    $tipo = ptv_tipoTelaOption('Piel Durazno PTV');
    $proveedor = ptv_crearProveedor(['tipo_tela_option' => $tipo]);

    $response = $this->actingAs($admin, 'admin')->get(route('admin.corte_confeccion.proveedores_tela.show', $proveedor->id));

    $response->assertOk();
    // El nombre del tipo aparece como texto compacto...
    $response->assertSee('Piel Durazno PTV', false);
    // ...pero no como la matriz real de checkboxes de Editar proveedor (marcador
    // data-tipo-checkbox). El modal rapido SI reenvia tipo_tela_ids[] oculto, sin
    // cambiarlo, y eso es intencional: no es la matriz que el usuario pidio quitar.
    $response->assertDontSee('data-tipo-checkbox', false);
});

test('la ficha del proveedor incluye el modal de edicion rapida tipo Cliente/Taller', function () {
    $admin = ptv_crearAdmin();
    Auth::guard('admin')->setUser($admin);

    $proveedor = ptv_crearProveedor(['nombre' => 'Proveedor Modal PTV']);

    $response = $this->actingAs($admin, 'admin')->get(route('admin.corte_confeccion.proveedores_tela.show', $proveedor->id));

    $response->assertOk();
    $response->assertSee('v-proveedor-edit-template', false);
    $response->assertSee('Editar proveedor', false);
    // La accion PRINCIPAL de edicion es el boton del modal (dispara openModal,
    // sin href a la pagina completa). Los tipos de tela tambien se editan por
    // modal, asi que la ficha ya no necesita ningun enlace a edit/{id}.
    $response->assertSee('@click="openModal"', false);
    $response->assertSee('Editar tipos', false);
    $response->assertSee('v-proveedor-tipos-modal-template', false);
    expect(substr_count($response->getContent(), 'href="'.route('admin.corte_confeccion.proveedores_tela.edit', $proveedor->id).'"'))->toBe(0);
});

test('el listado crea proveedor desde modal y no enlaza a la pagina create', function () {
    $admin = ptv_crearAdmin();
    Auth::guard('admin')->setUser($admin);
    ptv_tipoTelaOption('Tela Modal Crear PTV');

    $response = $this->actingAs($admin, 'admin')->get(route('admin.corte_confeccion.proveedores_tela.index'));

    $response->assertOk();
    $response->assertSee('v-proveedor-create-template', false);
    $response->assertSee('@click="$refs.proveedorCreate.openModal()"', false);
    $response->assertDontSee('href="'.route('admin.corte_confeccion.proveedores_tela.create').'"', false);
    $response->assertDontSee('Estado</x-admin::form.control-group.label>', false);
});

test('crear proveedor por ajax lo deja activo y asocia tipos de tela sin referencias iniciales', function () {
    $admin = ptv_crearAdmin();
    Auth::guard('admin')->setUser($admin);
    $tipo = ptv_tipoTelaOption('Tela Alta Modal PTV');

    $response = $this->actingAs($admin, 'admin')->postJson(route('admin.corte_confeccion.proveedores_tela.store'), [
        'nombre' => 'Proveedor Creado Modal PTV',
        'nit' => '901234567',
        'telefono' => '3001112233',
        'contacto' => 'Contacto Modal',
        'direccion' => 'Calle Modal',
        'observacion' => 'Alta por modal',
        'estado' => 'inactivo',
        'tipo_tela_ids' => [$tipo->id],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.proveedor.nombre', 'Proveedor Creado Modal PTV')
        ->assertJsonPath('data.proveedor.estado', 'activo');

    $proveedor = ProveedorTela::query()->where('nit', '901234567')->firstOrFail();

    expect($proveedor->estado)->toBe('activo')
        ->and($proveedor->tiposTela()->pluck('attribute_options.id')->all())->toBe([$tipo->id])
        ->and($proveedor->referenciasTela()->count())->toBe(0);
});

test('crear proveedor por ajax conserva errores de validacion en json', function () {
    $admin = ptv_crearAdmin();
    Auth::guard('admin')->setUser($admin);

    $response = $this->actingAs($admin, 'admin')->postJson(route('admin.corte_confeccion.proveedores_tela.store'), [
        'nombre' => '',
        'nit' => '',
        'telefono' => '',
        'tipo_tela_ids' => [],
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['nombre', 'nit', 'telefono', 'tipo_tela_ids']);
});

test('el listado de proveedores enlaza Ver y Editar a la misma ficha, no a la pagina completa', function () {
    $admin = ptv_crearAdmin();
    Auth::guard('admin')->setUser($admin);

    $proveedor = ptv_crearProveedor(['nombre' => 'Proveedor Accion Listado PTV']);

    $response = $this->actingAs($admin, 'admin')->get(route('admin.corte_confeccion.proveedores_tela.index'), ['X-Requested-With' => 'XMLHttpRequest']);

    $response->assertOk();

    $fila = collect($response->json('records'))->firstWhere('id', $proveedor->id);
    $urlsAccion = collect($fila['actions'])->pluck('url');

    expect($urlsAccion->unique()->all())->toBe([route('admin.corte_confeccion.proveedores_tela.show', $proveedor->id)]);
});

test('actualizar el proveedor por ajax devuelve json para refrescar la ficha visual', function () {
    $admin = ptv_crearAdmin();
    Auth::guard('admin')->setUser($admin);

    $proveedor = ptv_crearProveedor();
    $tipoTelaId = $proveedor->tiposTela()->first()->id;

    $response = $this->actingAs($admin, 'admin')->putJson(route('admin.corte_confeccion.proveedores_tela.update', $proveedor->id), [
        'nombre' => 'Proveedor Ajax Actualizado PTV',
        'nit' => $proveedor->nit,
        'telefono' => $proveedor->telefono,
        'contacto' => 'Nuevo responsable',
        'direccion' => $proveedor->direccion,
        'estado' => 'inactivo',
        'observacion' => null,
        'tipo_tela_ids' => [$tipoTelaId],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.proveedor.nombre', 'Proveedor Ajax Actualizado PTV')
        ->assertJsonPath('data.proveedor.estado', 'inactivo')
        ->assertJsonPath('data.proveedor.contacto', 'Nuevo responsable');

    expect($proveedor->fresh()->nombre)->toBe('Proveedor Ajax Actualizado PTV');
});

test('editar tipos por ajax permite agregar y quitar cuando no hay datos asociados', function () {
    $admin = ptv_crearAdmin();
    Auth::guard('admin')->setUser($admin);

    $tipoA = ptv_tipoTelaOption('Tela Inicial Tipos PTV');
    $tipoB = ptv_tipoTelaOption('Tela Nueva Tipos PTV');
    $proveedor = app(ProveedorTelaService::class)->create([
        'nombre' => 'Proveedor Tipos Ajax PTV',
        'nit' => (string) random_int(800000000, 899999999),
        'telefono' => '3002223344',
        'contacto' => null,
        'direccion' => null,
        'tipo_tela_ids' => [$tipoA->id],
        'referencias' => [],
        'estado' => 'activo',
        'observacion' => null,
    ]);

    $response = $this->actingAs($admin, 'admin')->putJson(route('admin.corte_confeccion.proveedores_tela.update', $proveedor->id), [
        'nombre' => $proveedor->nombre,
        'nit' => $proveedor->nit,
        'telefono' => $proveedor->telefono,
        'contacto' => $proveedor->contacto,
        'direccion' => $proveedor->direccion,
        'estado' => $proveedor->estado,
        'observacion' => $proveedor->observacion,
        'tipo_tela_ids' => [$tipoB->id],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.proveedor.tipos_tela.0', 'Tela Nueva Tipos PTV');

    expect($proveedor->refresh()->tiposTela()->pluck('attribute_options.id')->all())->toBe([$tipoB->id]);
});

test('proveedor de tela aun no tiene relacion persistente con usuario admin', function () {
    expect(Schema::hasColumn('bylopez_cc_proveedores_tela', 'usuario_id'))->toBeFalse()
        ->and(Schema::hasColumn('bylopez_cc_proveedores_tela', 'admin_id'))->toBeFalse()
        ->and(Schema::hasColumn('bylopez_cc_proveedores_tela', 'created_by'))->toBeFalse();
});

test('el formulario clasico de edicion completa sigue redireccionando a edit, sin cambios', function () {
    $admin = ptv_crearAdmin();
    Auth::guard('admin')->setUser($admin);

    $proveedor = ptv_crearProveedor();
    $tipoTelaId = $proveedor->tiposTela()->first()->id;

    $response = $this->actingAs($admin, 'admin')->put(route('admin.corte_confeccion.proveedores_tela.update', $proveedor->id), [
        'nombre' => $proveedor->nombre,
        'nit' => $proveedor->nit,
        'telefono' => $proveedor->telefono,
        'contacto' => $proveedor->contacto,
        'direccion' => $proveedor->direccion,
        'estado' => $proveedor->estado,
        'observacion' => $proveedor->observacion,
        'tipo_tela_ids' => [$tipoTelaId],
    ]);

    $response->assertRedirect(route('admin.corte_confeccion.proveedores_tela.edit', $proveedor->id));
});

test('sin permiso de ver proveedores, la ficha de detalle responde prohibido', function () {
    $usuarioSinPermiso = ptv_crearAdmin('Sin Permiso PTV', 'custom', ['corte_confeccion']);
    $admin = ptv_crearAdmin('Admin PTV Creador', 'all');
    Auth::guard('admin')->setUser($admin);

    $proveedor = ptv_crearProveedor();

    $response = $this->actingAs($usuarioSinPermiso, 'admin')->get(route('admin.corte_confeccion.proveedores_tela.show', $proveedor->id));

    // El middleware de ACL de Bagisto responde 401 (no 403) cuando el rol
    // autenticado no tiene la clave de permiso de la ruta.
    $response->assertUnauthorized();
});

test('el listado de proveedores enlaza el nombre a la ficha de detalle', function () {
    $admin = ptv_crearAdmin();
    Auth::guard('admin')->setUser($admin);

    $proveedor = ptv_crearProveedor(['nombre' => 'Proveedor Enlace Listado PTV']);

    $response = $this->actingAs($admin, 'admin')->get(route('admin.corte_confeccion.proveedores_tela.index'), ['X-Requested-With' => 'XMLHttpRequest']);

    $response->assertOk();

    $fila = collect($response->json('records'))->firstWhere('id', $proveedor->id);

    expect($fila['nombre'])->toContain('href="'.route('admin.corte_confeccion.proveedores_tela.show', $proveedor->id));
});
