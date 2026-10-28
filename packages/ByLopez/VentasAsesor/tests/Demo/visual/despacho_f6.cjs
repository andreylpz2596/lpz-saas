// F6 despues de una devolucion recibida (bodega): el drawer de Despachos muestra que la decision anterior ya no vale y pide decidir otra vez.
// env: PEDIDO (preparado por `recorridos.php V`: transferencia de 10, 6 despachadas, 2 devueltas y recibidas, 4 pendientes).
module.exports = async (page, h) => {
    const id = process.env.PEDIDO;
    const r = {};
    await page.goto(h.base + '/admin/logistica/despachos', { waitUntil: 'domcontentloaded' });
    await page.locator(`[data-logistica-despacho][data-pedido="${id}"]`).locator('visible=true').first().waitFor({ timeout: 30000 });
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(2500);
    await page.locator(`[data-logistica-despacho][data-pedido="${id}"]`).locator('visible=true').first().evaluate((e) => e.click());
    await page.waitForSelector('[data-logistica="disposicion-cobro"]', { timeout: 60000 });
    r.panel = (await page.locator('[data-logistica="disposicion-cobro"]').innerText()).replace(/\s+/g, ' ').slice(0, 600);
    r.anterior_visible = await page.locator('[data-logistica="disposicion-anterior"]').count();
    r.anterior = r.anterior_visible ? (await page.locator('[data-logistica="disposicion-anterior"]').innerText()).replace(/\s+/g, ' ') : null;
    r.guardar_habilitado_sin_nueva_decision = await page.locator('[data-logistica="guardar"]').isEnabled();
    await h.shot('1_decision_anterior_invalidada');

    // La nueva decision (cobrar al entregar) habilita el despacho de las 4 pendientes; no se despacha aqui (solo se muestra el estado).
    await page.locator('[data-logistica="disposicion-cobrar"]').check();
    await page.click('[data-logistica="disposicion-guardar"]');
    await page.waitForTimeout(3500);
    r.tras_decidir = (await page.locator('[data-logistica="disposicion-vigente"]').innerText().catch(() => 'sin mensaje')).replace(/\s+/g, ' ');
    r.guardar_habilitado_con_nueva_decision = await page.locator('[data-logistica="guardar"]').isEnabled();
    await h.shot('2_nueva_decision_registrada');
    r.desbordamiento_px = await h.overflow();

    return r;
};
