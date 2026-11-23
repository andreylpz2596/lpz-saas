<?php

use ByLopez\VentasAsesor\Services\Venta\BorradorVentaService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use Tests\TestCase;
use Webkul\User\Models\Admin;

require_once __DIR__.'/../helpersFase03f.php';

uses(TestCase::class);

/*
| 03G-D: la pantalla HTML de "Nueva venta rapida". Solo verifica lo que Pest puede probar
| sin ejecutar JavaScript: que la ruta renderiza, que el ACL real la protege igual que la
| API 03G-C, y que el Vue recibe los datos de arranque (grupos) sin filtrar el invitado.
| El recorrido interactivo (clicks, inputs, axios) no corre aqui: queda para revision manual
| o Playwright, que este modulo no tiene configurado todavia.
*/

function f03gd_asesor(): Admin
{
    // La pantalla de Roles marca SIEMPRE la clave agrupadora al marcar una hoja (ventas_asesor.pedidos para ventas_asesor.pedidos.create). Sin ella el
    // menu de Bagisto no puede armarse (`Undefined array key "key"` en Webkul\Core\Menu) y toda pagina del panel responde 500: causa demostrada del
    // fallo de esta prueba, que armaba un rol que ninguna persona puede crear desde la pantalla de Roles.
    return f03b_admin('custom', [AutorizacionVentasAsesor::PERMISO_MODULO, 'ventas_asesor.pedidos', BorradorVentaService::PERMISO]);
}

test('la pantalla renderiza para un asesor con el permiso operativo del modulo', function () {
    f03f_configurarBase();
    $grupo = f03b_grupo('G');

    $this->actingAs(f03gd_asesor(), 'admin')
        ->get(route('admin.ventas_asesor.nueva_venta.pantalla'))
        ->assertOk()
        ->assertViewIs('ventasasesor::admin.nueva-venta.index')
        ->assertSee('v-nueva-venta', false)
        ->assertSee('CONFIRMAR VENTA')
        ->assertSee($grupo->name)
        ->assertDontSee('Invitado');
});

test('las matrices viven en una modal lateral izquierda (x-admin::drawer) con boton "Ver tallas" y una sola accion de agregar', function () {
    f03f_configurarBase();
    f03b_grupo('G');

    $this->actingAs(f03gd_asesor(), 'admin')
        ->get(route('admin.ventas_asesor.nueva_venta.pantalla'))
        ->assertOk()
        // El drawer core de Bagisto, abierto desde la izquierda, con la referencia que usa verTallas().
        ->assertSee('ref="drawerMatrices"', false)
        ->assertSee('position="left"', false)
        ->assertSee('@close="alCerrarMatrices"', false)
        ->assertSee('Ver tallas')
        // Una sola accion para toda la seleccion (footer fijo), no un boton por matriz.
        ->assertSee('Agregar productos al carrito')
        ->assertDontSee('ACTUALIZAR VENTA');
});

test('sin autenticar redirige al login; sin el permiso del modulo da 401', function () {
    $this->get(route('admin.ventas_asesor.nueva_venta.pantalla'))
        ->assertRedirect(route('admin.session.create'));

    $this->actingAs(f03b_admin('custom', ['dashboard']), 'admin')
        ->get(route('admin.ventas_asesor.nueva_venta.pantalla'))
        ->assertUnauthorized();
});

test('en-curso: lista los borradores activos del asesor autenticado (BorradorVentaService::listarDelPropietario)', function () {
    f03f_configurarBase();
    $asesor = f03gd_asesor();
    $cliente = f03b_cliente(f03b_grupo('G'), ['first_name' => 'Ana', 'last_name' => 'Curso']);

    // P7: una venta en curso nace con su primera prenda; la vacia (solo iniciar) no se lista.
    $vacia = $this->actingAs($asesor, 'admin')
        ->postJson(route('admin.ventas_asesor.nueva_venta.iniciar'), ['customer_id' => $cliente->id, 'canal' => 'whatsapp'])
        ->assertOk()->json('borrador.id');

    $configurable = f03b_configurable(10000.0);
    $variante = $configurable->variants()->first();
    f03b_stock($variante, 20);
    $this->actingAs($asesor, 'admin')
        ->postJson(route('admin.ventas_asesor.nueva_venta.iniciar_con_tallas'), [
            'customer_id' => $cliente->id, 'canal' => 'whatsapp', 'referencia' => 'ref-prueba-en-curso',
            'producto_id' => $configurable->id, 'cantidades' => [$variante->id => 1],
        ])->assertOk();

    $respuesta = $this->actingAs($asesor, 'admin')
        ->getJson(route('admin.ventas_asesor.nueva_venta.en_curso'))
        ->assertOk()
        ->json();

    expect($respuesta)->toHaveCount(1)
        ->and($respuesta[0]['cliente']['nombre'])->toBe('Ana')
        ->and($respuesta[0]['estado'])->toBe('activo');
});
