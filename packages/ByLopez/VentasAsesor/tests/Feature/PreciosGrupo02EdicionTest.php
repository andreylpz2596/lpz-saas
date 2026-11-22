<?php

use ByLopez\VentasAsesor\DataObjects\VistaPreviaPreciosGrupo as Vista;
use ByLopez\VentasAsesor\Exceptions\ConfirmacionVentaInvalidaException;
use ByLopez\VentasAsesor\Exceptions\PrecioGrupoInvalidoException as Invalido;
use ByLopez\VentasAsesor\Models\CambioPrecioGrupo;
use ByLopez\VentasAsesor\Models\LotePrecioGrupo;
use ByLopez\VentasAsesor\Services\Precios\ConsultaPreciosGrupoService;
use ByLopez\VentasAsesor\Services\Precios\EdicionPreciosGrupoService;
use ByLopez\VentasAsesor\Services\Venta\CatalogoVentaService;
use ByLopez\VentasAsesor\Services\Venta\ConfirmarVentaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Webkul\Product\Models\ProductCustomerGroupPrice;

require_once __DIR__.'/../helpersPreciosGrupo.php';
require_once __DIR__.'/../helpersFase03f.php';
require_once __DIR__.'/../helpersCom.php';

uses(TestCase::class);

/*
| PG-02 — «Costos y precios › Precios por grupo»: consulta, vista previa, confirmacion, bitacora, permisos y efectos.
| Solo lpz_saas_testing. Los esperados se calculan a mano (no con el servicio que se prueba).
*/

function pg02_error(callable $accion): ?string
{
    try {
        $accion();
    } catch (Invalido $e) {
        return $e->codigo;
    }

    return null;
}

/** Solicitud + vista previa + confirmacion con el actor de la prueba. */
function pg02_aplicar($test, array $entrada): array
{
    $s = $test->edicion->solicitud($entrada + ['grupo' => $test->vip2->id, 'motivo' => 'Prueba PG02']);
    $vista = $test->edicion->previsualizar($test->admin, $s);

    return [$vista, $test->edicion->confirmar($test->admin, $s, $vista->token)];
}

function pg02_reglas(int $productId, ?int $grupoId = null): array
{
    return ProductCustomerGroupPrice::query()->where('product_id', $productId)
        ->when($grupoId !== null, fn ($q) => $q->where('customer_group_id', $grupoId))
        ->orderBy('customer_group_id')->orderBy('qty')
        ->get(['customer_group_id', 'qty', 'value_type', 'value'])
        ->map(fn ($r) => [(int) $r->customer_group_id, (int) $r->qty, $r->value_type, (float) $r->value])->all();
}

beforeEach(function () {
    [$this->padre, $this->variantes] = pg_catalogo(10000.0);
    $this->vip1 = f03b_grupo('VIP1');
    $this->vip2 = f03b_grupo('VIP2');
    $this->admin = f03b_admin();
    $this->edicion = app(EdicionPreciosGrupoService::class);
    $this->consulta = app(ConsultaPreciosGrupoService::class);
    [$this->s, $this->m] = [$this->variantes[0], $this->variantes[1]];

    // Estado inicial: VIP1 y VIP2 con fijo 1+ en todas las tallas; VIP2 ademas 10+.
    foreach ($this->variantes as $v) {
        pg_regla($v, $this->vip1, 1, 'fixed', 7000);
        pg_regla($v, $this->vip2, 1, 'fixed', 8000);
        pg_regla($v, $this->vip2, 10, 'fixed', 7500);
    }

    pg_reindexar($this->padre, ...$this->variantes);
});

test('PG02-1 la consulta de VIP 2 muestra sus reglas, precio y origen sin escribir nada ni mezclar VIP 1', function () {
    $antes = DB::table('product_customer_group_prices')->count();

    $refs = $this->consulta->referencias($this->vip2, [$this->s->id, $this->m->id], 10, false);
    $fila = $refs[0]['variantes'][0];

    expect($refs)->toHaveCount(1)
        ->and(array_map(fn ($r) => [$r['qty'], $r['tipo'], $r['valor']], $fila['reglas']))->toBe([[1, 'fixed', 8000.0], [10, 'fixed', 7500.0]])
        ->and($fila['eval']->final)->toBe(7500.0)
        ->and($fila['eval']->origen)->toBe('grupo')
        ->and($fila['eval']->regla['qty'])->toBe(10)
        ->and($fila['costo'])->toBeNull()
        ->and($fila['con_costos'])->toBeFalse()
        ->and(collect($this->consulta->otrosGrupos($this->s->id, $this->vip2->id, 10))->firstWhere('nombre', $this->vip1->name)['precio'])->toBe(7000.0)
        ->and(DB::table('product_customer_group_prices')->count())->toBe($antes)
        ->and(LotePrecioGrupo::query()->count())->toBe(0);
});

test('PG02-2 editar UNA talla de VIP 2 no toca sus hermanas, otros escalones ni VIP 1', function () {
    $antesM = pg02_reglas($this->m->id);
    $antesVip1 = pg02_reglas($this->s->id, $this->vip1->id);

    [$vista, $r] = pg02_aplicar($this, ['operacion' => 'fijo', 'qty' => '1', 'valor' => '5800', 'ids' => [$this->s->id]]);

    expect($vista->conteo)->toBe(['crear' => 0, 'actualizar' => 1, 'quitar' => 0, 'sin_cambio' => 0, 'error' => 0])
        ->and($vista->filas[0]['precio_nuevo'])->toBe(5800.0)
        ->and(pg02_reglas($this->s->id, $this->vip2->id))->toBe([[$this->vip2->id, 1, 'fixed', 5800.0], [$this->vip2->id, 10, 'fixed', 7500.0]])
        ->and(pg02_reglas($this->s->id, $this->vip1->id))->toBe($antesVip1)
        ->and(pg02_reglas($this->m->id))->toBe($antesM)
        ->and($r['lote']->indice_estado)->toBe(LotePrecioGrupo::INDICE_ACTUALIZADO)
        ->and(pg_indice($this->s, $this->vip2))->toBe(5800.0)
        ->and(pg_indice($this->m, $this->vip2))->toBe(8000.0);

    $cambio = CambioPrecioGrupo::query()->sole();
    expect([$cambio->accion, (float) $cambio->valor_anterior, (float) $cambio->valor_nuevo, (float) $cambio->precio_resultante, $cambio->qty])
        ->toBe(['actualizar', 8000.0, 5800.0, 5800.0, 1])
        ->and($r['lote']->actor_admin_id)->toBe((int) $this->admin->id)
        ->and($r['lote']->motivo)->toBe('Prueba PG02');
});

test('PG02-3 editar TODAS las tallas de la referencia; el escalon 10 y VIP 1 quedan intactos', function () {
    $ids = array_map(fn ($v) => $v->id, $this->variantes);

    [$vista] = pg02_aplicar($this, ['operacion' => 'fijo', 'qty' => '1', 'valor' => '6000', 'ids' => $ids]);

    expect($vista->conteo['actualizar'])->toBe(count($ids));

    foreach ($this->variantes as $v) {
        expect(pg02_reglas($v->id, $this->vip2->id))->toBe([[$this->vip2->id, 1, 'fixed', 6000.0], [$this->vip2->id, 10, 'fixed', 7500.0]])
            ->and(pg02_reglas($v->id, $this->vip1->id))->toBe([[$this->vip1->id, 1, 'fixed', 7000.0]]);
    }

    // El escalon 10 (7.500) ahora es MAS CARO que el de 1 (6.000): Bagisto lo ignora y la vista previa lo advirtio.
    expect(collect($vista->filas[0]['advertencias'])->pluck('codigo')->all())->toContain('escalones_sin_efecto')
        ->and(pg_motor($this->s, $this->vip2, 10))->toBe(6000.0);
});

test('PG02-4 seleccion de varias paginas y «todos los resultados del filtro»', function () {
    [$padre2, $variantes2] = pg_catalogo(12000.0);
    config(['ventasasesor.precios_grupo.por_pagina' => 1]);

    $todo = $this->consulta->listar($this->vip2, ['grupo' => $this->vip2->id, 'q' => $this->padre->sku.'|x'], 1, false);
    expect($todo['total_variantes'])->toBe(0);

    $pag1 = $this->consulta->listar($this->vip2, ['grupo' => $this->vip2->id], 1, false)['referencias'];
    $pag2 = $this->consulta->listar($this->vip2, ['grupo' => $this->vip2->id], 2, false)['referencias'];
    expect($pag1->total())->toBeGreaterThanOrEqual(2)->and($pag1->items())->toHaveCount(1);

    // La seleccion combina una talla de la pagina 1 y una de la pagina 2.
    $ids = [$pag1->items()[0]['variantes'][0]['id'], $pag2->items()[0]['variantes'][0]['id']];
    $s = $this->edicion->solicitud(['grupo' => $this->vip2->id, 'operacion' => 'completar', 'tipo' => 'discount', 'valor' => '5', 'qty' => '20', 'ids' => $ids, 'motivo' => 'm']);
    expect($this->edicion->previsualizar($this->admin, $s)->conteo['crear'])->toBe(2);

    // Filtro por SKU del padre 2: exactamente sus variantes.
    $filtro = $this->consulta->idsDelFiltro(['grupo' => $this->vip2->id, 'q' => $padre2->sku]);
    expect($filtro)->toEqualCanonicalizing(array_map(fn ($v) => $v->id, $variantes2));

    // Filtro por talla (super atributo del catalogo) + «sin precio especifico»: solo las del padre 2.
    $talla = DB::table('product_attribute_values')->where('product_id', $variantes2[0]->id)
        ->whereIn('attribute_id', DB::table('product_super_attributes')->where('product_id', $padre2->id)->pluck('attribute_id'))
        ->whereNotIn('attribute_id', DB::table('attributes')->where('code', 'color')->pluck('id'))->value('integer_value');
    $conTalla = $this->consulta->idsDelFiltro(['grupo' => $this->vip2->id, 'talla' => $talla, 'sin_regla' => true]);
    expect($conTalla)->toContain($variantes2[0]->id)
        ->and(array_intersect($conTalla, array_map(fn ($v) => $v->id, $this->variantes)))->toBe([]);
});

test('PG02-5 variante SIN regla: «Sin precio especifico» con el precio alternativo real (base, o especial si gana)', function () {
    [, $vars] = pg_catalogo(11000.0);
    $refs = $this->consulta->referencias($this->vip2, [$vars[0]->id], 1, false);
    $f = $refs[0]['variantes'][0];

    expect($f['reglas'])->toBe([])
        ->and($f['eval']->final)->toBe(11000.0)
        ->and($f['eval']->origen)->toBe('base')
        ->and($refs[0]['resumen']['sin_regla'])->toBe(1);

    pg_precioEspecial($vars[0], 9900.0);
    $f = $this->consulta->referencias($this->vip2, [$vars[0]->id], 1, false)[0]['variantes'][0];
    expect([$f['eval']->final, $f['eval']->origen])->toBe([9900.0, 'especial']);
});

test('PG02-6 fijo y porcentual: el resultado coincide con el calculo a mano y con el motor tras guardar', function () {
    [$vista] = pg02_aplicar($this, ['operacion' => 'porcentaje', 'qty' => '1', 'valor' => '12.5', 'ids' => [$this->s->id]]);

    // 10.000 − 12,5 % = 8.750 (sobre el BASE, no sobre el fijo anterior de 8.000).
    expect($vista->filas[0]['precio_nuevo'])->toBe(8750.0)
        ->and($vista->filas[0]['nueva'])->toBe(['tipo' => 'discount', 'valor' => 12.5])
        ->and(pg_motor($this->s, $this->vip2, 1))->toBe(8750.0);

    // Cambio del precio base: el % sigue al base; el fijo no.
    pg_precioBase($this->s, 12000.0);
    pg_precioBase($this->m, 12000.0);
    expect(pg_motor($this->s, $this->vip2, 1))->toBe(10500.0)
        ->and(pg_motor($this->m, $this->vip2, 1))->toBe(8000.0);
});

test('PG02-7 escalones en la vista previa: antes, en y despues; «completar» no toca lo existente', function () {
    $s = $this->edicion->solicitud(['grupo' => $this->vip2->id, 'operacion' => 'completar', 'tipo' => 'fixed', 'valor' => '6500', 'qty' => '20',
        'ids' => [$this->s->id, $this->m->id], 'motivo' => 'm']);
    $vista = $this->edicion->previsualizar($this->admin, $s);
    expect($vista->conteo['crear'])->toBe(2)->and($vista->filas[0]['precio_nuevo'])->toBe(6500.0);
    $this->edicion->confirmar($this->admin, $s, $vista->token);

    expect(pg_motor($this->s, $this->vip2, 9))->toBe(8000.0)
        ->and(pg_motor($this->s, $this->vip2, 10))->toBe(7500.0)
        ->and(pg_motor($this->s, $this->vip2, 19))->toBe(7500.0)
        ->and(pg_motor($this->s, $this->vip2, 20))->toBe(6500.0)
        ->and(pg_motor($this->s, $this->vip2, 21))->toBe(6500.0);

    $otra = $this->edicion->solicitud(['grupo' => $this->vip2->id, 'operacion' => 'completar', 'tipo' => 'fixed', 'valor' => '1', 'qty' => '1', 'ids' => [$this->s->id]]);
    $v2 = $this->edicion->previsualizar($this->admin, $otra);
    expect($v2->conteo['sin_cambio'])->toBe(1)->and($v2->filas[0]['motivo'])->toBe('ya_tiene')->and($v2->confirmable())->toBeFalse();
});

test('PG02-8 precio especial: la vista previa muestra que gana el especial (no se acumula)', function () {
    pg_precioEspecial($this->s, 7800.0);

    $s = $this->edicion->solicitud(['grupo' => $this->vip2->id, 'operacion' => 'fijo', 'qty' => '1', 'valor' => '7900', 'ids' => [$this->s->id]]);
    $f = $this->edicion->previsualizar($this->admin, $s)->filas[0];

    expect($f['precio_nuevo'])->toBe(7800.0)
        ->and($f['origen_nuevo']->origen)->toBe('especial')
        ->and(collect($f['advertencias'])->pluck('codigo')->all())->toContain('gana_especial');

    // Igual al base: se permite, con advertencia (no cambia lo que se cobra).
    $s = $this->edicion->solicitud(['grupo' => $this->vip2->id, 'operacion' => 'fijo', 'qty' => '1', 'valor' => '10000', 'ids' => [$this->s->id]]);
    expect(collect($this->edicion->previsualizar($this->admin, $s)->filas[0]['advertencias'])->pluck('codigo')->all())->toContain('fijo_no_menor_base');
});

test('PG02-19 regla ByLopez: un fijo MAYOR que el precio base no se puede guardar (fijo, completar, ajustar)', function () {
    pg_precioBase($this->m, 7500.0);
    $antes = [pg02_reglas($this->s->id), pg02_reglas($this->m->id)];

    // Masivo 9.000 a S (base 10.000) y M (base 7.500): la fila de M es error y bloquea TODO el lote.
    $s = $this->edicion->solicitud(['grupo' => $this->vip2->id, 'operacion' => 'fijo', 'qty' => '1', 'valor' => '9000', 'ids' => [$this->s->id, $this->m->id], 'motivo' => 'm']);
    $vista = $this->edicion->previsualizar($this->admin, $s);
    $m = collect($vista->filas)->firstWhere('id', $this->m->id);

    expect([$m['accion'], $m['motivo']])->toBe(['error', 'mayor_que_base'])
        ->and($vista->conteo['actualizar'])->toBe(1)->and($vista->confirmable())->toBeFalse()
        ->and(pg02_error(fn () => $this->edicion->confirmar($this->admin, $s, $vista->token)))->toBe(Invalido::FILAS_CON_ERROR)
        ->and([pg02_reglas($this->s->id), pg02_reglas($this->m->id)])->toBe($antes);

    // Completar con fijo mayor que el base: error.
    $s = $this->edicion->solicitud(['grupo' => $this->vip2->id, 'operacion' => 'completar', 'tipo' => 'fixed', 'valor' => '12000', 'qty' => '30', 'ids' => [$this->s->id]]);
    expect($this->edicion->previsualizar($this->admin, $s)->filas[0]['motivo'])->toBe('mayor_que_base');

    // Ajuste que lleva el fijo (8.000) por encima del base (10.000): +30 % = 10.400 → error.
    $s = $this->edicion->solicitud(['grupo' => $this->vip2->id, 'operacion' => 'ajustar', 'modo_ajuste' => 'porcentaje', 'valor' => '30', 'qty' => '1', 'ids' => [$this->s->id]]);
    expect($this->edicion->previsualizar($this->admin, $s)->filas[0]['motivo'])->toBe('mayor_que_base');

    // Un descuento % nunca supera el base: se permite.
    $s = $this->edicion->solicitud(['grupo' => $this->vip2->id, 'operacion' => 'porcentaje', 'valor' => '5', 'qty' => '1', 'ids' => [$this->m->id]]);
    expect($this->edicion->previsualizar($this->admin, $s)->conteo['actualizar'])->toBe(1);
});

test('PG02-20 la ficha nativa de Bagisto tampoco guarda un fijo de grupo mayor que el base', function () {
    $producto = f03f_simple(7500.0);
    $datos = fn (array $grupos) => [
        'sku' => $producto->sku, 'url_key' => $producto->url_key, 'short_description' => 'Corta', 'description' => 'Larga',
        'name' => 'Top prueba PG02-20', 'price' => 7500, 'weight' => 1, 'channel' => core()->getCurrentChannelCode(),
        'locale' => app()->getLocale(), 'customer_group_prices' => $grupos,
    ];

    $this->actingAs($this->admin, 'admin')
        ->putJson(route('admin.catalog.products.update', $producto->id), $datos(['price_0' => ['customer_group_id' => $this->vip2->id, 'qty' => 1, 'value_type' => 'fixed', 'value' => 72000]]))
        ->assertUnprocessable()->assertJsonValidationErrorFor('customer_group_prices');
    expect(pg02_reglas($producto->id))->toBe([]);

    $this->actingAs($this->admin, 'admin')
        ->putJson(route('admin.catalog.products.update', $producto->id), $datos(['price_0' => ['customer_group_id' => $this->vip2->id, 'qty' => 1, 'value_type' => 'fixed', 'value' => 7200]]))
        ->assertRedirect();
    expect(pg02_reglas($producto->id))->toBe([[$this->vip2->id, 1, 'fixed', 7200.0]]);
});

test('PG02-9 la vista previa coincide con el precio REAL de Nueva venta (cantidad 1 por indice y 12 en vivo)', function () {
    f03b_stock($this->s, 50);
    $cliente = f03b_cliente($this->vip2);

    foreach ([['1', '6100'], ['10', '5900']] as [$qty, $valor]) {
        [$vista] = pg02_aplicar($this, ['operacion' => 'fijo', 'qty' => $qty, 'valor' => $valor, 'ids' => [$this->s->id]]);
        $catalogo = app(CatalogoVentaService::class);

        expect($catalogo->variante($this->padre->id, $this->s->id, $cliente->id, (int) $qty)->precioUnitario)->toBe($vista->filas[0]['precio_nuevo']);
    }

    expect(app(CatalogoVentaService::class)->variante($this->padre->id, $this->s->id, $cliente->id, 12)->precioUnitario)->toBe(5900.0);
});

test('PG02-10 cancelar la vista previa no escribe nada', function () {
    $antes = [DB::table('product_customer_group_prices')->count(), pg02_reglas($this->s->id), DB::table('product_price_indices')->max('updated_at')];

    $s = $this->edicion->solicitud(['grupo' => $this->vip2->id, 'operacion' => 'quitar', 'qty' => '1', 'ids' => [$this->s->id, $this->m->id], 'motivo' => 'm']);
    $vista = $this->edicion->previsualizar($this->admin, $s);

    expect($vista->conteo['quitar'])->toBe(2)
        ->and($vista->filas[0]['precio_nuevo'])->toBe(10000.0)
        ->and([DB::table('product_customer_group_prices')->count(), pg02_reglas($this->s->id), DB::table('product_price_indices')->max('updated_at')])->toBe($antes)
        ->and(LotePrecioGrupo::query()->count())->toBe(0);
});

test('PG02-11 doble envio = un solo lote; una vista previa vieja tras otro cambio = conflicto sin escribir', function () {
    $s = $this->edicion->solicitud(['grupo' => $this->vip2->id, 'operacion' => 'fijo', 'qty' => '1', 'valor' => '5000', 'ids' => [$this->s->id]]);
    $vista = $this->edicion->previsualizar($this->admin, $s);

    $r1 = $this->edicion->confirmar($this->admin, $s, $vista->token);
    $r2 = $this->edicion->confirmar($this->admin, $s, $vista->token);

    expect($r1['ya_aplicado'])->toBeFalse()->and($r2['ya_aplicado'])->toBeTrue()
        ->and($r2['lote']->id)->toBe($r1['lote']->id)
        ->and(LotePrecioGrupo::query()->count())->toBe(1)
        ->and(CambioPrecioGrupo::query()->count())->toBe(1);

    // Usuario B previsualiza 5.500; usuario A cambia a 5.200 antes de que B confirme.
    $sB = $this->edicion->solicitud(['grupo' => $this->vip2->id, 'operacion' => 'fijo', 'qty' => '1', 'valor' => '5500', 'ids' => [$this->s->id]]);
    $vistaB = $this->edicion->previsualizar($this->admin, $sB);
    pg02_aplicar($this, ['operacion' => 'fijo', 'qty' => '1', 'valor' => '5200', 'ids' => [$this->s->id]]);

    expect(pg02_error(fn () => $this->edicion->confirmar($this->admin, $sB, $vistaB->token)))->toBe(Invalido::CONFLICTO)
        ->and(pg02_reglas($this->s->id, $this->vip2->id)[0][3])->toBe(5200.0);

    // Un cambio desde la ficha de Bagisto (fuera de esta pantalla) tambien invalida la vista previa.
    $sC = $this->edicion->solicitud(['grupo' => $this->vip2->id, 'operacion' => 'fijo', 'qty' => '1', 'valor' => '5300', 'ids' => [$this->s->id]]);
    $vistaC = $this->edicion->previsualizar($this->admin, $sC);
    pg_precioBase($this->s, 15000.0);
    expect(pg02_error(fn () => $this->edicion->confirmar($this->admin, $sC, $vistaC->token)))->toBe(Invalido::CONFLICTO);
});

test('PG02-12 una fila con error bloquea TODO el lote (sin aplicaciones parciales)', function () {
    ProductCustomerGroupPrice::query()->where('product_id', $this->m->id)->where('customer_group_id', $this->vip2->id)->where('qty', 1)->update(['value' => 300]);
    $antes = [pg02_reglas($this->s->id), pg02_reglas($this->m->id)];

    $s = $this->edicion->solicitud(['grupo' => $this->vip2->id, 'operacion' => 'ajustar', 'modo_ajuste' => 'importe', 'valor' => '-500', 'qty' => '1',
        'ids' => [$this->s->id, $this->m->id], 'motivo' => 'baja']);
    $vista = $this->edicion->previsualizar($this->admin, $s);

    expect($vista->conteo['actualizar'])->toBe(1)->and($vista->conteo['error'])->toBe(1)->and($vista->confirmable())->toBeFalse()
        ->and(pg02_error(fn () => $this->edicion->confirmar($this->admin, $s, $vista->token)))->toBe(Invalido::FILAS_CON_ERROR)
        ->and([pg02_reglas($this->s->id), pg02_reglas($this->m->id)])->toBe($antes)
        ->and(LotePrecioGrupo::query()->count())->toBe(0);
});

test('PG02-13 ajuste unico de fijos: % con redondeo; omite descuentos % y escalones faltantes; nunca convierte tipos', function () {
    ProductCustomerGroupPrice::query()->where('product_id', $this->m->id)->where('customer_group_id', $this->vip2->id)->where('qty', 1)
        ->update(['value_type' => 'discount', 'value' => 10]);
    [$extra] = array_slice($this->variantes, 2, 1) ?: [null];

    $ids = array_values(array_filter([$this->s->id, $this->m->id, $extra?->id]));
    ProductCustomerGroupPrice::query()->where('product_id', $extra?->id ?? 0)->where('customer_group_id', $this->vip2->id)->where('qty', 1)->delete();

    [$vista] = pg02_aplicar($this, ['operacion' => 'ajustar', 'modo_ajuste' => 'porcentaje', 'valor' => '3', 'redondeo' => '100', 'qty' => '1', 'ids' => $ids]);

    // 8.000 × 1,03 = 8.240 → múltiplo de 100 = 8.200.
    $porId = collect($vista->filas)->keyBy('id');
    expect($porId[$this->s->id]['nueva'])->toBe(['tipo' => 'fixed', 'valor' => 8200.0])
        ->and($porId[$this->m->id]['motivo'])->toBe('es_porcentaje')
        ->and(pg02_reglas($this->m->id, $this->vip2->id)[0])->toBe([$this->vip2->id, 1, 'discount', 10.0]);

    if ($extra) {
        expect($porId[$extra->id]['motivo'])->toBe('sin_escalon')
            ->and(collect(pg02_reglas($extra->id, $this->vip2->id))->pluck(1)->all())->toBe([10]);
    }
});

test('PG02-14 validaciones: sin vacios a cero, % fuera de rango, motivo en masivo, grupo invitado', function () {
    $base = ['grupo' => $this->vip2->id, 'operacion' => 'fijo', 'qty' => '1', 'ids' => [$this->s->id]];

    expect(pg02_error(fn () => $this->edicion->solicitud($base + ['valor' => ''])))->toBe(Invalido::VALOR_INVALIDO)
        ->and(pg02_error(fn () => $this->edicion->solicitud($base + ['valor' => '0'])))->toBe(Invalido::VALOR_INVALIDO)
        ->and(pg02_error(fn () => $this->edicion->solicitud(['operacion' => 'porcentaje', 'valor' => '100'] + $base)))->toBe(Invalido::VALOR_INVALIDO)
        ->and(pg02_error(fn () => $this->edicion->solicitud(['qty' => '0', 'valor' => '1'] + $base)))->toBe(Invalido::CANTIDAD_INVALIDA)
        ->and(pg02_error(fn () => $this->edicion->solicitud(['ids' => [$this->s->id, $this->m->id], 'valor' => '1'] + $base)))->toBe(Invalido::MOTIVO_REQUERIDO)
        ->and(pg02_error(fn () => $this->edicion->solicitud(['grupo' => core()->getGuestCustomerGroup()->id, 'valor' => '1'] + $base)))->toBe(Invalido::GRUPO_INVALIDO)
        ->and(pg02_error(fn () => $this->edicion->previsualizar($this->admin, $this->edicion->solicitud(['ids' => [$this->padre->id], 'valor' => '1'] + $base))))->toBe(Invalido::PRODUCTO_NO_VALIDO);
});

test('PG02-15 permisos PROPIOS de precios (separados de costos) y costos ocultos sin permiso', function () {
    $q = ['grupo' => $this->vip2->id, 'q' => $this->padre->sku];
    $url = route('admin.ventas_asesor.precios_grupo.index', $q);
    $post = ['filtros' => $q, 'operacion' => 'fijo', 'qty' => 1, 'valor' => '7900', 'alcance' => 'seleccion', 'ids' => [$this->s->id]];
    com_costo($this->s, 4200.0);

    // Asesora (aunque tenga edicion de catalogo en Bagisto): nada.
    $asesor = f03b_admin('custom', ['ventas_asesor', 'ventas_asesor.operar_como_asesor', 'ventas_asesor.pedidos', 'ventas_asesor.pedidos.create', 'catalog', 'catalog.products', 'catalog.products.edit']);
    $this->actingAs($asesor, 'admin')->get($url)->assertUnauthorized();
    $this->actingAs($asesor, 'admin')->post(route('admin.ventas_asesor.precios_grupo.previsualizar'), $post)->assertUnauthorized();
    $this->actingAs($asesor, 'admin')->post(route('admin.ventas_asesor.precios_grupo.confirmar'), $post + ['token' => 'x'])->assertUnauthorized();

    // Solo COSTOS: no ve precios por grupo (ni editar costos le da precios).
    $soloCostos = f03b_admin('custom', ['ventas_asesor', 'ventas_asesor.costos', 'ventas_asesor.costos.actualizar']);
    $this->actingAs($soloCostos, 'admin')->get($url)->assertUnauthorized();
    $this->actingAs($soloCostos, 'admin')->post(route('admin.ventas_asesor.precios_grupo.previsualizar'), $post)->assertUnauthorized();

    // Solo VER precios: consulta sí, sin formulario de edicion, sin costos, y no entra a Costos.
    $lector = f03b_admin('custom', ['ventas_asesor', 'ventas_asesor.precios_grupo']);
    $this->actingAs($lector, 'admin')->get($url)->assertOk()->assertSee($this->vip2->name)
        ->assertDontSee('data-form="edicion-masiva"', false)->assertDontSee('$ 4.200', false)->assertDontSee('data-pestana="costos"', false);
    $this->actingAs($lector, 'admin')->post(route('admin.ventas_asesor.precios_grupo.previsualizar'), $post)->assertUnauthorized();
    $this->actingAs($lector, 'admin')->get(route('admin.ventas_asesor.costos.index'))->assertUnauthorized();

    // EDITAR precios sin costos: edita (vista previa y confirmacion) pero nunca ve costos ni margen.
    $editor = f03b_admin('custom', ['ventas_asesor', 'ventas_asesor.precios_grupo', 'ventas_asesor.precios_grupo.editar']);
    $this->actingAs($editor, 'admin')->get($url)->assertOk()->assertSee('data-form="edicion-masiva"', false)->assertDontSee('$ 4.200', false);
    $html = $this->actingAs($editor, 'admin')->post(route('admin.ventas_asesor.precios_grupo.previsualizar'), $post)
        ->assertOk()->assertDontSee('$ 4.200', false)->getContent();
    preg_match('/name="token" value="([0-9a-f]{64})"/', $html, $m);
    $this->actingAs($editor, 'admin')->post(route('admin.ventas_asesor.precios_grupo.confirmar'), $post + ['token' => $m[1]])->assertRedirect();
    expect(pg02_reglas($this->s->id, $this->vip2->id)[0][3])->toBe(7900.0);
    $this->actingAs($editor, 'admin')->get(route('admin.ventas_asesor.costos.index'))->assertUnauthorized();

    // Menu: UNA entrada «Costos y precios»; con solo precios apunta a la pestaña de precios.
    $menu = $this->actingAs($editor, 'admin')->get($url)->getContent();
    expect(substr_count($menu, route('admin.ventas_asesor.precios_grupo.index').'"'))->toBeGreaterThanOrEqual(1)
        ->and($menu)->not->toContain('href="'.route('admin.ventas_asesor.costos.index').'"');

    // Costo: solo con permiso de costos; sin costo NO se muestra 0.
    $conCosto = $this->consulta->referencias($this->vip2, [$this->s->id, $this->m->id], 1, true)[0]['variantes'];
    $sinPermiso = $this->consulta->referencias($this->vip2, [$this->s->id], 1, false)[0]['variantes'][0];
    expect($conCosto[0]['costo'])->toBe(4200.0)->and($conCosto[0]['diferencia'])->toBe(3700.0)
        ->and($conCosto[1]['costo'])->toBeNull()->and($conCosto[1]['diferencia'])->toBeNull()
        ->and($sinPermiso['costo'])->toBeNull()->and($sinPermiso['con_costos'])->toBeFalse();
});

test('PG02-16 HTTP de punta a punta: consulta, vista previa, cancelar, confirmar y reenvio', function () {
    $this->actingAs($this->admin, 'admin')
        ->get(route('admin.ventas_asesor.precios_grupo.index', ['grupo' => $this->vip2->id, 'qty' => 10, 'q' => $this->padre->sku]))
        ->assertOk()->assertSee($this->vip2->name)->assertSee('data-form="edicion-masiva"', false)->assertSee('$ 7.500', false);

    $post = ['filtros' => ['grupo' => $this->vip2->id, 'q' => $this->padre->sku], 'operacion' => 'fijo', 'qty' => 1, 'valor' => '5800', 'alcance' => 'filtro', 'motivo' => 'Lista nov'];
    $html = $this->actingAs($this->admin, 'admin')->post(route('admin.ventas_asesor.precios_grupo.previsualizar'), $post)
        ->assertOk()->assertSee('data-conteo="actualizar">'.count($this->variantes), false)->assertSee('$ 5.800', false)->getContent();
    expect(LotePrecioGrupo::query()->count())->toBe(0);

    preg_match('/name="token" value="([0-9a-f]{64})"/', $html, $m);
    $confirmar = $post + ['token' => $m[1], 'ids' => array_map(fn ($v) => $v->id, $this->variantes)];
    $this->actingAs($this->admin, 'admin')->post(route('admin.ventas_asesor.precios_grupo.confirmar'), $confirmar)->assertRedirect();
    $this->actingAs($this->admin, 'admin')->post(route('admin.ventas_asesor.precios_grupo.confirmar'), $confirmar)->assertRedirect()
        ->assertSessionHas('success', trans('ventasasesor::app.precios.ya-aplicado'));

    $lote = LotePrecioGrupo::query()->sole();
    expect($lote->alcance)->toBe('filtro')->and($lote->filtros['q'])->toBe($this->padre->sku)->and($lote->actualizadas)->toBe(count($this->variantes));

    $this->actingAs($this->admin, 'admin')
        ->get(route('admin.ventas_asesor.precios_grupo.index', ['grupo' => $this->vip2->id, 'lote' => $lote->lote]))
        ->assertOk()->assertSee('data-lote="'.$lote->lote.'"', false)->assertSee('data-indice="actualizado"', false);

    // La pestaña Costos sigue funcionando.
    $this->actingAs($this->admin, 'admin')->get(route('admin.ventas_asesor.costos.index'))->assertOk()->assertSee('data-pestana="precios"', false);
});

test('PG02-17 pedido confirmado intacto; borrador abierto: la confirmacion exige revisar el total nuevo', function () {
    Mail::fake();
    f03f_configurarBase();
    f03b_stock($this->s, 50);
    $asesor = f03b_admin();
    $cliente = f03b_cliente($this->vip2);

    $pedido = com_vender($cliente->id, $asesor, $this->padre, $this->s, 2);
    $precioPedido = (float) DB::table('order_items')->where('order_id', $pedido->id)->whereNull('parent_id')->value('base_price');
    $borrador = f03f_borradorListo($cliente->id, $asesor, [[$this->padre, $this->s, 2]]);
    $totalVisto = f03f_total($borrador);

    [$vista] = pg02_aplicar($this, ['operacion' => 'fijo', 'qty' => '1', 'valor' => '6000', 'ids' => [$this->s->id]]);

    expect($precioPedido)->toBe(8000.0)
        ->and((float) DB::table('order_items')->where('order_id', $pedido->id)->whereNull('parent_id')->value('base_price'))->toBe(8000.0)
        ->and($vista->borradoresAbiertos)->toBe(1)
        // El borrador NO cambia solo: sigue con el precio que vio la asesora hasta que se opere o confirme.
        ->and((float) DB::table('cart_items')->where('cart_id', $borrador->cart_id)->whereNull('parent_id')->value('base_price'))->toBe(8000.0);

    $error = null;
    try {
        f03f_sinCommitPrematuro(fn () => app(ConfirmarVentaService::class)->confirmar($borrador->id, $asesor, $totalVisto));
    } catch (ConfirmacionVentaInvalidaException $e) {
        $error = $e->codigo;
    }

    expect($error)->toBe(ConfirmacionVentaInvalidaException::TOTALES_CAMBIARON)
        ->and(DB::table('orders')->where('customer_id', $cliente->id)->count())->toBe(1);
});

test('PG02-18 volumen: la consulta no hace consultas por variante', function () {
    $contar = function (array $ids) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->consulta->referencias($this->vip2, $ids, 10, true);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    [, $otras] = pg_catalogo(9000.0);
    [, $mas] = pg_catalogo(9500.0);
    $pocas = $contar([$this->s->id]);
    $muchas = $contar(array_merge(array_map(fn ($v) => $v->id, $this->variantes), array_map(fn ($v) => $v->id, $otras), array_map(fn ($v) => $v->id, $mas)));

    expect($muchas)->toBeLessThanOrEqual($pocas + 2);
});

test('PG02-21 si la reindexacion FALLA: precios guardados, lote en error, mensaje honesto; reintentar lo recupera', function () {
    f03b_stock($this->s, 50);
    $cliente = f03b_cliente($this->vip2);
    $catalogo = fn (int $qty) => app(CatalogoVentaService::class)->variante($this->padre->id, $this->s->id, $cliente->id, $qty)->precioUnitario;

    // El indexador de Bagisto falla (p. ej. base caida a mitad del job).
    $this->app->instance(\Webkul\Product\Helpers\Indexers\Price::class, new class
    {
        public function reindexRows($productos): void
        {
            throw new RuntimeException('indice no disponible');
        }
    });

    [, $r] = pg02_aplicar($this, ['operacion' => 'fijo', 'qty' => '1', 'valor' => '6100', 'ids' => [$this->s->id]]);

    expect($r['lote']->indice_estado)->toBe(LotePrecioGrupo::INDICE_ERROR)
        ->and($r['lote']->indice_error)->toContain('indice no disponible')
        ->and(pg02_reglas($this->s->id, $this->vip2->id)[0][3])->toBe(6100.0)
        // Lo que REALMENTE cobra Nueva venta: con 1 u. sigue el indice viejo (8.000); con 2 u. ya el nuevo (6.100).
        ->and($catalogo(1))->toBe(8000.0)
        ->and($catalogo(2))->toBe(6100.0);

    $this->app->forgetInstance(\Webkul\Product\Helpers\Indexers\Price::class);
    $lote = $this->edicion->reindexarLote($this->admin, $r['lote']);

    expect($lote->indice_estado)->toBe(LotePrecioGrupo::INDICE_ACTUALIZADO)
        ->and($catalogo(1))->toBe(6100.0)
        // Limites del escalon 10 (7.500), inmediatamente despues del cambio.
        ->and($catalogo(9))->toBe(6100.0)
        ->and($catalogo(10))->toBe(6100.0);
});

test('PG02-22 HTTP: tras una falla de indice el aviso NO dice «disponible» y el boton de reintento recupera', function () {
    $this->app->instance(\Webkul\Product\Helpers\Indexers\Price::class, new class
    {
        public function reindexRows($productos): void
        {
            throw new RuntimeException('indice no disponible');
        }
    });

    $q = ['grupo' => $this->vip2->id, 'q' => $this->padre->sku];
    $post = ['filtros' => $q, 'operacion' => 'fijo', 'qty' => 1, 'valor' => '6200', 'alcance' => 'seleccion', 'ids' => [$this->s->id]];
    $html = $this->actingAs($this->admin, 'admin')->post(route('admin.ventas_asesor.precios_grupo.previsualizar'), $post)->getContent();
    preg_match('/name="token" value="([0-9a-f]{64})"/', $html, $m);

    $this->actingAs($this->admin, 'admin')->post(route('admin.ventas_asesor.precios_grupo.confirmar'), $post + ['token' => $m[1]])
        ->assertRedirect()->assertSessionHas('error');
    $lote = LotePrecioGrupo::query()->sole();

    $this->actingAs($this->admin, 'admin')->get(route('admin.ventas_asesor.precios_grupo.index', $q + ['lote' => $lote->lote]))
        ->assertOk()->assertSee('data-indice="error"', false)->assertSee('data-form="reindexar"', false)
        ->assertDontSee(trans('ventasasesor::app.precios.indice-ok'));

    $this->app->forgetInstance(\Webkul\Product\Helpers\Indexers\Price::class);
    $this->actingAs($this->admin, 'admin')->post(route('admin.ventas_asesor.precios_grupo.reindexar', $lote->lote))
        ->assertRedirect()->assertSessionHas('success', trans('ventasasesor::app.precios.reindexado'));
    expect($lote->fresh()->indice_estado)->toBe(LotePrecioGrupo::INDICE_ACTUALIZADO)
        ->and(pg_indice($this->s, $this->vip2))->toBe(6200.0);
});

test('PG02-23 redondeo: al peso, a $50 y a $100 (al multiplo mas cercano; la mitad sube) con el calculado visible', function () {
    $casos = [
        ['1', 8248.0],   // 8.000 × 1,031 = 8.248
        ['50', 8250.0],  // 8.248 → 8.250
        ['100', 8200.0], // 8.248 → 8.200
    ];

    foreach ($casos as [$redondeo, $esperado]) {
        $s = $this->edicion->solicitud(['grupo' => $this->vip2->id, 'operacion' => 'ajustar', 'modo_ajuste' => 'porcentaje', 'valor' => '3.1', 'redondeo' => $redondeo, 'qty' => '1', 'ids' => [$this->s->id]]);
        $f = $this->edicion->previsualizar($this->admin, $s)->filas[0];

        expect($f['nueva']['valor'])->toBe($esperado, "redondeo {$redondeo}")
            ->and($f['valor_calculado'])->toBe(8248.0);
    }

    // Justo en la mitad sube: 8.000 + 250 = 8.250 → $100 = 8.300.
    $s = $this->edicion->solicitud(['grupo' => $this->vip2->id, 'operacion' => 'ajustar', 'modo_ajuste' => 'importe', 'valor' => '250', 'redondeo' => '100', 'qty' => '1', 'ids' => [$this->s->id]]);
    expect($this->edicion->previsualizar($this->admin, $s)->filas[0]['nueva']['valor'])->toBe(8300.0);
});

test('PG02-24 cupon del carrito: se aplica DESPUES, sobre el precio de grupo (no se reemplazan)', function () {
    Mail::fake();
    f03f_configurarBase();
    f03b_stock($this->s, 50);
    $asesor = f03b_admin();
    $cliente = f03b_cliente($this->vip2);

    $regla = \Webkul\CartRule\Models\CartRule::factory()->afterCreating(function ($r) {
        $r->cart_rule_customer_groups()->sync([$this->vip2->id]);
        $r->cart_rule_channels()->sync([core()->getDefaultChannel()->id]);
    })->create([
        'name' => 'PG02 cupon 10', 'action_type' => 'by_percent', 'discount_amount' => 10, 'usage_per_customer' => 0, 'uses_per_coupon' => 0,
        'condition_type' => 1, 'status' => 1, 'discount_quantity' => 0, 'apply_to_shipping' => 0, 'use_auto_generation' => 0, 'times_used' => 0,
        'coupon_type' => 1, 'end_other_rules' => 0, 'uses_attribute_conditions' => 0, 'discount_step' => 0, 'free_shipping' => 0, 'sort_order' => 0,
        'conditions' => [], 'starts_from' => null, 'ends_till' => null,
    ]);
    \Webkul\CartRule\Models\CartRuleCoupon::factory()->create(['cart_rule_id' => $regla->id, 'code' => $codigo = 'PG02-'.uniqid(), 'type' => 0, 'is_primary' => 1]);

    $borrador = f03f_borradorListo($cliente->id, $asesor, [[$this->padre, $this->s, 2]]);
    $carrito = \Webkul\Checkout\Models\Cart::query()->find($borrador->cart_id);
    app(\ByLopez\VentasAsesor\Support\Venta\CarritoComercial::class)->operar($carrito, $cliente->id, function () use ($codigo) {
        \Webkul\Checkout\Facades\Cart::setCouponCode($codigo)->collectTotals();
    });

    $linea = DB::table('cart_items')->where('cart_id', $borrador->cart_id)->whereNull('parent_id')->first();
    // Precio de grupo 8.000 × 2 = 16.000; cupon 10 % = 1.600 sobre ESE precio.
    expect((float) $linea->base_price)->toBe(8000.0)
        ->and((float) $linea->base_discount_amount)->toBe(1600.0);
});

test('PG02-25 conflicto con la FICHA NATIVA de Bagisto: una vista previa vieja no se aplica', function () {
    $producto = f03f_simple(9000.0);
    $fila = pg_regla($producto, $this->vip2, 1, 'fixed', 8000);
    pg_reindexar($producto);

    $s = $this->edicion->solicitud(['grupo' => $this->vip2->id, 'operacion' => 'fijo', 'qty' => '1', 'valor' => '7000', 'ids' => [$producto->id]]);
    $vista = $this->edicion->previsualizar($this->admin, $s);

    // Otra persona cambia el precio de grupo desde Catalogo › Productos › Editar.
    $this->actingAs($this->admin, 'admin')->putJson(route('admin.catalog.products.update', $producto->id), [
        'sku' => $producto->sku, 'url_key' => $producto->url_key, 'short_description' => 'Corta', 'description' => 'Larga',
        'name' => 'Ficha PG02-25', 'price' => 9000, 'weight' => 1, 'channel' => core()->getCurrentChannelCode(), 'locale' => app()->getLocale(),
        'customer_group_prices' => [$fila->id => ['customer_group_id' => $this->vip2->id, 'qty' => 1, 'value_type' => 'fixed', 'value' => 7500]],
    ])->assertRedirect();

    expect(pg02_error(fn () => $this->edicion->confirmar($this->admin, $s, $vista->token)))->toBe(Invalido::CONFLICTO)
        ->and(pg02_reglas($producto->id, $this->vip2->id)[0][3])->toBe(7500.0)
        ->and(LotePrecioGrupo::query()->count())->toBe(0);
});

test('PG02-26 filtro con / sin precio especifico y conteo de TODOS los resultados (no solo la pagina)', function () {
    [$padre2, $vars2] = pg_catalogo(9000.0);
    config(['ventasasesor.precios_grupo.por_pagina' => 1]);
    $sku = fn ($p) => ['grupo' => $this->vip2->id, 'q' => $p->sku];

    $con = $this->consulta->idsDelFiltro($sku($this->padre) + ['regla' => 'con']);
    $sin = $this->consulta->idsDelFiltro($sku($padre2) + ['regla' => 'sin']);
    $conDelOtro = $this->consulta->idsDelFiltro($sku($padre2) + ['regla' => 'con']);

    expect($con)->toEqualCanonicalizing(array_map(fn ($v) => $v->id, $this->variantes))
        ->and($sin)->toEqualCanonicalizing(array_map(fn ($v) => $v->id, $vars2))
        ->and($conDelOtro)->toBe([]);

    $todos = $this->consulta->listar($this->vip2, ['grupo' => $this->vip2->id, 'q' => 'x'.$this->padre->sku], 1, false);
    $ambos = DB::table('products')->whereIn('parent_id', [$this->padre->id, $padre2->id])->count();
    $lista = $this->consulta->listar($this->vip2, ['grupo' => $this->vip2->id, 'familia' => $this->padre->attribute_family_id], 1, false);
    expect($lista['referencias']->items())->toHaveCount(1)
        ->and($lista['total_variantes'])->toBeGreaterThanOrEqual($ambos)
        ->and($lista['referencias']->total())->toBeGreaterThanOrEqual(2);
});
