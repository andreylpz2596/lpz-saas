// Cancelar lo pendiente desde el drawer de Despachos (administrador). env: PEDIDO.
// Pasos: abrir el drawer → ver el panel → ESCRIBIR el motivo → Cancelar el modal (nada se guarda) → reabrir → guardar con un 500 SIMULADO
// por el navegador (el servidor no llega a ejecutar) → el formulario conserva lo escrito y muestra el error → reintentar de verdad.
module.exports = async (page, h) => {
    const id = process.env.PEDIDO;
    const r = {};
    await page.goto(h.base + '/admin/logistica/despachos', { waitUntil: 'domcontentloaded' });
    await page.locator(`[data-logistica-despacho][data-pedido="${id}"]`).locator("visible=true").first().waitFor({ timeout: 30000 });
    await page.waitForLoadState("networkidle").catch(() => {});
    await page.waitForTimeout(2500);
    const abrir = async () => {
        await page.locator(`[data-logistica-despacho][data-pedido="${id}"]`).locator("visible=true").first().evaluate((e) => e.click());
        await page.waitForSelector('[data-logistica="cancelar-pendiente"]', { timeout: 60000 });
        await page.locator('[data-logistica="cancelar-pendiente"] summary').click();
        await page.waitForSelector('[data-logistica="cancelacion-motivo"]');
    };

    await abrir();
    r.panel = (await page.locator('[data-logistica="cancelar-pendiente"]').innerText()).replace(/\s+/g, ' ').slice(0, 400);
    await h.shot('1_panel_abierto');

    // Modal: Cancelar sin guardar → al reabrir no hay nada escrito ni cancelado.
    await page.fill('[data-logistica="cancelacion-motivo"]', 'Texto que se descarta');
    await page.locator('button:has-text("Cancelar")').filter({ hasNotText: 'pendiente' }).first().click();
    await page.waitForTimeout(800);
    await abrir();
    r.motivo_tras_reabrir = await page.inputValue('[data-logistica="cancelacion-motivo"]');
    r.reabre_vacio = r.motivo_tras_reabrir === '';

    // 500 simulado en el NAVEGADOR (el servidor no recibe la peticion): el formulario conserva el texto y muestra el error.
    await page.route('**/cancelar-pendiente', (ruta) => ruta.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ ok: false, codigo: 'error_inesperado', mensaje: 'No pudimos completar la operación por un error inesperado. Referencia: SIMULADO.' }) }));
    await page.fill('[data-logistica="cancelacion-motivo"]', 'DEMO: el cliente desiste de las unidades pendientes');
    await page.click('[data-logistica="cancelacion-confirmar"]');
    await page.waitForSelector('[data-logistica="cancelacion-error"]', { timeout: 10000 });
    r.error_visible = (await page.locator('[data-logistica="cancelacion-error"]').innerText()).replace(/\s+/g, ' ');
    r.motivo_conservado = await page.inputValue('[data-logistica="cancelacion-motivo"]');
    r.boton_habilitado_tras_error = await page.locator('[data-logistica="cancelacion-confirmar"]').isEnabled();
    await h.shot('2_error_simulado_500');

    // Recuperacion: se quita la simulacion y se reintenta; ahora SI llega al servidor.
    await page.unroute('**/cancelar-pendiente');
    await page.click('[data-logistica="cancelacion-confirmar"]');
    await page.waitForTimeout(3500);
    r.panel_tras_exito = await page.locator('[data-logistica="cancelar-pendiente"]').count();
    await h.shot('3_tras_cancelar');
    r.desbordamiento_px = await h.overflow();

    return r;
};
