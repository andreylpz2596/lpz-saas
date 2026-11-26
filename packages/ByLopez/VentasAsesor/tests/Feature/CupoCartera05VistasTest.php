<?php

use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

require_once __DIR__.'/../helpersCupo.php';

uses(TestCase::class);

/*
| Cupo de cartera (Doc. 35) — las pantallas RENDERIZAN lo que dicen los servicios y respetan los permisos: bloque en Cartera del cliente,
| tarjeta de la ficha nativa, alerta en «Mis ventas» y panel compacto en la bandeja de Cartera. Solo lpz_saas_testing o la base exclusiva.
| La verificacion visual (390 / 430 / 1366 px) se hace en el navegador real; esto cubre contenido, permisos y ausencia de errores.
*/

beforeEach(function () {
    ['admin' => $this->admin, 'cliente' => $this->cliente] = cupo_base(bloqueo: true);
    $this->urlCliente = route('admin.ventas_asesor.cartera.index', ['cliente' => $this->cliente->id]);
    // Como el arbol de Roles: marcar una hoja marca tambien su agrupadora (la que abre la pantalla).
    $this->todos = [AutorizacionVentasAsesor::PERMISO_MODULO, 'ventas_asesor.cartera', AutorizacionVentasAsesor::PERMISO_CARTERA_VER_TODA];
});

test('Cartera del cliente: el administrador ve el bloque con cifras, detalle por pedido que concilia y todas las acciones', function () {
    cupo_fijar($this->admin, $this->cliente, 2000000, 'activo', 'Cliente con buenas referencias');
    $a = cupo_pedido($this->cliente, 900000);
    $b = cupo_pedido($this->cliente, 450000);
    cupo_pagar($this->admin, $b->id, '50000');

    $r = $this->actingAs($this->admin, 'admin')->get($this->urlCliente)->assertOk();

    $r->assertSee('data-cupo-bloque', false)->assertSee('CUPO DE CARTERA', false)
        ->assertSee('data-cupo-estado="activo"', false)
        ->assertSee('$ 2.000.000', false)                 // autorizado
        ->assertSee('$ 1.300.000', false)                 // exposicion: 900.000 + (450.000 − 50.000)
        ->assertSee('$ 700.000', false)                   // disponible
        ->assertSee('65 %', false)                        // utilizacion
        ->assertSee('data-cupo-detalle', false)->assertSee('data-cupo-pedido="'.$a->id.'"', false)->assertSee('data-cupo-pedido="'.$b->id.'"', false)
        ->assertSee('data-cupo-total', false)
        ->assertSee('data-cupo-accion="editar"', false)->assertSee('data-cupo-accion="exceso"', false)->assertSee('data-cupo-accion="historial"', false)
        ->assertSee('Cliente con buenas referencias', false)
        ->assertDontSee('data-cupo-no-bloquea', false);   // el bloqueo esta activo
});

test('Cartera del cliente: EXCEDIDO muestra «CUPO EXCEDIDO EN $X» con disponible $ 0; BLOQUEADO lo dice; SIN CUPO avisa', function () {
    $this->actingAs($this->admin, 'admin')->get($this->urlCliente)->assertOk()
        ->assertSee('data-cupo-estado="sin_cupo"', false)->assertSee('data-cupo-alerta="sin_cupo"', false);

    cupo_fijar($this->admin, $this->cliente, 1000000);
    cupo_pedido($this->cliente, 1250000);

    $this->actingAs($this->admin, 'admin')->get($this->urlCliente)->assertOk()
        ->assertSee('data-cupo-estado="excedido"', false)->assertSee('CUPO EXCEDIDO EN $ 250.000', false)
        ->assertSee('data-cupo-disponible>$ 0', false);

    cupo_fijar($this->admin, $this->cliente, 1000000, 'bloqueado', 'Cheque devuelto');

    $this->actingAs($this->admin, 'admin')->get($this->urlCliente)->assertOk()
        ->assertSee('data-cupo-estado="bloqueado"', false)->assertSee('data-cupo-alerta="bloqueado"', false)->assertSee('CUPO BLOQUEADO', false);
});

test('el bloqueo apagado se avisa en la pantalla (las cifras son informativas)', function () {
    config(['ventasasesor.cupo.activo' => false]);
    cupo_fijar($this->admin, $this->cliente, 500000);

    $this->actingAs($this->admin, 'admin')->get($this->urlCliente)->assertOk()->assertSee('data-cupo-no-bloquea', false);
});

test('permisos de la pantalla: asesor con «ver» ve cifras sin botones, sin detalle y sin auditoria; sin la hoja no hay bloque; BODEGA `all` tampoco', function () {
    cupo_fijar($this->admin, $this->cliente, 2000000, 'activo', 'MOTIVO-INTERNO-DE-AUDITORIA');
    cupo_pedido($this->cliente, 600000);
    $verPropios = f03b_admin('custom', [...$this->todos, AutorizacionVentasAsesor::PERMISO_CUPO_VER]);
    $sinCupo = f03b_admin('custom', $this->todos);
    $bodegaAll = f03b_admin('all');

    $this->actingAs($verPropios, 'admin')->get($this->urlCliente)->assertOk()
        ->assertSee('data-cupo-bloque', false)->assertSee('$ 1.400.000', false)
        ->assertDontSee('data-cupo-accion', false)                 // sin botones
        ->assertDontSee('MOTIVO-INTERNO-DE-AUDITORIA', false)      // sin auditoria
        ->assertSee('data-cupo-detalle', false);                   // alcance «todos» sobre Cartera: el detalle SI

    $soloPropios = f03b_admin('custom', [AutorizacionVentasAsesor::PERMISO_MODULO, 'ventas_asesor.cartera', AutorizacionVentasAsesor::PERMISO_CARTERA_VER_PROPIA, AutorizacionVentasAsesor::PERMISO_CUPO_VER]);
    // view_own sobre un cliente que no atiende: Cartera no lo muestra; aqui solo importa que NO haya detalle de pedidos ajenos.
    $r = $this->actingAs($soloPropios, 'admin')->get($this->urlCliente);
    expect($r->getContent())->not->toContain('data-cupo-detalle');

    $this->actingAs($sinCupo, 'admin')->get($this->urlCliente)->assertOk()->assertDontSee('data-cupo-bloque', false)->assertDontSee('CUPO DE CARTERA', false);

    // Un rol `all` que no es el administrador autorizado tampoco ve el cupo (la herencia de `all` no es una asignacion).
    $this->actingAs($bodegaAll, 'admin')->get($this->urlCliente)->assertOk()->assertDontSee('data-cupo-bloque', false);
});

test('bandeja de Cartera: panel compacto con contadores, alertas y mayores saldos; solo para quien ve cupos con alcance «todos»', function () {
    $excedido = pagb_cliente();
    $cerca = pagb_cliente();
    $bloqueado = pagb_cliente();
    cupo_fijar($this->admin, $excedido, 100000);
    cupo_pedido($excedido, 160000);
    cupo_fijar($this->admin, $cerca, 1000000);
    cupo_pedido($cerca, 850000);
    cupo_fijar($this->admin, $bloqueado, 500000, 'bloqueado', 'Mora');
    cupo_fijar($this->admin, $this->cliente, 300000);

    $r = $this->actingAs($this->admin, 'admin')->get(route('admin.ventas_asesor.cartera.index'))->assertOk();
    $r->assertSee('data-cupo-panel', false)->assertSee('data-cupo-panel-excedidos>1', false)->assertSee('data-cupo-panel-bloqueados>1', false)
        ->assertSee('data-cupo-panel-cerca>1', false)->assertSee('data-cupo-panel-con-cupo>4', false)
        ->assertSee('data-cupo-panel-fila="'.$excedido->id.'"', false)->assertSee('data-cupo-panel-fila="'.$bloqueado->id.'"', false)
        ->assertSee('data-cupo-panel-fila="'.$cerca->id.'"', false)
        ->assertSee('$ 1.010.000', false);   // dinero expuesto: 160.000 + 850.000

    $verSinTodos = f03b_admin('custom', [AutorizacionVentasAsesor::PERMISO_MODULO, 'ventas_asesor.cartera', AutorizacionVentasAsesor::PERMISO_CARTERA_VER_PROPIA, AutorizacionVentasAsesor::PERMISO_CUPO_VER]);
    $sinCupo = f03b_admin('custom', $this->todos);

    $this->actingAs($verSinTodos, 'admin')->get(route('admin.ventas_asesor.cartera.index'))->assertOk()->assertDontSee('data-cupo-panel', false);
    $this->actingAs($sinCupo, 'admin')->get(route('admin.ventas_asesor.cartera.index'))->assertOk()->assertDontSee('data-cupo-panel', false);
});

test('«Mis ventas»: alerta junto al cliente solo cuando esta cerca, agotado, excedido o bloqueado (un cliente activo no lleva alerta)', function () {
    $alerta = pagb_cliente();
    $tranquilo = pagb_cliente();
    cupo_fijar($this->admin, $alerta, 100000);
    $pedidoAlerta = cupo_pedido($alerta, 150000);
    cupo_fijar($this->admin, $tranquilo, 5000000);
    $pedidoTranquilo = cupo_pedido($tranquilo, 100000);
    pagb_atribuir($pedidoAlerta, $this->admin);
    pagb_atribuir($pedidoTranquilo, $this->admin);
    $url = route('admin.ventas_asesor.pedidos.index');

    $html = $this->actingAs($this->admin, 'admin')->get($url)->assertOk()->getContent();

    expect($html)->toContain('data-cupo-alerta-fila="excedido"')->and(substr_count($html, 'data-cupo-alerta-fila="excedido"'))->toBeGreaterThanOrEqual(1)
        ->and($html)->not->toContain('data-cupo-alerta-fila="activo"');

    $sinVer = f03b_admin('custom', [AutorizacionVentasAsesor::PERMISO_MODULO, 'ventas_asesor.pedidos', AutorizacionVentasAsesor::PERMISO_PEDIDOS_VER_TODOS]);
    expect($this->actingAs($sinVer, 'admin')->get($url)->assertOk()->getContent())->not->toContain('data-cupo-alerta-fila');
});

test('ficha nativa del cliente: la tarjeta de Cartera lleva la linea de cupo solo con la hoja de ver', function () {
    cupo_fijar($this->admin, $this->cliente, 800000);
    cupo_pedido($this->cliente, 200000);
    $url = route('admin.customers.customers.view', ['id' => $this->cliente->id]);

    $this->actingAs($this->admin, 'admin')->get($url)->assertOk()
        ->assertSee('data-tarjeta-cupo="activo"', false)->assertSee('disponible $ 600.000 de $ 800.000', false);

    $sinCupo = f03b_admin('custom', [...$this->todos, 'customers.customers']);
    $this->actingAs($sinCupo, 'admin')->get($url)->assertOk()->assertDontSee('data-tarjeta-cupo', false)->assertSee('data-tarjeta-cartera', false);
});
