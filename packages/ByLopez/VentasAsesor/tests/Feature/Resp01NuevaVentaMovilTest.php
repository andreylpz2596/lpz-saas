<?php

use ByLopez\VentasAsesor\Services\Venta\BorradorVentaService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use Tests\TestCase;

require_once __DIR__.'/../helpersFase03f.php';

uses(TestCase::class);

/*
| Auditoria responsive iPhone (Doc. 36): la modal de tallas de Nueva venta en pantallas tactiles. Pest no ejecuta CSS ni
| JavaScript: verifica el marcado del que dependen los ajustes. El recorrido real (abrir tallas, escribir cantidades,
| guardar y editar) se hizo con WebKit y Chromium emulando iPhone en pruebas; no reemplaza la revision en un iPhone fisico.
*/

test('RESP-4 Nueva venta trae los ajustes moviles compartidos y la matriz tactil (teclado numerico, etiquetas con la talla)', function () {
    f03f_configurarBase();
    f03b_grupo('G');
    $asesor = f03b_admin('custom', [AutorizacionVentasAsesor::PERMISO_MODULO, 'ventas_asesor.pedidos', BorradorVentaService::PERMISO]);

    $this->actingAs($asesor, 'admin')
        ->get(route('admin.ventas_asesor.nueva_venta.pantalla'))
        ->assertOk()
        // Campos de 16 px en pantallas tactiles (sin acercamiento automatico de Safari), inyectado por Logistica.
        ->assertSee('<style data-bylopez="responsive-movil">', false)
        // Matriz: botones de 40 px de alto que caben a lo ancho (senal en su propia linea), teclado numerico y nombre
        // accesible con la talla.
        ->assertSee('class="nv-matriz w-full text-left text-sm"', false)
        ->assertSee('.nv-matriz .nv-paso { width: 36px; height: 40px;', false)
        ->assertSee('.nv-matriz .nv-senal { display: block; margin-left: 0; }', false)
        ->assertSee("{{ ' ' }}", false)
        // «Ver tallas» fijo bajo la cabecera en telefono con una clase propia (las utilidades max-lg:* no se compilan).
        ->assertSee(":class=\"tarjetasPorColor.length ? 'nv-cta-fija' : ''\"", false)
        ->assertSee('.nv-cta-fija { position: sticky; top: 60px;', false)
        ->assertDontSee('max-lg:sticky', false)
        ->assertSee('inputmode="numeric"', false)
        ->assertSee(":aria-label=\"'Cantidad de la talla ' + tallaDe(celda.variante)\"", false)
        ->assertSee(":aria-label=\"'Restar una unidad de la talla ' + tallaDe(celda.variante)\"", false)
        ->assertSee(":aria-label=\"'Sumar una unidad a la talla ' + tallaDe(celda.variante)\"", false)
        ->assertSee('aria-label="Quitar esta talla de la venta"', false)
        // El zoom manual sigue disponible.
        ->assertSee('content="width=device-width, initial-scale=1"', false)
        ->assertDontSee('maximum-scale', false)
        ->assertDontSee('user-scalable', false);
});
