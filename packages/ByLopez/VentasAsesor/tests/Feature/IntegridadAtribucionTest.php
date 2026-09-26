<?php

use ByLopez\VentasAsesor\Enums\CanalVenta;
use ByLopez\VentasAsesor\Enums\EstadoAtribucion;
use ByLopez\VentasAsesor\Enums\MecanismoCreacion;
use ByLopez\VentasAsesor\Enums\OrigenPropiedadComercial;
use ByLopez\VentasAsesor\Enums\TipoOperacionAtribucion;
use ByLopez\VentasAsesor\Enums\TipoPropiedadComercial;
use ByLopez\VentasAsesor\Exceptions\AtribucionEnConflictoException;
use ByLopez\VentasAsesor\Exceptions\AtribucionInvalidaException;
use ByLopez\VentasAsesor\Exceptions\HistorialInmutableException;
use ByLopez\VentasAsesor\Models\AtribucionPedido;
use ByLopez\VentasAsesor\Models\LogAsignacionAsesor;
use ByLopez\VentasAsesor\Services\AsignacionAsesorService;
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
| Integridad, idempotencia e historial (Fase 02A1)
|--------------------------------------------------------------------------
|
| Corre solo sobre lpz_saas_testing: el guard de Tests\TestCase bloquea
| cualquier otra base y DatabaseTransactions revierte cada caso.
|
*/

function va21_admin(array|string|null $permisos, string $tipo = 'custom', string $nombre = 'VA21 Admin'): Admin
{
    $rol = Role::factory()->create([
        'name' => $nombre.' rol',
        'permission_type' => $tipo,
        'permissions' => $permisos,
    ]);

    return Admin::factory()->create(['role_id' => $rol->id, 'name' => $nombre]);
}

function va21_asesor(string $nombre = 'VA21 Asesora'): Admin
{
    return va21_admin([
        AutorizacionVentasAsesor::PERMISO_MODULO,
        AutorizacionVentasAsesor::PERMISO_OPERAR_COMO_ASESOR,
    ], 'custom', $nombre);
}

function va21_supervisor(string $nombre = 'VA21 Supervisor'): Admin
{
    return va21_admin([
        AutorizacionVentasAsesor::PERMISO_PEDIDOS_ASIGNAR,
        AutorizacionVentasAsesor::PERMISO_PEDIDOS_REASIGNAR,
    ], 'custom', $nombre);
}

function va21_pedido(): Order
{
    return Order::factory()->create();
}

function va21_registrar(): RegistrarAtribucionService
{
    return app(RegistrarAtribucionService::class);
}

function va21_resolver(): ResolverAtribucionService
{
    return app(ResolverAtribucionService::class);
}

function va21_asignacion(): AsignacionAsesorService
{
    return app(AsignacionAsesorService::class);
}

/** Atribucion sin asesor lista para recibir asignaciones. */
function va21_atribucionSinAsignar(?Order $pedido = null): AtribucionPedido
{
    $pedido ??= va21_pedido();

    return va21_registrar()->registrar($pedido->id, va21_resolver()->paraCreacionAdministrativa(
        va21_admin(null, 'all', 'VA21 Gerencia '.uniqid()),
        MecanismoCreacion::ADMIN_BAGISTO,
        CanalVenta::WHATSAPP,
    ));
}

function va21_logs(AtribucionPedido $atribucion, ?TipoOperacionAtribucion $tipo = null): int
{
    return LogAsignacionAsesor::query()
        ->where('order_attribution_id', $atribucion->id)
        ->when($tipo, fn ($consulta) => $consulta->where('tipo_operacion', $tipo->value))
        ->count();
}

test('1. la atribucion inicial genera exactamente un historial con su contexto', function () {
    $asesor = va21_asesor('VA21 Inicial');
    $pedido = va21_pedido();

    $atribucion = va21_registrar()->registrar($pedido->id, va21_resolver()->paraCreacionAdministrativa(
        $asesor, MecanismoCreacion::VENTAS_ASESOR, CanalVenta::WHATSAPP
    ));

    $log = LogAsignacionAsesor::query()->where('order_attribution_id', $atribucion->id)->sole();

    expect(va21_logs($atribucion))->toBe(1)
        ->and($log->tipo_operacion)->toBe(TipoOperacionAtribucion::ASIGNACION_INICIAL)
        ->and($log->esAsignacionInicial())->toBeTrue()
        ->and($log->tipo_propiedad_anterior)->toBeNull()
        ->and($log->advisor_id_anterior)->toBeNull()
        ->and($log->tipo_propiedad_nuevo)->toBe(TipoPropiedadComercial::ASESOR)
        ->and($log->advisor_id_nuevo)->toBe($asesor->id)
        ->and($log->advisor_nuevo_snapshot)->toBe('VA21 Inicial')
        ->and($log->origen_propiedad)->toBe(OrigenPropiedadComercial::AUTO_ASESOR)
        ->and($log->sales_channel)->toBe(CanalVenta::WHATSAPP)
        ->and($log->creation_source)->toBe(MecanismoCreacion::VENTAS_ASESOR)
        ->and($log->changed_by_admin_id)->toBe($asesor->id)
        ->and($log->changed_by_name_snapshot)->toBe('VA21 Inicial')
        ->and($log->order_id)->toBe($pedido->id)
        ->and($log->motivo)->not->toBeEmpty()
        ->and($log->created_at)->not->toBeNull();
});

test('2. un reintento identico no crea otra atribucion ni otro historial', function () {
    $asesor = va21_asesor();
    $pedido = va21_pedido();
    $servicio = va21_registrar();

    $contexto = fn () => va21_resolver()->paraCreacionAdministrativa(
        $asesor, MecanismoCreacion::VENTAS_ASESOR, CanalVenta::WHATSAPP, cartId: 12, referenciaExterna: 'conv-1'
    );

    $primera = $servicio->registrar($pedido->id, $contexto());
    $actualizado = $primera->updated_at;

    $segunda = $servicio->registrar($pedido->id, $contexto());
    $tercera = $servicio->registrar($pedido->id, $contexto());

    expect($segunda->id)->toBe($primera->id)
        ->and($tercera->id)->toBe($primera->id)
        ->and($segunda->updated_at->equalTo($actualizado))->toBeTrue()
        ->and(AtribucionPedido::query()->where('order_id', $pedido->id)->count())->toBe(1)
        ->and(va21_logs($primera))->toBe(1);
});

test('3. un reintento incompatible lanza conflicto y no toca nada', function (string $campo, callable $otroContexto) {
    $pedido = va21_pedido();
    $servicio = va21_registrar();

    $original = $servicio->registrar($pedido->id, va21_resolver()->paraStorefront(cartId: 5, referenciaExterna: 'ref-1'));
    $antes = $original->replicate();

    try {
        $servicio->registrar($pedido->id, $otroContexto());
        $lanzo = false;
        $camposEnConflicto = [];
    } catch (AtribucionEnConflictoException $e) {
        $lanzo = true;
        $camposEnConflicto = $e->camposEnConflicto;
    }

    $original->refresh();

    expect($lanzo)->toBeTrue()
        ->and($camposEnConflicto)->toContain($campo)
        ->and($original->commercial_owner_type)->toBe($antes->commercial_owner_type)
        ->and($original->advisor_id)->toBe($antes->advisor_id)
        ->and($original->cart_id)->toBe($antes->cart_id)
        ->and($original->external_reference)->toBe($antes->external_reference)
        ->and(va21_logs($original))->toBe(1);
})->with([
    'otra propiedad comercial' => ['commercial_owner_type', fn () => va21_resolver()->paraCreacionAdministrativa(
        va21_asesor('VA21 Conflicto'), MecanismoCreacion::VENTAS_ASESOR, CanalVenta::WHATSAPP
    )],
    'otro carrito' => ['cart_id', fn () => va21_resolver()->paraStorefront(cartId: 99, referenciaExterna: 'ref-1')],
    'otra referencia externa' => ['external_reference', fn () => va21_resolver()->paraStorefront(cartId: 5, referenciaExterna: 'ref-2')],
    'otro estado tecnico' => ['attribution_status', fn () => va21_resolver()->paraFalloTecnico('listener.sin_contexto')],
]);

/*
 * Esta prueba cubre la rama de FILA PREEXISTENTE: la atribucion ya esta cuando el
 * servicio hace su primera consulta. La carrera real, con violacion de unicidad y
 * recuperacion posterior al catch, se prueba en CierreAuditado02A2Test (Fase 02A2).
 */
test('4. con una atribucion preexistente se compara en vez de sobrescribir', function () {
    $pedido = va21_pedido();
    $servicio = va21_registrar();
    $resolver = va21_resolver();

    // Simula al proceso que gano la carrera: inserta sin pasar por el servicio.
    DB::table('bylopez_va_order_attributions')->insert([
        'order_id' => $pedido->id,
        'commercial_owner_type' => TipoPropiedadComercial::EMPRESA->value,
        'commercial_owner_source' => OrigenPropiedadComercial::REGLA_STOREFRONT->value,
        'sales_channel' => CanalVenta::ECOMMERCE->value,
        'creation_source' => MecanismoCreacion::STOREFRONT->value,
        'attribution_status' => EstadoAtribucion::COMPLETA->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $equivalente = $servicio->registrar($pedido->id, $resolver->paraStorefront());

    expect($equivalente->order_id)->toBe($pedido->id)
        ->and($equivalente->commercial_owner_type)->toBe(TipoPropiedadComercial::EMPRESA)
        ->and(AtribucionPedido::query()->where('order_id', $pedido->id)->count())->toBe(1)
        ->and(fn () => $servicio->registrar($pedido->id, $resolver->paraCreacionAdministrativa(
            va21_asesor('VA21 Carrera'), MecanismoCreacion::VENTAS_ASESOR, CanalVenta::WHATSAPP
        )))->toThrow(AtribucionEnConflictoException::class);
});

test('5 y 6. borrar el pedido esta restringido y la atribucion con su historial sobreviven', function () {
    $pedido = va21_pedido();
    $atribucion = va21_registrar()->registrar($pedido->id, va21_resolver()->paraStorefront());

    expect(fn () => Order::query()->whereKey($pedido->id)->delete())->toThrow(QueryException::class)
        ->and(Order::query()->whereKey($pedido->id)->exists())->toBeTrue()
        ->and(AtribucionPedido::query()->whereKey($atribucion->id)->exists())->toBeTrue()
        ->and(va21_logs($atribucion))->toBe(1);
});

test('7 y 8. eliminar al administrador conserva ids y snapshots, y la relacion resuelve nula', function () {
    $asesor = va21_asesor('VA21 Eliminada');
    $pedido = va21_pedido();

    $atribucion = va21_registrar()->registrar($pedido->id, va21_resolver()->paraCreacionAdministrativa(
        $asesor, MecanismoCreacion::VENTAS_ASESOR, CanalVenta::WHATSAPP
    ));

    $idAsesor = $asesor->id;
    Admin::query()->whereKey($idAsesor)->delete();

    $atribucion->refresh();
    $log = LogAsignacionAsesor::query()->where('order_attribution_id', $atribucion->id)->sole();

    expect($atribucion->advisor_id)->toBe($idAsesor)
        ->and($atribucion->created_by_admin_id)->toBe($idAsesor)
        ->and($atribucion->assigned_by_admin_id)->toBe($idAsesor)
        ->and($atribucion->advisor_name_snapshot)->toBe('VA21 Eliminada')
        ->and($atribucion->created_by_name_snapshot)->toBe('VA21 Eliminada')
        ->and($atribucion->commercial_owner_type)->toBe(TipoPropiedadComercial::ASESOR)
        ->and($atribucion->attribution_status)->toBe(EstadoAtribucion::COMPLETA)
        ->and($atribucion->asesor)->toBeNull()
        ->and($atribucion->creador)->toBeNull()
        ->and($log->advisor_id_nuevo)->toBe($idAsesor)
        ->and($log->advisor_nuevo_snapshot)->toBe('VA21 Eliminada');
});

test('9 y 10. el historial no puede actualizarse ni eliminarse', function () {
    $atribucion = va21_atribucionSinAsignar();
    $log = LogAsignacionAsesor::query()->where('order_attribution_id', $atribucion->id)->sole();
    $original = $log->getAttributes();

    expect(fn () => $log->update(['motivo' => 'reescrito']))->toThrow(HistorialInmutableException::class)
        ->and(fn () => LogAsignacionAsesor::query()->whereKey($log->id)->firstOrFail()->delete())
        ->toThrow(HistorialInmutableException::class)
        ->and(fn () => LogAsignacionAsesor::query()->create(['order_attribution_id' => $atribucion->id, 'order_id' => $atribucion->order_id, 'tipo_operacion' => TipoOperacionAtribucion::RECONCILIACION, 'tipo_propiedad_nuevo' => TipoPropiedadComercial::EMPRESA, 'motivo' => 'insercion no autorizada']))
        ->toThrow(HistorialInmutableException::class);

    $log->refresh();

    expect($log->getAttributes())->toBe($original)
        ->and(va21_logs($atribucion))->toBe(1)
        ->and($atribucion->refresh()->commercial_owner_type)->toBe(TipoPropiedadComercial::SIN_ASIGNAR);
});

test('11. la atribucion no puede eliminarse ni por el modelo ni por la base', function () {
    $atribucion = va21_atribucionSinAsignar();

    expect(fn () => $atribucion->delete())->toThrow(HistorialInmutableException::class)
        // La consulta masiva salta los eventos del modelo: ahi responde la clave foranea.
        ->and(fn () => AtribucionPedido::query()->whereKey($atribucion->id)->delete())->toThrow(QueryException::class)
        ->and(AtribucionPedido::query()->whereKey($atribucion->id)->exists())->toBeTrue()
        ->and(va21_logs($atribucion))->toBe(1);
});

test('12. una reasignacion usa el estado vigente y no el del modelo recibido', function () {
    $supervisor = va21_supervisor();
    $soloAsignar = va21_admin([AutorizacionVentasAsesor::PERMISO_PEDIDOS_ASIGNAR], 'custom', 'VA21 Solo asignar');
    $primera = va21_asesor('VA21 Primera');
    $segunda = va21_asesor('VA21 Segunda');

    $atribucion = va21_atribucionSinAsignar();
    $obsoleta = AtribucionPedido::query()->findOrFail($atribucion->id);

    // Otro proceso ya asigno la venta: la copia $obsoleta sigue diciendo "sin asignar".
    va21_asignacion()->asignarAsesor($atribucion, $supervisor, $primera, 'Asignacion vigente');

    expect($obsoleta->commercial_owner_type)->toBe(TipoPropiedadComercial::SIN_ASIGNAR)
        // Con el estado real, esto ya es una REASIGNACION y 'assign' no basta.
        ->and(fn () => va21_asignacion()->asignarAsesor($obsoleta, $soloAsignar, $segunda, 'Intento con estado viejo'))
        ->toThrow(AtribucionInvalidaException::class)
        ->and($atribucion->refresh()->advisor_id)->toBe($primera->id)
        ->and(va21_logs($atribucion))->toBe(2);

    $resultado = va21_asignacion()->asignarAsesor($obsoleta, $supervisor, $segunda, 'Reasignacion autorizada');
    $log = LogAsignacionAsesor::query()->where('order_attribution_id', $atribucion->id)->latest('id')->firstOrFail();

    expect($resultado->advisor_id)->toBe($segunda->id)
        ->and($log->tipo_operacion)->toBe(TipoOperacionAtribucion::REASIGNAR_ASESOR)
        // El anterior sale del estado bloqueado, no del modelo obsoleto.
        ->and($log->advisor_id_anterior)->toBe($primera->id)
        ->and($log->tipo_propiedad_anterior)->toBe(TipoPropiedadComercial::ASESOR);
});

test('13. un cambio que no altera nada no genera historial', function () {
    $supervisor = va21_supervisor();
    $asesor = va21_asesor('VA21 Igual');
    $atribucion = va21_atribucionSinAsignar();

    va21_asignacion()->asignarAsesor($atribucion, $supervisor, $asesor, 'Primera asignacion');

    expect(fn () => va21_asignacion()->asignarAsesor($atribucion->refresh(), $supervisor, $asesor, 'Mismo asesor'))
        ->toThrow(AtribucionInvalidaException::class)
        ->and(va21_logs($atribucion))->toBe(2);
});

test('14 a 17. cada operacion de propiedad genera exactamente un historial de su tipo', function () {
    $supervisor = va21_supervisor();
    $primera = va21_asesor('VA21 A');
    $segunda = va21_asesor('VA21 B');
    $servicio = va21_asignacion();
    $atribucion = va21_atribucionSinAsignar();

    $servicio->asignarAsesor($atribucion, $supervisor, $primera, 'Asignar');
    $servicio->asignarAsesor($atribucion->refresh(), $supervisor, $segunda, 'Reasignar');
    $servicio->marcarComoVentaDeEmpresa($atribucion->refresh(), $supervisor, 'Venta de empresa');
    $servicio->dejarSinAsignar($atribucion->refresh(), $supervisor, 'Queda por revisar');

    expect(va21_logs($atribucion))->toBe(5)
        ->and(va21_logs($atribucion, TipoOperacionAtribucion::ASIGNACION_INICIAL))->toBe(1)
        ->and(va21_logs($atribucion, TipoOperacionAtribucion::ASIGNAR_ASESOR))->toBe(1)
        ->and(va21_logs($atribucion, TipoOperacionAtribucion::REASIGNAR_ASESOR))->toBe(1)
        ->and(va21_logs($atribucion, TipoOperacionAtribucion::MARCAR_EMPRESA))->toBe(1)
        ->and(va21_logs($atribucion, TipoOperacionAtribucion::MARCAR_SIN_ASIGNAR))->toBe(1)
        ->and($atribucion->refresh()->commercial_owner_type)->toBe(TipoPropiedadComercial::SIN_ASIGNAR)
        ->and($atribucion->advisor_id)->toBeNull();
});

test('18, 19 y 20. el fallo tecnico tiene camino autorizado, es idempotente y nunca lleva asesor', function () {
    $pedido = va21_pedido();
    $servicio = va21_registrar();

    $atribucion = $servicio->registrarFalloTecnico($pedido->id, va21_resolver(), 'listener.contexto_ausente');
    $repetida = $servicio->registrarFalloTecnico($pedido->id, va21_resolver(), 'listener.contexto_ausente');

    $log = LogAsignacionAsesor::query()->where('order_attribution_id', $atribucion->id)->sole();

    expect($atribucion->attribution_status)->toBe(EstadoAtribucion::FALLO_TECNICO)
        ->and($atribucion->commercial_owner_type)->toBe(TipoPropiedadComercial::SIN_ASIGNAR)
        ->and($atribucion->advisor_id)->toBeNull()
        ->and($atribucion->created_by_admin_id)->toBeNull()
        ->and($repetida->id)->toBe($atribucion->id)
        ->and(AtribucionPedido::query()->where('order_id', $pedido->id)->count())->toBe(1)
        ->and(va21_logs($atribucion))->toBe(1)
        ->and($log->codigo_tecnico)->toBe('listener.contexto_ausente')
        ->and($log->tipo_operacion)->toBe(TipoOperacionAtribucion::ASIGNACION_INICIAL)
        ->and(AtribucionPedido::query()->conIncidencia()->count())->toBe(1)
        // Un codigo con posible informacion sensible se rechaza.
        ->and(fn () => va21_resolver()->paraFalloTecnico('Stack trace: password=abc en /var/www'))
        ->toThrow(AtribucionInvalidaException::class);
});

test('21. no existen campos ni metodos que afirmen comision', function () {
    $columnas = Schema::getColumnListing('bylopez_va_order_attributions');

    expect($columnas)->not->toContain('is_excluded')
        ->and($columnas)->not->toContain('exclusion_reason')
        ->and(method_exists(AtribucionPedido::class, 'esComisionable'))->toBeFalse()
        ->and(method_exists(TipoPropiedadComercial::class, 'esComisionable'))->toBeFalse()
        ->and(method_exists(AtribucionPedido::class, 'esPropiedadDeAsesor'))->toBeTrue();
});

test('22. ninguna migracion del modulo altera tablas del core', function () {
    $migraciones = glob(dirname(__DIR__, 2).'/src/Database/Migrations/*.php');

    // Tres de la Fase 02A/02A1, la cola de incidencias de la Fase 02B1A, el indice de
    // telefonos de RT-04B1a, los borradores de venta de la Fase 03B y las solicitudes
    // comerciales de la Fase 03C (D-39).
    expect($migraciones)->toHaveCount(7);

    foreach ($migraciones as $archivo) {
        $contenido = file_get_contents($archivo);

        preg_match_all("/Schema::(create|table|drop|dropIfExists)\(\s*'([a-z0-9_]+)'/", $contenido, $coincidencias);

        foreach ($coincidencias[2] as $tabla) {
            expect($tabla)->toStartWith('bylopez_va_', basename($archivo).' toca '.$tabla);
        }

        // Puede REFERENCIAR tablas del core en claves foraneas, pero nunca modificarlas.
        expect($contenido)->not->toContain("Schema::table('orders'")
            ->and($contenido)->not->toContain("Schema::table('admins'")
            ->and($contenido)->not->toContain("Schema::table('cart'")
            ->and($contenido)->not->toContain("Schema::table('customers'");
    }

    foreach (['orders', 'admins', 'cart'] as $tabla) {
        expect(Schema::hasColumn($tabla, 'commercial_owner_type'))->toBeFalse()
            ->and(Schema::hasColumn($tabla, 'advisor_id'))->toBeFalse();
    }
});
