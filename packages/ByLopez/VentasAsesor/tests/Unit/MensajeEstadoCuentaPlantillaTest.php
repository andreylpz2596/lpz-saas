<?php

use ByLopez\VentasAsesor\Services\Mensajes\PlantillaEstadoCuenta;
use Tests\TestCase;

uses(TestCase::class);

/*
| Texto del «Estado de cuenta» de WhatsApp (solo formato, sin base de datos): agrupado por dia, total/abonado solo con abono,
| abonos en verificacion, medios de pago, mas de 10 pedidos, cliente al dia y sin emojis.
*/

function ec_pedido(string $numero, string $fecha, float $saldo, float $total = 0, float $abonado = 0): array
{
    return ['numero' => $numero, 'fecha' => $fecha, 'total' => $total ?: $saldo, 'abonado' => $abonado, 'saldo' => $saldo];
}

function ec_texto(array $pedidos, array $extra = [], bool $emojis = true, ?string $frase = null): string
{
    return (new PlantillaEstadoCuenta('LÓPEZ LÓPEZ', $emojis, $frase))->armar(array_merge([
        'cliente' => 'Marta',
        'fecha' => '2026-11-22',
        'pedidos' => $pedidos,
        'en_verificacion' => 0.0,
        'medios' => [
            ['medio' => 'Bancolombia', 'lineas' => [['Tipo', 'Ahorros'], ['Número', '123-456789-00'], ['Titular', 'López López SAS']]],
            ['medio' => 'Nequi / Daviplata', 'lineas' => [['Número', '3157941278'], ['Titular', 'Camila López']]],
        ],
    ], $extra));
}

test('agrupa por dia del mas antiguo al mas nuevo; total y abonado solo cuando hubo abono; total pendiente con cantidad de pedidos', function () {
    $t = ec_texto([
        ec_pedido('BYL-107', '2026-11-17', 45000, 85000, 40000),
        ec_pedido('BYL-115', '2026-11-19', 61000),
        ec_pedido('BYL-116', '2026-11-19', 32500),
        ec_pedido('BYL-121', '2026-11-21', 74000),
    ]);

    expect($t)->toBe(implode("\n\n", [
        'Hola Marta 👋🏻',
        'Te compartimos el estado de tu cuenta en LÓPEZ LÓPEZ al 22 de noviembre de 2026.',
        '🧾 Pedidos con saldo pendiente:',
        "📅 17 de noviembre\n• #BYL-107 · Saldo $ 45.000 (total $ 85.000, abonado $ 40.000)",
        "📅 19 de noviembre\n• #BYL-115 · Saldo $ 61.000\n• #BYL-116 · Saldo $ 32.500",
        "📅 21 de noviembre\n• #BYL-121 · Saldo $ 74.000",
        '💰 Total pendiente: $ 212.500 (4 pedidos)',
        "💳 Puedes pagar a:\n• Bancolombia (Ahorros): 123-456789-00 · Titular: López López SAS\n• Nequi / Daviplata: 3157941278 · Titular: Camila López",
        'Cuando realices el pago, envíanos el comprobante por este medio. 🙌',
        'Gracias por confiar en LÓPEZ LÓPEZ. 🤍',
    ]));
});

test('abonos en verificacion: se aclaran; un solo pedido en singular; sin medios configurados no hay bloque de pago', function () {
    $t = ec_texto([ec_pedido('BYL-9', '2026-11-20', 30000, 50000, 20000)], ['en_verificacion' => 20000.0, 'medios' => []]);

    expect($t)->toContain('💰 Total pendiente: $ 30.000 (1 pedido)')
        ->toContain('ℹ️ Incluye abonos por $ 20.000 que estamos verificando.')
        ->not->toContain('Puedes pagar a');
});

test('mas de 10 pedidos: lista los 10 mas antiguos y resume el resto; el total los incluye a todos', function () {
    $pedidos = [];

    for ($i = 1; $i <= 13; $i++) {
        $pedidos[] = ec_pedido('BYL-'.$i, '2026-11-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 10000);
    }

    $t = ec_texto($pedidos);

    expect($t)->toContain('#BYL-10 ·')->not->toContain('#BYL-11 ·')
        ->toContain('… y 3 pedidos más por $ 30.000')
        ->toContain('💰 Total pendiente: $ 130.000 (13 pedidos)');
});

test('cliente al dia: mensaje corto, sin medios de pago ni totales', function () {
    expect(ec_texto([]))->toBe("Hola Marta 👋🏻\n\n¡Estás al día! No tienes saldos pendientes con LÓPEZ LÓPEZ. 🤍\n\nGracias por confiar en LÓPEZ LÓPEZ. 🤍");
});

test('sin emojis y con frase final propia', function () {
    $t = ec_texto([ec_pedido('BYL-1', '2026-11-20', 5000)], [], false, 'Con cariño, LÓPEZ LÓPEZ.');

    expect($t)->toStartWith("Hola Marta\n\nTe compartimos")
        ->toContain("Pedidos con saldo pendiente:\n\n20 de noviembre\n• #BYL-1 · Saldo $ 5.000")
        ->toEndWith('Con cariño, LÓPEZ LÓPEZ.')
        ->not->toMatch('/[\x{1F300}-\x{1FAFF}]/u');
});
