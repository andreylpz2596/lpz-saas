<?php

use ByLopez\VentasAsesor\Models\CambioCostoComisionable;
use ByLopez\VentasAsesor\Models\Pago;
use ByLopez\VentasAsesor\Support\Costos\CostoComisionableCatalogo;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

require_once __DIR__.'/../helpersCom.php';
require_once __DIR__.'/../helpersPagB.php';

uses(TestCase::class);

/*
| Mascara monetaria VISUAL en VentasAsesor: lo que el usuario ve y escribe cambia ($ 100.000); lo que se guarda no. Aqui se prueba lo
| que entrega el servidor: las vistas (campos enmascarados) y, sobre todo, el GUARDADO con exactamente los valores crudos que produce
| la mascara ("120000", "51000.5"), con su ida y vuelta. La logica del navegador la cubren tests/js/moneda*.mjs de CorteConfeccion.
*/

beforeEach(function () {
    Mail::fake();
});

const MONEDA_PERMISOS = ['ventas_asesor', 'ventas_asesor.cartera', 'ventas_asesor.cartera.registrar', 'ventas_asesor.cartera.view_all', 'ventas_asesor.pedidos', 'ventas_asesor.pedidos.view_all'];

test('M-1 detalle del pedido: los montos del modal de pago son campos enmascarados y siguen enlazados al valor crudo', function () {
    pagb_corte('2000-01-01');
    $pedido = pagb_pedido(pagb_cliente(), 500000);
    $html = $this->actingAs(pagb_asesor(MONEDA_PERMISOS), 'admin')
        ->get(route('admin.ventas_asesor.pedidos.index', ['pedido' => $pedido->id]))
        ->assertOk()
        ->getContent();

    // El componente y la libreria compartida estan en la pagina (una sola vez).
    expect(substr_count($html, 'window.ByLopezMoneda'))->toBeGreaterThan(0)
        ->and(substr_count($html, "app.component('v-moneda'"))->toBe(1)
        // Monto unico y monto por parte (pago combinado): enmascarados, con el mismo modelo de siempre.
        ->and($html)->toContain('v-model="form.monto"')
        ->and($html)->toContain('v-model="parte.monto"')
        ->and($html)->toMatch('/<v-moneda[^>]*v-model="form\.monto"[^>]*><\/v-moneda>/s')
        ->and($html)->toMatch('/<v-moneda[^>]*v-model="parte\.monto"[^>]*><\/v-moneda>/s')
        // Los campos de entrada ya no son inputs de texto sueltos con ese modelo.
        ->and($html)->not->toMatch('/<input[^>]*v-model="form\.monto"/')
        ->and($html)->not->toMatch('/<input[^>]*v-model="parte\.monto"/')
        // El valor inicial sigue siendo el saldo crudo (String(saldo)), sin formato.
        ->and($html)->toContain('monto: String(this.saldo)')
        // Los componentes se cierran de forma explicita: dentro de un x-template el HTML no admite <v-moneda />.
        ->and($html)->not->toMatch('/<v-moneda[^>]*\/>/');
});

test('M-2 pago: el servidor guarda EXACTAMENTE el valor crudo que envia la mascara (ida y vuelta)', function () {
    pagb_corte('2000-01-01');
    $pedido = pagb_pedido(pagb_cliente(), 500000);
    $url = route('admin.ventas_asesor.pedidos.pagos.store', ['pedido' => $pedido->id]);
    $admin = pagb_asesor(MONEDA_PERMISOS);
    $base = ['medio_cobro' => 'transferencia', 'plataforma' => 'nequi', 'fecha_pago' => now()->toDateString()];

    // Se ve «$ 120.000» y se envia "120000".
    $this->actingAs($admin, 'admin')->postJson($url, $base + ['monto' => '120000', 'referencia' => 'TRX-1', 'idempotency_key' => 'moneda-clave-0001'])
        ->assertCreated()->assertJsonPath('saldo.saldo', 380000);

    // Con decimales: «$ 51.000,50» se envia "51000.50".
    $this->actingAs($admin, 'admin')->postJson($url, $base + ['monto' => '51000.50', 'referencia' => 'TRX-2', 'idempotency_key' => 'moneda-clave-0002'])
        ->assertCreated()->assertJsonPath('saldo.saldo', 328999.5);

    // La columna guarda 4 decimales: 120000.0000 y 51000.5000 son EXACTAMENTE los valores enviados.
    $montos = Pago::query()->orderBy('id')->pluck('monto')->map(fn ($m) => (string) $m)->all();

    expect($montos)->toBe(['120000.0000', '51000.5000'])
        ->and(array_map('floatval', $montos))->toBe([120000.0, 51000.5]);

    // Lo que la mascara NUNCA debe enviar sigue rechazado por el servidor (la regla de siempre: texto plano con punto decimal).
    foreach (['120.000', '$ 120.000', '120,000', '12000000000', '1.5.5', ''] as $malo) {
        $this->actingAs($admin, 'admin')->postJson($url, $base + ['monto' => $malo, 'referencia' => 'TRX-3', 'idempotency_key' => 'moneda-clave-'.md5($malo)])
            ->assertStatus(422);
    }

    expect(Pago::query()->count())->toBe(2);
});

test('M-3 control de costos: «Nuevo costo» es un campo mejorable y el costo se guarda con el valor crudo ("120000"), con ida y vuelta', function () {
    $admin = f03b_admin();
    $producto = f03f_simple(9000.0);
    com_costo($producto, 5000.0);

    // El campo sigue siendo un input type=number con su name (sin JS funciona igual) y queda marcado para la mascara.
    $html = $this->actingAs($admin, 'admin')->get(route('admin.ventas_asesor.costos.index'))->assertOk()->getContent();

    expect($html)->toMatch('/<input id="f-nuevo" type="number" name="nuevo_costo"[^>]*required data-moneda/')
        ->and($html)->toContain('app.component(\'v-moneda\'');

    // La mascara envia "120000" (se veia «$ 120.000»).
    $vista = $this->actingAs($admin, 'admin')
        ->post(route('admin.ventas_asesor.costos.previsualizar'), ['alcance' => 'seleccion', 'ids' => [$producto->id], 'nuevo_costo' => '120000', 'motivo' => 'ajuste'])
        ->assertOk()->getContent();

    // La vista previa devuelve el valor crudo en el formulario de confirmacion (lo que el navegador reenvia tal cual).
    preg_match('/name="nuevo_costo" value="([^"]*)"/', $vista, $nuevo);
    preg_match('/name="token" value="([^"]*)"/', $vista, $token);

    expect($nuevo[1])->toMatch('/^120000(\.0+)?$/');

    $this->actingAs($admin, 'admin')
        ->post(route('admin.ventas_asesor.costos.actualizar'), ['ids' => [$producto->id], 'nuevo_costo' => $nuevo[1], 'token' => $token[1], 'motivo' => 'ajuste'])
        ->assertRedirect();

    expect(app(CostoComisionableCatalogo::class)->costosDe([$producto->id])[$producto->id])->toBe(120000.0)
        ->and(CambioCostoComisionable::query()->where('product_id', $producto->id)->count())->toBe(1);

    // Ida y vuelta con decimales: «$ 120.000,5» → "120000.5".
    $vista = $this->actingAs($admin, 'admin')
        ->post(route('admin.ventas_asesor.costos.previsualizar'), ['alcance' => 'seleccion', 'ids' => [$producto->id], 'nuevo_costo' => '120000.5', 'motivo' => 'ajuste'])
        ->assertOk()->getContent();
    preg_match('/name="nuevo_costo" value="([^"]*)"/', $vista, $nuevo);
    preg_match('/name="token" value="([^"]*)"/', $vista, $token);

    $this->actingAs($admin, 'admin')
        ->post(route('admin.ventas_asesor.costos.actualizar'), ['ids' => [$producto->id], 'nuevo_costo' => $nuevo[1], 'token' => $token[1], 'motivo' => 'ajuste'])
        ->assertRedirect();

    expect(app(CostoComisionableCatalogo::class)->costosDe([$producto->id])[$producto->id])->toBe(120000.5);
});

test('M-4 la mascara no toca los valores de LECTURA que ya usan el formateador de Bagisto ni el abono de Cartera (centavos)', function () {
    // El abono de Cartera mantiene su campo original: su logica en centavos no se altera.
    $vistaCartera = file_get_contents(base_path('packages/ByLopez/VentasAsesor/src/Resources/views/admin/cartera/cliente.blade.php'));

    expect($vistaCartera)->not->toContain('v-moneda')
        ->and($vistaCartera)->toContain('v-model="form.monto"')
        ->and($vistaCartera)->toContain('centavos(');
});
