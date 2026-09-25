<?php

use ByLopez\VentasAsesor\DataObjects\ContextoComercial;
use ByLopez\VentasAsesor\Enums\CanalVenta;
use ByLopez\VentasAsesor\Enums\MecanismoCreacion;
use ByLopez\VentasAsesor\Enums\OrigenPropiedadComercial;
use ByLopez\VentasAsesor\Enums\TipoPropiedadComercial;
use ByLopez\VentasAsesor\Exceptions\ContextoDePeticionInvalidoException;
use ByLopez\VentasAsesor\Services\ResolverAtribucionService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use ByLopez\VentasAsesor\Support\ContextoComercialDePeticion;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Contexto comercial acotado a la peticion (Fase 02B1A)
|--------------------------------------------------------------------------
|
| Sin base de datos: el transporte del contexto es memoria de la peticion. La
| numeracion sigue la lista de pruebas obligatorias del encargo 02B1A.
|
*/

function va31_contexto(int $cartId): ContextoComercial
{
    return (new ResolverAtribucionService(new AutorizacionVentasAsesor))
        ->paraStorefront(CanalVenta::ECOMMERCE, $cartId);
}

function va31_transporte(): ContextoComercialDePeticion
{
    return new ContextoComercialDePeticion;
}

test('2. consultar el contexto no lo consume', function () {
    $transporte = va31_transporte();
    $transporte->registrar(501, va31_contexto(501));

    $primera = $transporte->para(501);
    $segunda = $transporte->para(501);
    $tercera = $transporte->para(501);

    expect($primera)->not->toBeNull()
        ->and($segunda)->toBe($primera)
        ->and($tercera)->toBe($primera)
        ->and($transporte->tieneContextoPara(501))->toBeTrue();
});

test('3. los contextos de carritos distintos no se pisan', function () {
    $transporte = va31_transporte();

    $transporte->registrar(601, va31_contexto(601));
    $transporte->registrar(602, va31_contexto(602));

    expect($transporte->para(601)->cartId)->toBe(601)
        ->and($transporte->para(602)->cartId)->toBe(602)
        ->and($transporte->cartsRegistrados())->toEqualCanonicalizing([601, 602]);

    // Limpiar uno no toca al otro.
    $transporte->olvidar(601);

    expect($transporte->para(601))->toBeNull()
        ->and($transporte->para(602))->not->toBeNull();
});

test('4. durante() retira el contexto despues de una operacion exitosa', function () {
    $transporte = va31_transporte();

    $resultado = $transporte->durante(701, va31_contexto(701), function () use ($transporte) {
        expect($transporte->para(701))->not->toBeNull();

        return 'pedido-creado';
    });

    expect($resultado)->toBe('pedido-creado')
        ->and($transporte->para(701))->toBeNull()
        ->and($transporte->cartsRegistrados())->toBe([]);
});

test('5. durante() retira el contexto tambien cuando la operacion lanza', function () {
    $transporte = va31_transporte();

    $lanzar = fn () => $transporte->durante(702, va31_contexto(702), function () {
        throw new RuntimeException('el pedido fallo');
    });

    expect($lanzar)->toThrow(RuntimeException::class, 'el pedido fallo')
        ->and($transporte->para(702))->toBeNull()
        ->and($transporte->cartsRegistrados())->toBe([]);
});

test('6. los reintentos internos del repositorio leen el mismo contexto', function () {
    $transporte = va31_transporte();

    $leidos = [];
    $ordenIds = [];
    $intentos = 0;

    /*
     * Imita createOrderIfNotThenRetry(): la recursion reintenta con un order_id
     * NUEVO cada vez, mientras el carrito es el mismo. El contexto tiene que
     * sobrevivir a los tres intentos.
     */
    $crearConReintentos = function () use (&$crearConReintentos, &$intentos, &$leidos, &$ordenIds, $transporte) {
        $intentos++;
        $leidos[] = $transporte->para(801);
        $ordenIds[] = 900 + $intentos;

        if ($intentos < 3) {
            return $crearConReintentos();
        }

        return $ordenIds[array_key_last($ordenIds)];
    };

    $pedidoFinal = $transporte->durante(801, va31_contexto(801), $crearConReintentos);

    expect($intentos)->toBe(3)
        ->and($pedidoFinal)->toBe(903)
        ->and($ordenIds)->toBe([901, 902, 903])
        ->and($leidos[0])->not->toBeNull()
        ->and($leidos[1])->toBe($leidos[0])
        ->and($leidos[2])->toBe($leidos[0])
        // Ningun order_id temporal quedo grabado en la clave del contexto.
        ->and($transporte->cartsRegistrados())->toBe([]);
});

test('la clave y el cart_id del contexto deben coincidir', function () {
    $transporte = va31_transporte();

    expect(fn () => $transporte->registrar(11, va31_contexto(22)))
        ->toThrow(ContextoDePeticionInvalidoException::class);

    expect(fn () => $transporte->registrar(11, (new ResolverAtribucionService(new AutorizacionVentasAsesor))->paraStorefront()))
        ->toThrow(ContextoDePeticionInvalidoException::class);

    expect($transporte->cartsRegistrados())->toBe([]);
});

test('no se reemplaza en silencio un contexto por otro incompatible', function () {
    $transporte = va31_transporte();
    $transporte->registrar(303, va31_contexto(303));

    $incompatible = new ContextoComercial(
        propiedad: TipoPropiedadComercial::SIN_ASIGNAR,
        advisorId: null,
        createdByAdminId: null,
        canal: CanalVenta::DESCONOCIDO,
        mecanismo: MecanismoCreacion::DESCONOCIDO,
        origenPropiedad: OrigenPropiedadComercial::SIN_CONTEXTO,
        cartId: 303,
    );

    expect(fn () => $transporte->registrar(303, $incompatible))
        ->toThrow(ContextoDePeticionInvalidoException::class);

    // El contexto original sigue intacto.
    expect($transporte->para(303)->propiedad)->toBe(TipoPropiedadComercial::EMPRESA);
});

test('registrar dos veces el mismo contexto es valido y el anidamiento restaura el anterior', function () {
    $transporte = va31_transporte();
    $original = va31_contexto(404);

    $transporte->registrar(404, $original);
    $transporte->registrar(404, va31_contexto(404));

    expect($transporte->para(404)->cartId)->toBe(404);

    $transporte->durante(404, va31_contexto(404), function () use ($transporte) {
        expect($transporte->para(404))->not->toBeNull();
    });

    // Al salir del anidamiento el contexto externo sigue vivo: no lo borro el nivel interno.
    expect($transporte->para(404))->not->toBeNull()
        ->and($transporte->para(404)->cartId)->toBe(404);
});

test('la clave del contexto debe ser un cart_id positivo', function () {
    $transporte = va31_transporte();

    expect(fn () => $transporte->registrar(0, va31_contexto(0)))
        ->toThrow(ContextoDePeticionInvalidoException::class);

    expect(fn () => $transporte->durante(-5, va31_contexto(1), fn () => null))
        ->toThrow(ContextoDePeticionInvalidoException::class);
});
