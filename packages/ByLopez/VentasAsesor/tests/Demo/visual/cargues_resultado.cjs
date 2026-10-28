// Cargues › Historial › abrir una carga y ver el resultado por fila (administrador). env: CARGA (id) — opcional: abre la que tenga ese # en la primera columna.
module.exports = async (page, h) => {
    const r = {};
    await page.goto(h.base + '/admin/cargues', { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle', { timeout: 30000 }).catch(() => {});
    await page.waitForTimeout(1500);
    await page.getByRole('tab', { name: /historial/i }).click();
    await page.waitForSelector('table tbody tr td', { timeout: 20000 });
    await h.shot('1_historial', true);
    const fila = process.env.CARGA
        ? page.locator('tbody tr', { has: page.locator('td', { hasText: new RegExp('^' + process.env.CARGA + '$') }) }).first()
        : page.locator('tbody tr').first();
    await fila.locator('button.cg-link').click();
    await page.waitForTimeout(9000);
    r.texto = (await page.locator('main, body').first().innerText()).replace(/\s+/g, ' ').slice(0, 700);
    await h.shot('2_carga_abierta', true);
    r.desbordamiento_px = await h.overflow();

    return r;
};
