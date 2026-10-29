// Verificacion visual de la DEMO (servidor local aislado). Uso:
//   DEMO_PASSWORD=... node run_demo.js <escenario.js> <rol: admin|asesora|bodega|umbral> [ancho=1440] [tema=claro|oscuro]
// Inicia sesion por el formulario real (la clave sale del entorno; nunca se guarda). Las capturas van a storage/logs/demo/capturas con
// nombre unico <escenario>__<rol>__<ancho>__<tema>__<paso>.png. El escenario exporta async (page, h) => resultado.
// Cada ejecucion lleva un identificador unico (RUN_ID, o marca de tiempo + aleatorio): las capturas y el resultado JSON van a
// capturas/<RUN_ID>/ y NUNCA sobrescriben evidencia anterior. Codigo de salida: 0 = todo bien; 1 = el escenario fallo; 2 = hubo errores
// INESPERADOS del navegador (pageerror, consola, HTTP >= 500) que el escenario no declaro con h.esperar(/patron/).
// Requiere playwright-core (npm i playwright-core) y Chrome instalado; PLAYWRIGHT_CORE y CHROME_PATH permiten indicar otras rutas.
const path = require('path');
const fs = require('fs');
const { chromium } = require(process.env.PLAYWRIGHT_CORE || 'playwright-core');

const base = process.env.BASE_URL || 'http://127.0.0.1:8020';
const runId = process.env.RUN_ID || (new Date().toISOString().replace(/[-:T]/g, '').slice(0, 14) + '-' + Math.random().toString(16).slice(2, 8));
const carpeta = path.resolve(__dirname, '../../../../../../storage/logs/demo/capturas', runId);
fs.mkdirSync(carpeta, { recursive: true });
const correos = { admin: 'demo.admin', asesora: 'demo.asesora', umbral: 'demo.umbral', bodega: 'demo.bodega' };

(async () => {
    const [esc, rol = 'admin', ancho = '1440', tema = 'claro'] = process.argv.slice(2);
    const w = parseInt(ancho, 10);
    const movil = w < 600;
    const browser = await chromium.launch({ executablePath: process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true, args: ['--disable-background-networking', '--no-proxy-server'] });
    const ctx = await browser.newContext({ viewport: { width: w, height: movil ? 844 : 900 }, locale: 'es-CO', isMobile: movil, hasTouch: movil, colorScheme: tema === 'oscuro' ? 'dark' : 'light' });
    if (tema === 'oscuro') await ctx.addCookies([{ name: 'dark_mode', value: '1', domain: new URL(base).hostname, path: '/' }]);
    const page = await ctx.newPage();
    // `php -S` atiende de a UNA conexion: un socket ocioso de Chrome puede dejar una navegacion sin respuesta. Se reintenta UNA vez y el reintento
    // queda registrado en el resultado (no se oculta ni se sube el tiempo de espera).
    let reintentos = 0;
    const irOriginal = page.goto.bind(page);
    page.goto = async (url, opciones) => {
        try {
            return await irOriginal(url, opciones);
        } catch (e) {
            if (!/Timeout/.test(String(e))) throw e;
            reintentos++;
            console.log('REINTENTO de navegacion:', url);
            return await irOriginal(url, opciones);
        }
    };
    const errores = [];
    const esperados = [];
    const patrones = [];
    const clasificar = (texto) => (patrones.some((p) => p.test(texto)) ? esperados : errores).push(texto);
    page.on('pageerror', (e) => clasificar('[pageerror] ' + String(e).slice(0, 300)));
    page.on('console', (m) => { if (m.type() === 'error') clasificar('[console] ' + m.text().slice(0, 300)); });
    page.on('response', async (r) => { if (r.status() >= 500) clasificar(`[http ${r.status()}] ${r.request().method()} ${r.url().replace(base, '')}`); });

    // El servidor informa desde su propio proceso la base que atiende: debe ser una base demo (no se infiere de un login).
    const ent = await (await ctx.request.get(base + '/__demo/entorno')).json().catch(() => null);
    if (!ent || !/^lpz_saas_demo_/.test(ent.base_consultada || '')) {
        console.log('BLOQUEADO: el servidor no confirma una base demo', JSON.stringify(ent));
        await browser.close();
        process.exit(3);
    }
    console.log('SERVIDOR base', ent.base_consultada, 'pid', ent.pid);

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
        runId,
        /** Declara un error ESPERADO (p. ej. un 500 simulado): se registra aparte y no hace fallar la ejecucion. */
        esperar: (patron) => patrones.push(patron),
    };
    let res;
    let fallo = false;
    try {
        res = await require(path.resolve(esc))(page, h);
    } catch (e) {
        fallo = true;
        console.log('FALLO', e.message.split('\n').slice(0, 6).join('\n'));
        await h.shot('fallo').catch(() => {});
    }
    if (res !== undefined) console.log('RES', JSON.stringify(res, null, 1));
    console.log('RUN', runId, '| esperados:', esperados.length, '| INESPERADOS:', errores.length);
    esperados.forEach((e) => console.log('  (esperado) ' + e));
    errores.forEach((e) => console.log('  (INESPERADO) ' + e));
    fs.writeFileSync(path.join(carpeta, `${nombre}__${rol}__${w}__${tema}.json`), JSON.stringify({ runId, escenario: nombre, rol, ancho: w, tema, fallo, reintentos_navegacion: reintentos, resultado: res ?? null, esperados, inesperados: errores }, null, 1));
    await browser.close();
    process.exit(fallo ? 1 : errores.length ? 2 : 0);
})();
