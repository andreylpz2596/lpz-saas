// Verificacion visual de la DEMO (servidor local aislado). Uso:
//   DEMO_PASSWORD=... node run_demo.js <escenario.js> <rol: admin|asesora|bodega|umbral> [ancho=1440] [tema=claro|oscuro]
// Inicia sesion por el formulario real (la clave sale del entorno; nunca se guarda). Las capturas van a storage/logs/demo/capturas con
// nombre unico <escenario>__<rol>__<ancho>__<tema>__<paso>.png. El escenario exporta async (page, h) => resultado.
// Requiere playwright-core (npm i playwright-core) y Chrome instalado; PLAYWRIGHT_CORE y CHROME_PATH permiten indicar otras rutas.
const path = require('path');
const fs = require('fs');
const { chromium } = require(process.env.PLAYWRIGHT_CORE || 'playwright-core');

const base = process.env.BASE_URL || 'http://127.0.0.1:8020';
const carpeta = path.resolve(__dirname, '../../../../../../storage/logs/demo/capturas');
fs.mkdirSync(carpeta, { recursive: true });
const correos = { admin: 'demo.admin', asesora: 'demo.asesora', umbral: 'demo.umbral', bodega: 'demo.bodega' };

(async () => {
    const [esc, rol = 'admin', ancho = '1440', tema = 'claro'] = process.argv.slice(2);
    const w = parseInt(ancho, 10);
    const movil = w < 600;
    const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
    const ctx = await browser.newContext({ viewport: { width: w, height: movil ? 844 : 900 }, locale: 'es-CO', isMobile: movil, hasTouch: movil, colorScheme: tema === 'oscuro' ? 'dark' : 'light' });
    if (tema === 'oscuro') await ctx.addCookies([{ name: 'dark_mode', value: '1', domain: new URL(base).hostname, path: '/' }]);
    const page = await ctx.newPage();
    const errores = [];
    page.on('pageerror', (e) => errores.push('[pageerror] ' + String(e).slice(0, 300)));
    page.on('console', (m) => { if (m.type() === 'error') errores.push('[console] ' + m.text().slice(0, 300)); });
    page.on('response', async (r) => { if (r.status() >= 500) errores.push(`[http ${r.status()}] ${r.request().method()} ${r.url().replace(base, '')}`); });

    await page.goto(base + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', correos[rol] + '@bylopez.test');
    await page.fill('input[name=password]', process.env.DEMO_PASSWORD || '');
    await page.click('form button');
    await page.waitForURL((u) => !u.pathname.endsWith('/admin/login'), { timeout: 45000 });

    const nombre = path.basename(esc).replace(/.c?js$/, '');
    const h = {
        base, w, rol, tema, movil,
        shot: (paso, completa = false) => page.screenshot({ path: path.join(carpeta, `${nombre}__${rol}__${w}__${tema}__${paso}.png`), fullPage: completa }),
        overflow: () => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth),
        errores,
    };
    let res;
    try {
        res = await require(path.resolve(esc))(page, h);
    } catch (e) {
        console.log('FALLO', e.message.split('\n').slice(0, 6).join('\n'));
        await h.shot('fallo').catch(() => {});
    }
    if (res !== undefined) console.log('RES', JSON.stringify(res, null, 1));
    console.log('ERRORES', errores.length);
    errores.forEach((e) => console.log('  ' + e));
    await browser.close();
})();
