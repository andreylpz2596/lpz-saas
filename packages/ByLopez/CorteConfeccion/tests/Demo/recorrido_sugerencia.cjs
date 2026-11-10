// Recorrido REAL de la sugerencia por stock, los minimos y la regla P1 (Doc. 09 §9-§11) contra una base demo AISLADA
// preparada con demo_prioridades.php (preparar + stock). Debugbar NO se oculta: se comprueba que ya no tapa las acciones.
// Uso: DB_DATABASE=<demo> DEMO_PRIO_PASSWORD=... DEMO_TALLER=1514 DEMO_CORTE=2792 BASE_URL=http://127.0.0.1:8031 \
//      ANCHO=1440|1024|430|390 MOTOR=chromium|webkit PLAYWRIGHT_CORE=<ruta> node recorrido_sugerencia.cjs [salida]
// Productos esperados (fijados con «stock»): URGENTE = disponible 0, ALTA = disponible 4 (minimo 10 se configura aqui),
// SIN_MINIMO = disponible 30 sin minimo. WebKit con perfil de iPhone es EMULACION, no un iPhone fisico.
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const pw = require(process.env.PLAYWRIGHT_CORE || 'playwright-core');

const base = process.env.BASE_URL || 'http://127.0.0.1:8031';
const taller = process.env.DEMO_TALLER || '1514';
const corte = process.env.DEMO_CORTE || '2792';
const P = { urgente: process.env.P_URGENTE || '6150', alta: process.env.P_ALTA || '6151', sinMinimo: process.env.P_SIN_MINIMO || '6152' };
const ancho = Number(process.env.ANCHO || 1440);
const motor = process.env.MOTOR || 'chromium';
const salida = path.resolve(process.argv[2] || 'capturas_sugerencia');
const etiqueta = `${motor}-${ancho}`;
const celular = ancho < 1024;
fs.mkdirSync(salida, { recursive: true });

const pasos = [];
const ok = (nombre, valor, detalle = '') => { pasos.push({ nombre, ok: !!valor, detalle }); console.log(`${valor ? 'OK ' : 'FALLA'} ${nombre}${detalle ? ' — ' + detalle : ''}`); };
const demo = (...args) => execFileSync('php', [path.join(__dirname, 'demo_prioridades.php'), ...args], { cwd: path.resolve(__dirname, '../../../../..') }).toString();

async function sesion(navegador, email) {
    const opciones = motor === 'webkit' && celular
        ? { ...pw.devices['iPhone 13'], locale: 'es-CO' }
        : { viewport: { width: ancho, height: celular ? 844 : 900 }, locale: 'es-CO' };
    const ctx = await navegador.newContext(opciones);
    const page = await ctx.newPage();
    page.setDefaultTimeout(90000);
    page.setDefaultNavigationTimeout(120000);
    page.on('pageerror', (e) => (/_boost|Load failed|Network Error/.test(String(e))
        ? console.log('   ruido de desarrollo:', String(e).slice(0, 120))
        : ok(`sin error JS (${email})`, false, String(e).slice(0, 200))));
    page.on('response', (r) => { if (r.status() >= 500) { ok('sin HTTP 5xx', false, `${r.status()} ${r.url()}`); } });
    await page.goto(`${base}/admin/login`, { waitUntil: 'load' });
    await page.fill('input[name=email]', email);
    await page.fill('input[name=password]', process.env.DEMO_PRIO_PASSWORD || '');
    await Promise.all([page.waitForURL((u) => !String(u).includes('/login'), { waitUntil: 'domcontentloaded' }), page.click('form button')]);
    return page;
}

async function cargar(page, url) {
    await page.goto(url, { waitUntil: 'load' });
    await page.waitForFunction(() => document.querySelector('#app')?.hasAttribute('data-v-app'), null, { timeout: 60000 });
    await page.waitForTimeout(400);
}

async function sinDesborde(page, nombre) {
    const d = await page.evaluate(() => ({ ancho: document.documentElement.scrollWidth, vista: window.innerWidth }));
    ok(`sin desplazamiento horizontal: ${nombre}`, d.ancho <= d.vista + 1, `${d.ancho}/${d.vista}`);
}

// Debugbar (solo desarrollo) debe quedar por debajo: el centro del elemento debe ser el propio elemento.
async function noTapado(page, selector, nombre) {
    const r = await page.evaluate((sel) => {
        const el = document.querySelector(sel);
        if (!el) { return { existe: false }; }
        const libreAhora = () => {
            const b = el.getBoundingClientRect();
            const arriba = document.elementFromPoint(b.left + b.width / 2, b.top + b.height / 2);
            return !!arriba && (arriba === el || el.contains(arriba));
        };
        // Como lo haria una persona: el elemento centrado y, aparte, la pagina desplazada hasta el final.
        el.scrollIntoView({ block: 'center' });
        const centrado = libreAhora();
        if (!el.closest('dialog, [data-testid="modal-prioridad"]')) { window.scrollTo(0, document.documentElement.scrollHeight); }
        const alFinal = libreAhora();
        const barra = document.querySelector('div.phpdebugbar');
        return { existe: true, libre: centrado && alFinal, centrado, alFinal, barra: !!barra, z: barra ? getComputedStyle(barra).zIndex : null };
    }, selector);
    ok(`${nombre} no queda tapado (Debugbar ${r.barra ? 'presente z=' + r.z : 'ausente'})`, r.existe && r.libre, JSON.stringify(r));
}

const foto = (page, nombre, completa = true) => page.screenshot({ path: path.join(salida, `${etiqueta}_${nombre}.png`), fullPage: completa });
const grupoDe = (page, productId) => page.locator(`details[data-grupo^="${taller}|${productId}|"]`).first();
const sugerenciaDe = (page, productId) => grupoDe(page, productId).locator(':scope > summary [data-sugerencia]').first().getAttribute('data-sugerencia');

(async () => {
    const navegador = await pw[motor].launch(motor === 'chromium' ? { executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true } : { headless: true });

    // ================= Administrador =================
    const admin = await sesion(navegador, 'cc.prio.admin@bylopez.test');
    const consolidado = `${base}/admin/corte-confeccion/ordenes-confeccion/consolidado?taller=${taller}`;
    await cargar(admin, consolidado);
    ok('hora del cálculo visible', (await admin.locator('[data-testid="sugerencia-calculada"]').innerText()).includes('calculada a las'));
    ok('sin disponible -> Urgente «Sin disponible»', await sugerenciaDe(admin, P.urgente) === 'urgente');
    ok('positivo sin mínimo -> «Sin mínimo configurado»', await sugerenciaDe(admin, P.sinMinimo) === 'sin_minimo');
    const sku = (await grupoDe(admin, P.alta).locator(':scope > summary p.font-mono').innerText()).trim();

    // Minimo configurado desde su pantalla (personal autorizado).
    await cargar(admin, `${base}/admin/corte-confeccion/stock-minimos?ambito=todas&q=${encodeURIComponent(sku)}`);
    const campo = admin.locator(`[data-minimo-fila="${P.alta}"] [data-minimo-input]`);
    await campo.fill('10');
    await Promise.all([admin.waitForURL(/stock-minimos/, { waitUntil: 'load' }), admin.click('[data-minimos-guardar]')]);
    await admin.waitForFunction(() => document.querySelector('#app')?.hasAttribute('data-v-app'));
    ok('mínimo guardado', (await admin.locator(`[data-minimo-fila="${P.alta}"] [data-minimo-input]`).inputValue()) === '10');
    ok('la variante pasa a Alta «Bajo el mínimo»', (await admin.locator(`[data-minimo-fila="${P.alta}"] [data-sugerencia]`).getAttribute('data-sugerencia')) === 'alta');
    await noTapado(admin, '[data-minimos-guardar]', 'botón «Guardar cambios»');
    await sinDesborde(admin, 'mínimos');
    await foto(admin, '01_minimos');

    await cargar(admin, consolidado);
    ok('consolidado refleja el mínimo (Alta)', await sugerenciaDe(admin, P.alta) === 'alta');
    ok('el administrador ve disponible y mínimo', (await grupoDe(admin, P.alta).locator('[data-sugerencia-detalle]').first().innerText()).includes('Disponible 4 de mínimo 10'));
    if (ancho >= 1280) {
        ok('escritorio: «Prioridad asignada» y «Sugerencia por stock» en columnas separadas', await admin.locator('[data-testid="grupos"] > div').first().locator('text=Sugerencia por stock').isVisible());
    }
    await sinDesborde(admin, 'consolidado con sugerencia');
    await foto(admin, '02_consolidado');

    await cargar(admin, `${consolidado}&sugerencia=alta`);
    const niveles = await admin.locator('details[data-grupo] > summary [data-sugerencia]').evaluateAll((els) => [...new Set(els.map((e) => e.dataset.sugerencia))]);
    ok('filtro por sugerencia = Alta', niveles.length === 1 && niveles[0] === 'alta', JSON.stringify(niveles));

    // «Cambiar prioridad» desde la sugerencia (variante con una sola orden): abre la modal existente; no copia el nivel.
    await cargar(admin, consolidado);
    await grupoDe(admin, P.sinMinimo).locator('[data-p-desde-sugerencia]').click();
    await admin.locator('[data-testid="modal-prioridad"]').waitFor({ state: 'visible' });
    ok('modal abierta desde la sugerencia', true);
    ok('la modal muestra la sugerencia como referencia', (await admin.locator('[data-p="sugerencia"]').innerText()).includes('Sin mínimo'));
    // La cantidad arranca con el total del origen (no con nada derivado de la sugerencia).
    const maximo = (await admin.locator('label[for="cc-p-cantidad"]').innerText()).match(/máx\. (\d+)/)?.[1];
    ok('la cantidad arranca con el total del origen', !!maximo && (await admin.locator('#cc-p-cantidad').inputValue()) === maximo, maximo);
    await admin.locator('#cc-p-cantidad').fill('1');
    await noTapado(admin, '[data-p-guardar]', 'botón «Guardar prioridad» de la modal');
    await foto(admin, '03_modal_desde_sugerencia', false);
    await admin.keyboard.press('Escape');
    await admin.waitForTimeout(300);

    // Prioridad asignada distinta de la sugerida: Alta 5 en una variante sugerida Urgente; la asignada manda.
    const grupoU = grupoDe(admin, P.urgente);
    await grupoU.locator(':scope > summary').click();
    const boton = grupoU.locator('[data-p-abrir]').first();
    const detalleId = await boton.getAttribute('data-detalle');
    await boton.click();
    await admin.locator('[data-testid="modal-prioridad"]').waitFor({ state: 'visible' });
    await admin.selectOption('#cc-p-desde', 'normal');
    await admin.selectOption('#cc-p-hacia', 'alta');
    await admin.locator('#cc-p-cantidad').fill('5');
    await admin.locator('[data-p-guardar]').click();
    await admin.locator('[data-testid="modal-prioridad"]').waitFor({ state: 'hidden', timeout: 30000 });
    // La fila se reemplaza sin recargar cuando llega la respuesta: se espera la confirmacion dentro de ella.
    await admin.locator(`#cc-resultados [data-detalle="${detalleId}"] [data-p="confirmacion"]`).waitFor({ state: 'attached', timeout: 30000 });
    const fila = admin.locator(`#cc-resultados [data-detalle="${detalleId}"]:not([data-p-abrir])`);
    ok('la prioridad asignada (Alta) se guarda aunque la sugerencia sea Urgente', /Alta \d+/.test(await fila.innerText()) && await sugerenciaDe(admin, P.urgente) === 'urgente');
    const ordenUrl = await fila.locator('a').first().getAttribute('href');

    // Ficha de la orden y distribucion: las dos prioridades visibles.
    await cargar(admin, ordenUrl);
    ok('ficha: columnas «Prioridad asignada» y «Sugerencia por stock»', await admin.locator('[data-testid="sugerencia-stock"]').count() > 0 && await admin.locator('[data-testid="prioridad-asignada"]').count() > 0);
    await sinDesborde(admin, 'ficha de la orden');
    await foto(admin, '04_ficha_admin');

    await cargar(admin, `${base}/admin/corte-confeccion/ordenes-confeccion/create/${corte}`);
    const sugDistribucion = celular ? admin.locator('[data-testid="sugerencia-stock-movil"]').first() : admin.locator('[data-testid="sugerencia-stock"]').first();
    ok('distribución: sugerencia por stock visible', await sugDistribucion.isVisible());
    ok('distribución: hora del cálculo', (await admin.locator('[data-testid="sugerencia-calculada"]').innerText()).includes('calculada a las'));
    await foto(admin, '05_distribucion');

    // ================= Taller (vinculado; rol con otro nombre) =================
    const sat = await sesion(navegador, 'cc.prio.satelite@bylopez.test');
    await cargar(sat, `${base}/admin/corte-confeccion/ordenes-confeccion/consolidado`);
    const html = await sat.content();
    ok('el taller ve nivel y motivo', html.includes('Sin disponible') && html.includes('Bajo el mínimo'));
    ok('el taller NO ve cifras globales de inventario', !html.includes('data-sugerencia-detalle') && !html.includes('Disponible 4 de mínimo'));
    await sinDesborde(sat, 'consolidado del taller');
    await foto(sat, '06_consolidado_taller');

    // Reporte con reparto P1: sugerido, ajuste, error de suma, cambio simultaneo y reenvio sin perder lo escrito.
    await cargar(sat, ordenUrl);
    const tarjeta = sat.locator(`[data-reporte-card]:has([data-report-id][value="${detalleId}"])`);
    await tarjeta.locator('[data-report-input]').fill('3');
    ok('reparto sugerido «3 Altas»', (await tarjeta.locator('[data-reparto-texto]').innerText()).includes('3 Altas (sugerido)'));
    await tarjeta.locator('[data-reparto-ajustar]').click();
    await tarjeta.locator('[data-reparto-nivel="alta"]').fill('2');
    await tarjeta.locator('[data-reparto-nivel="normal"]').fill('2');
    ok('error si el reparto no suma la entrega', (await tarjeta.locator('[data-reparto-error]').innerText()).includes('suma 4 y debe sumar 3'));
    await tarjeta.locator('[data-reparto-nivel="alta"]').fill('1');
    ok('reparto ajustado «1 Alta + 2 Normales»', (await tarjeta.locator('[data-reparto-texto]').innerText()).includes('1 Alta + 2 Normales'));

    demo('cambiar', detalleId, '2', 'normal', 'urgente'); // el administrador cambia el saldo mientras el taller reporta
    await sat.click('[data-review-report]');
    await sat.locator('[data-reporte-review]').waitFor({ state: 'visible' });
    ok('la revisión muestra el reparto', (await sat.locator('[data-review-reparto]').first().innerText()).includes('1 Alta + 2 Normales'));
    await foto(sat, '07_revision_reparto', false);
    await Promise.all([sat.waitForNavigation({ waitUntil: 'load' }), sat.click('[data-reporte-review] button[type="submit"]')]);
    await sat.waitForFunction(() => document.querySelector('#app')?.hasAttribute('data-v-app'));
    const tarjeta2 = sat.locator(`[data-reporte-card]:has([data-report-id][value="${detalleId}"])`);
    ok('saldo cambiado: se informa y se pide revisar', (await sat.content()).includes('No se registró la entrega') && (await tarjeta2.innerText()).includes('cambiaron'));
    ok('lo escrito se conserva (cantidad y reparto)', (await tarjeta2.locator('[data-report-input]').inputValue()) === '3' && (await tarjeta2.locator('[data-reparto-nivel="normal"]').inputValue()) === '2');
    await sinDesborde(sat, 'error de reparto');
    await foto(sat, '08_reparto_cambio');

    await tarjeta2.locator('[data-reparto-ajustar]').click(); // «Usar sugerido»
    ok('nuevo sugerido con Urgente primero', (await tarjeta2.locator('[data-reparto-texto]').innerText()).includes('2 Urgentes + 1 Alta'));
    await sat.click('[data-review-report]');
    await sat.locator('[data-reporte-review]').waitFor({ state: 'visible' });
    await noTapado(sat, '[data-reporte-review] button[type="submit"]', 'botón «Confirmar entrega»');
    await Promise.all([sat.waitForNavigation({ waitUntil: 'load' }), sat.click('[data-reporte-review] button[type="submit"]')]);
    await sat.waitForFunction(() => document.querySelector('#app')?.hasAttribute('data-v-app'));
    ok('entrega registrada', !(await sat.content()).includes('No se registró la entrega'));
    ok('el historial guarda el reparto confirmado', (await sat.locator('[data-reparto-reportado]').allInnerTexts()).some((t) => t.includes('2 Urgentes + 1 Alta')));
    await foto(sat, '09_ficha_taller');

    const prohibido = await sat.goto(`${base}/admin/corte-confeccion/stock-minimos`, { waitUntil: 'load' });
    // 401 si su rol no trae la llave (ACL de Bagisto); 403 si la trae (vinculo con taller: ConsolidadoConfeccionGate).
    ok('el taller no entra a mínimos (401/403)', [401, 403].includes(prohibido.status()), String(prohibido.status()));

    await navegador.close();
    fs.writeFileSync(path.join(salida, `${etiqueta}_resultado_sugerencia.json`), JSON.stringify({ etiqueta, pasos }, null, 2));
    const fallas = pasos.filter((p) => !p.ok).length;
    console.log(`${etiqueta}: ${pasos.length - fallas}/${pasos.length} pasos OK`);
    process.exit(fallas ? 1 : 0);
})().catch((e) => { console.error('ERROR', String(e).slice(0, 800)); process.exit(2); });
