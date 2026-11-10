<?php

use ByLopez\VentasAsesor\Services\Credito\CreditoClienteService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use Webkul\User\Models\Role;

require_once __DIR__.'/../helpersD10.php';

uses(TestCase::class);

/*
| D10 V1 · cierre: ACL por rol (HTTP, Bouncer real), comprobante de reembolso (privado, validado, sin sobrescritura), endpoint de credito para
| Nueva venta y bloques de interfaz en Cartera / detalle del pedido. Solo lpz_saas_testing, dentro de la transaccion de la prueba.
*/

const D10_BASE = ['ventas_asesor', 'ventas_asesor.cartera', 'ventas_asesor.cartera.view_all', 'ventas_asesor.pedidos', 'ventas_asesor.pedidos.view_all'];

function d10i_rol(array $extra, string $nombre = 'D10 rol'): object
{
    return f03b_admin('custom', array_merge(D10_BASE, $extra));
}

function d10i_reembolso(object $actor, array $e, array $datos = [], ?UploadedFile $archivo = null)
{
    return test()->actingAs($actor, 'admin')->post(route('admin.ventas_asesor.cartera.credito.reembolsar'), array_filter($datos + [
        'origen' => $e['a']->id, 'monto' => '5000', 'medio' => 'transferencia', 'referencia' => 'TRF-1', 'motivo' => 'Devolucion al cliente',
        'idempotency_key' => 'd10i-ree-'.Str::uuid(), 'comprobante' => $archivo,
    ], fn ($v) => $v !== null), ['Accept' => 'application/json']);
}

test('ACL por rol: Administrador (all) opera; rol con solo «ver» consulta pero no aplica; ASESOR VENTAS y BODEGA reales no tienen hojas de credito', function () {
    $e = d10_escenario();
    $cliente = (int) $e['cliente']->id;
    $admin = d10_admin();
    $soloVer = d10i_rol(['ventas_asesor.cartera.credito.ver']);
    $sinCredito = d10i_rol([]);

    $this->actingAs($admin, 'admin')->getJson(route('admin.ventas_asesor.cartera.credito.cliente', $cliente))
        ->assertOk()->assertJsonPath('visible', true)->assertJsonPath('puede_aplicar', true)->assertJsonPath('total_disponible', 20000);

    $this->actingAs($soloVer, 'admin')->getJson(route('admin.ventas_asesor.cartera.credito.cliente', $cliente))
        ->assertOk()->assertJsonPath('puede_aplicar', false);

    // Sin la hoja, Bagisto cierra la ruta (falla cerrado) y el servicio tampoco opera.
    $this->actingAs($sinCredito, 'admin')->getJson(route('admin.ventas_asesor.cartera.credito.cliente', $cliente))->assertUnauthorized();
    $this->actingAs($sinCredito, 'admin')->getJson(route('admin.ventas_asesor.cartera.credito.pedido', $e['a']->id))->assertUnauthorized();

    $aplicar = ['origen' => $e['a']->id, 'destino' => $e['b']->id, 'monto' => '1000', 'idempotency_key' => 'd10i-apl-'.Str::uuid()];
    $this->actingAs($soloVer, 'admin')->postJson(route('admin.ventas_asesor.cartera.credito.aplicar'), $aplicar)->assertUnauthorized();
    $this->actingAs($sinCredito, 'admin')->postJson(route('admin.ventas_asesor.cartera.credito.aplicar'), $aplicar)->assertUnauthorized();
    $this->actingAs($soloVer, 'admin')->postJson(route('admin.ventas_asesor.cartera.credito.reversar', 1), ['motivo' => 'x', 'idempotency_key' => 'd10i-rev-'.Str::uuid()])->assertUnauthorized();
    $this->actingAs($soloVer, 'admin')->postJson(route('admin.ventas_asesor.cartera.credito.ajustar'), ['pedido' => $e['a']->id, 'monto' => '1', 'causa' => 'error_bylopez', 'motivo' => 'x', 'idempotency_key' => 'd10i-aju-'.Str::uuid()])->assertUnauthorized();
    d10i_reembolso($soloVer, $e)->assertUnauthorized();

    // Nada se movio por los intentos denegados.
    expect(DB::table('bylopez_va_creditos_cliente_movimientos')->count())->toBe(0);

    // Roles reales de la base: ninguna hoja de credito asignada a ASESOR VENTAS ni a BODEGA (los roles `all` ya las tienen por definicion de Bagisto).
    foreach (Role::query()->whereIn('name', ['ASESOR VENTAS', 'BODEGA'])->where('permission_type', 'custom')->get() as $rol) {
        expect(collect($rol->permissions)->filter(fn ($p) => str_contains((string) $p, 'cartera.credito'))->all())->toBe([]);
    }
});

test('comprobante de reembolso: privado, tipos y tamano validados, sin sobrescritura, descarga solo con permiso', function () {
    Storage::fake('private');
    $e = d10_escenario();
    $admin = d10_admin();
    $pdf = fn (string $n = 'comprobante.pdf', int $kb = 100) => UploadedFile::fake()->create($n, $kb, 'application/pdf');

    // Valido: PDF → 201, ruta guardada en el movimiento de ESE reembolso, archivo en disco privado.
    $r = d10i_reembolso($admin, $e, [], $pdf())->assertCreated();
    $mov = DB::table('bylopez_va_creditos_cliente_movimientos')->where('id', $r->json('movimiento_id'))->first();

    expect($mov->tipo)->toBe('reembolso')->and($mov->comprobante_ruta)->not->toBeNull()
        ->and(Storage::disk('private')->exists($mov->comprobante_ruta))->toBeTrue()
        ->and(Storage::disk('public')->exists($mov->comprobante_ruta))->toBeFalse()
        ->and(str_starts_with($mov->comprobante_ruta, 'ventas-asesor/credito/'))->toBeTrue();

    // Tipo no permitido y tamano excesivo → 422 y NO se mueve credito ni queda archivo.
    $antes = count(Storage::disk('private')->allFiles());
    d10i_reembolso($admin, $e, [], UploadedFile::fake()->create('malo.exe', 10, 'application/x-msdownload'))->assertStatus(422);
    d10i_reembolso($admin, $e, [], $pdf('grande.pdf', (int) config('ventasasesor.comisiones.comprobantes.max_kb') + 1))->assertStatus(422);
    expect(count(Storage::disk('private')->allFiles()))->toBe($antes)->and(DB::table('bylopez_va_creditos_cliente_movimientos')->count())->toBe(1);

    // Mismo nombre de archivo en otro reembolso: archivo distinto (nunca se pisa el anterior).
    $r2 = d10i_reembolso($admin, $e, ['monto' => '3000'], $pdf())->assertCreated();
    $ruta2 = DB::table('bylopez_va_creditos_cliente_movimientos')->where('id', $r2->json('movimiento_id'))->value('comprobante_ruta');
    expect($ruta2)->not->toBe($mov->comprobante_ruta)->and(Storage::disk('private')->exists($mov->comprobante_ruta))->toBeTrue();

    // Repeticion idempotente (misma clave): un solo movimiento y NO se acumula el archivo repetido.
    $clave = 'd10i-ree-fija-'.Str::uuid();
    $primero = d10i_reembolso($admin, $e, ['monto' => '1000', 'idempotency_key' => $clave], $pdf())->assertCreated();
    $archivos = count(Storage::disk('private')->allFiles());
    $segundo = d10i_reembolso($admin, $e, ['monto' => '1000', 'idempotency_key' => $clave], $pdf())->assertOk();
    expect($segundo->json('movimiento_id'))->toBe($primero->json('movimiento_id'))->and(count(Storage::disk('private')->allFiles()))->toBe($archivos);

    // Descarga: con permiso de ver → el archivo; sin permiso → denegado; movimiento sin comprobante → 404; y la ruta no existe en la carpeta publica.
    $this->actingAs($admin, 'admin')->get(route('admin.ventas_asesor.cartera.credito.comprobante', $mov->id))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->actingAs(d10i_rol([]), 'admin')->get(route('admin.ventas_asesor.cartera.credito.comprobante', $mov->id))->assertUnauthorized();
    $sinArchivo = d10i_reembolso($admin, $e, ['monto' => '500'])->assertCreated()->json('movimiento_id');
    $this->actingAs($admin, 'admin')->get(route('admin.ventas_asesor.cartera.credito.comprobante', $sinArchivo))->assertNotFound();
    expect(file_exists(public_path($mov->comprobante_ruta)))->toBeFalse();

    // El historial avisa que hay comprobante (para el enlace de la interfaz).
    $historial = collect($this->actingAs($admin, 'admin')->getJson(route('admin.ventas_asesor.cartera.credito.pedido', $e['a']->id))->json('movimientos'));
    expect((bool) $historial->firstWhere('id', $mov->id)['tiene_comprobante'])->toBeTrue()
        ->and((bool) $historial->firstWhere('id', $sinArchivo)['tiene_comprobante'])->toBeFalse();
});

test('la transferencia exige referencia o comprobante; solo el comprobante tambien basta', function () {
    Storage::fake('private');
    $e = d10_escenario();
    $admin = d10_admin();

    d10i_reembolso($admin, $e, ['referencia' => null])->assertStatus(422);
    d10i_reembolso($admin, $e, ['referencia' => null], UploadedFile::fake()->create('t.png', 50, 'image/png'))->assertCreated();
});

test('interfaz: Cartera del cliente y detalle del pedido muestran el bloque solo a quien puede verlo; el pedido sin credito no lo muestra', function () {
    $e = d10_escenario();
    $admin = d10_admin();
    $soloVer = d10i_rol(['ventas_asesor.cartera.credito.ver']);
    $sin = d10i_rol([]);
    $urlCliente = route('admin.ventas_asesor.cartera.index', ['cliente' => $e['cliente']->id]);
    $urlOrigen = route('admin.ventas_asesor.pedidos.index', ['pedido' => $e['a']->id]);
    $urlDestino = route('admin.ventas_asesor.pedidos.index', ['pedido' => $e['b']->id]);

    $this->actingAs($admin, 'admin')->get($urlCliente)->assertOk()->assertSee('data-credito-cliente', false)->assertSee('data-credito-totales', false);
    $this->actingAs($admin, 'admin')->get($urlOrigen)->assertOk()->assertSee('data-credito-cliente', false)->assertSee('data-credito-accion="aplicar"', false)
        ->assertSee('data-credito-accion="reembolsar"', false)->assertSee('data-credito-accion="movimientos"', false);
    // El flete ya fue condonado entero: no hay nada mas que condonar y el boton no se ofrece.
    $this->actingAs($admin, 'admin')->get($urlOrigen)->assertDontSee('data-credito-accion="ajustar"', false);
    // El pedido destino (sin credito propio, con flete 0) no muestra el bloque.
    $this->actingAs($admin, 'admin')->get($urlDestino)->assertOk()->assertDontSee('data-credito-cliente', false);

    // Solo «ver»: ve el bloque pero SIN botones de operar.
    $this->actingAs($soloVer, 'admin')->get($urlOrigen)->assertOk()->assertSee('data-credito-cliente', false)
        ->assertDontSee('data-credito-accion="aplicar"', false)->assertDontSee('data-credito-accion="reembolsar"', false);
    $this->actingAs($sin, 'admin')->get($urlOrigen)->assertOk()->assertDontSee('data-credito-cliente', false);

    // Tras aplicar: el destino pasa a mostrar el credito recibido de otros pedidos.
    d10_aplicar($e, '8000');
    $this->actingAs($admin, 'admin')->get($urlDestino)->assertOk()->assertSee('data-credito-cliente', false);
});

test('Nueva venta: el endpoint de credito del cliente reporta el disponible por origen y nunca el de otro cliente', function () {
    $e = d10_escenario();
    $otro = pagb_cliente();
    $admin = d10_admin();

    $this->actingAs($admin, 'admin')->getJson(route('admin.ventas_asesor.cartera.credito.cliente', $e['cliente']->id))
        ->assertOk()->assertJsonPath('origenes.0.order_id', $e['a']->id)->assertJsonPath('origenes.0.disponible', 20000);
    $this->actingAs($admin, 'admin')->getJson(route('admin.ventas_asesor.cartera.credito.cliente', $otro->id))
        ->assertOk()->assertJsonPath('total_disponible', 0)->assertJsonCount(0, 'origenes');

    // Aplicacion parcial desde Nueva venta: 8.000 de 20.000; quedan 12.000 y el destino debe 42.000.
    $this->actingAs($admin, 'admin')->postJson(route('admin.ventas_asesor.cartera.credito.aplicar'), [
        'origen' => $e['a']->id, 'destino' => $e['b']->id, 'monto' => '8000.00', 'motivo' => 'Aplicado desde Nueva venta', 'idempotency_key' => 'd10i-nv-'.Str::uuid(),
    ])->assertCreated();

    expect(d10_credito($e['a']->id)['disponible'])->toBe(12000.0)->and(d10_saldo($e['b']->id)->saldo)->toBe(42000.0)
        ->and(app(CreditoClienteService::class)->delCliente((int) $e['cliente']->id)['total_disponible'])->toBe(12000.0);
});

test('solo el Administrador autorizado opera dinero: roles reales y cualquier rol `all` que no lo sea reciben denegacion (HTTP, Bouncer real)', function () {
    Storage::fake('private');
    $e = d10_escenario();
    // Configuracion por defecto de produccion/dev: el unico rol que opera es el #1 (Administrador).
    config()->set('ventasasesor.credito.rol_administrador_id', 1);
    $rolAdmin = Role::query()->findOrFail(1);
    expect($rolAdmin->permission_type)->toBe('all');

    $crear = fn (int $rolId) => \Webkul\User\Models\Admin::factory()->create(['role_id' => $rolId]);
    $rol = fn (string $nombre, string $tipo, ?array $permisos) => Role::factory()->create(['name' => $nombre, 'permission_type' => $tipo, 'permissions' => $permisos])->id;

    $actores = [
        'ASESOR VENTAS (custom, cartera view_own)' => $crear($rol('ASESOR VENTAS', 'custom', ['ventas_asesor', 'ventas_asesor.cartera', 'ventas_asesor.cartera.view_own'])),
        'BODEGA principal (custom)' => $crear($rol('BODEGA', 'custom', ['corte_confeccion', 'logistica'])),
        'BODEGA #12071 (all)' => $crear($rol('BODEGA', 'all', null)),
        'otro all (QA)' => $crear($rol('Admin Cartera QA', 'all', null)),
        'all con nombre Administrador pero otro id' => $crear($rol('Administrador', 'all', null)),
        'custom con TODAS las hojas de credito mal asignadas' => $crear($rol('Mal asignado', 'custom', array_merge(D10_BASE, [
            'ventas_asesor.cartera.credito.ver', 'ventas_asesor.cartera.credito.aplicar', 'ventas_asesor.cartera.credito.reembolsar',
            'ventas_asesor.cartera.credito.reversar', 'ventas_asesor.cartera.credito.ajustar',
        ]))),
    ];
    $administrador = $crear(1);
    $sinMovimientos = fn () => DB::table('bylopez_va_creditos_cliente_movimientos')->count();

    $intentos = fn ($actor) => [
        'ver cliente' => fn () => test()->actingAs($actor, 'admin')->getJson(route('admin.ventas_asesor.cartera.credito.cliente', $e['cliente']->id)),
        'ver pedido' => fn () => test()->actingAs($actor, 'admin')->getJson(route('admin.ventas_asesor.cartera.credito.pedido', $e['a']->id)),
        'aplicar' => fn () => test()->actingAs($actor, 'admin')->postJson(route('admin.ventas_asesor.cartera.credito.aplicar'), ['origen' => $e['a']->id, 'destino' => $e['b']->id, 'monto' => '1000', 'idempotency_key' => 'd10x-apl-'.Str::uuid()]),
        'reembolsar' => fn () => d10i_reembolso($actor, $e),
        'reversar' => fn () => test()->actingAs($actor, 'admin')->postJson(route('admin.ventas_asesor.cartera.credito.reversar', 1), ['motivo' => 'x', 'idempotency_key' => 'd10x-rev-'.Str::uuid()]),
        'ajustar' => fn () => test()->actingAs($actor, 'admin')->postJson(route('admin.ventas_asesor.cartera.credito.ajustar'), ['pedido' => $e['a']->id, 'monto' => '1', 'causa' => 'error_bylopez', 'motivo' => 'x', 'idempotency_key' => 'd10x-aju-'.Str::uuid()]),
        'reversar ajuste' => fn () => test()->actingAs($actor, 'admin')->postJson(route('admin.ventas_asesor.cartera.credito.ajuste_reversar', 1), ['motivo' => 'x', 'idempotency_key' => 'd10x-ra-'.Str::uuid()]),
    ];

    foreach ($actores as $nombre => $actor) {
        foreach ($intentos($actor) as $operacion => $ejecutar) {
            $resp = $ejecutar();
            $estado = $resp->getStatusCode();

            // «ver cliente» (chip de Nueva venta) responde 200 con visible:false para no provocar el aviso de 403 del panel; jamas ofrece credito.
            if ($operacion === 'ver cliente' && $estado === 200) {
                // Solo un rol `custom` con la hoja «ver» asignada expresamente puede VER (nunca operar); un rol `all` no administrador ni eso.
                $verPermitido = str_starts_with($nombre, 'custom con TODAS');
                expect((bool) $resp->json('visible'))->toBe($verPermitido, "{$nombre} / ver cliente")->and((bool) $resp->json('puede_aplicar'))->toBeFalse();

                continue;
            }

            if ($operacion === 'ver pedido' && $estado === 200) {
                expect($nombre)->toStartWith('custom con TODAS');

                continue;
            }

            expect($estado)->toBeIn([401, 403], "{$nombre} / {$operacion} respondio {$estado}");
        }

        // Las pantallas tampoco ofrecen el bloque ni los botones.
        $this->actingAs($actor, 'admin')->get(route('admin.ventas_asesor.pedidos.index', ['pedido' => $e['a']->id]))->assertDontSee('data-credito-accion="aplicar"', false);
    }

    expect($sinMovimientos())->toBe(0)->and(DB::table('bylopez_va_ajustes_obligacion')->count())->toBe(1);

    // El Administrador autorizado (rol #1) sigue operando: ve, aplica y reembolsa.
    $this->actingAs($administrador, 'admin')->getJson(route('admin.ventas_asesor.cartera.credito.cliente', $e['cliente']->id))->assertOk()->assertJsonPath('puede_aplicar', true);
    $this->actingAs($administrador, 'admin')->postJson(route('admin.ventas_asesor.cartera.credito.aplicar'), ['origen' => $e['a']->id, 'destino' => $e['b']->id, 'monto' => '1000', 'idempotency_key' => 'd10x-ok-'.Str::uuid()])->assertCreated();
    d10i_reembolso($administrador, $e)->assertCreated();
    expect($sinMovimientos())->toBe(2);

    // Un administrador desactivado deja de serlo.
    $administrador->forceFill(['status' => 0])->save();
    $this->actingAs($administrador->refresh(), 'admin')->postJson(route('admin.ventas_asesor.cartera.credito.aplicar'), ['origen' => $e['a']->id, 'destino' => $e['b']->id, 'monto' => '1000', 'idempotency_key' => 'd10x-off-'.Str::uuid()]);
    expect($sinMovimientos())->toBe(2);
});
