// Doc. 34 · Ayudas comunes de las pruebas de NAVEGADOR del dictado (demo local con autenticacion real).
// Requiere puppeteer-core (ruta en PUPPETEER_DIR) y Chrome instalado. La clave de la asesora se lee de DEMO_CLAVE (fuera del repo).
const fs = require('fs');
const path = require('path');
const puppeteer = require(process.env.PUPPETEER_DIR ? path.join(process.env.PUPPETEER_DIR, 'puppeteer-core') : 'puppeteer-core');

const BASE = process.env.DEMO_BASE || 'http://127.0.0.1:8098';
const EVID = process.env.EVID;
const CRED = Object.fromEntries(fs.readFileSync(process.env.DEMO_CLAVE, 'utf8').trim().split('\n').map((l) => l.split('=')));

async function abrir({ ancho = 1366, alto = 900, movil = false, iniciar = null } = {}) {
    const browser = await puppeteer.launch({
        executablePath: process.env.CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe',
        headless: 'new',
        args: ['--no-first-run', '--lang=es-CO'],
    });
    const page = await browser.newPage();
    page.setDefaultNavigationTimeout(120000);
    page.setDefaultTimeout(60000);
    await page.setViewport(movil ? { width: ancho, height: alto, isMobile: true, hasTouch: true, deviceScaleFactor: 2 } : { width: ancho, height: alto });
    const errores = [];
    page.on('pageerror', (e) => errores.push('pageerror: ' + String(e.message).slice(0, 300)));
    page.on('console', (m) => { if (m.type() === 'error' && ! /favicon|Failed to load resource/.test(m.text())) errores.push('console: ' + m.text().slice(0, 200)); });
    if (iniciar) {
        await page.evaluateOnNewDocument(iniciar);
    }

    return { browser, page, errores };
}

async function login(page) {
    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle2' });
    // El formulario lo monta Vue (v-form): se espera el <form> real con sus campos.
    await page.waitForSelector('form input[name="email"]');
    await page.type('form input[name="email"]', CRED.usuario);
    await page.type('form input[name="password"]', CRED.clave);
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle2' }), page.click('form button[aria-label="Iniciar Sesión"]')]);

    return ! page.url().includes('/login');
}

/** Abre Nueva venta, busca el cliente de la demo por telefono y lo elige; abre el panel del dictado. */
async function ventaConCliente(page) {
    await page.goto(BASE + '/admin/ventas-asesor/nueva-venta/pantalla', { waitUntil: 'networkidle2' });
    await page.waitForSelector('input[placeholder^="Buscar cliente"]');
    await page.type('input[placeholder^="Buscar cliente"]', CRED.cliente_telefono);
    await page.keyboard.press('Enter');
    await clicTexto(page, 'button', /Seleccionar/, 20000);
    await page.waitForSelector('[data-venta="dictado"]', { timeout: 20000 });
    await page.click('[data-venta="dictado-abrir"]');
    await page.waitForSelector('[data-venta="dictado-texto"]');
}

/** Clic en el primer elemento que coincide con el selector y cuyo texto cumple el patron (dentro de un ambito opcional). */
async function clicTexto(page, selector, patron, espera = 10000, ambito = 'body') {
    const fuente = patron instanceof RegExp ? patron.source : patron.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    await page.waitForFunction((amb, sel, f) => [...(document.querySelector(amb)?.querySelectorAll(sel) || [])].some((n) => new RegExp(f).test(n.innerText) && ! n.disabled),
        { timeout: espera }, ambito, selector, fuente);
    await page.evaluate((amb, sel, f) => [...document.querySelector(amb).querySelectorAll(sel)].find((n) => new RegExp(f).test(n.innerText) && ! n.disabled).click(),
        ambito, selector, fuente);
}

/** Escribe en un input/textarea con v-model (reemplaza el valor y avisa a Vue). */
async function escribir(page, selector, valor) {
    await page.waitForSelector(selector);
    await page.$eval(selector, (n, v) => { n.focus(); n.value = v; n.dispatchEvent(new Event('input', { bubbles: true })); }, valor);
}

async function lineas(page) {
    return page.$$eval('[data-dictado-linea]', (ns) => ns.map((n) => ({
        estado: n.getAttribute('data-dictado-estado'),
        etiqueta: n.querySelector('[data-venta="dictado-estado"]')?.textContent.trim(),
        texto: n.innerText.replace(/\s+/g, ' ').trim().slice(0, 300),
    })));
}

async function interpretar(page, texto, nLineas) {
    await escribir(page, '[data-venta="dictado-texto"]', texto);
    await page.click('[data-venta="dictado-interpretar"]');
    await page.waitForFunction((n) => document.querySelectorAll('[data-dictado-linea]').length === n
        && ! document.querySelector('[data-venta="dictado-interpretar"]').disabled, { timeout: 20000 }, nLineas);

    return lineas(page);
}

async function esperarEstado(page, i, estados) {
    await page.waitForFunction((i, estados) => {
        const n = document.querySelector('[data-dictado-linea="' + i + '"]');
        return n && estados.includes(n.getAttribute('data-dictado-estado')) && ! n.querySelector('[data-venta="dictado-validar"][disabled]')
            && ! [...n.querySelectorAll('button')].some((b) => /Validando/.test(b.innerText));
    }, { timeout: 20000 }, i, estados);
}

const enLinea = (i, sel) => '[data-dictado-linea="' + i + '"] ' + sel;

async function foto(page, nombre, completa = false) {
    await page.screenshot({ path: path.join(EVID, nombre), fullPage: completa });
}

async function modoOscuro(page, activo) {
    await page.evaluate((a) => document.documentElement.classList.toggle('dark', a), activo);
}

/** Axios de la app (para simular una respuesta perdida DESPUES de que el servidor guardo). */
async function perderProximaRespuesta(page, fragmentoUrl) {
    await page.evaluate((frag) => {
        const app = document.querySelector('#app')?.__vue_app__;
        const ax = app?.config?.globalProperties?.$axios;
        if (! ax) {
            throw new Error('sin axios de la app');
        }
        window.__perder = frag;
        if (! window.__interceptorPerdida) {
            window.__interceptorPerdida = ax.interceptors.response.use((r) => {
                if (window.__perder && r.config.url.includes(window.__perder)) {
                    window.__perder = null;
                    window.__respuestaPerdida = r.data;
                    return Promise.reject(Object.assign(new Error('Network Error'), { config: r.config, request: {}, response: undefined }));
                }
                return r;
            });
        }
    }, fragmentoUrl);
}

/** Contraste WCAG del texto de cada selector contra su fondo efectivo (espera a que terminen las transiciones de Bagisto). */
async function contrastes(page, selectores) {
    await new Promise((r) => setTimeout(r, 1000));
    return page.evaluate((sels) => {
        const rgb = (s) => (s.match(/[\d.]+/g) || []).map(Number);
        const lum = ([r, g, b]) => { const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b); };
        const fondo = (el) => { for (let n = el; n; n = n.parentElement) { const c = rgb(getComputedStyle(n).backgroundColor); if (c.length === 3 || c[3] > 0.5) return c.slice(0, 3); } return [255, 255, 255]; };
        const ratio = (a, b) => { const [x, y] = [lum(a), lum(b)].sort((p, q) => q - p); return Math.round((x + 0.05) / (y + 0.05) * 10) / 10; };
        const out = {};
        for (const [k, s] of Object.entries(sels)) {
            const [css, pseudo] = s.split("::");
            const el = [...document.querySelectorAll(css)].find((e) => e.offsetParent !== null && ! e.disabled);
            out[k] = el ? ratio(rgb(getComputedStyle(el, pseudo ? "::" + pseudo : null).color).slice(0, 3), fondo(el)) : null;
        }
        return out;
    }, selectores);
}

module.exports = { BASE, EVID, CRED, abrir, login, ventaConCliente, clicTexto, escribir, lineas, interpretar, esperarEstado, enLinea, foto, modoOscuro, perderProximaRespuesta, contrastes };
