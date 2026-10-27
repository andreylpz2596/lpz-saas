<?php

use ByLopez\CorteConfeccion\Enums\EstadoRollo;
use ByLopez\CorteConfeccion\Http\Requests\StoreRolloRequest;
use ByLopez\CorteConfeccion\Models\OrdenCorte;
use ByLopez\CorteConfeccion\Models\ProveedorTela;
use ByLopez\CorteConfeccion\Models\ProveedorTelaReferencia;
use ByLopez\CorteConfeccion\Models\Rollo;
use ByLopez\CorteConfeccion\Services\CompraTelaService;
use ByLopez\CorteConfeccion\Services\ProveedorTelaService;
use ByLopez\CorteConfeccion\Services\RolloService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Models\AttributeOption;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

function f1d_atributo(string $code, string $nombre): Attribute
{
    return Attribute::firstWhere('code', $code)
        ?? Attribute::factory()->create(['code' => $code, 'type' => 'select', 'admin_name' => $nombre]);
}

function f1d_color(string $nombre): AttributeOption
{
    return AttributeOption::factory()
        ->for(f1d_atributo('color', 'Color'), 'attribute')
        ->create(['admin_name' => $nombre.' '.uniqid()]);
}

function f1d_tipoTela(string $nombre = 'Tela F1D'): AttributeOption
{
    return AttributeOption::factory()
        ->for(f1d_atributo('tipo_tela', 'Tipo de tela'), 'attribute')
        ->create(['admin_name' => $nombre.' '.uniqid()]);
}

function f1d_size(): AttributeOption
{
    return AttributeOption::factory()
        ->for(f1d_atributo('size', 'Talla'), 'attribute')
        ->create(['admin_name' => 'M '.uniqid()]);
}

function f1d_admin(): Admin
{
    $role = Role::factory()->create(['permission_type' => 'all']);

    return Admin::factory()->create(['role_id' => $role->id]);
}

function f1d_proveedorConReferencia(AttributeOption $tipoTela, AttributeOption $color): ProveedorTela
{
    return app(ProveedorTelaService::class)->create([
        'nombre' => 'Proveedor F1D '.uniqid(),
        'nit' => (string) random_int(500000000, 599999999),
        'telefono' => '3000000000',
        'contacto' => 'QA F1D',
        'direccion' => 'Calle F1D',
        'tipo_tela_ids' => [$tipoTela->id],
        'referencias' => [[
            'tipo_tela_id' => $tipoTela->id,
            'color_option_id' => $color->id,
            'color' => 'Texto ignorado',
            'referencia' => 'REF-F1D-'.uniqid(),
            'gramaje' => '180',
            'valor_kilo_referencia' => 20000,
            'estado' => 'activo',
        ]],
        'estado' => 'activo',
        'observacion' => null,
    ]);
}

function f1d_compraConRollo(ProveedorTela $proveedor, ProveedorTelaReferencia $referencia, AttributeOption $tipoTela): Rollo
{
    $compra = app(CompraTelaService::class)->create([
        'codigo' => 'CT-F1D-'.uniqid(),
        'proveedor_id' => $proveedor->id,
        'numero_factura' => null,
        'fecha_compra' => now()->toDateString(),
        'descuento' => 0,
        'impuesto' => 0,
        'observacion' => null,
        'rollos' => [[
            'codigo' => 'R-F1D-REF-'.uniqid(),
            'tipo_tela_id' => $tipoTela->id,
            'referencia_tela_id' => $referencia->id,
            'tipo_tela' => $tipoTela->admin_name,
            'color' => 'Color manipulado',
            'referencia' => $referencia->referencia,
            'gramaje' => $referencia->gramaje,
            'peso_inicial' => 10,
            'valor_kilo' => 20000,
            'color_option_id' => f1d_color('Color alterno')->id,
        ]],
    ]);

    return $compra->rollos()->firstOrFail();
}

function f1d_rolloManual(array $overrides = []): Rollo
{
    $color = $overrides['color_option'] ?? f1d_color('Azul manual F1D');

    unset($overrides['color_option']);

    return app(RolloService::class)->create(array_merge([
        'codigo' => 'R-F1D-MAN-'.uniqid(),
        'proveedor' => 'Proveedor manual',
        'tipo_tela' => 'Interlock',
        'color_option_id' => $color->id,
        'color' => 'Texto manipulado',
        'peso_inicial' => 12,
        'peso_disponible' => 12,
        'costo_total' => 240000,
        'fecha_compra' => now()->toDateString(),
        'observacion' => null,
    ], $overrides));
}

function f1d_updatePayload(Rollo $rollo, array $overrides = []): array
{
    return array_merge([
        'codigo' => $rollo->codigo,
        'proveedor' => $rollo->proveedor,
        'tipo_tela' => $rollo->tipo_tela,
        'color_option_id' => $rollo->color_option_id,
        'color' => $rollo->color,
        'peso_inicial' => $rollo->peso_inicial,
        'peso_disponible' => $rollo->peso_disponible,
        'costo_total' => $rollo->costo_total,
        'fecha_compra' => optional($rollo->fecha_compra)->toDateString(),
        'observacion' => $rollo->observacion,
    ], $overrides);
}

function f1d_ordenCorteConRollo(Rollo $rollo): OrdenCorte
{
    return OrdenCorte::create([
        'codigo' => 'OC-F1D-'.uniqid(),
        'rollo_id' => $rollo->id,
        'cortador_nombre' => 'Cortador F1D',
        'cortador_nombre_snapshot' => 'Cortador F1D',
        'color' => $rollo->color,
        'genero_linea' => 'Unisex',
        'peso_entregado' => 0,
        'peso_total_rollos' => 10,
        'peso_sobrante_total' => 0,
        'merma_total' => 0,
        'peso_consumido_total' => 0,
        'total_piezas_cortadas' => 0,
        'peso_promedio_pieza' => 0,
        'estado' => 'pendiente_entrega',
    ]);
}

test('F1-D: rollo desde referencia copia color_option_id de la referencia', function () {
    $tipoTela = f1d_tipoTela();
    $color = f1d_color('Rojo F1D');
    $proveedor = f1d_proveedorConReferencia($tipoTela, $color);
    $referencia = $proveedor->referenciasTela()->firstOrFail();

    $rollo = f1d_compraConRollo($proveedor, $referencia, $tipoTela);

    expect($rollo->color_option_id)->toBe($color->id)
        ->and($rollo->color)->toBe($referencia->color);
});

test('F1-D: rollo desde referencia no puede cambiar color directamente', function () {
    $tipoTela = f1d_tipoTela();
    $proveedor = f1d_proveedorConReferencia($tipoTela, f1d_color('Verde F1D'));
    $rollo = f1d_compraConRollo($proveedor, $proveedor->referenciasTela()->firstOrFail(), $tipoTela);

    expect(fn () => app(RolloService::class)->update($rollo, f1d_updatePayload($rollo, [
        'color_option_id' => f1d_color('Azul bloqueo F1D')->id,
    ])))->toThrow(ValidationException::class);
});

test('F1-D: manipular snapshot de color de un rollo con referencia es rechazado', function () {
    $tipoTela = f1d_tipoTela();
    $proveedor = f1d_proveedorConReferencia($tipoTela, f1d_color('Naranja F1D'));
    $rollo = f1d_compraConRollo($proveedor, $proveedor->referenciasTela()->firstOrFail(), $tipoTela);

    expect(fn () => app(RolloService::class)->update($rollo, f1d_updatePayload($rollo, [
        'color' => 'Texto alterado',
    ])))->toThrow(ValidationException::class);
});

test('F1-D: rollo manual nuevo exige color Bagisto real y deriva snapshot', function () {
    expect(fn () => app(RolloService::class)->create([
        'codigo' => 'R-F1D-SIN-COLOR-'.uniqid(),
        'proveedor' => 'Proveedor manual',
        'tipo_tela' => 'Interlock',
        'peso_inicial' => 10,
        'costo_total' => 100000,
    ]))->toThrow(ValidationException::class);

    $color = f1d_color('Violeta F1D');
    $rollo = f1d_rolloManual(['color_option' => $color, 'color' => 'Texto libre ignorado']);

    expect($rollo->color_option_id)->toBe($color->id)
        ->and($rollo->color)->toBe($color->admin_name);
});

test('F1-D: rollo manual rechaza option_id de size', function () {
    expect(fn () => f1d_rolloManual([
        'color_option_id' => f1d_size()->id,
    ]))->toThrow(ValidationException::class);
});

test('F1-D: rollo manual sin uso puede cambiar entre colores Bagisto validos', function () {
    $colorNuevo = f1d_color('Negro F1D');
    $rollo = f1d_rolloManual();

    $actualizado = app(RolloService::class)->update($rollo, f1d_updatePayload($rollo, [
        'color_option_id' => $colorNuevo->id,
        'color' => 'Texto manipulado',
    ]));

    expect($actualizado->color_option_id)->toBe($colorNuevo->id)
        ->and($actualizado->color)->toBe($colorNuevo->admin_name);
});

test('F1-D: rollo reservado no puede cambiar color', function () {
    $rollo = f1d_rolloManual();
    $rollo->update(['estado' => EstadoRollo::ReservadoEnCorte->value]);

    expect(fn () => app(RolloService::class)->update($rollo->refresh(), f1d_updatePayload($rollo->refresh(), [
        'color_option_id' => f1d_color('Reservado F1D')->id,
    ])))->toThrow(ValidationException::class);
});

test('F1-D: rollo ya usado en Orden de Corte no puede cambiar color', function () {
    $rollo = f1d_rolloManual();
    f1d_ordenCorteConRollo($rollo);

    expect(fn () => app(RolloService::class)->update($rollo->refresh(), f1d_updatePayload($rollo->refresh(), [
        'color_option_id' => f1d_color('Usado F1D')->id,
    ])))->toThrow(ValidationException::class);
});

test('F1-D: historico con color_option_id null sigue visible', function () {
    $rollo = Rollo::create([
        'codigo' => 'R-F1D-HIST-'.uniqid(),
        'proveedor' => 'Proveedor historico',
        'tipo_tela' => 'Rib',
        'color' => 'Verde botella historico',
        'color_option_id' => null,
        'peso_inicial' => 8,
        'peso_disponible' => 8,
        'costo_total' => 160000,
        'estado' => EstadoRollo::Disponible->value,
    ]);

    $this->actingAs(f1d_admin(), 'admin')
        ->get(route('admin.corte_confeccion.rollos.show', $rollo->id))
        ->assertOk()
        ->assertSee('Verde botella historico');
});

/*
|--------------------------------------------------------------------------
| Cierre de F1-D: reglas de color del rollo a nivel de request/HTTP, snapshot
| inmutable en rollos bloqueados y orden cerrada.
|--------------------------------------------------------------------------
*/

/**
 * Orden de corte que usa el rollo SOLO por el pivote (orden_corte_rollos),
 * como el 2.o rollo de una orden multi-rollo: la FK historica
 * ordenes_corte.rollo_id apunta a otro rollo.
 */
function f1d_ordenSoloPorPivote(Rollo $rollo, string $estadoOrden): OrdenCorte
{
    $otro = f1d_rolloManual();

    $orden = OrdenCorte::create([
        'codigo' => 'OC-F1D-PIV-'.uniqid(),
        'rollo_id' => $otro->id,
        'cortador_nombre' => 'Cortador F1D',
        'cortador_nombre_snapshot' => 'Cortador F1D',
        'color' => $rollo->color,
        'genero_linea' => 'Unisex',
        'peso_entregado' => 0,
        'peso_total_rollos' => 10,
        'estado' => $estadoOrden,
    ]);

    $orden->rollosCorte()->create([
        'rollo_id' => $rollo->id,
        'peso_inicial_usado' => $rollo->peso_disponible,
        'peso_sobrante' => 0,
        'peso_consumido' => 0,
    ]);

    return $orden;
}

/** Los cuatro tipos de rollo con el color bloqueado, para probar las mismas reglas en todos. */
function f1d_rollosConColorBloqueado(): array
{
    $tipoTela = f1d_tipoTela();
    $proveedor = f1d_proveedorConReferencia($tipoTela, f1d_color('Base bloqueo F1D'));

    $conReferencia = f1d_compraConRollo($proveedor, $proveedor->referenciasTela()->firstOrFail(), $tipoTela);

    $reservado = f1d_rolloManual();
    $reservado->update(['estado' => EstadoRollo::ReservadoEnCorte->value]);

    $usadoPorFk = f1d_rolloManual();
    f1d_ordenCorteConRollo($usadoPorFk);

    $usadoPorPivote = f1d_rolloManual();
    f1d_ordenSoloPorPivote($usadoPorPivote, 'cerrado');

    return [
        'con referencia' => $conReferencia,
        'reservado' => $reservado,
        'usado (FK historica)' => $usadoPorFk,
        'usado solo por pivote en orden cerrada' => $usadoPorPivote,
    ];
}

test('F1-D: rollo manual rechaza option_id de tipo_tela', function () {
    expect(fn () => f1d_rolloManual(['color_option_id' => f1d_tipoTela()->id]))
        ->toThrow(ValidationException::class);
});

test('F1-D: rollo usado solo por pivote en una orden CERRADA no puede cambiar color (trazabilidad)', function () {
    $rollo = f1d_rolloManual();
    f1d_ordenSoloPorPivote($rollo, 'cerrado');

    // El rollo ya no esta reservado (la orden cerro), pero participo en ella.
    $rollo->update(['estado' => EstadoRollo::Agotado->value]);

    $colorOriginal = $rollo->color_option_id;

    expect(fn () => app(RolloService::class)->update($rollo->refresh(), f1d_updatePayload($rollo->refresh(), [
        'color_option_id' => f1d_color('Cerrada F1D')->id,
    ])))->toThrow(ValidationException::class);

    expect($rollo->fresh()->color_option_id)->toBe($colorOriginal);
});

test('F1-D: un rollo con color bloqueado NUNCA reescribe su snapshot, aunque llegue el mismo color_option_id', function () {
    foreach (f1d_rollosConColorBloqueado() as $descripcion => $rollo) {
        $snapshotOriginal = $rollo->color;
        $colorOptionId = $rollo->color_option_id;

        // La opcion se renombra en Bagisto: derivar el snapshot de nuevo lo cambiaria.
        DB::table('attribute_options')->where('id', $colorOptionId)->update(['admin_name' => 'Renombrado '.uniqid()]);

        app(RolloService::class)->update($rollo->refresh(), f1d_updatePayload($rollo->refresh(), [
            'color_option_id' => $colorOptionId,
            'observacion' => 'edicion permitida',
        ]));

        expect($rollo->fresh()->color)->toBe($snapshotOriginal, "snapshot cambio en: $descripcion")
            ->and($rollo->fresh()->color_option_id)->toBe($colorOptionId)
            ->and($rollo->fresh()->observacion)->toBe('edicion permitida');
    }
});

test('F1-D HTTP: manipular color_option_id o color de un rollo bloqueado es rechazado y no cambia nada', function () {
    $admin = f1d_admin();
    $otroColor = f1d_color('Manipulado F1D');

    foreach (f1d_rollosConColorBloqueado() as $descripcion => $rollo) {
        $antes = $rollo->only(['color', 'color_option_id']);
        $url = route('admin.corte_confeccion.rollos.update', $rollo->id);

        foreach ([
            'otro color de Bagisto' => ['color_option_id' => $otroColor->id],
            'option_id de size' => ['color_option_id' => f1d_size()->id],
            'texto libre en color' => ['color' => 'Texto libre'],
        ] as $intento => $cambios) {
            $this->actingAs($admin, 'admin')
                ->putJson($url, f1d_updatePayload($rollo, $cambios))
                ->assertStatus(422);

            expect($rollo->fresh()->only(['color', 'color_option_id']))->toBe($antes, "'$intento' modifico el color en: $descripcion");
        }
    }
});

test('F1-D HTTP: editar otros datos de un rollo bloqueado (sin enviar color, como hace el modal) funciona y conserva el color', function () {
    $admin = f1d_admin();

    foreach (f1d_rollosConColorBloqueado() as $descripcion => $rollo) {
        $antes = $rollo->only(['color', 'color_option_id']);
        $payload = f1d_updatePayload($rollo, ['observacion' => 'Nota '.$descripcion]);

        // El select de color viene deshabilitado en el modal: no se envia.
        unset($payload['color'], $payload['color_option_id']);

        // El modal envia por axios (AJAX): responde JSON 200 en vez de redirigir.
        $this->actingAs($admin, 'admin')
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->putJson(route('admin.corte_confeccion.rollos.update', $rollo->id), $payload)
            ->assertOk();

        expect($rollo->fresh()->observacion)->toBe('Nota '.$descripcion)
            ->and($rollo->fresh()->only(['color', 'color_option_id']))->toBe($antes);
    }
});

test('F1-D HTTP: un rollo manual sin uso cambia de color solo a opciones del atributo color', function () {
    $admin = f1d_admin();
    $rollo = f1d_rolloManual();
    $nuevo = f1d_color('Cambio HTTP F1D');
    $url = route('admin.corte_confeccion.rollos.update', $rollo->id);

    foreach ([f1d_size(), f1d_tipoTela()] as $ajena) {
        $this->actingAs($admin, 'admin')
            ->putJson($url, f1d_updatePayload($rollo, ['color_option_id' => $ajena->id]))
            ->assertStatus(422);
    }

    $this->actingAs($admin, 'admin')
        ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
        ->putJson($url, f1d_updatePayload($rollo, ['color_option_id' => $nuevo->id, 'color' => 'Texto ignorado']))
        ->assertOk();

    expect($rollo->fresh()->color_option_id)->toBe($nuevo->id)
        ->and($rollo->fresh()->color)->toBe($nuevo->admin_name);
});

test('F1-D: el alta manual no tiene ruta activa y su Request exige un color del atributo color de Bagisto', function () {
    // CASO B: no hay ruta que cree rollos manuales; aun asi el Request y el Service quedan blindados.
    expect(Route::has('admin.corte_confeccion.rollos.create'))->toBeFalse()
        ->and(Route::has('admin.corte_confeccion.rollos.store'))->toBeFalse();

    $errores = function (array $cambios) {
        $base = [
            'codigo' => 'R-F1D-REQ-'.uniqid(), 'proveedor' => 'Proveedor', 'tipo_tela' => 'Interlock',
            'peso_inicial' => 10, 'costo_total' => 100,
        ];
        $request = StoreRolloRequest::create('/x', 'POST', array_merge($base, $cambios));

        return Validator::make($request->all(), $request->rules())->errors();
    };

    $color = f1d_color('Request F1D');

    expect($errores(['color_option_id' => $color->id])->has('color_option_id'))->toBeFalse()
        ->and($errores([])->has('color_option_id'))->toBeTrue()                                    // obligatorio
        ->and($errores(['color' => 'Texto libre'])->has('color_option_id'))->toBeTrue()            // el texto no reemplaza al ID
        ->and($errores(['color_option_id' => f1d_size()->id])->has('color_option_id'))->toBeTrue()
        ->and($errores(['color_option_id' => f1d_tipoTela()->id])->has('color_option_id'))->toBeTrue();
});

test('F1-D UI: el modal de edicion de rollos ofrece un selector de colores Bagisto y ningun input de texto libre', function () {
    f1d_color('Visible F1D');

    $html = $this->actingAs(f1d_admin(), 'admin')
        ->get(route('admin.corte_confeccion.rollos.index'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('name="color_option_id"')
        ->and($html)->toContain('Seleccionar color del catálogo')
        ->and($html)->not->toContain('Sin vincular')
        ->and(preg_match('/<(input|textarea|select)\b[^>]*\bname="color"/', $html))->toBe(0);
});
