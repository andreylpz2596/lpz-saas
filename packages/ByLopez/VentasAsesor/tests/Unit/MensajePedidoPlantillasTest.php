<?php

use ByLopez\VentasAsesor\Services\Mensajes\PlantillasMensajePedido as P;
use Tests\TestCase;

uses(TestCase::class);

/*
| Texto de los mensajes de WhatsApp (solo formato, sin base de datos): cada tipo, emojis, dinero, consolidacion de productos y que
| NUNCA aparezca nada interno. Las plantillas reciben los datos ya armados por LectorPedidoParaMensaje.
*/

function wp_datos(array $extra = []): array
{
    $d = [
        'pedido' => ['id' => 114, 'numero' => '114', 'estado' => 'processing', 'metodo_envio' => 'envio_nacional', 'es_recogida' => false, 'es_domicilio' => false],
        'cliente' => ['nombre' => 'Marta', 'telefono' => '+573001112233'],
        'lineas' => [
            ['id' => 1, 'producto_id' => 10, 'nombre' => 'Camiseta Caballero', 'color' => 'Negro', 'talla' => 'XL', 'objetivo' => 2],
            ['id' => 2, 'producto_id' => 10, 'nombre' => 'Camiseta Caballero', 'color' => 'Negro', 'talla' => 'M', 'objetivo' => 5],
            ['id' => 3, 'producto_id' => 11, 'nombre' => 'Camiseta Dama Rosado', 'color' => 'Rosado', 'talla' => 'M', 'objetivo' => 1],
        ],
        'objetivo' => 8, 'despachadas' => 0, 'envios' => [],
        'destino' => ['empresa' => null, 'direccion' => 'Calle 10 # 20-30', 'ciudad' => 'Bogotá', 'departamento' => 'Bogotá D.C.'],
        'recogida' => null,
        'dinero' => ['total' => 189000.0, 'abonos' => null, 'saldo' => null],
    ];

    // Las listas se reemplazan completas (array_replace_recursive las mezclaria por indice).
    foreach (['lineas'] as $lista) {
        if (isset($extra[$lista])) {
            $d[$lista] = $extra[$lista];
            unset($extra[$lista]);
        }
    }

    return array_replace_recursive($d, $extra);
}

function wp_envio(array $extra = []): array
{
    return $extra + [
        'id' => 64, 'orden' => 1, 'fecha' => '2026-11-04 10:00:00',
        'unidades' => [1 => ['cantidad' => 2, 'nombre' => 'x'], 2 => ['cantidad' => 5, 'nombre' => 'x']],
        'pendientes_despues' => [], 'pendientes_total_despues' => 0,
        'guia' => ['transportadora' => 'Inter Rapidísimo', 'tipo' => 'transportadora', 'numero' => '123456789', 'interna' => false, 'tracking' => null],
        'es_recogida' => false, 'validado' => false,
    ];
}

function wp_p(bool $emojis = true, ?string $frase = null): P
{
    return new P('LÓPEZ LÓPEZ', $emojis, $frase);
}

test('despacho completo: productos reales, transportadora, guia, destino, total y seguimiento; sin SKU', function () {
    $t = wp_p()->armar(P::DESPACHO_COMPLETO, wp_datos(['despachadas' => 7]), wp_envio());

    expect($t)->toContain('Hola Marta 👋🏻')
        ->and($t)->toContain('¡Tu pedido de LÓPEZ LÓPEZ #114 ya fue despachado! 📦✨')
        ->and($t)->toContain("• 2 Camiseta Caballero · Negro · Talla XL\n• 5 Camiseta Caballero · Negro · Talla M")
        ->and($t)->toContain('📦 Total despachado: 7 prendas')
        ->and($t)->toContain('🚚 Transportadora: Inter Rapidísimo')
        ->and($t)->toContain('🔎 Guía: 123456789')
        ->and($t)->toContain('📍 Destino: Bogotá')
        ->and($t)->toContain('💰 Total del pedido: $ 189.000')
        ->and($t)->toContain('Puedes realizar seguimiento con el número de guía.')
        ->and($t)->toContain('Gracias por confiar en LÓPEZ LÓPEZ. 🤍')
        ->and($t)->not->toContain('Rosado');   // la Dama NO se despacho: nunca se usan las cantidades originales
});

test('despacho parcial: separa enviados de pendientes y NUNCA dice que el pedido fue despachado', function () {
    $envio = wp_envio(['pendientes_despues' => [2 => 0, 3 => 1], 'pendientes_total_despues' => 1, 'unidades' => [1 => ['cantidad' => 2, 'nombre' => 'x']]]);
    $t = wp_p()->armar(P::DESPACHO_PARCIAL, wp_datos(['despachadas' => 2]), $envio);

    expect($t)->toContain('Realizamos un despacho parcial.')
        ->and($t)->toContain("✅ Productos enviados:\n• 2 Camiseta Caballero · Negro · Talla XL")
        ->and($t)->toContain("⏳ Pendientes:\n• 1 Camiseta Dama Rosado · Talla M")
        ->and($t)->toContain('Las prendas pendientes continúan asociadas al mismo pedido.')
        ->and($t)->toContain('Te avisaremos cuando realicemos el siguiente despacho.')
        ->and($t)->not->toContain('ya fue despachado')
        ->and($t)->not->toContain('Total del pedido');
});

test('segundo despacho: una guia por despacho, solo sus prendas, sin mezclar', function () {
    $d = wp_datos(['despachadas' => 8]);
    $segundo = wp_envio(['id' => 65, 'orden' => 2, 'unidades' => [3 => ['cantidad' => 1, 'nombre' => 'x']],
        'guia' => ['transportadora' => 'Servientrega', 'tipo' => 'transportadora', 'numero' => '999000111', 'interna' => false, 'tracking' => 'https://rastreo.test/999000111']]);
    $t = wp_p()->armar(P::DESPACHO_FINAL, $d, $segundo);

    expect($t)->toContain('Realizamos el último despacho de tu pedido LÓPEZ LÓPEZ #114. ✅')
        ->and($t)->toContain('Con este despacho tu pedido queda completo.')
        ->and($t)->toContain('• 1 Camiseta Dama Rosado · Talla M')
        ->and($t)->toContain('🔎 Guía: 999000111')
        ->and($t)->toContain('🔗 Seguimiento: https://rastreo.test/999000111')
        ->and($t)->not->toContain('123456789')
        ->and($t)->not->toContain('Camiseta Caballero');

    $nuevoParcial = wp_p()->armar(P::DESPACHO_PARCIAL, $d, wp_envio(['orden' => 2, 'pendientes_despues' => [3 => 1], 'pendientes_total_despues' => 1]));
    expect($nuevoParcial)->toContain('Realizamos un nuevo despacho parcial.');
});

test('guia interna: se muestra como guia interna y nunca se etiqueta con otra transportadora', function () {
    $envio = wp_envio(['guia' => ['transportadora' => 'PICAP', 'tipo' => 'plataforma', 'numero' => 'GI-000123', 'interna' => true, 'tracking' => null]]);
    $t = wp_p()->armar(P::DESPACHO_COMPLETO, wp_datos(), $envio);

    expect($t)->toContain('🔎 Guía interna: GI-000123')->and($t)->toContain('🚚 Transportadora: PICAP')->and($t)->not->toContain('Inter Rapid');
});

test('domicilio local: sin transportadora ni guia externa, con la direccion y aviso de telefono', function () {
    $d = wp_datos(['pedido' => ['metodo_envio' => 'domicilio_local', 'es_domicilio' => true]]);
    $envio = wp_envio(['guia' => ['transportadora' => 'Jeison', 'tipo' => 'domiciliario', 'numero' => 'GI-000200', 'interna' => true, 'tracking' => null]]);
    $t = wp_p()->armar(P::DESPACHO_COMPLETO, $d, $envio);

    expect($t)->toContain('🛵 Tu pedido va en camino a domicilio.')
        ->and($t)->toContain("📍 Dirección:\nCalle 10 # 20-30, Bogotá")
        ->and($t)->toContain('Por favor mantente pendiente del teléfono.')
        ->and($t)->not->toContain('Transportadora')->and($t)->not->toContain('GI-000200')->and($t)->not->toContain('Jeison')->and($t)->not->toContain('seguimiento');

    $listo = wp_p()->armar(P::LISTO_DOMICILIO, $d);
    expect($listo)->toContain('está listo para salir a domicilio. 🛵')
        ->and($listo)->toContain('📦 8 prendas')->and($listo)->toContain('💰 Total del pedido: $ 189.000')
        ->and($listo)->not->toContain('Guía');
});

test('recogida: sin guia ni transportadora; lugar de la bodega; horario solo si existe', function () {
    $d = wp_datos(['pedido' => ['metodo_envio' => 'pickup', 'es_recogida' => true], 'destino' => ['empresa' => 'BODEGA PRINCIPAL', 'direccion' => 'Cra 74b # 58-19 sur', 'ciudad' => 'BOGOTA']]);
    $sin = wp_p()->armar(P::LISTO_RECOGER, $d);

    expect($sin)->toContain('ya está listo para recoger. 🛍️')
        ->and($sin)->toContain("📍 Puedes recogerlo en:\nBODEGA PRINCIPAL\nCra 74b # 58-19 sur, BOGOTA")
        ->and($sin)->not->toContain('Guía')->and($sin)->not->toContain('Transportadora')->and($sin)->not->toContain('🕒');

    $con = wp_p()->armar(P::LISTO_RECOGER, array_replace($d, ['recogida' => ['fecha' => '2026-11-05', 'desde' => '09:00', 'hasta' => '17:00']]));
    expect($con)->toContain('🕒 Fecha: 05/11/2026, de 09:00 a 17:00');
});

test('entregado: mensaje corto y sin dinero ni listado', function () {
    $t = wp_p()->armar(P::ENTREGADO, wp_datos());

    expect($t)->toBe("Hola Marta 🤍\n\nNos aparece que tu pedido LÓPEZ LÓPEZ #114 ya fue entregado.\n\nEsperamos que todo haya llegado correctamente.\n\nMuchas gracias por confiar en LÓPEZ LÓPEZ. 🤍");
});

test('resumen: productos, entrega, direccion y total; una linea por producto, talla y color', function () {
    $t = wp_p()->armar(P::RESUMEN, wp_datos());

    expect($t)->toContain('Este es el resumen de tu pedido de LÓPEZ LÓPEZ #114.')
        ->and($t)->toContain('📦 Total: 8 prendas')
        ->and($t)->toContain('🚚 Entrega: Envío nacional')
        ->and($t)->toContain("📍 Dirección:\nCalle 10 # 20-30, Bogotá")
        ->and($t)->toContain('💰 Total del pedido: $ 189.000');
});

test('dinero: sin abonos solo el total; con abono parcial el saldo de Cartera; pagado; BODEGA (sin dinero) no ve ningun valor', function () {
    $solo = wp_p()->armar(P::RESUMEN, wp_datos());
    expect($solo)->toContain('Total del pedido')->and($solo)->not->toContain('Saldo')->and($solo)->not->toContain('Abonos')->and($solo)->not->toContain('Debes');

    $parcial = wp_p()->armar(P::RESUMEN, wp_datos(['dinero' => ['abonos' => 50000.0, 'saldo' => 139000.0]]));
    expect($parcial)->toContain('✅ Abonos aplicados: $ 50.000')->and($parcial)->toContain('⏳ Saldo pendiente: $ 139.000')->and($parcial)->not->toContain('Debes');

    $pagado = wp_p()->armar(P::RESUMEN, wp_datos(['dinero' => ['abonos' => 189000.0, 'saldo' => 0.0]]));
    expect($pagado)->toContain('✅ Pedido pagado')->and($pagado)->not->toContain('Saldo pendiente');

    $d = wp_datos();
    $d['dinero'] = null;
    foreach ([P::DESPACHO_COMPLETO, P::LISTO_DOMICILIO] as $tipo) {
        expect(wp_p()->armar($tipo, $d, $tipo === P::DESPACHO_COMPLETO ? wp_envio() : null))->not->toContain('$')->and(wp_p()->armar($tipo, $d, $tipo === P::DESPACHO_COMPLETO ? wp_envio() : null))->not->toContain('Total del pedido');
    }
});

test('emojis apagados: ningun emoji pero los acentos y la ñ se conservan; frase final propia reemplaza la despedida', function () {
    $t = wp_p(false, 'Gracias por elegirnos, LÓPEZ LÓPEZ')->armar(P::DESPACHO_COMPLETO, wp_datos(), wp_envio());

    expect(preg_match('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}]/u', $t))->toBe(0)
        ->and($t)->toContain('¡Tu pedido de LÓPEZ LÓPEZ #114 ya fue despachado!')
        ->and($t)->toContain('Transportadora: Inter Rapidísimo')
        ->and($t)->toContain('Gracias por elegirnos, LÓPEZ LÓPEZ')
        ->and($t)->not->toContain('Gracias por confiar');
});

test('productos: se suman solo lineas identicas; productos distintos con nombre parecido NO se mezclan', function () {
    $d = wp_datos(['lineas' => [
        ['id' => 1, 'producto_id' => 10, 'nombre' => 'Camiseta Caballero', 'color' => 'Negro', 'talla' => 'M', 'objetivo' => 2],
        ['id' => 2, 'producto_id' => 10, 'nombre' => 'Camiseta Caballero', 'color' => 'Negro', 'talla' => 'M', 'objetivo' => 3],
        ['id' => 3, 'producto_id' => 99, 'nombre' => 'Camiseta Caballero', 'color' => 'Negro', 'talla' => 'M', 'objetivo' => 4],
    ], 'objetivo' => 9]);
    $t = wp_p()->armar(P::RESUMEN, $d);

    expect($t)->toContain('• 5 Camiseta Caballero · Negro · Talla M')->and($t)->toContain('• 4 Camiseta Caballero · Negro · Talla M');
});

test('el color se agrega solo cuando el nombre del producto no lo trae', function () {
    $d = wp_datos(['lineas' => [
        ['id' => 1, 'producto_id' => 10, 'nombre' => 'Camiseta Piel Durazno Negro Hombre', 'color' => 'Negro', 'talla' => 'M', 'objetivo' => 1],
    ], 'objetivo' => 1]);

    expect(wp_p()->armar(P::RESUMEN, $d))->toContain('• 1 Camiseta Piel Durazno Negro Hombre · Talla M');
});

test('ningun tipo de mensaje menciona nada interno', function () {
    $d = wp_datos(['pedido' => ['es_domicilio' => true], 'dinero' => ['abonos' => 1000.0, 'saldo' => 500.0]]);
    $textos = [
        wp_p()->armar(P::RESUMEN, $d), wp_p()->armar(P::LISTO_DOMICILIO, $d), wp_p()->armar(P::LISTO_RECOGER, $d),
        wp_p()->armar(P::DESPACHO_COMPLETO, $d, wp_envio()), wp_p()->armar(P::DESPACHO_PARCIAL, $d, wp_envio(['pendientes_despues' => [3 => 1], 'pendientes_total_despues' => 1])),
        wp_p()->armar(P::DESPACHO_FINAL, $d, wp_envio(['orden' => 2])), wp_p()->armar(P::ENTREGADO, $d),
    ];

    foreach ($textos as $t) {
        expect(mb_strtolower($t))->not->toMatch('/costo|utilidad|margen|comisi|sku|conciliaci|custodia|c6|99\s?env|recaudo|flete|deuda|nota interna|crédito|credito/u');
    }
});
