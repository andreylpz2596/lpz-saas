// Doc. 34 · HUMO del dictado sobre la version INSTALADA (pruebas.alpz.com.co o la demo local), con autenticacion real.
// Uso: DEMO_BASE=https://pruebas.alpz.com.co DEMO_CLAVE=<archivo fuera del repo> EVID=<carpeta> PREFIJO=P node navegador_humo.cjs
const c = require('./comun.cjs');

const PREFIJO = process.env.PREFIJO || 'H';

(async () => {
    const pasos = [];
    const paso = (nombre, ok, detalle = null) => { pasos.push({ paso: nombre, ok: !! ok, detalle }); console.log((ok ? 'OK  ' : 'FALLA ') + nombre + (detalle && ! ok ? ' · ' + JSON.stringify(detalle).slice(0, 400) : '')); };
    let navegador;
    const L = (i, sel) => c.enLinea(i, sel);

    try {
        const a = await c.abrir({ ancho: 1366, alto: 900 });
        navegador = a.browser;
        const page = a.page;
        paso('login con la cuenta de QA (autenticacion real) en ' + c.BASE, await c.login(page));
        await c.ventaConCliente(page);
        paso('cliente de QA elegido y panel «Dictar pedido» abierto', true);

        let ls = await c.interpretar(page, 'dos rojas M de dama, negro caballero L 3, rojo 10 niño, crop top negro 2', 4);
        paso('orden libre: «dos rojas M de dama» → Dama · Rojo · M · 2 (110D-DZ-M)', ls[0].estado === 'ok' && /110D-DZ-M/.test(ls[0].texto), ls[0]);
        paso('«negro caballero L 3» → 106H-DZ-L', ls[1].estado === 'ok' && /106H-DZ-L/.test(ls[1].texto), ls[1]);
        paso('«rojo 10 niño» → pregunta talla o cantidad con botones', /Talla 10-12/.test(ls[2].texto) && /10 unidades/.test(ls[2].texto), ls[2]);
        paso('crop top Negro sin talla → pregunta M o Única', /Talla M/.test(ls[3].texto) && /Talla Única/.test(ls[3].texto), ls[3]);
        await c.foto(page, PREFIJO + '1_interpretado_escritorio.png', true);

        await c.clicTexto(page, 'button', /^Talla 10-12$/, 10000, '[data-dictado-linea="2"]');
        await page.waitForSelector(L(2, '[data-venta="dictado-editor"]'));
        await c.escribir(page, L(2, '[data-venta="dictado-cantidad"]'), '1');
        await page.click(L(2, '[data-venta="dictado-validar"]'));
        await c.esperarEstado(page, 2, ['ok', 'aviso']);
        await c.clicTexto(page, 'button', /^Talla Única$/, 10000, '[data-dictado-linea="3"]');
        await c.esperarEstado(page, 3, ['ok', 'aviso']);
        ls = await c.lineas(page);
        paso('dudas resueltas con los botones: 110N-DZ-10 × 1 y 106D-TP-U × 2', /110N-DZ-10/.test(ls[2].texto) && /106D-TP-U/.test(ls[3].texto), ls);

        await page.click('[data-venta="dictado-agregar"]');
        await page.waitForSelector('[data-venta="dictado-hecho"]', { timeout: 60000 });
        const hecho = await page.$eval('[data-venta="dictado-hecho"]', (x) => x.innerText);
        paso('agregado: el servidor confirma lo aplicado (había 0 + 2 = quedan 2 de 110D-DZ-M)', /110D-DZ-M: había 0 \+ 2 = quedan 2/.test(hecho), hecho);
        const venta = await page.$$eval('[data-resumen-producto]', (ns) => ns.map((x) => x.innerText.replace(/\s+/g, ' ').trim()));
        paso('el resumen de la venta tiene las 4 referencias', venta.length >= 4, venta);
        await c.foto(page, PREFIJO + '2_agregado_escritorio.png', true);

        await c.interpretar(page, 'una roja M de dama', 1);
        const efecto = await page.$eval('[data-venta="dictado-efectos"]', (x) => x.innerText);
        paso('variante ya presente: «ya hay 2 + dictadas 1 = quedarán 3»', /110D-DZ-M: ya hay 2 \+ dictadas 1 = quedarán 3/.test(efecto), efecto);
        await page.click('[data-venta="dictado-agregar"]');
        await page.waitForSelector('[data-venta="dictado-hecho"]', { timeout: 60000 });
        paso('agregado: había 2 + 1 = quedan 3', /había 2 \+ 1 = quedan 3/.test(await page.$eval('[data-venta="dictado-hecho"]', (x) => x.innerText)));
        paso('sin errores de JavaScript (escritorio)', a.errores.length === 0, a.errores);
        await navegador.close();

        const m = await c.abrir({ ancho: 390, alto: 844, movil: true });
        navegador = m.browser;
        await c.login(m.page);
        await c.ventaConCliente(m.page);
        await c.interpretar(m.page, 'dos rojas M de dama, rojo 10 niño', 2);
        const ancho = await m.page.evaluate(() => [document.documentElement.scrollWidth, window.innerWidth]);
        paso('movil 390 px: sin desborde horizontal', ancho[0] <= ancho[1], ancho);
        await c.foto(m.page, PREFIJO + '3_movil_390.png');
        await c.modoOscuro(m.page, true);
        await c.foto(m.page, PREFIJO + '4_movil_390_oscuro.png');
        paso('sin errores de JavaScript (movil)', m.errores.length === 0, m.errores);
    } catch (e) {
        paso('EXCEPCION', false, String(e && e.stack || e).slice(0, 600));
    } finally {
        if (navegador) {
            await navegador.close().catch(() => {});
        }
        const fallas = pasos.filter((p) => ! p.ok).length;
        console.log(JSON.stringify({ guion: 'navegador_humo', base: c.BASE, pasos: pasos.length, fallas }));
        process.exit(fallas ? 1 : 0);
    }
})();
