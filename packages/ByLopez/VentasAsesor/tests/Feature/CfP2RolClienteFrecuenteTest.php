<?php

use ByLopez\VentasAsesor\Support\Autoservicio\RolClienteFrecuente;
use Tests\TestCase;
use Webkul\User\Models\Role;

uses(TestCase::class);

/* P2 (doc. 33 §5): el rol de autoservicio es seguro, idempotente y no reutiliza roles de QA con nombre parecido. */

beforeEach(function () {
    Role::query()->where('name', RolClienteFrecuente::NOMBRE)->delete();
});

test('P2 asegurar crea el rol con SOLO los permisos de autoservicio y es repetible', function () {
    $servicio = app(RolClienteFrecuente::class);

    $a = $servicio->asegurar();
    $b = $servicio->asegurar();

    expect($b->id)->toBe($a->id)
        ->and(Role::query()->where('name', RolClienteFrecuente::NOMBRE)->count())->toBe(1)
        ->and($a->permission_type)->toBe('custom')
        ->and($a->permissions)->toEqualCanonicalizing(RolClienteFrecuente::PERMISOS)
        ->and($servicio->esSeguro($a))->toBeTrue();
});

test('P2 el rol no incluye ninguna clave administrativa ni all', function () {
    $rol = app(RolClienteFrecuente::class)->asegurar();

    foreach ($rol->permissions as $clave) {
        expect($clave)->toStartWith('autoservicio');
    }
    expect($rol->permission_type)->not->toBe('all');
});

test('P2 un rol de QA con nombre parecido (all o catalogo) nunca se acepta como seguro', function () {
    $servicio = app(RolClienteFrecuente::class);
    $qa1 = Role::factory()->create(['name' => 'CLIENTE FRECUENTE QA '.uniqid(), 'permission_type' => 'custom', 'permissions' => ['catalog.products', 'catalog.products.delete']]);
    $qa2 = Role::factory()->create(['name' => 'CLIENTE FRECUENTE QA '.uniqid(), 'permission_type' => 'all', 'permissions' => null]);

    expect($servicio->esSeguro($qa1))->toBeFalse()->and($servicio->esSeguro($qa2))->toBeFalse();
});

test('P2 un rol con el nombre exacto pero permisos distintos se rechaza y NO se modifica', function () {
    $malo = Role::factory()->create(['name' => RolClienteFrecuente::NOMBRE, 'permission_type' => 'custom', 'permissions' => ['autoservicio', 'catalog.products.delete']]);

    expect(fn () => app(RolClienteFrecuente::class)->asegurar())->toThrow(RuntimeException::class);
    expect(Role::query()->find($malo->id)->permissions)->toEqual(['autoservicio', 'catalog.products.delete']);
});

test('P2 un rol seguro con un permiso extra deja de ser seguro', function () {
    $rol = app(RolClienteFrecuente::class)->asegurar();
    $rol->permissions = array_merge($rol->permissions, ['sales.orders']);

    expect(app(RolClienteFrecuente::class)->esSeguro($rol))->toBeFalse();
});

test('P2 el comando sin --aplicar no crea nada y con --aplicar crea el rol una sola vez', function () {
    $this->artisan('ventas-asesor:rol-cliente-frecuente')->assertSuccessful();
    expect(Role::query()->where('name', RolClienteFrecuente::NOMBRE)->exists())->toBeFalse();

    $this->artisan('ventas-asesor:rol-cliente-frecuente', ['--aplicar' => true])->assertSuccessful();
    $this->artisan('ventas-asesor:rol-cliente-frecuente', ['--aplicar' => true])->assertSuccessful();
    expect(Role::query()->where('name', RolClienteFrecuente::NOMBRE)->count())->toBe(1);
});
