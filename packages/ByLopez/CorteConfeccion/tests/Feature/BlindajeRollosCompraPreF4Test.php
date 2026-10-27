<?php

use ByLopez\CorteConfeccion\Enums\EstadoOrdenCorte;
use ByLopez\CorteConfeccion\Enums\EstadoRollo;
use ByLopez\CorteConfeccion\Models\CompraTela;
use ByLopez\CorteConfeccion\Models\OrdenCorte;
use ByLopez\CorteConfeccion\Models\Rollo;
use ByLopez\CorteConfeccion\Services\CompraTelaService;
use ByLopez\CorteConfeccion\Services\OrdenCorteService;
use ByLopez\CorteConfeccion\Services\ProveedorTelaService;
use ByLopez\CorteConfeccion\Services\RolloService;
use ByLopez\CorteConfeccion\Support\OrdenCorteEstadoMachine;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Models\AttributeOption;
use Webkul\Product\Models\Product;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

/*
|--------------------------------------------------------------------------
| PRE-F4 / F3.1 - Blindaje de rollos en Compra de Tela y retiro formal.
|
| Un rollo vinculado a una Orden de Corte (pivote orden_corte_rollos O FK
| historica ordenes_corte.rollo_id) nunca se cambia, reactiva, elimina ni
| desvincula desde la Compra de Tela. Antes de en_corte se libera con
| OrdenCorteService::retirarRollo(); desde en_corte queda bloqueado.
| Helpers con prefijo pf4_.
|--------------------------------------------------------------------------
*/

function pf4_admin(string $rol = 'Admin QA PF4', string $permissionType = 'all'): Admin
{
    $role = Role::factory()->create(['name' => $rol, 'permission_type' => $permissionType]);

    return Admin::factory()->create(['role_id' => $role->id]);
}

function pf4_opcion(string $code, string $nombre): AttributeOption
{
    $atributo = Attribute::firstWhere('code', $code)
        ?? Attribute::factory()->create(['code' => $code, 'type' => 'select', 'admin_name' => $nombre]);

    return AttributeOption::factory()->for($atributo, 'attribute')->create(['admin_name' => $nombre.' '.uniqid()]);
}

function pf4_referenciaData(AttributeOption $tela, AttributeOption $color, string $referencia, ?int $id = null): array
{
    return array_filter([
        'id' => $id,
        'tipo_tela_id' => $tela->id,
        'color_option_id' => $color->id,
        'color' => 'ignorado',
        'referencia' => $referencia,
        'gramaje' => '180',
        'valor_kilo_referencia' => 20000,
        'estado' => 'activo',
    ], fn ($v) => $v !== null);
}

/**
 * Proveedor con 2 referencias (Rojo/Azul) y una compra de $cantidad rollos de
 * la referencia roja (10 kg cada uno).
 */
function pf4_escenario(int $cantidad = 3): array
{
    $tela = pf4_opcion('tipo_tela', 'Tela PF4');
    $rojo = pf4_opcion('color', 'Rojo PF4');
    $azul = pf4_opcion('color', 'Azul PF4');

    $proveedor = app(ProveedorTelaService::class)->create([
        'nombre' => 'Proveedor PF4 '.uniqid(),
        'nit' => (string) random_int(800000000, 899999999),
        'telefono' => '3000000000',
        'contacto' => 'QA',
        'direccion' => 'Calle QA',
        'tipo_tela_ids' => [$tela->id],
        'referencias' => [pf4_referenciaData($tela, $rojo, 'REF-ROJA'), pf4_referenciaData($tela, $azul, 'REF-AZUL')],
        'estado' => 'activo',
        'observacion' => null,
    ]);

    $refRoja = $proveedor->referenciasTela()->where('color_option_id', $rojo->id)->firstOrFail();
    $refAzul = $proveedor->referenciasTela()->where('color_option_id', $azul->id)->firstOrFail();

    $compra = app(CompraTelaService::class)->create([
        'codigo' => 'CT-PF4-'.uniqid(),
        'proveedor_id' => $proveedor->id,
        'numero_factura' => null,
        'fecha_compra' => now()->toDateString(),
        'descuento' => 0,
        'impuesto' => 0,
        'observacion' => null,
        'rollos' => collect(range(1, $cantidad))->map(fn ($i) => [
            'codigo' => 'R-PF4-'.$i.'-'.uniqid(),
            'referencia_tela_id' => $refRoja->id,
            'tipo_tela_id' => $tela->id,
            'tipo_tela' => $tela->admin_name,
            'color' => 'x',
            'referencia' => 'REF-ROJA',
            'gramaje' => '180',
            'peso_inicial' => 10,
            'valor_kilo' => 20000,
        ])->all(),
    ]);

    return [
        'tela' => $tela, 'rojo' => $rojo, 'azul' => $azul, 'proveedor' => $proveedor,
        'refRoja' => $refRoja, 'refAzul' => $refAzul, 'compra' => $compra,
        'rollos' => $compra->rollos()->orderBy('id')->get()->all(),
    ];
}

function pf4_payloadRollo(Rollo $rollo, array $cambios = []): array
{
    $r = $rollo->fresh();

    return array_merge([
        'id' => $r->id,
        'codigo' => $r->codigo,
        'tipo_tela_id' => $r->tipo_tela_id,
        'referencia_tela_id' => $r->proveedor_tela_referencia_id,
        'tipo_tela' => $r->tipo_tela,
        'color' => $r->color,
        'referencia' => $r->referencia,
        'gramaje' => $r->gramaje,
        'peso_inicial' => (float) $r->peso_inicial,
        'valor_kilo' => (float) $r->valor_kilo,
        'observacion' => $r->observacion,
    ], $cambios);
}

function pf4_payloadCompra(CompraTela $compra, array $rollosPayload, array $cambios = []): array
{
    $c = $compra->fresh();

    return array_merge([
        'codigo' => $c->codigo,
        'proveedor_id' => $c->proveedor_id,
        'numero_factura' => $c->numero_factura,
        'fecha_compra' => $c->fecha_compra->toDateString(),
        'descuento' => 0,
        'impuesto' => 0,
        'observacion' => $c->observacion,
        'rollos' => $rollosPayload,
    ], $cambios);
}

/** Lo que identifica y "reserva" un rollo, para comprobar que no cambia. */
function pf4_foto(Rollo $rollo): array
{
    return $rollo->fresh()->only(['codigo', 'color', 'color_option_id', 'proveedor_tela_referencia_id', 'estado', 'peso_inicial', 'peso_disponible', 'valor_kilo', 'gramaje', 'referencia']);
}

/**
 * Orden armada directamente (permite cualquier estado). $conPivote = false
 * reproduce las ordenes anteriores al multi-rollo: solo la FK rollo_id.
 *
 * @param  list<Rollo>  $rollos
 */
function pf4_orden(array $rollos, string $estado = 'pendiente_entrega', bool $conPivote = true): OrdenCorte
{
    $orden = OrdenCorte::create([
        'codigo' => 'OC-PF4-'.uniqid(),
        'rollo_id' => $rollos[0]->id,
        'cortador_nombre' => 'Cortador PF4',
        'cortador_nombre_snapshot' => 'Cortador PF4',
        'color' => $rollos[0]->color,
        'genero_linea' => 'Hombre',
        'peso_entregado' => 0,
        'peso_total_rollos' => collect($rollos)->sum(fn ($r) => (float) $r->peso_disponible),
        'estado' => $estado,
    ]);

    foreach ($rollos as $rollo) {
        if ($conPivote) {
            $orden->rollosCorte()->create([
                'rollo_id' => $rollo->id,
                'peso_inicial_usado' => $rollo->peso_disponible,
                'entregado_en' => $estado === EstadoOrdenCorte::PendienteEntrega->value ? null : now(),
                'peso_sobrante' => 0,
                'peso_consumido' => 0,
            ]);
        }

        if ($estado !== EstadoOrdenCorte::Cerrado->value && $estado !== EstadoOrdenCorte::Anulado->value) {
            $rollo->update(['estado' => EstadoRollo::ReservadoEnCorte->value]);
        }
    }

    return $orden->fresh();
}

/**
 * Orden por el flujo REAL de Services (create/entregar/en_corte) para probar el
 * retiro formal con pivotes, pesos y estados reales.
 */
function pf4_ordenReal(array $rollos, string $hasta = 'pendiente_entrega'): OrdenCorte
{
    Auth::guard('admin')->setUser(pf4_admin('Bodega QA PF4 '.uniqid()));

    $servicio = app(OrdenCorteService::class);
    $orden = $servicio->create([
        'rollo_ids' => collect($rollos)->pluck('id')->all(),
        'cortador_id' => pf4_admin('CORTADOR')->id,
        'fecha_entrega' => now()->toDateString(),
        'observacion' => null,
        'detalles' => [['product_id' => Product::factory()->simple()->create()->id, 'genero_linea' => 'Hombre', 'talla' => 'M', 'cantidad_proyectada' => 10]],
    ]);

    if ($hasta === 'pendiente_entrega') {
        return $orden;
    }

    $orden = $servicio->entregar($orden);

    if ($hasta === 'entregado_a_cortador') {
        return $orden;
    }

    return $servicio->cambiarEstado($orden, EstadoOrdenCorte::EntregadoACortador->value, EstadoOrdenCorte::EnCorte->value);
}

beforeEach(function () {
    Auth::guard('admin')->setUser(pf4_admin());
    $this->compras = app(CompraTelaService::class);
});

/*
| Punto de no retorno, derivado de la maquina de estados.
*/

test('MAQUINA - solo pendiente_entrega y entregado_a_cortador estan antes de en_corte; anulado no cuenta como iniciado', function () {
    $antes = [];
    $iniciados = [];

    foreach (EstadoOrdenCorte::cases() as $estado) {
        if (OrdenCorteEstadoMachine::antesDeEnCorte($estado->value)) {
            $antes[] = $estado->value;
        }

        if (OrdenCorteEstadoMachine::haIniciadoCorte($estado->value)) {
            $iniciados[] = $estado->value;
        }
    }

    expect($antes)->toBe(['pendiente_entrega', 'entregado_a_cortador'])
        ->and($iniciados)->toBe(['en_corte', 'reportado_por_cortador', 'recibido_bodega', 'cerrado'])
        ->and(OrdenCorteEstadoMachine::antesDeEnCorte('anulado'))->toBeFalse()
        ->and(OrdenCorteEstadoMachine::haIniciadoCorte('anulado'))->toBeFalse();
});

/*
| Bloqueo desde Compra de Tela.
*/

test('CASO 1 - rollo disponible sin orden: la compra lo modifica segun las reglas normales', function () {
    $e = pf4_escenario(2);
    [$a, $b] = $e['rollos'];

    $this->compras->update($e['compra'], pf4_payloadCompra($e['compra'], [
        pf4_payloadRollo($a, ['peso_inicial' => 12, 'observacion' => 'ajustado']),
        pf4_payloadRollo($b),
    ]));

    expect((float) $a->fresh()->peso_inicial)->toBe(12.0)
        ->and((float) $a->fresh()->peso_disponible)->toBe(12.0)
        ->and($a->fresh()->observacion)->toBe('ajustado')
        ->and($a->fresh()->estaVinculadoAOrdenCorte())->toBeFalse();
});

test('CASO 2 - primer rollo (FK historica + pivote) reservado: la compra no puede cambiar su color ni su referencia', function () {
    $e = pf4_escenario(2);
    [$a, $b] = $e['rollos'];
    pf4_orden([$a, $b]);   // $a es tambien ordenes_corte.rollo_id
    $antes = pf4_foto($a);

    foreach ([
        'texto de color' => ['color' => 'Otro color'],
        'otra referencia' => ['referencia_tela_id' => $e['refAzul']->id, 'color' => $e['refAzul']->color],
    ] as $intento => $cambio) {
        expect(fn () => $this->compras->update($e['compra'], pf4_payloadCompra($e['compra'], [
            pf4_payloadRollo($a, $cambio),
            pf4_payloadRollo($b),
        ])), $intento)->toThrow(ValidationException::class, 'Retirelo primero de la orden');

        expect(pf4_foto($a))->toBe($antes);
    }
});

test('CASO 3 - 2.o/N.o rollo solo por pivote: la compra no puede cambiar su color', function () {
    $e = pf4_escenario(3);
    [$a, $b, $c] = $e['rollos'];
    pf4_orden([$a, $b, $c]);    // $b y $c solo estan en el pivote

    foreach ([$b, $c] as $soloPivote) {
        $antes = pf4_foto($soloPivote);

        expect(Rollo::query()->find($soloPivote->id)->ordenesCorte()->exists())->toBeFalse();   // la FK no lo ve

        $payload = collect($e['rollos'])->map(fn ($r) => $r->id === $soloPivote->id
            ? pf4_payloadRollo($r, ['color' => 'Color manipulado'])
            : pf4_payloadRollo($r))->all();

        expect(fn () => $this->compras->update($e['compra'], pf4_payloadCompra($e['compra'], $payload)))
            ->toThrow(ValidationException::class, 'Retirelo primero de la orden');

        expect(pf4_foto($soloPivote))->toBe($antes);
    }
});

test('CASOS 4, 5 y 6 - 2.o rollo reservado omitido del payload: no se elimina, conserva su pivote y sigue reservado', function () {
    $e = pf4_escenario(2);
    [$a, $b] = $e['rollos'];   // Rollo A y Rollo B; B es el 2.o rollo de la orden
    $orden = pf4_orden([$a, $b]);

    $antes = pf4_foto($b);
    $totalCompra = $e['compra']->fresh()->only(['cantidad_rollos', 'peso_total', 'subtotal']);

    expect(fn () => $this->compras->update($e['compra'], pf4_payloadCompra($e['compra'], [pf4_payloadRollo($a)])))
        ->toThrow(ValidationException::class);

    // CASO 4: sigue existiendo.
    expect(Rollo::query()->find($b->id))->not->toBeNull()
        // CASO 5: su fila del pivote sigue ahi (no hubo cascada).
        ->and($orden->rollosCorte()->where('rollo_id', $b->id)->exists())->toBeTrue()
        ->and($orden->rollosCorte()->count())->toBe(2)
        // CASO 6: sigue reservado, no volvio a disponible.
        ->and($b->fresh()->estado)->toBe(EstadoRollo::ReservadoEnCorte->value)
        ->and(pf4_foto($b))->toBe($antes)
        // Y la compra no quedo a medias.
        ->and($e['compra']->fresh()->only(['cantidad_rollos', 'peso_total', 'subtotal']))->toBe($totalCompra);
});

test('CASO 7 - orden que aun no inicia corte: el mensaje obliga a retirar primero el rollo de la orden', function () {
    foreach ([EstadoOrdenCorte::PendienteEntrega->value, EstadoOrdenCorte::EntregadoACortador->value] as $estado) {
        $e = pf4_escenario(2);
        [$a, $b] = $e['rollos'];
        pf4_orden([$a, $b], $estado);

        expect(fn () => $this->compras->update($e['compra'], pf4_payloadCompra($e['compra'], [pf4_payloadRollo($a), pf4_payloadRollo($b, ['peso_inicial' => 99])])))
            ->toThrow(ValidationException::class, 'este rollo esta reservado en una Orden de Corte. Retirelo primero de la orden antes de modificarlo en la Compra de Tela.');

        expect(fn () => $this->compras->update($e['compra'], pf4_payloadCompra($e['compra'], [pf4_payloadRollo($a)])))
            ->toThrow(ValidationException::class, 'Retirelo primero de la orden');
    }
});

test('CASO 8 - rollo retirado formalmente antes de en_corte queda disponible y ya se puede modificar desde la compra', function () {
    $e = pf4_escenario(3);
    [$a, $b, $c] = $e['rollos'];
    $orden = pf4_ordenReal([$a, $b, $c]);

    expect(fn () => $this->compras->update($e['compra'], pf4_payloadCompra($e['compra'], [
        pf4_payloadRollo($a), pf4_payloadRollo($b, ['peso_inicial' => 15]), pf4_payloadRollo($c),
    ])))->toThrow(ValidationException::class, 'Retirelo primero');

    app(OrdenCorteService::class)->retirarRollo($orden, $b->id);

    expect($b->fresh()->estado)->toBe(EstadoRollo::Disponible->value)
        ->and($b->fresh()->estaVinculadoAOrdenCorte())->toBeFalse()
        ->and($orden->rollosCorte()->where('rollo_id', $b->id)->exists())->toBeFalse();

    // Ahora si: cambia su peso y hasta puede eliminarse (omitido) sin tocar los rollos aun reservados.
    $this->compras->update($e['compra'], pf4_payloadCompra($e['compra'], [
        pf4_payloadRollo($a), pf4_payloadRollo($b, ['peso_inicial' => 15]), pf4_payloadRollo($c),
    ]));

    expect((float) $b->fresh()->peso_inicial)->toBe(15.0)
        ->and((float) $b->fresh()->peso_disponible)->toBe(15.0);

    $this->compras->update($e['compra'], pf4_payloadCompra($e['compra'], [pf4_payloadRollo($a), pf4_payloadRollo($c)]));

    expect(Rollo::query()->find($b->id))->toBeNull()
        ->and(Rollo::query()->find($a->id))->not->toBeNull()
        ->and($orden->rollosCorte()->count())->toBe(2);
});

test('CASO 9 - orden en_corte: el retiro del rollo esta bloqueado', function () {
    $e = pf4_escenario(2);
    [$a, $b] = $e['rollos'];
    $orden = pf4_ordenReal([$a, $b], 'en_corte');

    expect($orden->estado)->toBe(EstadoOrdenCorte::EnCorte->value)
        ->and(fn () => app(OrdenCorteService::class)->retirarRollo($orden, $b->id))
        ->toThrow(ValidationException::class, 'la orden ya inicio el corte');

    expect($orden->rollosCorte()->count())->toBe(2)
        ->and($b->fresh()->estado)->toBe(EstadoRollo::ReservadoEnCorte->value);
});

test('CASOS 10 y 13 - orden en_corte, reportada, recibida o cerrada: modificar/eliminar/retirar sigue bloqueado (tambien solo por pivote)', function () {
    foreach ([
        EstadoOrdenCorte::EnCorte->value,
        EstadoOrdenCorte::ReportadoPorCortador->value,
        EstadoOrdenCorte::RecibidoBodega->value,
        EstadoOrdenCorte::Cerrado->value,
    ] as $estado) {
        $e = pf4_escenario(2);
        [$a, $b] = $e['rollos'];
        $orden = pf4_orden([$a, $b], $estado);   // $b solo por pivote
        $antes = pf4_foto($b);

        expect($b->fresh()->vinculoConOrdenCorte())->toBe(Rollo::VINCULO_INICIADA, $estado)
            ->and($b->fresh()->colorBloqueado())->toBeTrue()
            ->and(fn () => $this->compras->update($e['compra'], pf4_payloadCompra($e['compra'], [pf4_payloadRollo($a), pf4_payloadRollo($b, ['peso_inicial' => 50])])))
            ->toThrow(ValidationException::class, 'ya participo en una Orden de Corte iniciada y no puede modificarse ni eliminarse')
            ->and(fn () => $this->compras->update($e['compra'], pf4_payloadCompra($e['compra'], [pf4_payloadRollo($a)])))
            ->toThrow(ValidationException::class, 'no puede modificarse ni eliminarse')
            ->and(fn () => app(OrdenCorteService::class)->retirarRollo($orden, $b->id))
            ->toThrow(ValidationException::class, 'ya inicio el corte');

        expect(pf4_foto($b))->toBe($antes, $estado)
            ->and($orden->rollosCorte()->where('rollo_id', $b->id)->exists())->toBeTrue()
            ->and(fn () => app(RolloService::class)->update($b->fresh(), ['color_option_id' => pf4_opcion('color', 'Cambio directo PF4')->id]))
            ->toThrow(ValidationException::class);
    }
});

test('CASOS 11 y 12 - la referencia cambia de color: el rollo bloqueado conserva su identidad y el rollo futuro hereda el color nuevo', function () {
    $e = pf4_escenario(2);
    [$a, $b] = $e['rollos'];
    pf4_orden([$a, $b], 'en_corte');

    $colorViejo = $a->color_option_id;
    $snapshotViejo = $a->color;
    $colorNuevo = pf4_opcion('color', 'Verde nuevo PF4');

    // La referencia (roja) pasa a ser verde.
    app(ProveedorTelaService::class)->update($e['proveedor']->fresh(), [
        'nombre' => $e['proveedor']->nombre, 'nit' => $e['proveedor']->nit, 'telefono' => '3000000000', 'contacto' => 'QA', 'direccion' => 'Calle QA',
        'tipo_tela_ids' => [$e['tela']->id],
        'referencias' => [pf4_referenciaData($e['tela'], $colorNuevo, 'REF-ROJA', $e['refRoja']->id)],
        'estado' => 'activo', 'observacion' => null,
    ]);

    expect($e['refRoja']->fresh()->color_option_id)->toBe($colorNuevo->id);

    // Guardar la compra con los rollos bloqueados sin cambios (+ un rollo NUEVO) NO re-sincroniza el color.
    $this->compras->update($e['compra'], pf4_payloadCompra($e['compra'], [
        pf4_payloadRollo($a, ['observacion' => 'nota']),
        pf4_payloadRollo($b),
        [
            'codigo' => 'R-PF4-NUEVO-'.uniqid(), 'referencia_tela_id' => $e['refRoja']->id, 'tipo_tela_id' => $e['tela']->id,
            'tipo_tela' => $e['tela']->admin_name, 'color' => $colorNuevo->admin_name, 'referencia' => 'REF-ROJA', 'gramaje' => '180',
            'peso_inicial' => 8, 'valor_kilo' => 20000,
        ],
    ]));

    // CASO 11: A y B conservan color_option_id y snapshot (solo cambio la observacion de A).
    foreach ([$a, $b] as $bloqueado) {
        expect($bloqueado->fresh()->color_option_id)->toBe($colorViejo)
            ->and($bloqueado->fresh()->color)->toBe($snapshotViejo);
    }
    expect($a->fresh()->observacion)->toBe('nota');

    // CASO 12: el rollo nuevo hereda el color actual de la referencia.
    $nuevo = $e['compra']->rollos()->whereNotIn('id', [$a->id, $b->id])->firstOrFail();

    expect($nuevo->color_option_id)->toBe($colorNuevo->id)
        ->and($nuevo->color)->toBe($colorNuevo->admin_name);
});

test('CASO 13 - orden legacy solo con FK (sin pivote) tambien bloquea: no hay doble logica inconsistente', function () {
    $e = pf4_escenario(2);
    [$a, $b] = $e['rollos'];
    pf4_orden([$a], 'pendiente_entrega', conPivote: false);   // orden anterior al multi-rollo

    expect($a->fresh()->vinculoConOrdenCorte())->toBe(Rollo::VINCULO_RESERVADA)
        ->and($b->fresh()->estaVinculadoAOrdenCorte())->toBeFalse()
        ->and(fn () => $this->compras->update($e['compra'], pf4_payloadCompra($e['compra'], [pf4_payloadRollo($a, ['peso_inicial' => 30]), pf4_payloadRollo($b)])))
        ->toThrow(ValidationException::class, 'Retirelo primero');
});

test('CASO 14 - retirar el PRIMER rollo (el de ordenes_corte.rollo_id) reasigna la FK y ambas fuentes quedan consistentes', function () {
    $e = pf4_escenario(3);
    [$a, $b, $c] = $e['rollos'];
    $orden = pf4_ordenReal([$a, $b, $c]);

    expect((int) $orden->rollo_id)->toBe($a->id);

    $orden = app(OrdenCorteService::class)->retirarRollo($orden, $a->id);

    // La FK NOT NULL ya no apunta a un rollo que no participa: pasa al primer restante.
    expect((int) $orden->rollo_id)->toBe($b->id)
        ->and($orden->color)->toBe($b->color)
        ->and($orden->rollosCorte()->pluck('rollo_id')->all())->toBe([$b->id, $c->id])
        ->and((float) $orden->peso_total_rollos)->toBe(20.0)
        // A no queda ni en el pivote ni en la FK: libre y editable.
        ->and($a->fresh()->estaVinculadoAOrdenCorte())->toBeFalse()
        ->and($a->fresh()->ordenesCorte()->exists())->toBeFalse()
        ->and($a->fresh()->estado)->toBe(EstadoRollo::Disponible->value);

    // El nuevo primero ahora esta protegido tambien por la FK.
    expect($b->fresh()->ordenesCorte()->exists())->toBeTrue()
        ->and(fn () => $this->compras->update($e['compra'], pf4_payloadCompra($e['compra'], [pf4_payloadRollo($a), pf4_payloadRollo($b, ['peso_inicial' => 44]), pf4_payloadRollo($c)])))
        ->toThrow(ValidationException::class);
});

test('RETIRO - reglas: no el ultimo rollo, rollo ajeno a la orden, y recalcula pesos tras la entrega', function () {
    $e = pf4_escenario(3);
    [$a, $b, $c] = $e['rollos'];
    $servicio = app(OrdenCorteService::class);

    $orden = pf4_ordenReal([$a, $b, $c], 'entregado_a_cortador');

    expect((float) $orden->peso_entregado)->toBe(30.0)
        ->and(fn () => $servicio->retirarRollo($orden, 999999999))->toThrow(ValidationException::class, 'no pertenece');

    // Entregado_a_cortador todavia permite retirar y recalcula lo entregado/reservado.
    $orden = $servicio->retirarRollo($orden, $c->id);

    expect($orden->estado)->toBe(EstadoOrdenCorte::EntregadoACortador->value)
        ->and((float) $orden->peso_entregado)->toBe(20.0)
        ->and((float) $orden->peso_total_rollos)->toBe(20.0)
        ->and($c->fresh()->estado)->toBe(EstadoRollo::Disponible->value)
        // El peso del rollo liberado no se toco.
        ->and((float) $c->fresh()->peso_disponible)->toBe(10.0);

    $orden = $servicio->retirarRollo($orden, $b->id);

    expect($orden->rollosCorte()->count())->toBe(1)
        ->and(fn () => $servicio->retirarRollo($orden, $a->id))->toThrow(ValidationException::class, 'ultimo rollo')
        ->and($a->fresh()->estado)->toBe(EstadoRollo::ReservadoEnCorte->value);
});

test('CASO 15 - HTTP manipulado desde la edicion de compra: 422 y datos intactos', function () {
    $e = pf4_escenario(3);
    [$a, $b, $c] = $e['rollos'];
    pf4_orden([$a, $b]);
    $admin = pf4_admin();
    $url = route('admin.corte_confeccion.compras_tela.update', $e['compra']->id);
    $antes = collect([$a, $b, $c])->map(fn ($r) => pf4_foto($r))->all();

    $intentos = [
        // Cambio de referencia coherente (el Request lo acepta): lo frena el Service.
        'otra referencia en rollo reservado' => [pf4_payloadRollo($a), pf4_payloadRollo($b, ['referencia_tela_id' => $e['refAzul']->id, 'color' => $e['refAzul']->color, 'referencia' => 'REF-AZUL']), pf4_payloadRollo($c)],
        // Omitir un rollo reservado.
        'omitir rollo reservado' => [pf4_payloadRollo($a), pf4_payloadRollo($c)],
        // Cambiar peso de un rollo reservado.
        'peso de rollo reservado' => [pf4_payloadRollo($a), pf4_payloadRollo($b, ['peso_inicial' => 77]), pf4_payloadRollo($c)],
    ];

    foreach ($intentos as $nombre => $rollos) {
        $this->actingAs($admin, 'admin')
            ->putJson($url, pf4_payloadCompra($e['compra'], $rollos))
            ->assertStatus(422)
            ->assertJsonValidationErrors('rollos');

        expect(collect([$a, $b, $c])->map(fn ($r) => pf4_foto($r))->all())->toBe($antes, "cambio datos en: $nombre");
    }
});

test('CASO 15b - HTTP: guardar la compra con rollos bloqueados sin cambios funciona aunque la referencia haya cambiado de color', function () {
    $e = pf4_escenario(2);
    [$a, $b] = $e['rollos'];
    pf4_orden([$a, $b], 'en_corte');
    $antes = collect([$a, $b])->map(fn ($r) => pf4_foto($r))->all();

    // La referencia cambia de color despues: el snapshot de los rollos ya no coincide con la referencia ACTUAL.
    app(ProveedorTelaService::class)->update($e['proveedor']->fresh(), [
        'nombre' => $e['proveedor']->nombre, 'nit' => $e['proveedor']->nit, 'telefono' => '3000000000', 'contacto' => 'QA', 'direccion' => 'Calle QA',
        'tipo_tela_ids' => [$e['tela']->id],
        'referencias' => [pf4_referenciaData($e['tela'], pf4_opcion('color', 'Morado PF4'), 'REF-ROJA', $e['refRoja']->id)],
        'estado' => 'activo', 'observacion' => null,
    ]);

    $this->actingAs(pf4_admin(), 'admin')
        ->put(route('admin.corte_confeccion.compras_tela.update', $e['compra']->id), pf4_payloadCompra($e['compra'], [
            pf4_payloadRollo($a, ['observacion' => 'solo nota']),
            pf4_payloadRollo($b),
        ], ['observacion' => 'compra editada']))
        ->assertSessionHasNoErrors();

    expect($e['compra']->fresh()->observacion)->toBe('compra editada')
        ->and($a->fresh()->observacion)->toBe('solo nota')
        ->and(collect([$a, $b])->map(fn ($r) => Arr::except(pf4_foto($r), []))->all())->toBe($antes);
});

test('CASO 16 - un rollo bloqueado en el lote revierte TODO: nada se modifica ni se elimina parcialmente', function () {
    $e = pf4_escenario(4);
    [$a, $b, $c, $d] = $e['rollos'];   // A y B en una orden; C y D libres
    pf4_orden([$a, $b]);

    $fotos = collect([$a, $b, $c, $d])->map(fn ($r) => pf4_foto($r))->all();
    $compraAntes = $e['compra']->fresh()->only(['observacion', 'cantidad_rollos', 'peso_total', 'subtotal', 'total_factura']);

    expect(fn () => $this->compras->update($e['compra'], pf4_payloadCompra($e['compra'], [
        pf4_payloadRollo($a),
        pf4_payloadRollo($b, ['peso_inicial' => 99]),          // bloqueado: cambia
        pf4_payloadRollo($c, ['peso_inicial' => 20]),           // libre: cambiaria
        // $d (libre) omitido: se eliminaria
    ], ['observacion' => 'no debe guardarse'])))->toThrow(ValidationException::class);

    expect(collect([$a, $b, $c, $d])->map(fn ($r) => pf4_foto($r))->all())->toBe($fotos)
        ->and(Rollo::query()->find($d->id))->not->toBeNull()
        ->and($e['compra']->fresh()->only(['observacion', 'cantidad_rollos', 'peso_total', 'subtotal', 'total_factura']))->toBe($compraAntes);
});

test('CASO 17 - un rollo disponible y sin uso omitido del payload se sigue eliminando', function () {
    $e = pf4_escenario(3);
    [$a, $b, $c] = $e['rollos'];
    pf4_orden([$a, $b]);

    $this->compras->update($e['compra'], pf4_payloadCompra($e['compra'], [pf4_payloadRollo($a), pf4_payloadRollo($b)]));

    expect(Rollo::query()->find($c->id))->toBeNull()
        ->and($e['compra']->fresh()->cantidad_rollos)->toBe(2)
        ->and(Rollo::query()->find($a->id))->not->toBeNull()
        ->and(Rollo::query()->find($b->id))->not->toBeNull();
});

test('CASO 18 - orden anulada antes de entregar: el rollo se libera de estado pero sigue vinculado (bloqueado) y puede reservarse de nuevo', function () {
    $e = pf4_escenario(2);
    [$a, $b] = $e['rollos'];
    $servicio = app(OrdenCorteService::class);
    $orden = pf4_ordenReal([$a, $b]);

    $servicio->cancel($orden);

    // Liberacion correcta del estado y del peso...
    expect($a->fresh()->estado)->toBe(EstadoRollo::Disponible->value)
        ->and((float) $a->fresh()->peso_disponible)->toBe(10.0)
        // ...pero pivote y FK se conservan: vinculo historico con una orden anulada.
        ->and($orden->rollosCorte()->count())->toBe(2)
        ->and($a->fresh()->vinculoConOrdenCorte())->toBe(Rollo::VINCULO_SOLO_ANULADAS)
        ->and($a->fresh()->colorBloqueado())->toBeTrue();

    $antes = pf4_foto($a);

    expect(fn () => $this->compras->update($e['compra'], pf4_payloadCompra($e['compra'], [pf4_payloadRollo($a, ['peso_inicial' => 30]), pf4_payloadRollo($b)])))
        ->toThrow(ValidationException::class, 'Orden de Corte anulada')
        ->and(fn () => $this->compras->update($e['compra'], pf4_payloadCompra($e['compra'], [pf4_payloadRollo($b)])))
        ->toThrow(ValidationException::class, 'no puede modificarse ni eliminarse');

    expect(pf4_foto($a))->toBe($antes);

    // Los rollos liberados por la anulacion pueden reservarse en una orden nueva.
    $nueva = pf4_ordenReal([$a, $b]);

    expect($nueva->rollosCorte()->count())->toBe(2)
        ->and($a->fresh()->estado)->toBe(EstadoRollo::ReservadoEnCorte->value);
});

test('F1-D - un rollo bloqueado no reescribe su snapshot aunque llegue el mismo color_option_id (regla conservada)', function () {
    $e = pf4_escenario(2);
    [$a, $b] = $e['rollos'];
    pf4_orden([$a, $b]);

    $snapshot = $b->color;
    DB::table('attribute_options')->where('id', $b->color_option_id)->update(['admin_name' => 'Renombrado PF4']);

    app(RolloService::class)->update($b->fresh(), [
        'codigo' => $b->codigo, 'proveedor' => $b->proveedor, 'tipo_tela' => $b->tipo_tela, 'color_option_id' => $b->color_option_id,
        'peso_inicial' => $b->peso_inicial, 'peso_disponible' => $b->peso_disponible, 'costo_total' => $b->costo_total, 'observacion' => 'ok',
    ]);

    expect($b->fresh()->color)->toBe($snapshot);
});

/*
| Retiro por HTTP y UI.
*/

test('HTTP - retirar rollo: Bodega con permiso lo retira; sin permiso 401; en_corte es rechazado', function () {
    $e = pf4_escenario(2);
    [$a, $b] = $e['rollos'];
    $orden = pf4_ordenReal([$a, $b]);
    $url = route('admin.corte_confeccion.ordenes_corte.rollos.retirar', [$orden->id, $b->id]);

    $sinPermiso = Role::factory()->create(['name' => 'BODEGA', 'permission_type' => 'custom', 'permissions' => ['corte_confeccion', 'corte_confeccion.ordenes_corte']]);
    $this->actingAs(Admin::factory()->create(['role_id' => $sinPermiso->id]), 'admin')->post($url)->assertUnauthorized();

    expect($b->fresh()->estado)->toBe(EstadoRollo::ReservadoEnCorte->value);

    $this->actingAs(pf4_admin('Bodega QA HTTP'), 'admin')
        ->post($url)
        ->assertRedirect(route('admin.corte_confeccion.ordenes_corte.show', $orden->id));

    expect($b->fresh()->estado)->toBe(EstadoRollo::Disponible->value)
        ->and($orden->rollosCorte()->count())->toBe(1);

    $otro = pf4_escenario(2);
    $enCorte = pf4_ordenReal($otro['rollos'], 'en_corte');
    $rolloEnCorte = $enCorte->rollosCorte()->first()->rollo_id;

    $this->actingAs(pf4_admin('Bodega QA HTTP2'), 'admin')
        ->post(route('admin.corte_confeccion.ordenes_corte.rollos.retirar', [$enCorte->id, $rolloEnCorte]))
        ->assertSessionHasErrors('estado');

    expect($enCorte->rollosCorte()->count())->toBe(2);
});

test('UI - la ficha de la orden ofrece Retirar solo antes de en_corte y con mas de un rollo; la edicion de compra marca bloqueado el 2.o rollo', function () {
    $e = pf4_escenario(2);
    [$a, $b] = $e['rollos'];
    $orden = pf4_ordenReal([$a, $b]);
    $admin = pf4_admin('Bodega QA UI');

    $this->actingAs($admin, 'admin')
        ->get(route('admin.corte_confeccion.ordenes_corte.show', $orden->id))
        ->assertOk()
        ->assertSee('rollos/'.$b->id.'/retirar', false)
        ->assertSee('Retirar');

    // La edicion de la compra marca ambos rollos como bloqueados (antes solo el primero, por la FK).
    $html = $this->actingAs($admin, 'admin')
        ->get(route('admin.corte_confeccion.compras_tela.edit', $e['compra']->id))
        ->assertOk()
        ->getContent();

    expect(preg_match_all('/(&quot;|")bloqueado(&quot;|")\s*:\s*true/', $html))->toBe(2);

    // Tras el corte ya no hay boton de retiro.
    $orden->update(['estado' => EstadoOrdenCorte::EnCorte->value]);

    $this->actingAs($admin, 'admin')
        ->get(route('admin.corte_confeccion.ordenes_corte.show', $orden->id))
        ->assertOk()
        ->assertDontSee('rollos/'.$b->id.'/retirar', false);
});

/*
|--------------------------------------------------------------------------
| PRE-F4.1 - Coherencia del punto de no retorno (en_corte): desde alli no se
| agregan ni retiran rollos, y la orden no puede anularse.
|--------------------------------------------------------------------------
*/

test('PRE-F4.1 agregar rollo - permitido antes de en_corte; bloqueado desde en_corte', function () {
    foreach ([
        EstadoOrdenCorte::PendienteEntrega->value => true,
        EstadoOrdenCorte::EntregadoACortador->value => true,
        EstadoOrdenCorte::EnCorte->value => false,
        EstadoOrdenCorte::ReportadoPorCortador->value => false,
        EstadoOrdenCorte::RecibidoBodega->value => false,
        EstadoOrdenCorte::Cerrado->value => false,
    ] as $estado => $permitido) {
        $e = pf4_escenario(3);
        [$a, $b, $libre] = $e['rollos'];
        $orden = pf4_orden([$a, $b], $estado);

        if ($permitido) {
            app(OrdenCorteService::class)->agregarRollo($orden, $libre->id);

            expect($orden->rollosCorte()->count())->toBe(3, $estado)
                ->and($libre->fresh()->estado)->toBe(EstadoRollo::ReservadoEnCorte->value);

            continue;
        }

        expect(fn () => app(OrdenCorteService::class)->agregarRollo($orden, $libre->id))->toThrow(ValidationException::class);

        expect($orden->rollosCorte()->count())->toBe(2, $estado)
            ->and($libre->fresh()->estado)->toBe(EstadoRollo::Disponible->value);
    }

    // En en_corte el motivo es el punto de no retorno (el estado si permitia editar la solicitud).
    $e = pf4_escenario(3);
    $orden = pf4_orden([$e['rollos'][0], $e['rollos'][1]], 'en_corte');

    expect(fn () => app(OrdenCorteService::class)->agregarRollo($orden, $e['rollos'][2]->id))
        ->toThrow(ValidationException::class, 'despues de iniciar el corte');
});

test('PRE-F4.1 retirar rollo - misma frontera que agregar (permitido antes de en_corte, bloqueado desde alli)', function () {
    foreach ([
        EstadoOrdenCorte::PendienteEntrega->value => true,
        EstadoOrdenCorte::EntregadoACortador->value => true,
        EstadoOrdenCorte::EnCorte->value => false,
        EstadoOrdenCorte::ReportadoPorCortador->value => false,
        EstadoOrdenCorte::RecibidoBodega->value => false,
        EstadoOrdenCorte::Cerrado->value => false,
    ] as $estado => $permitido) {
        $e = pf4_escenario(2);
        $orden = pf4_orden($e['rollos'], $estado);

        if ($permitido) {
            app(OrdenCorteService::class)->retirarRollo($orden, $e['rollos'][1]->id);

            expect($orden->rollosCorte()->count())->toBe(1, $estado);

            continue;
        }

        expect(fn () => app(OrdenCorteService::class)->retirarRollo($orden, $e['rollos'][1]->id))->toThrow(ValidationException::class);

        expect($orden->rollosCorte()->count())->toBe(2, $estado);
    }
});

test('PRE-F4.1 cancelar - antes de en_corte libera los rollos', function () {
    foreach ([EstadoOrdenCorte::PendienteEntrega->value, EstadoOrdenCorte::EntregadoACortador->value] as $estado) {
        $e = pf4_escenario(2);
        $orden = pf4_orden($e['rollos'], $estado);

        $anulada = app(OrdenCorteService::class)->cancel($orden);

        expect($anulada->estado)->toBe(EstadoOrdenCorte::Anulado->value);

        foreach ($e['rollos'] as $rollo) {
            expect($rollo->fresh()->estado)->toBe(EstadoRollo::Disponible->value, $estado)
                ->and((float) $rollo->fresh()->peso_disponible)->toBe(10.0);
        }
    }
});

test('PRE-F4.1 cancelar - desde en_corte se bloquea sin tocar rollos, pesos, pivotes ni el estado de la orden', function () {
    foreach ([
        EstadoOrdenCorte::EnCorte->value,
        EstadoOrdenCorte::ReportadoPorCortador->value,
        EstadoOrdenCorte::RecibidoBodega->value,
    ] as $estado) {
        $e = pf4_escenario(2);
        $orden = pf4_orden($e['rollos'], $estado);
        $fotos = collect($e['rollos'])->map(fn ($r) => pf4_foto($r))->all();

        expect(fn () => app(OrdenCorteService::class)->cancel($orden))
            ->toThrow(ValidationException::class, 'No se puede anular una Orden de Corte despues de iniciar el corte.');

        expect($orden->fresh()->estado)->toBe($estado)
            ->and($orden->rollosCorte()->count())->toBe(2)
            ->and(collect($e['rollos'])->map(fn ($r) => pf4_foto($r))->all())->toBe($fotos)
            ->and($e['rollos'][0]->fresh()->estado)->toBe(EstadoRollo::ReservadoEnCorte->value);
    }
});

test('PRE-F4.1 cancelar - una orden cerrada conserva su comportamiento terminal existente', function () {
    $e = pf4_escenario(2);
    $orden = pf4_orden($e['rollos'], 'cerrado');

    expect(fn () => app(OrdenCorteService::class)->cancel($orden))
        ->toThrow(ValidationException::class, 'Una orden cerrada no puede anularse.');

    expect($orden->fresh()->estado)->toBe('cerrado');
});

test('PRE-F4.1 HTTP y UI - el backend rechaza anular/agregar desde en_corte y la ficha oculta ambos botones', function () {
    $e = pf4_escenario(3);
    [$a, $b, $libre] = $e['rollos'];
    $orden = pf4_orden([$a, $b], 'pendiente_entrega');
    $admin = pf4_admin('Bodega QA F41');
    $cancelUrl = route('admin.corte_confeccion.ordenes_corte.cancel', $orden->id);
    $showUrl = route('admin.corte_confeccion.ordenes_corte.show', $orden->id);

    // Antes de en_corte la ficha ofrece Anular y Agregar rollo.
    $this->actingAs($admin, 'admin')->get($showUrl)->assertOk()
        ->assertSee($cancelUrl, false)
        ->assertSee('agregar-rollo-orden-form', false);

    $orden->update(['estado' => EstadoOrdenCorte::EnCorte->value]);

    $this->actingAs($admin, 'admin')->get($showUrl)->assertOk()
        ->assertDontSee($cancelUrl, false)
        ->assertDontSee('agregar-rollo-orden-form', false)
        ->assertDontSee('/retirar', false);

    // El backend sigue siendo la autoridad aunque se fuerce la peticion.
    $this->actingAs($admin, 'admin')->post($cancelUrl)->assertSessionHasErrors('estado');
    $this->actingAs($admin, 'admin')
        ->post(route('admin.corte_confeccion.ordenes_corte.rollos.store', $orden->id), ['rollo_id' => $libre->id])
        ->assertSessionHasErrors('estado');

    expect($orden->fresh()->estado)->toBe(EstadoOrdenCorte::EnCorte->value)
        ->and($orden->rollosCorte()->count())->toBe(2)
        ->and($libre->fresh()->estado)->toBe(EstadoRollo::Disponible->value);
});
