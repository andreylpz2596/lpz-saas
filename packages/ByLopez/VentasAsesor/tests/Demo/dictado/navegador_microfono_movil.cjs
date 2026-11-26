// Doc. 34 · NAVEGADOR (demo local, autenticacion real): microfono con EVENTOS SIMULADOS (no es audio real) y vista movil 390 px.
// El reconocimiento del navegador se reemplaza por uno falso que la prueba controla: provisional → final, final repetido,
// repeticion legitima, eventos tardios, detener y reanudar, edicion a mano, permiso denegado, interrupcion y sin soporte.
const c = require('./comun.cjs');

const RECONOCIMIENTO_FALSO = () => {
    window.__recs = [];
    class Falso {
        constructor() { window.__recs.push(this); this.inicios = 0; this.detenido = false; this.abortado = false; }
        start() { this.inicios++; }
        stop() { this.detenido = true; setTimeout(() => this.onend && this.onend(), 30); }
        abort() { this.abortado = true; setTimeout(() => this.onend && this.onend(), 10); }
    }
    window.SpeechRecognition = Falso;
    window.webkitSpeechRecognition = Falso;
    // resultados: TODOS los de la sesion [[texto, final], ...] como los entrega el navegador; resultIndex = primero que cambio.
    window.__evento = (n, resultIndex, resultados) => {
        const rec = window.__recs[n];
        const results = resultados.map(([t, f]) => Object.assign([{ transcript: t }], { isFinal: f }));
        rec.onresult && rec.onresult({ resultIndex, results });
    };
    window.__error = (n, error) => window.__recs[n].onerror && window.__recs[n].onerror({ error });
};

(async () => {
    const pasos = [];
    const paso = (nombre, ok, detalle = null) => { pasos.push({ paso: nombre, ok: !! ok, detalle }); console.log((ok ? 'OK  ' : 'FALLA ') + nombre + (detalle && ! ok ? ' · ' + JSON.stringify(detalle).slice(0, 400) : '')); };
    const texto = (page) => page.$eval('[data-venta="dictado-texto"]', (x) => x.value);
    const mic = (page) => page.$eval('[data-venta="dictado-mic"]', (x) => x.innerText.trim());
    const pausa = (ms) => new Promise((r) => setTimeout(r, ms));
    let navegador;

    try {
        // ---------- A. Microfono simulado (escritorio) ----------
        const a = await c.abrir({ ancho: 1366, alto: 900, iniciar: RECONOCIMIENTO_FALSO });
        navegador = a.browser;
        const page = a.page;
        const agregar = [];
        page.on('request', (r) => { if (r.url().includes('dictado/agregar')) agregar.push(r.url()); });
        paso('login (autenticacion real)', await c.login(page));
        await c.ventaConCliente(page);

        await page.click('[data-venta="dictado-hablar"]');
        paso('Hablar: estado «Escuchando» visible', /Escuchando/.test(await mic(page)), await mic(page));
        await page.evaluate(() => window.__evento(0, 0, [['dos rojas', false]]));
        const prov = await texto(page);
        await page.evaluate(() => window.__evento(0, 0, [['dos rojas M de dama', true]]));
        await page.evaluate(() => window.__evento(0, 1, [['dos rojas M de dama', true], ['negro', false]]));
        await page.evaluate(() => window.__evento(0, 1, [['dos rojas M de dama', true], ['negro caballero L 3', true]]));
        const t1 = await texto(page);
        paso('provisional → final: el provisional se reemplaza, no se agrega otra linea', prov === 'dos rojas' && t1 === 'dos rojas M de dama, negro caballero L 3', { prov, t1 });

        await page.evaluate(() => window.__evento(0, 1, [['dos rojas M de dama', true], ['negro caballero L 3', true]]));
        paso('el MISMO evento final repetido no duplica', await texto(page) === t1, await texto(page));

        await page.evaluate(() => window.__evento(0, 2, [['dos rojas M de dama', true], ['negro caballero L 3', true], ['negro caballero L 3', true]]));
        const t2 = await texto(page);
        paso('dos frases iguales dichas a proposito (otro indice) se conservan las dos', t2 === 'dos rojas M de dama, negro caballero L 3, negro caballero L 3', t2);

        await page.click('[data-venta="dictado-hablar"]');
        await pausa(200);
        await page.evaluate(() => window.__evento(0, 3, [['dos rojas M de dama', true], ['negro caballero L 3', true], ['negro caballero L 3', true], ['blanco', true]]));
        paso('Detener: el evento tardio despues de terminar no toca el texto', await texto(page) === t2 && ! /Escuchando/.test(await mic(page)), { texto: await texto(page), mic: await mic(page) });

        await page.click('[data-venta="dictado-hablar"]');
        await page.evaluate(() => window.__evento(1, 0, [['una lila S dama', true]]));
        const t3 = await texto(page);
        paso('detener y volver a iniciar: continua despues de lo que habia', t3 === t2 + ', una lila S dama', t3);

        await page.focus('[data-venta="dictado-texto"]');
        await page.keyboard.press('End');
        await page.keyboard.type(' y verde menta dama M 1');
        await pausa(100);
        const editado = await texto(page);
        await page.evaluate(() => window.__evento(1, 1, [['una lila S dama', true], ['caballero azul', true]]));
        await pausa(100);
        const trasEvento = await texto(page);
        const abortado = await page.evaluate(() => window.__recs[1].abortado);
        paso('edicion a mano mientras escucha: el microfono se detiene y un evento posterior no borra lo escrito',
            /tu texto no se reemplaza/.test(await mic(page)) && abortado && trasEvento === editado && editado.endsWith('verde menta dama M 1'), { editado, trasEvento, mic: await mic(page) });
        paso('nunca agrega solo al terminar de hablar', agregar.length === 0, agregar);

        await page.click('[data-venta="dictado-hablar"]');
        await page.evaluate(() => window.__error(2, 'not-allowed'));
        await pausa(100);
        paso('permiso denegado: mensaje claro y se puede seguir escribiendo', /bloqueado para este sitio/.test(await mic(page)) && ! await page.$eval('[data-venta="dictado-texto"]', (x) => x.readOnly), await mic(page));
        await page.click('[data-venta="dictado-hablar"]');
        await page.evaluate(() => window.__error(3, 'network'));
        await pausa(100);
        paso('interrupcion del reconocimiento: se informa y el texto se conserva', /se interrumpió \(network\)/.test(await mic(page)) && await texto(page) === trasEvento, await mic(page));

        const ls = await c.interpretar(page, await texto(page), 5);
        paso('lo dictado (y lo escrito) se interpreta como siempre', ls.length === 5, ls.map((l) => l.etiqueta + ' | ' + l.texto.slice(0, 80)));
        await navegador.close();

        // ---------- B. Navegador sin reconocimiento de voz ----------
        const b = await c.abrir({ ancho: 1366, alto: 900, iniciar: () => { delete window.SpeechRecognition; delete window.webkitSpeechRecognition; window.SpeechRecognition = undefined; window.webkitSpeechRecognition = undefined; } });
        navegador = b.browser;
        await c.login(b.page);
        await c.ventaConCliente(b.page);
        await b.page.click('[data-venta="dictado-hablar"]');
        await pausa(100);
        paso('sin soporte de voz: lo explica y el cuadro sigue sirviendo para escribir', /no reconoce la voz/.test(await mic(b.page)) && ! await b.page.$eval('[data-venta="dictado-texto"]', (x) => x.readOnly), await mic(b.page));
        await navegador.close();

        // ---------- C. Movil 390 px, claro y oscuro ----------
        const m = await c.abrir({ ancho: 390, alto: 844, movil: true, iniciar: RECONOCIMIENTO_FALSO });
        navegador = m.browser;
        await c.login(m.page);
        await c.ventaConCliente(m.page);
        await c.interpretar(m.page, 'dos rojas M de dama, rojo 10 niño, crop top negro 2, verde M dama 3', 4);
        const medidas = await m.page.evaluate(() => ({
            ancho: document.documentElement.scrollWidth, ventana: window.innerWidth,
            etiquetas: getComputedStyle(document.querySelector('.nv-dic-celda-etq')).display,
            cabecera: getComputedStyle(document.querySelector('.nv-dic-cabecera')).display,
            letraTexto: parseFloat(getComputedStyle(document.querySelector('[data-venta="dictado-texto"]')).fontSize),
        }));
        paso('390 px: sin desborde horizontal, tarjetas con etiquetas y sin cabecera de tabla', medidas.ancho <= medidas.ventana && medidas.etiquetas !== 'none' && medidas.cabecera === 'none', medidas);
        paso('390 px: letra de 16 px en el cuadro (iPhone no hace zoom al tocar)', medidas.letraTexto >= 16, medidas.letraTexto);
        await m.page.evaluate(() => document.querySelector('[data-dictado-linea="1"]').scrollIntoView({ block: 'center' }));
        await pausa(300);
        const boton = await m.page.evaluate(() => {
            const r = document.querySelector('[data-venta="dictado-agregar"]').getBoundingClientRect();
            return { arriba: r.top, abajo: r.bottom, alto: window.innerHeight };
        });
        paso('390 px: el boton principal queda visible (fijo abajo) mientras se revisan las lineas', boton.abajo <= boton.alto && boton.arriba >= 0, boton);
        await c.foto(m.page, 'C1_movil_390_claro.png', true);
        await c.foto(m.page, 'C1_movil_390_claro_pantalla.png');
        await c.modoOscuro(m.page, true);
        await c.foto(m.page, 'C2_movil_390_oscuro.png', true);
        await c.foto(m.page, 'C2_movil_390_oscuro_pantalla.png');
        await c.modoOscuro(m.page, false);

        // Teclado: en movil, elegir una opcion lleva el foco al campo pendiente.
        await c.clicTexto(m.page, 'button', /^Talla 10-12$/, 10000, '[data-dictado-linea="1"]');
        await pausa(400);
        const foco = await m.page.evaluate(() => document.activeElement?.getAttribute('data-venta'));
        paso('390 px: elegir «Talla 10-12» pone el foco en la cantidad', foco === 'dictado-cantidad', foco);
        await c.foto(m.page, 'C3_movil_390_opcion_elegida.png');
        await navegador.close();
        navegador = null;
        paso('sin errores de JavaScript en la pagina', [...a.errores, ...b.errores, ...m.errores].length === 0, [...a.errores, ...b.errores, ...m.errores]);
    } catch (e) {
        paso('EXCEPCION', false, String(e && e.stack || e).slice(0, 600));
    } finally {
        if (navegador) {
            await navegador.close().catch(() => {});
        }
        const fallas = pasos.filter((p) => ! p.ok).length;
        console.log(JSON.stringify({ guion: 'navegador_microfono_movil', pasos: pasos.length, fallas }));
        process.exit(fallas ? 1 : 0);
    }
})();
