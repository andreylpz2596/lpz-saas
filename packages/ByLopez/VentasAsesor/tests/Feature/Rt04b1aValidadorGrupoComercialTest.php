<?php

use ByLopez\VentasAsesor\Exceptions\GrupoComercialInvalidoException as Invalido;
use ByLopez\VentasAsesor\Support\Clientes\ValidadorGrupoComercial;
use Tests\TestCase;
use Webkul\Customer\Models\CustomerGroup;

uses(TestCase::class);

/*
| RT-04B1a — ValidadorGrupoComercial (D-RT04-04). Solo lpz_saas_testing.
*/

function rt04b1a_codigoDe(callable $accion): ?string
{
    try {
        $accion();
    } catch (Invalido $e) {
        return $e->codigo;
    }

    return null;
}

/** Core cachea el grupo invitado en su singleton; se limpia para ver el estado actual de la base. */
function rt04b1a_olvidarInvitadoCacheado(): void
{
    (fn () => $this->guestCustomerGroup = null)->call(core());
}

beforeEach(function () {
    $this->validador = app(ValidadorGrupoComercial::class);
});

test('un grupo existente distinto del invitado es valido y se devuelve', function () {
    $grupo = CustomerGroup::factory()->create(['code' => 'rt04b1a_'.uniqid(), 'name' => 'RT04B1a', 'is_user_defined' => 1]);

    expect($this->validador->validar($grupo->id)->id)->toBe($grupo->id);
});

test('grupo NULL, cero o negativo: grupo_requerido (sin valor por defecto)', function (?int $id) {
    expect(rt04b1a_codigoDe(fn () => $this->validador->validar($id)))->toBe(Invalido::GRUPO_REQUERIDO);
})->with([null, 0, -1]);

test('grupo inexistente: grupo_inexistente', function () {
    $inexistente = (int) CustomerGroup::query()->max('id') + 1000;

    expect(rt04b1a_codigoDe(fn () => $this->validador->validar($inexistente)))->toBe(Invalido::GRUPO_INEXISTENTE);
});

test('el invitado se rechaza identificandolo como el core (code guest), no por su nombre visible', function () {
    $invitado = core()->getGuestCustomerGroup();

    // El nombre visible no importa: en desarrollo se llama "Invitado".
    $invitado->update(['name' => 'Nombre visible cualquiera']);

    expect($invitado->code)->toBe('guest')
        ->and(rt04b1a_codigoDe(fn () => $this->validador->validar($invitado->id)))->toBe(Invalido::GRUPO_INVITADO_NO_PERMITIDO);
});

test('un grupo llamado "Guest" pero con otro code NO es el invitado del core', function () {
    $impostor = CustomerGroup::factory()->create(['code' => 'rt04b1a_guest_'.uniqid(), 'name' => 'Guest', 'is_user_defined' => 1]);

    expect($this->validador->validar($impostor->id)->id)->toBe($impostor->id);
});

test('si el core no encuentra su grupo invitado, se falla cerrado: invitado_no_identificable', function () {
    $grupo = CustomerGroup::factory()->create(['code' => 'rt04b1a_'.uniqid(), 'name' => 'RT04B1a', 'is_user_defined' => 1]);
    CustomerGroup::query()->where('code', 'guest')->update(['code' => 'guest_renombrado_rt04b1a']);
    rt04b1a_olvidarInvitadoCacheado();

    try {
        expect(rt04b1a_codigoDe(fn () => $this->validador->validar($grupo->id)))->toBe(Invalido::INVITADO_NO_IDENTIFICABLE);
    } finally {
        rt04b1a_olvidarInvitadoCacheado();
    }
});
