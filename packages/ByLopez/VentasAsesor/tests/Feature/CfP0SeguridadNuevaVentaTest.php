<?php

use ByLopez\VentasAsesor\Services\Venta\BorradorVentaService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Webkul\Customer\Models\CustomerAddress;

require_once __DIR__.'/../helpersFase03f.php';
require_once __DIR__.'/../helpersGeo.php';

uses(TestCase::class);

/*
| P0 (Cliente frecuente, doc 33 §14): fugas de datos entre asesores en Nueva venta. Dos asesores y dos clientes
| sinteticos, peticiones HTTP directas (no Vue). Solo base exclusiva de pruebas.
*/

beforeEach(function () {
    Mail::fake();
    f03f_configurarBase();
    geo_cargar();

    $this->grupo = f03b_grupo('G');
    $this->clienteA = f03b_cliente($this->grupo, ['first_name' => 'Ana', 'last_name' => 'ClienteA', 'phone' => '3'.random_int(100000000, 149999999)]);
    $this->clienteB = f03b_cliente($this->grupo, ['first_name' => 'Beto', 'last_name' => 'ClienteB', 'phone' => '3'.random_int(150000000, 199999999)]);

    foreach ([$this->clienteA, $this->clienteB] as $c) {
        CustomerAddress::factory()->create([
            'customer_id' => $c->id, 'address_type' => 'customer', 'default_address' => 1,
            'first_name' => $c->first_name, 'last_name' => $c->last_name,
            'address' => 'Calle secreta de '.$c->first_name, 'phone' => '3000000000',
        ]);
    }

    $this->asesor1 = f03b_admin('custom', [AutorizacionVentasAsesor::PERMISO_MODULO, BorradorVentaService::PERMISO]);
    $this->asesor2 = f03b_admin('custom', [AutorizacionVentasAsesor::PERMISO_MODULO, BorradorVentaService::PERMISO]);

    $this->configurable = f03b_configurable(10000.0);
    foreach ($this->configurable->variants()->get() as $v) {
        f03b_stock($v, 50);
    }
});

function cf_iniciar($prueba, $asesor, int $cliente): int
{
    return $prueba->actingAs($asesor, 'admin')
        ->postJson(route('admin.ventas_asesor.nueva_venta.iniciar'), ['customer_id' => $cliente, 'canal' => 'tienda_fisica'])
        ->assertOk()
        ->json('borrador.id');
}

test('P0 estado: el propietario lo lee; otro asesor recibe 409 sin datos; el inexistente da 404', function () {
    $borrador = cf_iniciar($this, $this->asesor1, $this->clienteA->id);
    $ruta = fn (int $id) => route('admin.ventas_asesor.nueva_venta.estado', ['borrador' => $id]);

    $this->actingAs($this->asesor1, 'admin')->getJson($ruta($borrador))->assertOk()->assertJsonPath('borrador.id', $borrador);

    $ajeno = $this->actingAs($this->asesor2, 'admin')->getJson($ruta($borrador));
    $ajeno->assertStatus(409)->assertJsonPath('codigo', 'no_es_propietario');
    expect($ajeno->getContent())->not->toContain('ClienteA')->not->toContain('Ana');

    $this->actingAs($this->asesor1, 'admin')->getJson($ruta(999999999))->assertNotFound();
});

test('P0 direcciones: solo las de un cliente con el que el actor tiene una venta propia', function () {
    cf_iniciar($this, $this->asesor1, $this->clienteA->id);
    $ruta = fn (int $id) => route('admin.ventas_asesor.nueva_venta.clientes.direcciones', ['cliente' => $id]);

    $propia = $this->actingAs($this->asesor1, 'admin')->getJson($ruta($this->clienteA->id));
    $propia->assertOk();
    expect($propia->json())->toHaveCount(1)->and($propia->getContent())->toContain('Calle secreta de Ana');

    // Cliente B: el asesor 1 nunca inicio una venta con el.
    $b = $this->actingAs($this->asesor1, 'admin')->getJson($ruta($this->clienteB->id));
    $b->assertStatus(409);
    expect($b->getContent())->not->toContain('Calle secreta');

    // Cliente A, pero otro asesor sin venta propia con el.
    $otro = $this->actingAs($this->asesor2, 'admin')->getJson($ruta($this->clienteA->id));
    $otro->assertStatus(409);
    expect($otro->getContent())->not->toContain('Calle secreta');

    // Cliente inexistente: tampoco filtra (mismo rechazo que uno ajeno).
    $this->actingAs($this->asesor1, 'admin')->getJson($ruta(999999999))->assertStatus(409);
});

test('P0 matriz: sin borrador sigue funcionando; con borrador ajeno 409; con otro cliente 422', function () {
    $borradorA = cf_iniciar($this, $this->asesor1, $this->clienteA->id);
    $ruta = route('admin.ventas_asesor.nueva_venta.productos.matriz', ['producto' => $this->configurable->id]);

    // Regresion: consulta previa a la venta, sin borrador.
    $this->actingAs($this->asesor1, 'admin')->getJson($ruta.'?customer_id='.$this->clienteA->id)->assertOk();

    // Propio y del mismo cliente.
    $this->actingAs($this->asesor1, 'admin')->getJson($ruta.'?customer_id='.$this->clienteA->id.'&borrador_id='.$borradorA)->assertOk();

    // Borrador de otro asesor.
    $this->actingAs($this->asesor2, 'admin')->getJson($ruta.'?customer_id='.$this->clienteA->id.'&borrador_id='.$borradorA)
        ->assertStatus(409)->assertJsonPath('codigo', 'no_es_propietario');

    // Propio, pero pidiendo el precio de otro cliente.
    $this->actingAs($this->asesor1, 'admin')->getJson($ruta.'?customer_id='.$this->clienteB->id.'&borrador_id='.$borradorA)
        ->assertStatus(422)->assertJsonPath('codigo', 'cliente_no_coincide');
});

test('P0 matrices por lote: mismas reglas que la matriz individual', function () {
    $borradorA = cf_iniciar($this, $this->asesor1, $this->clienteA->id);
    $ruta = route('admin.ventas_asesor.nueva_venta.matrices');
    $consulta = fn (int $cliente, ?int $borrador = null) => $ruta.'?'.http_build_query(array_filter([
        'productos' => [$this->configurable->id], 'customer_id' => $cliente, 'borrador_id' => $borrador,
    ]));

    $this->actingAs($this->asesor1, 'admin')->getJson($consulta($this->clienteA->id))->assertOk();
    $this->actingAs($this->asesor1, 'admin')->getJson($consulta($this->clienteA->id, $borradorA))->assertOk();
    $this->actingAs($this->asesor2, 'admin')->getJson($consulta($this->clienteA->id, $borradorA))->assertStatus(409);
    $this->actingAs($this->asesor1, 'admin')->getJson($consulta($this->clienteB->id, $borradorA))->assertStatus(422);
});
test('P0 agregar direccion: devuelve la lista del cliente del borrador propio y rechaza el borrador ajeno', function () {
    $borradorA = cf_iniciar($this, $this->asesor1, $this->clienteA->id);
    $ruta = route('admin.ventas_asesor.nueva_venta.direcciones.agregar', ['borrador' => $borradorA]);
    $dir = ['nombre' => 'Ana', 'apellido' => 'Prueba', 'lineas' => ['Calle 1 # 2-3'], 'municipio_codigo' => '11001', 'codigo_postal' => '110111', 'telefono' => '3001234567'];

    $this->actingAs($this->asesor2, 'admin')->postJson($ruta, $dir)->assertStatus(409);

    $propia = $this->actingAs($this->asesor1, 'admin')->postJson($ruta, $dir)->assertOk();
    expect($propia->json('direcciones'))->toHaveCount(2);
});
