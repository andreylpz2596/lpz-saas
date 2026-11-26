// Doc. 34 · NAVEGADOR (demo local, autenticacion real): flujo principal en escritorio.
// Uso: PUPPETEER_DIR=<node_modules con puppeteer-core> DEMO_CLAVE=<archivo fuera del repo> EVID=<carpeta> node navegador_flujo.cjs
const c = require('./comun.cjs');

(async () => {
    const pasos = [];
    const paso = (nombre, ok, detalle = null) => { pasos.push({ paso: nombre, ok: !! ok, detalle }); console.log((ok ? 'OK  ' : 'FALLA ') + nombre + (detalle && ! ok ? ' · ' + JSON.stringify(detalle).slice(0, 400) : '')); };
    const { browser, page, errores } = await c.abrir({ ancho: 1366, alto: 900 });
    const L = (i, sel) => c.enLinea(i, sel);

    try {
        paso('login con la asesora de prueba (autenticacion real)', await c.login(page));
        await c.ventaConCliente(page);
        paso('cliente elegido y panel «Dictar pedido» abierto', true);

        // 1. Orden libre + duda talla/cantidad + crop top sin talla + color ambiguo + palabra no usada.
        let ls = await c.interpretar(page, 'dos rojas M de dama, negro caballero L 3, rojo 10 niño, crop top negro 2, verde M dama 3, negro camel dama S 1', 6);
        paso('6 lineas, cada una con su estado en texto', ls.length === 6 && ls.every((l) => l.etiqueta), ls.map((l) => l.etiqueta + ' | ' + l.texto.slice(0, 100)));
        paso('L1 «dos rojas M de dama» → Dama · Rojo · M · 2 (110D-DZ-M), Lista', ls[0].estado === 'ok' && /110D-DZ-M/.test(ls[0].texto), ls[0]);
        paso('L3 «rojo 10 niño» → botones «Talla 10-12» / «10 unidades»', /Talla 10-12/.test(ls[2].texto) && /10 unidades/.test(ls[2].texto), ls[2]);
        paso('L4 crop top Negro sin talla: existe en M y Única → pregunta (no asume Única)', /M y Única/.test(ls[3].texto) && /Talla Única/.test(ls[3].texto), ls[3]);
        paso('L5 «verde» ambiguo → ofrece los verdes', /Verde Menta/.test(ls[4].texto), ls[4]);
        paso('L6 palabra no usada «camel» visible como aviso', ls[5].estado === 'aviso' && /camel/.test(ls[5].texto), ls[5]);
        await c.foto(page, 'A1_interpretado_escritorio_claro.png', true);
        await c.modoOscuro(page, true);
        await c.foto(page, 'A1_interpretado_escritorio_oscuro.png', true);
        await c.modoOscuro(page, false);

        // 2. Opciones: «Talla 10-12» conserva lo valido y deja el foco en la cantidad vacia.
        await c.clicTexto(page, 'button', /^Talla 10-12$/, 10000, '[data-dictado-linea="2"]');
        await page.waitForSelector(L(2, '[data-venta="dictado-editor"]'));
        await new Promise((r) => setTimeout(r, 300));
        const foco = await page.evaluate(() => document.activeElement?.getAttribute('data-venta'));
        const cantidadVacia = await page.$eval(L(2, '[data-venta="dictado-cantidad"]'), (n) => n.value);
        paso('elegir «Talla 10-12» abre el editor con el foco en la cantidad (vacia, no se completa sola)', foco === 'dictado-cantidad' && cantidadVacia === '', { foco, cantidadVacia });
        await c.escribir(page, L(2, '[data-venta="dictado-cantidad"]'), '4');
        await page.click(L(2, '[data-venta="dictado-validar"]'));
        await c.esperarEstado(page, 2, ['ok', 'aviso']);
        paso('L3 validada: Niño · Rojo · 10-12 · 4 (110N-DZ-10)', /110N-DZ-10/.test((await c.lineas(page))[2].texto));

        await c.clicTexto(page, 'button', /^Talla Única$/, 10000, '[data-dictado-linea="3"]');
        await c.esperarEstado(page, 3, ['ok', 'aviso']);
        paso('L4 crop top: «Talla Única» con la cantidad dicha (2) se valida directo (106D-TP-U)', /106D-TP-U/.test((await c.lineas(page))[3].texto));

        await c.clicTexto(page, 'button', /^Verde Menta$/, 10000, '[data-dictado-linea="4"]');
        // La linea ya estaba en «error» antes de validar: se espera a que cambie (otra respuesta del servidor), no a un estado.
        await page.waitForFunction(() => {
            const n = document.querySelector('[data-dictado-linea="4"]');
            return n && (n.getAttribute('data-dictado-estado') !== 'error' || /No existe/.test(n.innerText)) && ! /¿Cuál\?/.test(n.innerText);
        }, { timeout: 20000 });
        ls = await c.lineas(page);
        paso('L5 color elegido en las opciones y revalidado con el catalogo', ls[4].estado !== 'error' || /No existe/.test(ls[4].etiqueta), ls[4]);
        if (ls[4].estado === 'error') {
            await page.click(L(4, '[data-venta="dictado-omitir"]'));
        }

        // 3. Corregir: otro color invalida la variante anterior (sin SKU oculto) y conserva tipo y talla validos.
        await page.click(L(1, '[data-venta="dictado-corregir"]'));
        await page.select(L(1, '[data-venta="dictado-color"]'), 'Blanco');
        const sinSku = await page.$eval('[data-dictado-linea="1"]', (n) => n.getAttribute('data-dictado-estado') === 'error' && ! n.querySelector('[data-venta="dictado-efecto"]'));
        const tipoTalla = await page.$$eval(L(1, 'select'), (ns) => ns.map((s) => s.value));
        paso('cambiar el color quita la variante anterior y conserva Caballero y L (validos para Blanco)', sinSku && tipoTalla[1] === 'H' && tipoTalla[2] === 'L', tipoTalla);
        const tallas = await page.$$eval(L(1, '[data-venta="dictado-talla"] option'), (ns) => ns.map((o) => o.textContent.trim()));
        paso('las tallas salen del catalogo real (Blanco Caballero incluye XXL)', tallas.includes('XXL'), tallas);
        await page.click(L(1, '[data-venta="dictado-validar"]'));
        await c.esperarEstado(page, 1, ['stock', 'ok', 'aviso']);
        ls = await c.lineas(page);
        const deshabilitado = await page.$eval('[data-venta="dictado-agregar"]', (b) => b.disabled);
        paso('124H-DZ-L: 3 dictadas y 2 en inventario → «Sin stock suficiente», no se rebaja sola y bloquea agregar',
            ls[1].estado === 'stock' && /hay 2 disponibles/.test(ls[1].texto) && deshabilitado, ls[1]);
        await c.foto(page, 'A2_stock_insuficiente_escritorio_claro.png', true);
        await page.click(L(1, '[data-venta="dictado-corregir"]'));
        await c.escribir(page, L(1, '[data-venta="dictado-cantidad"]'), '2');
        await page.click(L(1, '[data-venta="dictado-validar"]'));
        await c.esperarEstado(page, 1, ['ok', 'aviso']);
        paso('con 2 unidades la linea queda lista', (await c.lineas(page))[1].estado === 'ok');

        // 4. Omitir / volver a incluir / todas omitidas.
        await page.click(L(5, '[data-venta="dictado-omitir"]'));
        const resumen = await page.$eval('[data-venta="dictado-resumen"]', (n) => n.innerText);
        paso('omitir: el resumen cuenta la omitida', /1\s*omitida/.test(resumen), resumen);
        await page.click(L(5, '[data-venta="dictado-omitir"]'));
        paso('volver a incluir', (await c.lineas(page))[5].estado !== 'omitida');
        const n = (await c.lineas(page)).length;
        const omitidasAntes = (await c.lineas(page)).map((l) => l.estado === 'omitida');
        for (let i = 0; i < n; i++) {
            if (! omitidasAntes[i]) {
                await page.click(L(i, '[data-venta="dictado-omitir"]'));
            }
        }
        const msg = await page.$eval('[data-venta="dictado-pendientes"]', (x) => x.innerText).catch(() => '');
        const bloqueado = await page.$eval('[data-venta="dictado-agregar"]', (b) => b.disabled);
        paso('todas omitidas: «no hay nada para agregar» y el boton bloqueado', /Todas las líneas están omitidas/.test(msg) && bloqueado, msg);
        for (let i = 0; i < n; i++) {
            if (! omitidasAntes[i]) {
                await page.click(L(i, '[data-venta="dictado-omitir"]'));
            }
        }
        await page.click(L(5, '[data-venta="dictado-omitir"]'));
        const efectos = await page.$eval('[data-venta="dictado-efectos"]', (x) => x.innerText);
        paso('efecto en la venta por referencia antes de agregar', /110D-DZ-M: ya hay 0 \+ dictadas 2 = quedarán 2/.test(efectos), efectos);
        await c.foto(page, 'A3_listo_para_agregar_escritorio_claro.png', true);

        // 5. Agregar: lo confirma el servidor y aparece en el resumen de la venta.
        const etiqueta = await page.$eval('[data-venta="dictado-agregar"]', (b) => b.innerText);
        await page.click('[data-venta="dictado-agregar"]');
        await page.waitForSelector('[data-venta="dictado-hecho"]', { timeout: 30000 });
        const hecho = await page.$eval('[data-venta="dictado-hecho"]', (x) => x.innerText);
        paso('agregar: el servidor dice había + agregado = quedan', /110D-DZ-M: había 0 \+ 2 = quedan 2/.test(hecho), { etiqueta, hecho });
        const venta = await page.$$eval('[data-resumen-producto]', (ns) => ns.map((x) => x.innerText.replace(/\s+/g, ' ').trim()));
        paso('el resumen de la venta muestra lo agregado', venta.some((t) => /M × 2/.test(t)), venta);
        await c.foto(page, 'A4_agregado_escritorio_claro.png', true);

        // 6. Variante ya presente + dos lineas del mismo SKU: 2 + (2 + 1) = 5.
        await c.interpretar(page, 'dos rojas M de dama, una roja M de dama', 2);
        const efectos2 = await page.$eval('[data-venta="dictado-efectos"]', (x) => x.innerText);
        paso('ya hay 2 + dictadas 3 (2 lineas) = quedaran 5', /110D-DZ-M: ya hay 2 \+ dictadas 3 \(2 líneas\) = quedarán 5/.test(efectos2), efectos2);
        await page.click('[data-venta="dictado-agregar"]');
        await page.waitForSelector('[data-venta="dictado-hecho"]', { timeout: 30000 });
        const hecho2 = await page.$eval('[data-venta="dictado-hecho"]', (x) => x.innerText);
        paso('agregado: había 2 + 3 = quedan 5', /había 2 \+ 3 = quedan 5/.test(hecho2), hecho2);

        // 7. Lapiz despues de agregar: cambia SOLO ese producto.
        const antes = await page.$$eval('[data-resumen-producto]', (ns) => ns.map((x) => x.innerText.replace(/\s+/g, ' ').trim()));
        await page.evaluate(() => [...document.querySelectorAll('[data-resumen-producto]')].find((x) => /M × 5/.test(x.innerText)).querySelector('button[aria-label^="Editar tallas"]').click());
        await page.waitForFunction(() => [...document.querySelectorAll('tr')].some((tr) => /^\s*M\b/.test(tr.innerText) && tr.querySelector('input[type="number"]')), { timeout: 20000 });
        await page.evaluate(() => {
            const tr = [...document.querySelectorAll('tr')].find((t) => /^\s*M\b/.test(t.innerText) && t.querySelector('input[type="number"]'));
            const input = tr.querySelector('input[type="number"]');
            input.value = '6';
            input.dispatchEvent(new Event('input', { bubbles: true }));
        });
        await c.clicTexto(page, 'button', /^\s*Guardar cambios\s*$/);
        await page.waitForFunction(() => [...document.querySelectorAll('[data-resumen-producto]')].some((x) => /M × 6/.test(x.innerText)), { timeout: 30000 });
        const despues = await page.$$eval('[data-resumen-producto]', (ns) => ns.map((x) => x.innerText.replace(/\s+/g, ' ').trim()));
        paso('lapiz: M pasa de 5 a 6 y los demas productos no cambian', antes.filter((t) => ! /M × 5/.test(t)).every((t) => despues.includes(t)), { antes, despues });
        await c.foto(page, 'A5_lapiz_despues_de_agregar_escritorio.png');
    } catch (e) {
        paso('EXCEPCION', false, String(e && e.stack || e).slice(0, 600));
        await c.foto(page, 'A_error.png', true).catch(() => {});
    } finally {
        paso('sin errores de JavaScript en la pagina', errores.length === 0, errores);
        await browser.close();
        const fallas = pasos.filter((p) => ! p.ok).length;
        console.log(JSON.stringify({ guion: 'navegador_flujo', pasos: pasos.length, fallas }));
        process.exit(fallas ? 1 : 0);
    }
})();
