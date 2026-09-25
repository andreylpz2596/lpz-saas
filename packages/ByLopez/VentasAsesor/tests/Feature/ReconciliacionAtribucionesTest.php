<?php

use ByLopez\VentasAsesor\DataObjects\AnalisisPedidoHistorico;
use ByLopez\VentasAsesor\DataObjects\ContextoComercial;
use ByLopez\VentasAsesor\Enums\CanalVenta;
use ByLopez\VentasAsesor\Enums\EstadoAtribucion;
use ByLopez\VentasAsesor\Enums\MecanismoCreacion;
use ByLopez\VentasAsesor\Enums\OrigenPropiedadComercial;
use ByLopez\VentasAsesor\Enums\ResultadoReconciliacion;
use ByLopez\VentasAsesor\Enums\TipoOperacionAtribucion;
use ByLopez\VentasAsesor\Models\AtribucionPedido;
use ByLopez\VentasAsesor\Models\IncidenciaAtribucion;
use ByLopez\VentasAsesor\Models\LogAsignacionAsesor;
use ByLopez\VentasAsesor\Services\HistorialAtribucionService;
use ByLopez\VentasAsesor\Services\IncidenciasAtribucionService;
use ByLopez\VentasAsesor\Services\ReconciliadorAtribucionesService;
use ByLopez\VentasAsesor\Services\RegistrarAtribucionService;
use ByLopez\VentasAsesor\Services\ResolverAtribucionService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use ByLopez\VentasAsesor\Support\ClasificadorOrigenPedido;
use ByLopez\VentasAsesor\Support\ContextoComercialDePeticion;
use ByLopez\VentasAsesor\Support\FuenteEvidenciaHistorica;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Tests\TestCase;
use Webkul\Sales\Models\Order;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Reconciliacion de atribuciones historicas (Fase 02B1B)
|--------------------------------------------------------------------------
|
| Solo sobre lpz_saas_testing. Regla: NO INVENTAR HISTORIA. Sin una fuente de
| evidencia persistida inequivoca, un pedido anterior al listener queda sin
| asesor / origen desconocido, sin importar quien ejecute el comando ni desde
| que "ruta" lo haga.
|
*/

const VA36_COMANDO = 'ventas-asesor:reconciliar-atribuciones';

function va36_pedido(?int $cartId = null): Order
{
    // Order::factory no dispara checkout.order.save.after: nace sin atribucion.
    return Order::factory()->create([
        'cart_id' => $cartId,
        'customer_email' => 'va36-'.uniqid().'@example.test',
        'customer_first_name' => 'VA36',
        'customer_last_name' => 'Cliente',
    ]);
}

function va36_admin(array $permisos, string $tipo = 'custom'): Admin
{
    $rol = Role::factory()->create(['name' => 'VA36 rol '.uniqid(), 'permission_type' => $tipo, 'permissions' => $permisos]);

    return Admin::factory()->create(['role_id' => $rol->id, 'name' => 'VA36 Admin']);
}

/** Registra una fuente de evidencia falsa (hoy no existe ninguna real). */
function va36_fuente(array $contextosPorPedido): FuenteEvidenciaHistorica
{
    $fuente = new class($contextosPorPedido) implements FuenteEvidenciaHistorica
    {
        public function __construct(private array $mapa) {}

        public function nombre(): string
        {
            return 'fuente_de_prueba';
        }

        public function contextoPara(object $pedido): ?ContextoComercial
        {
            return $this->mapa[(int) $pedido->id] ?? null;
        }
    };

    app()->instance('va36.fuente.'.spl_object_id($fuente), $fuente);
    app()->tag('va36.fuente.'.spl_object_id($fuente), 'ventasasesor.fuentes_evidencia');

    return $fuente;
}

function va36_contextoEmpresa(): ContextoComercial
{
    return app(ResolverAtribucionService::class)->paraStorefront(CanalVenta::ECOMMERCE);
}

/** @return array<string, int> */
function va36_huella(): array
{
    return [
        'atribuciones' => AtribucionPedido::query()->count(),
        'logs' => LogAsignacionAsesor::query()->count(),
        'incidencias' => IncidenciaAtribucion::query()->count(),
        'pedidos' => DB::table('orders')->count(),
        'clientes' => DB::table('customers')->count(),
    ];
}

function va36_servicio(): ReconciliadorAtribucionesService
{
    return app(ReconciliadorAtribucionesService::class);
}

/** @return list<AnalisisPedidoHistorico> */
function va36_ejecutar(bool $persistir, int $orderId): array
{
    return iterator_to_array(va36_servicio()->ejecutar($persistir, $orderId), false);
}

test('1 y 11 - el dry-run no ejecuta NINGUNA escritura ni crea incidencias (ni siquiera intenta insertar)', function () {
    $huerfano = va36_pedido(4001);
    $conFallo = va36_pedido();
    app(RegistrarAtribucionService::class)->registrarFalloTecnico($conFallo->id, app(ResolverAtribucionService::class), 'listener.origen_no_determinado');
    $atribuido = va36_pedido();
    app(RegistrarAtribucionService::class)->registrar($atribuido->id, va36_contextoEmpresa());

    // Un conflicto anunciado (fallo tecnico + evidencia) tambien debe quedarse en el reporte.
    va36_fuente([$conFallo->id => va36_contextoEmpresa()]);

    $antes = va36_huella();
    $consultas = [];
    DB::listen(function ($consulta) use (&$consultas) {
        $consultas[] = $consulta->sql;
    });

    // Sin opciones (el modo por defecto es dry-run) y con --dry-run explicito.
    $this->artisan(VA36_COMANDO)->expectsOutputToContain('MODO DRY-RUN — NO SE REALIZARON ESCRITURAS')->assertExitCode(0);
    $this->artisan(VA36_COMANDO, ['--dry-run' => true])->expectsOutputToContain('MODO DRY-RUN — NO SE REALIZARON ESCRITURAS')->assertExitCode(0);
    $this->artisan(VA36_COMANDO, ['--dry-run' => true, '--order' => $conFallo->id])->assertExitCode(0);

    $escrituras = array_filter($consultas, fn (string $sql) => preg_match('/^\s*(insert|update|delete|replace|create|alter|drop|truncate)\b/i', $sql));

    expect(va36_huella())->toBe($antes)
        ->and($escrituras)->toBe([])
        ->and(IncidenciaAtribucion::query()->count())->toBe(0)
        ->and(AtribucionPedido::query()->where('order_id', $huerfano->id)->exists())->toBeFalse();
});

test('--dry-run y --persistir son excluyentes y los enteros se validan', function () {
    $this->artisan(VA36_COMANDO, ['--dry-run' => true, '--persistir' => true])->assertExitCode(2);
    $this->artisan(VA36_COMANDO, ['--order' => 'abc'])->assertExitCode(2);
    $this->artisan(VA36_COMANDO, ['--limite' => '0'])->assertExitCode(2);
});

test('2 - con evidencia persistida inequivoca el pedido es reconciliable y el modo persistente registra ESA atribucion', function () {
    $pedido = va36_pedido(4002);
    va36_fuente([$pedido->id => va36_contextoEmpresa()]);

    [$analisis] = va36_ejecutar(false, $pedido->id);

    expect($analisis->resultado)->toBe(ResultadoReconciliacion::RECONCILIABLE)
        ->and($analisis->fuente)->toBe('fuente_de_prueba')
        ->and(AtribucionPedido::query()->where('order_id', $pedido->id)->exists())->toBeFalse();

    [$aplicado] = va36_ejecutar(true, $pedido->id);

    $atribucion = AtribucionPedido::query()->where('order_id', $pedido->id)->sole();

    expect($aplicado->resultado)->toBe(ResultadoReconciliacion::RECONCILIADO)
        ->and($atribucion->creation_source)->toBe(MecanismoCreacion::STOREFRONT)
        ->and($atribucion->sales_channel)->toBe(CanalVenta::ECOMMERCE)
        ->and($atribucion->advisor_id)->toBeNull()
        ->and(LogAsignacionAsesor::query()->where('order_attribution_id', $atribucion->id)->sole()->motivo)->toContain('fuente_de_prueba');
});

test('3, 7 y 8 - sin evidencia (aunque haya cart_id, request de tienda o admin autenticado) queda sin asesor y con origen DESCONOCIDO', function () {
    // El "pasado" no se infiere del presente: ruta de tienda actual + asesor con sesion + carrito.
    $asesor = va36_admin([AutorizacionVentasAsesor::PERMISO_MODULO, AutorizacionVentasAsesor::PERMISO_OPERAR_COMO_ASESOR]);
    auth()->guard('admin')->setUser($asesor);

    $peticion = Request::create('/va36', 'POST');
    $ruta = new Route(['POST'], '/va36', ['as' => 'shop.checkout.onepage.orders.store']);
    $ruta->bind($peticion);
    $peticion->setRouteResolver(fn () => $ruta);
    app()->instance('request', $peticion);

    $pedido = va36_pedido(4003);

    [$analisis] = va36_ejecutar(false, $pedido->id);

    expect($analisis->resultado)->toBe(ResultadoReconciliacion::SIN_EVIDENCIA)
        ->and($analisis->contexto)->toBeNull()
        ->and($analisis->hechos['cart_id_presente'])->toBeTrue();

    va36_ejecutar(true, $pedido->id);

    $atribucion = AtribucionPedido::query()->where('order_id', $pedido->id)->sole();

    expect($atribucion->attribution_status)->toBe(EstadoAtribucion::SIN_ASESOR)
        ->and($atribucion->sales_channel)->toBe(CanalVenta::DESCONOCIDO)          // no ecommerce
        ->and($atribucion->creation_source)->toBe(MecanismoCreacion::DESCONOCIDO) // no storefront / admin
        ->and($atribucion->commercial_owner_source)->toBe(OrigenPropiedadComercial::SIN_CONTEXTO)
        ->and($atribucion->advisor_id)->toBeNull()                                // 9: sin asesor sin evidencia
        ->and($atribucion->created_by_admin_id)->toBeNull()                       // 10: sin creador sin evidencia
        ->and($atribucion->cart_id)->toBeNull()
        ->and($atribucion->notes)->toBe(ReconciliadorAtribucionesService::NOTA_SIN_EVIDENCIA)
        ->and(IncidenciaAtribucion::query()->count())->toBe(0);
});

test('8 - el reconciliador no depende de la peticion: ni clasificador, ni contexto de peticion, ni request', function () {
    $tipos = collect((new ReflectionClass(ReconciliadorAtribucionesService::class))->getConstructor()->getParameters())
        ->map(fn (ReflectionParameter $p) => (string) $p->getType())
        ->all();

    expect($tipos)->not->toContain(ClasificadorOrigenPedido::class)
        ->and($tipos)->not->toContain(ContextoComercialDePeticion::class)
        ->and($tipos)->not->toContain(Request::class);

    $codigo = file_get_contents((new ReflectionClass(ReconciliadorAtribucionesService::class))->getFileName());

    expect($codigo)->not->toContain('->clasificar(')->not->toContain('request()')->not->toContain('auth()')->not->toContain('->route(');
});

test('4 y 5 - un pedido ya atribuido se ignora y ejecutar dos veces no duplica nada (idempotencia)', function () {
    $atribuido = va36_pedido();
    app(RegistrarAtribucionService::class)->registrar($atribuido->id, va36_contextoEmpresa());
    $huerfano = va36_pedido();

    [$ya] = va36_ejecutar(true, $atribuido->id);
    expect($ya->resultado)->toBe(ResultadoReconciliacion::YA_ATRIBUIDO);

    va36_ejecutar(true, $huerfano->id);
    $primera = va36_huella();
    $atribucionId = AtribucionPedido::query()->where('order_id', $huerfano->id)->value('id');

    [$segunda] = va36_ejecutar(true, $huerfano->id);

    expect($segunda->resultado)->toBe(ResultadoReconciliacion::YA_ATRIBUIDO)
        ->and(va36_huella())->toBe($primera)
        ->and(AtribucionPedido::query()->where('order_id', $huerfano->id)->value('id'))->toBe($atribucionId)
        ->and(LogAsignacionAsesor::query()->where('order_attribution_id', $atribucionId)->count())->toBe(1);
});

test('fallo_tecnico existente sin evidencia se informa y NO se toca, ni con --persistir', function () {
    $pedido = va36_pedido();
    app(RegistrarAtribucionService::class)->registrarFalloTecnico($pedido->id, app(ResolverAtribucionService::class), 'listener.cart_id_ausente');
    $antes = va36_huella();

    [$resultado] = va36_ejecutar(true, $pedido->id);

    expect($resultado->resultado)->toBe(ResultadoReconciliacion::FALLO_TECNICO)
        ->and(va36_huella())->toBe($antes)
        ->and(AtribucionPedido::query()->where('order_id', $pedido->id)->value('attribution_status'))->toBe(EstadoAtribucion::FALLO_TECNICO);
});

test('6 - conflicto con una atribucion existente: no se sobrescribe, se reporta y la incidencia se abre UNA vez (sin --force)', function () {
    $pedido = va36_pedido();
    app(RegistrarAtribucionService::class)->registrarFalloTecnico($pedido->id, app(ResolverAtribucionService::class), 'listener.origen_no_determinado');
    $original = AtribucionPedido::query()->where('order_id', $pedido->id)->sole()->only(['id', 'attribution_status', 'creation_source', 'sales_channel']);
    va36_fuente([$pedido->id => va36_contextoEmpresa()]);

    // Dry-run: informa el conflicto, sin incidencia.
    [$dry] = va36_ejecutar(false, $pedido->id);
    expect($dry->resultado)->toBe(ResultadoReconciliacion::CONFLICTO)
        ->and(IncidenciaAtribucion::query()->count())->toBe(0);

    va36_ejecutar(true, $pedido->id);
    $incidencia = IncidenciaAtribucion::query()->where('order_id', $pedido->id)->sole();

    expect($incidencia->attempt_count)->toBe(1);

    // Repetir no aumenta contadores ni crea filas.
    va36_ejecutar(true, $pedido->id);

    expect(IncidenciaAtribucion::query()->where('order_id', $pedido->id)->sole()->attempt_count)->toBe(1)
        ->and(AtribucionPedido::query()->where('order_id', $pedido->id)->sole()->only(['id', 'attribution_status', 'creation_source', 'sales_channel']))->toEqual($original);

    expect(fn () => $this->artisan(VA36_COMANDO, ['--force' => true]))->toThrow(InvalidOptionException::class);
});

test('6b - una atribucion creada entre el analisis y la escritura (carrera con el listener) se respeta y queda como conflicto', function () {
    $pedido = va36_pedido();
    va36_fuente([$pedido->id => va36_contextoEmpresa()]);

    $analisis = va36_servicio()->analizar((object) ['id' => $pedido->id, 'increment_id' => $pedido->increment_id, 'cart_id' => null, 'status' => 'pending']);
    expect($analisis->resultado)->toBe(ResultadoReconciliacion::RECONCILIABLE);

    // Mientras tanto, otro proceso atribuye distinto.
    app(RegistrarAtribucionService::class)->registrarFalloTecnico($pedido->id, app(ResolverAtribucionService::class), 'listener.origen_no_determinado');

    $aplicado = va36_servicio()->aplicar($analisis);

    expect($aplicado->resultado)->toBe(ResultadoReconciliacion::CONFLICTO)
        ->and(AtribucionPedido::query()->where('order_id', $pedido->id)->value('attribution_status'))->toBe(EstadoAtribucion::FALLO_TECNICO)
        ->and(IncidenciaAtribucion::query()->where('order_id', $pedido->id)->count())->toBe(1);
});

test('fuentes de evidencia contradictorias son conflicto, no una eleccion arbitraria', function () {
    $pedido = va36_pedido();
    va36_fuente([$pedido->id => va36_contextoEmpresa()]);
    va36_fuente([$pedido->id => app(ResolverAtribucionService::class)->paraStorefront(CanalVenta::OTRO)]);

    [$analisis] = va36_ejecutar(false, $pedido->id);

    expect($analisis->resultado)->toBe(ResultadoReconciliacion::CONFLICTO)
        ->and($analisis->codigo)->toBe('fuentes_de_evidencia_contradictorias');
});

test('12 - un fallo en un pedido no corrompe los demas: se informa como error y el resto se reconcilia', function () {
    $malo = va36_pedido();
    $bueno = va36_pedido();

    $registrarQueFallaEnUno = new class(app(HistorialAtribucionService::class), $malo->id) extends RegistrarAtribucionService
    {
        public function __construct(HistorialAtribucionService $historial, private int $pedidoMalo)
        {
            parent::__construct($historial);
        }

        public function registrarSinEvidencia(int $orderId, ResolverAtribucionService $resolver, ?string $notas = null): AtribucionPedido
        {
            if ($orderId === $this->pedidoMalo) {
                throw new RuntimeException('fallo simulado en un solo pedido');
            }

            return parent::registrarSinEvidencia($orderId, $resolver, $notas);
        }
    };

    $servicio = new ReconciliadorAtribucionesService(
        $registrarQueFallaEnUno,
        app(ResolverAtribucionService::class),
        app(IncidenciasAtribucionService::class),
    );

    $resultados = collect(iterator_to_array($servicio->ejecutar(true), false))->keyBy('orderId');

    expect($resultados[$malo->id]->resultado)->toBe(ResultadoReconciliacion::ERROR)
        ->and($resultados[$bueno->id]->resultado)->toBe(ResultadoReconciliacion::SIN_EVIDENCIA)
        ->and(AtribucionPedido::query()->where('order_id', $malo->id)->exists())->toBeFalse()
        ->and(AtribucionPedido::query()->where('order_id', $bueno->id)->exists())->toBeTrue()
        ->and(LogAsignacionAsesor::query()->whereIn('order_id', [$malo->id, $bueno->id])->count())->toBe(1);
});

test('--limite y --order acotan el analisis; el recorrido usa lotes (lazy), no carga todos los pedidos', function () {
    $a = va36_pedido();
    $b = va36_pedido();
    va36_pedido();

    expect(iterator_to_array(va36_servicio()->ejecutar(false, null, 2), false))->toHaveCount(2)
        ->and(iterator_to_array(va36_servicio()->ejecutar(false, $b->id), false))->toHaveCount(1);

    $codigo = file_get_contents((new ReflectionClass(ReconciliadorAtribucionesService::class))->getFileName());

    expect($codigo)->toContain('lazyById')->not->toContain('->get()')->not->toContain('->all()');
});

test('la salida del comando trae el resumen operativo y no expone datos del cliente', function () {
    $pedido = va36_pedido(4004);

    $this->artisan(VA36_COMANDO, ['--order' => $pedido->id])
        ->expectsOutputToContain('Pedidos analizados:            1')
        ->expectsOutputToContain('Sin evidencia suficiente:     1')
        ->doesntExpectOutputToContain($pedido->customer_email)
        ->doesntExpectOutputToContain('VA36')
        ->assertExitCode(0);
});

test('13 - la ejecucion esta protegida por el guard de la base de pruebas', function () {
    expect(DB::selectOne('SELECT DATABASE() AS base')->base)->toBe('lpz_saas_testing');

    expect(fn () => TestCase::verificarConexionDePruebas('lpz_saas', '127.0.0.1'))->toThrow(RuntimeException::class);
});

test('el modo persistente registra en el historial la asignacion inicial (sin inventar un tipo de operacion nuevo)', function () {
    $pedido = va36_pedido();

    va36_ejecutar(true, $pedido->id);

    $log = LogAsignacionAsesor::query()->where('order_id', $pedido->id)->sole();

    expect($log->tipo_operacion)->toBe(TipoOperacionAtribucion::ASIGNACION_INICIAL)
        ->and($log->advisor_id_nuevo)->toBeNull()
        ->and($log->changed_by_admin_id)->toBeNull();
});
