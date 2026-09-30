<?php

use ByLopez\VentasAsesor\DataObjects\CeldaMatriz;
use ByLopez\VentasAsesor\DataObjects\DatosClienteComercial;
use ByLopez\VentasAsesor\DataObjects\EstadoNuevaVenta;
use ByLopez\VentasAsesor\DataObjects\MatrizVariantes;
use ByLopez\VentasAsesor\DataObjects\OrigenDireccion;
use ByLopez\VentasAsesor\Enums\MecanismoCreacion;
use ByLopez\VentasAsesor\Exceptions\BorradorVentaInvalidoException as BorradorInvalido;
use ByLopez\VentasAsesor\Exceptions\ClienteComercialExistenteException;
use ByLopez\VentasAsesor\Exceptions\GrupoComercialInvalidoException;
use ByLopez\VentasAsesor\Exceptions\NuevaVentaInvalidaException as Invalida;
use ByLopez\VentasAsesor\Exceptions\VarianteInvalidaException;
use ByLopez\VentasAsesor\Models\BorradorVenta;
use ByLopez\VentasAsesor\Models\SolicitudBorrador;
use ByLopez\VentasAsesor\Services\Clientes\TelefonosClienteService;
use ByLopez\VentasAsesor\Services\Venta\CatalogoVentaService;
use ByLopez\VentasAsesor\Services\Venta\EntregaBorradorService;
use ByLopez\VentasAsesor\Services\Venta\LineasBorradorService;
use ByLopez\VentasAsesor\Services\Venta\NuevaVentaService;
use ByLopez\VentasAsesor\Services\Venta\PagoBorradorService;
use ByLopez\VentasAsesor\Services\Venta\SolicitudBorradorService;
use ByLopez\VentasAsesor\Support\Venta\LoteCatalogo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Webkul\Category\Models\Category;
use Webkul\Category\Models\CategoryTranslation;
use Webkul\Checkout\Models\Cart as CartModel;
use Webkul\Checkout\Models\CartItem;
use Webkul\Customer\Models\CustomerAddress;

require_once __DIR__.'/../helpersFase03f.php';

uses(TestCase::class);

/*
| 03G-B: dominio de "Nueva venta rapida" (orquestador). Solo lpz_saas_testing.
| Sin interfaz: todo se prueba contra NuevaVentaService y los servicios que orquesta.
*/

if (! class_exists('F03gbNuevaVentaContada')) {
    class F03gbNuevaVentaContada extends NuevaVentaService
    {
        public int $estados = 0;

        public function estado(int $borradorId, bool $conMetodosDePago = false, bool $requiereRevisionEntrega = false, ?array $ofrecidosPago = null): EstadoNuevaVenta
        {
            $this->estados++;

            return parent::estado($borradorId, $conMetodosDePago, $requiereRevisionEntrega, $ofrecidosPago);
        }
    }

    class F03gbPagoContado extends PagoBorradorService
    {
        public int $resoluciones = 0;

        protected function ofrecidosConCartYaFijado(): array
        {
            $this->resoluciones++;

            return parent::ofrecidosConCartYaFijado();
        }
    }
}

beforeEach(function () {
    Mail::fake();
    f03f_configurarBase();

    $this->asesor = f03b_admin();
    $this->grupo = f03b_grupo('G');
    $this->cliente = f03b_cliente($this->grupo);
    $this->configurable = f03b_configurable(10000.0);
    $this->variantes = $this->configurable->variants()->get();

    foreach ($this->variantes as $v) {
        f03b_stock($v, 50);
    }

    // Dos tallas de un mismo color: la unidad de trabajo de la venta rapida.
    $porColor = $this->variantes->groupBy(fn ($v) => (int) $v->color)->first(fn ($g) => $g->count() >= 2);
    [$this->m, $this->l] = $porColor->take(2)->values()->all();

    $this->nv = app(NuevaVentaService::class);
});

function f03gb_iniciar($t, ?string $canal = 'tienda_fisica'): EstadoNuevaVenta
{
    return $t->nv->iniciar($t->cliente->id, $t->asesor, $canal);
}

function f03gb_codigo(callable $accion): ?string
{
    try {
        $accion();
    } catch (Invalida|BorradorInvalido|GrupoComercialInvalidoException|VarianteInvalidaException $e) {
        return $e->codigo;
    }

    return null;
}

function f03gb_cantidadesCarrito(int $borradorId): array
{
    $cartId = BorradorVenta::query()->whereKey($borradorId)->value('cart_id');

    return CartItem::query()->from('cart_items as hijo')->join('cart_items as padre', 'padre.id', '=', 'hijo.parent_id')
        ->where('hijo.cart_id', $cartId)->pluck('padre.quantity', 'hijo.product_id')->map(fn ($q) => (int) $q)->all();
}

function f03gb_cantidadesSolicitud(int $borradorId): array
{
    return SolicitudBorrador::query()->where('borrador_id', $borradorId)
        ->pluck('cantidad_solicitada', 'variante_id')->map(fn ($q) => (int) $q)->all();
}

function f03gb_stockTotal($t): int
{
    return (int) DB::table('product_inventories')->whereIn('product_id', $t->variantes->pluck('id'))->sum('qty');
}

/* ---------------------------------------------------------------- Lote de tallas */

test('lote M6 + L6: precio de Bagisto, solicitud y carrito coinciden, sin custom_price y stock intacto', function () {
    $b = f03gb_iniciar($this)->borrador->id;
    $stock = f03gb_stockTotal($this);

    $estado = $this->nv->aplicarTallas($b, $this->configurable->id, [$this->m->id => 6, $this->l->id => 6], $this->asesor);

    expect(f03gb_cantidadesCarrito($b))->toBe([$this->m->id => 6, $this->l->id => 6])
        ->and(f03gb_cantidadesSolicitud($b))->toBe([$this->m->id => 6, $this->l->id => 6])
        ->and($estado->lineas)->toHaveCount(2)
        ->and($estado->solicitudes)->toHaveCount(2)
        ->and($estado->totalEsperado)->toBe(120000.0)
        ->and(f03gb_stockTotal($this))->toBe($stock)
        ->and(CartItem::query()->where('cart_id', $estado->borrador->cartId)->whereNotNull('custom_price')->count())->toBe(0);
});

test('lote de una sola talla y de tres tallas', function () {
    $b = f03gb_iniciar($this)->borrador->id;
    $uno = $this->nv->aplicarTallas($b, $this->configurable->id, [$this->m->id => 3], $this->asesor);

    expect($uno->lineas)->toHaveCount(1);

    $tres = $this->variantes->reject(fn ($v) => (int) $v->color !== (int) $this->m->color)->pluck('id')->all();
    $extra = $this->variantes->pluck('id')->diff([$this->m->id, $this->l->id])->first();
    $estado = $this->nv->aplicarTallas($b, $this->configurable->id, [$this->m->id => 3, $this->l->id => 2, $extra => 1], $this->asesor);

    expect($estado->lineas)->toHaveCount(3)
        ->and(f03gb_cantidadesCarrito($b))->toBe([$this->m->id => 3, $this->l->id => 2, $extra => 1])
        ->and($tres)->not->toBeEmpty();
});

test('mutaciones: 0→6, 6→10, 10→6, 6→0, M6/L6→M3/L10, quitar una talla, agregar una nueva', function () {
    $b = f03gb_iniciar($this)->borrador->id;
    $p = $this->configurable->id;
    $aplicar = fn (array $c) => $this->nv->aplicarTallas($b, $p, $c, $this->asesor);

    $aplicar([$this->m->id => 6]);
    expect(f03gb_cantidadesCarrito($b))->toBe([$this->m->id => 6])->and(f03gb_cantidadesSolicitud($b))->toBe([$this->m->id => 6]);

    $aplicar([$this->m->id => 10]);
    expect(f03gb_cantidadesCarrito($b))->toBe([$this->m->id => 10])->and(f03gb_cantidadesSolicitud($b))->toBe([$this->m->id => 10]);

    $aplicar([$this->m->id => 6]);
    expect(f03gb_cantidadesCarrito($b))->toBe([$this->m->id => 6])->and(f03gb_cantidadesSolicitud($b))->toBe([$this->m->id => 6]);

    $aplicar([$this->m->id => 0]);
    expect(f03gb_cantidadesCarrito($b))->toBe([])->and(f03gb_cantidadesSolicitud($b))->toBe([]);

    $aplicar([$this->m->id => 6, $this->l->id => 6]);
    $aplicar([$this->m->id => 3, $this->l->id => 10]);
    expect(f03gb_cantidadesCarrito($b))->toBe([$this->m->id => 3, $this->l->id => 10])
        ->and(f03gb_cantidadesSolicitud($b))->toBe([$this->m->id => 3, $this->l->id => 10]);

    $aplicar([$this->m->id => 0]);
    expect(f03gb_cantidadesCarrito($b))->toBe([$this->l->id => 10]);

    $nueva = $this->variantes->pluck('id')->diff([$this->m->id, $this->l->id])->first();
    $aplicar([$nueva => 2]);
    expect(f03gb_cantidadesCarrito($b))->toBe([$this->l->id => 10, $nueva => 2])
        ->and(f03gb_cantidadesSolicitud($b))->toBe([$this->l->id => 10, $nueva => 2]);
});

test('una solicitud por encima del carrito no arrastra el carrito: solicitud y carrito son cosas distintas (D-39)', function () {
    $b = f03gb_iniciar($this)->borrador->id;
    $this->nv->aplicarTallas($b, $this->configurable->id, [$this->m->id => 6], $this->asesor);

    $estado = $this->nv->aplicarTallas($b, $this->configurable->id, [$this->m->id => 2], $this->asesor);

    expect($estado->solicitudes[0]->cantidadSolicitada)->toBe(2)
        ->and($estado->solicitudes[0]->cantidadEnCarrito)->toBe(2)
        ->and($estado->lineas[0]->cantidad)->toBe(2);
});

test('todo o nada: una talla sin stock revierte TODO el lote y dice cual variante y por que', function () {
    f03b_stock($this->l, 4);
    $b = f03gb_iniciar($this)->borrador->id;
    $this->nv->aplicarTallas($b, $this->configurable->id, [$this->m->id => 2], $this->asesor);

    try {
        $this->nv->aplicarTallas($b, $this->configurable->id, [$this->m->id => 5, $this->l->id => 9], $this->asesor);
        $this->fail('Debio rechazar el lote');
    } catch (Invalida $e) {
        expect($e->codigo)->toBe(Invalida::LOTE_RECHAZADO)
            ->and($e->varianteId)->toBe($this->l->id)
            ->and($e->causa)->not->toBeNull();
    }

    expect(f03gb_cantidadesCarrito($b))->toBe([$this->m->id => 2])
        ->and(f03gb_cantidadesSolicitud($b))->toBe([$this->m->id => 2]);
});

test('todo o nada: M6 ya aplicada en el lote + L sin stock → carrito, lineas, totales, tarifas, entrega y solicitudes identicos a antes', function () {
    f03b_stock($this->l, 4);
    $b = f03gb_iniciar($this)->borrador->id;
    $this->nv->aplicarTallas($b, $this->configurable->id, [$this->m->id => 2, $this->l->id => 2], $this->asesor);
    $this->nv->entregaRapida($b, $this->asesor, OrigenDireccion::puntoRecogida(), 'recogida_bodega', OrigenDireccion::puntual(f03f_direccion()));
    $cartId = (int) BorradorVenta::query()->whereKey($b)->value('cart_id');

    $foto = fn () => [
        'cart' => (array) DB::table('cart')->where('id', $cartId)->first(['shipping_method', 'items_count', 'items_qty', 'sub_total', 'tax_total', 'discount_amount', 'shipping_amount', 'grand_total', 'base_grand_total']),
        'items' => DB::table('cart_items')->where('cart_id', $cartId)->orderBy('id')->get(['id', 'product_id', 'parent_id', 'quantity', 'price', 'base_price', 'total', 'discount_amount', 'tax_amount', 'custom_price'])->map(fn ($r) => (array) $r)->all(),
        'tarifas' => DB::table('cart_shipping_rates')->where('cart_id', $cartId)->orderBy('id')->get(['method', 'price'])->map(fn ($r) => (array) $r)->all(),
        'solicitudes' => f03gb_cantidadesSolicitud($b),
    ];
    $antes = $foto();

    // M va primero en el lote: se muta (solicitud y carrito) y luego L falla por stock.
    $mutadas = [];
    Event::listen('checkout.cart.update.after', function ($item) use (&$mutadas) {
        $mutadas[] = [(int) $item->child?->product_id, (int) $item->quantity];
    });

    expect(f03gb_codigo(fn () => $this->nv->aplicarTallas($b, $this->configurable->id, [$this->m->id => 6, $this->l->id => 999], $this->asesor)))
        ->toBe(Invalida::LOTE_RECHAZADO);

    expect($mutadas)->toBe([[$this->m->id, 6]])
        ->and($antes['cart']['shipping_method'])->toBe('recogida_bodega')
        ->and($antes['tarifas'])->not->toBeEmpty()
        ->and($foto())->toBe($antes)
        ->and(f03gb_cantidadesCarrito($b))->toBe([$this->m->id => 2, $this->l->id => 2])
        ->and(DB::transactionLevel())->toBe(1);
});

test('todo o nada: variante de otro configurable, cantidad invalida o lote vacio se rechazan sin tocar nada', function () {
    $otro = f03b_configurable(9000.0);
    $ajena = $otro->variants()->first();
    f03b_stock($ajena, 20);
    $b = f03gb_iniciar($this)->borrador->id;
    $p = $this->configurable->id;

    expect(f03gb_codigo(fn () => $this->nv->aplicarTallas($b, $p, [$this->m->id => 2, $ajena->id => 1], $this->asesor)))->not->toBeNull()
        ->and(f03gb_codigo(fn () => $this->nv->aplicarTallas($b, $p, [$this->m->id => 2, $this->l->id => -1], $this->asesor)))->not->toBeNull()
        ->and(f03gb_codigo(fn () => $this->nv->aplicarTallas($b, $p, [$this->m->id => 'x'], $this->asesor)))->not->toBeNull()
        ->and(f03gb_codigo(fn () => $this->nv->aplicarTallas($b, $p, [], $this->asesor)))->toBe(Invalida::LOTE_VACIO)
        ->and(f03gb_cantidadesCarrito($b))->toBe([])
        ->and(f03gb_cantidadesSolicitud($b))->toBe([]);
});

test('RT-03: el precio del lote es el del grupo del cliente, no el de la sesion', function () {
    f03b_descuentoDeGrupo($this->m, $this->grupo, 20, $this->configurable);
    auth()->guard('customer')->logout();
    $b = f03gb_iniciar($this)->borrador->id;

    $estado = $this->nv->aplicarTallas($b, $this->configurable->id, [$this->m->id => 5], $this->asesor);

    expect($estado->lineas[0]->precioUnitario)->toBe(8000.0)
        ->and($estado->totalEsperado)->toBe(40000.0);
});

test('el estado se compone UNA vez por operacion', function () {
    $nv = app()->make(F03gbNuevaVentaContada::class);
    $inicio = $nv->iniciar($this->cliente->id, $this->asesor, 'whatsapp');
    expect($nv->estados)->toBe(1);

    $nv->estados = 0;
    $nv->aplicarTallas($inicio->borrador->id, $this->configurable->id, [$this->m->id => 3, $this->l->id => 4], $this->asesor);
    expect($nv->estados)->toBe(1);

    $nv->estados = 0;
    $nv->entregaRapida($inicio->borrador->id, $this->asesor, OrigenDireccion::puntoRecogida(), 'recogida_bodega', OrigenDireccion::puntual(f03f_direccion()));
    expect($nv->estados)->toBe(1);
});

/* ---------------------------------------------------------------- Cliente */

test('iniciar con cliente existente: borrador sin lineas, canal explicito y sin persistir "ultimo canal"', function () {
    $estado = f03gb_iniciar($this, 'whatsapp');

    expect($estado->borrador->canalVenta)->toBe('whatsapp')
        ->and($estado->lineas)->toBe([])
        ->and($estado->confirmable())->toBeFalse()
        ->and($estado->motivosNoConfirmable)->toContain(EstadoNuevaVenta::MOTIVO_SIN_LINEAS);
});

test('canal no permitido no crea nada', function () {
    $antes = BorradorVenta::query()->count();

    expect(f03gb_codigo(fn () => f03gb_iniciar($this, 'ecommerce')))->toBe(Invalida::CANAL_NO_PERMITIDO)
        ->and(BorradorVenta::query()->count())->toBe($antes);
});

test('cliente inexistente: no se crea borrador ni Cart', function () {
    $borradores = BorradorVenta::query()->count();
    $carts = CartModel::query()->count();

    expect(f03gb_codigo(fn () => $this->nv->iniciar(99_999_999, $this->asesor, 'whatsapp')))->toBe(BorradorInvalido::CLIENTE_INEXISTENTE)
        ->and(BorradorVenta::query()->count())->toBe($borradores)
        ->and(CartModel::query()->count())->toBe($carts);
});

test('cliente nuevo: sin email, grupo explicito, telefono como identidad', function () {
    $telefono = '3'.random_int(100000000, 199999999);
    $estado = $this->nv->iniciarConClienteNuevo(
        new DatosClienteComercial('Lucia', 'Nueva', $telefono, $this->grupo->id), $this->asesor, 'whatsapp');

    $cliente = DB::table('customers')->where('id', $estado->borrador->cliente['customer_id'])->first();

    expect($cliente->email)->toBeNull()
        ->and((int) $cliente->customer_group_id)->toBe($this->grupo->id)
        ->and($estado->borrador->canalVenta)->toBe('whatsapp');
});

test('cliente nuevo: grupo invitado o nulo se rechaza y no queda cliente ni borrador', function () {
    $clientes = DB::table('customers')->count();
    $borradores = BorradorVenta::query()->count();
    $datos = fn (?int $g) => new DatosClienteComercial('Ana', 'Sin', '3'.random_int(100000000, 199999999), $g);

    expect(f03gb_codigo(fn () => $this->nv->iniciarConClienteNuevo($datos(core()->getGuestCustomerGroup()->id), $this->asesor, 'whatsapp')))
        ->not->toBeNull()
        ->and(f03gb_codigo(fn () => $this->nv->iniciarConClienteNuevo($datos(null), $this->asesor, 'whatsapp')))->not->toBeNull()
        ->and(DB::table('customers')->count())->toBe($clientes)
        ->and(BorradorVenta::query()->count())->toBe($borradores);
});

test('cliente nuevo con telefono de otro cliente: RT-04, no se duplica', function () {
    $existente = f03b_cliente($this->grupo, ['phone' => '3111234567']);
    app(TelefonosClienteService::class)->establecerPrincipal($existente->id, '3111234567', false);
    $clientes = DB::table('customers')->count();

    expect(fn () => $this->nv->iniciarConClienteNuevo(
        new DatosClienteComercial('Otra', 'Persona', '3111234567', $this->grupo->id), $this->asesor, 'whatsapp'))
        ->toThrow(ClienteComercialExistenteException::class)
        ->and(DB::table('customers')->count())->toBe($clientes);
});

test('la misma referencia externa devuelve la misma venta sin volver a crear al cliente', function () {
    $datos = new DatosClienteComercial('Rita', 'Ref', '3'.random_int(100000000, 199999999), $this->grupo->id);
    $a = $this->nv->iniciarConClienteNuevo($datos, $this->asesor, 'whatsapp', 'ref-03gb-1', MecanismoCreacion::N8N);
    $clientes = DB::table('customers')->count();
    $b = $this->nv->iniciarConClienteNuevo($datos, $this->asesor, 'whatsapp', 'ref-03gb-1', MecanismoCreacion::N8N);

    expect($b->borrador->id)->toBe($a->borrador->id)->and(DB::table('customers')->count())->toBe($clientes);
});

test('cliente inactivo o suspendido: puede tener borrador, el estado dice por que no es confirmable', function () {
    $inactivo = f03b_cliente($this->grupo, ['status' => 0]);
    $suspendido = f03b_cliente($this->grupo, ['is_suspended' => 1]);

    foreach ([$inactivo, $suspendido] as $c) {
        $estado = $this->nv->iniciar($c->id, $this->asesor, 'whatsapp');

        expect($estado->borrador->clienteConfirmable)->toBeFalse()
            ->and($estado->motivosNoConfirmable)->toContain(EstadoNuevaVenta::MOTIVO_CLIENTE_NO_CONFIRMABLE);
    }
});

/* ---------------------------------------------------------------- Entrega */

test('recogida con facturacion REAL: usa la direccion predeterminada y la bodega es solo el envio', function () {
    $direccion = CustomerAddress::factory()->create([
        'customer_id' => $this->cliente->id, 'address_type' => 'customer', 'default_address' => 1,
        'first_name' => 'Real', 'last_name' => 'Cliente',
    ]);
    $b = f03gb_iniciar($this)->borrador->id;
    $this->nv->aplicarTallas($b, $this->configurable->id, [$this->m->id => 2], $this->asesor);

    expect($this->nv->direccionPredeterminadaId($this->cliente->id))->toBe($direccion->id);

    $estado = $this->nv->entregaRapida($b, $this->asesor, OrigenDireccion::puntoRecogida(), 'recogida_bodega');

    expect($estado->requiereDireccionFacturacion)->toBeFalse()
        ->and($estado->entrega->facturacion->nombre ?? null)->toBe('Real')
        ->and($estado->entrega->metodoSeleccionado->codigo)->toBe('recogida_bodega')
        ->and($estado->entrega->entregaCompleta)->toBeTrue();
});

test('recogida sin ninguna direccion: exige una real y NO inventa la de la bodega', function () {
    $b = f03gb_iniciar($this)->borrador->id;
    $this->nv->aplicarTallas($b, $this->configurable->id, [$this->m->id => 2], $this->asesor);

    expect(f03gb_iniciar($this)->requiereDireccionFacturacion)->toBeTrue()
        ->and(f03gb_codigo(fn () => $this->nv->entregaRapida($b, $this->asesor, OrigenDireccion::puntoRecogida(), 'recogida_bodega')))
        ->toBe(Invalida::DIRECCION_FACTURACION_REQUERIDA);

    $estado = $this->nv->entregaRapida($b, $this->asesor, OrigenDireccion::puntoRecogida(), 'recogida_bodega', OrigenDireccion::puntual(f03f_direccion()));

    expect($estado->requiereDireccionFacturacion)->toBeFalse()
        ->and($estado->entrega->facturacion->nombre)->toBe('Ana')
        ->and($estado->entrega->entregaCompleta)->toBeTrue();
});

test('domicilio y envio nacional se resuelven en una sola operacion', function (string $metodo) {
    $b = f03gb_iniciar($this)->borrador->id;
    $this->nv->aplicarTallas($b, $this->configurable->id, [$this->m->id => 2], $this->asesor);

    $estado = $this->nv->entregaRapida($b, $this->asesor,
        OrigenDireccion::puntual(f03f_direccion(['lineas' => ['Carrera 9 # 8-7']])), $metodo, OrigenDireccion::puntual(f03f_direccion()));

    expect($estado->entrega->metodoSeleccionado->codigo)->toBe($metodo)
        ->and($estado->entrega->entregaCompleta)->toBeTrue();
})->with(['domicilio_local', 'envio_nacional']);

test('editar lineas: el mismo metodo se reaplica si sigue disponible', function () {
    $b = f03gb_iniciar($this)->borrador->id;
    $this->nv->aplicarTallas($b, $this->configurable->id, [$this->m->id => 2], $this->asesor);
    $this->nv->entregaRapida($b, $this->asesor, OrigenDireccion::puntual(f03f_direccion(['lineas' => ['Carrera 9 # 8-7']])), 'domicilio_local', OrigenDireccion::puntual(f03f_direccion()));

    $estado = $this->nv->aplicarTallas($b, $this->configurable->id, [$this->m->id => 5, $this->l->id => 1], $this->asesor);

    expect($estado->requiereRevisionEntrega)->toBeFalse()
        ->and($estado->entrega->metodoSeleccionado->codigo)->toBe('domicilio_local')
        ->and($estado->entrega->entregaCompleta)->toBeTrue();
});

test('editar lineas: si la tarifa cambio se acepta la nueva y el total la refleja', function () {
    $b = f03gb_iniciar($this)->borrador->id;
    $this->nv->aplicarTallas($b, $this->configurable->id, [$this->m->id => 2], $this->asesor);
    $antes = $this->nv->entregaRapida($b, $this->asesor, OrigenDireccion::puntual(f03f_direccion(['lineas' => ['Carrera 9 # 8-7']])), 'domicilio_local', OrigenDireccion::puntual(f03f_direccion()));
    expect($antes->entrega->metodoSeleccionado->precio)->toBe(8000.0);

    config(['carriers.domicilio_local.default_rate' => '12000']);
    $estado = $this->nv->aplicarTallas($b, $this->configurable->id, [$this->m->id => 3], $this->asesor);

    expect($estado->requiereRevisionEntrega)->toBeFalse()
        ->and($estado->entrega->metodoSeleccionado->precio)->toBe(12000.0)
        ->and($estado->totalEsperado)->toBe(42000.0);
});

test('editar lineas: si el metodo ya no existe se pide revision y NUNCA se elige otro', function () {
    $b = f03gb_iniciar($this)->borrador->id;
    $this->nv->aplicarTallas($b, $this->configurable->id, [$this->m->id => 2], $this->asesor);
    $this->nv->entregaRapida($b, $this->asesor, OrigenDireccion::puntual(f03f_direccion(['lineas' => ['Carrera 9 # 8-7']])), 'domicilio_local', OrigenDireccion::puntual(f03f_direccion()));

    config(['carriers.domicilio_local.active' => false]);
    $estado = $this->nv->aplicarTallas($b, $this->configurable->id, [$this->m->id => 3], $this->asesor);

    expect($estado->requiereRevisionEntrega)->toBeTrue()
        ->and($estado->entrega->metodoSeleccionado)->toBeNull()
        ->and($estado->motivosNoConfirmable)->toContain(EstadoNuevaVenta::MOTIVO_REVISAR_ENTREGA)
        ->and(f03gb_cantidadesCarrito($b))->toBe([$this->m->id => 3]);
});

/* ---------------------------------------------------------------- Pago */

test('pago: los metodos ofrecidos se resuelven UNA vez por operacion y solo si se tocan', function () {
    $contado = app()->make(F03gbPagoContado::class);
    app()->instance(PagoBorradorService::class, $contado);
    $nv = app()->make(NuevaVentaService::class);

    $b = $nv->iniciar($this->cliente->id, $this->asesor, 'whatsapp')->borrador->id;
    $nv->aplicarTallas($b, $this->configurable->id, [$this->m->id => 2], $this->asesor);
    $nv->entregaRapida($b, $this->asesor, OrigenDireccion::puntoRecogida(), 'recogida_bodega', OrigenDireccion::puntual(f03f_direccion()));
    expect($contado->resoluciones)->toBe(0);

    $estado = $nv->seleccionarPago($b, 'cashondelivery', $this->asesor);

    expect($contado->resoluciones)->toBe(1)
        ->and($estado->metodosPagoResueltos)->toBeTrue()
        ->and($estado->pago->metodoSeleccionado?->codigo)->toBe('cashondelivery');
});

test('venta completa: el total del estado es el que confirma 03F', function () {
    $b = f03gb_iniciar($this)->borrador->id;
    $this->nv->aplicarTallas($b, $this->configurable->id, [$this->m->id => 3, $this->l->id => 3], $this->asesor);
    $this->nv->entregaRapida($b, $this->asesor, OrigenDireccion::puntoRecogida(), 'recogida_bodega', OrigenDireccion::puntual(f03f_direccion()));
    $estado = $this->nv->seleccionarPago($b, 'cashondelivery', $this->asesor);

    expect($estado->confirmable())->toBeTrue()->and($estado->totalEsperado)->toBe(60000.0);

    $resultado = $this->nv->confirmar($b, $this->asesor, $estado->totalEsperado);

    expect($resultado)->not->toBeNull();
});

/* ---------------------------------------------------------------- Catalogo */

function f03gb_categoria(string $slug, string $nombre, ?int $padre = 1): Category
{
    $categoria = Category::factory()->create(['parent_id' => $padre, 'status' => 1]);
    CategoryTranslation::query()->where('category_id', $categoria->id)->delete();
    CategoryTranslation::factory()->create([
        'category_id' => $categoria->id, 'slug' => $slug, 'name' => $nombre,
        'locale' => 'es', 'locale_id' => core()->getAllLocales()->firstWhere('code', 'es')?->id ?? 1,
    ]);

    return $categoria->fresh();
}

test('accesos rapidos: se resuelven por slug/nombre de la config, no por id, y los faltantes quedan sin resolver', function () {
    $dama = f03gb_categoria('camisetas-dama', 'CAMISETAS DAMA');
    $nino = f03gb_categoria('otro-slug', 'CAMISETA NIÑO');

    $accesos = collect(app(CatalogoVentaService::class)->accesosRapidos())->keyBy('clave');

    expect($accesos)->toHaveCount(4)
        ->and($accesos['camiseta_dama']->categoriaId)->toBe($dama->id)
        ->and($accesos['camiseta_nino']->categoriaId)->toBe($nino->id)
        ->and($accesos['camiseta_caballero']->resuelto())->toBeFalse()
        ->and($accesos['camiseta_caballero']->motivo)->toBe('categoria_inexistente');

    config(['ventasasesor.nueva_venta.accesos_rapidos' => [
        ['clave' => 'x', 'etiqueta' => 'X', 'slugs' => ['camisetas-dama'], 'nombres' => []],
    ]]);
    expect(app(CatalogoVentaService::class)->accesosRapidos()[0]->categoriaId)->toBe($dama->id);
});

test('accesos rapidos: dos categorias con el mismo slug es ambiguo y falla cerrado', function () {
    f03gb_categoria('dup-a', 'TOP Dama');
    f03gb_categoria('dup-b', 'TOP Dama');

    $acceso = collect(app(CatalogoVentaService::class)->accesosRapidos())->firstWhere('clave', 'top_dama');

    expect($acceso->resuelto())->toBeFalse()->and($acceso->motivo)->toBe('categoria_ambigua')
        ->and(f03gb_codigo(fn () => app(CatalogoVentaService::class)->coloresDeAcceso('top_dama')))->toBe(Invalida::ACCESO_RAPIDO_SIN_RESOLVER)
        ->and(f03gb_codigo(fn () => app(CatalogoVentaService::class)->coloresDeAcceso('no_existe')))->toBe(Invalida::ACCESO_RAPIDO_INEXISTENTE);
});

test('colores de un acceso: solo los productos de esa categoria', function () {
    $categoria = f03gb_categoria('camiseta-caballero', 'CAMISETA CABALLERO');
    $this->configurable->categories()->sync([$categoria->id]);
    $otro = f03b_configurable(9000.0);

    $colores = app(CatalogoVentaService::class)->coloresDeAcceso('camiseta_caballero');

    expect(collect($colores)->pluck('productId')->all())->toContain($this->configurable->id)
        ->and(collect($colores)->pluck('productId')->all())->not->toContain($otro->id);
});

test('matriz: colores, tallas ordenadas por sort_order, SKU, stock, precio de grupo y sin disponibilidad calculada', function () {
    f03b_stock($this->m, 7);
    f03b_descuentoDeGrupo($this->m, $this->grupo, 10, $this->configurable);
    $matriz = app(CatalogoVentaService::class)->matriz($this->configurable->id, $this->cliente->id, $this->asesor);

    expect($matriz->productoId)->toBe($this->configurable->id)
        ->and($matriz->celdas)->toHaveCount($this->variantes->count());

    $celda = collect($matriz->celdas)->first(fn ($c) => $c->variante->productId === $this->m->id);

    expect($celda->variante->sku)->toBe($this->m->sku)
        ->and($celda->variante->stockVendible)->toBe(7)
        ->and($celda->variante->precioUnitario)->toBe(9000.0);

    $ordenes = collect($matriz->celdas)->pluck('ordenTalla')->all();
    $ordenadas = $ordenes;
    sort($ordenadas);
    expect($ordenes)->toBe($ordenadas);

    expect(array_keys(get_object_vars($celda->variante)))->not->toContain('disponibilidad');
});

test('matriz: la senal de ventas simultaneas llega por lote y no cambia el stock', function () {
    $otro = f03b_admin();
    $otroBorrador = $this->nv->iniciar($this->cliente->id, $otro, 'whatsapp')->borrador->id;
    $this->nv->aplicarTallas($otroBorrador, $this->configurable->id, [$this->m->id => 4], $otro);

    $mio = f03gb_iniciar($this)->borrador->id;
    $stock = f03gb_stockTotal($this);
    $matriz = app(CatalogoVentaService::class)->matriz($this->configurable->id, $this->cliente->id, $this->asesor, 1, $mio);

    $celda = collect($matriz->celdas)->first(fn ($c) => $c->variante->productId === $this->m->id);

    expect($celda->senal)->not->toBeNull()
        ->and(f03gb_stockTotal($this))->toBe($stock);
});

test('03G-B2 equivalencia: matriz con LoteCatalogo es IDENTICA (sin contar "consultado_en") a construirla variante por variante sin lote', function () {
    f03b_stock($this->m, 7);
    f03b_descuentoDeGrupo($this->m, $this->grupo, 10, $this->configurable);
    $cat = app(CatalogoVentaService::class);

    $conLote = $cat->matriz($this->configurable->id, $this->cliente->id, $this->asesor)->toArray();

    // Misma variante, en el mismo orden, construida SIN LoteCatalogo (variante() en vez de varianteEnLote()).
    $porOrden = collect($conLote['celdas'])->keyBy(fn ($c) => $c['variante']['productId']);
    $sinLote = (new MatrizVariantes(
        $this->configurable->id, $this->configurable->type, $this->configurable->sku, $this->configurable->name,
        collect($conLote['colores'])->all(),
        $porOrden->map(fn ($c, $varianteId) => new CeldaMatriz(
            $cat->variante($this->configurable->id, $varianteId, $this->cliente->id),
            $c['orden_talla'],
            null,
        ))->values()->all(),
        1,
    ))->toArray();

    $limpiar = function (array $m) {
        foreach ($m['celdas'] as &$c) {
            unset($c['senal']);
        }

        return $m;
    };

    expect($limpiar($conLote))->toEqual($limpiar($sinLote));
});

test('03G-B2 sin N+1: la matriz memoriza traducciones en lote (mismo numero de consultas, menos tiempo con 4+ variantes)', function () {
    $cuatro = f03b_configurable(10000.0);
    foreach ($cuatro->variants()->get() as $v) {
        f03b_stock($v, 20);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $t = microtime(true);
    app(CatalogoVentaService::class)->matriz($cuatro->id, $this->cliente->id, $this->asesor);
    $ms = (microtime(true) - $t) * 1000;
    $consultas = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($consultas)->toBeLessThanOrEqual(35)
        ->and($ms)->toBeLessThan(700.0);
});

test('buscar variante por SKU', function () {
    $v = app(CatalogoVentaService::class)->buscarVariantePorSku($this->m->sku, $this->cliente->id);

    expect($v->productId)->toBe($this->m->id)
        ->and(f03gb_codigo(fn () => app(CatalogoVentaService::class)->buscarVariantePorSku('NO-EXISTE', $this->cliente->id)))->not->toBeNull();
});

test('sin N+1: la matriz usa relaciones precargadas (consultas acotadas y estables entre productos)', function () {
    $consultas = function (int $productoId) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(CatalogoVentaService::class)->matriz($productoId, $this->cliente->id, $this->asesor);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $pequeno = $consultas($this->configurable->id);

    $grande = f03b_configurable(10000.0);
    foreach ($grande->variants()->get() as $v) {
        f03b_stock($v, 5);
    }
    $nGrande = $consultas($grande->id);

    expect($pequeno)->toBeLessThanOrEqual(35)
        ->and(abs($nGrande - $pequeno))->toBeLessThanOrEqual(3);
});

/* ---------------------------------------------------------------- 03G-B2: lote de catalogo */

/** Borrador variado: tallas con precio de grupo, solicitud por encima del carrito, simple y variante desactivada. */
function f03gb2_borradorVariado($t): int
{
    f03b_descuentoDeGrupo($t->m, $t->grupo, 20, $t->configurable);
    $simple = f03f_simple(7000.0);
    $tercera = $t->variantes->pluck('id')->diff([$t->m->id, $t->l->id])->first();
    $b = f03gb_iniciar($t)->borrador->id;

    $t->nv->aplicarTallas($b, $t->configurable->id, [$t->m->id => 6, $t->l->id => 6, $tercera => 3], $t->asesor);
    $t->nv->aplicarTallas($b, $simple->id, [$simple->id => 2], $t->asesor);
    app(SolicitudBorradorService::class)
        ->cambiarCantidad($b, (int) SolicitudBorrador::query()->where('borrador_id', $b)->where('variante_id', $t->l->id)->value('id'), 9, $t->asesor);
    $t->nv->entregaRapida($b, $t->asesor, OrigenDireccion::puntoRecogida(), 'recogida_bodega', OrigenDireccion::puntual(f03f_direccion()));

    DB::table('product_attribute_values')->where('product_id', $tercera)
        ->where('attribute_id', DB::table('attributes')->where('code', 'status')->value('id'))->update(['boolean_value' => 0]);

    return $b;
}

function f03gb2_vistas(int $b, bool $enLote): array
{
    $lote = $enLote ? new LoteCatalogo : null;
    $lineas = app(LineasBorradorService::class)->listar($b, $lote);

    return [
        'lineas' => $lineas,
        'solicitudes' => app(SolicitudBorradorService::class)->listar($b, $lote, $enLote ? $lineas->borrador : null),
        'entrega' => app(EntregaBorradorService::class)->vista($b, $enLote ? $lineas : null),
        'pago' => app(PagoBorradorService::class)->vista($b, [], $enLote ? $lineas->borrador : null),
    ];
}

test('03G-B2 equivalencia: lineas, solicitudes, entrega y pago en lote son IDENTICOS a la lectura normal', function () {
    $b = f03gb2_borradorVariado($this);

    $normal = f03gb2_vistas($b, false);
    $enLote = f03gb2_vistas($b, true);

    expect($enLote)->toEqual($normal)
        ->and($normal['solicitudes']->solicitudes)->toHaveCount(4)
        ->and(collect($normal['solicitudes']->solicitudes)->where('vendible', false))->toHaveCount(1)
        ->and(collect($normal['solicitudes']->solicitudes)->firstWhere('varianteId', $this->m->id)->precioUnitarioReferencia)->toBe(8000.0)
        ->and($normal['entrega']->metodoSeleccionado?->codigo)->toBe('recogida_bodega');
});

test('03G-B2 equivalencia: cliente con grupo no apto → misma vista en lote (sin precio) que sin lote', function () {
    $b = f03gb2_borradorVariado($this);
    $this->cliente->update(['customer_group_id' => DB::table('customer_groups')->where('code', 'guest')->value('id')]);

    expect(f03gb2_vistas($b, true))->toEqual(f03gb2_vistas($b, false))
        ->and(collect(f03gb2_vistas($b, true)['solicitudes']->solicitudes)->pluck('precioUnitarioReferencia')->filter()->all())->toBe([]);
});

test('03G-B2 equivalencia: varianteEnLote da la misma variante y los mismos errores que variante()', function () {
    f03b_descuentoDeGrupo($this->m, $this->grupo, 20, $this->configurable);
    $cat = app(CatalogoVentaService::class);
    $lote = new LoteCatalogo;
    $p = $this->configurable->id;
    $c = $this->cliente->id;

    foreach ($this->variantes as $v) {
        foreach ([1, 7] as $cantidad) {
            expect($cat->varianteEnLote($lote, $p, $v->id, $c, $cantidad))->toEqual($cat->variante($p, $v->id, $c, $cantidad));
        }
    }

    $ajena = f03b_configurable(9000.0)->variants()->first();
    $invitado = f03b_cliente();
    $invitado->update(['customer_group_id' => DB::table('customer_groups')->where('code', 'guest')->value('id')]);
    $codigo = fn (callable $f) => rescue($f, fn ($e) => $e::class.':'.($e->codigo ?? ''), false);

    foreach ([
        [$p, $ajena->id, $c, 1], [$p, 99_999_999, $c, 1], [$this->m->id, $this->m->id, $c, 1],
        [99_999_999, $this->m->id, $c, 1], [$p, $this->m->id, $c, 0], [$p, $this->m->id, $invitado->id, 1],
    ] as [$producto, $variante, $cliente, $cantidad]) {
        $esperado = $codigo(fn () => $cat->variante($producto, $variante, $cliente, $cantidad));

        expect($esperado)->toBeString()
            ->and($codigo(fn () => $cat->varianteEnLote($lote, $producto, $variante, $cliente, $cantidad)))->toBe($esperado);
    }
});

test('03G-B2 sin N+1 propio: el estado y el lote crecen poco por talla y collectTotals sigue siendo uno por primitiva', function () {
    $contar = function (callable $f) {
        $totales = 0;
        Event::listen('checkout.cart.collect.totals.before', function () use (&$totales) {
            $totales++;
        });
        DB::flushQueryLog();
        DB::enableQueryLog();
        $f();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$n, $totales];
    };
    $cuatro = $this->variantes->take(4)->pluck('id')->all();

    $b1 = f03gb_iniciar($this)->borrador->id;
    [$lote1, $ct1] = $contar(fn () => $this->nv->aplicarTallas($b1, $this->configurable->id, [$cuatro[0] => 6], $this->asesor));
    [$estado1] = $contar(fn () => $this->nv->estado($b1));

    $b4 = f03gb_iniciar($this)->borrador->id;
    [$lote4, $ct4] = $contar(fn () => $this->nv->aplicarTallas($b4, $this->configurable->id, array_fill_keys($cuatro, 6), $this->asesor));
    [$estado4] = $contar(fn () => $this->nv->estado($b4));

    expect($ct1)->toBe(1)
        ->and($ct4 - $ct1)->toBe(3)
        ->and($estado4 - $estado1)->toBeLessThanOrEqual(3 * 12)
        ->and($lote4 - $lote1)->toBeLessThanOrEqual(3 * 110);
});
