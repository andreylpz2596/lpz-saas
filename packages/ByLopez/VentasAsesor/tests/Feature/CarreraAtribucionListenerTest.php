<?php

use ByLopez\VentasAsesor\Enums\CanalVenta;
use ByLopez\VentasAsesor\Enums\TipoOperacionAtribucion;
use ByLopez\VentasAsesor\Enums\TipoPropiedadComercial;
use ByLopez\VentasAsesor\Models\AtribucionPedido;
use ByLopez\VentasAsesor\Services\RegistrarAtribucionService;
use ByLopez\VentasAsesor\Services\ResolverAtribucionService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Webkul\Sales\Models\Order;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Dos ejecuciones concurrentes reales (Fase 02B1A)
|--------------------------------------------------------------------------
|
| La segunda conexion NO simula un ganador insertando una fila a mano: ejecuta el
| mismo RegistrarAtribucionService. Fabricar el ganador por fuera del servicio
| daria un historial vacio y la prueba no demostraria nada sobre la concurrencia
| real del listener.
|
*/

function va36_conexionParalela(): Connection
{
    $nombre = 'va36_paralela';

    if (config('database.connections.'.$nombre) === null) {
        config(['database.connections.'.$nombre => config('database.connections.'.config('database.default'))]);
    }

    return DB::connection($nombre);
}

/**
 * Ejecuta la operacion como si fuera OTRO proceso: sobre la conexion paralela,
 * que confirma de verdad porque no esta dentro de la transaccion de la prueba.
 */
function va36_comoOtroProceso(Closure $operacion): mixed
{
    $original = config('database.default');

    va36_conexionParalela();

    config(['database.default' => 'va36_paralela']);

    try {
        return $operacion();
    } finally {
        config(['database.default' => $original]);
    }
}

test('32. dos ejecuciones concurrentes dejan una atribucion y exactamente un historial inicial', function () {
    $paralela = va36_conexionParalela();

    // El pedido lo confirma la otra conexion: la clave foranea lo exige fuera de la transaccion.
    $pedidoId = (int) $paralela->table('orders')->insertGetId(array_merge(
        Order::factory()->make()->getAttributes(),
        [
            'increment_id' => 'VA36-'.uniqid(),
            'cart_id' => 9600,
            'customer_email' => 'va36-'.uniqid().'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ));

    /*
     * La limpieza va DESPUES del rollback de la prueba: el servicio deja la fila
     * ganadora bloqueada y borrarla antes agotaria el tiempo de espera.
     */
    $this->beforeApplicationDestroyed(function () use ($pedidoId) {
        $limpieza = va36_conexionParalela();
        $limpieza->table('bylopez_va_attribution_incidents')->where('order_id', $pedidoId)->delete();
        $limpieza->table('bylopez_va_advisor_assignment_logs')->where('order_id', $pedidoId)->delete();
        $limpieza->table('bylopez_va_order_attributions')->where('order_id', $pedidoId)->delete();
        $limpieza->table('orders')->where('id', $pedidoId)->delete();
        $limpieza->disconnect();
    });

    $contexto = fn () => app(ResolverAtribucionService::class)->paraStorefront(CanalVenta::ECOMMERCE, 9600);

    // Nuestra transaccion todavia no ve nada: es el estado con el que arranca el listener.
    expect(AtribucionPedido::query()->where('order_id', $pedidoId)->count())->toBe(0);

    // PRIMERA ejecucion: otro proceso, mismo servicio autorizado, confirma.
    $ganadora = va36_comoOtroProceso(
        fn () => app(RegistrarAtribucionService::class)->registrar($pedidoId, $contexto())
    );

    // SEGUNDA ejecucion: este proceso, mismo servicio, choca contra la unicidad.
    $recuperada = app(RegistrarAtribucionService::class)->registrar($pedidoId, $contexto());

    expect($recuperada->id)->toBe($ganadora->id)
        ->and($recuperada->commercial_owner_type)->toBe(TipoPropiedadComercial::EMPRESA)
        // Una sola atribucion y UN solo evento inicial, escrito por el ganador.
        ->and($paralela->table('bylopez_va_order_attributions')->where('order_id', $pedidoId)->count())->toBe(1)
        ->and($paralela->table('bylopez_va_advisor_assignment_logs')
            ->where('order_id', $pedidoId)
            ->where('tipo_operacion', TipoOperacionAtribucion::ASIGNACION_INICIAL->value)
            ->count())->toBe(1)
        // Nadie fabrico incidencias: los contextos eran equivalentes.
        ->and($paralela->table('bylopez_va_attribution_incidents')->where('order_id', $pedidoId)->count())->toBe(0);
});
