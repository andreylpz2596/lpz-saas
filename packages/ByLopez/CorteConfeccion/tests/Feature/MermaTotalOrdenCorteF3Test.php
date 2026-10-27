<?php

use ByLopez\CorteConfeccion\DataGrids\RecepcionCorteDataGrid;
use ByLopez\CorteConfeccion\Enums\EstadoOrdenCorte;
use ByLopez\CorteConfeccion\Enums\EstadoRollo;
use ByLopez\CorteConfeccion\Http\Requests\StoreRecepcionCorteRequest;
use ByLopez\CorteConfeccion\Models\OrdenCorte;
use ByLopez\CorteConfeccion\Models\RecepcionCorte;
use ByLopez\CorteConfeccion\Models\Rollo;
use ByLopez\CorteConfeccion\Services\DisponibilidadConfeccionService;
use ByLopez\CorteConfeccion\Services\OrdenCorteService;
use ByLopez\CorteConfeccion\Services\RecepcionCorteService;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Models\AttributeOption;
use Webkul\Product\Models\Product;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

/*
|--------------------------------------------------------------------------
| F3 - Merma total de tela por Orden de Corte.
|
| Las ordenes se arman DIRECTAMENTE (sin OrdenCorteService::create) para poder
| probar rollos de colores/gramajes distintos sin relajar la compatibilidad de
| create() (eso es F4). La recepcion y el cierre SI se ejecutan por los
| Services reales: son lo que se prueba. Helpers con prefijo f3_.
|--------------------------------------------------------------------------
*/

function f3_admin(string $rol, string $permissionType = 'all'): Admin
{
    $role = Role::factory()->create(['name' => $rol, 'permission_type' => $permissionType]);

    return Admin::factory()->create(['role_id' => $role->id]);
}

function f3_bodegaHttp(): Admin
{
    $role = Role::factory()->create([
        'name' => 'BODEGA',
        'permission_type' => 'custom',
        'permissions' => ['corte_confeccion', 'corte_confeccion.recepciones_corte', 'corte_confeccion.ordenes_corte'],
    ]);

    return Admin::factory()->create(['role_id' => $role->id]);
}

function f3_tipoTela(): AttributeOption
{
    $atributo = Attribute::firstWhere('code', 'tipo_tela')
        ?? Attribute::factory()->create(['code' => 'tipo_tela', 'type' => 'select', 'admin_name' => 'Tipo de tela']);

    return AttributeOption::factory()->for($atributo, 'attribute')->create(['admin_name' => 'Tela F3 '.uniqid()]);
}

/**
 * Rollo ya reservado en una orden (peso_disponible = su peso completo, igual
 * que en el flujo real: reservar NO descuenta peso).
 */
function f3_rollo(string $color, float $peso, AttributeOption $tela, string $gramaje = '180'): Rollo
{
    return Rollo::create([
        'codigo' => 'R-F3-'.uniqid(),
        'proveedor' => 'Proveedor F3',
        'color' => $color,
        'color_option_id' => cc_test_color_option($color.' '.uniqid())->id,
        'tipo_tela' => $tela->admin_name,
        'tipo_tela_id' => $tela->id,
        'referencia' => 'REF-'.$color,
        'gramaje' => $gramaje,
        'peso_inicial' => $peso,
        'peso_disponible' => $peso,
        'estado' => EstadoRollo::ReservadoEnCorte->value,
    ]);
}

/**
 * Orden en "reportado_por_cortador" lista para la recepcion de Bodega. Cada
 * pivote toma el peso completo del rollo (peso_inicial_usado = peso_disponible).
 *
 * @param  list<Rollo>  $rollos
 */
function f3_orden(array $rollos, array $overrides = [], int $piezasReportadas = 20): OrdenCorte
{
    $pesoTotal = round(collect($rollos)->sum(fn ($r) => (float) $r->peso_disponible), 3);

    $orden = OrdenCorte::create(array_merge([
        'codigo' => 'OC-F3-'.uniqid(),
        'rollo_id' => $rollos[0]->id,
        'cortador_nombre' => 'Cortador F3',
        'cortador_nombre_snapshot' => 'Cortador F3',
        'color' => $rollos[0]->color,
        'genero_linea' => 'Hombre',
        'fecha_solicitud' => now()->toDateString(),
        'peso_entregado' => $pesoTotal,
        'peso_total_rollos' => $pesoTotal,
        'estado' => EstadoOrdenCorte::ReportadoPorCortador->value,
    ], $overrides));

    foreach ($rollos as $rollo) {
        $orden->rollosCorte()->create([
            'rollo_id' => $rollo->id,
            'peso_inicial_usado' => $rollo->peso_disponible,
            'entregado_en' => now(),
            'peso_sobrante' => 0,
            'peso_consumido' => 0,
        ]);
    }

    $producto = Product::factory()->simple()->create();

    $orden->detalles()->create([
        'product_id' => $producto->id,
        'producto_sku' => $producto->sku,
        'producto_nombre' => 'Camiseta F3',
        'genero_linea' => 'Hombre',
        'talla' => 'M',
        'cantidad_proyectada' => 0,
        'cantidad_real_cortada' => $piezasReportadas,
        'es_adicional' => false,
    ]);

    return $orden->fresh(['rollosCorte', 'detalles']);
}

function f3_payload(OrdenCorte $orden, mixed $merma, int $recibidas = 20, int $defectuosas = 0): array
{
    return [
        'fecha_recepcion' => now()->toDateString(),
        'merma_total' => $merma,
        'recibido_por' => 'Bodega QA F3',
        'observacion' => null,
        'detalles' => [[
            'orden_corte_detalle_id' => $orden->detalles->first()->id,
            'cantidad_recibida' => $recibidas,
            'cantidad_defectuosa' => $defectuosas,
            'observacion' => null,
        ]],
    ];
}

function f3_recibir(OrdenCorte $orden, mixed $merma, int $recibidas = 20, int $defectuosas = 0): RecepcionCorte
{
    return app(RecepcionCorteService::class)->create($orden, f3_payload($orden, $merma, $recibidas, $defectuosas));
}

/**
 * Orden con la recepcion HISTORICA (pre-F3): sobrante usable + retazos +
 * desperdicio, marcador merma_unica = false (default).
 */
function f3_ordenHistorica(array $rollos, float $sobrante, float $retazos, float $desperdicio): OrdenCorte
{
    $orden = f3_orden($rollos, [
        'peso_sobrante_total' => $sobrante,
        'merma_total' => $retazos + $desperdicio,
        'estado' => EstadoOrdenCorte::RecibidoBodega->value,
    ]);

    RecepcionCorte::create([
        'orden_corte_id' => $orden->id,
        'fecha_recepcion' => now()->toDateString(),
        'peso_sobrante_usable' => $sobrante,
        'peso_retasos' => $retazos,
        'peso_desperdicio' => $desperdicio,
        'merma_total' => $retazos + $desperdicio,
        'recibido_por' => 'Bodega historica',
    ]);

    return $orden->fresh(['rollosCorte', 'detalles', 'recepcion']);
}

/** Foto de los datos "de identidad" de un rollo, para comprobar que no cambian. */
function f3_identidad(Rollo $rollo): array
{
    $r = $rollo->fresh();

    return [
        'color' => $r->color,
        'color_option_id' => $r->color_option_id,
        'tipo_tela_id' => $r->tipo_tela_id,
        'gramaje' => $r->gramaje,
        'referencia' => $r->referencia,
        'proveedor_tela_referencia_id' => $r->proveedor_tela_referencia_id,
        'peso_inicial' => (float) $r->peso_inicial,
    ];
}

beforeEach(function () {
    Auth::guard('admin')->setUser(f3_admin('Bodega QA F3 '.uniqid()));
    $this->tela = f3_tipoTela();
});

test('CASO 1 - merma total simple: se guarda una vez en la orden y ningun rollo recupera esos kg', function () {
    $a = f3_rollo('Negro', 30, $this->tela);
    $b = f3_rollo('Negro', 20, $this->tela);
    $orden = f3_orden([$a, $b]);

    $recepcion = f3_recibir($orden, 4);
    $orden = $orden->fresh();

    expect((float) $recepcion->merma_total)->toBe(4.0)
        ->and((float) $orden->merma_total)->toBe(4.0)
        ->and($orden->merma_unica)->toBeTrue()
        ->and((float) $orden->peso_sobrante_total)->toBe(0.0)
        ->and((float) $orden->peso_consumido_total)->toBe(46.0)
        ->and($orden->estado)->toBe(EstadoOrdenCorte::RecibidoBodega->value)
        // Los pesos historicos de la recepcion quedan en su default: no se usan.
        ->and((float) $recepcion->peso_sobrante_usable)->toBe(0.0)
        ->and((float) $recepcion->peso_retasos)->toBe(0.0)
        ->and((float) $recepcion->peso_desperdicio)->toBe(0.0);

    // La recepcion NO toca los rollos.
    expect((float) $a->fresh()->peso_disponible)->toBe(30.0)
        ->and((float) $b->fresh()->peso_disponible)->toBe(20.0);

    app(OrdenCorteService::class)->cerrar($orden);

    expect((float) $a->fresh()->peso_disponible)->toBe(0.0)
        ->and((float) $b->fresh()->peso_disponible)->toBe(0.0)
        ->and((float) $orden->fresh()->merma_total)->toBe(4.0);
});

test('CASO 2 - varios rollos del mismo color: la merma no se reparte en cascada ni de ningun otro modo', function () {
    $a = f3_rollo('Negro', 25, $this->tela);
    $b = f3_rollo('Negro', 25, $this->tela);
    $orden = f3_orden([$a, $b]);

    f3_recibir($orden, 3);
    app(OrdenCorteService::class)->cerrar($orden->fresh());

    $pivotes = $orden->fresh()->rollosCorte->keyBy('rollo_id');

    // Reparto historico (A = 3, B = 0) prohibido: ni A, ni B, ni ningun otro.
    expect((float) $pivotes[$a->id]->peso_sobrante)->toBe(0.0)
        ->and((float) $pivotes[$b->id]->peso_sobrante)->toBe(0.0)
        ->and((float) $a->fresh()->peso_disponible)->toBe(0.0)
        ->and((float) $b->fresh()->peso_disponible)->toBe(0.0)
        ->and((float) $pivotes[$a->id]->peso_consumido)->toBe(25.0)
        ->and((float) $pivotes[$b->id]->peso_consumido)->toBe(25.0);
});

test('CASO 3 - colores y gramajes mezclados: cero contaminacion de peso entre colores', function () {
    $negro = f3_rollo('Negro', 20, $this->tela, '180');
    $rojo = f3_rollo('Rojo', 25, $this->tela, '170');
    $vino = f3_rollo('Vino Tinto', 22, $this->tela, '175');
    $rollos = [$negro, $rojo, $vino];
    $orden = f3_orden($rollos);

    // CASO 12: identidad de los rollos antes de recepcionar/cerrar.
    $identidadAntes = collect($rollos)->mapWithKeys(fn ($r) => [$r->id => f3_identidad($r)])->all();

    f3_recibir($orden, 4.8);
    app(OrdenCorteService::class)->cerrar($orden->fresh());

    $orden = $orden->fresh(['rollosCorte']);

    expect((float) $orden->merma_total)->toBe(4.8)
        ->and((float) $orden->peso_consumido_total)->toBe(62.2)   // 67 asignados - 4.8 de merma
        ->and((float) $orden->rollosCorte->sum('peso_sobrante'))->toBe(0.0);

    foreach ($rollos as $rollo) {
        $pivote = $orden->rollosCorte->firstWhere('rollo_id', $rollo->id);

        // Ningun rollo recibe (ni absorbe parte de) los 4.8 kg: cada uno solo
        // conoce lo que salio fisicamente de el, sin sobrante identificado.
        expect((float) $pivote->peso_sobrante)->toBe(0.0)
            ->and((float) $pivote->peso_consumido)->toBe((float) $pivote->peso_inicial_usado)
            ->and((float) $rollo->fresh()->peso_disponible)->toBe(0.0)
            ->and($rollo->fresh()->estado)->toBe(EstadoRollo::Agotado->value)
            // CASO 12: color, color_option_id, tela, gramaje y referencia intactos.
            ->and(f3_identidad($rollo))->toBe($identidadAntes[$rollo->id]);
    }
});

test('CASO 4 - merma cero es valida y sigue marcando la orden como F3 (0 no significa historica)', function () {
    foreach ([0, '0', '0.000', 0.0] as $cero) {
        $rollo = f3_rollo('Negro', 10, $this->tela);
        $orden = f3_orden([$rollo]);

        $recepcion = f3_recibir($orden, $cero);
        $orden = $orden->fresh();

        expect((float) $recepcion->merma_total)->toBe(0.0)
            ->and($orden->merma_unica)->toBeTrue()
            ->and((float) $orden->peso_consumido_total)->toBe(10.0);

        app(OrdenCorteService::class)->cerrar($orden);

        expect((float) $rollo->fresh()->peso_disponible)->toBe(0.0)
            ->and($rollo->fresh()->estado)->toBe(EstadoRollo::Agotado->value);
    }
});

test('CASO 5 - merma negativa o no numerica es rechazada (Service) y no crea recepcion', function () {
    $rollo = f3_rollo('Negro', 10, $this->tela);
    $orden = f3_orden([$rollo]);

    foreach ([-1, '-0.001', 'abc', null, '1.2345'] as $invalida) {
        expect(fn () => f3_recibir($orden->fresh(['detalles']), $invalida))
            ->toThrow(ValidationException::class);
    }

    expect(RecepcionCorte::where('orden_corte_id', $orden->id)->count())->toBe(0)
        ->and($orden->fresh()->estado)->toBe(EstadoOrdenCorte::ReportadoPorCortador->value)
        ->and($orden->fresh()->merma_unica)->toBeFalse();
});

test('CASO 6 - merma mayor al peso de tela asignado es rechazada, la igual es valida', function () {
    $a = f3_rollo('Negro', 25, $this->tela);
    $b = f3_rollo('Rojo', 25, $this->tela);
    $orden = f3_orden([$a, $b]);   // 50 kg asignados

    expect(fn () => f3_recibir($orden->fresh(['detalles']), 50.001))
        ->toThrow(ValidationException::class, 'La merma total no puede superar el peso de tela asignado a la orden.');

    expect(RecepcionCorte::where('orden_corte_id', $orden->id)->count())->toBe(0);

    // Exactamente el peso asignado: todo el peso fue merma (limite inclusivo).
    $recepcion = f3_recibir($orden->fresh(['detalles']), 50);

    expect((float) $recepcion->merma_total)->toBe(50.0)
        ->and((float) $orden->fresh()->peso_consumido_total)->toBe(0.0);
});

test('CASO 7 - segundo intento de recepcion o de cierre no duplica ni reaplica la merma', function () {
    $rollo = f3_rollo('Negro', 30, $this->tela);
    $orden = f3_orden([$rollo]);

    f3_recibir($orden, 4);

    expect(fn () => f3_recibir($orden->fresh(['detalles']), 9))->toThrow(ValidationException::class);

    expect(RecepcionCorte::where('orden_corte_id', $orden->id)->count())->toBe(1)
        ->and((float) $orden->fresh()->merma_total)->toBe(4.0);

    app(OrdenCorteService::class)->cerrar($orden->fresh());

    $pivoteCerrado = $orden->fresh()->rollosCorte->first()->only(['peso_sobrante', 'peso_consumido']);
    $rolloCerrado = $rollo->fresh()->only(['peso_disponible', 'estado']);

    expect(fn () => app(OrdenCorteService::class)->cerrar($orden->fresh()))->toThrow(ValidationException::class)
        ->and(fn () => f3_recibir($orden->fresh(['detalles']), 1))->toThrow(ValidationException::class);

    expect((float) $orden->fresh()->merma_total)->toBe(4.0)
        ->and($orden->fresh()->rollosCorte->first()->only(['peso_sobrante', 'peso_consumido']))->toBe($pivoteCerrado)
        ->and($rollo->fresh()->only(['peso_disponible', 'estado']))->toBe($rolloCerrado);
});

test('CASO 8 - orden historica sigue legible y no se recalcula al abrirla', function () {
    $rollo = f3_rollo('Negro', 22, $this->tela);
    $orden = f3_ordenHistorica([$rollo], sobrante: 2, retazos: 2, desperdicio: 2);

    // Cerrada con la cascada historica: el pivote registra el sobrante usable.
    app(OrdenCorteService::class)->cerrar($orden);
    $orden = $orden->fresh(['rollosCorte']);
    $pivote = $orden->rollosCorte->first();

    expect($orden->merma_unica)->toBeFalse()
        ->and((float) $orden->peso_sobrante_total)->toBe(2.0)
        ->and((float) $orden->merma_total)->toBe(4.0)
        ->and((float) $pivote->peso_sobrante)->toBe(2.0)
        ->and((float) $pivote->peso_consumido)->toBe(20.0)
        ->and((float) $rollo->fresh()->peso_disponible)->toBe(2.0)
        ->and($rollo->fresh()->estado)->toBe(EstadoRollo::ParcialmenteUsado->value);

    $antes = $orden->only(['peso_sobrante_total', 'merma_total', 'peso_consumido_total', 'merma_unica']);

    $respuesta = $this->actingAs(f3_admin('Admin QA F3 Viewer'), 'admin')
        ->get(route('admin.corte_confeccion.ordenes_corte.show', $orden->id));

    $respuesta->assertOk()
        ->assertSee('Peso sobrante')
        ->assertDontSee('Merma no reutilizable');

    // Abrir la vista no escribe nada.
    expect($orden->fresh()->only(['peso_sobrante_total', 'merma_total', 'peso_consumido_total', 'merma_unica']))->toBe($antes)
        ->and((float) $rollo->fresh()->peso_disponible)->toBe(2.0);
});

test('CASO 9 - la recepcion de prendas sigue igual: cantidades, defectuosas, identidad y total de piezas', function () {
    $rollo = f3_rollo('Negro', 30, $this->tela);
    $orden = f3_orden([$rollo], piezasReportadas: 20);
    $detalle = $orden->detalles->first();

    $payload = f3_payload($orden, 2, recibidas: 18, defectuosas: 3);
    $payload['detalles'][0]['observacion'] = 'Faltan 2 prendas';

    $recepcion = app(RecepcionCorteService::class)->create($orden, $payload);
    $fila = $recepcion->detalles->first();

    expect($fila->orden_corte_detalle_id)->toBe($detalle->id)
        ->and($fila->product_id)->toBe($detalle->product_id)
        ->and($fila->producto_sku)->toBe($detalle->producto_sku)
        ->and($fila->talla)->toBe('M')
        ->and($fila->cantidad_recibida)->toBe(18)
        ->and($fila->cantidad_defectuosa)->toBe(3)
        ->and($fila->observacion)->toBe('Faltan 2 prendas')
        // La cantidad reportada por el cortador es historica e intacta.
        ->and($detalle->fresh()->cantidad_real_cortada)->toBe(20)
        ->and($orden->fresh()->total_piezas_cortadas)->toBe(18);
});

test('CASO 10 - la merma de tela no altera la disponibilidad para confeccion', function () {
    $ordenSinMerma = f3_orden([f3_rollo('Negro', 30, $this->tela)]);
    $ordenConMerma = f3_orden([f3_rollo('Negro', 30, $this->tela)]);

    f3_recibir($ordenSinMerma, 0, recibidas: 20, defectuosas: 4);
    f3_recibir($ordenConMerma, 12.5, recibidas: 20, defectuosas: 4);

    $disponibilidad = app(DisponibilidadConfeccionService::class);

    $sin = $disponibilidad->porOrdenCorte($ordenSinMerma->fresh())->first();
    $con = $disponibilidad->porOrdenCorte($ordenConMerma->fresh())->first();

    // Depende solo de las prendas recibidas/defectuosas (RecepcionCorteDetalle).
    expect($sin['cantidad_recibida'])->toBe(20)
        ->and($sin['cantidad_defectuosa'])->toBe(4)
        ->and($sin['cantidad_disponible_confeccion'])->toBe(16)
        ->and($con['cantidad_apta_confeccion'])->toBe($sin['cantidad_apta_confeccion'])
        ->and($con['cantidad_disponible_confeccion'])->toBe(16)
        ->and($disponibilidad->disponiblePara($ordenConMerma->fresh(), $con['product_id']))->toBe(16);
});

test('CASO 11 - la merma nunca incrementa Rollo.peso_disponible (ni en la recepcion ni en el cierre)', function () {
    $rollos = [f3_rollo('Negro', 20, $this->tela), f3_rollo('Rojo', 15, $this->tela)];
    $orden = f3_orden($rollos);
    $antes = collect($rollos)->mapWithKeys(fn ($r) => [$r->id => (float) $r->peso_disponible])->all();

    f3_recibir($orden, 6);

    foreach ($rollos as $rollo) {
        expect((float) $rollo->fresh()->peso_disponible)->toBe($antes[$rollo->id]);   // recepcion: sin cambios
    }

    app(OrdenCorteService::class)->cerrar($orden->fresh());

    foreach ($rollos as $rollo) {
        $despues = (float) $rollo->fresh()->peso_disponible;

        expect($despues)->toBeLessThanOrEqual($antes[$rollo->id])
            ->and($despues)->not->toBe(6.0)
            ->and($despues)->toBe(0.0);
    }
});

test('CASO 13 - el limite fisico usa el peso realmente entregado (peso_inicial_usado es el rollo completo)', function () {
    $rollos = [f3_rollo('Negro', 25, $this->tela), f3_rollo('Rojo', 25, $this->tela), f3_rollo('Vino Tinto', 22.5, $this->tela)];

    // Los 3 entregados: limite = 72.5.
    $completa = f3_orden($rollos);

    expect((float) $completa->peso_entregado)->toBe(72.5)
        ->and($completa->pesoBase())->toBe(72.5)
        ->and(fn () => f3_recibir($completa->fresh(['detalles']), 72.501))->toThrow(ValidationException::class);

    expect((float) f3_recibir($completa->fresh(['detalles']), 72.5)->merma_total)->toBe(72.5);

    // Solo 2 rollos entregados (50 kg) aunque la orden tenga 72.5 reservados: manda lo entregado.
    $parcial = f3_orden($rollos, ['peso_entregado' => 50]);

    expect($parcial->pesoBase())->toBe(50.0)
        ->and(fn () => f3_recibir($parcial->fresh(['detalles']), 60))->toThrow(ValidationException::class, 'La merma total no puede superar');

    // Sin entrega registrada, el limite cae al peso total de los rollos.
    $sinEntrega = f3_orden($rollos, ['peso_entregado' => 0]);

    expect($sinEntrega->pesoBase())->toBe(72.5);
});

test('CASO 14 - la cascada historica aplica SOLO a ordenes historicas; una orden F3 nunca entra por ella', function () {
    // Historica: 5 kg de sobrante usable -> cascada original (rollo 1 recibe hasta su peso, rollo 2 el resto).
    $h1 = f3_rollo('Negro', 3, $this->tela);
    $h2 = f3_rollo('Negro', 24, $this->tela);
    $historica = f3_ordenHistorica([$h1, $h2], sobrante: 5, retazos: 0, desperdicio: 0);

    app(OrdenCorteService::class)->cerrar($historica);

    expect((float) $h1->fresh()->peso_disponible)->toBe(3.0)
        ->and($h1->fresh()->estado)->toBe(EstadoRollo::Disponible->value)
        ->and((float) $h2->fresh()->peso_disponible)->toBe(2.0)
        ->and($h2->fresh()->estado)->toBe(EstadoRollo::ParcialmenteUsado->value);

    // F3: mismos pesos, pero la merma de 5 kg no vuelve a ningun rollo.
    $f1 = f3_rollo('Negro', 3, $this->tela);
    $f2 = f3_rollo('Negro', 24, $this->tela);
    $nueva = f3_orden([$f1, $f2]);

    f3_recibir($nueva, 5);
    app(OrdenCorteService::class)->cerrar($nueva->fresh());

    expect((float) $f1->fresh()->peso_disponible)->toBe(0.0)
        ->and($f1->fresh()->estado)->toBe(EstadoRollo::Agotado->value)
        ->and((float) $f2->fresh()->peso_disponible)->toBe(0.0)
        ->and($f2->fresh()->estado)->toBe(EstadoRollo::Agotado->value);

    // Garantia estructural: aunque una orden F3 trajera un peso_sobrante_total
    // anomalo (el contrato de recepcion F3 lo deja siempre en 0), el cierre
    // por el marcador merma_unica JAMAS reparte sobrante entre rollos.
    $a1 = f3_rollo('Negro', 3, $this->tela);
    $a2 = f3_rollo('Rojo', 24, $this->tela);
    $anomala = f3_orden([$a1, $a2], ['merma_unica' => true, 'peso_sobrante_total' => 5, 'merma_total' => 0, 'estado' => EstadoOrdenCorte::RecibidoBodega->value]);
    RecepcionCorte::create(['orden_corte_id' => $anomala->id, 'fecha_recepcion' => now()->toDateString(), 'recibido_por' => 'QA']);

    app(OrdenCorteService::class)->cerrar($anomala->fresh());

    expect((float) $a1->fresh()->peso_disponible)->toBe(0.0)
        ->and((float) $a2->fresh()->peso_disponible)->toBe(0.0)
        ->and((float) $anomala->fresh()->rollosCorte->sum('peso_sobrante'))->toBe(0.0);

    // Historica con 0 y 0: sigue siendo historica (el marcador, no el valor, decide).
    $historicaCero = f3_ordenHistorica([f3_rollo('Rojo', 10, $this->tela)], sobrante: 0, retazos: 0, desperdicio: 0);

    expect($historicaCero->merma_unica)->toBeFalse();
});

test('CASO 14b - el contrato historico (sobrante usable, retazos, desperdicio) ya no crea recepciones nuevas', function () {
    $orden = f3_orden([f3_rollo('Negro', 10, $this->tela)]);

    foreach (['peso_sobrante_usable', 'peso_retasos', 'peso_desperdicio'] as $campo) {
        $payload = f3_payload($orden, 1);
        $payload[$campo] = 2;

        expect(fn () => app(RecepcionCorteService::class)->create($orden->fresh(['detalles']), $payload))
            ->toThrow(ValidationException::class, 'una unica merma total');
    }

    expect(RecepcionCorte::where('orden_corte_id', $orden->id)->count())->toBe(0)
        ->and($orden->fresh()->merma_unica)->toBeFalse();
});

test('HTTP - Request valida la merma: requerida, >= 0, 3 decimales, limite fisico y sin pesos historicos', function () {
    $orden = f3_orden([f3_rollo('Negro', 10, $this->tela), f3_rollo('Rojo', 10, $this->tela)]);
    $bodega = f3_bodegaHttp();
    $url = route('admin.corte_confeccion.recepciones_corte.store', $orden->id);

    $casos = [
        'falta' => [fn (array $p) => array_diff_key($p, ['merma_total' => 1]), 'merma_total'],
        'negativa' => [fn (array $p) => array_merge($p, ['merma_total' => -0.5]), 'merma_total'],
        'demasiados decimales' => [fn (array $p) => array_merge($p, ['merma_total' => 1.2345]), 'merma_total'],
        'mayor al maximo' => [fn (array $p) => array_merge($p, ['merma_total' => 20.001]), 'merma_total'],
        'sobrante usable legacy' => [fn (array $p) => array_merge($p, ['peso_sobrante_usable' => 1]), 'peso_sobrante_usable'],
        'retazos legacy' => [fn (array $p) => array_merge($p, ['peso_retasos' => 1]), 'peso_retasos'],
        'desperdicio legacy' => [fn (array $p) => array_merge($p, ['peso_desperdicio' => 1]), 'peso_desperdicio'],
    ];

    foreach ($casos as $nombre => [$modificar, $campoConError]) {
        $respuesta = $this->actingAs($bodega, 'admin')->post($url, $modificar(f3_payload($orden, 1)));

        $respuesta->assertSessionHasErrors($campoConError);
        expect(RecepcionCorte::where('orden_corte_id', $orden->id)->count())->toBe(0, "el caso '$nombre' creo una recepcion");
    }

    $ok = $this->actingAs($bodega, 'admin')->post($url, f3_payload($orden, 20));

    $ok->assertSessionDoesntHaveErrors();
    expect((float) $orden->fresh()->merma_total)->toBe(20.0)
        ->and($orden->fresh()->merma_unica)->toBeTrue();
});

test('Request aislado - el limite fisico y los pesos historicos se rechazan ANTES de llegar al Service', function () {
    $orden = f3_orden([f3_rollo('Negro', 10, $this->tela), f3_rollo('Rojo', 15, $this->tela)]);   // 25 kg

    $errores = function (array $payload) use ($orden) {
        $uri = '/recepciones-corte/'.$orden->id;
        $request = StoreRecepcionCorteRequest::create($uri, 'POST', $payload);
        $route = new Route('POST', '/recepciones-corte/{ordenCorteId}', []);
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);

        $validator = Validator::make($request->all(), $request->rules(), $request->messages());
        $request->withValidator($validator);

        return $validator->errors();
    };

    expect($errores(f3_payload($orden, 25))->isEmpty())->toBeTrue()
        ->and($errores(f3_payload($orden, 25.001))->first('merma_total'))
        ->toBe('La merma total no puede superar el peso de tela asignado a la orden.')
        ->and($errores(f3_payload($orden, -1))->has('merma_total'))->toBeTrue()
        ->and($errores(f3_payload($orden, 1.2345))->has('merma_total'))->toBeTrue()
        ->and($errores(f3_payload($orden, 1) + ['peso_sobrante_usable' => 1])->has('peso_sobrante_usable'))->toBeTrue();
});

test('UI - el formulario de recepcion pide UNA sola merma para toda la orden, sin desglose por rollo/color', function () {
    $orden = f3_orden([f3_rollo('Negro', 20, $this->tela), f3_rollo('Rojo', 25, $this->tela)]);

    $html = $this->actingAs(f3_bodegaHttp(), 'admin')
        ->get(route('admin.corte_confeccion.ordenes_corte.show', $orden->id))
        ->assertOk()
        ->getContent();

    expect($html)
        ->toContain('name="merma_total"')
        ->toContain('Merma / sobrante no reutilizable')
        ->toContain('Peso total de tela que no puede volver al inventario de rollos. Registre el total de toda la orden, sin separarlo por color.')
        ->toContain('Tela asignada a la orden')
        ->toContain('45.000 kg')
        ->not->toContain('name="peso_sobrante_usable"')
        ->not->toContain('name="peso_retasos"')
        ->not->toContain('name="peso_desperdicio"');

    // Un unico input de peso en el formulario de recepcion.
    expect(substr_count($html, 'step="0.001"'))->toBe(1);
});

test('UI - tras la recepcion la ficha muestra tela asignada, merma no reutilizable y peso aprovechado', function () {
    $orden = f3_orden([f3_rollo('Negro', 20, $this->tela), f3_rollo('Rojo', 25, $this->tela)]);
    f3_recibir($orden, 4.8);

    $this->actingAs(f3_admin('Admin QA F3 Viewer'), 'admin')
        ->get(route('admin.corte_confeccion.ordenes_corte.show', $orden->id))
        ->assertOk()
        ->assertSee('Tela asignada')
        ->assertSee('45.000 kg')
        ->assertSee('Merma no reutilizable')
        ->assertSee('4.800 kg')
        ->assertSee('Peso aprovechado')
        ->assertSee('40.200 kg')
        ->assertDontSee('Peso sobrante');
});

test('Vista de recepcion - F3 solo muestra la merma total; la historica conserva sus tres pesos', function () {
    $nueva = f3_orden([f3_rollo('Negro', 10, $this->tela)]);
    $recepcionNueva = f3_recibir($nueva, 1.5);

    $historica = f3_ordenHistorica([f3_rollo('Rojo', 10, $this->tela)], sobrante: 2, retazos: 1, desperdicio: 1);

    $bodega = f3_bodegaHttp();

    $this->actingAs($bodega, 'admin')
        ->get(route('admin.corte_confeccion.recepciones_corte.show', $recepcionNueva->id))
        ->assertOk()
        ->assertSee('Merma total')
        ->assertDontSee('Peso sobrante usable')
        ->assertDontSee('Peso retasos')
        ->assertDontSee('Peso desperdicio');

    $this->actingAs($bodega, 'admin')
        ->get(route('admin.corte_confeccion.recepciones_corte.show', $historica->recepcion->id))
        ->assertOk()
        ->assertSee('Peso sobrante usable')
        ->assertSee('Peso retasos')
        ->assertSee('Peso desperdicio')
        ->assertSee('Merma total');
});

test('DataGrid - las recepciones F3 no muestran sobrante/retazos/desperdicio (un 0 seria enganoso)', function () {
    $nueva = f3_orden([f3_rollo('Negro', 10, $this->tela)]);
    f3_recibir($nueva, 1.5);

    $historica = f3_ordenHistorica([f3_rollo('Rojo', 10, $this->tela)], sobrante: 2, retazos: 1, desperdicio: 1);

    $filas = app(RecepcionCorteDataGrid::class)->prepareQueryBuilder()->get()->keyBy('orden_codigo');

    expect($filas[$nueva->codigo]->peso_sobrante_usable)->toBeNull()
        ->and($filas[$nueva->codigo]->peso_retasos)->toBeNull()
        ->and($filas[$nueva->codigo]->peso_desperdicio)->toBeNull()
        ->and((float) $filas[$nueva->codigo]->merma_total)->toBe(1.5)
        ->and((float) $filas[$historica->codigo]->peso_sobrante_usable)->toBe(2.0)
        ->and((float) $filas[$historica->codigo]->peso_retasos)->toBe(1.0)
        ->and((float) $filas[$historica->codigo]->merma_total)->toBe(2.0);
});
