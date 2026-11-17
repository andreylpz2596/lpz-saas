<?php

use ByLopez\VentasAsesor\Support\Comisiones\CalendarioComision;
use Carbon\CarbonImmutable;
use Tests\TestCase;

uses(TestCase::class);

/*
| PERÍODO DE COMISIÓN POR ASESORA (2026-11-16). Cálculo puro del calendario: no escribe en la base.
| Fechas en America/Bogota salvo que el caso diga otra zona.
*/

function cal_b(string $fechaHora): CarbonImmutable
{
    return CarbonImmutable::parse($fechaHora, 'America/Bogota');
}

/** [clave, desde, hasta] del período que contiene ese instante. */
function cal_p(CalendarioComision $c, string $fechaHora): array
{
    $p = $c->periodoEn(cal_b($fechaHora));

    return [$p->clave, $p->inicio->toDateString(), $p->hasta()->toDateString()];
}

test('sin día de ingreso el período es el mes calendario (igual que antes)', function () {
    $c = new CalendarioComision;

    expect(cal_p($c, '2026-11-16 10:00'))->toBe(['2026-11', '2026-11-01', '2026-11-30'])
        ->and(cal_p($c, '2026-02-28 23:59'))->toBe(['2026-02', '2026-02-01', '2026-02-28'])
        ->and($c->periodoEn(cal_b('2026-11-16'))->esMesCalendario())->toBeTrue()
        ->and($c->periodoEn(cal_b('2026-11-16'))->etiqueta('es'))->toBe('noviembre 2026');
});

test('día 1 explícito es exactamente el mes calendario', function () {
    $c = new CalendarioComision([['dia' => 1, 'desde' => null]]);

    expect(cal_p($c, '2026-12-31 23:59'))->toBe(['2026-12', '2026-12-01', '2026-12-31']);
});

test('Karen ingresó el 14: períodos del 14 al 13, clave = mes en que empieza', function () {
    $c = new CalendarioComision([['dia' => 14, 'desde' => null]]);

    expect(cal_p($c, '2026-11-16 10:00'))->toBe(['2026-11', '2026-11-14', '2026-12-13'])
        ->and(cal_p($c, '2026-11-13 23:59'))->toBe(['2026-10', '2026-10-14', '2026-11-13'])
        ->and(cal_p($c, '2026-11-14 00:00'))->toBe(['2026-11', '2026-11-14', '2026-12-13'])
        ->and(cal_p($c, '2027-01-05 08:00'))->toBe(['2026-12', '2026-12-14', '2027-01-13']);

    $etiqueta = $c->periodoEn(cal_b('2026-11-16'))->etiqueta('es');
    expect($etiqueta)->toContain('14')->toContain('13')->toContain('2026')->toContain('–');
});

test('Wendy ingresó el 18: períodos del 18 al 17', function () {
    $c = new CalendarioComision([['dia' => 18, 'desde' => null]]);

    expect(cal_p($c, '2026-11-17 12:00'))->toBe(['2026-10', '2026-10-18', '2026-11-17'])
        ->and(cal_p($c, '2026-11-18 00:00'))->toBe(['2026-11', '2026-11-18', '2026-12-17']);
});

test('el instante se lleva a Bogotá antes de decidir el período', function () {
    $c = new CalendarioComision([['dia' => 14, 'desde' => null]]);

    // 04:00 UTC del 14 = 23:00 del 13 en Bogotá: todavía es el período anterior.
    expect($c->clave(CarbonImmutable::parse('2026-11-14 04:00', 'UTC')))->toBe('2026-10')
        ->and($c->clave(CarbonImmutable::parse('2026-11-14 05:00', 'UTC')))->toBe('2026-11');
});

test('día 31 en meses cortos: el período empieza el último día del mes', function () {
    $c = new CalendarioComision([['dia' => 31, 'desde' => null]]);

    expect(cal_p($c, '2027-02-10'))->toBe(['2027-01', '2027-01-31', '2027-02-27'])
        ->and(cal_p($c, '2027-02-28'))->toBe(['2027-02', '2027-02-28', '2027-03-30'])
        ->and(cal_p($c, '2027-04-29'))->toBe(['2027-03', '2027-03-31', '2027-04-29'])
        ->and(cal_p($c, '2027-04-30'))->toBe(['2027-04', '2027-04-30', '2027-05-30'])
        // Año bisiesto: febrero tiene 29.
        ->and(cal_p($c, '2028-02-28'))->toBe(['2028-01', '2028-01-31', '2028-02-28'])
        ->and(cal_p($c, '2028-02-29'))->toBe(['2028-02', '2028-02-29', '2028-03-30']);
});

test('día 29 y 30 también se ajustan en febrero', function () {
    $c30 = new CalendarioComision([['dia' => 30, 'desde' => null]]);
    $c29 = new CalendarioComision([['dia' => 29, 'desde' => null]]);

    expect(cal_p($c30, '2027-02-28'))->toBe(['2027-02', '2027-02-28', '2027-03-29'])
        ->and(cal_p($c30, '2027-02-27'))->toBe(['2027-01', '2027-01-30', '2027-02-27'])
        ->and(cal_p($c29, '2027-02-28'))->toBe(['2027-02', '2027-02-28', '2027-03-28'])
        ->and(cal_p($c29, '2028-02-29'))->toBe(['2028-02', '2028-02-29', '2028-03-28']);
});

test('cambio de 1 a 14 con comisiones: el período en curso se alarga y los vividos no cambian', function () {
    // Cambio guardado el 2026-11-20: el período en curso (nov) termina natural el 1-dic; el primer 14 desde ahí es el 14-dic.
    expect(CalendarioComision::primerInicioDesde(14, cal_b('2026-12-01'))->toDateString())->toBe('2026-12-14');

    $c = new CalendarioComision([['dia' => 1, 'desde' => null], ['dia' => 14, 'desde' => '2026-12-14']]);

    expect(cal_p($c, '2026-10-10'))->toBe(['2026-10', '2026-10-01', '2026-10-31'])
        ->and(cal_p($c, '2026-11-20'))->toBe(['2026-11', '2026-11-01', '2026-12-13'])
        ->and(cal_p($c, '2026-12-05'))->toBe(['2026-11', '2026-11-01', '2026-12-13'])
        ->and(cal_p($c, '2026-12-14'))->toBe(['2026-12', '2026-12-14', '2027-01-13'])
        ->and($c->rango('2026-11')?->hasta()->toDateString())->toBe('2026-12-13')
        ->and($c->rango('2026-12')?->inicio->toDateString())->toBe('2026-12-14')
        ->and($c->diaEn(cal_b('2026-12-05')))->toBe(1)
        ->and($c->cambioProgramado(cal_b('2026-11-20')))->toMatchArray(['dia' => 14]);
});

test('cambio de 14 a 1 con comisiones: diciembre no abre período propio (queda absorbido)', function () {
    // Cambio el 2026-11-20: el período en curso (14-nov a 13-dic) se alarga hasta el 1-ene.
    expect(CalendarioComision::primerInicioDesde(1, cal_b('2026-12-14'))->toDateString())->toBe('2027-01-01');

    $c = new CalendarioComision([['dia' => 14, 'desde' => null], ['dia' => 1, 'desde' => '2027-01-01']]);

    expect(cal_p($c, '2026-10-20'))->toBe(['2026-10', '2026-10-14', '2026-11-13'])
        ->and(cal_p($c, '2026-12-20'))->toBe(['2026-11', '2026-11-14', '2026-12-31'])
        ->and(cal_p($c, '2027-01-05'))->toBe(['2027-01', '2027-01-01', '2027-01-31'])
        ->and($c->rango('2026-12'))->toBeNull();
});

test('cambio que coincide con el fin natural no alarga nada', function () {
    // 31 → 28: el período 31-ene..27-feb termina el 28-feb, que ya es inicio del día 28.
    $c = new CalendarioComision([['dia' => 31, 'desde' => null], ['dia' => 28, 'desde' => '2027-02-28']]);

    expect(cal_p($c, '2027-02-10'))->toBe(['2027-01', '2027-01-31', '2027-02-27'])
        ->and(cal_p($c, '2027-02-28'))->toBe(['2027-02', '2027-02-28', '2027-03-27']);
});

test('propiedad: períodos contiguos, sin huecos, claves crecientes y rango(clave) coherente', function (array $versiones) {
    $c = new CalendarioComision($versiones);
    $dia = cal_b('2026-01-01');
    $anterior = null;

    for ($i = 0; $i < 1100; $i++, $dia = $dia->addDay()) {
        $p = $c->periodoEn($dia);

        expect($dia->greaterThanOrEqualTo($p->inicio) && $dia->lessThan($p->fin))->toBeTrue()
            ->and($p->clave)->toBe($p->inicio->format('Y-m'))
            ->and($c->rango($p->clave)?->inicio->toDateString())->toBe($p->inicio->toDateString());

        if ($anterior !== null && $anterior->clave !== $p->clave) {
            expect($p->inicio->toDateString())->toBe($anterior->fin->toDateString())
                ->and($p->clave > $anterior->clave)->toBeTrue();
        }

        $anterior = $p;
    }
})->with([
    'calendario' => [[]],
    'día 14' => [[['dia' => 14, 'desde' => null]]],
    'día 31' => [[['dia' => 31, 'desde' => null]]],
    '1 → 14 → 31 → 1' => [[['dia' => 1, 'desde' => null], ['dia' => 14, 'desde' => '2026-03-14'], ['dia' => 31, 'desde' => '2026-07-31'], ['dia' => 1, 'desde' => '2027-01-01']]],
    '18 → 2' => [[['dia' => 18, 'desde' => null], ['dia' => 2, 'desde' => '2026-06-02']]],
]);

test('sugerencia de liquidación: el último período ya terminado de la asesora', function () {
    $karen = new CalendarioComision([['dia' => 14, 'desde' => null]]);
    $zuly = new CalendarioComision;

    expect($karen->sugerenciaLiquidacion(cal_b('2026-11-16')))->toMatchArray(['desde' => '2026-10-14', 'hasta' => '2026-11-13'])
        ->and($zuly->sugerenciaLiquidacion(cal_b('2026-11-16')))->toMatchArray(['desde' => '2026-10-01', 'hasta' => '2026-10-31']);
});
