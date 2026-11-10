// Ensayo previo al iPhone: el mismo recorrido que hara Camilo, en WebKit (motor de Safari) con el perfil de un iPhone Pro Max.
// WebKit movil no emula la rueda: el desplazamiento se prueba por scrollBy (comprueba que NADA lo bloquea; el gesto tactil real lo valida el iPhone).
// NO equivale a Safari/iOS fisico (teclado virtual y barra dinamica de Safari no se emulan); sirve para cazar fallos antes.
// Uso: DEMO_CC_PASSWORD=... DEMO_CC_ORDEN=708 BASE_URL=http://127.0.0.1:8030 PLAYWRIGHT_CORE=<ruta> node iphone_safari_webkit.cjs [salida]
const fs = require('fs');
const path = require('path');
const { webkit, devices } = require(process.env.PLAYWRIGHT_CORE || 'playwright-core');

const base = process.env.BASE_URL || 'http://127.0.0.1:8030';
const orden = process.env.DEMO_CC_ORDEN;
const salida = path.resolve(process.argv[2] || 'capturas_iphone');
fs.mkdirSync(salida, { recursive: true });

const pasos = [];
const registrar = (nombre, ok, detalle) => { pasos.push({ nombre, ok, detalle }); console.log(`${ok ? 'OK ' : 'FALLA'} ${nombre} ${detalle ? JSON.stringify(detalle) : ''}`); };

const login = async (page, correo) => {
    await page.goto(base + '/admin/login', { waitUntil: 'domcontentloaded', timeout: 90000 });
    await page.waitForSelector('form button', { timeout: 90000 });
    await page.fill('input[name=email]', correo);
    await page.fill('input[name=password]', process.env.DEMO_CC_PASSWORD || '');
    await page.click('form button');
    await page.waitForURL((u) => !u.pathname.endsWith('/admin/login'), { timeout: 90000 });
};

(async () => {
    const browser = await webkit.launch();
    const perfil = devices['iPhone 15 Pro Max'];
    const ficha = `${base}/admin/corte-confeccion/ordenes-confeccion/view/${orden}`;

    // 1) Usuario SIN la ACL: no hay boton.
    let ctx = await browser.newContext({ ...perfil, locale: 'es-CO' });
    let page = await ctx.newPage();
    await login(page, 'cc.bodega.sin@bylopez.test');
    await page.goto(ficha, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1200);
    registrar('sin ACL: la ficha abre y NO hay boton «Reasignar prendas»', (await page.locator('[data-reasignar-abrir]').count()) === 0);
    await ctx.close();

    // 2) Usuario CON la ACL: recorrido completo.
    ctx = await browser.newContext({ ...perfil, locale: 'es-CO' });
    page = await ctx.newPage();
    const errores = [];
    await login(page, 'cc.bodega@bylopez.test');
    await page.waitForTimeout(5000); // deja que el panel termine de cargar tras el login: sus peticiones en vuelo se cancelarian al navegar
    // El listener se registra DESPUES del login: las peticiones del panel que se cancelan al navegar no son de la ficha. Se ignora el script de Laravel Boost (herramienta de desarrollo, no del modulo).
    let recargando = false; // al confirmar la pagina se recarga y el panel cancela sus peticiones en vuelo (AxiosError: Network Error): no es de la ficha
    page.on('pageerror', (e) => { if (!/_boost/.test(String(e)) && !(recargando && /Network Error/.test(String(e)))) { errores.push(String(e).slice(0, 200)); } });
    await page.goto(ficha, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1500);
    await page.screenshot({ path: path.join(salida, '1-ficha.png') });

    const desbordamiento = await page.evaluate(() => Math.max(0, document.documentElement.scrollWidth - document.documentElement.clientWidth));
    registrar('ficha sin desbordamiento horizontal', desbordamiento === 0, { px: desbordamiento });

    // Scroll de la ficha desde el centro con rueda/gesto.
    const alto = await page.evaluate(() => document.documentElement.scrollHeight);
        await page.evaluate(() => window.scrollTo(0, 0));
    await page.evaluate(() => window.scrollBy(0, 300));
    await page.waitForTimeout(300);
    const y1 = await page.evaluate(() => Math.round(window.scrollY));
    registrar('la ficha se desplaza desde el centro antes de abrir el modal', y1 > 0, { scrollY: y1, alto });

    await page.evaluate(() => window.scrollTo(0, 0));
    await page.locator('[data-reasignar-abrir]').first().click();
    await page.waitForSelector('#reasignar-modal [data-r="contenido"]:not(.hidden)', { timeout: 30000 });
    await page.screenshot({ path: path.join(salida, '2-modal-abierto.png') });

    // Scroll del modal desde el centro.
        await page.evaluate(() => document.getElementById('reasignar-modal').scrollBy(0, 350));
    await page.waitForTimeout(300);
    const scrollModal = await page.evaluate(() => Math.round(document.getElementById('reasignar-modal').scrollTop));
    registrar('el modal se desplaza desde el centro', scrollModal > 0, { scrollTop: scrollModal });

    // Campos: 16 px o mas evita el zoom automatico de Safari al enfocar.
    const tamanos = await page.evaluate(() => [...document.querySelectorAll('#reasignar-modal input[type=number], #reasignar-modal textarea, #reasignar-modal select')].map((n) => parseFloat(getComputedStyle(n).fontSize)));
    registrar('todos los campos del modal tienen >= 16 px (sin zoom al enfocar)', tamanos.length > 0 && tamanos.every((t) => t >= 16), { campos: tamanos.length, minimo: Math.min(...tamanos) });

    // Cantidad y motivo.
    await page.selectOption('#r-destino', { index: 1 });
    const filaM = page.locator('#reasignar-modal [data-r="filas"] tr').nth(1);
    await filaM.locator('input').last().scrollIntoViewIfNeeded();
    await filaM.locator('input').last().fill('100');
    await page.fill('#r-motivo', 'Prueba iPhone: el taller origen no alcanza la fecha');
    const escala = await page.evaluate(() => (window.visualViewport ? window.visualViewport.scale : 1));
    registrar('tras enfocar y escribir, la pagina no se amplio (escala 1)', escala === 1, { escala });
    const habilitado = await page.locator('#reasignar-modal [data-r="enviar"]').isEnabled();
    registrar('con cantidad y motivo el boton «Reasignar prendas» queda habilitado', habilitado);
    await page.screenshot({ path: path.join(salida, '3-modal-lleno.png') });

    // Cerrar y volver a desplazar la ficha.
    await page.click('#reasignar-modal [data-r="cerrar"] >> nth=1');
    await page.waitForTimeout(300);
    await page.evaluate(() => window.scrollTo(0, 0));
        await page.evaluate(() => window.scrollBy(0, 300));
    await page.waitForTimeout(300);
    const y2 = await page.evaluate(() => Math.round(window.scrollY));
    registrar('tras cerrar el modal la ficha vuelve a desplazarse', y2 > 0, { scrollY: y2 });

    // Confirmar de verdad.
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.locator('[data-reasignar-abrir]').first().click();
    await page.waitForSelector('#reasignar-modal [data-r="contenido"]:not(.hidden)', { timeout: 30000 });
    await page.selectOption('#r-destino', { index: 1 });
    const fila = page.locator('#reasignar-modal [data-r="filas"] tr').nth(1);
    await fila.locator('input').last().fill('100');
    await page.fill('#r-motivo', 'Prueba iPhone: confirmacion');
    recargando = true;
    const [respuesta] = await Promise.all([
        page.waitForResponse((r) => r.url().includes('/ordenes-confeccion/reasignar/') && r.request().method() === 'POST', { timeout: 60000 }),
        page.click('#reasignar-modal [data-r="enviar"]'),
    ]);
    registrar('confirmar la reasignacion responde HTTP 200', respuesta.status() === 200, { status: respuesta.status() });
    await page.waitForSelector('#reasignaciones-historial', { timeout: 60000 }).catch(() => {});
    await page.waitForTimeout(1000);
    const historial = await page.locator('#reasignaciones-historial').count();
    registrar('el historial de reasignaciones aparece tras confirmar', historial > 0);
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.evaluate(() => window.scrollBy(0, 300));
    await page.waitForTimeout(300);
    registrar('la ficha sigue desplazandose tras confirmar', (await page.evaluate(() => Math.round(window.scrollY))) > 0);

    // Cancelar traslado (en transito con salida fisica): exige constancia + confirmacion.
    const resumen = page.locator('#reasignaciones-historial details summary', { hasText: 'Cancelar traslado' }).first();
    if (await resumen.count()) {
        await resumen.scrollIntoViewIfNeeded();
        await resumen.click();
        const formulario = resumen.locator('xpath=ancestor::details//form');
        registrar('«Cancelar traslado» pide constancia de devolucion y confirmacion fisica', (await formulario.locator('[name=constancia_devolucion]').count()) === 1 && (await formulario.locator('[name=devolucion_fisica_confirmada]').count()) === 1);
        await page.screenshot({ path: path.join(salida, '4-cancelar-traslado.png') });
    } else {
        registrar('«Cancelar traslado» visible en el historial', false);
    }

    registrar('sin errores de JavaScript en la pagina', errores.length === 0, errores);
    await ctx.close();
    await browser.close();

    fs.writeFileSync(path.join(salida, 'resultado_iphone_webkit.json'), JSON.stringify(pasos, null, 2));
    process.exit(pasos.every((p) => p.ok) ? 0 : 1);
})();
