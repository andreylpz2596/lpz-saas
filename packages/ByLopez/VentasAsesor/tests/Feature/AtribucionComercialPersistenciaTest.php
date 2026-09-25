<?php

use ByLopez\VentasAsesor\Enums\CanalVenta;
use ByLopez\VentasAsesor\Enums\EstadoAtribucion;
use ByLopez\VentasAsesor\Enums\MecanismoCreacion;
use ByLopez\VentasAsesor\Enums\OrigenPropiedadComercial;
use ByLopez\VentasAsesor\Enums\TipoOperacionAtribucion;
use ByLopez\VentasAsesor\Enums\TipoPropiedadComercial;
use ByLopez\VentasAsesor\Exceptions\AtribucionEnConflictoException;
use ByLopez\VentasAsesor\Exceptions\AtribucionInvalidaException;
use ByLopez\VentasAsesor\Models\AtribucionPedido;
use ByLopez\VentasAsesor\Models\LogAsignacionAsesor;
use ByLopez\VentasAsesor\Services\AsignacionAsesorService;
use ByLopez\VentasAsesor\Services\RegistrarAtribucionService;
use ByLopez\VentasAsesor\Services\ResolverAtribucionService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Webkul\Sales\Models\Order;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Persistencia de la atribucion comercial (Fase 02A)
|--------------------------------------------------------------------------
|
| Corre exclusivamente sobre lpz_saas_testing: el guard de Tests\TestCase
| bloquea cualquier otra base y DatabaseTransactions revierte cada caso.
|
*/

function va02_admin(array|string|null $permisos, string $tipo = 'custom', string $nombre = 'VA02 Admin'): Admin
{
    $rol = Role::factory()->create([
        'name' => $nombre.' rol',
        'permission_type' => $tipo,
        'permissions' => $permisos,
    ]);

    return Admin::factory()->create(['role_id' => $rol->id, 'name' => $nombre]);
}

function va02_asesorEnBase(string $nombre = 'VA02 Asesora'): Admin
{
    return va02_admin([
        AutorizacionVentasAsesor::PERMISO_MODULO,
        AutorizacionVentasAsesor::PERMISO_OPERAR_COMO_ASESOR,
    ], 'custom', $nombre);
}

function va02_pedido(): Order
{
    return Order::factory()->create();
}

function va02_registrar(): RegistrarAtribucionService
{
    return app(RegistrarAtribucionService::class);
}

function va02_resolverDb(): ResolverAtribucionService
{
    return app(ResolverAtribucionService::class);
}

test('las tablas propias existen con sus columnas, su unicidad y sus indices', function () {
    expect(Schema::hasTable('bylopez_va_order_attributions'))->toBeTrue()
        ->and(Schema::hasTable('bylopez_va_advisor_assignment_logs'))->toBeTrue();

    $columnas = Schema::getColumnListing('bylopez_va_order_attributions');

    expect($columnas)->toContain(
        'order_id', 'created_by_admin_id', 'created_by_name_snapshot', 'advisor_id', 'advisor_name_snapshot',
        'commercial_owner_type', 'commercial_owner_source', 'sales_channel', 'creation_source',
        'attribution_status', 'external_reference', 'cart_id', 'assigned_at', 'assigned_by_admin_id',
        'notes',
    );

    $unicos = collect(Schema::getIndexes('bylopez_va_order_attributions'))
        ->filter(fn ($indice) => $indice['unique'])
        ->flatMap(fn ($indice) => [$indice['columns']])
        ->all();

    expect($unicos)->toContain(['order_id'])
        ->and(Schema::getColumnListing('bylopez_va_advisor_assignment_logs'))->toContain(
            'order_attribution_id', 'order_id', 'tipo_operacion', 'advisor_id_anterior', 'advisor_anterior_snapshot',
            'advisor_id_nuevo', 'advisor_nuevo_snapshot', 'tipo_propiedad_anterior', 'tipo_propiedad_nuevo',
            'origen_propiedad', 'sales_channel', 'creation_source',
            'changed_by_admin_id', 'changed_by_name_snapshot', 'codigo_tecnico', 'motivo',
        );
});

test('el modulo no agrega columnas de atribucion a las tablas del core', function () {
    foreach (['advisor_id', 'created_by_admin_id', 'commercial_owner_type', 'sales_channel', 'creation_source'] as $columna) {
        expect(Schema::hasColumn('orders', $columna))->toBeFalse($columna)
            ->and(Schema::hasColumn('cart', $columna))->toBeFalse($columna);
    }
});

test('registrar una venta de asesor guarda snapshots, estado y fecha de asignacion', function () {
    $asesor = va02_asesorEnBase('VA02 Carolina');
    $pedido = va02_pedido();

    $contexto = va02_resolverDb()->paraCreacionAdministrativa(
        $asesor, MecanismoCreacion::VENTAS_ASESOR, CanalVenta::WHATSAPP, cartId: 77, referenciaExterna: 'conv-9'
    );

    $atribucion = va02_registrar()->registrar($pedido->id, $contexto);

    expect($atribucion->order_id)->toBe($pedido->id)
        ->and($atribucion->advisor_id)->toBe($asesor->id)
        ->and($atribucion->created_by_admin_id)->toBe($asesor->id)
        ->and($atribucion->advisor_name_snapshot)->toBe('VA02 Carolina')
        ->and($atribucion->created_by_name_snapshot)->toBe('VA02 Carolina')
        ->and($atribucion->commercial_owner_type)->toBe(TipoPropiedadComercial::ASESOR)
        ->and($atribucion->commercial_owner_source)->toBe(OrigenPropiedadComercial::AUTO_ASESOR)
        ->and($atribucion->attribution_status)->toBe(EstadoAtribucion::COMPLETA)
        ->and($atribucion->sales_channel)->toBe(CanalVenta::WHATSAPP)
        ->and($atribucion->creation_source)->toBe(MecanismoCreacion::VENTAS_ASESOR)
        ->and($atribucion->cart_id)->toBe(77)
        ->and($atribucion->external_reference)->toBe('conv-9')
        ->and($atribucion->assigned_at)->not->toBeNull()
        ->and($atribucion->assigned_by_admin_id)->toBe($asesor->id)
        ->and($atribucion->esPropiedadDeAsesor())->toBeTrue()
        ->and($atribucion->pedido->id)->toBe($pedido->id)
        ->and($atribucion->asesor->id)->toBe($asesor->id);
});

test('un pedido del storefront queda como venta de empresa sin asesor ni creador', function () {
    $pedido = va02_pedido();

    $atribucion = va02_registrar()->registrar($pedido->id, va02_resolverDb()->paraStorefront());

    expect($atribucion->commercial_owner_type)->toBe(TipoPropiedadComercial::EMPRESA)
        ->and($atribucion->advisor_id)->toBeNull()
        ->and($atribucion->created_by_admin_id)->toBeNull()
        ->and($atribucion->attribution_status)->toBe(EstadoAtribucion::COMPLETA)
        ->and($atribucion->assigned_at)->toBeNull()
        ->and($atribucion->esPropiedadDeAsesor())->toBeFalse();
});

test('un pedido registrado por un administrador total queda sin asignar con creador conocido', function () {
    $admin = va02_admin(null, 'all', 'VA02 Gerencia');
    $pedido = va02_pedido();

    $atribucion = va02_registrar()->registrar($pedido->id, va02_resolverDb()->paraCreacionAdministrativa(
        $admin, MecanismoCreacion::ADMIN_BAGISTO, CanalVenta::TIENDA_FISICA
    ));

    expect($atribucion->commercial_owner_type)->toBe(TipoPropiedadComercial::SIN_ASIGNAR)
        ->and($atribucion->advisor_id)->toBeNull()
        ->and($atribucion->created_by_admin_id)->toBe($admin->id)
        ->and($atribucion->created_by_name_snapshot)->toBe('VA02 Gerencia')
        ->and($atribucion->attribution_status)->toBe(EstadoAtribucion::SIN_ASESOR)
        ->and(AtribucionPedido::query()->sinAsignar()->count())->toBe(1);
});

test('registrar dos veces el mismo pedido con el mismo contexto no duplica nada', function () {
    $asesor = va02_asesorEnBase();
    $pedido = va02_pedido();
    $servicio = va02_registrar();

    $contexto = fn () => va02_resolverDb()->paraCreacionAdministrativa(
        $asesor, MecanismoCreacion::VENTAS_ASESOR, CanalVenta::WHATSAPP
    );

    $primera = $servicio->registrar($pedido->id, $contexto());
    $segunda = $servicio->registrar($pedido->id, $contexto());

    expect($segunda->id)->toBe($primera->id)
        ->and($segunda->advisor_id)->toBe($asesor->id)
        ->and($segunda->creation_source)->toBe(MecanismoCreacion::VENTAS_ASESOR)
        ->and(AtribucionPedido::query()->where('order_id', $pedido->id)->count())->toBe(1)
        ->and(LogAsignacionAsesor::query()->where('order_id', $pedido->id)->count())->toBe(1);
});

test('registrar el mismo pedido con un contexto distinto produce conflicto', function () {
    $asesor = va02_asesorEnBase();
    $otroAsesor = va02_asesorEnBase('VA02 Segunda');
    $pedido = va02_pedido();
    $servicio = va02_registrar();

    $primera = $servicio->registrar($pedido->id, va02_resolverDb()->paraCreacionAdministrativa(
        $asesor, MecanismoCreacion::VENTAS_ASESOR, CanalVenta::WHATSAPP
    ));

    expect(fn () => $servicio->registrar($pedido->id, va02_resolverDb()->paraCreacionAdministrativa(
        $otroAsesor, MecanismoCreacion::ADMIN_BAGISTO, CanalVenta::OTRO
    )))->toThrow(AtribucionEnConflictoException::class);

    $primera->refresh();

    expect($primera->advisor_id)->toBe($asesor->id)
        ->and($primera->creation_source)->toBe(MecanismoCreacion::VENTAS_ASESOR)
        ->and(LogAsignacionAsesor::query()->where('order_id', $pedido->id)->count())->toBe(1);
});

test('la base impide dos atribuciones para el mismo pedido', function () {
    $pedido = va02_pedido();
    va02_registrar()->registrar($pedido->id, va02_resolverDb()->paraStorefront());

    expect(fn () => DB::table('bylopez_va_order_attributions')->insert([
        'order_id' => $pedido->id,
        'commercial_owner_type' => TipoPropiedadComercial::EMPRESA->value,
        'sales_channel' => CanalVenta::ECOMMERCE->value,
        'creation_source' => MecanismoCreacion::STOREFRONT->value,
        'attribution_status' => EstadoAtribucion::COMPLETA->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('dar de baja al asesor conserva su id historico y su snapshot', function () {
    $asesor = va02_asesorEnBase('VA02 Baja');
    $pedido = va02_pedido();

    $atribucion = va02_registrar()->registrar($pedido->id, va02_resolverDb()->paraCreacionAdministrativa(
        $asesor, MecanismoCreacion::VENTAS_ASESOR, CanalVenta::WHATSAPP
    ));

    Admin::query()->whereKey($asesor->id)->delete();

    $atribucion->refresh();

    // Fase 02A1: sin clave foranea, el id historico sobrevive y la propiedad
    // comercial sigue siendo coherente (advisor con advisor_id).
    expect($atribucion->advisor_id)->toBe($asesor->id)
        ->and($atribucion->advisor_name_snapshot)->toBe('VA02 Baja')
        ->and($atribucion->created_by_admin_id)->toBe($asesor->id)
        ->and($atribucion->created_by_name_snapshot)->toBe('VA02 Baja')
        ->and($atribucion->commercial_owner_type)->toBe(TipoPropiedadComercial::ASESOR)
        ->and($atribucion->asesor)->toBeNull();
});

test('asignar un asesor a una venta sin asignar deja historial y actualiza la atribucion', function () {
    $gerencia = va02_admin(null, 'all', 'VA02 Gerencia');
    $asesor = va02_asesorEnBase('VA02 Destino');
    $pedido = va02_pedido();

    $atribucion = va02_registrar()->registrar($pedido->id, va02_resolverDb()->paraCreacionAdministrativa(
        $gerencia, MecanismoCreacion::ADMIN_BAGISTO, CanalVenta::WHATSAPP
    ));

    $resultado = app(AsignacionAsesorService::class)
        ->asignarAsesor($atribucion, $gerencia, $asesor, 'La venta la atendio ella por WhatsApp');

    $log = LogAsignacionAsesor::query()
        ->where('order_attribution_id', $atribucion->id)
        ->where('tipo_operacion', TipoOperacionAtribucion::ASIGNAR_ASESOR->value)
        ->sole();

    expect($resultado->commercial_owner_type)->toBe(TipoPropiedadComercial::ASESOR)
        ->and($resultado->advisor_id)->toBe($asesor->id)
        ->and($resultado->advisor_name_snapshot)->toBe('VA02 Destino')
        ->and($resultado->commercial_owner_source)->toBe(OrigenPropiedadComercial::REASIGNACION)
        ->and($resultado->attribution_status)->toBe(EstadoAtribucion::COMPLETA)
        ->and($resultado->assigned_by_admin_id)->toBe($gerencia->id)
        ->and($log->tipo_propiedad_anterior)->toBe(TipoPropiedadComercial::SIN_ASIGNAR)
        ->and($log->tipo_propiedad_nuevo)->toBe(TipoPropiedadComercial::ASESOR)
        ->and($log->advisor_id_anterior)->toBeNull()
        ->and($log->advisor_id_nuevo)->toBe($asesor->id)
        ->and($log->changed_by_admin_id)->toBe($gerencia->id)
        ->and($log->changed_by_name_snapshot)->toBe('VA02 Gerencia')
        ->and($log->order_id)->toBe($pedido->id)
        ->and($log->motivo)->toBe('La venta la atendio ella por WhatsApp');
});

test('pasar una venta de asesor a venta de empresa exige el permiso de reasignacion', function () {
    $asesor = va02_asesorEnBase('VA02 Propietaria');
    $pedido = va02_pedido();
    $servicio = app(AsignacionAsesorService::class);

    $atribucion = va02_registrar()->registrar($pedido->id, va02_resolverDb()->paraCreacionAdministrativa(
        $asesor, MecanismoCreacion::VENTAS_ASESOR, CanalVenta::WHATSAPP
    ));

    // El propio asesor no tiene 'reassign'. Solo existe el historial inicial.
    expect(fn () => $servicio->marcarComoVentaDeEmpresa($atribucion, $asesor, 'Cliente fijo de ByLopez'))
        ->toThrow(AtribucionInvalidaException::class)
        ->and($atribucion->refresh()->advisor_id)->toBe($asesor->id)
        ->and(LogAsignacionAsesor::query()->count())->toBe(1);

    $supervisor = va02_admin([
        AutorizacionVentasAsesor::PERMISO_PEDIDOS_REASIGNAR,
    ], 'custom', 'VA02 Supervisor');

    $resultado = $servicio->marcarComoVentaDeEmpresa($atribucion, $supervisor, 'Cliente fijo de ByLopez');

    expect($resultado->commercial_owner_type)->toBe(TipoPropiedadComercial::EMPRESA)
        ->and($resultado->advisor_id)->toBeNull()
        ->and($resultado->advisor_name_snapshot)->toBeNull()
        ->and($resultado->assigned_at)->toBeNull()
        ->and($resultado->esPropiedadDeAsesor())->toBeFalse()
        ->and(LogAsignacionAsesor::query()->count())->toBe(2);
});

test('todo cambio de propiedad exige motivo y debe cambiar algo', function () {
    $supervisor = va02_admin([
        AutorizacionVentasAsesor::PERMISO_PEDIDOS_ASIGNAR,
        AutorizacionVentasAsesor::PERMISO_PEDIDOS_REASIGNAR,
    ], 'custom', 'VA02 Supervisor');
    $asesor = va02_asesorEnBase();
    $pedido = va02_pedido();
    $servicio = app(AsignacionAsesorService::class);

    $atribucion = va02_registrar()->registrar($pedido->id, va02_resolverDb()->paraCreacionAdministrativa(
        $asesor, MecanismoCreacion::VENTAS_ASESOR, CanalVenta::WHATSAPP
    ));

    expect(fn () => $servicio->asignarAsesor($atribucion, $supervisor, $asesor, '   '))
        ->toThrow(AtribucionInvalidaException::class)
        ->and(fn () => $servicio->asignarAsesor($atribucion, $supervisor, $asesor, 'Sin cambio real'))
        ->toThrow(AtribucionInvalidaException::class)
        ->and(fn () => $servicio->asignarAsesor(
            $atribucion, $supervisor, va02_admin(['ventas_asesor'], 'custom', 'VA02 Bodega'), 'No es asesor'
        ))->toThrow(AtribucionInvalidaException::class)
        // Solo queda el historial inicial: ningun intento rechazado escribio auditoria.
        ->and(LogAsignacionAsesor::query()->count())->toBe(1);
});

test('la consulta por asesor filtra en el servidor y respeta el aislamiento', function () {
    $asesora = va02_asesorEnBase('VA02 Propia');
    $otra = va02_asesorEnBase('VA02 Ajena');
    $registrar = va02_registrar();
    $resolver = va02_resolverDb();

    $propio = $registrar->registrar(va02_pedido()->id, $resolver->paraCreacionAdministrativa(
        $asesora, MecanismoCreacion::VENTAS_ASESOR, CanalVenta::WHATSAPP
    ));
    $ajeno = $registrar->registrar(va02_pedido()->id, $resolver->paraCreacionAdministrativa(
        $otra, MecanismoCreacion::VENTAS_ASESOR, CanalVenta::WHATSAPP
    ));

    $visibles = AtribucionPedido::query()->deAsesor($asesora->id)->pluck('id')->all();
    $autorizacion = new AutorizacionVentasAsesor;

    expect($visibles)->toBe([$propio->id])
        ->and($visibles)->not->toContain($ajeno->id)
        ->and($autorizacion->puedeVerRegistroDe($asesora, $ajeno->advisor_id))->toBeFalse()
        ->and($autorizacion->puedeVerRegistroDe($asesora, $propio->advisor_id))->toBeFalse();
});

test('un pedido sin evidencia se registra sin asesor y sin inferir nada', function () {
    $pedido = va02_pedido();

    $atribucion = va02_registrar()->registrarSinEvidencia($pedido->id, va02_resolverDb(), 'Historico');

    expect($atribucion->commercial_owner_type)->toBe(TipoPropiedadComercial::SIN_ASIGNAR)
        ->and($atribucion->advisor_id)->toBeNull()
        ->and($atribucion->created_by_admin_id)->toBeNull()
        ->and($atribucion->sales_channel)->toBe(CanalVenta::DESCONOCIDO)
        ->and($atribucion->creation_source)->toBe(MecanismoCreacion::DESCONOCIDO)
        ->and($atribucion->notes)->toBe('Historico');
});
