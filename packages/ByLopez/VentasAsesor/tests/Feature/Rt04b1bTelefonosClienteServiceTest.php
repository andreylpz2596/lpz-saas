<?php

use ByLopez\VentasAsesor\Exceptions\OperacionTelefonoInvalidaException as Invalida;
use ByLopez\VentasAsesor\Exceptions\TelefonoEnVariasFichasException;
use ByLopez\VentasAsesor\Exceptions\TelefonoPerteneceAOtroClienteException;
use ByLopez\VentasAsesor\Models\TelefonoCliente;
use ByLopez\VentasAsesor\Services\Clientes\TelefonosClienteService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Webkul\Customer\Models\Customer;

require_once __DIR__.'/../helpersRt04b1b.php';

uses(TestCase::class);

/*
| RT-04B1b — TelefonosClienteService: principal <-> customers.phone, secundarios,
| unicidad y transacciones. Solo lpz_saas_testing.
*/

function rt04b1b_filas(int $customerId): array
{
    return TelefonoCliente::query()->where('customer_id', $customerId)->orderBy('id')
        ->get()->map(fn ($f) => [$f->telefono_normalizado, $f->esPrincipal()])->all();
}

beforeEach(function () {
    $this->servicio = app(TelefonosClienteService::class);
});

test('registrar principal: fila principal normalizada y customers.phone en E.164, sincronizados', function () {
    $cliente = Customer::factory()->create(['phone' => null]);
    $movil = rt04b1b_movil();

    $this->servicio->establecerPrincipal($cliente->id, substr($movil, 0, 3).' '.substr($movil, 3, 3).' '.substr($movil, 6));

    expect($cliente->fresh()->phone)->toBe('+57'.$movil)
        ->and(rt04b1b_filas($cliente->id))->toBe([['+57'.$movil, true]]);
});

test('agregar secundario: resuelve al mismo Customer y es idempotente (tambien con otro formato)', function () {
    $cliente = Customer::factory()->create(['phone' => null]);
    $this->servicio->establecerPrincipal($cliente->id, $principal = rt04b1b_movil());
    $secundario = rt04b1b_movil();

    $this->servicio->agregarSecundario($cliente->id, $secundario);
    $this->servicio->agregarSecundario($cliente->id, '+57 '.$secundario);

    expect(rt04b1b_filas($cliente->id))->toBe([['+57'.$principal, true], ['+57'.$secundario, false]])
        ->and($cliente->fresh()->phone)->toBe('+57'.$principal);
});

test('promover secundario: pasa a principal, el anterior queda secundario y customers.phone lo sigue', function () {
    $cliente = Customer::factory()->create(['phone' => null]);
    $this->servicio->establecerPrincipal($cliente->id, $a = rt04b1b_movil());
    $this->servicio->agregarSecundario($cliente->id, $b = rt04b1b_movil());

    $this->servicio->promoverAPrincipal($cliente->id, $b);

    expect(rt04b1b_filas($cliente->id))->toBe([['+57'.$a, false], ['+57'.$b, true]])
        ->and($cliente->fresh()->phone)->toBe('+57'.$b);
});

test('promover exige que el numero ya sea del cliente', function () {
    $cliente = Customer::factory()->create(['phone' => null]);
    $this->servicio->establecerPrincipal($cliente->id, rt04b1b_movil());

    expect(fn () => $this->servicio->promoverAPrincipal($cliente->id, rt04b1b_movil()))
        ->toThrow(Invalida::class, Invalida::TELEFONO_NO_ES_DEL_CLIENTE);
});

test('cambiar principal sin conservar el anterior: el anterior se retira y customers.phone cambia', function () {
    $cliente = Customer::factory()->create(['phone' => null]);
    $this->servicio->establecerPrincipal($cliente->id, rt04b1b_movil());

    $this->servicio->establecerPrincipal($cliente->id, $nuevo = rt04b1b_movil(), false);

    expect(rt04b1b_filas($cliente->id))->toBe([['+57'.$nuevo, true]])
        ->and($cliente->fresh()->phone)->toBe('+57'.$nuevo);
});

test('eliminar secundario si; eliminar el principal no (primero se reemplaza)', function () {
    $cliente = Customer::factory()->create(['phone' => null]);
    $this->servicio->establecerPrincipal($cliente->id, $a = rt04b1b_movil());
    $this->servicio->agregarSecundario($cliente->id, $b = rt04b1b_movil());

    $this->servicio->eliminarSecundario($cliente->id, $b);

    expect(rt04b1b_filas($cliente->id))->toBe([['+57'.$a, true]])
        ->and(fn () => $this->servicio->eliminarSecundario($cliente->id, $a))->toThrow(Invalida::class, Invalida::PRINCIPAL_NO_ELIMINABLE)
        ->and($cliente->fresh()->phone)->toBe('+57'.$a);
});

test('un numero de otro Customer se rechaza en principal y secundario, sin reasignar', function () {
    $titular = Customer::factory()->create(['phone' => null]);
    $otro = Customer::factory()->create(['phone' => null]);
    $this->servicio->establecerPrincipal($titular->id, $movil = rt04b1b_movil());

    expect(fn () => $this->servicio->agregarSecundario($otro->id, '0057'.$movil))->toThrow(TelefonoPerteneceAOtroClienteException::class)
        ->and(fn () => $this->servicio->establecerPrincipal($otro->id, $movil))->toThrow(TelefonoPerteneceAOtroClienteException::class)
        ->and(rt04b1b_filas($otro->id))->toBe([])
        ->and($otro->fresh()->phone)->toBeNull();
});

test('cliente historico sin indexar: su customers.phone cuenta como dueño del numero', function () {
    $historico = Customer::factory()->create(['phone' => $movil = rt04b1b_movil()]);
    $otro = Customer::factory()->create(['phone' => null]);

    expect(fn () => $this->servicio->agregarSecundario($otro->id, '+57'.$movil))->toThrow(TelefonoPerteneceAOtroClienteException::class)
        ->and(TelefonoCliente::query()->where('customer_id', $historico->id)->count())->toBe(0);
});

test('cliente historico: al establecer un principal nuevo, su telefono anterior queda como secundario', function () {
    $cliente = Customer::factory()->create(['phone' => $viejo = rt04b1b_movil()]);

    $this->servicio->establecerPrincipal($cliente->id, $nuevo = rt04b1b_movil());

    expect(rt04b1b_filas($cliente->id))->toBe([['+57'.$viejo, false], ['+57'.$nuevo, true]])
        ->and($cliente->fresh()->phone)->toBe('+57'.$nuevo);
});

test('mismo numero en dos fichas historicas: fail closed', function () {
    $movil = rt04b1b_movil();
    Customer::factory()->create(['phone' => $movil]);
    Customer::factory()->create(['phone' => '+57'.$movil]);
    $tercero = Customer::factory()->create(['phone' => null]);

    expect(fn () => $this->servicio->agregarSecundario($tercero->id, $movil))->toThrow(TelefonoEnVariasFichasException::class);
});

test('rollback: si customers.phone choca con el UNIQUE del core, tampoco queda la fila del indice', function () {
    $cliente = Customer::factory()->create(['phone' => null]);
    $movil = rt04b1b_movil();
    // Ficha de otro cliente con un valor que el localizador no puede asociar al numero
    // (el UNIQUE del core compara cadenas exactas): simulamos una carrera escribiendolo
    // justo antes de actualizar customers.phone.
    $intruso = Customer::factory()->create(['phone' => null]);
    Customer::saving(function ($c) use ($cliente, $intruso, $movil) {
        if ((int) $c->id === (int) $cliente->id && $c->phone === '+57'.$movil) {
            DB::table('customers')->where('id', $intruso->id)->update(['phone' => '+57'.$movil]);
        }
    });

    expect(fn () => $this->servicio->establecerPrincipal($cliente->id, $movil))->toThrow(TelefonoPerteneceAOtroClienteException::class)
        ->and(rt04b1b_filas($cliente->id))->toBe([])
        ->and($cliente->fresh()->phone)->toBeNull();
});

test('telefono no normalizable y cliente inexistente se rechazan con codigo estable', function () {
    $cliente = Customer::factory()->create(['phone' => null]);

    expect(fn () => $this->servicio->agregarSecundario($cliente->id, '2345678'))->toThrow(Invalida::class, Invalida::TELEFONO_NO_NORMALIZABLE)
        ->and(fn () => $this->servicio->agregarSecundario(PHP_INT_MAX, rt04b1b_movil()))->toThrow(Invalida::class, Invalida::CLIENTE_INEXISTENTE);
});
