// Recogida con pago pendiente (bodega): el drawer exige la instruccion de cobro (F6). env: PEDIDO.
module.exports = async (page, h) => {
    const id = process.env.PEDIDO;
    const r = {};
    await page.goto(h.base + '/admin/logistica/despachos', { waitUntil: 'domcontentloaded' });
    await page.waitForSelector(`[data-logistica-despacho][data-pedido="${id}"]`, { timeout: 30000 });
    await page.locator(`[data-logistica-despacho][data-pedido="${id}"]`).first().evaluate((e) => e.click());
    await page.waitForSelector('[data-logistica="disposicion-cobro"]', { timeout: 60000 });
    r.panel = (await page.locator('[data-logistica="disposicion-cobro"]').innerText()).replace(/\s+/g, ' ').slice(0, 500);
    r.guardar_habilitado_sin_decision = await page.locator('[data-logistica="guardar"]').isEnabled();
    await h.shot('1_decision_requerida');

    // Bodega no puede dar credito: la opcion aparece deshabilitada con su explicacion.
    r.credito_deshabilitado = await page.locator('[data-logistica="disposicion-credito"]').isDisabled();
    await page.locator('[data-logistica="disposicion-cobrar"]').check();
    await page.click('[data-logistica="disposicion-guardar"]');
    await page.waitForTimeout(3500);
    r.tras_decidir = (await page.locator('[data-logistica="disposicion-vigente"]').innerText().catch(() => 'sin mensaje')).replace(/\s+/g, ' ');
    r.guardar_habilitado_con_decision = await page.locator('[data-logistica="guardar"]').isEnabled();
    await h.shot('2_decision_registrada');
    r.desbordamiento_px = await h.overflow();

    return r;
};
