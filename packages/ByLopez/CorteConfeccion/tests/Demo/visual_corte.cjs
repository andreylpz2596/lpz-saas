// Revision visual de Corte y Confeccion contra la DEMO aislada (plan de validacion, P12 y seccion 6.5).
// Uso:
//   DEMO_CC_PASSWORD=... DEMO_CC_IDS='{"orden_jairo":698,...}' BASE_URL=http://127.0.0.1:8021 \
//   PLAYWRIGHT_CORE=<ruta a playwright-core> node visual_corte.cjs [salida]
// Recorre las pantallas del modulo a 360/390/430 (movil) y 1280/1440 (escritorio): captura, desbordamiento horizontal, errores
// de consola/HTTP>=500 y scroll vertical que responde (inicio arriba/centro/abajo). Despues ejecuta el flujo REAL del modal
// «Reasignar prendas» (abrir, maximos, mover, enviar) en 390 y 1440. Emulacion Chromium: NO equivale a Safari/iPhone fisico.
const fs = require('fs');
const path = require('path');
const { chromium } = require(process.env.PLAYWRIGHT_CORE || 'playwright-core');

const base = process.env.BASE_URL || 'http://127.0.0.1:8021';
const ids = JSON.parse(process.env.DEMO_CC_IDS || '{}');
const salida = path.resolve(process.argv[2] || 'capturas_corte');
fs.mkdirSync(salida, { recursive: true });

const pantallas = [
    ['proveedores', '/admin/corte-confeccion/proveedores-tela'],
    ['referencias', '/admin/corte-confeccion/proveedores-tela/referencias'],
    ['compras', '/admin/corte-confeccion/compras-tela'],
    ['rollos', '/admin/corte-confeccion/rollos'],
    ['cortadores', '/admin/corte-confeccion/cortadores'],
    ['cortes', '/admin/corte-confeccion/ordenes-corte'],
    ['corte-ficha', `/admin/corte-confeccion/ordenes-corte/view/${ids.corte1}`],
    ['distribuir', `/admin/corte-confeccion/ordenes-confeccion/create/${ids.corte1}`],
    ['talleres', '/admin/corte-confeccion/talleres'],
    ['taller-ficha', `/admin/corte-confeccion/talleres/view/${ids.taller_andrea}`],
    ['taller-ficha-reasignaciones', `/admin/corte-confeccion/talleres/view/${ids.taller_jairo2}`],
    ['confeccion', '/admin/corte-confeccion/ordenes-confeccion'],
    ['confeccion-ficha', `/admin/corte-confeccion/ordenes-confeccion/view/${ids.orden_jairo}`],
    ['confeccion-ficha-historial', `/admin/corte-confeccion/ordenes-confeccion/view/${ids.orden_jairo2}`],
    ['movimientos', '/admin/corte-confeccion/inventario-movimientos'],
];

// ANCHOS (coma) y MODO (pantallas|flujo|todo) permiten repartir la pasada en varios procesos/servidores.
const anchos = (process.env.ANCHOS || '360,390,430,1280,1440').split(',').map(Number);
const modo = process.env.MODO || 'todo';
const etiqueta = process.env.ETIQUETA || 'completo';

(async () => {
    const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
    const resultados = [];
    const errores = [];

    const login = async (page, correo) => {
        await page.goto(base + '/admin/login', { waitUntil: 'domcontentloaded', timeout: 90000 });
        await page.waitForSelector('form button', { timeout: 90000 });
        await page.waitForTimeout(500);
        await page.fill('input[name=email]', correo);
        await page.fill('input[name=password]', process.env.DEMO_CC_PASSWORD || '');
        await page.click('form button');
        await page.waitForURL((u) => !u.pathname.endsWith('/admin/login'), { timeout: 90000 });
    };

    for (const ancho of (modo === 'flujo' ? [] : anchos)) {
        const movil = ancho < 600;
        const ctx = await browser.newContext({ viewport: { width: ancho, height: movil ? 844 : 900 }, locale: 'es-CO', isMobile: movil, hasTouch: movil });
        const page = await ctx.newPage();
        const actual = { pantalla: '' };
        page.on('pageerror', (e) => errores.push({ ancho, pantalla: actual.pantalla, tipo: 'pageerror', texto: String(e).slice(0, 300) }));
        page.on('console', (m) => { if (m.type() === 'error') errores.push({ ancho, pantalla: actual.pantalla, tipo: 'console', texto: m.text().slice(0, 300) }); });
        page.on('response', (r) => { if (r.status() >= 500) errores.push({ ancho, pantalla: actual.pantalla, tipo: 'http', texto: `${r.status()} ${r.url().replace(base, '')}` }); });

        await login(page, 'cc.admin@bylopez.test');

        for (const [nombre, ruta] of pantallas) {
            actual.pantalla = nombre;
            const r = await page.goto(base + ruta, { waitUntil: 'domcontentloaded' });
            await page.waitForLoadState('networkidle', { timeout: 30000 }).catch(() => {});
            await page.waitForTimeout(700);

            const medidas = await page.evaluate(() => {
                const doc = document.documentElement;

                return {
                    desbordamiento_px: Math.max(0, doc.scrollWidth - doc.clientWidth),
                    alto: doc.scrollHeight,
                    visible: window.innerHeight,
                };
            });

            // Scroll vertical: desde arriba, centro y abajo, con rueda/tecla; debe avanzar cuando hay contenido de sobra.
            let scroll = 'n/a';
            if (medidas.alto > medidas.visible + 50) {
                const posiciones = [];
                for (const destino of [0, Math.floor((medidas.alto - medidas.visible) / 2), medidas.alto]) {
                    await page.evaluate((y) => window.scrollTo(0, y), destino);
                    await page.waitForTimeout(80);
                    await page.mouse.move(Math.floor(ancho / 2), 300);
                    await page.mouse.wheel(0, 150);
                    await page.waitForTimeout(120);
                    posiciones.push(await page.evaluate(() => Math.round(window.scrollY)));
                }
                scroll = posiciones.join('/');
                await page.evaluate(() => window.scrollTo(0, 0));
            }

            await page.screenshot({ path: path.join(salida, `${nombre}__${ancho}.png`), fullPage: false });
            resultados.push({ ancho, pantalla: nombre, http: r ? r.status() : null, ...medidas, scroll_y_arriba_centro_abajo: scroll });
        }

        await ctx.close();
    }

    // Flujo REAL del modal «Reasignar prendas» (390 y 1440).
    const flujo = [];
    for (const [ancho, orden] of (modo === 'pantallas' ? [] : [[1440, ids.orden_jairo], [390, ids.orden_jairo2]])) {
        const movil = ancho < 600;
        const ctx = await browser.newContext({ viewport: { width: ancho, height: movil ? 844 : 900 }, locale: 'es-CO', isMobile: movil, hasTouch: movil });
        const page = await ctx.newPage();
        const lista = [];
        page.on('pageerror', (e) => lista.push(String(e).slice(0, 200)));
        await login(page, 'cc.bodega@bylopez.test');
        await page.goto(`${base}/admin/corte-confeccion/ordenes-confeccion/view/${orden}`, { waitUntil: 'domcontentloaded', timeout: 90000 });
        await page.waitForTimeout(1500);
        const abrir = page.locator('[data-reasignar-abrir]').first();
        const paso = { ancho, orden, boton_visible: await abrir.isVisible() };

        if (paso.boton_visible) {
            await abrir.click();
            await page.waitForSelector('#reasignar-modal [data-r="contenido"]:not(.hidden)', { timeout: 20000 });
            paso.filas = await page.locator('#reasignar-modal [data-r="filas"] tr').count();
            await page.screenshot({ path: path.join(salida, `modal-abierto__${ancho}.png`) });

            await page.selectOption('#r-destino', { index: 1 });
            // M: mover 100 (el modal calcula el maximo desde el saldo real).
            const filaM = page.locator('#reasignar-modal [data-r="filas"] tr').nth(1);
            await filaM.locator('input').last().fill('100');
            await page.fill('#r-motivo', 'Prueba visual: el taller origen no alcanza la fecha');
            paso.antes_despues = (await page.locator('#reasignar-modal [data-r="antes-despues"]').innerText()).replace(/\s+/g, ' ');
            paso.impacto = (await page.locator('#reasignar-modal [data-r="impacto-tarifa"]').innerText()).replace(/\s+/g, ' ');
            paso.boton_habilitado = await page.locator('#reasignar-modal [data-r="enviar"]').isEnabled();
            await page.screenshot({ path: path.join(salida, `modal-lleno__${ancho}.png`) });

            // Error de cantidad (supera el maximo): se muestra y no se envia.
            await filaM.locator('input').last().fill('99999');
            await page.click('#reasignar-modal [data-r="enviar"]');
            await page.waitForTimeout(400);
            paso.error_visible = await page.locator('#reasignar-modal [data-r="errores"]').isVisible();
            paso.error_texto = (await page.locator('#reasignar-modal [data-r="errores"]').innerText().catch(() => '')).slice(0, 160);
            await page.screenshot({ path: path.join(salida, `modal-error__${ancho}.png`) });

            // Scroll dentro del modal abierto (movil) y que la pagina de atras no quede bloqueada despues de cerrar.
            await filaM.locator('input').last().fill('100');
            paso.scroll_modal = await page.evaluate(() => { const m = document.getElementById('reasignar-modal'); m.scrollTo(0, 400); return Math.round(m.scrollTop); });

            if (ancho === 1440) {
                // Se espera la RESPUESTA del POST (la URL ya coincide desde antes) y luego la recarga de la ficha.
                const [respuesta] = await Promise.all([
                    page.waitForResponse((r) => r.url().includes('/ordenes-confeccion/reasignar/') && r.request().method() === 'POST', { timeout: 60000 }),
                    page.click('#reasignar-modal [data-r="enviar"]'),
                ]);
                paso.http_envio = respuesta.status();
                await page.waitForLoadState('domcontentloaded').catch(() => {});
                await page.waitForSelector('#reasignaciones-historial', { timeout: 60000 }).catch(() => {});
                paso.tras_enviar = (await page.locator('body').innerText()).includes('Reasignaciones') ? 'historial visible' : 'sin historial';
                await page.screenshot({ path: path.join(salida, `tras-reasignar__${ancho}.png`) });
            } else {
                await page.click('#reasignar-modal [data-r="cerrar"] >> nth=1');
                await page.waitForTimeout(300);
                await page.evaluate(() => window.scrollTo(0, 400));
                paso.scroll_pagina_tras_cerrar = await page.evaluate(() => Math.round(window.scrollY));
            }
        }

        paso.errores_pagina = lista;
        flujo.push(paso);
        await ctx.close();
    }

    await browser.close();
    fs.writeFileSync(path.join(salida, `resultado_${etiqueta}.json`), JSON.stringify({ resultados, flujo, errores }, null, 2));

    const desbordadas = resultados.filter((r) => r.desbordamiento_px > 0);
    console.log(JSON.stringify({ pantallas: resultados.length, http_no_200: resultados.filter((r) => r.http !== 200).length, con_desbordamiento: desbordadas.map((r) => `${r.pantalla}@${r.ancho}:${r.desbordamiento_px}`), errores: errores.length, flujo }, null, 2));
})();
