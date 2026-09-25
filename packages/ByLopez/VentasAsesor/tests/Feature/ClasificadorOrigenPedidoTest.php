<?php

use ByLopez\VentasAsesor\Enums\CanalVenta;
use ByLopez\VentasAsesor\Enums\EstadoAtribucion;
use ByLopez\VentasAsesor\Enums\MecanismoCreacion;
use ByLopez\VentasAsesor\Enums\OrigenPropiedadComercial;
use ByLopez\VentasAsesor\Enums\TipoPropiedadComercial;
use ByLopez\VentasAsesor\Services\ResolverAtribucionService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use ByLopez\VentasAsesor\Support\ClasificadorOrigenPedido;
use ByLopez\VentasAsesor\Support\ContextoComercialDePeticion;
use ByLopez\VentasAsesor\Support\RutasDeCreacionDePedido;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Tests\TestCase;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Clasificacion cerrada del origen del pedido (Fase 02B1A)
|--------------------------------------------------------------------------
|
| Los administradores existen en lpz_saas_testing porque la atribucion necesita
| su id real; DatabaseTransactions revierte todo al terminar.
|
*/

function va33_admin(array|string|null $permisos, string $tipo = 'custom', string $nombre = 'VA33 Admin'): Admin
{
    $rol = Role::factory()->create([
        'name' => $nombre.' rol '.uniqid(),
        'permission_type' => $tipo,
        'permissions' => $permisos,
    ]);

    return Admin::factory()->create(['role_id' => $rol->id, 'name' => $nombre]);
}

function va33_asesor(): Admin
{
    return va33_admin([
        AutorizacionVentasAsesor::PERMISO_MODULO,
        AutorizacionVentasAsesor::PERMISO_OPERAR_COMO_ASESOR,
    ], 'custom', 'VA33 Asesora');
}

/** Peticion con el NOMBRE exacto de ruta que se quiere probar. */
function va33_peticion(?string $ruta): Request
{
    $peticion = Request::create('/va33-ruta', 'POST');

    if ($ruta !== null) {
        $rutaLaravel = new Route(['POST'], '/va33-ruta', ['as' => $ruta]);
        $rutaLaravel->bind($peticion);

        $peticion->setRouteResolver(fn () => $rutaLaravel);
    }

    return $peticion;
}

function va33_clasificador(?string $ruta, ?ContextoComercialDePeticion $transporte = null): ClasificadorOrigenPedido
{
    return new ClasificadorOrigenPedido(
        app(ResolverAtribucionService::class),
        $transporte ?? new ContextoComercialDePeticion,
        app('auth'),
        va33_peticion($ruta),
    );
}

function va33_autenticar(?Admin $admin): void
{
    if ($admin === null) {
        return;
    }

    auth()->guard('admin')->setUser($admin);
}

test('8. el contexto explicito del carrito tiene prioridad sobre la ruta', function () {
    $asesor = va33_asesor();
    $transporte = new ContextoComercialDePeticion;

    $explicito = app(ResolverAtribucionService::class)->paraCreacionAdministrativa(
        creador: $asesor,
        mecanismo: MecanismoCreacion::VENTAS_ASESOR,
        canal: CanalVenta::WHATSAPP,
        cartId: 7001,
    );

    $transporte->registrar(7001, $explicito);

    // La ruta dice "tienda"; el contexto explicito dice otra cosa y manda el contexto.
    $contexto = va33_clasificador('shop.checkout.onepage.orders.store', $transporte)->clasificar(7001);

    expect($contexto)->toBe($explicito)
        ->and($contexto->propiedad)->toBe(TipoPropiedadComercial::ASESOR)
        ->and($contexto->advisorId)->toBe((int) $asesor->id)
        ->and($contexto->mecanismo)->toBe(MecanismoCreacion::VENTAS_ASESOR)
        // Leerlo no lo consumio.
        ->and($transporte->para(7001))->toBe($explicito);
});

test('9. la ruta administrativa exacta produce admin_bagisto con canal desconocido', function () {
    $admin = va33_admin(['sales'], 'custom', 'VA33 Vendedor');
    va33_autenticar($admin);

    $contexto = va33_clasificador('admin.sales.orders.store')->clasificar(7100);

    expect($contexto->mecanismo)->toBe(MecanismoCreacion::ADMIN_BAGISTO)
        // No se afirma WhatsApp sin evidencia.
        ->and($contexto->canal)->toBe(CanalVenta::DESCONOCIDO)
        ->and($contexto->createdByAdminId)->toBe((int) $admin->id)
        ->and($contexto->cartId)->toBe(7100);
});

test('10. un administrador asesor custom recibe la venta automaticamente', function () {
    $asesor = va33_asesor();
    va33_autenticar($asesor);

    $contexto = va33_clasificador('admin.sales.orders.store')->clasificar(7101);

    expect($contexto->propiedad)->toBe(TipoPropiedadComercial::ASESOR)
        ->and($contexto->advisorId)->toBe((int) $asesor->id)
        ->and($contexto->origenPropiedad)->toBe(OrigenPropiedadComercial::AUTO_ASESOR)
        ->and($contexto->estado())->toBe(EstadoAtribucion::COMPLETA);
});

test('11. un administrador con permission_type all no se autoasigna la venta', function () {
    $superAdmin = va33_admin(null, 'all', 'VA33 Super');
    va33_autenticar($superAdmin);

    $contexto = va33_clasificador('admin.sales.orders.store')->clasificar(7102);

    expect($contexto->propiedad)->toBe(TipoPropiedadComercial::SIN_ASIGNAR)
        ->and($contexto->advisorId)->toBeNull()
        ->and($contexto->createdByAdminId)->toBe((int) $superAdmin->id)
        ->and($contexto->estado())->toBe(EstadoAtribucion::SIN_ASESOR);
});

test('12. la ruta administrativa sin administrador autenticado es fallo tecnico, nunca tienda', function () {
    $contexto = va33_clasificador('admin.sales.orders.store')->clasificar(7103);

    expect($contexto->esFalloTecnico())->toBeTrue()
        ->and($contexto->codigoTecnico)->toBe(ClasificadorOrigenPedido::CODIGO_ADMIN_SIN_AUTENTICAR)
        ->and($contexto->propiedad)->toBe(TipoPropiedadComercial::SIN_ASIGNAR)
        ->and($contexto->advisorId)->toBeNull()
        // Lo importante: NO degrada a storefront.
        ->and($contexto->mecanismo)->not->toBe(MecanismoCreacion::STOREFRONT);
});

test('13. la ruta exacta del checkout produce venta de la empresa', function () {
    $contexto = va33_clasificador('shop.checkout.onepage.orders.store')->clasificar(7200);

    expect($contexto->propiedad)->toBe(TipoPropiedadComercial::EMPRESA)
        ->and($contexto->advisorId)->toBeNull()
        ->and($contexto->canal)->toBe(CanalVenta::ECOMMERCE)
        ->and($contexto->mecanismo)->toBe(MecanismoCreacion::STOREFRONT)
        ->and($contexto->estado())->toBe(EstadoAtribucion::COMPLETA)
        ->and($contexto->createdByAdminId)->toBeNull()
        ->and($contexto->cartId)->toBe(7200);
});

test('14. las ocho rutas de tienda y pasarela aprobadas producen la misma atribucion', function () {
    // Fuente unica: si alguien agrega una ruta al codigo, esta prueba la cubre sola.
    expect(RutasDeCreacionDePedido::TIENDA)->toBe([
        'shop.checkout.onepage.orders.store',
        'stripe.payment.success',
        'payu.success',
        'razorpay.payment.success',
        'paypal.standard.success',
        'paypal.smart-button.capture-order',
        'phonepe.callback',
        'phonepe.webhook',
    ])->and(RutasDeCreacionDePedido::TIENDA)->toHaveCount(8);

    foreach (RutasDeCreacionDePedido::TIENDA as $indice => $ruta) {
        $contexto = va33_clasificador($ruta)->clasificar(7300 + $indice);

        expect($contexto->propiedad)->toBe(TipoPropiedadComercial::EMPRESA, $ruta)
            ->and($contexto->advisorId)->toBeNull($ruta)
            ->and($contexto->canal)->toBe(CanalVenta::ECOMMERCE, $ruta)
            ->and($contexto->mecanismo)->toBe(MecanismoCreacion::STOREFRONT, $ruta)
            ->and($contexto->estado())->toBe(EstadoAtribucion::COMPLETA, $ruta);
    }
});

test('15. un guard administrativo activo no cambia la propiedad de un pedido de tienda', function () {
    // Panel y tienda comparten la sesion de Laravel: tener sesion de admin no prueba nada.
    va33_autenticar(va33_asesor());

    $contexto = va33_clasificador('shop.checkout.onepage.orders.store')->clasificar(7400);

    expect($contexto->propiedad)->toBe(TipoPropiedadComercial::EMPRESA)
        ->and($contexto->advisorId)->toBeNull()
        ->and($contexto->createdByAdminId)->toBeNull()
        ->and($contexto->mecanismo)->toBe(MecanismoCreacion::STOREFRONT);
});

test('16. una ruta desconocida produce fallo tecnico, no storefront por descarte', function () {
    foreach (['admin.sales.orders.store.otra', 'shop.checkout.cart.index', 'pos.ventas.store', 'api.orders.store'] as $ruta) {
        $contexto = va33_clasificador($ruta)->clasificar(7500);

        expect($contexto->esFalloTecnico())->toBeTrue($ruta)
            ->and($contexto->codigoTecnico)->toBe(ClasificadorOrigenPedido::CODIGO_ORIGEN_NO_DETERMINADO, $ruta)
            ->and($contexto->propiedad)->toBe(TipoPropiedadComercial::SIN_ASIGNAR, $ruta)
            ->and($contexto->mecanismo)->toBe(MecanismoCreacion::DESCONOCIDO, $ruta);
    }

    // La comparacion es exacta: ni prefijo de admin ni prefijo de shop clasifican.
    expect(RutasDeCreacionDePedido::esAdministrativa('admin.sales.orders.store.otra'))->toBeFalse()
        ->and(RutasDeCreacionDePedido::esDeTienda('shop.checkout.cart.index'))->toBeFalse()
        ->and(RutasDeCreacionDePedido::todas())->toHaveCount(9);
});

test('17. la ausencia de ruta produce fallo tecnico', function () {
    $contexto = va33_clasificador(null)->clasificar(7600);

    expect($contexto->esFalloTecnico())->toBeTrue()
        ->and($contexto->codigoTecnico)->toBe(ClasificadorOrigenPedido::CODIGO_ORIGEN_NO_DETERMINADO)
        ->and(va33_clasificador(null)->rutaActual())->toBeNull();
});

test('18. la ausencia de cart_id se maneja sin inferencias inseguras', function () {
    $transporte = new ContextoComercialDePeticion;

    // Hay un contexto vivo de OTRO carrito: no puede usarse por coincidencia temporal.
    $transporte->registrar(7700, app(ResolverAtribucionService::class)->paraCreacionAdministrativa(
        creador: va33_asesor(),
        mecanismo: MecanismoCreacion::VENTAS_ASESOR,
        canal: CanalVenta::WHATSAPP,
        cartId: 7700,
    ));

    $tienda = va33_clasificador('shop.checkout.onepage.orders.store', $transporte)->clasificar(null);

    expect($tienda->esFalloTecnico())->toBeTrue()
        ->and($tienda->codigoTecnico)->toBe(ClasificadorOrigenPedido::CODIGO_CART_ID_AUSENTE)
        ->and($tienda->advisorId)->toBeNull()
        ->and($tienda->propiedad)->toBe(TipoPropiedadComercial::SIN_ASIGNAR);

    /*
     * La creacion administrativa SI puede atribuirse sin cart_id: la evidencia es el
     * administrador autenticado en una ruta que solo alcanza el panel, no la
     * correlacion con el carrito. Se pierde la trazabilidad al carrito, que se
     * documenta como limitacion conocida.
     */
    $asesor = va33_asesor();
    va33_autenticar($asesor);

    $admin = va33_clasificador('admin.sales.orders.store', $transporte)->clasificar(null);

    expect($admin->esFalloTecnico())->toBeFalse()
        ->and($admin->propiedad)->toBe(TipoPropiedadComercial::ASESOR)
        ->and($admin->advisorId)->toBe((int) $asesor->id)
        ->and($admin->cartId)->toBeNull();
});
