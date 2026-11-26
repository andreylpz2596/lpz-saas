<?php

use ByLopez\Logistica\Models\Transportadora;
use ByLopez\VentasAsesor\Services\Clientes\ClienteAccesoService;
use ByLopez\VentasAsesor\Support\Autoservicio\RolClienteFrecuente;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

require_once __DIR__.'/../helpersFase03f.php';
require_once __DIR__.'/../helpersGeo.php';
require_once __DIR__.'/../../../Logistica/tests/helpersDespacho.php';
require_once __DIR__.'/../../../Logistica/tests/helpersDomicilios.php';

uses(TestCase::class);

/* Doc. 33: domicilio LOCAL del cliente: sin envio nacional, con domiciliarios y PICAP (sin costo) a elegir. Base exclusiva. */

beforeEach(function () {
    Mail::fake();
    f03f_configurarBase();
    geo_cargar();
    Role::query()->where('name', RolClienteFrecuente::NOMBRE)->delete();
    $this->c = log6_catalogo();   // Bogota: Jeison 10.000, Brian 12.000, PICAP sin tarifa

    $gestor = f03b_admin('custom', [ClienteAccesoService::PERMISO]);
    $this->cliente = f03b_cliente(f03b_grupo('G'), ['first_name' => 'Ana', 'last_name' => 'Local', 'email' => 'l.'.uniqid().'@example.test']);
    $this->user = Admin::query()->find(app(ClienteAccesoService::class)->crearUsuario($this->cliente->id, $gestor)['usuario']['id']);

    $configurable = f03b_configurable(10000.0);
    $this->v = $configurable->variants()->first();
    f03b_stock($this->v, 50);
    $this->configurable = $configurable;
});

function cf4b_local($t): array
{
    $id = $t->actingAs($t->user, 'admin')->postJson(route('admin.autoservicio.iniciar_con_tallas'), [
        'referencia' => (string) Str::uuid(), 'producto_id' => $t->configurable->id, 'cantidades' => [$t->v->id => 1],
    ])->assertOk()->json('borrador.id');

    // Direccion propia en Bogota (11001), creada como la crea el cliente en la pantalla.
    $t->dir = (object) ['id' => $t->actingAs($t->user, 'admin')->postJson(route('admin.autoservicio.direcciones.agregar', ['borrador' => $id]), [
        'nombre' => 'Ana', 'apellido' => 'Local', 'telefono' => '3001234567', 'lineas' => ['Calle 1 # 2-3'], 'municipio_codigo' => '11001', 'codigo_postal' => '110111',
    ])->assertOk()->json('direccion_id')];

    $e = $t->actingAs($t->user, 'admin')->postJson(route('admin.autoservicio.entrega', ['borrador' => $id]), [
        'envio' => ['tipo' => 'guardada', 'address_id' => $t->dir->id],
    ])->assertOk()->json();

    return [$id, $e];
}

test('local: el cliente ve los domiciliarios y PICAP con valor, y jamas el envio nacional', function () {
    [$id, $e] = cf4b_local($this);
    $dom = $e['entrega']['domiciliario'];
    $nombres = array_column($dom['opciones'], 'tarifa', 'nombre');

    expect($dom)->not->toBeNull()
        ->and($nombres)->toHaveKeys(['JEISON', 'BRIAN', 'PICAP'])
        ->and((float) $nombres['PICAP'])->toBe(0.0)
        ->and($dom)->not->toHaveKey('sugerido_id')
        ->and(array_column($e['entrega']['metodos_disponibles'], 'codigo'))->not->toContain('envio_nacional');
});

test('local: pedir envio nacional se rechaza; domicilio local con PICAP no cobra nada y con otro domiciliario cobra su tarifa', function () {
    [$id] = cf4b_local($this);

    $this->actingAs($this->user, 'admin')->postJson(route('admin.autoservicio.entrega', ['borrador' => $id]), [
        'envio' => ['tipo' => 'guardada', 'address_id' => $this->dir->id], 'metodo' => 'envio_nacional',
    ])->assertStatus(403)->assertJsonPath('codigo', 'metodo_no_permitido');

    $e = $this->actingAs($this->user, 'admin')->postJson(route('admin.autoservicio.entrega', ['borrador' => $id]), [
        'envio' => ['tipo' => 'guardada', 'address_id' => $this->dir->id], 'metodo' => 'domicilio_local',
    ])->assertOk()->json();
    $picap = collect($e['entrega']['domiciliario']['opciones'])->firstWhere('nombre', 'PICAP');
    $brian = collect($e['entrega']['domiciliario']['opciones'])->firstWhere('nombre', 'BRIAN');

    $r = $this->actingAs($this->user, 'admin')->postJson(route('admin.autoservicio.domiciliario', ['borrador' => $id]), ['transportadora_id' => $picap['id']])->assertOk()->json();
    expect($r['entrega']['domiciliario']['elegido_id'])->toBe($picap['id'])
        ->and((float) $r['entrega']['metodo_seleccionado']['precio'])->toBe(0.0);

    $r = $this->actingAs($this->user, 'admin')->postJson(route('admin.autoservicio.domiciliario', ['borrador' => $id]), ['transportadora_id' => $brian['id']])->assertOk()->json();
    expect((float) $r['entrega']['metodo_seleccionado']['precio'])->toBe(12000.0);
});

test('local: el cliente no puede elegir un transportador fuera de las opciones', function () {
    [$id] = cf4b_local($this);
    $fuera = Transportadora::query()->create(['codigo' => 'X_'.Str::random(4), 'nombre' => 'X', 'tipo' => 'domiciliario', 'activo' => true, 'liquida_cuenta_corriente' => false, 'genera_guia_interna' => false]);

    $this->actingAs($this->user, 'admin')->postJson(route('admin.autoservicio.domiciliario', ['borrador' => $id]), ['transportadora_id' => $fuera->id])->assertStatus(403);
    $this->actingAs($this->user, 'admin')->postJson(route('admin.autoservicio.domiciliario', ['borrador' => $id]), [])->assertStatus(422);
});
