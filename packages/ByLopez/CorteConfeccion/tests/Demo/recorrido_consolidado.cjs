// Recorrido REAL del Consolidado general, la modal «Cambiar prioridad» y la alerta en la campana nativa (Doc. 09),
// contra la DEMO aislada preparada con demo_prioridades.php.
// Uso: DB_DATABASE=<demo> DEMO_PRIO_PASSWORD=... DEMO_TALLER=1514 ANCHO=1440|1024|430|390 MOTOR=chromium|webkit \
//      PLAYWRIGHT_CORE=<ruta> node recorrido_consolidado.cjs [salida]
// WebKit con perfil de iPhone es EMULACION: no equivale a Safari en un iPhone fisico (teclado real, barras del navegador).
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const pw = require(process.env.PLAYWRIGHT_CORE || 'playwright-core');

const base = process.env.BASE_URL || 'http://127.0.0.1:8030';
const taller = process.env.DEMO_TALLER || '1514';
const ancho = Number(process.env.ANCHO || 1440);
const motor = process.env.MOTOR || 'chromium';
const salida = path.resolve(process.argv[2] || 'capturas_consolidado');
const etiqueta = `${motor}-${ancho}`;
const celular = ancho < 1024;
fs.mkdirSync(salida, { recursive: true });

const pasos = [];
const ok = (nombre, valor, detalle = '') => { pasos.push({ nombre, ok: !!valor, detalle }); console.log(`${valor ? 'OK ' : 'FALLA'} ${nombre}${detalle ? ' — ' + detalle : ''}`); };

// Operaciones de prioridad en la base demo (para comprobar que el doble clic no duplica).
const operaciones = () => JSON.parse(execFileSync('php', [path.join(__dirname, 'demo_prioridades.php'), 'estado'], { cwd: path.resolve(__dirname, '../../../../..') }).toString()).operaciones.length;

async function sesion(navegador, email) {
    const opciones = motor === 'webkit' && celular
        ? { ...pw.devices['iPhone 13'], locale: 'es-CO' }
        : { viewport: { width: ancho, height: celular ? 844 : 900 }, locale: 'es-CO' };
    const ctx = await navegador.newContext(opciones);
    // Debugbar ya no se oculta: partials/debugbar-local.blade.php lo deja por debajo de la modal (se comprueba abajo).
    const page = await ctx.newPage();
    page.setDefaultTimeout(90000);
    page.setDefaultNavigationTimeout(120000);
    // Laravel Boost (herramienta de desarrollo) y peticiones canceladas al navegar: ruido ajeno, se anota aparte.
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
    // Bagisto monta Vue despues de load y reemplaza nodos: se espera el montaje antes de tocar nada.
    await page.waitForFunction(() => document.querySelector('#app')?.hasAttribute('data-v-app'), null, { timeout: 60000 });
    await page.waitForTimeout(400);
}

async function sinDesborde(page, nombre) {
    const d = await page.evaluate(() => ({ ancho: document.documentElement.scrollWidth, vista: window.innerWidth }));
    ok(`sin desplazamiento horizontal: ${nombre}`, d.ancho <= d.vista + 1, `${d.ancho}/${d.vista}`);
}

const foto = (page, nombre, completa = true) => page.screenshot({ path: path.join(salida, `${etiqueta}_${nombre}.png`), fullPage: completa });
const panel = (page) => page.locator('[data-testid="modal-prioridad"]');

(async () => {
    const navegador = await pw[motor].launch(motor === 'chromium' ? { executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true } : { headless: true });

    // ---------------- Administrador ----------------
    const admin = await sesion(navegador, 'cc.prio.admin@bylopez.test');
    await cargar(admin, `${base}/admin/corte-confeccion/ordenes-confeccion/consolidado`);
    ok('pestaña activa evidente', (await admin.locator('nav[aria-label="Vistas de órdenes de confección"] [aria-current="page"]').innerText()).includes('Consolidado'));
    ok('sin @endif ni alerta suelta', !(await admin.content()).includes('@endif') && await admin.locator('[data-testid="aviso-contexto"]').count() === 0);
    ok('cuatro indicadores', await admin.locator('[data-testid="indicadores"] > div').count() === 4);

    const contenido = await admin.evaluate(() => {
        const r = document.getElementById('cc-consolidado').getBoundingClientRect();
        const padre = document.getElementById('cc-consolidado').parentElement.getBoundingClientRect();
        return { usado: Math.round(r.width), disponible: Math.round(padre.width) };
    });
    ok('el contenido usa todo el ancho disponible', contenido.usado >= contenido.disponible - 2, `${contenido.usado}/${contenido.disponible}`);

    const filtros = admin.locator('#cc-filtros');
    if (celular) {
        ok('celular: filtros plegados al entrar', !(await filtros.isVisible()));
        await admin.click('[data-cc-filtros]');
        ok('celular: «Filtros» los despliega', await filtros.isVisible());
    } else {
        ok('escritorio: filtros visibles y compactos', await filtros.isVisible() && (await filtros.boundingBox()).height < 230, `alto=${Math.round((await filtros.boundingBox()).height)}`);
    }
    await sinDesborde(admin, 'consolidado');
    await foto(admin, '01_consolidado');

    await cargar(admin, `${base}/admin/corte-confeccion/ordenes-confeccion/consolidado?taller=${taller}`);
    const grupo = admin.locator('details[data-grupo]').first();
    const resumen = grupo.locator(':scope > summary');
    if (!celular) {
        const alto = (await resumen.boundingBox()).height;
        const nombre = (await resumen.locator('p > span.truncate').first().boundingBox()).height;
        ok('escritorio: nombre en una línea y fila compacta', nombre < 30 && alto < 130, `nombre=${Math.round(nombre)} fila=${Math.round(alto)}`);
        const ordenAncho = await admin.evaluate(() => {
            const d = document.querySelector('details[data-grupo]');
            d.open = true;
            const a = d.querySelector('[data-detalle]:not([data-p-abrir]) a, [data-detalle]:not([data-p-abrir]) p');
            const h = a ? a.getBoundingClientRect().height : 0;
            d.open = false;
            return h;
        });
        ok('escritorio: el código de orden no se parte en varias líneas', ordenAncho < 30, `alto=${Math.round(ordenAncho)}`);
        const col = await admin.evaluate(() => {
            const cab = document.querySelector('[data-testid="grupos"] > div').getBoundingClientRect();
            const fila = document.querySelector('details[data-grupo] > summary').getBoundingClientRect();
            return Math.abs(cab.left - fila.left) < 2 && Math.abs(cab.right - fila.right) < 2;
        });
        ok('escritorio: encabezado y filas alineados', col);
    }
    await resumen.click();
    ok('la variante despliega sus órdenes de origen', await grupo.locator('[data-p-abrir]').first().isVisible());
    await sinDesborde(admin, 'variante desplegada');
    await foto(admin, '02_desplegado');

    // Apertura con TECLADO (Enter sobre el boton).
    const boton = grupo.locator('[data-p-abrir]').first();
    const detalle = await boton.getAttribute('data-detalle');
    const reparto = JSON.parse(await boton.getAttribute('data-reparto'));
    await boton.focus(); // focus() desplaza la pagina hasta el boton: la posicion de referencia se toma despues.
    const yAntes = await admin.evaluate(() => window.scrollY);
    await admin.keyboard.press('Enter');
    await panel(admin).waitFor({ state: 'visible' });
    await admin.waitForTimeout(400);
    ok('modal abierta con teclado y foco en el primer campo', await admin.evaluate(() => document.activeElement?.id === 'cc-p-desde'));

    const caja = await admin.locator('[data-testid="modal-prioridad"]').evaluate((el) => {
        const p = el.closest('.box-shadow').getBoundingClientRect();
        const header = document.querySelector('header, .sticky, [class*="fixed"][class*="top-0"]');
        return { izq: p.left, der: p.right, arriba: p.top, abajo: p.bottom, vw: window.innerWidth, vh: window.innerHeight, cuerpo: document.body.style.overflow };
    });
    ok('modal dentro de la pantalla (sin cortes ni desborde horizontal)', caja.izq >= 0 && caja.der <= caja.vw + 1 && caja.arriba >= 0, JSON.stringify(caja));
    ok('scroll de la página bloqueado con la modal abierta', caja.cuerpo === 'hidden');
    if (!celular) { ok('escritorio: modal centrada', Math.abs((caja.izq + caja.der) / 2 - caja.vw / 2) < 4); }
    const encima = await admin.evaluate(() => {
        const p = document.querySelector('[data-testid="modal-prioridad"]').closest('.box-shadow').getBoundingClientRect();
        const el = document.elementFromPoint(p.left + 10, p.top + 10);
        return !!el?.closest('[data-testid="modal-prioridad"]') || !!el?.closest('.box-shadow')?.querySelector('[data-testid="modal-prioridad"]');
    });
    ok('modal por encima del encabezado y el menú', encima);
    const pieLibre = await admin.evaluate(() => {
        const b = document.querySelector('[data-p-guardar]').getBoundingClientRect();
        const el = document.elementFromPoint(b.left + b.width / 2, b.top + b.height / 2);
        return !!el?.closest('[data-p-guardar]');
    });
    ok('Debugbar no tapa «Guardar prioridad»', pieLibre);
    await foto(admin, '03_modal', false);

    // Foco atrapado: 12 tabulaciones no salen de la modal.
    let fuera = 0;
    for (let i = 0; i < 12; i++) {
        await admin.keyboard.press('Tab');
        if (!(await admin.evaluate(() => !!document.activeElement?.closest('.box-shadow')?.querySelector('[data-testid="modal-prioridad"]') || !!document.activeElement?.closest('[data-testid="modal-prioridad"]')))) { fuera++; }
    }
    ok('Tab no sale de la modal', fuera === 0, `fuera=${fuera}`);
    const focoVisible = await admin.evaluate(() => getComputedStyle(document.activeElement).outlineStyle !== 'none' || getComputedStyle(document.activeElement).boxShadow !== 'none');
    ok('foco visible', focoVisible);

    await admin.keyboard.press('Escape');
    await admin.waitForTimeout(400);
    ok('Escape cierra la modal', !(await panel(admin).isVisible().catch(() => false)));
    ok('el foco vuelve al botón que la abrió', await admin.evaluate((d) => document.activeElement?.matches(`[data-p-abrir][data-detalle="${d}"]`), detalle));
    ok('el scroll se restaura', Math.abs((await admin.evaluate(() => window.scrollY)) - yAntes) < 40);

    // Validaciones junto al campo.
    await boton.click();
    await panel(admin).waitFor({ state: 'visible' });
    const campo = admin.locator('#cc-p-cantidad');
    const guardar = admin.locator('[data-p-guardar]');
    await admin.selectOption('#cc-p-desde', 'normal');
    ok('por defecto la cantidad es todo el origen', (await campo.inputValue()) === String(reparto.normal) && await guardar.isEnabled(), await campo.inputValue());
    await campo.fill('2.5');
    ok('decimal: error junto al campo y no se puede guardar', (await admin.locator('#cc-p-cantidad-error').innerText()).includes('entero') && await guardar.isDisabled());
    await campo.fill(String(reparto.normal + 1));
    ok('mayor al saldo: error junto al campo', (await admin.locator('#cc-p-cantidad-error').innerText()).includes(`Solo hay ${reparto.normal}`) && await guardar.isDisabled());

    const cantidad = Math.min(5, reparto.normal);
    await campo.fill(String(cantidad));
    await admin.selectOption('#cc-p-hacia', 'alta');
    await admin.fill('#cc-p-nota', 'Recorrido visual: pedido mayorista con nota algo más larga para ver cómo se acomoda el texto en la ventana.');
    const previa = await admin.locator('[data-p="vista-previa"]').innerText();
    const quedan = reparto.normal - cantidad;
    ok('vista previa «N pasarán a Alta · M seguirán en Normal»', previa === `${cantidad} pasarán a Alta` + (quedan > 0 ? ` · ${quedan} seguirán en Normal` : ''), previa);
    await foto(admin, '04_modal_completa', false);

    // Doble clic en Guardar: una sola operacion; la modal se cierra y la fila e indicadores se actualizan sin recargar.
    const opsAntes = operaciones();
    const altasAntes = Number((await admin.locator('[data-i="alta"]').innerText()).replace(/\./g, ''));
    await admin.evaluate(() => { window.__sinRecarga = true; });
    await guardar.dblclick();
    await panel(admin).waitFor({ state: 'hidden', timeout: 30000 });
    await admin.waitForFunction((a) => Number(document.querySelector('[data-i="alta"]').innerText.replace(/\./g, '')) !== a, altasAntes, { timeout: 30000 });
    ok('guardado sin recargar la página', await admin.evaluate(() => window.__sinRecarga === true));
    ok('doble clic = una sola operación', operaciones() === opsAntes + 1, `${opsAntes} -> ${operaciones()}`);
    ok('indicador de altas actualizado', Number((await admin.locator('[data-i="alta"]').innerText()).replace(/\./g, '')) === altasAntes + cantidad);
    ok('la fila muestra la nueva prioridad', (await admin.locator(`#cc-resultados [data-detalle="${detalle}"]:not([data-p-abrir])`).innerText()).includes(`Alta ${reparto.alta + cantidad}`));
    ok('la variante sigue desplegada', await admin.locator('details[data-grupo]').first().evaluate((d) => d.open));
    const flash = (await admin.locator('[data-p="confirmacion"]').innerText().catch(() => '')).includes('Se avisó a Satélite Demo');
    ok('mensaje indica a quién se avisó', flash);
    await sinDesborde(admin, 'tras guardar');
    await foto(admin, '05_tras_guardar', false);

    // ---------------- Taller (rol «Satélite demo», aislado por vínculo) ----------------
    const sat = await sesion(navegador, 'cc.prio.satelite@bylopez.test');
    await cargar(sat, `${base}/admin/corte-confeccion/ordenes-confeccion/consolidado`);
    await sat.waitForTimeout(1200);
    await sat.locator('.icon-notification').first().click();
    const aviso = sat.locator('[data-cc-aviso]').first();
    await aviso.waitFor({ state: 'visible', timeout: 15000 });
    ok('el aviso aparece en la campana nativa', (await aviso.innerText()).includes(`${cantidad} u.`));
    await foto(sat, '06_campana', false);
    await Promise.all([sat.waitForURL(/consolidado/, { waitUntil: 'load' }), aviso.click()]);
    await sat.waitForFunction(() => document.querySelector('#app')?.hasAttribute('data-v-app'));
    ok('abre el consolidado con el contexto de la alerta', await sat.locator('[data-testid="aviso-contexto"]').count() === 1);
    ok('resalta la orden del cambio', await sat.locator('#cc-resultados [data-foco="1"]').count() === 1);
    ok('el taller no ve selector de taller ni botón de cambio', await sat.locator('#cc-taller').count() === 0 && await sat.locator('[data-p-abrir]').count() === 0);
    await sinDesborde(sat, 'consolidado del taller');
    await foto(sat, '07_taller_alerta');

    await cargar(sat, `${base}/admin/corte-confeccion/ordenes-confeccion/consolidado?taller=1512`);
    ok('filtro de taller ajeno no amplía el alcance', (await sat.locator('[data-i="por_confeccionar"]').innerText()) === '0');

    await navegador.close();
    fs.writeFileSync(path.join(salida, `${etiqueta}_resultado.json`), JSON.stringify({ etiqueta, cantidad, detalle, pasos }, null, 2));
    const fallas = pasos.filter((p) => !p.ok).length;
    console.log(`${etiqueta}: ${pasos.length - fallas}/${pasos.length} pasos OK`);
    process.exit(fallas ? 1 : 0);
})().catch((e) => { console.error('ERROR', String(e).slice(0, 600)); process.exit(2); });
