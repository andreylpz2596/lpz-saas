<?php

use ByLopez\VentasAsesor\Enums\ResultadoIndexacionTelefono as Resultado;
use ByLopez\VentasAsesor\Exceptions\TelefonoPerteneceAOtroClienteException;
use ByLopez\VentasAsesor\Models\TelefonoCliente;
use ByLopez\VentasAsesor\Repositories\TelefonoClienteRepository;
use ByLopez\VentasAsesor\Services\Clientes\IndexadorTelefonosClientesService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;
use Webkul\Core\Rules\PhoneNumber;
use Webkul\Customer\Models\Customer;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| RT-04B1a — Indice comercial de telefonos e indexador historico
|--------------------------------------------------------------------------
| Solo lpz_saas_testing. Numeros ficticios y unicos por prueba.
*/

/** Movil colombiano ficticio de 10 digitos, distinto en cada llamada. */
function rt04b1a_movil(): string
{
    return '3'.str_pad((string) random_int(0, 999_999_999), 9, '0', STR_PAD_LEFT);
}

function rt04b1a_fila(int $customerId, string $movil, bool $principal): TelefonoCliente
{
    return app(TelefonoClienteRepository::class)->guardar(new TelefonoCliente([
        'customer_id' => $customerId,
        'telefono_normalizado' => '+57'.$movil,
        'telefono_original' => $movil,
        'principal' => $principal,
    ]));
}

/** @return array<int, Resultado> customer_id => resultado */
function rt04b1a_indexar(bool $persistir, int ...$clientes): array
{
    $resultados = [];

    foreach ($clientes as $id) {
        foreach (app(IndexadorTelefonosClientesService::class)->ejecutar($persistir, $id) as $analisis) {
            $resultados[$analisis->customerId] = $analisis;
        }
    }

    return $resultados;
}

// ---------------------------------------------------------------- invariantes de la tabla

test('un Customer admite un principal y varios secundarios, todos resolviendo al mismo Customer', function () {
    $cliente = Customer::factory()->create(['phone' => null]);

    rt04b1a_fila($cliente->id, rt04b1a_movil(), true);
    rt04b1a_fila($cliente->id, rt04b1a_movil(), false);
    rt04b1a_fila($cliente->id, rt04b1a_movil(), false);

    $filas = TelefonoCliente::query()->where('customer_id', $cliente->id)->get();

    expect($filas)->toHaveCount(3)
        ->and($filas->filter->esPrincipal())->toHaveCount(1)
        ->and($filas->pluck('customer_id')->unique()->all())->toBe([$cliente->id]);
});

test('un secundario se guarda como NULL en la base, nunca como 0', function () {
    $cliente = Customer::factory()->create(['phone' => null]);
    $fila = rt04b1a_fila($cliente->id, rt04b1a_movil(), false);

    expect(DB::table('bylopez_va_customer_phones')->where('id', $fila->id)->value('principal'))->toBeNull();
});

test('la base impide un segundo principal para el mismo Customer', function () {
    $cliente = Customer::factory()->create(['phone' => null]);
    rt04b1a_fila($cliente->id, rt04b1a_movil(), true);

    expect(fn () => rt04b1a_fila($cliente->id, rt04b1a_movil(), true))->toThrow(UniqueConstraintViolationException::class);
});

test('la base impide el mismo numero normalizado dos veces en el mismo Customer', function () {
    $cliente = Customer::factory()->create(['phone' => null]);
    $movil = rt04b1a_movil();
    rt04b1a_fila($cliente->id, $movil, true);

    expect(fn () => rt04b1a_fila($cliente->id, $movil, false))->toThrow(UniqueConstraintViolationException::class);
});

test('un numero normalizado que ya es de otro Customer se rechaza con el titular, sin reasignarlo', function () {
    $titular = Customer::factory()->create(['phone' => null]);
    $otro = Customer::factory()->create(['phone' => null]);
    $movil = rt04b1a_movil();
    rt04b1a_fila($titular->id, $movil, false);

    try {
        rt04b1a_fila($otro->id, $movil, true);
        $this->fail('Debio rechazarse');
    } catch (TelefonoPerteneceAOtroClienteException $e) {
        expect($e->customerIdTitular)->toBe($titular->id)
            ->and($e->customerIdSolicitante)->toBe($otro->id)
            ->and($e->getMessage())->not->toContain($movil);
    }

    expect(TelefonoCliente::query()->where('telefono_normalizado', '+57'.$movil)->pluck('customer_id')->all())->toBe([$titular->id]);
});

test('borrar el Customer borra su indice (CASCADE): no bloquea el borrado del core', function () {
    $cliente = Customer::factory()->create(['phone' => null]);
    rt04b1a_fila($cliente->id, rt04b1a_movil(), true);

    $cliente->delete();

    expect(TelefonoCliente::query()->where('customer_id', $cliente->id)->count())->toBe(0);
});

// ---------------------------------------------------------------- compatibilidad con customers.phone

test('customers.phone en E.164 pasa la regla PhoneNumber del core; con espacios no (por eso el principal se guarda normalizado)', function () {
    expect(Validator::make(['phone' => '+573005550101'], ['phone' => [new PhoneNumber]])->passes())->toBeTrue()
        ->and(Validator::make(['phone' => '300 555 0101'], ['phone' => [new PhoneNumber]])->passes())->toBeFalse();
});

test('customers.phone sigue siendo UNICO global en el core: el indice no lo sustituye ni lo relaja', function () {
    $movil = rt04b1a_movil();
    Customer::factory()->create(['phone' => $movil]);

    expect(fn () => Customer::factory()->create(['phone' => $movil]))->toThrow(UniqueConstraintViolationException::class);
});

// ---------------------------------------------------------------- indexador historico

test('dry-run: reporta INDEXABLE y no escribe nada', function () {
    $cliente = Customer::factory()->create(['phone' => $movil = rt04b1a_movil()]);

    $r = rt04b1a_indexar(false, $cliente->id);

    expect($r[$cliente->id]->resultado)->toBe(Resultado::INDEXABLE)
        ->and($r[$cliente->id]->telefonoEnmascarado)->toBe('***'.substr($movil, -4))
        ->and(TelefonoCliente::query()->where('customer_id', $cliente->id)->count())->toBe(0);
});

test('persistir: crea el principal normalizado, NO modifica customers.phone y es idempotente', function () {
    $cliente = Customer::factory()->create(['phone' => $movil = rt04b1a_movil()]);

    expect(rt04b1a_indexar(true, $cliente->id)[$cliente->id]->resultado)->toBe(Resultado::INDEXADO)
        ->and(rt04b1a_indexar(true, $cliente->id)[$cliente->id]->resultado)->toBe(Resultado::YA_INDEXADO);

    $fila = TelefonoCliente::query()->where('customer_id', $cliente->id)->sole();

    expect($fila->telefono_normalizado)->toBe('+57'.$movil)
        ->and($fila->telefono_original)->toBe($movil)
        ->and($fila->esPrincipal())->toBeTrue()
        ->and($cliente->fresh()->phone)->toBe($movil);
});

test('ficha invalida o ambigua: se reporta y no se indexa', function () {
    $invalido = Customer::factory()->create(['phone' => '12345']);
    $ambiguo = Customer::factory()->create(['phone' => '2'.random_int(100000, 999999)]);

    $r = rt04b1a_indexar(true, $invalido->id, $ambiguo->id);

    expect($r[$invalido->id]->resultado)->toBe(Resultado::INVALIDO)
        ->and($r[$ambiguo->id]->resultado)->toBe(Resultado::AMBIGUO)
        ->and(TelefonoCliente::query()->whereIn('customer_id', [$invalido->id, $ambiguo->id])->count())->toBe(0);
});

test('mismo numero en dos fichas con formatos distintos: DUPLICADO en ambas y no se elige titular', function () {
    $movil = rt04b1a_movil();
    $a = Customer::factory()->create(['phone' => $movil]);
    $b = Customer::factory()->create(['phone' => '+57'.$movil]);

    $r = rt04b1a_indexar(true, $a->id, $b->id);

    expect($r[$a->id]->resultado)->toBe(Resultado::DUPLICADO)
        ->and($r[$a->id]->customerIdRelacionado)->toBe($b->id)
        ->and($r[$b->id]->resultado)->toBe(Resultado::DUPLICADO)
        ->and(TelefonoCliente::query()->where('telefono_normalizado', '+57'.$movil)->count())->toBe(0);
});

test('numero ya indexado para otro Customer: CONFLICTO con el titular, sin escribir', function () {
    $movil = rt04b1a_movil();
    $titular = Customer::factory()->create(['phone' => null]);
    rt04b1a_fila($titular->id, $movil, false);
    $otro = Customer::factory()->create(['phone' => $movil]);

    $r = rt04b1a_indexar(true, $otro->id);

    expect($r[$otro->id]->resultado)->toBe(Resultado::CONFLICTO)
        ->and($r[$otro->id]->motivo)->toBe('pertenece_a_otro_cliente')
        ->and($r[$otro->id]->customerIdRelacionado)->toBe($titular->id)
        ->and(TelefonoCliente::query()->where('customer_id', $otro->id)->count())->toBe(0);
});

test('ficha igual a un secundario del mismo Customer: CONFLICTO (la promocion es de B1b)', function () {
    $movil = rt04b1a_movil();
    $cliente = Customer::factory()->create(['phone' => null]);
    rt04b1a_fila($cliente->id, $movil, false);
    $cliente->update(['phone' => $movil]);

    $r = rt04b1a_indexar(true, $cliente->id);

    expect($r[$cliente->id]->resultado)->toBe(Resultado::CONFLICTO)
        ->and($r[$cliente->id]->motivo)->toBe('igual_a_un_secundario');
});

test('ficha cambiada fuera del modulo: DIVERGENTE en dry-run y REINDEXADO al persistir, sin tocar customers.phone', function () {
    $cliente = Customer::factory()->create(['phone' => rt04b1a_movil()]);
    rt04b1a_indexar(true, $cliente->id);
    $cliente->update(['phone' => $nuevo = rt04b1a_movil()]);

    expect(rt04b1a_indexar(false, $cliente->id)[$cliente->id]->resultado)->toBe(Resultado::DIVERGENTE)
        ->and(rt04b1a_indexar(true, $cliente->id)[$cliente->id]->resultado)->toBe(Resultado::REINDEXADO);

    expect(TelefonoCliente::query()->where('customer_id', $cliente->id)->sole()->telefono_normalizado)->toBe('+57'.$nuevo)
        ->and($cliente->fresh()->phone)->toBe($nuevo);
});

test('ficha que queda sin telefono: el principal huerfano se reporta y se retira al persistir', function () {
    $cliente = Customer::factory()->create(['phone' => rt04b1a_movil()]);
    rt04b1a_indexar(true, $cliente->id);
    $cliente->update(['phone' => null]);

    $r = rt04b1a_indexar(true, $cliente->id);

    expect($r[$cliente->id]->resultado)->toBe(Resultado::REINDEXADO)
        ->and($r[$cliente->id]->motivo)->toBe('ficha_sin_telefono')
        ->and(TelefonoCliente::query()->where('customer_id', $cliente->id)->count())->toBe(0);
});

test('comando: dry-run por defecto, telefono enmascarado y sin escrituras', function () {
    $cliente = Customer::factory()->create(['phone' => $movil = rt04b1a_movil()]);

    $this->artisan('ventas-asesor:indexar-telefonos', ['--customer' => $cliente->id])
        ->expectsOutputToContain('MODO DRY-RUN')
        ->expectsOutputToContain('***'.substr($movil, -4))
        ->doesntExpectOutputToContain($movil)
        ->assertExitCode(0);

    expect(TelefonoCliente::query()->where('customer_id', $cliente->id)->count())->toBe(0);
});

test('comando: --persistir escribe; --dry-run y --persistir juntos se rechazan', function () {
    $cliente = Customer::factory()->create(['phone' => rt04b1a_movil()]);

    $this->artisan('ventas-asesor:indexar-telefonos', ['--customer' => $cliente->id, '--persistir' => true])
        ->expectsOutputToContain('MODO PERSISTENTE')->assertExitCode(0);

    $this->artisan('ventas-asesor:indexar-telefonos', ['--dry-run' => true, '--persistir' => true])->assertExitCode(2);

    expect(TelefonoCliente::query()->where('customer_id', $cliente->id)->count())->toBe(1);
});
