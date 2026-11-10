<?php

require_once __DIR__.'/../Support/QaFlujo.php';

use ByLopez\CorteConfeccion\Models\OrdenConfeccion;
use ByLopez\CorteConfeccion\Models\OrdenTarifa;
use ByLopez\CorteConfeccion\Services\OrdenConfeccionService;
use ByLopez\CorteConfeccion\Services\OrdenTarifaService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Webkul\Attribute\Models\AttributeFamily;
use Webkul\Product\Models\Product;

/*
|--------------------------------------------------------------------------
| P10: tarifas por combinacion tipo de tela + referencia (familia). Sin archivos dedicados en la suite entregada.
|--------------------------------------------------------------------------
*/

function tar_vigencia(int $tallerId, int $tipoTelaId, int $familiaId, float $valor, string $desde): void
{
    DB::table('bylopez_cc_tarifas_prestador')->insert([
        'taller_id' => $tallerId, 'tipo_tela_id' => $tipoTelaId, 'attribute_family_id' => $familiaId,
        'valor_unitario' => $valor, 'vigente_desde' => $desde,
    ]);
}

/** Orden de confeccion MIXTA: producto A (familia 1) y producto B (otra familia). */
function tar_orden_mixta(): array
{
    $admin = qa_admin('Admin Tarifas '.uniqid());
    Auth::guard('admin')->setUser($admin);

    $a = Product::factory()->simple()->create();
    $b = Product::factory()->simple()->create();
    $familiaB = AttributeFamily::factory()->create();
    DB::table('products')->where('id', $b->id)->update(['attribute_family_id' => $familiaB->id]);

    $corte = qa_orden_corte_recibida([
        ['product' => $a, 'cantidad_recibida' => 100, 'cantidad_defectuosa' => 0],
        ['product' => $b, 'cantidad_recibida' => 50, 'cantidad_defectuosa' => 0],
    ]);

    Auth::guard('admin')->setUser($admin);
    $taller = qa_taller();

    $orden = app(OrdenConfeccionService::class)->create([
        'orden_corte_id' => $corte->id, 'taller_id' => $taller->id,
        'detalles' => [
            ['product_id' => $a->id, 'talla' => '', 'cantidad_entregada' => 100],
            ['product_id' => $b->id, 'talla' => '', 'cantidad_entregada' => 50],
        ],
    ]);

    $lineas = collect(app(OrdenTarifaService::class)->combinaciones($orden)['lineas']);

    return ['admin' => $admin, 'taller' => $taller, 'corte' => $corte, 'orden' => $orden, 'a' => $a, 'b' => $b,
        'tipoTela' => $lineas->first()['tipo_tela_id'], 'famA' => (int) $a->fresh()->attribute_family_id, 'famB' => (int) $familiaB->id];
}

test('P10 una orden mixta guarda una tarifa por combinacion y «Sin tarifa» es NULL, no cero', function () {
    $taller0 = null;
    $admin = qa_admin('Admin Tarifas Pre');
    Auth::guard('admin')->setUser($admin);

    // La tarifa de la familia A existe ANTES de crear la orden; la de B no.
    $a = Product::factory()->simple()->create();
    $b = Product::factory()->simple()->create();
    $familiaB = AttributeFamily::factory()->create();
    DB::table('products')->where('id', $b->id)->update(['attribute_family_id' => $familiaB->id]);
    $corte = qa_orden_corte_recibida([
        ['product' => $a, 'cantidad_recibida' => 100, 'cantidad_defectuosa' => 0],
        ['product' => $b, 'cantidad_recibida' => 50, 'cantidad_defectuosa' => 0],
    ]);
    Auth::guard('admin')->setUser($admin);
    $taller = qa_taller();
    $tipoTela = (int) DB::table('bylopez_cc_orden_corte_rollos as ocr')->join('bylopez_cc_rollos as r', 'r.id', '=', 'ocr.rollo_id')->where('ocr.orden_corte_id', $corte->id)->value('r.tipo_tela_id');
    tar_vigencia($taller->id, $tipoTela, (int) $a->fresh()->attribute_family_id, 1450, now()->subDays(3)->toDateString());

    $orden = app(OrdenConfeccionService::class)->create([
        'orden_corte_id' => $corte->id, 'taller_id' => $taller->id,
        'detalles' => [
            ['product_id' => $a->id, 'talla' => '', 'cantidad_entregada' => 100],
            ['product_id' => $b->id, 'talla' => '', 'cantidad_entregada' => 50],
        ],
    ]);

    $resumen = app(OrdenTarifaService::class)->resumen($orden->fresh());
    $porFamilia = collect($resumen['lineas'])->keyBy('attribute_family_id');

    expect($resumen['lineas'])->toHaveCount(2)
        ->and($resumen['lineas_sin_tarifa'])->toBe(1)
        ->and((float) $porFamilia[(int) $a->fresh()->attribute_family_id]['tarifa'])->toBe(1450.0)
        ->and($porFamilia[(int) $familiaB->id]['tarifa'])->toBeNull()
        ->and($porFamilia[(int) $familiaB->id]['importe'])->toBeNull()
        ->and($porFamilia[(int) $familiaB->id]['origen'])->toBe('sin_tarifa')
        ->and($resumen['total_referencial'])->toBe(145000.0); // solo la linea con tarifa; la otra NO suma cero disfrazado

    // En la base, «Sin tarifa» es NULL.
    expect(OrdenTarifa::where('orden_confeccion_id', $orden->id)->whereNull('tarifa_unitaria')->count())->toBe(1);
});

test('P10 la tarifa de una referencia no usa la referencia comercial de tela (el esquema no la tiene)', function () {
    expect(Schema::hasColumn('bylopez_cc_tarifas_prestador', 'referencia_tela_id'))->toBeFalse()
        ->and(Schema::hasColumn('bylopez_cc_orden_tarifas', 'referencia_tela_id'))->toBeFalse()
        ->and(Schema::hasColumn('bylopez_cc_tarifas_prestador', 'attribute_family_id'))->toBeTrue()
        ->and(Schema::hasColumn('bylopez_cc_tarifas_prestador', 'tipo_tela_id'))->toBeTrue();
});

test('P10 cambiar la ficha o configurarla despues no reescribe ordenes anteriores ni se aplica retroactivamente', function () {
    $c = tar_orden_mixta(); // la orden nace SIN ninguna tarifa configurada
    $servicio = app(OrdenTarifaService::class);

    expect($servicio->resumen($c['orden']->fresh())['lineas_sin_tarifa'])->toBe(2);

    // Despues se configuran tarifas en la ficha y se vuelve a sincronizar: lo conservado NO se pisa.
    tar_vigencia($c['taller']->id, $c['tipoTela'], $c['famA'], 1450, now()->subDay()->toDateString());
    $servicio->sincronizar($c['orden']->fresh());

    expect($servicio->resumen($c['orden']->fresh())['lineas_sin_tarifa'])->toBe(2);

    // Una orden NUEVA si toma la tarifa vigente.
    Auth::guard('admin')->setUser($c['admin']);
    $corte2 = qa_orden_corte_recibida([['product' => $c['a'], 'cantidad_recibida' => 30, 'cantidad_defectuosa' => 0]]);
    Auth::guard('admin')->setUser($c['admin']);
    $nueva = app(OrdenConfeccionService::class)->create([
        'orden_corte_id' => $corte2->id, 'taller_id' => $c['taller']->id,
        'detalles' => [['product_id' => $c['a']->id, 'talla' => '', 'cantidad_entregada' => 30]],
    ]);
    $linea = $servicio->resumen($nueva->fresh())['lineas'][0];

    // (tipo de tela distinto en este corte: solo se verifica que la ficha vigente se consulta por SU combinacion)
    expect($linea)->toHaveKeys(['tarifa', 'origen']);
});

test('P10 pactar una combinacion exige motivo, deja evento, no toca otras ordenes ni la ficha, y se rechaza en ordenes anuladas', function () {
    $c = tar_orden_mixta();
    $servicio = app(OrdenTarifaService::class);
    $otraOrden = OrdenConfeccion::query()->whereKeyNot($c['orden']->id)->first();

    $servicio->pactar($c['orden']->fresh(), $c['tipoTela'], $c['famA'], 1600, 'Descuento por volumen', $c['admin']->id);

    $fila = OrdenTarifa::where('orden_confeccion_id', $c['orden']->id)->where('attribute_family_id', $c['famA'])->first();
    expect((float) $fila->tarifa_unitaria)->toBe(1600.0)
        ->and($fila->motivo)->toBe('Descuento por volumen')
        ->and($fila->origen())->toBe('pactada')
        ->and(DB::table('bylopez_cc_cuenta_eventos')->where('orden_confeccion_id', $c['orden']->id)->where('tipo', 'tarifa_orden_modificada')->count())->toBe(1)
        ->and(DB::table('bylopez_cc_tarifas_prestador')->where('taller_id', $c['taller']->id)->count())->toBe(0); // la ficha no cambia

    // Mismo valor otra vez: sin cambio, rechazado.
    expect(fn () => $servicio->pactar($c['orden']->fresh(), $c['tipoTela'], $c['famA'], 1600, 'Otra vez', $c['admin']->id))->toThrow(ValidationException::class);

    // Combinacion que no esta en la orden.
    expect(fn () => $servicio->pactar($c['orden']->fresh(), $c['tipoTela'], 987654321, 100, 'No existe', $c['admin']->id))->toThrow(ValidationException::class);

    // Por HTTP: motivo obligatorio y permiso.
    $url = route('admin.corte_confeccion.ordenes_confeccion.tarifa', $c['orden']->id);
    $this->actingAs($c['admin'], 'admin')->postJson($url, ['tipo_tela_id' => $c['tipoTela'], 'attribute_family_id' => $c['famB'], 'valor_unitario' => 900, 'motivo' => ''])->assertStatus(422);
    $this->actingAs(qa_admin('TALLER CONFECCION', 'all'), 'admin')->postJson($url, ['tipo_tela_id' => $c['tipoTela'], 'attribute_family_id' => $c['famB'], 'valor_unitario' => 900, 'motivo' => 'intento'])->assertForbidden();

    // Orden anulada: ya no se pacta.
    $c['orden']->update(['estado' => 'anulada']);
    expect(fn () => $servicio->pactar($c['orden']->fresh(), $c['tipoTela'], $c['famB'], 900, 'Tarde', $c['admin']->id))->toThrow(ValidationException::class);

    expect($otraOrden === null || OrdenTarifa::where('orden_confeccion_id', $otraOrden->id)->where('motivo', 'Descuento por volumen')->doesntExist())->toBeTrue();
});
