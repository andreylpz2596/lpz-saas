<?php

use ByLopez\VentasAsesor\Models\BorradorVenta;
use ByLopez\VentasAsesor\Services\Venta\BorradorVentaService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

require_once __DIR__.'/../helpersFase03f.php';
require_once __DIR__.'/../helpersGeo.php';

uses(TestCase::class);

/*
| P7 (Cliente frecuente, doc 33 §8): consultar el catalogo NO crea ventas en curso. El borrador nace con la primera
| prenda (iniciar_con_tallas), de forma idempotente, y la lista solo muestra ventas con productos. Base exclusiva.
*/

beforeEach(function () {
    Mail::fake();
    f03f_configurarBase();
    geo_cargar();

    $this->grupo = f03b_grupo('G');
    $this->cliente = f03b_cliente($this->grupo, ['first_name' => 'Rita', 'last_name' => 'Compradora', 'phone' => '3'.random_int(100000000, 149999999)]);
    $this->asesor = f03b_admin('custom', [AutorizacionVentasAsesor::PERMISO_MODULO, BorradorVentaService::PERMISO]);
    $this->otro = f03b_admin('custom', [AutorizacionVentasAsesor::PERMISO_MODULO, BorradorVentaService::PERMISO]);

    $this->configurable = f03b_configurable(10000.0);
    $this->variantes = $this->configurable->variants()->get();
    foreach ($this->variantes as $v) {
        f03b_stock($v, 50);
    }
    [$this->m, $this->l] = $this->variantes->groupBy(fn ($v) => (int) $v->color)->first(fn ($g) => $g->count() >= 2)->take(2)->values()->all();

    $this->ruta = route('admin.ventas_asesor.nueva_venta.iniciar_con_tallas');
    $this->cuerpo = fn (array $extra = []) => array_merge([
        'customer_id' => $this->cliente->id,
        'canal' => 'tienda_fisica',
        'referencia' => (string) Illuminate\Support\Str::uuid(),
        'producto_id' => $this->configurable->id,
        'cantidades' => [$this->m->id => 2, $this->l->id => 3],
    ], $extra);
});

function cf7_borradores(int $adminId): int
{
    return BorradorVenta::query()->where('propietario_admin_id', $adminId)->count();
}

test('P7 consultar el catalogo (matriz sin borrador) no crea ningun borrador ni carrito', function () {
    $carritos = DB::table('cart')->count();

    $this->actingAs($this->asesor, 'admin')
        ->getJson(route('admin.ventas_asesor.nueva_venta.productos.matriz', ['producto' => $this->configurable->id]).'?customer_id='.$this->cliente->id)
        ->assertOk();

    expect(cf7_borradores($this->asesor->id))->toBe(0)
        ->and(DB::table('cart')->count())->toBe($carritos);
});

test('P7 la primera prenda crea el borrador con sus lineas en una sola operacion', function () {
    $r = $this->actingAs($this->asesor, 'admin')->postJson($this->ruta, ($this->cuerpo)())->assertOk()->json();

    expect($r['borrador']['id'])->toBeInt()
        ->and(collect($r['lineas'])->sum('cantidad'))->toBe(5)
        ->and(cf7_borradores($this->asesor->id))->toBe(1);
});

test('P7 reintento con la misma referencia (doble clic, red lenta) no duplica el borrador ni las prendas', function () {
    $cuerpo = ($this->cuerpo)();

    $a = $this->actingAs($this->asesor, 'admin')->postJson($this->ruta, $cuerpo)->assertOk()->json();
    $b = $this->actingAs($this->asesor, 'admin')->postJson($this->ruta, $cuerpo)->assertOk()->json();

    expect($b['borrador']['id'])->toBe($a['borrador']['id'])
        ->and(collect($b['lineas'])->sum('cantidad'))->toBe(5)
        ->and(cf7_borradores($this->asesor->id))->toBe(1);
});

test('P7 referencias distintas = ventas independientes (dos pestanas)', function () {
    $a = $this->actingAs($this->asesor, 'admin')->postJson($this->ruta, ($this->cuerpo)())->assertOk()->json('borrador.id');
    $b = $this->actingAs($this->asesor, 'admin')->postJson($this->ruta, ($this->cuerpo)())->assertOk()->json('borrador.id');

    expect($a)->not->toBe($b)->and(cf7_borradores($this->asesor->id))->toBe(2);
});

test('P7 sin ninguna cantidad positiva no se crea nada', function () {
    $carritos = DB::table('cart')->count();

    $this->actingAs($this->asesor, 'admin')
        ->postJson($this->ruta, ($this->cuerpo)(['cantidades' => [$this->m->id => 0, $this->l->id => 0]]))
        ->assertStatus(422);

    expect(cf7_borradores($this->asesor->id))->toBe(0)->and(DB::table('cart')->count())->toBe($carritos);
});

test('P7 si el lote se rechaza (stock) no queda un borrador vacio', function () {
    $carritos = DB::table('cart')->count();

    $this->actingAs($this->asesor, 'admin')
        ->postJson($this->ruta, ($this->cuerpo)(['cantidades' => [$this->m->id => 9999]]))
        ->assertStatus(422)->assertJsonPath('codigo', 'lote_rechazado');

    expect(cf7_borradores($this->asesor->id))->toBe(0)->and(DB::table('cart')->count())->toBe($carritos);
});

test('P7 la referencia no se comparte entre asesores: otro asesor con la misma referencia crea el suyo', function () {
    $cuerpo = ($this->cuerpo)();

    $a = $this->actingAs($this->asesor, 'admin')->postJson($this->ruta, $cuerpo)->assertOk()->json('borrador.id');
    $b = $this->actingAs($this->otro, 'admin')->postJson($this->ruta, $cuerpo)->assertOk()->json('borrador.id');

    expect($a)->not->toBe($b)
        ->and(BorradorVenta::query()->find($b)->propietario_admin_id)->toBe($this->otro->id);
});

test('P7 «en curso» solo lista ventas con productos; las vacias heredadas no aparecen', function () {
    // Una vacia heredada (flujo antiguo iniciar) y una real.
    $vacia = $this->actingAs($this->asesor, 'admin')
        ->postJson(route('admin.ventas_asesor.nueva_venta.iniciar'), ['customer_id' => $this->cliente->id, 'canal' => 'tienda_fisica'])
        ->assertOk()->json('borrador.id');
    $real = $this->actingAs($this->asesor, 'admin')->postJson($this->ruta, ($this->cuerpo)())->assertOk()->json('borrador.id');

    $ids = collect($this->actingAs($this->asesor, 'admin')->getJson(route('admin.ventas_asesor.nueva_venta.en_curso'))->assertOk()->json())->pluck('id')->all();

    expect($ids)->toContain($real)->not->toContain($vacia);
});

test('P7 quitar todas las prendas deja la venta oculta de «en curso» (sin borrarla)', function () {
    $r = $this->actingAs($this->asesor, 'admin')->postJson($this->ruta, ($this->cuerpo)())->assertOk()->json();
    $id = $r['borrador']['id'];

    $this->actingAs($this->asesor, 'admin')
        ->postJson(route('admin.ventas_asesor.nueva_venta.tallas', ['borrador' => $id]), [
            'producto_id' => $this->configurable->id, 'cantidades' => [$this->m->id => 0, $this->l->id => 0],
        ])->assertOk();

    $ids = collect($this->actingAs($this->asesor, 'admin')->getJson(route('admin.ventas_asesor.nueva_venta.en_curso'))->json())->pluck('id')->all();

    expect($ids)->not->toContain($id)->and(BorradorVenta::query()->find($id))->not->toBeNull();
});
