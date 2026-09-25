<?php

use ByLopez\VentasAsesor\DataObjects\ContextoComercial;
use ByLopez\VentasAsesor\Enums\CanalVenta;
use ByLopez\VentasAsesor\Enums\CodigoIncidencia;
use ByLopez\VentasAsesor\Enums\EstadoAtribucion;
use ByLopez\VentasAsesor\Enums\MecanismoCreacion;
use ByLopez\VentasAsesor\Enums\TipoOperacionAtribucion;
use ByLopez\VentasAsesor\Enums\TipoPropiedadComercial;
use ByLopez\VentasAsesor\Exceptions\AtribucionInvalidaException;
use ByLopez\VentasAsesor\Listeners\AtribuirPedidoCreado;
use ByLopez\VentasAsesor\Models\AtribucionPedido;
use ByLopez\VentasAsesor\Models\IncidenciaAtribucion;
use ByLopez\VentasAsesor\Models\LogAsignacionAsesor;
use ByLopez\VentasAsesor\Providers\EventServiceProvider;
use ByLopez\VentasAsesor\Services\HistorialAtribucionService;
use ByLopez\VentasAsesor\Services\IncidenciasAtribucionService;
use ByLopez\VentasAsesor\Services\RegistrarAtribucionService;
use ByLopez\VentasAsesor\Services\ResolverAtribucionService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use ByLopez\VentasAsesor\Support\ClasificadorOrigenPedido;
use ByLopez\VentasAsesor\Support\ContextoComercialDePeticion;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Webkul\Sales\Models\Order;
use Webkul\Sales\Models\OrderPayment;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Listener de atribucion sobre checkout.order.save.after (Fase 02B1A)
|--------------------------------------------------------------------------
|
| Solo sobre lpz_saas_testing. El listener corre SINCRONO dentro de la
| transaccion del pedido: por eso ninguna de estas pruebas puede permitir que
| relance, y por eso el rollback tiene que llevarse tambien lo que escribio.
|
*/

function va35_peticion(?string $ruta): Request
{
    $peticion = Request::create('/va35-ruta', 'POST');

    if ($ruta !== null) {
        $rutaLaravel = new Route(['POST'], '/va35-ruta', ['as' => $ruta]);
        $rutaLaravel->bind($peticion);

        $peticion->setRouteResolver(fn () => $rutaLaravel);
    }

    return $peticion;
}

function va35_clasificador(?string $ruta, ?ContextoComercialDePeticion $transporte = null): ClasificadorOrigenPedido
{
    return new ClasificadorOrigenPedido(
        app(ResolverAtribucionService::class),
        $transporte ?? app(ContextoComercialDePeticion::class),
        app('auth'),
        va35_peticion($ruta),
    );
}

function va35_listener(
    ?string $ruta,
    ?ContextoComercialDePeticion $transporte = null,
    ?RegistrarAtribucionService $registrar = null,
    ?IncidenciasAtribucionService $incidencias = null,
): AtribuirPedidoCreado {
    return new AtribuirPedidoCreado(
        va35_clasificador($ruta, $transporte),
        app(ResolverAtribucionService::class),
        $registrar ?? app(RegistrarAtribucionService::class),
        $incidencias ?? app(IncidenciasAtribucionService::class),
    );
}

function va35_pedido(?int $cartId = null): Order
{
    /*
     * customer_email y customer_name van explicitos porque otros listeners del core
     * sobre este mismo evento construyen el correo de confirmacion y fallan con null.
     */
    return Order::factory()->create([
        'cart_id' => $cartId,
        'customer_email' => 'va35-'.uniqid().'@example.test',
        'customer_first_name' => 'VA35',
        'customer_last_name' => 'Cliente',
    ]);
}

function va35_admin(array|string|null $permisos, string $tipo = 'custom', string $nombre = 'VA35 Admin'): Admin
{
    $rol = Role::factory()->create([
        'name' => $nombre.' rol '.uniqid(),
        'permission_type' => $tipo,
        'permissions' => $permisos,
    ]);

    return Admin::factory()->create(['role_id' => $rol->id, 'name' => $nombre]);
}

function va35_asesor(): Admin
{
    return va35_admin([
        AutorizacionVentasAsesor::PERMISO_MODULO,
        AutorizacionVentasAsesor::PERMISO_OPERAR_COMO_ASESOR,
    ], 'custom', 'VA35 Asesora');
}

/** Motor de atribucion que falla siempre, para ejercitar cada rama del listener. */
function va35_registrarQueFalla(Throwable $excepcion): RegistrarAtribucionService
{
    $falso = new class(app(HistorialAtribucionService::class)) extends RegistrarAtribucionService
    {
        public ?Throwable $aLanzar = null;

        public int $llamadas = 0;

        public function registrar(int $orderId, ContextoComercial $contexto, ?string $motivo = null): AtribucionPedido
        {
            $this->llamadas++;

            throw $this->aLanzar;
        }

        public function registrarFalloTecnico(int $orderId, ResolverAtribucionService $resolver, string $codigoTecnico): AtribucionPedido
        {
            $this->llamadas++;

            throw $this->aLanzar;
        }
    };

    $falso->aLanzar = $excepcion;

    return $falso;
}

/** Servicio de incidencias que cuenta llamadas y, si se le pide, falla. */
function va35_incidenciasEspia(?Throwable $excepcion = null): IncidenciasAtribucionService
{
    $espia = new class extends IncidenciasAtribucionService
    {
        public ?Throwable $aLanzar = null;

        /** @var list<string> */
        public array $llamadas = [];

        public function registrar(int $orderId, CodigoIncidencia $codigo, ?int $atribucionId = null, array $camposConflicto = []): IncidenciaAtribucion
        {
            $this->llamadas[] = $codigo->value;

            if ($this->aLanzar !== null) {
                throw $this->aLanzar;
            }

            return parent::registrar($orderId, $codigo, $atribucionId, $camposConflicto);
        }
    };

    $espia->aLanzar = $excepcion;

    return $espia;
}

/** Conteos de las tablas del core que este flujo podria tocar por accidente. */
function va35_fotoDelCore(): array
{
    $foto = [];

    foreach (['orders', 'order_items', 'cart', 'cart_items', 'customers', 'admins', 'roles', 'channels'] as $tabla) {
        $foto[$tabla] = DB::table($tabla)->count();
    }

    return $foto;
}

test('35. toda la suite corre contra lpz_saas_testing', function () {
    expect(DB::selectOne('SELECT DATABASE() AS base')->base)->toBe('lpz_saas_testing')
        ->and(config('database.connections.'.config('database.default').'.host'))
        ->toBeIn(['127.0.0.1', 'localhost', '::1']);
});

test('33. el listener esta registrado exactamente una vez sobre checkout.order.save.after', function () {
    $crudos = collect(Event::getRawListeners()['checkout.order.save.after'] ?? []);

    $propios = $crudos->filter(
        fn ($listener) => is_string($listener) && str_contains($listener, AtribuirPedidoCreado::class)
    );

    expect($propios)->toHaveCount(1)
        ->and($propios->first())->toBe(AtribuirPedidoCreado::class.'@handle')
        ->and(array_keys(app()->getLoadedProviders()))->toContain(EventServiceProvider::class);
});

test('19. el evento repetido no duplica la atribucion ni el historial', function () {
    $pedido = va35_pedido(8100);

    /*
     * El evento real tiene mas oyentes: el core envia el correo de pedido creado. Se
     * intercepta el envio y se completa el remitente, que la base de pruebas no trae
     * configurado. Nada de esto toca el core: es configuracion de la prueba.
     */
    Mail::fake();
    config(['mail.from.address' => 'va35-remitente@example.test', 'mail.from.name' => 'VA35']);

    // El oyente de facturacion del core lee el metodo de pago del pedido.
    OrderPayment::factory()->create(['order_id' => $pedido->id, 'method' => 'free']);
    $pedido->refresh();

    // Se usa el bus real de eventos: es el camino que recorre un pedido de verdad.
    app()->instance('request', va35_peticion('shop.checkout.onepage.orders.store'));
    app()->forgetScopedInstances();

    Event::dispatch('checkout.order.save.after', $pedido);
    Event::dispatch('checkout.order.save.after', $pedido);
    Event::dispatch('checkout.order.save.after', $pedido);

    $atribuciones = AtribucionPedido::query()->where('order_id', $pedido->id)->get();

    expect($atribuciones)->toHaveCount(1)
        ->and($atribuciones->first()->commercial_owner_type)->toBe(TipoPropiedadComercial::EMPRESA)
        ->and($atribuciones->first()->sales_channel)->toBe(CanalVenta::ECOMMERCE)
        ->and($atribuciones->first()->creation_source)->toBe(MecanismoCreacion::STOREFRONT)
        ->and($atribuciones->first()->attribution_status)->toBe(EstadoAtribucion::COMPLETA)
        ->and($atribuciones->first()->cart_id)->toBe(8100)
        ->and(LogAsignacionAsesor::query()->where('order_id', $pedido->id)->count())->toBe(1)
        ->and(IncidenciaAtribucion::query()->dePedido($pedido->id)->count())->toBe(0);
});

test('20. un conflicto conserva la atribucion existente y crea la incidencia', function () {
    $asesor = va35_asesor();
    $pedido = va35_pedido(8200);

    // Atribucion previa: la venta ya es del asesor.
    $previa = app(RegistrarAtribucionService::class)->registrar(
        $pedido->id,
        app(ResolverAtribucionService::class)->paraCreacionAdministrativa(
            creador: $asesor,
            mecanismo: MecanismoCreacion::ADMIN_BAGISTO,
            canal: CanalVenta::DESCONOCIDO,
            cartId: 8200,
        ),
    );

    $antes = AtribucionPedido::query()->find($previa->id)->getAttributes();

    // Ahora llega el mismo pedido clasificado como venta de tienda: contradiccion.
    va35_listener('shop.checkout.onepage.orders.store')->handle($pedido);

    $incidencia = IncidenciaAtribucion::query()
        ->dePedido($pedido->id)
        ->conCodigo(CodigoIncidencia::ATRIBUCION_CONFLICTO)
        ->first();

    expect(AtribucionPedido::query()->find($previa->id)->getAttributes())->toBe($antes)
        ->and($incidencia)->not->toBeNull()
        ->and($incidencia->order_attribution_id)->toBe((int) $previa->id)
        ->and($incidencia->attempt_count)->toBe(1)
        ->and($incidencia->campos_conflicto)->toContain('advisor_id', 'commercial_owner_type')
        // Solo nombres de campo: ningun valor viajo a la tabla.
        ->and($incidencia->campos_conflicto)
        ->each->toBeIn(IncidenciasAtribucionService::CAMPOS_PERMITIDOS)
        // El conflicto NO se disfraza de fallo tecnico.
        ->and(AtribucionPedido::query()->find($previa->id)->attribution_status)
        ->toBe(EstadoAtribucion::COMPLETA)
        ->and(LogAsignacionAsesor::query()->where('order_id', $pedido->id)->count())->toBe(1);

    // Repetir el evento incrementa el contador y no duplica la incidencia.
    va35_listener('shop.checkout.onepage.orders.store')->handle($pedido);

    expect(IncidenciaAtribucion::query()->dePedido($pedido->id)->count())->toBe(1)
        ->and($incidencia->refresh()->attempt_count)->toBe(2);
});

test('25. un historial incoherente crea su incidencia sin tocar la atribucion', function () {
    $pedido = va35_pedido(8300);

    $atribucion = app(RegistrarAtribucionService::class)->registrarFalloTecnico(
        $pedido->id,
        app(ResolverAtribucionService::class),
        ClasificadorOrigenPedido::CODIGO_ORIGEN_NO_DETERMINADO,
    );

    /*
     * Se rompe el historial por debajo del modelo (que prohibe borrarlo) para
     * reproducir una incoherencia real: fallo tecnico sin su evento inicial.
     */
    DB::table('bylopez_va_advisor_assignment_logs')->where('order_id', $pedido->id)->delete();

    $antes = AtribucionPedido::query()->find($atribucion->id)->getAttributes();

    va35_listener('ruta.que.no.existe')->handle($pedido);

    expect(IncidenciaAtribucion::query()->dePedido($pedido->id)->conCodigo(CodigoIncidencia::HISTORIAL_INCOHERENTE)->count())->toBe(1)
        ->and(AtribucionPedido::query()->find($atribucion->id)->getAttributes())->toBe($antes)
        ->and(LogAsignacionAsesor::query()->where('order_id', $pedido->id)->count())->toBe(0);
});

test('26. un error de dominio no aborta el listener y deja incidencia de contexto invalido', function () {
    $pedido = va35_pedido(8400);
    $motor = va35_registrarQueFalla(new AtribucionInvalidaException('contexto imposible'));

    $ejecutar = fn () => va35_listener('shop.checkout.onepage.orders.store', null, $motor)->handle($pedido);

    // Lo primero y mas importante: no relanza, porque relanzar tumbaria la venta.
    expect($ejecutar)->not->toThrow(Throwable::class);

    expect(IncidenciaAtribucion::query()->dePedido($pedido->id)->conCodigo(CodigoIncidencia::CONTEXTO_INVALIDO)->count())->toBe(1)
        // No se invento ninguna atribucion.
        ->and(AtribucionPedido::query()->where('order_id', $pedido->id)->count())->toBe(0);
});

test('27. un error inesperado no aborta el listener y deja incidencia listener_error', function () {
    $pedido = va35_pedido(8500);
    $motor = va35_registrarQueFalla(new RuntimeException('algo que nadie previo'));

    $ejecutar = fn () => va35_listener('shop.checkout.onepage.orders.store', null, $motor)->handle($pedido);

    expect($ejecutar)->not->toThrow(Throwable::class);

    $incidencia = IncidenciaAtribucion::query()
        ->dePedido($pedido->id)
        ->conCodigo(CodigoIncidencia::LISTENER_ERROR)
        ->first();

    expect($incidencia)->not->toBeNull()
        ->and($incidencia->campos_conflicto)->toBeNull()
        ->and(AtribucionPedido::query()->where('order_id', $pedido->id)->count())->toBe(0);
});

test('28. si tambien falla registrar la incidencia, el listener sigue sin propagar', function () {
    $pedido = va35_pedido(8600);
    $motor = va35_registrarQueFalla(new RuntimeException('fallo del motor'));
    $incidencias = va35_incidenciasEspia(new RuntimeException('la cola tampoco responde'));

    $ejecutar = fn () => va35_listener('shop.checkout.onepage.orders.store', null, $motor, $incidencias)->handle($pedido);

    expect($ejecutar)->not->toThrow(Throwable::class)
        // Se intento una sola vez: no hay bucle de manejo de errores.
        ->and($incidencias->llamadas)->toBe([CodigoIncidencia::LISTENER_ERROR->value])
        ->and(IncidenciaAtribucion::query()->dePedido($pedido->id)->count())->toBe(0);
});

test('29. un error de base de datos no intenta otra escritura por la misma conexion', function () {
    $pedido = va35_pedido(8700);

    $errorDeBase = new QueryException(
        config('database.default'),
        'insert into bylopez_va_order_attributions',
        [],
        new PDOException('SQLSTATE[HY000]: conexion perdida'),
    );

    $motor = va35_registrarQueFalla($errorDeBase);
    $incidencias = va35_incidenciasEspia();

    $ejecutar = fn () => va35_listener('shop.checkout.onepage.orders.store', null, $motor, $incidencias)->handle($pedido);

    expect($ejecutar)->not->toThrow(Throwable::class)
        // Ni incidencia ni fallo tecnico: irian por la conexion que acaba de fallar.
        ->and($incidencias->llamadas)->toBe([])
        ->and($motor->llamadas)->toBe(1)
        ->and(IncidenciaAtribucion::query()->dePedido($pedido->id)->count())->toBe(0)
        ->and(AtribucionPedido::query()->where('order_id', $pedido->id)->count())->toBe(0);
});

test('30. el rollback del pedido revierte tambien la atribucion y la incidencia', function () {
    $asesor = va35_asesor();
    $pedido = va35_pedido(8800);

    app(RegistrarAtribucionService::class)->registrar(
        $pedido->id,
        app(ResolverAtribucionService::class)->paraCreacionAdministrativa(
            creador: $asesor,
            mecanismo: MecanismoCreacion::ADMIN_BAGISTO,
            canal: CanalVenta::DESCONOCIDO,
            cartId: 8800,
        ),
    );

    // Punto de guardado equivalente al que abre OrderRepository antes de crear.
    DB::beginTransaction();

    va35_listener('shop.checkout.onepage.orders.store')->handle($pedido);

    expect(IncidenciaAtribucion::query()->dePedido($pedido->id)->count())->toBe(1);

    // Otro listener revienta y el repositorio revierte: la incidencia se va con todo.
    DB::rollBack();

    expect(IncidenciaAtribucion::query()->dePedido($pedido->id)->count())->toBe(0)
        ->and(AtribucionPedido::query()->where('order_id', $pedido->id)->count())->toBe(1);
});

test('31. tras un reintento del repositorio queda una sola atribucion con un solo historial inicial', function () {
    $transporte = app(ContextoComercialDePeticion::class);
    $asesor = va35_asesor();

    $contexto = app(ResolverAtribucionService::class)->paraCreacionAdministrativa(
        creador: $asesor,
        mecanismo: MecanismoCreacion::ADMIN_BAGISTO,
        canal: CanalVenta::DESCONOCIDO,
        cartId: 8900,
    );

    $leidos = [];
    $pedidos = [];

    /*
     * Reproduce createOrderIfNotThenRetry(): el primer intento se revierte entero y
     * el segundo crea OTRO pedido, con el mismo carrito y el mismo contexto.
     */
    $transporte->durante(8900, $contexto, function () use (&$leidos, &$pedidos, $transporte) {
        foreach ([1, 2] as $intento) {
            DB::beginTransaction();

            $pedido = va35_pedido(8900);
            $pedidos[] = (int) $pedido->id;
            $leidos[] = $transporte->para(8900);

            va35_listener('admin.sales.orders.store', $transporte)->handle($pedido);

            if ($intento === 1) {
                DB::rollBack();   // el primer intento se pierde entero
            } else {
                DB::commit();     // este es el pedido que sobrevive
            }
        }
    });

    $vivos = $pedidos[1];
    $muerto = $pedidos[0];

    expect($pedidos[0])->not->toBe($pedidos[1])
        ->and($leidos[1])->toBe($leidos[0])
        ->and(AtribucionPedido::query()->where('order_id', $muerto)->count())->toBe(0)
        ->and(AtribucionPedido::query()->where('order_id', $vivos)->count())->toBe(1)
        ->and(LogAsignacionAsesor::query()->where('order_id', $vivos)->where('tipo_operacion', TipoOperacionAtribucion::ASIGNACION_INICIAL->value)->count())->toBe(1)
        ->and(AtribucionPedido::query()->where('order_id', $vivos)->first()->advisor_id)->toBe((int) $asesor->id)
        // El duenno del contexto lo retira al salir; el listener nunca lo borro.
        ->and($transporte->para(8900))->toBeNull();
});

test('34. el listener no modifica ninguna tabla del core', function () {
    $pedido = va35_pedido(9200);
    $pedidoAntes = DB::table('orders')->where('id', $pedido->id)->first();

    $antes = va35_fotoDelCore();

    va35_listener('shop.checkout.onepage.orders.store')->handle($pedido);

    expect(va35_fotoDelCore())->toBe($antes)
        ->and(DB::table('orders')->where('id', $pedido->id)->first())->toEqual($pedidoAntes)
        // Lo que si cambio son las tablas propias del modulo.
        ->and(AtribucionPedido::query()->where('order_id', $pedido->id)->count())->toBe(1);
});

test('un pedido sin id no rompe el listener ni escribe nada', function () {
    $huerfano = new Order;

    $ejecutar = fn () => va35_listener('shop.checkout.onepage.orders.store')->handle($huerfano);

    expect($ejecutar)->not->toThrow(Throwable::class)
        ->and(IncidenciaAtribucion::query()->count())->toBe(IncidenciaAtribucion::query()->count());
});

test('un pedido de tienda sin cart_id queda como fallo tecnico con su codigo seguro', function () {
    $pedido = va35_pedido(null);

    va35_listener('shop.checkout.onepage.orders.store')->handle($pedido);

    $atribucion = AtribucionPedido::query()->where('order_id', $pedido->id)->first();

    expect($atribucion)->not->toBeNull()
        ->and($atribucion->attribution_status)->toBe(EstadoAtribucion::FALLO_TECNICO)
        ->and($atribucion->advisor_id)->toBeNull()
        ->and($atribucion->cart_id)->toBeNull()
        ->and(LogAsignacionAsesor::query()->where('order_id', $pedido->id)->value('codigo_tecnico'))
        ->toBe(ClasificadorOrigenPedido::CODIGO_CART_ID_AUSENTE);
});
