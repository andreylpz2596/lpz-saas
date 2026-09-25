<?php

use ByLopez\VentasAsesor\Enums\CodigoIncidencia;
use ByLopez\VentasAsesor\Enums\EstadoIncidencia;
use ByLopez\VentasAsesor\Models\AtribucionPedido;
use ByLopez\VentasAsesor\Models\IncidenciaAtribucion;
use ByLopez\VentasAsesor\Services\IncidenciasAtribucionService;
use ByLopez\VentasAsesor\Services\RegistrarAtribucionService;
use ByLopez\VentasAsesor\Services\ResolverAtribucionService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Webkul\Sales\Models\Order;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Cola operativa de incidencias de atribucion (Fase 02B1A)
|--------------------------------------------------------------------------
|
| Esta tabla es MUTABLE a proposito: se reabre, se cuenta y se resuelve. No es el
| historial de propiedad comercial, que es de solo adicion y nunca se toca.
|
*/

function va34_incidencias(): IncidenciasAtribucionService
{
    return app(IncidenciasAtribucionService::class);
}

function va34_pedido(): Order
{
    return Order::factory()->create();
}

function va34_admin(string $nombre = 'VA34 Operador'): Admin
{
    $rol = Role::factory()->create([
        'name' => $nombre.' rol '.uniqid(),
        'permission_type' => 'custom',
        'permissions' => [AutorizacionVentasAsesor::PERMISO_MODULO],
    ]);

    return Admin::factory()->create(['role_id' => $rol->id, 'name' => $nombre]);
}

test('la tabla de incidencias existe con su unicidad, sus indices y sin campos sensibles', function () {
    expect(Schema::hasTable('bylopez_va_attribution_incidents'))->toBeTrue();

    $columnas = Schema::getColumnListing('bylopez_va_attribution_incidents');

    expect($columnas)->toContain(
        'id', 'order_id', 'order_attribution_id', 'codigo', 'campos_conflicto', 'estado',
        'attempt_count', 'first_detected_at', 'last_detected_at', 'resolved_at',
        'resolved_by_admin_id', 'resolved_by_name_snapshot', 'created_at', 'updated_at',
    );

    // Nada donde puedan colarse valores, payloads, trazas ni datos del cliente.
    foreach (['valores', 'valor_esperado', 'valor_recibido', 'contexto', 'payload', 'request',
        'notas', 'notes', 'mensaje', 'message', 'trace', 'telefono', 'direccion', 'token'] as $prohibida) {
        expect($columnas)->not->toContain($prohibida);
    }

    $indices = collect(Schema::getIndexes('bylopez_va_attribution_incidents'));

    expect($indices->filter(fn ($i) => $i['unique'])->pluck('columns')->all())
        ->toContain(['order_id', 'codigo'])
        ->and($indices->pluck('columns')->all())
        ->toContain(['estado'], ['codigo'], ['last_detected_at']);
});

test('la primera deteccion crea la incidencia con attempt_count igual a 1', function () {
    $pedido = va34_pedido();

    $incidencia = va34_incidencias()->registrar($pedido->id, CodigoIncidencia::LISTENER_ERROR);

    expect($incidencia->attempt_count)->toBe(1)
        ->and($incidencia->estado)->toBe(EstadoIncidencia::ABIERTA)
        ->and($incidencia->codigo)->toBe(CodigoIncidencia::LISTENER_ERROR)
        ->and($incidencia->first_detected_at)->not->toBeNull()
        ->and($incidencia->last_detected_at)->not->toBeNull()
        ->and($incidencia->campos_conflicto)->toBeNull()
        ->and(IncidenciaAtribucion::query()->dePedido($pedido->id)->count())->toBe(1);
});

test('21. repetir la misma incidencia incrementa el contador sin duplicar filas', function () {
    $pedido = va34_pedido();
    $servicio = va34_incidencias();

    $primera = $servicio->registrar($pedido->id, CodigoIncidencia::ATRIBUCION_CONFLICTO);
    $segunda = $servicio->registrar($pedido->id, CodigoIncidencia::ATRIBUCION_CONFLICTO);
    $tercera = $servicio->registrar($pedido->id, CodigoIncidencia::ATRIBUCION_CONFLICTO);

    expect($segunda->id)->toBe($primera->id)
        ->and($tercera->id)->toBe($primera->id)
        ->and($tercera->attempt_count)->toBe(3)
        ->and($tercera->first_detected_at->equalTo($primera->first_detected_at))->toBeTrue()
        ->and(IncidenciaAtribucion::query()->dePedido($pedido->id)->count())->toBe(1);

    // Un codigo distinto SI es otra incidencia.
    $servicio->registrar($pedido->id, CodigoIncidencia::LISTENER_ERROR);

    expect(IncidenciaAtribucion::query()->dePedido($pedido->id)->count())->toBe(2);
});

test('22. los campos de conflicto quedan filtrados, unicos y ordenados', function () {
    $pedido = va34_pedido();

    $incidencia = va34_incidencias()->registrar(
        $pedido->id,
        CodigoIncidencia::ATRIBUCION_CONFLICTO,
        null,
        // Desordenados, repetidos y con nombres que no estan permitidos.
        ['sales_channel', 'advisor_id', 'advisor_id', 'telefono_cliente', 'notas_libres', 'cart_id', 'codigo_tecnico'],
    );

    expect($incidencia->campos_conflicto)
        ->toBe(['advisor_id', 'cart_id', 'codigo_tecnico', 'sales_channel']);

    // Una repeticion fusiona nombres nuevos sin perder los anteriores ni desordenarse.
    $fusionada = va34_incidencias()->registrar(
        $pedido->id,
        CodigoIncidencia::ATRIBUCION_CONFLICTO,
        null,
        ['attribution_status', 'advisor_id', 'payload'],
    );

    expect($fusionada->campos_conflicto)
        ->toBe(['advisor_id', 'attribution_status', 'cart_id', 'codigo_tecnico', 'sales_channel'])
        ->and($fusionada->attempt_count)->toBe(2);
});

test('23. resolver una incidencia no modifica la atribucion y es idempotente', function () {
    $pedido = va34_pedido();
    $operador = va34_admin();

    $atribucion = app(RegistrarAtribucionService::class)
        ->registrar($pedido->id, app(ResolverAtribucionService::class)->paraStorefront());

    $antes = AtribucionPedido::query()->find($atribucion->id)->getAttributes();

    $incidencia = va34_incidencias()->registrar(
        $pedido->id,
        CodigoIncidencia::ATRIBUCION_CONFLICTO,
        $atribucion->id,
        ['advisor_id'],
    );

    $resuelta = va34_incidencias()->resolver($incidencia, $operador);

    expect($resuelta->estado)->toBe(EstadoIncidencia::RESUELTA)
        ->and($resuelta->resolved_by_admin_id)->toBe((int) $operador->id)
        ->and($resuelta->resolved_by_name_snapshot)->toBe($operador->name)
        ->and($resuelta->resolved_at)->not->toBeNull();

    $momento = $resuelta->resolved_at;

    // Idempotente: resolver otra vez no cambia quien ni cuando.
    $otraVez = va34_incidencias()->resolver($resuelta->refresh(), va34_admin('VA34 Otro'));

    expect($otraVez->resolved_by_admin_id)->toBe((int) $operador->id)
        ->and($otraVez->resolved_at->equalTo($momento))->toBeTrue();

    // La atribucion quedo exactamente igual: resolver no reasigna nada.
    expect(AtribucionPedido::query()->find($atribucion->id)->getAttributes())->toBe($antes);
});

test('24. una incidencia resuelta que reaparece vuelve a open y limpia la resolucion', function () {
    $pedido = va34_pedido();

    $incidencia = va34_incidencias()->registrar($pedido->id, CodigoIncidencia::HISTORIAL_INCOHERENTE);
    va34_incidencias()->resolver($incidencia, va34_admin());

    expect($incidencia->refresh()->estado)->toBe(EstadoIncidencia::RESUELTA);

    $reaparecida = va34_incidencias()->registrar($pedido->id, CodigoIncidencia::HISTORIAL_INCOHERENTE);

    expect($reaparecida->id)->toBe($incidencia->id)
        ->and($reaparecida->estado)->toBe(EstadoIncidencia::ABIERTA)
        ->and($reaparecida->attempt_count)->toBe(2)
        ->and($reaparecida->resolved_at)->toBeNull()
        ->and($reaparecida->resolved_by_admin_id)->toBeNull()
        ->and($reaparecida->resolved_by_name_snapshot)->toBeNull()
        ->and(IncidenciaAtribucion::query()->dePedido($pedido->id)->count())->toBe(1);
});

test('la base tambien protege attempt_count mayor o igual a 1', function () {
    $pedido = va34_pedido();
    $incidencia = va34_incidencias()->registrar($pedido->id, CodigoIncidencia::CONTEXTO_INVALIDO);

    expect(fn () => DB::table('bylopez_va_attribution_incidents')
        ->where('id', $incidencia->id)
        ->update(['attempt_count' => 0]))->toThrow(QueryException::class);

    expect($incidencia->refresh()->attempt_count)->toBe(1);
});
