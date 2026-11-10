<?php

require_once __DIR__.'/../Support/QaFlujo.php';

use ByLopez\CorteConfeccion\Models\Notificacion;
use ByLopez\CorteConfeccion\Models\OrdenConfeccionDetalle;
use ByLopez\CorteConfeccion\Models\PrioridadOperacion;
use ByLopez\CorteConfeccion\Models\StockMinimo;
use ByLopez\CorteConfeccion\Services\ConsolidadoConfeccionService;
use ByLopez\CorteConfeccion\Services\OrdenConfeccionService;
use ByLopez\CorteConfeccion\Services\PrioridadConfeccionService;
use ByLopez\CorteConfeccion\Services\StockMinimoService;
use ByLopez\CorteConfeccion\Services\SugerenciaStockService;
use ByLopez\CorteConfeccion\Services\VarianteConsultaService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Webkul\Product\Models\Product;
use Webkul\User\Models\Admin;

/*
|--------------------------------------------------------------------------
| Doc. 09 §9-§11: sugerencia por stock (separada de la prioridad asignada) y minimos por variante.
|--------------------------------------------------------------------------
*/

/** Fija el disponible (indice de Bagisto del canal por defecto); null = la variante no tiene indice. */
function sg_stock(Product $p, ?int $qty): void
{
    $canal = core()->getDefaultChannel()->id;

    if ($qty === null) {
        DB::table('product_inventory_indices')->where('product_id', $p->id)->delete();

        return;
    }

    DB::table('product_inventory_indices')->updateOrInsert(['product_id' => $p->id, 'channel_id' => $canal], ['qty' => $qty, 'created_at' => now(), 'updated_at' => now()]);
}

function sg_minimo(Product $p, ?int $minimo): void
{
    $minimo === null
        ? StockMinimo::query()->where('product_id', $p->id)->delete()
        : StockMinimo::query()->updateOrCreate(['product_id' => $p->id], ['minimo' => $minimo]);
}

function sg(Product $p, ?array $alcance = null): array
{
    return app(SugerenciaStockService::class)->paraProductos([$p->id], $alcance)->get($p->id);
}

test('S01 reglas: cero y negativo Urgente; bajo el minimo Alta; igual o superior Normal; sin minimo y minimo cero se distinguen', function (?int $stock, ?int $minimo, string $nivel, string $motivo) {
    $p = Product::factory()->simple()->create();
    sg_stock($p, $stock);
    sg_minimo($p, $minimo);

    $s = sg($p);

    expect([$s['nivel'], $s['motivo']])->toBe([$nivel, $motivo])
        ->and($s['disponible'])->toBe($stock)
        ->and($s['minimo'])->toBe($minimo);
})->with([
    'stock cero' => [0, 10, 'urgente', 'Sin disponible'],
    'stock negativo' => [-3, 10, 'urgente', 'Disponible negativo'],
    'cero sin minimo' => [0, null, 'urgente', 'Sin disponible'],
    'positivo bajo el minimo' => [4, 10, 'alta', 'Bajo el mínimo'],
    'igual al minimo' => [10, 10, 'normal', 'Cubre el mínimo'],
    'superior al minimo' => [25, 10, 'normal', 'Cubre el mínimo'],
    'positivo sin minimo' => [8, null, 'sin_minimo', 'Sin mínimo configurado'],
    'positivo con minimo cero' => [8, 0, 'normal', 'Cubre el mínimo'],
    'cero con minimo cero' => [0, 0, 'urgente', 'Sin disponible'],
    'sin indice de inventario' => [null, 10, 'sin_informacion', 'Sin información de inventario'],
]);

test('S02 si la consulta de stock falla queda «Sin información», nunca una falsa urgencia', function () {
    $p = Product::factory()->simple()->create();
    sg_stock($p, 0);

    app()->instance(VarianteConsultaService::class, new class extends VarianteConsultaService
    {
        public function stockVendible(iterable $productIds, ?int $channelId = null): Collection
        {
            throw new RuntimeException('inventario inaccesible');
        }
    });

    $s = sg($p);

    expect($s['nivel'])->toBe('sin_informacion')
        ->and($s['disponible'])->toBeNull()
        ->and($s['detalle'])->toBe('No se pudo consultar el inventario');
});

test('S03 las reservas se descuentan una sola vez: se usa el indice vendible de Bagisto, no fisico menos reservas otra vez', function () {
    $p = Product::factory()->simple()->create();
    $fuente = DB::table('channel_inventory_sources')->where('channel_id', core()->getDefaultChannel()->id)->value('inventory_source_id');

    // Fisico 10, reservado 4 -> el indice de Bagisto ya dice 6.
    DB::table('product_inventories')->updateOrInsert(['product_id' => $p->id, 'inventory_source_id' => $fuente, 'vendor_id' => 0], ['qty' => 10]);
    DB::table('product_ordered_inventories')->updateOrInsert(['product_id' => $p->id, 'channel_id' => core()->getDefaultChannel()->id], ['qty' => 4]);
    sg_stock($p, 6);
    sg_minimo($p, 5);

    $s = sg($p);

    expect($s['disponible'])->toBe(6)->and($s['nivel'])->toBe('normal');
});

test('S04 variantes con el mismo nombre pero distinto product_id tienen sugerencias independientes', function () {
    $e = qa_escenario_maestro();
    DB::table('product_flat')->whereIn('product_id', [$e['S']->id, $e['M']->id])->update(['name' => 'Camiseta igual']);
    sg_stock($e['S'], 0);
    sg_stock($e['M'], 50);
    sg_minimo($e['M'], 10);

    $grupos = app(ConsolidadoConfeccionService::class)->consultar(null, [])['grupos'];

    expect($grupos->where('product_id', $e['S']->id)->pluck('sugerencia.nivel')->unique()->values()->all())->toBe(['urgente'])
        ->and($grupos->where('product_id', $e['M']->id)->pluck('sugerencia.nivel')->unique()->values()->all())->toBe(['normal']);
});

test('S05 la misma variante en varios talleres repite la sugerencia global sin cambiar cantidades ni indicadores', function () {
    $e = qa_escenario_maestro();
    sg_stock($e['S'], 0);

    $antes = app(ConsolidadoConfeccionService::class)->consultar(null, ['producto' => $e['S']->id]);
    $grupos = $antes['grupos'];

    expect($grupos)->toHaveCount(2)
        ->and($grupos->pluck('sugerencia.nivel')->unique()->values()->all())->toBe(['urgente'])
        ->and($antes['totales']['por_confeccionar'])->toBe(300);

    sg_stock($e['S'], 999);
    $despues = app(ConsolidadoConfeccionService::class)->consultar(null, ['producto' => $e['S']->id]);

    expect($despues['totales'])->toBe($antes['totales']);
});

test('S06 un cambio de stock se refleja igual en distribucion, ficha de la orden y consolidado', function () {
    $e = qa_escenario_maestro();
    $X = Product::factory()->simple()->create();
    $corte = qa_orden_corte_recibida([['product' => $X, 'cantidad_recibida' => 50, 'cantidad_defectuosa' => 0]]);
    Auth::guard('admin')->setUser($e['admin']);
    $orden = app(OrdenConfeccionService::class)->distribuir(['orden_corte_id' => $corte->id, 'asignaciones' => [
        ['taller_id' => $e['jairo']->id, 'detalles' => [['product_id' => $X->id, 'cantidad' => 20]]],
    ]])->first();
    sg_minimo($X, 10);

    foreach ([[0, 'urgente'], [5, 'alta'], [30, 'normal']] as [$stock, $nivel]) {
        sg_stock($X, $stock);

        $contexto = $this->actingAs($e['admin'], 'admin')->getJson(route('admin.corte_confeccion.ordenes_confeccion.contexto', $corte->id))->assertOk();
        expect(collect($contexto->json('filas'))->firstWhere('product_id', $X->id)['sugerencia']['nivel'])->toBe($nivel);

        $this->actingAs($e['admin'], 'admin')->get(route('admin.corte_confeccion.ordenes_confeccion.show', $orden->id))
            ->assertOk()->assertSee('data-sugerencia="'.$nivel.'"', false);

        $this->actingAs($e['admin'], 'admin')->get(route('admin.corte_confeccion.ordenes_confeccion.consolidado', ['producto' => $X->id]))
            ->assertOk()->assertSee('data-sugerencia="'.$nivel.'"', false)->assertSee('Sugerencia por stock calculada a las');
    }
});

test('S07 prendas pendientes de recibir: ayuda «Priorizar recepción», no suman al disponible; el taller solo ve las suyas y sin cifras globales', function () {
    $e = qa_escenario_maestro();
    Auth::guard('admin')->setUser($e['admin']);
    sg_stock($e['M'], 0);
    sg_minimo($e['M'], 20);

    app(OrdenConfeccionService::class)->reportarTaller($e['ordJ']->fresh(), ['detalles' => [['id' => qa_detalle($e['ordJ'], $e['M'])->id, 'cantidad' => 100]]]);
    app(OrdenConfeccionService::class)->reportarTaller($e['ordA']->fresh(), ['detalles' => [['id' => qa_detalle($e['ordA'], $e['M'])->id, 'cantidad' => 30]]]);

    $global = sg($e['M']);
    expect($global['priorizar_recepcion'])->toBeTrue()
        ->and($global['pendiente_recibir'])->toBe(130)
        ->and($global['disponible'])->toBe(0)
        ->and($global['nivel'])->toBe('urgente');

    expect(sg($e['M'], [$e['andrea']->id])['pendiente_recibir'])->toBe(30);

    // El taller (Andrea) ve nivel, motivo y SOLO sus 30 pendientes; nunca el disponible, el minimo ni el global.
    $andrea = Admin::query()->findOrFail($e['andrea']->usuario_id);
    $html = $this->actingAs($andrea, 'admin')->get(route('admin.corte_confeccion.ordenes_confeccion.consolidado'))->assertOk()->getContent();

    expect($html)->toContain('Sin disponible')
        ->toContain('Priorizar recepción · 30 prendas pendientes')
        ->not->toContain('130 prendas pendientes')
        ->not->toContain('data-sugerencia-detalle')
        ->not->toContain('Disponible 0');

    $this->actingAs($andrea, 'admin')->get(route('admin.corte_confeccion.ordenes_confeccion.show', $e['ordA']->id))
        ->assertOk()->assertSee('Sin disponible')->assertDontSee('data-sugerencia-detalle', false);

    // La administracion ve el global con acceso al detalle.
    $this->actingAs($e['admin'], 'admin')->get(route('admin.corte_confeccion.ordenes_confeccion.consolidado'))
        ->assertOk()->assertSee('Priorizar recepción · 130 prendas pendientes')->assertSee('Disponible 0 · mínimo 20');
});

test('S08 la prioridad asignada manda: una sugerencia distinta nunca cambia cantidades, prioridades ni crea alertas', function () {
    $e = qa_escenario_maestro();
    sg_stock($e['L'], 0); // sugerencia Urgente
    $d = qa_detalle($e['ordJ'], $e['L']);
    $antes = $d->only(['cantidad_entregada', 'cantidad_urgente', 'cantidad_alta', 'prioridad']);

    $grupo = app(ConsolidadoConfeccionService::class)->consultar(null, ['producto' => $e['L']->id])['grupos']->firstWhere('taller_id', $e['jairo']->id);
    $this->actingAs($e['admin'], 'admin')->get(route('admin.corte_confeccion.ordenes_confeccion.show', $e['ordJ']->id))->assertOk();

    expect($grupo['sugerencia']['nivel'])->toBe('urgente')
        ->and($grupo['prioridades'])->toBe(['urgente' => 0, 'alta' => 0, 'normal' => 200])
        ->and($d->fresh()->only(['cantidad_entregada', 'cantidad_urgente', 'cantidad_alta', 'prioridad']))->toBe($antes)
        ->and(PrioridadOperacion::count())->toBe(0)
        ->and(Notificacion::count())->toBe(0);
});

test('S09 filtro por sugerencia distingue Urgente, Alta, Normal, sin minimo y sin informacion; indicadores sobre lo filtrado', function () {
    $e = qa_escenario_maestro();
    sg_stock($e['S'], 0);                       // urgente
    sg_stock($e['M'], 3);
    sg_minimo($e['M'], 5); // alta
    sg_stock($e['L'], null);                    // sin informacion

    $c = fn (string $s) => app(ConsolidadoConfeccionService::class)->consultar(null, ['sugerencia' => $s]);

    expect($c('urgente')['grupos']->pluck('product_id')->unique()->values()->all())->toBe([$e['S']->id])
        ->and($c('urgente')['totales']['por_confeccionar'])->toBe(300)
        ->and($c('alta')['grupos']->pluck('product_id')->unique()->values()->all())->toBe([$e['M']->id])
        ->and($c('alta')['totales']['por_confeccionar'])->toBe(400)
        ->and($c('sin_informacion')['grupos']->pluck('product_id')->unique()->values()->all())->toBe([$e['L']->id])
        ->and($c('sin_minimo')['grupos'])->toHaveCount(0)
        ->and($c('normal')['grupos'])->toHaveCount(0);

    sg_stock($e['L'], 40);
    expect($c('sin_minimo')['grupos']->pluck('product_id')->unique()->values()->all())->toBe([$e['L']->id]);

    $this->actingAs($e['admin'], 'admin')->get(route('admin.corte_confeccion.ordenes_confeccion.consolidado', ['sugerencia' => 'alta']))
        ->assertOk()->assertSee('Bajo el mínimo')->assertDontSee('Sin disponible');
});

test('S10 minimos: vacio = sin minimo, 0 = minimo cero, invalidos se rechazan, cambio simultaneo no se pisa; con historial', function () {
    $e = qa_escenario_maestro();
    $servicio = app(StockMinimoService::class);
    $id = $e['S']->id;

    expect($servicio->guardar([$id => ['nuevo' => '0', 'original' => '']], $e['admin']->id))->toBe(1)
        ->and(StockMinimo::query()->where('product_id', $id)->value('minimo'))->toBe(0);

    expect($servicio->guardar([$id => ['nuevo' => '12', 'original' => '0']], $e['admin']->id))->toBe(1);
    expect($servicio->guardar([$id => ['nuevo' => '12', 'original' => '12']], $e['admin']->id))->toBe(0);

    foreach (['-1', '2.5', 'diez'] as $malo) {
        expect(fn () => $servicio->guardar([$id => ['nuevo' => $malo, 'original' => '12']], $e['admin']->id))->toThrow(ValidationException::class);
    }

    // Otra persona lo cambio a 12; esta pantalla aun creia que era 0: no se guarda nada.
    expect(fn () => $servicio->guardar([$id => ['nuevo' => '30', 'original' => '0'], $e['M']->id => ['nuevo' => '5', 'original' => '']], $e['admin']->id))
        ->toThrow(ValidationException::class);
    expect(StockMinimo::query()->where('product_id', $e['M']->id)->exists())->toBeFalse();

    expect($servicio->guardar([$id => ['nuevo' => '', 'original' => '12']], $e['admin']->id))->toBe(1)
        ->and(StockMinimo::query()->where('product_id', $id)->exists())->toBeFalse();

    $historial = DB::table('bylopez_cc_stock_minimo_cambios')->where('product_id', $id)->orderBy('id')->get(['anterior', 'nuevo'])->map(fn ($h) => [$h->anterior, $h->nuevo])->all();
    expect($historial)->toBe([[null, 0], [0, 12], [12, null]]);

    // Nunca sobre un configurable.
    $padre = Product::factory()->configurable()->create();
    expect(fn () => $servicio->guardar([$padre->id => ['nuevo' => '3', 'original' => '']], $e['admin']->id))->toThrow(ValidationException::class);
});

test('S11 permisos de minimos: consultar y configurar son llaves separadas; un taller vinculado nunca entra aunque tenga todo', function () {
    $e = qa_escenario_maestro();
    $soloVer = qa_admin('Bodega '.uniqid(), 'custom', ['corte_confeccion', 'corte_confeccion.stock_minimos']);
    $editor = qa_admin('Planeacion '.uniqid(), 'custom', ['corte_confeccion', 'corte_confeccion.stock_minimos', 'corte_confeccion.stock_minimos.editar']);
    $sinLlave = qa_admin('Ventas '.uniqid(), 'custom', ['corte_confeccion', 'corte_confeccion.ordenes_confeccion']);
    $taller = Admin::query()->findOrFail($e['andrea']->usuario_id); // rol TALLER CONFECCION con permission_type=all

    $ruta = route('admin.corte_confeccion.stock_minimos.index', ['ambito' => 'todas']);
    $guardar = route('admin.corte_confeccion.stock_minimos.guardar');
    $payload = ['minimos' => [$e['S']->id => ['nuevo' => '7', 'original' => '']]];

    $this->actingAs($soloVer, 'admin')->get($ruta)->assertOk()->assertDontSee('Guardar cambios</button>', false);
    expect($this->actingAs($soloVer, 'admin')->post($guardar, $payload)->status())->toBeIn([401, 403]);

    expect($this->actingAs($sinLlave, 'admin')->get($ruta)->status())->toBeIn([401, 403]);
    expect($this->actingAs($taller, 'admin')->get($ruta)->status())->toBe(403);
    expect($this->actingAs($taller, 'admin')->post($guardar, $payload)->status())->toBe(403);
    expect(StockMinimo::count())->toBe(0);

    $this->actingAs($editor, 'admin')->get($ruta)->assertOk()->assertSee('Guardar cambios</button>', false);
    $this->actingAs($editor, 'admin')->post($guardar, $payload)->assertRedirect();
    expect(StockMinimo::query()->where('product_id', $e['S']->id)->value('minimo'))->toBe(7);
});

test('S12 reporte con saldo por prioridad cambiado: se rechaza, informa y conserva lo escrito', function () {
    $e = qa_escenario_maestro();
    $d = qa_detalle($e['ordA'], $e['M']);
    $andrea = Admin::query()->findOrFail($e['andrea']->usuario_id);
    $retornos = DB::table('bylopez_cc_ordenes_confeccion_retornos')->count();

    // La pantalla vio todo Normal; entretanto el administrador paso 40 a Urgente.
    Auth::guard('admin')->setUser($e['admin']);
    app(PrioridadConfeccionService::class)->cambiar(['idempotency_key' => (string) Str::uuid(), 'cambios' => [['detalle_id' => $d->id, 'cantidad' => 40, 'desde' => 'normal', 'hacia' => 'urgente']]]);

    $r = $this->actingAs($andrea, 'admin')
        ->from(route('admin.corte_confeccion.ordenes_confeccion.show', $e['ordA']->id))
        ->post(route('admin.corte_confeccion.ordenes_confeccion.reportar_taller', $e['ordA']->id), ['detalles' => [
            $d->id => ['id' => $d->id, 'cantidad' => 30, 'normal' => '30', 'base' => '0:0:100'],
        ]]);

    $r->assertRedirect()->assertSessionHasErrors("detalles.{$d->id}.reparto");
    expect(session()->getOldInput("detalles.{$d->id}.cantidad"))->toEqual(30)
        ->and(DB::table('bylopez_cc_ordenes_confeccion_retornos')->count())->toBe($retornos);

    // Con la base actual, el reparto elegido (Normal) se registra tal cual.
    $this->actingAs($andrea, 'admin')->post(route('admin.corte_confeccion.ordenes_confeccion.reportar_taller', $e['ordA']->id), ['detalles' => [
        $d->id => ['id' => $d->id, 'cantidad' => 30, 'urgente' => '0', 'alta' => '0', 'normal' => '30', 'base' => '40:0:60'],
    ]])->assertSessionHasNoErrors();

    expect(OrdenConfeccionDetalle::query()->findOrFail($d->id)->prioridadesPorConfeccionar())->toBe(['urgente' => 40, 'alta' => 0, 'normal' => 30]);
});

test('S13 Debugbar queda por debajo de las modales solo en las pantallas del modulo (desarrollo)', function () {
    $e = qa_escenario_maestro();
    config(['app.debug' => true]);

    $this->actingAs($e['admin'], 'admin')->get(route('admin.corte_confeccion.ordenes_confeccion.consolidado'))
        ->assertOk()->assertSee('div.phpdebugbar { z-index: 9000 !important; }', false);

    config(['app.debug' => false]);

    $this->actingAs($e['admin'], 'admin')->get(route('admin.corte_confeccion.ordenes_confeccion.consolidado'))
        ->assertOk()->assertDontSee('div.phpdebugbar', false);
});
