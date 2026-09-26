<?php

use ByLopez\VentasAsesor\Enums\CanalVenta;
use ByLopez\VentasAsesor\Enums\EstadoAtribucion;
use ByLopez\VentasAsesor\Enums\MecanismoCreacion;
use ByLopez\VentasAsesor\Enums\OrigenPropiedadComercial;
use ByLopez\VentasAsesor\Enums\TipoOperacionAtribucion;
use ByLopez\VentasAsesor\Enums\TipoPropiedadComercial;
use ByLopez\VentasAsesor\Exceptions\AtribucionEnConflictoException;
use ByLopez\VentasAsesor\Exceptions\HistorialIncoherenteException;
use ByLopez\VentasAsesor\Exceptions\HistorialInmutableException;
use ByLopez\VentasAsesor\Models\AtribucionPedido;
use ByLopez\VentasAsesor\Models\LogAsignacionAsesor;
use ByLopez\VentasAsesor\Services\RegistrarAtribucionService;
use ByLopez\VentasAsesor\Services\ResolverAtribucionService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Webkul\Sales\Models\Order;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Cierre auditado (Fase 02A2)
|--------------------------------------------------------------------------
|
| Solo sobre lpz_saas_testing. La prueba de carrera usa una SEGUNDA conexion
| que confirma sus escrituras fuera de la transaccion de la prueba; por eso
| limpia lo suyo a mano en el finally.
|
*/

function va22_registrar(): RegistrarAtribucionService
{
    return app(RegistrarAtribucionService::class);
}

function va22_resolver(): ResolverAtribucionService
{
    return app(ResolverAtribucionService::class);
}

function va22_pedido(): Order
{
    return Order::factory()->create();
}

function va22_asesor(string $nombre = 'VA22 Asesora'): Admin
{
    $rol = Role::factory()->create([
        'name' => $nombre.' rol',
        'permission_type' => 'custom',
        'permissions' => [AutorizacionVentasAsesor::PERMISO_OPERAR_COMO_ASESOR],
    ]);

    return Admin::factory()->create(['role_id' => $rol->id, 'name' => $nombre]);
}

/** Conexion independiente que simula al otro proceso: sus escrituras se confirman. */
function va22_conexionParalela(): Connection
{
    $nombre = 'va22_paralela';

    if (config('database.connections.'.$nombre) === null) {
        config(['database.connections.'.$nombre => config('database.connections.'.config('database.default'))]);
    }

    return DB::connection($nombre);
}

/** Fila equivalente a lo que produce paraStorefront(), insertada sin pasar por el servicio. */
function va22_filaGanadora(int $orderId): array
{
    return [
        'order_id' => $orderId,
        'commercial_owner_type' => TipoPropiedadComercial::EMPRESA->value,
        'commercial_owner_source' => OrigenPropiedadComercial::REGLA_STOREFRONT->value,
        'sales_channel' => CanalVenta::ECOMMERCE->value,
        'creation_source' => MecanismoCreacion::STOREFRONT->value,
        'attribution_status' => EstadoAtribucion::COMPLETA->value,
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

/** Fila equivalente a lo que produce paraFalloTecnico(), sin historial. */
function va22_filaFalloTecnico(int $orderId): array
{
    return [
        'order_id' => $orderId,
        'commercial_owner_type' => TipoPropiedadComercial::SIN_ASIGNAR->value,
        'commercial_owner_source' => OrigenPropiedadComercial::SIN_CONTEXTO->value,
        'sales_channel' => CanalVenta::DESCONOCIDO->value,
        'creation_source' => MecanismoCreacion::DESCONOCIDO->value,
        'attribution_status' => EstadoAtribucion::FALLO_TECNICO->value,
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

test('1, 2 y 3. la carrera real ejecuta la rama de recuperacion y compara con el ganador', function () {
    $paralela = va22_conexionParalela();
    $servicio = va22_registrar();
    $resolver = va22_resolver();

    /*
     * El pedido lo crea y confirma la otra conexion: la fila ganadora lo referencia
     * por clave foranea y debe existir fuera de la transaccion de esta prueba.
     */
    $pedidoId = (int) $paralela->table('orders')->insertGetId(array_merge(
        Order::factory()->make()->getAttributes(),
        ['increment_id' => 'VA22-'.uniqid(), 'created_at' => now(), 'updated_at' => now()],
    ));

    /*
     * La limpieza va DESPUES del rollback de la prueba: el servicio deja la fila
     * ganadora bloqueada con lockForUpdate() y borrarla antes agotaria el tiempo de
     * espera. DatabaseTransactions registra su rollback primero, asi que este
     * callback corre cuando los bloqueos ya se liberaron.
     */
    $this->beforeApplicationDestroyed(function () use ($pedidoId) {
        $limpieza = va22_conexionParalela();
        $limpieza->table('bylopez_va_advisor_assignment_logs')->where('order_id', $pedidoId)->delete();
        $limpieza->table('bylopez_va_order_attributions')->where('order_id', $pedidoId)->delete();
        $limpieza->table('orders')->where('id', $pedidoId)->delete();
        $limpieza->disconnect();
    });

    // MariaDB 10.4 expone tx_isolation; MySQL 5.7+ y MariaDB 11 usan transaction_isolation.
    $aislamiento = str_replace('_', '-', strtoupper(
        rescue(fn () => DB::selectOne('SELECT @@transaction_isolation AS nivel')->nivel,
            fn () => DB::selectOne('SELECT @@tx_isolation AS nivel')->nivel)
    ));

    // Esta es exactamente la consulta inicial del servicio: hoy no encuentra nada.
    expect(AtribucionPedido::query()->where('order_id', $pedidoId)->count())->toBe(0);

    // El otro proceso gana la carrera: inserta y confirma.
    $paralela->table('bylopez_va_order_attributions')->insert(va22_filaGanadora($pedidoId));
    $idGanadora = (int) $paralela->table('bylopez_va_order_attributions')
        ->where('order_id', $pedidoId)->value('id');

    /*
     * Seguimos sin verla: nuestra transaccion mantiene su instantanea. Por eso el
     * servicio pasara de largo la consulta inicial y llegara al INSERT.
     */
    expect(AtribucionPedido::query()->where('order_id', $pedidoId)->count())->toBe(0)
        ->and($aislamiento)->toBe('REPEATABLE-READ');

    // Contexto equivalente: el INSERT viola la unicidad y la recuperacion devuelve al ganador.
    $recuperada = $servicio->registrar($pedidoId, $resolver->paraStorefront());

    /*
     * Prueba de que se ejecuto la rama del catch: la fila devuelta es la del otro
     * proceso (su id lo genero la conexion paralela) y esta transaccion nunca la
     * habia visto, porque dos consultas previas devolvieron cero.
     */
    expect($recuperada->id)->toBe($idGanadora)
        ->and($recuperada->commercial_owner_type)->toBe(TipoPropiedadComercial::EMPRESA)
        ->and($paralela->table('bylopez_va_order_attributions')->where('order_id', $pedidoId)->count())->toBe(1)
        ->and($paralela->table('bylopez_va_advisor_assignment_logs')->where('order_id', $pedidoId)->count())->toBe(0);

    // Mismo ganador, contexto incompatible: conflicto, otra vez por la rama del catch.
    expect(fn () => $servicio->registrar($pedidoId, $resolver->paraCreacionAdministrativa(
        va22_asesor('VA22 Carrera'), MecanismoCreacion::VENTAS_ASESOR, CanalVenta::WHATSAPP
    )))->toThrow(AtribucionEnConflictoException::class);
});
test('4. el camino de fallo tecnico no admite notas libres', function () {
    $pedido = va22_pedido();

    $atribucion = va22_registrar()->registrarFalloTecnico($pedido->id, va22_resolver(), 'listener.sin_contexto');
    $log = LogAsignacionAsesor::query()->where('order_attribution_id', $atribucion->id)->sole();

    expect((new ReflectionMethod(ResolverAtribucionService::class, 'paraFalloTecnico'))->getNumberOfParameters())->toBe(1)
        ->and((new ReflectionMethod(RegistrarAtribucionService::class, 'registrarFalloTecnico'))->getNumberOfParameters())->toBe(3)
        ->and($atribucion->notes)->toBeNull()
        ->and($log->codigo_tecnico)->toBe('listener.sin_contexto')
        // Texto fijo mas el codigo validado: nada que venga del llamador.
        ->and($log->motivo)->toBe('Atribucion inicial con fallo tecnico: listener.sin_contexto');
});

test('5. repetir el fallo tecnico con el mismo codigo es idempotente', function () {
    $pedido = va22_pedido();
    $servicio = va22_registrar();

    $primera = $servicio->registrarFalloTecnico($pedido->id, va22_resolver(), 'listener.contexto_ausente');
    $segunda = $servicio->registrarFalloTecnico($pedido->id, va22_resolver(), 'LISTENER.Contexto_Ausente');

    expect($segunda->id)->toBe($primera->id)
        ->and($segunda->attribution_status)->toBe(EstadoAtribucion::FALLO_TECNICO)
        ->and($segunda->advisor_id)->toBeNull()
        ->and(AtribucionPedido::query()->where('order_id', $pedido->id)->count())->toBe(1)
        ->and(LogAsignacionAsesor::query()->where('order_attribution_id', $primera->id)->count())->toBe(1);
});

test('6. un codigo tecnico distinto para el mismo pedido produce conflicto', function () {
    $pedido = va22_pedido();
    $servicio = va22_registrar();

    $original = $servicio->registrarFalloTecnico($pedido->id, va22_resolver(), 'listener.contexto_ausente');

    try {
        $servicio->registrarFalloTecnico($pedido->id, va22_resolver(), 'listener.resolucion_fallida');
        $campos = [];
        $lanzo = false;
    } catch (AtribucionEnConflictoException $e) {
        $campos = $e->camposEnConflicto;
        $lanzo = true;
    }

    $original->refresh();
    $log = LogAsignacionAsesor::query()->where('order_attribution_id', $original->id)->sole();

    expect($lanzo)->toBeTrue()
        ->and($campos)->toBe(['codigo_tecnico'])
        ->and($log->codigo_tecnico)->toBe('listener.contexto_ausente')
        ->and(LogAsignacionAsesor::query()->where('order_attribution_id', $original->id)->count())->toBe(1);
});

test('7. un historial inicial ausente o duplicado no se acepta en silencio', function () {
    $servicio = va22_registrar();

    // Ausente: fila puesta a mano, sin su evento inicial.
    $sinHistorial = va22_pedido();
    DB::table('bylopez_va_order_attributions')->insert(va22_filaFalloTecnico($sinHistorial->id));

    expect(fn () => $servicio->registrarFalloTecnico($sinHistorial->id, va22_resolver(), 'listener.contexto_ausente'))
        ->toThrow(HistorialIncoherenteException::class);

    // Duplicado: se copia el evento inicial saltandose el modelo.
    $conDuplicado = va22_pedido();
    $atribucion = $servicio->registrarFalloTecnico($conDuplicado->id, va22_resolver(), 'listener.contexto_ausente');

    $inicial = (array) DB::table('bylopez_va_advisor_assignment_logs')
        ->where('order_attribution_id', $atribucion->id)->first();
    unset($inicial['id']);
    DB::table('bylopez_va_advisor_assignment_logs')->insert($inicial);

    expect(fn () => $servicio->registrarFalloTecnico($conDuplicado->id, va22_resolver(), 'listener.contexto_ausente'))
        ->toThrow(HistorialIncoherenteException::class)
        ->and(AtribucionPedido::query()->where('order_id', $conDuplicado->id)->count())->toBe(1);
});

test('8 y 9. guardar de nuevo un historial persistido se rechaza, con y sin cambios', function () {
    $pedido = va22_pedido();
    $atribucion = va22_registrar()->registrarFalloTecnico($pedido->id, va22_resolver(), 'listener.sin_contexto');

    $log = LogAsignacionAsesor::query()->where('order_attribution_id', $atribucion->id)->sole();
    $original = $log->getAttributes();

    // Sin cambios: Model::save() cortaria por isDirty(), por eso el guardia vive en `saving`.
    expect(fn () => $log->save())->toThrow(HistorialInmutableException::class);

    $log->motivo = 'motivo reescrito';

    expect(fn () => $log->save())->toThrow(HistorialInmutableException::class);

    $log->refresh();

    expect($log->getAttributes())->toBe($original)
        ->and(LogAsignacionAsesor::query()->where('order_attribution_id', $atribucion->id)->count())->toBe(1);
});

test('12. el esquema conserva RESTRICT y sigue sin claves foraneas hacia admins', function () {
    $reglas = collect(DB::select(
        'SELECT CONSTRAINT_NAME n, REFERENCED_TABLE_NAME r, DELETE_RULE d
         FROM information_schema.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME LIKE ?',
        ['bylopez_va_%']
    ))->mapWithKeys(fn ($fila) => [$fila->n => $fila->d.' -> '.$fila->r]);

    expect($reglas->get('bvaoa_order_fk'))->toBe('RESTRICT -> orders')
        ->and($reglas->get('bvaal_attribution_fk'))->toBe('RESTRICT -> bylopez_va_order_attributions')
        // Fase 02B1A: la cola de incidencias sigue la misma politica.
        ->and($reglas->get('bvaai_order_fk'))->toBe('RESTRICT -> orders')
        ->and($reglas->get('bvaai_attribution_fk'))->toBe('RESTRICT -> bylopez_va_order_attributions')
        // RT-04B1a: excepcion DELIBERADA. El indice de telefonos es derivado del cliente;
        // RESTRICT impediria borrar clientes desde el panel de Bagisto.
        ->and($reglas->get('bvacp_customer_fk'))->toBe('CASCADE -> customers')
        ->and($reglas->filter(fn ($regla) => str_contains($regla, '-> admins'))->all())->toBe([])
        ->and($reglas)->toHaveCount(5);
});

test('el historial inicial de un fallo tecnico es el unico portador del codigo', function () {
    $pedido = va22_pedido();
    $atribucion = va22_registrar()->registrarFalloTecnico($pedido->id, va22_resolver(), 'listener.contexto_ausente');

    expect(in_array('codigo_tecnico', Schema::getColumnListing('bylopez_va_order_attributions'), true))
        ->toBeFalse()
        ->and(LogAsignacionAsesor::query()->where('order_attribution_id', $atribucion->id)->value('tipo_operacion'))
        ->toBe(TipoOperacionAtribucion::ASIGNACION_INICIAL);
});
