// Doc. 34 · NAVEGADOR (demo local, autenticacion real): red caida, respuesta perdida, doble clic, catalogo que no carga y
// rechazo del servidor. Comprueba el estado GUARDADO (resumen de la venta y base de la demo), no solo lo que dice la pantalla.
const { execSync } = require('child_process');
const c = require('./comun.cjs');

const MYSQL = process.env.MYSQL || 'C:/xampp/mysql/bin/mysql.exe';
const BASE_DEMO = process.env.DEMO_BD || 'lpz_dictado_demo_20261126';
const sql = (q) => execSync(`"${MYSQL}" -uroot ${BASE_DEMO} -N -e "${q.replace(/"/g, '\\"')}"`).toString().replace(/\r/g, '').trim();

(async () => {
    const pasos = [];
    const paso = (nombre, ok, detalle = null) => { pasos.push({ paso: nombre, ok: !! ok, detalle }); console.log((ok ? 'OK  ' : 'FALLA ') + nombre + (detalle && ! ok ? ' · ' + JSON.stringify(detalle).slice(0, 400) : '')); };
    const { browser, page, errores } = await c.abrir({ ancho: 1366, alto: 900 });
    let bloquear = null;
    const peticiones = [];
    await page.setRequestInterception(true);
    page.on('request', (r) => {
        if (r.url().includes('/dictado/')) {
            peticiones.push(r.url().split('/dictado/')[1]);
        }
        if (bloquear && r.url().includes(bloquear)) {
            bloquear = null;
            r.abort('internetdisconnected');
        } else {
            r.continue();
        }
    });
    const enVenta = () => page.$$eval('[data-resumen-producto]', (ns) => ns.map((x) => x.innerText.replace(/\s+/g, ' ').trim()));
    const cantidadDe = async (patronProducto, talla) => {
        const filas = await enVenta();
        const fila = filas.find((t) => new RegExp(patronProducto).test(t)) || '';
        const m = fila.match(new RegExp('(?:^|\\s)' + talla + ' × (\\d+)'));
        return m ? Number(m[1]) : 0;
    };
    const variante = (sku) => Number(sql(`SELECT id FROM products WHERE sku='${sku}'`));

    try {
        paso('login (autenticacion real)', await c.login(page));
        await c.ventaConCliente(page);

        // 1. Interpretar sin red: mensaje claro y el texto se conserva.
        await c.escribir(page, '[data-venta="dictado-texto"]', 'negro caballero M 3');
        bloquear = 'dictado/interpretar';
        await page.click('[data-venta="dictado-interpretar"]');
        await page.waitForSelector('[data-venta="dictado-error"]');
        const errorRed = await page.$eval('[data-venta="dictado-error"]', (x) => x.innerText);
        const textoConservado = await page.$eval('[data-venta="dictado-texto"]', (x) => x.value);
        paso('interpretar sin red: «No hay conexión…» y el texto se conserva', /No hay conexión/.test(errorRed) && textoConservado === 'negro caballero M 3', { errorRed, textoConservado });

        // 2. Catalogo que no llega: aviso de carga (no «no existe»).
        await page.evaluate(() => {
            const ax = document.querySelector('#app').__vue_app__.config.globalProperties.$axios;
            window.__sinCatalogo = true;
            ax.interceptors.response.use((r) => {
                if (window.__sinCatalogo && r.config.url.includes('dictado/interpretar')) {
                    window.__sinCatalogo = false;
                    r.data.catalogo = [];
                }
                return r;
            });
        });
        let ls = await c.interpretar(page, 'negro caballero M 3', 1);
        const avisoCatalogo = await page.$eval('[data-venta="dictado"]', (x) => x.innerText);
        paso('catalogo no disponible: aviso de carga, sin decir «no existe»', /No se pudo cargar el catálogo/.test(avisoCatalogo) && ! /No existe/.test(ls[0].etiqueta), ls[0]);
        ls = await c.interpretar(page, 'negro caballero M 3', 1);
        paso('al reinterpretar, el catalogo vuelve', ! /No se pudo cargar el catálogo/.test(await page.$eval('[data-venta="dictado"]', (x) => x.innerText)) && ls[0].estado === 'ok');

        // 3. Agregar sin red ANTES de llegar al servidor: «no sabemos», edicion bloqueada, reintentar guarda una vez.
        bloquear = 'dictado/agregar';
        await page.click('[data-venta="dictado-agregar"]');
        await page.waitForSelector('[data-venta="dictado-incierto"]', { timeout: 20000 });
        const corregirBloqueado = await page.$eval('[data-venta="dictado-corregir"]', (b) => b.disabled);
        const textoBloqueado = await page.$eval('[data-venta="dictado-texto"]', (x) => x.readOnly);
        paso('sin red al agregar: «No sabemos si se guardó» + Reintentar; corregir y el texto quedan bloqueados', corregirBloqueado && textoBloqueado);
        await c.foto(page, 'B1_respuesta_incierta_escritorio.png');
        await page.click('[data-venta="dictado-reintentar"]');
        await page.waitForSelector('[data-venta="dictado-hecho"]', { timeout: 30000 });
        const hecho1 = await page.$eval('[data-venta="dictado-hecho"]', (x) => x.innerText);
        paso('reintentar: se agrega (la primera nunca llego) y la venta tiene 3', /Agregado a la venta/.test(hecho1) && await cantidadDe('Negro', 'M') === 3, { hecho1, venta: await enVenta() });

        // 4. Respuesta perdida DESPUES de guardar: el reintento con la misma operacion no duplica.
        await c.interpretar(page, 'negro caballero M 2', 1);
        await c.perderProximaRespuesta(page, 'dictado/agregar');
        await page.click('[data-venta="dictado-agregar"]');
        await page.waitForSelector('[data-venta="dictado-incierto"]', { timeout: 30000 });
        const enBaseTrasPerdida = Number(sql(`SELECT COUNT(*) FROM bylopez_va_dictado_operaciones WHERE estado='aplicada'`));
        await page.click('[data-venta="dictado-reintentar"]');
        await page.waitForSelector('[data-venta="dictado-hecho"]', { timeout: 30000 });
        const hecho2 = await page.$eval('[data-venta="dictado-hecho"]', (x) => x.innerText);
        const negroM = await cantidadDe('Negro', 'M');
        paso('respuesta perdida tras guardar: el reintento dice «Ya estaba agregado: no se duplicó» y quedan 3 + 2 = 5 (no 7)',
            /no se duplicó/.test(hecho2) && negroM === 5, { hecho2, negroM, operacionesAplicadas: enBaseTrasPerdida });
        await c.foto(page, 'B2_reintento_sin_duplicar_escritorio.png');

        // 5. Doble clic: una sola peticion y una sola suma.
        await c.interpretar(page, 'negro caballero M 1', 1);
        const antes = peticiones.filter((p) => p.startsWith('agregar')).length;
        await page.evaluate(() => { const b = document.querySelector('[data-venta="dictado-agregar"]'); b.click(); b.click(); });
        await page.waitForSelector('[data-venta="dictado-hecho"]', { timeout: 30000 });
        await new Promise((r) => setTimeout(r, 1500));
        const enviadas = peticiones.filter((p) => p.startsWith('agregar')).length - antes;
        paso('doble clic: una sola peticion y la venta pasa de 5 a 6', enviadas === 1 && await cantidadDe('Negro', 'M') === 6, { enviadas, venta: await enVenta() });

        // 6. Rechazo claro del servidor: el stock cambia entre interpretar y agregar → no se agrega NADA (ni la otra linea).
        const blancoM = variante('124D-DZ-M');
        ls = await c.interpretar(page, 'negro caballero M 1, blanca dama M 4', 2);
        sql(`UPDATE product_inventory_indices SET qty=2 WHERE product_id=${blancoM}`);
        sql(`UPDATE product_inventories SET qty=2 WHERE product_id=${blancoM}`);
        await page.click('[data-venta="dictado-agregar"]');
        await page.waitForFunction(() => /No se agregó nada/.test(document.querySelector('[data-venta="dictado-error"]')?.innerText || ''), { timeout: 30000 });
        const errorServidor = await page.$eval('[data-venta="dictado-error"]', (x) => x.innerText);
        ls = await c.lineas(page);
        const negroSinCambio = await cantidadDe('Negro', 'M');
        paso('stock cambiado entre interpretar y agregar: «No se agregó nada», la linea marcada y la otra tampoco se agrego',
            negroSinCambio === 6 && ls[1].estado === 'error' && /No se pudo agregar/.test(ls[1].etiqueta), { errorServidor, ls, negroSinCambio });
        await c.foto(page, 'B3_rechazo_servidor_escritorio.png');
        sql(`UPDATE product_inventory_indices SET qty=20 WHERE product_id=${blancoM}`);
        sql(`UPDATE product_inventories SET qty=20 WHERE product_id=${blancoM}`);
    } catch (e) {
        paso('EXCEPCION', false, String(e && e.stack || e).slice(0, 600));
        await c.foto(page, 'B_error.png', true).catch(() => {});
    } finally {
        // Los errores de red provocados a proposito aparecen en consola: solo cuentan los de JavaScript.
        const reales = errores.filter((e) => ! /net::ERR_INTERNET_DISCONNECTED|Network Error|status of 4\d\d/.test(e));
        paso('sin errores de JavaScript en la pagina', reales.length === 0, reales);
        await browser.close();
        const fallas = pasos.filter((p) => ! p.ok).length;
        console.log(JSON.stringify({ guion: 'navegador_robustez', pasos: pasos.length, fallas }));
        process.exit(fallas ? 1 : 0);
    }
})();
