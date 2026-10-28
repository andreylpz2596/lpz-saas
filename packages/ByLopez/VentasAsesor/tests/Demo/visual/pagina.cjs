// Captura generica de una pantalla del panel. env: PAGINA (ruta, p. ej. /admin/ventas-asesor/comisiones), PASO (nombre de la captura).
// Informa desbordamiento horizontal (debe ser 0), errores de consola y el texto visible principal para detectar pantallas vacias o con error.
module.exports = async (page, h) => {
    const ruta = process.env.PAGINA;
    const respuesta = await page.goto(h.base + ruta, { waitUntil: 'domcontentloaded' });
    await page.waitForLoadState('networkidle', { timeout: 30000 }).catch(() => {});
    await page.waitForTimeout(1500);
    await h.shot(process.env.PASO || 'pagina', true);

    return {
        ruta,
        http: respuesta ? respuesta.status() : null,
        desbordamiento_horizontal_px: await h.overflow(),
        oscuro: await page.evaluate(() => document.documentElement.classList.contains('dark')),
        titulo: (await page.title()).slice(0, 80),
        texto: (await page.locator('main, body').first().innerText()).replace(/\s+/g, ' ').slice(0, 220),
    };
};
