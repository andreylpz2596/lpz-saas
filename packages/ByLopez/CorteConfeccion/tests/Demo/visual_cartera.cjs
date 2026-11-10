// Revision visual y funcional de la CARTERA contra la DEMO aislada (Chromium o WebKit con perfil iPhone).
// Uso: DEMO_CC_PASSWORD=... DEMO_CARTERA='{"taller":1535,"orden_a":743}' BASE_URL=http://127.0.0.1:8030 MOTOR=chromium|webkit ANCHO=1440|390 \
//      PLAYWRIGHT_CORE=<ruta> node visual_cartera.cjs [salida]
// Emulacion: no equivale a Safari/iPhone fisico (teclado virtual y gesto tactil real).
const fs = require('fs');
const path = require('path');
const pw = require(process.env.PLAYWRIGHT_CORE || 'playwright-core');

const base = process.env.BASE_URL || 'http://127.0.0.1:8030';
const ids = JSON.parse(process.env.DEMO_CARTERA || '{}');
const motor = process.env.MOTOR || 'chromium';
const ancho = parseInt(process.env.ANCHO || '1440', 10);
const salida = path.resolve(process.argv[2] || 'capturas_cartera');
fs.mkdirSync(salida, { recursive: true });

const pasos = [];
const registrar = (nombre, ok, detalle) => { pasos.push({ nombre, ok, detalle }); console.log(`${ok ? 'OK   ' : 'FALLA'} ${nombre} ${detalle !== undefined ? JSON.stringify(detalle) : ''}`); };

(async () => {
    const movil = ancho < 600;
    let browser;

    if (motor === 'webkit') {
        browser = await pw.webkit.launch();
    } else {
        browser = await pw.chromium.launch({ executablePath: process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
    }

    // Un contexto (sesion) por usuario: el prestador no comparte cookies con el administrador.
    const nuevoContexto = () => (motor === 'webkit'
        ? browser.newContext({ ...pw.devices['iPhone 15 Pro Max'], locale: 'es-CO' })
        : browser.newContext({ viewport: { width: ancho, height: movil ? 844 : 900 }, locale: 'es-CO', isMobile: movil, hasTouch: movil }));
    let contexto = await nuevoContexto();

    const login = async (page, correo) => {
        await page.goto(base + '/admin/login', { waitUntil: 'domcontentloaded', timeout: 90000 });
        await page.waitForSelector('form button', { timeout: 90000 });
        await page.fill('input[name=email]', correo);
        await page.fill('input[name=password]', process.env.DEMO_CC_PASSWORD || '');
        await page.click('form button');
        await page.waitForURL((u) => !u.pathname.endsWith('/admin/login'), { timeout: 90000 });
        await page.waitForTimeout(4000); // deja terminar las peticiones del panel antes de navegar
    };
    const desborde = (page) => page.evaluate(() => Math.max(0, document.documentElement.scrollWidth - document.documentElement.clientWidth));
    const ir = async (page, ruta, nombre) => {
        const r = await page.goto(base + ruta, { waitUntil: 'domcontentloaded', timeout: 90000 });
        await page.waitForTimeout(1800);
        await page.screenshot({ path: path.join(salida, `${nombre}__${motor}_${ancho}.png`), fullPage: false });
        return r;
    };

    // ---------- Administrador ----------
    let page = await contexto.newPage();
    await login(page, 'cc.admin@bylopez.test');
    const errores = [];
    page.on('pageerror', (e) => { if (!/_boost/.test(String(e))) { errores.push(String(e).slice(0, 220)); } });
    page.on('response', (r) => { if (r.status() >= 500) { errores.push(`HTTP ${r.status()} ${r.url().replace(base, '')}`); } });

    let r = await ir(page, '/admin/corte-confeccion/cartera', 'cartera-listado');
    registrar('listado de cartera abre (200) sin desbordamiento', r.status() === 200 && (await desborde(page)) === 0, { px: await desborde(page) });

    r = await ir(page, `/admin/corte-confeccion/cartera/taller/${ids.taller}`, 'cuenta-admin');
    registrar('cuenta del satélite abre (200) sin desbordamiento horizontal', r.status() === 200 && (await desborde(page)) === 0, { px: await desborde(page) });
    const texto = await page.locator('body').innerText();
    registrar('muestra «Faltaría por pagar» $ 945.000 y $ 800.000 por lo recibido', texto.includes('$ 945.000') && texto.includes('$ 800.000'), { nota: 'solo vale con datos recien sembrados' });
    registrar('muestra Recibido por bodega y Pendiente de recibir', texto.includes('Recibido por bodega') && texto.includes('Pendiente de recibir'));

    await page.locator('details summary', { hasText: 'Tocar para ver la cuenta' }).first().click();
    await page.waitForTimeout(300);
    await page.screenshot({ path: path.join(salida, `cuenta-detalle__${motor}_${ancho}.png`) });
    registrar('al tocar la cifra se ve la cuenta (trabajo recibido, abonos)', (await page.locator('body').innerText()).includes('Trabajo recibido por bodega'));

    // Scroll de la pagina
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.evaluate(() => window.scrollBy(0, 400));
    registrar('la cuenta se desplaza', (await page.evaluate(() => Math.round(window.scrollY))) > 0);

    // ---------- Modal Registrar movimiento ----------
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.locator('[data-c-abrir="movimiento"]').first().click();
    await page.waitForSelector('#c-modal-movimiento:not(.hidden)', { timeout: 15000 });
    registrar('«Registrar movimiento» abre el modal', true);
    await page.screenshot({ path: path.join(salida, `modal-registrar__${motor}_${ancho}.png`) });

    const tamanos = await page.evaluate(() => [...document.querySelectorAll('#c-modal-movimiento input:not([type=radio]):not([type=checkbox]):not([type=file]), #c-modal-movimiento select, #c-modal-movimiento textarea')].filter((n) => n.offsetParent !== null).map((n) => parseFloat(getComputedStyle(n).fontSize)));
    registrar('campos visibles del modal con >= 16 px en móvil (sin zoom de Safari)', !movil || tamanos.every((t) => t >= 16), { minimo: Math.min(...tamanos) });

    // Consolidado con detalle: una operación manual
    await page.check('#c-modal-movimiento input[name="c-modo"][value="detalle"]');
    await page.click('#c-modal-movimiento [data-c-agregar-op]');
    const fila = page.locator('#c-modal-movimiento [data-c="ops"] [data-uid]').first();
    await fila.locator('[data-f="banco_destino"]').fill('Nequi');
    await fila.locator('[data-f="referencia"]').fill('VIS-' + Date.now().toString().slice(-6));
    // Importe poco comun: una operacion sin referencia del mismo importe y fecha ya registrada saldria como «posible duplicado».
    const monto = 20000 + Math.floor(Math.random() * 900) * 10 + 7;
    const montoTxt = monto.toLocaleString('es-CO');
    await fila.locator('[data-f="valor"]').fill(String(monto));
    await fila.locator('[data-f="valor"]').blur();
    await page.waitForTimeout(300);
    const valorModal = await page.inputValue('#c-modal-movimiento [data-c="valor"]');
    registrar('el total del modal sigue la suma de las operaciones incluidas', valorModal.includes(montoTxt), { valor: valorModal });
    await page.screenshot({ path: path.join(salida, `modal-detalle__${motor}_${ancho}.png`) });

    // Scroll del modal
    const sm = await page.evaluate(() => { const m = document.getElementById('c-modal-movimiento'); m.scrollBy(0, 500); return Math.round(m.scrollTop); });
    registrar('el modal se desplaza', sm > 0, { scrollTop: sm });

    // Envío real
    const [resp] = await Promise.all([
        page.waitForResponse((x) => x.url().includes('/movimientos') && x.request().method() === 'POST', { timeout: 60000 }),
        page.click('#c-modal-movimiento [data-c-enviar-movimiento]'),
    ]);
    registrar('registrar un abono con detalle responde 200', resp.status() === 200, resp.status() === 200 ? { status: 200 } : { status: resp.status(), errores: (await page.locator('#c-modal-movimiento [data-c="errores"]').innerText().catch(() => '')).slice(0, 300) });
    await page.waitForLoadState('domcontentloaded').catch(() => {});
    await page.waitForTimeout(2500);
    registrar('el historial muestra el nuevo abono', (await page.locator('body').innerText()).includes('1 registro · 1 transferencia'));
    await page.screenshot({ path: path.join(salida, `historial__${motor}_${ancho}.png`) });

    // ---------- Corte de cuentas ----------
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.locator('[data-c-abrir="corte"]').first().click();
    await page.waitForSelector('#c-modal-corte:not(.hidden)', { timeout: 15000 });
    await page.waitForFunction(() => document.querySelector('#c-modal-corte [data-c="corte-resumen"]').innerText.includes('Queda por pagar'), null, { timeout: 20000 });
    await page.fill('#c-corte-descuento', '500000');
    await page.locator('#c-corte-descuento').blur();
    await page.waitForFunction(() => document.querySelector('#c-modal-corte [data-c="corte-resumen"]').innerText.includes('500.000'), null, { timeout: 20000 });
    await page.waitForTimeout(500);
    await page.screenshot({ path: path.join(salida, `modal-corte__${motor}_${ancho}.png`) });
    const resumen = await page.locator('#c-modal-corte [data-c="corte-resumen"]').innerText();
    registrar('el corte muestra descuento de $ 500.000 y queda por pagar', resumen.includes('500.000') && resumen.includes('Queda por pagar'), { resumen: resumen.replace(/\s+/g, ' ').slice(0, 220) });
    registrar('el corte avisa que no registra transferencia', (await page.locator('#c-modal-corte').innerText()).includes('no registra ninguna transferencia'));
    await page.click('#c-modal-corte [data-c-cerrar] >> nth=1').catch(() => page.keyboard.press('Escape'));
    await page.waitForTimeout(300);
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.evaluate(() => window.scrollBy(0, 300));
    registrar('tras cerrar el modal la página sigue desplazándose', (await page.evaluate(() => Math.round(window.scrollY))) > 0);

    // ---------- Ficha y orden ----------
    r = await ir(page, `/admin/corte-confeccion/talleres/view/${ids.taller}`, 'ficha-taller');
    registrar('la ficha del taller muestra la cuenta (resumen) y el enlace completo', (await page.locator('body').innerText()).includes('Ver cuenta completa'), { desborde: await desborde(page) });
    r = await ir(page, `/admin/corte-confeccion/ordenes-confeccion/view/${ids.orden_a}`, 'orden-confeccion');
    registrar('la orden muestra «Cuenta de esta orden»', (await page.locator('body').innerText()).includes('Cuenta de esta orden'), { desborde: await desborde(page) });
    await contexto.close();

    // ---------- Prestador (solo consulta) ----------
    contexto = await nuevoContexto();
    page = await contexto.newPage();
    await login(page, 'cc.jairo@bylopez.test');
    page.on('pageerror', (e) => { if (!/_boost/.test(String(e))) { errores.push('prestador: ' + String(e).slice(0, 200)); } });
    r = await ir(page, '/admin/corte-confeccion/cartera/mi-cuenta', 'mi-cuenta');
    const t2 = await page.locator('body').innerText();
    registrar('Mi cuenta (prestador) abre, es de consulta y sin botones de registro', r.status() === 200 && t2.includes('solo de consulta') && !t2.includes('Registrar movimiento') && !t2.includes('Hacer corte de cuentas'), { desborde: await desborde(page) });
    const ajena = await page.goto(`${base}/admin/corte-confeccion/cartera/taller/1`, { waitUntil: 'domcontentloaded' });
    registrar('el prestador no abre la cuenta de otra persona', [401, 403].includes(ajena.status()), { status: ajena.status() });

    registrar('sin errores de JavaScript ni HTTP 5xx', errores.length === 0, errores);
    await browser.close();
    fs.writeFileSync(path.join(salida, `resultado_${motor}_${ancho}.json`), JSON.stringify(pasos, null, 2));
    process.exit(pasos.every((p) => p.ok) ? 0 : 1);
})().catch((e) => { console.error('ERROR del recorrido:', String(e).slice(0, 600)); process.exit(2); });
