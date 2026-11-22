<?php

use ByLopez\VentasAsesor\Services\Venta\CatalogoVentaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Webkul\Product\Models\ProductCustomerGroupPrice;

require_once __DIR__.'/../helpersPreciosGrupo.php';
require_once __DIR__.'/../helpersFase03f.php';

uses(TestCase::class);

/*
| PG-01 — CARACTERIZACION del precio por grupo de Bagisto 2.4.9 (sin cambios de codigo). Fija con el motor REAL las
| reglas que usa «Costos y precios». Solo lpz_saas_testing. Valores de fixture, no precios comerciales.
*/

beforeEach(function () {
    [$this->padre, $this->variantes] = pg_catalogo(10000.0);
    $this->v = $this->variantes[0];
    $this->vip1 = f03b_grupo('VIP1');
    $this->vip2 = f03b_grupo('VIP2');
});

test('PG01-1 «discount» es un PORCENTAJE DE DESCUENTO sobre el precio base y sigue al precio base', function () {
    pg_regla($this->v, $this->vip2, 1, 'discount', 10);

    expect(pg_motor($this->v, $this->vip2))->toBe(9000.0)
        ->and(pg_motor($this->v, $this->vip1))->toBe(10000.0);

    pg_precioBase($this->v, 12000.0);

    expect(pg_motor($this->v, $this->vip2))->toBe(10800.0);
});

test('PG01-2 «fixed» es el precio unitario y NO sigue al precio base', function () {
    pg_regla($this->v, $this->vip2, 1, 'fixed', 8000);

    expect(pg_motor($this->v, $this->vip2))->toBe(8000.0);

    pg_precioBase($this->v, 12000.0);

    expect(pg_motor($this->v, $this->vip2))->toBe(8000.0);
});

test('PG01-3 un precio fijo IGUAL o MAYOR que el precio base se ignora (gana el base)', function () {
    pg_regla($this->v, $this->vip2, 1, 'fixed', 12000);
    expect(pg_motor($this->v, $this->vip2))->toBe(10000.0);

    pg_precioBase($this->v, 13000.0);
    expect(pg_motor($this->v, $this->vip2))->toBe(12000.0);
});

test('PG01-4 escalones: cuenta la cantidad de la LINEA (SKU); antes, en y despues del escalon', function () {
    pg_regla($this->v, $this->vip2, 1, 'fixed', 9000);
    pg_regla($this->v, $this->vip2, 10, 'fixed', 8000);

    expect(pg_motor($this->v, $this->vip2, 9))->toBe(9000.0)
        ->and(pg_motor($this->v, $this->vip2, 10))->toBe(8000.0)
        ->and(pg_motor($this->v, $this->vip2, 11))->toBe(8000.0);
});

test('PG01-5 sin escalon 1, por debajo del primer escalon rige el precio base', function () {
    pg_regla($this->v, $this->vip2, 10, 'fixed', 7100);

    expect(pg_motor($this->v, $this->vip2, 1))->toBe(10000.0)
        ->and(pg_motor($this->v, $this->vip2, 9))->toBe(10000.0)
        ->and(pg_motor($this->v, $this->vip2, 10))->toBe(7100.0);
});

test('PG01-6 un escalon FIJO mas caro que el anterior se ignora; uno de DESCUENTO reemplaza siempre', function () {
    pg_regla($this->v, $this->vip2, 1, 'fixed', 8000);
    $r = pg_regla($this->v, $this->vip2, 5, 'fixed', 8500);

    expect(pg_motor($this->v, $this->vip2, 6))->toBe(8000.0);

    $r->update(['value_type' => 'discount', 'value' => 10]);

    expect(pg_motor($this->v, $this->vip2, 6))->toBe(9000.0);
});

test('PG01-7 precio especial: NO se acumula, gana el MENOR; el % se calcula sobre el BASE, no sobre el especial', function () {
    pg_precioEspecial($this->v, 8500.0);
    pg_regla($this->v, $this->vip2, 1, 'fixed', 9000);
    expect(pg_motor($this->v, $this->vip2))->toBe(8500.0);

    ProductCustomerGroupPrice::query()->where('product_id', $this->v->id)->update(['value' => 8000]);
    expect(pg_motor($this->v, $this->vip2))->toBe(8000.0);

    ProductCustomerGroupPrice::query()->where('product_id', $this->v->id)->update(['value_type' => 'discount', 'value' => 10]);
    pg_precioEspecial($this->v, 9500.0);
    expect(pg_motor($this->v, $this->vip2))->toBe(9000.0);

    // Especial vencido: ya no participa.
    pg_precioEspecial($this->v, 5000.0, now()->subDays(10)->toDateString(), now()->subDays(5)->toDateString());
    expect(pg_motor($this->v, $this->vip2))->toBe(9000.0);
});

test('PG01-8 una fila con grupo vacio («todos los grupos») tambien aplica al grupo consultado', function () {
    pg_regla($this->v, null, 1, 'fixed', 9500);

    expect(pg_motor($this->v, $this->vip2))->toBe(9500.0)
        ->and(pg_motor($this->v, $this->vip1))->toBe(9500.0);
});

test('PG01-9 con cantidad 1 el precio sale del INDICE: sin reindexar queda viejo; con cantidad 2 se calcula en vivo', function () {
    pg_regla($this->v, $this->vip2, 1, 'fixed', 9000);

    $tipo = fn () => $this->v->fresh()->getTypeInstance();
    $conGrupo = fn (callable $f) => app(\ByLopez\VentasAsesor\Support\ContextoPrecioComercial::class)
        ->duranteCreacionDeCarrito((int) f03b_cliente($this->vip2)->id, $f);

    expect($conGrupo(fn () => (float) $tipo()->getFinalPrice(1)))->toBe(10000.0)
        ->and($conGrupo(fn () => (float) $tipo()->getFinalPrice(2)))->toBe(9000.0);

    pg_reindexar($this->v, $this->padre);

    expect(pg_indice($this->v, $this->vip2))->toBe(9000.0)
        ->and($conGrupo(fn () => (float) $tipo()->getFinalPrice(1)))->toBe(9000.0)
        // El padre configurable indexa el MINIMO de sus variantes para ese grupo (listados).
        ->and(pg_indice($this->padre, $this->vip2))->toBe(9000.0)
        ->and(pg_indice($this->padre, $this->vip1))->toBe(10000.0);
});

test('PG01-10 una regla en el PADRE configurable no cambia el precio de sus variantes', function () {
    pg_regla($this->padre, $this->vip2, 1, 'fixed', 5000);
    pg_reindexar($this->padre, ...$this->variantes);

    expect(pg_motor($this->v, $this->vip2))->toBe(10000.0)
        ->and(pg_indice($this->padre, $this->vip2))->toBe(10000.0);
});

test('PG01-11 Nueva venta (catalogo) da el mismo precio que el motor para el grupo del cliente y la cantidad', function () {
    pg_regla($this->v, $this->vip2, 1, 'fixed', 9000);
    pg_regla($this->v, $this->vip2, 10, 'discount', 25);
    pg_reindexar($this->v, $this->padre);
    f03b_stock($this->v, 50);
    $cliente = f03b_cliente($this->vip2);
    $catalogo = app(CatalogoVentaService::class);

    foreach ([1, 9, 10, 12] as $qty) {
        expect($catalogo->variante($this->padre->id, $this->v->id, $cliente->id, $qty)->precioUnitario)
            ->toBe(pg_motor($this->v, $this->vip2, $qty));
    }

    expect(pg_motor($this->v, $this->vip2, 10))->toBe(7500.0);
});

test('PG01-12 carrito real: el escalon se evalua por SKU, no por la suma de tallas del mismo padre', function () {
    Mail::fake();
    f03f_configurarBase();
    [$s, $m] = [$this->variantes[0], $this->variantes[1]];

    foreach ([$s, $m] as $v) {
        pg_regla($v, $this->vip2, 1, 'fixed', 9000);
        pg_regla($v, $this->vip2, 10, 'fixed', 8000);
        f03b_stock($v, 50);
    }

    pg_reindexar($s, $m, $this->padre);
    $asesor = f03b_admin();
    $cliente = f03b_cliente($this->vip2);

    $borrador = f03f_borradorListo($cliente->id, $asesor, [[$this->padre, $s, 6], [$this->padre, $m, 6]]);
    $precios = DB::table('cart_items')->where('cart_id', $borrador->cart_id)->whereNull('parent_id')->pluck('base_price')->map(fn ($p) => (float) $p)->all();

    expect($precios)->toBe([9000.0, 9000.0]);
});
