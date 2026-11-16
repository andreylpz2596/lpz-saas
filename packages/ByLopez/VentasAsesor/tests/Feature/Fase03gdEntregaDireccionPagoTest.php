<?php

use ByLopez\Logistica\Support\PuntosRecogida;
use ByLopez\VentasAsesor\DataObjects\DireccionComercial;
use ByLopez\VentasAsesor\Services\Clientes\GuardarDireccionClienteComercialService;
use ByLopez\VentasAsesor\Services\Venta\BorradorVentaService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor as Autz;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;
use Webkul\Customer\Models\Customer;
use Webkul\User\Models\Admin;

require_once __DIR__.'/../helpersFase03f.php';
require_once __DIR__.'/../helpersDomicilioLocal.php';
require_once __DIR__.'/../helpersGeo.php';

uses(TestCase::class);

/*
| 03G-D · Entrega → Direccion → Pago → Confirmar, por la API HTTP real de Nueva venta.
| CustomerAddress (reutilizable) → CartAddress (esta venta) → OrderAddress (historico).
| Recogida = bodega real + carrier `pickup`, sin pedir direccion de envio del cliente (D-41).
*/

beforeEach(function () {
    Mail::fake();
    f03f_configurarBase();
    geo_cargar();   // D-58: catalogo DIVIPOLA minimo, con el importador real, dentro de la transaccion
    dl_domicilioLocal();   // domicilio local con la regla vigente (zona local + domiciliario + tarifa 8.000)

    $this->grupo = f03b_grupo('G');
    $this->configurable = f03b_configurable(10000.0);
    $this->variante = $this->configurable->variants()->first();
    f03b_stock($this->variante, 50);
});

/** Asesor real: crea ventas, recibe la atribucion automatica (D-27) y ve sus pedidos. */
function f03ge_asesor(): Admin
{
    return f03b_admin('custom', [
        Autz::PERMISO_MODULO, BorradorVentaService::PERMISO, Autz::PERMISO_OPERAR_COMO_ASESOR,
        'ventas_asesor.pedidos', Autz::PERMISO_PEDIDOS_VER_PROPIOS,
    ]);
}

function f03ge_cliente(array $extra = []): Customer
{
    return f03b_cliente(test()->grupo, array_merge(['phone' => '3'.random_int(100000000, 199999999)], $extra));
}

/** Direccion guardada por el servicio de RT-04 (el mismo que usa la pantalla). */
function f03ge_direccion(Customer $cliente, string $linea, bool $predeterminada = false): int
{
    return (int) app(GuardarDireccionClienteComercialService::class)->guardar($cliente->id, new DireccionComercial(
        nombre: 'Rita', apellido: 'Destino', lineas: [$linea], ciudad: 'Bogotá', departamento: 'DC',
        pais: 'CO', codigoPostal: '110111', telefono: '3001112233', predeterminada: $predeterminada,
    ))->id;
}

/** Venta iniciada con 2 unidades, por HTTP. */
function f03ge_venta(Admin $asesor, Customer $cliente): int
{
    $estado = test()->actingAs($asesor, 'admin')
        ->postJson(route('admin.ventas_asesor.nueva_venta.iniciar'), ['customer_id' => $cliente->id, 'canal' => 'whatsapp'])
        ->assertOk()->json();

    test()->actingAs($asesor, 'admin')
        ->postJson(route('admin.ventas_asesor.nueva_venta.tallas', ['borrador' => $estado['borrador']['id']]), [
            'producto_id' => test()->configurable->id, 'cantidades' => [test()->variante->id => 2],
        ])->assertOk();

    return (int) $estado['borrador']['id'];
}

function f03ge_entrega(Admin $asesor, int $borradorId, array $cuerpo)
{
    return test()->actingAs($asesor, 'admin')
        ->postJson(route('admin.ventas_asesor.nueva_venta.entrega', ['borrador' => $borradorId]), $cuerpo);
}

/** customer.address.requirements.postcode del canal (configuracion dinamica de Bagisto). */
function f03ge_postcodeObligatorio(bool $si): void
{
    DB::table('core_config')->updateOrInsert(
        ['code' => 'customer.address.requirements.postcode', 'channel_code' => core()->getDefaultChannel()->code, 'locale_code' => null],
        ['value' => $si ? '1' : '0'],
    );
    Cache::flush();
}

function f03ge_carrito(int $borradorId): object
{
    $cartId = DB::table('bylopez_va_borradores_venta')->where('id', $borradorId)->value('cart_id');

    return (object) [
        'cart' => DB::table('cart')->where('id', $cartId)->first(),
        'envio' => DB::table('addresses')->where('cart_id', $cartId)->where('address_type', 'cart_shipping')->first(),
        'facturacion' => DB::table('addresses')->where('cart_id', $cartId)->where('address_type', 'cart_billing')->first(),
    ];
}

test('A/B/J. Recogida: bodega real + pickup aplicado sin pedir direccion de envio; facturacion = predeterminada real; sin metodos hibridos', function () {
    $asesor = f03ge_asesor();
    $cliente = f03ge_cliente();
    $predeterminada = f03ge_direccion($cliente, 'Calle Facturación 1', true);
    $borradorId = f03ge_venta($asesor, $cliente);

    // Lo unico que manda la pantalla en recogida: tipo + carrier pickup. Sin direccion del cliente.
    $estado = f03ge_entrega($asesor, $borradorId, ['envio' => ['tipo' => 'punto_recogida'], 'metodo' => PuntosRecogida::METODO])
        ->assertOk()->json();

    $bodega = app(PuntosRecogida::class)->disponibles()->first();
    $carrito = f03ge_carrito($borradorId);

    expect($estado['entrega']['envio']['origen'])->toBe('punto_recogida')
        ->and($estado['entrega']['metodo_seleccionado']['codigo'])->toBe(PuntosRecogida::METODO)
        ->and($estado['entrega']['entrega_completa'])->toBeTrue()                      // J: Pago puede aparecer
        // Solo pickup con destino bodega: flatrate/free/etc. no se ofrecen (estado hibrido).
        ->and(collect($estado['entrega']['metodos_disponibles'])->pluck('codigo')->all())->toBe([PuntosRecogida::METODO])
        ->and($carrito->cart->shipping_method)->toBe(PuntosRecogida::METODO)
        ->and($carrito->envio->address)->toBe($bodega->street)
        ->and($carrito->envio->company_name)->toBe($bodega->name)
        // La facturacion es la direccion REAL del cliente, nunca la bodega (D-30).
        ->and((int) $carrito->facturacion->parent_address_id)->toBe($predeterminada)
        ->and($carrito->facturacion->address)->toBe('Calle Facturación 1');

    // Un metodo de domicilio con destino bodega se rechaza (antes Bagisto lo aceptaba).
    f03ge_entrega($asesor, $borradorId, ['envio' => ['tipo' => 'punto_recogida'], 'metodo' => 'flatrate_flatrate'])
        ->assertStatus(422)->assertJsonPath('codigo', 'metodo_entrega_no_disponible');
});

test('C/D. Domicilio: la predeterminada viene marcada; con varias se usa la elegida; la CustomerAddress se copia al CartAddress sin modificarse', function () {
    $asesor = f03ge_asesor();
    $cliente = f03ge_cliente();
    $principal = f03ge_direccion($cliente, 'Calle Principal 10', true);
    $otra = f03ge_direccion($cliente, 'Carrera Otra 20');
    $borradorId = f03ge_venta($asesor, $cliente);

    $direcciones = $this->actingAs($asesor, 'admin')
        ->getJson(route('admin.ventas_asesor.nueva_venta.clientes.direcciones', ['cliente' => $cliente->id]))
        ->assertOk()->json();

    expect(collect($direcciones)->firstWhere('predeterminada', true)['id'])->toBe($principal)
        ->and($direcciones)->toHaveCount(2);

    $antes = DB::table('addresses')->where('id', $otra)->first();

    $estado = f03ge_entrega($asesor, $borradorId, ['envio' => ['tipo' => 'guardada', 'address_id' => $otra]])
        ->assertOk()->json();

    $codigos = collect($estado['entrega']['metodos_disponibles'])->pluck('codigo');

    expect($estado['entrega']['envio']['customerAddressId'])->toBe($otra)
        ->and($estado['entrega']['envio']['origen'])->toBe('guardada')
        ->and($codigos)->not->toContain(PuntosRecogida::METODO)
        ->and($codigos)->toContain('domicilio_local')
        ->and($estado['entrega']['entrega_completa'])->toBeFalse();            // I: falta el metodo → sin Pago

    $estado = f03ge_entrega($asesor, $borradorId, ['envio' => ['tipo' => 'guardada', 'address_id' => $otra], 'metodo' => 'domicilio_local'])
        ->assertOk()->json();

    expect($estado['entrega']['entrega_completa'])->toBeTrue()
        ->and(f03ge_carrito($borradorId)->envio->address)->toBe('Carrera Otra 20')
        ->and((array) DB::table('addresses')->where('id', $otra)->first())->toEqual((array) $antes);
});

test('E/F/L. Cliente sin direccion y sin email: se agrega desde Nueva venta (RT-04), queda predeterminada y se usa; sin codigo postal o ajeno se rechaza', function () {
    $asesor = f03ge_asesor();
    $cliente = f03ge_cliente(['email' => null]);
    $borradorId = f03ge_venta($asesor, $cliente);

    $nueva = ['nombre' => 'Lia', 'apellido' => 'Sincorreo', 'telefono' => '3009998877', 'lineas' => ['Calle Nueva 5'],
        'municipio_codigo' => '11001', 'codigo_postal' => '110111'];

    // Con Bagisto exigiendo codigo postal (customer.address.requirements.postcode = 1), la guardada lo exige.
    f03ge_postcodeObligatorio(true);
    $this->actingAs($asesor, 'admin')
        ->postJson(route('admin.ventas_asesor.nueva_venta.direcciones.agregar', ['borrador' => $borradorId]), ['codigo_postal' => ''] + $nueva)
        ->assertStatus(422)->assertJsonPath('codigo', 'datos_cliente_invalidos')->assertJsonPath('errores.codigo_postal', 'requerido');

    // Otro asesor no puede agregar direcciones al cliente de una venta ajena.
    $this->actingAs(f03ge_asesor(), 'admin')
        ->postJson(route('admin.ventas_asesor.nueva_venta.direcciones.agregar', ['borrador' => $borradorId]), $nueva)
        ->assertStatus(409);

    expect(DB::table('addresses')->where('customer_id', $cliente->id)->where('address_type', 'customer')->count())->toBe(0);

    $r = $this->actingAs($asesor, 'admin')
        ->postJson(route('admin.ventas_asesor.nueva_venta.direcciones.agregar', ['borrador' => $borradorId]), $nueva)
        ->assertOk()->json();

    $guardada = DB::table('addresses')->where('id', $r['direccion_id'])->first();

    expect($guardada->customer_id)->toBe($cliente->id)
        ->and($guardada->address_type)->toBe('customer')
        ->and($guardada->email)->toBeNull()
        ->and((int) $guardada->default_address)->toBe(1)                     // primera → predeterminada
        // D-58: ciudad, estado y pais salen del catalogo (Bogotá, D.C. es BOG, no CUN).
        ->and([$guardada->city, $guardada->state, $guardada->country])->toBe(['Bogotá, D.C.', 'BOG', 'CO'])
        ->and(collect($r['direcciones'])->pluck('id'))->toContain($r['direccion_id']);

    $estado = f03ge_entrega($asesor, $borradorId, [
        'envio' => ['tipo' => 'guardada', 'address_id' => $r['direccion_id']], 'metodo' => 'domicilio_local',
    ])->assertOk()->json();

    expect($estado['entrega']['entrega_completa'])->toBeTrue()
        ->and(DB::table('customers')->where('id', $cliente->id)->value('email'))->toBeNull();
});

test('G/H. Domicilio → Recogida → Domicilio: nunca queda un estado hibrido ni la bodega como destino del domicilio', function () {
    $asesor = f03ge_asesor();
    $cliente = f03ge_cliente();
    $casa = f03ge_direccion($cliente, 'Calle Casa 7', true);
    $borradorId = f03ge_venta($asesor, $cliente);
    $bodega = app(PuntosRecogida::class)->disponibles()->first();

    f03ge_entrega($asesor, $borradorId, ['envio' => ['tipo' => 'guardada', 'address_id' => $casa], 'metodo' => 'domicilio_local'])->assertOk();

    // G: a recogida.
    $estado = f03ge_entrega($asesor, $borradorId, ['envio' => ['tipo' => 'punto_recogida'], 'metodo' => PuntosRecogida::METODO])
        ->assertOk()->json();
    $carrito = f03ge_carrito($borradorId);

    expect($estado['entrega']['envio']['origen'])->toBe('punto_recogida')
        ->and($carrito->cart->shipping_method)->toBe(PuntosRecogida::METODO)
        ->and($carrito->envio->address)->toBe($bodega->street)
        ->and($carrito->envio->parent_address_id)->toBeNull();               // la casa ya no es destino

    // H: de vuelta a domicilio. El pickup no se arrastra; el metodo se vuelve a elegir.
    $estado = f03ge_entrega($asesor, $borradorId, ['envio' => ['tipo' => 'guardada', 'address_id' => $casa]])
        ->assertOk()->json();

    expect($estado['entrega']['envio']['customerAddressId'])->toBe($casa)
        ->and($estado['entrega']['metodo_seleccionado'])->toBeNull()
        ->and($estado['entrega']['entrega_completa'])->toBeFalse()
        ->and(f03ge_carrito($borradorId)->envio->address)->toBe('Calle Casa 7');

    // Un pickup con destino domicilio tambien se rechaza.
    f03ge_entrega($asesor, $borradorId, ['envio' => ['tipo' => 'guardada', 'address_id' => $casa], 'metodo' => PuntosRecogida::METODO])
        ->assertStatus(422)->assertJsonPath('codigo', 'metodo_entrega_no_disponible');
});

test('D-58. Codigo postal segun Bagisto: con requirements.postcode = 0 se guarda sin codigo (NULL); sin guardar = solo CartAddress', function () {
    $asesor = f03ge_asesor();
    $cliente = f03ge_cliente(['email' => null]);
    $borradorId = f03ge_venta($asesor, $cliente);
    f03ge_postcodeObligatorio(false);

    $r = $this->actingAs($asesor, 'admin')
        ->postJson(route('admin.ventas_asesor.nueva_venta.direcciones.agregar', ['borrador' => $borradorId]), [
            'nombre' => 'Sin', 'apellido' => 'Postal', 'telefono' => '3007776655', 'lineas' => ['Carrera 3 # 4-5'],
            'municipio_codigo' => '15542',
        ])->assertOk()->json();

    $guardada = DB::table('addresses')->where('id', $r['direccion_id'])->first();

    expect($guardada->postcode)->toBeNull()
        ->and($guardada->state)->toBe('BOY')                     // codigo de country_states, como Bagisto
        ->and($guardada->email)->toBeNull();

    // Sin guardar en el cliente: la direccion viaja solo al CartAddress; no se crea CustomerAddress.
    $antes = DB::table('addresses')->where('customer_id', $cliente->id)->where('address_type', 'customer')->count();

    f03ge_entrega($asesor, $borradorId, ['envio' => ['tipo' => 'puntual', 'direccion' => [
        'nombre' => 'Solo', 'apellido' => 'Venta', 'lineas' => ['Calle Puntual 1'], 'pais' => 'CO',
        'municipio_codigo' => '15001', 'telefono' => '3001231234',
    ]]])->assertOk();

    expect(DB::table('addresses')->where('customer_id', $cliente->id)->where('address_type', 'customer')->count())->toBe($antes)
        ->and(f03ge_carrito($borradorId)->envio->address)->toBe('Calle Puntual 1')
        ->and(f03ge_carrito($borradorId)->envio->parent_address_id)->toBeNull()
        ->and([f03ge_carrito($borradorId)->envio->city, f03ge_carrito($borradorId)->envio->state, f03ge_carrito($borradorId)->envio->country])->toBe(['Tunja', 'BOY', 'CO']);

    // Con Bagisto EXIGIENDO el codigo postal: la guardada lo exige, la puntual de esta venta no (D-46).
    f03ge_postcodeObligatorio(true);

    $this->actingAs($asesor, 'admin')
        ->postJson(route('admin.ventas_asesor.nueva_venta.direcciones.agregar', ['borrador' => $borradorId]), [
            'nombre' => 'Sin', 'apellido' => 'Postal', 'telefono' => '3007776655', 'lineas' => ['Carrera 3 # 4-5'], 'municipio_codigo' => '15542',
        ])->assertStatus(422)->assertJsonPath('errores.codigo_postal', 'requerido');

    f03ge_entrega($asesor, $borradorId, ['envio' => ['tipo' => 'puntual', 'direccion' => [
        'nombre' => 'Solo', 'apellido' => 'Venta', 'lineas' => ['Calle Puntual 2'], 'pais' => 'CO',
        'municipio_codigo' => '15001', 'telefono' => '3001231234',
    ]]])->assertOk();
});

test('K/M/N. Confirmar domicilio con transferencia: OrderAddress = snapshot del CartAddress, sin transaccion, atribuido al asesor y visible en Mis ventas', function () {
    $asesor = f03ge_asesor();
    $cliente = f03ge_cliente(['email' => null]);
    $borradorId = f03ge_venta($asesor, $cliente);
    f03ge_postcodeObligatorio(false);

    // La direccion se crea como la UI: municipio DIVIPOLA seleccionado + datos de la direccion.
    $casa = $this->actingAs($asesor, 'admin')
        ->postJson(route('admin.ventas_asesor.nueva_venta.direcciones.agregar', ['borrador' => $borradorId]), [
            'nombre' => 'Rita', 'apellido' => 'Destino', 'telefono' => '3001112233', 'lineas' => ['Calle Pedido 99'], 'municipio_codigo' => '15542',
        ])->assertOk()->json('direccion_id');

    f03ge_entrega($asesor, $borradorId, ['envio' => ['tipo' => 'guardada', 'address_id' => $casa], 'metodo' => 'domicilio_local'])->assertOk();

    $estado = $this->actingAs($asesor, 'admin')
        ->postJson(route('admin.ventas_asesor.nueva_venta.pago', ['borrador' => $borradorId]), ['metodo' => 'moneytransfer'])
        ->assertOk()->json();

    expect($estado['confirmable'])->toBeTrue();

    $confirmacion = $this->actingAs($asesor, 'admin')
        ->postJson(route('admin.ventas_asesor.nueva_venta.confirmar', ['borrador' => $borradorId]), ['total_esperado' => $estado['total_esperado']])
        ->assertOk()->json();

    $orderId = $confirmacion['order_id'];
    $envio = DB::table('addresses')->where('order_id', $orderId)->where('address_type', 'order_shipping')->first();

    // M: el OrderAddress es la copia de la direccion de ESTA venta.
    expect($envio->address)->toBe('Calle Pedido 99')
        ->and([$envio->city, $envio->state, $envio->country])->toBe(['Pesca', 'BOY', 'CO'])      // snapshot del catalogo
        ->and(DB::table('orders')->where('id', $orderId)->value('shipping_method'))->toBe('domicilio_local')
        ->and(DB::table('addresses')->where('order_id', $orderId)->where('address_type', 'order_billing')->exists())->toBeTrue();

    // K: la transferencia no registra dinero (sin OrderTransaction).
    expect(DB::table('order_transactions')->where('order_id', $orderId)->count())->toBe(0);

    // N: atribucion al asesor y aparece en SU Mis ventas, con pago distinto de «pagado».
    expect((int) DB::table('bylopez_va_order_attributions')->where('order_id', $orderId)->value('advisor_id'))->toBe((int) $asesor->id);

    $fila = $this->actingAs($asesor, 'admin')->get(route('admin.ventas_asesor.pedidos.index'))
        ->assertOk()->viewData('pedidos')->getCollection()->firstWhere('id', $orderId);

    expect($fila)->not->toBeNull()
        ->and($fila->estado_pago)->not->toBe('pagado')
        ->and($fila->estado_despacho)->toBe('pendiente');

    // Cierre 03G: el borrador confirmado con Order NO vuelve a ser editable ni duplica el pedido (D-55).
    foreach ([
        fn () => f03ge_entrega($asesor, $borradorId, ['envio' => ['tipo' => 'guardada', 'address_id' => $casa], 'metodo' => 'domicilio_local']),
        fn () => $this->actingAs($asesor, 'admin')->postJson(route('admin.ventas_asesor.nueva_venta.pago', ['borrador' => $borradorId]), ['metodo' => 'cashondelivery']),
        fn () => f03ge_agregarDireccion($asesor, $borradorId, f03ge_cuerpoDireccion()),
    ] as $edicion) {
        $edicion()->assertStatus(409)->assertJsonPath('codigo', 'borrador_no_editable');
    }

    $pedidosDespues = DB::table('orders')->count();

    $repetida = $this->actingAs($asesor, 'admin')
        ->postJson(route('admin.ventas_asesor.nueva_venta.confirmar', ['borrador' => $borradorId]), ['total_esperado' => $estado['total_esperado']])
        ->assertOk()->json();

    $lectura = $this->actingAs($asesor, 'admin')
        ->getJson(route('admin.ventas_asesor.nueva_venta.estado', ['borrador' => $borradorId]))
        ->assertOk()->json();

    expect($repetida['order_id'])->toBe($orderId)->and($repetida['ya_existia'])->toBeTrue()
        ->and(DB::table('orders')->count())->toBe($pedidosDespues)
        ->and($lectura['borrador']['estado'])->toBe('confirmado')
        ->and($lectura['borrador']['orderId'])->toBe($orderId);
});

test('pantalla: Entrega con opciones de radio, Direccion y Pago condicionados a la entrega aplicada', function () {
    $this->actingAs(f03ge_asesor(), 'admin')
        ->get(route('admin.ventas_asesor.nueva_venta.pantalla'))
        ->assertOk()
        ->assertSee('id="seccion-direccion"', false)
        ->assertSee('v-if="estado && entregaLista"', false)          // I/J: Pago solo con entrega lista
        ->assertSee('Envío a domicilio')
        ->assertSee('Este cliente no tiene una dirección de entrega.')
        ->assertSee('+ Agregar dirección')
        ->assertSee('ref="modalDireccion"', false)                  // formulario en modal, no inline
        ->assertDontSee('seccion-form-direccion')
        // D-58: UN autocomplete «Departamento — Municipio»; sin segundo selector de departamento en la modal
        ->assertSee('Ciudad / municipio *', false)
        ->assertSee('@input="alEscribirUbicacion"', false)
        ->assertSee('Selecciona una ciudad o municipio de la lista.')
        ->assertDontSee('Departamento *')
        // Cierre 03G: un grupo nativo por seleccion (exclusividad), recreado desde el estado real, y aviso del borrador
        ->assertSee('name="tipo-entrega"', false)
        ->assertSee('name="direccion-entrega"', false)
        ->assertSee('name="metodo-envio"', false)
        ->assertSee('name="metodo-pago"', false)
        ->assertSee('versionVista', false)
        ->assertSee('data-banner-borrador', false)
        ->assertSee('Reintentar confirmación')
        ->assertDontSee('formDireccion.datos.departamento')
        ->assertSee(core()->getConfigData('sales.carriers.pickup.title') ?: 'Recogida en tienda')
        ->assertDontSee('Recogida en bodega')
        // departamentos por CODIGO de country_states (lo que Bagisto guarda en addresses.state)
        ->assertViewHas('departamentos', fn ($d) => collect($d)->contains(fn ($e) => $e['codigo'] === 'BOY' && $e['nombre'] === 'Boyacá'));
});

/* ---------------------------------------------------------------- D-58: seleccion estructurada */

/** Cuerpo de «agregar direccion» como lo manda la UI. */
function f03ge_cuerpoDireccion(array $extra = []): array
{
    return array_merge([
        'nombre' => 'Lia', 'apellido' => 'Estructurada', 'telefono' => '3009998877', 'lineas' => ['Calle Catalogo 1'],
        'municipio_codigo' => '15542',
    ], $extra);
}

function f03ge_agregarDireccion(Admin $asesor, int $borradorId, array $cuerpo)
{
    return test()->actingAs($asesor, 'admin')
        ->postJson(route('admin.ventas_asesor.nueva_venta.direcciones.agregar', ['borrador' => $borradorId]), $cuerpo);
}

function f03ge_cuantasDelCliente(int $customerId): int
{
    return DB::table('addresses')->where('customer_id', $customerId)->where('address_type', 'customer')->count();
}

test('G. seleccionar «Boyacá — Pesca»: el backend persiste country = CO, state = BOY y city = Pesca desde el catalogo', function () {
    $asesor = f03ge_asesor();
    $cliente = f03ge_cliente(['email' => null]);
    $borradorId = f03ge_venta($asesor, $cliente);
    f03ge_postcodeObligatorio(false);

    $r = f03ge_agregarDireccion($asesor, $borradorId, f03ge_cuerpoDireccion())->assertOk()->json();
    $guardada = DB::table('addresses')->where('id', $r['direccion_id'])->first();

    expect([$guardada->city, $guardada->state, $guardada->country])->toBe(['Pesca', 'BOY', 'CO'])
        ->and($guardada->email)->toBeNull()                                  // email opcional (RT-04)
        ->and($guardada->postcode)->toBeNull()
        ->and($guardada->address_type)->toBe('customer');
});

test('H. manipulacion: con un codigo valido, ciudad/departamento/pais falsificados se ignoran (CustomerAddress y CartAddress)', function () {
    $asesor = f03ge_asesor();
    $cliente = f03ge_cliente();
    $borradorId = f03ge_venta($asesor, $cliente);
    f03ge_postcodeObligatorio(false);

    // Guardada: el navegador manda Medellin / ANT / US, pero el codigo es el de Pesca.
    $r = f03ge_agregarDireccion($asesor, $borradorId, f03ge_cuerpoDireccion(['ciudad' => 'Medellín', 'departamento' => 'ANT', 'pais' => 'US', 'state' => 'ANT', 'city' => 'Medellín']))
        ->assertOk()->json();
    $guardada = DB::table('addresses')->where('id', $r['direccion_id'])->first();

    expect([$guardada->city, $guardada->state, $guardada->country])->toBe(['Pesca', 'BOY', 'CO']);

    // Puntual (sin guardar): mismo comportamiento en el CartAddress.
    f03ge_entrega($asesor, $borradorId, ['envio' => ['tipo' => 'puntual', 'direccion' => [
        'nombre' => 'Solo', 'apellido' => 'Venta', 'lineas' => ['Calle Falsa 1'], 'pais' => 'CO', 'telefono' => '3001231234',
        'municipio_codigo' => '15001', 'ciudad' => 'Medellín', 'departamento' => 'ANT',
    ]]])->assertOk();

    $envio = f03ge_carrito($borradorId)->envio;

    expect([$envio->city, $envio->state, $envio->country])->toBe(['Tunja', 'BOY', 'CO']);
});

test('I. texto parcial sin seleccionar («Pes» sin municipio_codigo): 422 y nada se guarda ni se usa en la venta', function () {
    $asesor = f03ge_asesor();
    $cliente = f03ge_cliente();
    $borradorId = f03ge_venta($asesor, $cliente);
    $antes = f03ge_cuantasDelCliente($cliente->id);

    $sinCodigo = f03ge_cuerpoDireccion(['ciudad' => 'Pes', 'departamento' => 'BOY']);
    unset($sinCodigo['municipio_codigo']);

    f03ge_agregarDireccion($asesor, $borradorId, $sinCodigo)
        ->assertStatus(422)->assertJsonPath('errors.municipio_codigo.0', 'Selecciona una ciudad o municipio de la lista.');

    // Un codigo vacio, con texto o con menos digitos tampoco vale.
    foreach (['', 'Pes', '1554'] as $malo) {
        f03ge_agregarDireccion($asesor, $borradorId, f03ge_cuerpoDireccion(['municipio_codigo' => $malo, 'ciudad' => 'Pes']))
            ->assertStatus(422)->assertJsonPath('errors.municipio_codigo.0', 'Selecciona una ciudad o municipio de la lista.');
    }

    // Direccion puntual de la venta, con «Pes» como ciudad y sin codigo.
    $respuesta = f03ge_entrega($asesor, $borradorId, ['envio' => ['tipo' => 'puntual', 'direccion' => [
        'nombre' => 'Solo', 'apellido' => 'Venta', 'lineas' => ['Calle Falsa 1'], 'pais' => 'CO', 'telefono' => '3001231234',
        'ciudad' => 'Pes', 'departamento' => 'BOY',
    ]]])->assertStatus(422);

    expect($respuesta->json('errors')['envio.direccion.municipio_codigo'][0])->toBe('Selecciona una ciudad o municipio de la lista.')
        ->and(f03ge_cuantasDelCliente($cliente->id))->toBe($antes)
        ->and(f03ge_carrito($borradorId)->envio)->toBeNull();
});

test('J/K. municipio inexistente o inactivo: rechazo con datos_cliente_invalidos y sin escrituras', function () {
    $asesor = f03ge_asesor();
    $cliente = f03ge_cliente();
    f03ge_direccion($cliente, 'Calle Base 1', true);                 // facturacion real resoluble (D-30)
    $borradorId = f03ge_venta($asesor, $cliente);
    $antes = f03ge_cuantasDelCliente($cliente->id);

    // J: codigo con forma valida pero que no existe.
    f03ge_agregarDireccion($asesor, $borradorId, f03ge_cuerpoDireccion(['municipio_codigo' => '99999']))
        ->assertStatus(422)->assertJsonPath('codigo', 'datos_cliente_invalidos')->assertJsonPath('errores.municipio_codigo', 'formato_invalido');

    f03ge_entrega($asesor, $borradorId, ['envio' => ['tipo' => 'puntual', 'direccion' => [
        'nombre' => 'Solo', 'apellido' => 'Venta', 'lineas' => ['Calle Falsa 1'], 'pais' => 'CO', 'telefono' => '3001231234', 'municipio_codigo' => '99999',
    ]]])->assertStatus(422)->assertJsonPath('codigo', 'datos_cliente_invalidos');

    // K: municipio dado de baja (activo = 0) en una importacion posterior.
    DB::table('bylopez_geo_municipios')->where('codigo_divipola', '15542')->update(['activo' => false]);

    f03ge_agregarDireccion($asesor, $borradorId, f03ge_cuerpoDireccion())
        ->assertStatus(422)->assertJsonPath('errores.municipio_codigo', 'formato_invalido');

    expect(f03ge_cuantasDelCliente($cliente->id))->toBe($antes)
        ->and(f03ge_carrito($borradorId)->envio)->toBeNull();
});

test('L/M/N. guardar en el cliente = CustomerAddress; sin guardar = solo CartAddress; una CustomerAddress existente no se modifica al usarla', function () {
    $asesor = f03ge_asesor();
    $cliente = f03ge_cliente(['email' => null]);
    $existente = f03ge_direccion($cliente, 'Calle Historica 3', true);        // texto libre, anterior a D-58
    $borradorId = f03ge_venta($asesor, $cliente);
    f03ge_postcodeObligatorio(false);

    $antes = (array) DB::table('addresses')->where('id', $existente)->first();

    // Usar la historica en la venta no la toca.
    f03ge_entrega($asesor, $borradorId, ['envio' => ['tipo' => 'guardada', 'address_id' => $existente], 'metodo' => 'domicilio_local'])->assertOk();
    expect((array) DB::table('addresses')->where('id', $existente)->first())->toEqual($antes);

    // M: guardar = una CustomerAddress NUEVA (RT-04); la historica sigue igual y conserva su predeterminada.
    $r = f03ge_agregarDireccion($asesor, $borradorId, f03ge_cuerpoDireccion())->assertOk()->json();

    expect(f03ge_cuantasDelCliente($cliente->id))->toBe(2)
        ->and((int) DB::table('addresses')->where('id', $r['direccion_id'])->value('default_address'))->toBe(0)
        ->and((array) DB::table('addresses')->where('id', $existente)->first())->toEqual($antes);

    // N: sin guardar → ninguna CustomerAddress nueva; solo el CartAddress con los valores del catalogo.
    f03ge_entrega($asesor, $borradorId, ['envio' => ['tipo' => 'puntual', 'direccion' => [
        'nombre' => 'Solo', 'apellido' => 'Venta', 'lineas' => ['Calle Puntual 9'], 'pais' => 'CO', 'telefono' => '3001231234', 'municipio_codigo' => '25175',
    ]], 'metodo' => 'domicilio_local'])->assertOk();

    $envio = f03ge_carrito($borradorId)->envio;

    expect(f03ge_cuantasDelCliente($cliente->id))->toBe(2)
        ->and([$envio->city, $envio->state, $envio->country, $envio->parent_address_id])->toBe(['Chía', 'CUN', 'CO', null]);
});

/* ---------------------------------------------------------------- cierre 03G: reconciliacion y estados del borrador */

test('cierre 03G · cambio de direccion A → B: el destino real es B, las tarifas son las de B y el metodo anterior hay que volver a elegirlo', function () {
    $asesor = f03ge_asesor();
    $cliente = f03ge_cliente();
    $a = f03ge_direccion($cliente, 'Calle A 1', true);
    $b = f03ge_direccion($cliente, 'Carrera B 2');
    $borradorId = f03ge_venta($asesor, $cliente);

    $estado = f03ge_entrega($asesor, $borradorId, ['envio' => ['tipo' => 'guardada', 'address_id' => $a], 'metodo' => 'domicilio_local'])->assertOk()->json();

    expect($estado['entrega']['entrega_completa'])->toBeTrue()
        ->and(f03ge_carrito($borradorId)->cart->shipping_method)->toBe('domicilio_local');

    // Se cambia SOLO la direccion: no se manda metodo.
    $estado = f03ge_entrega($asesor, $borradorId, ['envio' => ['tipo' => 'guardada', 'address_id' => $b]])->assertOk()->json();
    $carrito = f03ge_carrito($borradorId);

    expect($estado['entrega']['envio']['customerAddressId'])->toBe($b)
        ->and($carrito->envio->address)->toBe('Carrera B 2')
        ->and($carrito->cart->shipping_method)->toBeNull()                       // no queda el metodo de la direccion A
        ->and($estado['entrega']['metodo_seleccionado'])->toBeNull()
        ->and($estado['entrega']['entrega_completa'])->toBeFalse()
        ->and(collect($estado['entrega']['metodos_disponibles'])->pluck('codigo'))->toContain('domicilio_local');

    // Las tarifas guardadas pertenecen al destino NUEVO; ninguna cuelga de la direccion anterior.
    $direccionesDelCarrito = DB::table('addresses')->where('cart_id', $carrito->cart->id)->pluck('id');

    expect(DB::table('cart_shipping_rates')->where('cart_address_id', $carrito->envio->id)->count())->toBeGreaterThan(0)
        ->and(DB::table('cart_shipping_rates')->whereIn('cart_address_id', $direccionesDelCarrito)->where('cart_address_id', '!=', $carrito->envio->id)->count())->toBe(0);

    // Con B y su metodo, la entrega vuelve a estar completa.
    f03ge_entrega($asesor, $borradorId, ['envio' => ['tipo' => 'guardada', 'address_id' => $b], 'metodo' => 'domicilio_local'])
        ->assertOk()->assertJsonPath('entrega.entrega_completa', true);
});

test('cierre 03G · cambio de pago contraentrega ↔ transferencia: solo uno queda aplicado, siempre el ultimo (03E)', function () {
    $asesor = f03ge_asesor();
    $cliente = f03ge_cliente();
    $casa = f03ge_direccion($cliente, 'Calle Pago 1', true);
    $borradorId = f03ge_venta($asesor, $cliente);

    f03ge_entrega($asesor, $borradorId, ['envio' => ['tipo' => 'guardada', 'address_id' => $casa], 'metodo' => 'domicilio_local'])->assertOk();

    foreach (['cashondelivery', 'moneytransfer', 'cashondelivery', 'moneytransfer'] as $metodo) {
        $estado = $this->actingAs($asesor, 'admin')
            ->postJson(route('admin.ventas_asesor.nueva_venta.pago', ['borrador' => $borradorId]), ['metodo' => $metodo])
            ->assertOk()->json();

        $cartId = f03ge_carrito($borradorId)->cart->id;

        expect($estado['pago']['metodo_seleccionado']['codigo'])->toBe($metodo)
            ->and(DB::table('cart_payment')->where('cart_id', $cartId)->count())->toBe(1)
            ->and(DB::table('cart_payment')->where('cart_id', $cartId)->value('method'))->toBe($metodo);
    }
});

test('cierre 03G · borrador confirmando: ninguna edicion pasa (409 borrador_no_editable), el estado se lee y confirmar respeta el lease (D-55)', function () {
    $asesor = f03ge_asesor();
    $cliente = f03ge_cliente();
    $casa = f03ge_direccion($cliente, 'Calle Lease 1', true);                  // facturacion real resoluble
    $borradorId = f03ge_venta($asesor, $cliente);
    $pedidosAntes = DB::table('orders')->count();

    DB::table('bylopez_va_borradores_venta')->where('id', $borradorId)->update([
        'estado' => 'confirmando', 'confirmation_token' => (string) Str::uuid(), 'confirmacion_iniciada_en' => now(),
    ]);

    $edicionesRechazadas = [
        fn () => f03ge_entrega($asesor, $borradorId, ['envio' => ['tipo' => 'guardada', 'address_id' => $casa], 'metodo' => 'domicilio_local']),
        fn () => $this->actingAs($asesor, 'admin')->postJson(route('admin.ventas_asesor.nueva_venta.pago', ['borrador' => $borradorId]), ['metodo' => 'cashondelivery']),
        fn () => f03ge_agregarDireccion($asesor, $borradorId, f03ge_cuerpoDireccion()),
        fn () => $this->actingAs($asesor, 'admin')->postJson(route('admin.ventas_asesor.nueva_venta.tallas', ['borrador' => $borradorId]), ['producto_id' => $this->configurable->id, 'cantidades' => [$this->variante->id => 3]]),
    ];

    foreach ($edicionesRechazadas as $edicion) {
        $r = $edicion()->assertStatus(409);

        expect($r->json('causa') ?? $r->json('codigo'))->toBe('borrador_no_editable');   // tallas: lote_rechazado + causa
    }

    // Lease vigente: confirmar de nuevo NO crea nada; informa que sigue en proceso.
    $this->actingAs($asesor, 'admin')
        ->postJson(route('admin.ventas_asesor.nueva_venta.confirmar', ['borrador' => $borradorId]), ['total_esperado' => 1])
        ->assertStatus(409)->assertJsonPath('codigo', 'confirmacion_en_proceso');

    // La pantalla puede leer el estado real para mostrar el aviso correcto.
    $estado = $this->actingAs($asesor, 'admin')
        ->getJson(route('admin.ventas_asesor.nueva_venta.estado', ['borrador' => $borradorId]))
        ->assertOk()->json();

    expect($estado['borrador']['estado'])->toBe('confirmando')
        ->and($estado['confirmable'])->toBeFalse()
        ->and($estado['motivos_no_confirmable'])->toContain('borrador_no_editable')
        ->and(DB::table('orders')->count())->toBe($pedidosAntes);
});
