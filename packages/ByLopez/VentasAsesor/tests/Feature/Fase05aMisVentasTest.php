<?php

use ByLopez\VentasAsesor\Enums\CanalVenta;
use ByLopez\VentasAsesor\Enums\EstadoAtribucion;
use ByLopez\VentasAsesor\Enums\MecanismoCreacion;
use ByLopez\VentasAsesor\Enums\OrigenPropiedadComercial;
use ByLopez\VentasAsesor\Enums\TipoPropiedadComercial;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor as Autz;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Webkul\Sales\Models\Order;
use Webkul\User\Models\Admin;

require_once __DIR__.'/../helpersFase03b.php';

uses(TestCase::class);

/*
| OPERACION ASESOR — "Mis ventas / Pedidos". La proteccion vive en backend: el alcance se
| aplica en SQL (ConsultaPedidosAsesorService::visibles) y el detalle se autoriza registro a
| registro (AutorizacionVentasAsesor::puedeVerRegistroDe). Casos de atribucion:
| A = advisor+completa, B = company+completa, C = unassigned+sin_asesor, y pedido sin fila.
*/

function f05a_asesor(string $nombre): Admin
{
    // El arbol de Roles marca la clave agrupadora al marcar una hoja: se reproduce aqui.
    $admin = f03b_admin('custom', [Autz::PERMISO_MODULO, 'ventas_asesor.pedidos', Autz::PERMISO_PEDIDOS_VER_PROPIOS, Autz::PERMISO_OPERAR_COMO_ASESOR]);
    $admin->update(['name' => $nombre]);

    return $admin;
}

function f05a_supervisor(): Admin
{
    return f03b_admin('custom', [Autz::PERMISO_MODULO, 'ventas_asesor.pedidos', Autz::PERMISO_PEDIDOS_VER_TODOS]);
}

function f05a_pedido(string $cliente, string $telefono, array $extra = []): Order
{
    $pedido = Order::factory()->create(array_merge([
        'customer_first_name' => $cliente, 'customer_last_name' => 'F05A',
        'grand_total' => 100000, 'base_grand_total' => 100000, 'total_qty_ordered' => 3,
        'order_currency_code' => 'COP', 'base_currency_code' => 'COP',
    ], $extra));

    DB::table('addresses')->insert([
        'address_type' => 'order_shipping', 'order_id' => $pedido->id,
        'first_name' => $cliente, 'last_name' => 'F05A', 'address' => 'Calle 1', 'city' => 'Cali',
        'phone' => $telefono, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $pedido;
}

function f05a_atribuir(Order $pedido, string $tipo, ?Admin $asesor = null): void
{
    DB::table('bylopez_va_order_attributions')->insert([
        'order_id' => $pedido->id,
        'advisor_id' => $asesor?->id,
        'advisor_name_snapshot' => $asesor?->name,
        'commercial_owner_type' => $tipo,
        'commercial_owner_source' => match ($tipo) {
            TipoPropiedadComercial::ASESOR->value => OrigenPropiedadComercial::AUTO_ASESOR->value,
            TipoPropiedadComercial::EMPRESA->value => OrigenPropiedadComercial::REGLA_STOREFRONT->value,
            default => OrigenPropiedadComercial::SIN_CONTEXTO->value,
        },
        'sales_channel' => CanalVenta::WHATSAPP->value,
        'creation_source' => MecanismoCreacion::VENTAS_ASESOR->value,
        'attribution_status' => $tipo === TipoPropiedadComercial::SIN_ASIGNAR->value
            ? EstadoAtribucion::SIN_ASESOR->value : EstadoAtribucion::COMPLETA->value,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** Escenario: A y B con un pedido cada uno, uno de empresa, uno sin asignar y uno sin fila. */
function f05a_escenario(): array
{
    $a = f05a_asesor('Asesora Alfa');
    $b = f05a_asesor('Asesor Beta');

    $pa = f05a_pedido('ClienteAlfa', '3001112233');
    $pb = f05a_pedido('ClienteBeta', '3004445566', ['status' => 'processing']);
    $pe = f05a_pedido('ClienteEmpresa', '3007778899');
    $ps = f05a_pedido('ClienteSinAsignar', '3000001111');
    $ph = f05a_pedido('ClienteHistorico', '3000002222');

    f05a_atribuir($pa, TipoPropiedadComercial::ASESOR->value, $a);
    f05a_atribuir($pb, TipoPropiedadComercial::ASESOR->value, $b);
    f05a_atribuir($pe, TipoPropiedadComercial::EMPRESA->value);
    f05a_atribuir($ps, TipoPropiedadComercial::SIN_ASIGNAR->value);

    return compact('a', 'b', 'pa', 'pb', 'pe', 'ps', 'ph');
}

function f05a_idsListados($respuesta): array
{
    return $respuesta->viewData('pedidos')->getCollection()->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
}

test('A ve solo su pedido y B solo el suyo (ni empresa, ni sin asignar, ni sin atribucion)', function () {
    $e = f05a_escenario();

    $ra = $this->actingAs($e['a'], 'admin')->get(route('admin.ventas_asesor.pedidos.index'))
        ->assertOk()
        ->assertViewIs('ventasasesor::admin.pedidos.index')
        ->assertSee('ClienteAlfa')
        ->assertDontSee('ClienteBeta')->assertDontSee('ClienteEmpresa')
        ->assertDontSee('ClienteSinAsignar')->assertDontSee('ClienteHistorico')
        ->assertSee('3001112233');

    expect(f05a_idsListados($ra))->toBe([$e['pa']->id])
        ->and($ra->viewData('verTodos'))->toBeFalse();

    $rb = $this->actingAs($e['b'], 'admin')->get(route('admin.ventas_asesor.pedidos.index'))->assertOk();

    expect(f05a_idsListados($rb))->toBe([$e['pb']->id]);
});

test('A no puede ver el pedido de B por URL (?pedido) ni ampliar el alcance con ?asesor', function () {
    $e = f05a_escenario();

    $this->actingAs($e['a'], 'admin')
        ->get(route('admin.ventas_asesor.pedidos.index', ['pedido' => $e['pb']->id]))
        ->assertForbidden();

    // Empresa, sin asignar y sin atribucion tampoco (RA-6: falla cerrado).
    foreach (['pe', 'ps', 'ph'] as $clave) {
        $this->actingAs($e['a'], 'admin')
            ->get(route('admin.ventas_asesor.pedidos.index', ['pedido' => $e[$clave]->id]))
            ->assertForbidden();
    }

    // Su propio pedido si.
    $this->actingAs($e['a'], 'admin')
        ->get(route('admin.ventas_asesor.pedidos.index', ['pedido' => $e['pa']->id]))
        ->assertOk()
        ->assertViewIs('ventasasesor::admin.pedidos.detalle')
        ->assertSee('ClienteAlfa')
        ->assertDontSee('Asesora Alfa'); // el propietario solo se muestra con view_all

    // ?asesor=<id de B> o ?asesor=empresa se ignoran con view_own.
    foreach ([(string) $e['b']->id, 'empresa', 'sin_atribucion'] as $asesor) {
        $r = $this->actingAs($e['a'], 'admin')
            ->get(route('admin.ventas_asesor.pedidos.index', ['asesor' => $asesor]))
            ->assertOk();

        expect(f05a_idsListados($r))->toBe([$e['pa']->id]);
    }

    // Pedido inexistente → 404.
    $this->actingAs($e['a'], 'admin')
        ->get(route('admin.ventas_asesor.pedidos.index', ['pedido' => 999999999]))
        ->assertNotFound();
});

test('sin el permiso del modulo → 401 (Bouncer); con el modulo pero sin view_own/view_all → 403', function () {
    $this->get(route('admin.ventas_asesor.pedidos.index'))
        ->assertRedirect(route('admin.session.create'));

    $this->actingAs(f03b_admin('custom', ['dashboard']), 'admin')
        ->get(route('admin.ventas_asesor.pedidos.index'))
        ->assertUnauthorized();

    // Solo modulo + clave agrupadora (sin hoja de visibilidad): el controlador niega.
    $this->actingAs(f03b_admin('custom', [Autz::PERMISO_MODULO, 'ventas_asesor.pedidos', Autz::PERMISO_OPERAR_COMO_ASESOR]), 'admin')
        ->get(route('admin.ventas_asesor.pedidos.index'))
        ->assertForbidden();

    // Y el detalle tampoco.
    $pedido = f05a_pedido('ClienteNadie', '3009990000');

    $this->actingAs(f03b_admin('custom', [Autz::PERMISO_MODULO, 'ventas_asesor.pedidos']), 'admin')
        ->get(route('admin.ventas_asesor.pedidos.index', ['pedido' => $pedido->id]))
        ->assertForbidden();
});

test('view_all ve todos los casos rotulados y filtra por asesor y propiedad', function () {
    $e = f05a_escenario();

    foreach ([f05a_supervisor(), f03b_admin('all')] as $admin) {
        $r = $this->actingAs($admin, 'admin')
            ->get(route('admin.ventas_asesor.pedidos.index', ['q' => 'F05A']))
            ->assertOk()
            ->assertSee('Asesora Alfa')->assertSee('Asesor Beta')
            ->assertSee('Empresa')->assertSee('Sin asignar')->assertSee('Sin atribución');

        $ids = [$e['pa']->id, $e['pb']->id, $e['pe']->id, $e['ps']->id, $e['ph']->id];
        sort($ids);

        expect(f05a_idsListados($r))->toBe($ids)
            ->and($r->viewData('verTodos'))->toBeTrue();
    }

    $sup = f05a_supervisor();
    $filtrar = fn (string $asesor) => f05a_idsListados($this->actingAs($sup, 'admin')
        ->get(route('admin.ventas_asesor.pedidos.index', ['q' => 'F05A', 'asesor' => $asesor]))->assertOk());

    expect($filtrar((string) $e['b']->id))->toBe([$e['pb']->id])
        ->and($filtrar('empresa'))->toBe([$e['pe']->id])
        ->and($filtrar('sin_asignar'))->toBe([$e['ps']->id])
        ->and($filtrar('sin_atribucion'))->toBe([$e['ph']->id]);

    // Detalle de cualquiera, con propietario visible.
    $this->actingAs($sup, 'admin')
        ->get(route('admin.ventas_asesor.pedidos.index', ['pedido' => $e['ph']->id]))
        ->assertOk()
        ->assertSee('Sin atribución');
});

test('filtros backend: texto (pedido, cliente, telefono), estado, entrega y fechas; validacion estructural', function () {
    $e = f05a_escenario();
    $sup = f05a_supervisor();
    $listar = fn (array $q) => f05a_idsListados($this->actingAs($sup, 'admin')
        ->get(route('admin.ventas_asesor.pedidos.index', $q))->assertOk());

    expect($listar(['q' => 'ClienteBeta']))->toBe([$e['pb']->id])
        ->and($listar(['q' => '300 444 5566']))->toBe([$e['pb']->id])
        ->and($listar(['q' => 'F05A', 'estado' => 'processing']))->toBe([$e['pb']->id])
        ->and($listar(['q' => 'F05A', 'entrega' => 'inexistente_x']))->toBe([])
        ->and($listar(['q' => 'F05A', 'hasta' => now()->subDays(2)->format('Y-m-d')]))->toBe([]);

    expect($listar(['q' => (string) $e['pa']->increment_id]))->toContain($e['pa']->id);

    // Un estado inventado o un asesor mal formado no se aceptan.
    $this->actingAs($sup, 'admin')
        ->get(route('admin.ventas_asesor.pedidos.index', ['estado' => 'entregado']))
        ->assertSessionHasErrors('estado');

    $this->actingAs($sup, 'admin')
        ->get(route('admin.ventas_asesor.pedidos.index', ['asesor' => '1 OR 1=1']))
        ->assertSessionHasErrors('asesor');
});

test('estados derivados: pago por facturas/transacciones y despacho por unidades enviadas', function () {
    $a = f05a_asesor('Asesora Pago');
    $pedido = f05a_pedido('ClientePago', '3001231234', ['status' => 'processing']);
    f05a_atribuir($pedido, TipoPropiedadComercial::ASESOR->value, $a);

    $facturaId = DB::table('invoices')->insertGetId([
        'order_id' => $pedido->id, 'state' => 'paid', 'total_qty' => 3,
        'base_grand_total' => 100000, 'grand_total' => 100000,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('order_transactions')->insert([
        'transaction_id' => 'f05a-'.uniqid(), 'payment_method' => 'moneytransfer', 'amount' => 40000,
        'invoice_id' => $facturaId, 'order_id' => $pedido->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('order_items')->insert([
        'order_id' => $pedido->id, 'sku' => 'F05A-1', 'type' => 'simple', 'name' => 'Item F05A',
        'qty_ordered' => 3, 'qty_shipped' => 1, 'qty_canceled' => 0,
        'price' => 33333, 'base_price' => 33333, 'total' => 100000, 'base_total' => 100000,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $fila = $this->actingAs($a, 'admin')->get(route('admin.ventas_asesor.pedidos.index'))
        ->assertOk()->viewData('pedidos')->getCollection()->first();

    expect($fila->estado_pago)->toBe('parcial')
        ->and($fila->estado_despacho)->toBe('parcial');

    $this->actingAs($a, 'admin')->get(route('admin.ventas_asesor.pedidos.index', ['pedido' => $pedido->id]))
        ->assertOk()
        ->assertSee('Item F05A')
        ->assertViewHas('estado_pago', 'parcial')
        ->assertViewHas('saldo', 60000.0);
});

test('KPI pendientes de pago: cuenta exactamente las filas con Pago = pendiente, dentro del alcance', function () {
    $a = f05a_asesor('Asesora Kpi');
    $b = f05a_asesor('Asesor Ajeno');

    $sinPago = f05a_pedido('KpiSinPago', '3001000001');
    $parcial = f05a_pedido('KpiParcial', '3001000002', ['status' => 'processing']);
    $cancelado = f05a_pedido('KpiCancelado', '3001000003', ['status' => 'canceled']);
    $pagadaSinEvidencia = f05a_pedido('KpiRevisar', '3001000004');
    $ajeno = f05a_pedido('KpiAjeno', '3001000005');

    foreach ([$sinPago, $parcial, $cancelado, $pagadaSinEvidencia] as $p) {
        f05a_atribuir($p, TipoPropiedadComercial::ASESOR->value, $a);
    }
    f05a_atribuir($ajeno, TipoPropiedadComercial::ASESOR->value, $b);

    $factura = fn (Order $p, string $estado) => DB::table('invoices')->insertGetId([
        'order_id' => $p->id, 'state' => $estado, 'total_qty' => 3,
        'base_grand_total' => 100000, 'grand_total' => 100000, 'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('order_transactions')->insert([
        'transaction_id' => 'f05a-kpi-'.uniqid(), 'payment_method' => 'moneytransfer', 'amount' => 40000,
        'invoice_id' => $factura($parcial, 'pending'), 'order_id' => $parcial->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $factura($pagadaSinEvidencia, 'paid');

    $r = $this->actingAs($a, 'admin')->get(route('admin.ventas_asesor.pedidos.index'))->assertOk();
    $estados = $r->viewData('pedidos')->getCollection()->pluck('estado_pago', 'id')->all();

    expect($estados)->toEqual([
        $sinPago->id => 'pendiente', $parcial->id => 'parcial',
        $cancelado->id => 'no_aplica', $pagadaSinEvidencia->id => 'revisar',
    ])
        ->and($r->viewData('indicadores')['pendientes_pago'])->toBe(1)
        ->and($r->viewData('indicadores')['pendientes_pago'])
        ->toBe(collect($estados)->filter(fn ($e) => $e === 'pendiente')->count());
});

test('KPI ventas de hoy: excluye cancelados, fraude y cerrados; incluye pendientes, procesando y completados; solo hoy y dentro del alcance', function () {
    $a = f05a_asesor('Asesora Hoy');
    $b = f05a_asesor('Asesor Otro');

    foreach (['pending' => 1000, 'processing' => 2000, 'completed' => 4000, 'canceled' => 8000, 'fraud' => 16000, 'closed' => 32000] as $estado => $total) {
        f05a_atribuir(f05a_pedido('Hoy'.$estado, '3002000000', ['status' => $estado, 'grand_total' => $total, 'base_grand_total' => $total]), TipoPropiedadComercial::ASESOR->value, $a);
    }
    f05a_atribuir(f05a_pedido('Ayer', '3002000001', ['created_at' => now()->subDay(), 'base_grand_total' => 64000]), TipoPropiedadComercial::ASESOR->value, $a);
    f05a_atribuir(f05a_pedido('DeOtro', '3002000002', ['base_grand_total' => 128000]), TipoPropiedadComercial::ASESOR->value, $b);

    $kpi = $this->actingAs($a, 'admin')->get(route('admin.ventas_asesor.pedidos.index'))
        ->assertOk()->viewData('indicadores');

    expect($kpi['hoy_cantidad'])->toBe(3)
        ->and($kpi['hoy_total'])->toBe(7000.0);
});

test('KPI por despachar (D-56): cuenta unidades fisicas pendientes (sin despachar + parcial), tambien en completed con envio parcial, dentro del alcance', function () {
    $a = f05a_asesor('Asesora Bodega');
    $b = f05a_asesor('Asesor Ajeno Bodega');

    $linea = fn (Order $p, int $pedidas, int $enviadas) => DB::table('order_items')->insert([
        'order_id' => $p->id, 'sku' => 'F05A-D-'.$p->id, 'type' => 'simple', 'name' => 'Prenda',
        'qty_ordered' => $pedidas, 'qty_shipped' => $enviadas, 'qty_canceled' => 0,
        'price' => 10000, 'base_price' => 10000, 'total' => 10000 * $pedidas, 'base_total' => 10000 * $pedidas,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $casos = [
        'sinDespachar' => ['processing', 0],          // CASO 1 → entra
        'parcial' => ['processing', 5],               // CASO 2 → entra
        'despachado' => ['completed', 10],            // CASO 3 → no entra
        'cancelado' => ['canceled', 0],               // CASO 4 → no entra
        'cerrado' => ['closed', 0],                   // CASO 5 → no entra
        'fraude' => ['fraud', 0],                     // CASO 6 → no entra
        // Hallazgo: TransactionController de Bagisto marca `completed` al pagar completo si existe
        // CUALQUIER Shipment, aunque sea parcial. Estado de datos resultante → entra.
        'completoConParcial' => ['completed', 5],
    ];

    $pedidos = [];

    foreach ($casos as $clave => [$estado, $enviadas]) {
        $pedidos[$clave] = f05a_pedido('Desp'.$clave, '3003000000', ['status' => $estado]);
        $linea($pedidos[$clave], 10, $enviadas);
        f05a_atribuir($pedidos[$clave], TipoPropiedadComercial::ASESOR->value, $a);
    }

    // CASO 7: pedidos de B sin despachar no suman al KPI de A.
    foreach ([1, 2] as $i) {
        $ajeno = f05a_pedido('DespAjeno'.$i, '3003000001', ['status' => 'processing']);
        $linea($ajeno, 10, 0);
        f05a_atribuir($ajeno, TipoPropiedadComercial::ASESOR->value, $b);
    }

    $r = $this->actingAs($a, 'admin')->get(route('admin.ventas_asesor.pedidos.index'))->assertOk();
    $despacho = $r->viewData('pedidos')->getCollection()->pluck('estado_despacho', 'id')->all();

    expect($despacho)->toEqual([
        $pedidos['sinDespachar']->id => 'pendiente',
        $pedidos['parcial']->id => 'parcial',
        $pedidos['despachado']->id => 'despachado',
        $pedidos['cancelado']->id => 'no_aplica',
        $pedidos['cerrado']->id => 'no_aplica',
        $pedidos['fraude']->id => 'no_aplica',
        $pedidos['completoConParcial']->id => 'parcial',
    ])
        // Misma fuente que la columna: KPI = filas con Despacho «sin despachar» o «parcial».
        ->and($r->viewData('indicadores')['por_despachar'])->toBe(3)
        ->and($r->viewData('indicadores')['por_despachar'])
        ->toBe(collect($despacho)->filter(fn ($e) => in_array($e, ['pendiente', 'parcial'], true))->count());

    expect($this->actingAs($b, 'admin')->get(route('admin.ventas_asesor.pedidos.index'))
        ->assertOk()->viewData('indicadores')['por_despachar'])->toBe(2);
});

test('sin N+1: el numero de consultas no crece con el tamano de la pagina', function () {
    $a = f05a_asesor('Asesora Volumen');

    $contar = function () use ($a) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($a, 'admin')->get(route('admin.ventas_asesor.pedidos.index'))->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    f05a_atribuir(f05a_pedido('Vol0', '3005550000'), TipoPropiedadComercial::ASESOR->value, $a);
    $contar(); // calentamiento: canal, locales y configuracion quedan en memoria del core
    $conUno = $contar();

    for ($i = 1; $i <= 12; $i++) {
        f05a_atribuir(f05a_pedido('Vol'.$i, '30055500'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)), TipoPropiedadComercial::ASESOR->value, $a);
    }

    expect($contar())->toBe($conUno);
});

test('KPI clicables: cada tarjeta filtra con ?resumen= exactamente los pedidos que su cifra cuenta, y la tarjeta activa se marca', function () {
    $a = f05a_asesor('Asesora Resumen');
    $b = f05a_asesor('Asesor Resumen Ajeno');

    $linea = fn (Order $p, int $enviadas) => DB::table('order_items')->insert([
        'order_id' => $p->id, 'sku' => 'F05A-R-'.$p->id, 'type' => 'simple', 'name' => 'Prenda',
        'qty_ordered' => 10, 'qty_shipped' => $enviadas, 'qty_canceled' => 0,
        'price' => 10000, 'base_price' => 10000, 'total' => 100000, 'base_total' => 100000,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $hoySinDespachar = f05a_pedido('RHoy', '3002000001', ['status' => 'processing']);
    $viejoDespachado = f05a_pedido('RViejo', '3002000002', ['status' => 'completed', 'created_at' => now()->subDays(3)]);
    $cancelado = f05a_pedido('RCancelado', '3002000003', ['status' => 'canceled']);
    $ajeno = f05a_pedido('RAjeno', '3002000004', ['status' => 'processing']);

    $linea($hoySinDespachar, 0);
    $linea($viejoDespachado, 10);
    $linea($cancelado, 0);
    $linea($ajeno, 0);

    foreach ([$hoySinDespachar, $viejoDespachado, $cancelado] as $p) {
        f05a_atribuir($p, TipoPropiedadComercial::ASESOR->value, $a);
    }
    f05a_atribuir($ajeno, TipoPropiedadComercial::ASESOR->value, $b);

    $listar = fn (?string $resumen) => $this->actingAs($a, 'admin')
        ->get(route('admin.ventas_asesor.pedidos.index', array_filter(['resumen' => $resumen])))->assertOk();

    // La cifra de cada tarjeta y los pedidos del filtro salen del MISMO criterio.
    foreach ([
        'hoy' => 'hoy_cantidad', 'abiertos' => 'abiertos',
        'por_despachar' => 'por_despachar', 'pendientes_pago' => 'pendientes_pago',
    ] as $resumen => $kpi) {
        $r = $listar($resumen);

        expect(count(f05a_idsListados($r)))->toBe($r->viewData('indicadores')[$kpi], $resumen);
    }

    expect(f05a_idsListados($listar('hoy')))->toEqual([$hoySinDespachar->id])
        ->and(f05a_idsListados($listar('por_despachar')))->toEqual([$hoySinDespachar->id])
        ->and(f05a_idsListados($listar('abiertos')))->toEqual([$hoySinDespachar->id])
        ->and(f05a_idsListados($listar(null)))->toHaveCount(3);

    $listar('por_despachar')->assertSee('data-resumen-activo="por_despachar"', false)->assertSee('aria-current="true"', false);
    $listar(null)->assertDontSee('data-resumen-activo', false);
});

test('el filtro de tarjeta convive con los filtros existentes (AND) y nunca amplia el alcance del asesor ni acepta valores desconocidos', function () {
    $a = f05a_asesor('Asesora Conv');
    $b = f05a_asesor('Asesor Conv Ajeno');

    $mio = f05a_pedido('ConvMio', '3002100001', ['status' => 'processing', 'shipping_method' => 'flatrate_flatrate']);
    $otro = f05a_pedido('ConvOtro', '3002100002', ['status' => 'processing', 'shipping_method' => 'free_free']);
    $ajeno = f05a_pedido('ConvAjeno', '3002100003', ['status' => 'processing', 'shipping_method' => 'flatrate_flatrate']);

    f05a_atribuir($mio, TipoPropiedadComercial::ASESOR->value, $a);
    f05a_atribuir($otro, TipoPropiedadComercial::ASESOR->value, $a);
    f05a_atribuir($ajeno, TipoPropiedadComercial::ASESOR->value, $b);

    $ids = fn (array $query) => f05a_idsListados($this->actingAs($a, 'admin')->get(route('admin.ventas_asesor.pedidos.index', $query))->assertOk());

    expect($ids(['resumen' => 'abiertos']))->toEqual([$mio->id, $otro->id])
        ->and($ids(['resumen' => 'abiertos', 'entrega' => 'flatrate_flatrate']))->toEqual([$mio->id])
        ->and($ids(['resumen' => 'abiertos', 'q' => 'ConvMio']))->toEqual([$mio->id])
        // Un valor de resumen desconocido es invalido (la peticion se rechaza, no se ignora en silencio).
        ->and($this->actingAs($a, 'admin')->get(route('admin.ventas_asesor.pedidos.index', ['resumen' => 'todo']))->status())->not->toBe(200);
});
