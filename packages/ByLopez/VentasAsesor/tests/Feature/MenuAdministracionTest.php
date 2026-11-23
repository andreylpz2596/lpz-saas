<?php

use Tests\TestCase;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

uses(TestCase::class);

/*
| 2026-11-22 (Camilo): menu «Administracion» (Costos y precios, Rentabilidad, Datos de cotizacion) SOLO del administrador.
*/

beforeEach(fn () => config()->set('ventasasesor.credito.rol_administrador_id', 1));

function menuadm_html(object $test, Admin $actor): string
{
    return $test->actingAs($actor, 'admin')->get(route('admin.ventas_asesor.inicio.index'))->getContent();
}

test('el administrador ve «Administracion» con sus tres opciones y ya no estan en Ventas Asesor', function () {
    $html = menuadm_html($this, Admin::factory()->create(['role_id' => 1]));

    expect($html)->toContain(trans('ventasasesor::app.menu.administracion'))
        ->and($html)->toContain('href="'.route('admin.ventas_asesor.costos.index').'"')
        ->and($html)->toContain('href="'.route('admin.ventas_asesor.rentabilidad.index').'"')
        ->and($html)->toContain('href="'.route('admin.ventas_asesor.nueva_venta.cotizaciones.datos').'"');
});

test('ni otro rol `all` ni un rol con permisos de costos y rentabilidad ven el menu', function () {
    $otroAll = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'all', 'permissions' => null])->id]);
    $custom = Admin::factory()->create(['role_id' => Role::factory()->create(['permission_type' => 'custom', 'permissions' => [
        'ventas_asesor', 'ventas_asesor.operar_como_asesor', 'ventas_asesor.costos', 'ventas_asesor.rentabilidad', 'ventas_asesor.rentabilidad.view', 'ventas_asesor.cotizaciones_datos',
    ]])->id]);

    foreach ([$otroAll, $custom] as $actor) {
        $html = menuadm_html($this, $actor);

        expect($html)->not->toContain(trans('ventasasesor::app.menu.administracion'))
            ->and($html)->not->toContain('href="'.route('admin.ventas_asesor.costos.index').'"')
            ->and($html)->not->toContain('href="'.route('admin.ventas_asesor.rentabilidad.index').'"');
    }
});
