// Recorrido REAL de navegador: adjuntar -> lectura OCR local -> revisar/corregir -> confirmar, contra la DEMO aislada.
// Uso: DEMO_CC_PASSWORD=... DEMO_TALLER=1511 ARCHIVOS=<carpeta> ANCHO=1440|390 PLAYWRIGHT_CORE=<ruta> node recorrido_comprobantes.cjs [salida]
// ARCHIVOS debe contener: nequi.jpg, daviplata.jpg, corresponsal.jpg, bold.jpg (los 4 comprobantes reales), nequi.png, nequi.webp,
//   nequi_otra_captura.jpg, foto_nequi.jpg, tela_sin_texto.jpg, daviplata_escaneado.pdf, texto.pdf.
// NO registra pagos reales: la base es aislada (lpz_saas_demo_*) y al final el ayudante PHP revierte lo creado y restaura el arranque.
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');
const pw = require(process.env.PLAYWRIGHT_CORE || 'playwright-core');

const base = process.env.BASE_URL || 'http://127.0.0.1:8030';
const taller = process.env.DEMO_TALLER || '1511';
const dir = process.env.ARCHIVOS;
const ancho = parseInt(process.env.ANCHO || '1440', 10);
const motorWebkit = process.env.MOTOR === 'webkit';
const salida = path.resolve(process.argv[2] || 'capturas_comprobantes');
fs.mkdirSync(salida, { recursive: true });
const ayudante = path.resolve(__dirname, 'consulta_demo_comprobantes.php');
const php = (args) => execSync(`php "${ayudante}" ${args}`, { cwd: path.resolve(__dirname, '../../../../..'), env: process.env }).toString().trim();
const estadoDb = () => JSON.parse(php('estado'));
const f = (n) => path.join(dir, n);
const ARRANQUE_ORIGINAL = process.env.ARRANQUE_ORIGINAL || '2026-10-31';

const pasos = [];
const tiempos = {};
const reg = (nombre, ok, detalle) => { pasos.push({ nombre, ok: !!ok, detalle }); console.log(`${ok ? 'OK   ' : 'FALLA'} ${nombre}${detalle !== undefined ? ' ' + JSON.stringify(detalle) : ''}`); };
const digitos = (t) => String(t || '').replace(/\D/g, '');

(async () => {
    const movil = ancho < 600;
    const browser = motorWebkit
        ? await pw.webkit.launch()
        : await pw.chromium.launch({ executablePath: process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
    const ctx = await browser.newContext(motorWebkit
        ? { ...pw.devices['iPhone 15 Pro Max'], locale: 'es-CO' }
        : { viewport: { width: ancho, height: movil ? 844 : 950 }, locale: 'es-CO', isMobile: movil, hasTouch: movil });
    const page = await ctx.newPage();
    const errores = [];
    page.on('pageerror', (e) => { if (!/_boost/.test(String(e))) { errores.push(String(e).slice(0, 200)); } });
    const respuestas = [];
    page.on('response', (r) => { if (r.url().includes('/comprobantes')) { r.json().then((j) => respuestas.push({ status: r.status(), json: j })).catch(() => {}); } });
    page.on('pageerror', (e) => console.log('  [pageerror]', String(e).slice(0, 200)));

    await page.goto(base + '/admin/login', { waitUntil: 'domcontentloaded', timeout: 90000 });
    await page.waitForLoadState('load'); await page.waitForTimeout(3000); // deja montar Vue antes de escribir
    await page.fill('input[name=email]', 'cc.admin@bylopez.test');
    await page.fill('input[name=password]', process.env.DEMO_CC_PASSWORD || '');
    await page.click('form button');
    await page.waitForURL((u) => !u.pathname.endsWith('/admin/login'), { timeout: 90000 });
    await page.waitForTimeout(3500);

    const cuenta = base + `/admin/corte-confeccion/cartera/taller/${taller}`;
    const modal = page.locator('#c-modal-movimiento');
    const abrir = async () => {
        await page.goto(cuenta, { waitUntil: 'domcontentloaded', timeout: 90000 });
        // Bagisto monta Vue despues de cargar sus scripts y reemplaza los nodos: se espera al montaje antes de interactuar.
        await page.waitForFunction(() => document.querySelector('#app') && document.querySelector('#app').hasAttribute('data-v-app'), null, { timeout: 90000 });
        await page.waitForTimeout(500);
        await page.click('[data-c-abrir="movimiento"]');
        await page.waitForSelector('#c-modal-movimiento:not(.hidden)');
    };
    const tarjeta = (i) => modal.locator('[data-c="ops"] [data-uid]').nth(i);
    const campo = (i, k) => tarjeta(i).locator(`[data-f="${k}"]`);
    const valores = async (i) => ({
        valor: await campo(i, 'valor').inputValue(), medio: await campo(i, 'medio').inputValue(), fecha: await campo(i, 'fecha').inputValue(), hora: await campo(i, 'hora').inputValue(),
        ref: await campo(i, 'referencia').inputValue(), aut: await campo(i, 'autorizacion').inputValue(), origen: await campo(i, 'banco_origen').inputValue(), destino: await campo(i, 'banco_destino').inputValue(),
        remitente: await campo(i, 'remitente').inputValue(), dest: await campo(i, 'contraparte').inputValue(),
    });
    const desborde = () => page.evaluate(() => Math.max(0, document.documentElement.scrollWidth - document.documentElement.clientWidth));
    const esperarLectura = async (n) => {
        await modal.locator('[data-c="ops"] [data-uid]').nth(n - 1).waitFor({ timeout: 120000 });
        await page.waitForFunction(() => !document.querySelector('#c-modal-movimiento [data-c="estado-lectura"]').textContent.includes('Leyendo'), null, { timeout: 120000 });
    };
    const subir = async (archivos, nuevasOps, etiqueta) => {
        const previas = await modal.locator('[data-c="ops"] [data-uid]').count();
        const t0 = Date.now();
        await modal.locator('[data-c="archivos"]').setInputFiles(archivos.map(f));
        await esperarLectura(previas + nuevasOps);
        const ms = Date.now() - t0;
        tiempos[etiqueta] = ms;
        return ms;
    };
    const foto = (n) => page.screenshot({ path: path.join(salida, `${n}__${motorWebkit ? 'webkit' : 'chromium'}_${ancho}.png`) });

    console.log(php('purgar')); // estado inicial limpio (solo lo creado por corridas anteriores de este recorrido)
    php(`arranque ${ARRANQUE_ORIGINAL}`);

    // ---------- 1. Interfaz ----------
    await abrir();
    reg('el adjunto está visible desde el inicio (sin elegir «con detalle») y sin campos duplicados de valor/fecha/medio', (await modal.locator('[data-c="archivos"]').isVisible()) && !(await modal.locator('[data-c="campos-manual"]').isVisible()));

    // ---------- 2. Nequi real, con fecha anterior al arranque ----------
    const t0 = Date.now();
    await modal.locator('[data-c="archivos"]').setInputFiles(f('nequi.jpg'));
    await page.waitForFunction(() => document.querySelector('#c-modal-movimiento [data-c="estado-lectura"]').textContent.includes('Leyendo comprobante'), null, { timeout: 10000 }).catch(() => {});
    reg('muestra «Leyendo comprobante…» mientras procesa', (await modal.locator('[data-c="estado-lectura"]').innerText()).includes('Leyendo comprobante') || tiempos['_leyendo_visto'] === true);
    await esperarLectura(1).catch(async (e) => { console.log('  [diagnóstico] ops html:', (await modal.locator('[data-c="ops"]').innerHTML()).slice(0, 300), '| estado:', await modal.locator('[data-c="estado-lectura"]').innerText(), '| errores:', await modal.locator('[data-c="errores"]').innerText()); await foto('diagnostico'); throw e; });
    tiempos['nequi.jpg'] = Date.now() - t0;
    const n1 = await valores(0);
    reg('Nequi real: valor, medio, fecha, hora, referencia y destinatario completados en pantalla', digitos(n1.valor) === '50000' && n1.medio === 'Nequi' && n1.fecha === '2026-01-05' && n1.hora === '09:15' && n1.ref === 'M00000001' && n1.dest === 'Maria Prueba', n1);
    reg('Nequi: no se inventa banco de origen ni autorización', n1.origen === '' && n1.aut === '');
    reg('hay vista del soporte y total automático', (await tarjeta(0).locator('img').count()) === 1 && (await modal.locator('[data-c="suma-ops"]').innerText()).includes('$ 50.000'));
    reg('la fecha anterior al arranque se avisa sin cambiarla', (await tarjeta(0).innerText()).includes('anterior al arranque') && n1.fecha === '2026-01-05');
    await foto('02-nequi-leido');
    await modal.locator('[data-c="observacion"]').fill('RECORRIDO-COMPROBANTES arranque');
    const antes = estadoDb().movimientos_total;
    await modal.locator('[data-c-enviar-movimiento]').click();
    await modal.locator('[data-c="errores"]:not(.hidden)').waitFor({ timeout: 30000 });
    const msg = await modal.locator('[data-c="errores"]').innerText();
    reg('registrar con fecha anterior al arranque: mensaje claro, nada guardado y la fecha leída intacta', /anterior al arranque/i.test(msg) && estadoDb().movimientos_total === antes && (await campo(0, 'fecha').inputValue()) === '2026-01-05', { msg: msg.slice(0, 120), total_antes: antes, total_despues: estadoDb().movimientos_total, fecha: await campo(0, 'fecha').inputValue() });
    await foto('03-bloqueo-por-arranque');

    // ---------- 3. Los cuatro comprobantes reales a la vez (arranque anterior SOLO en la demo) ----------
    php('arranque 2026-09-01');
    await abrir();
    const ms4 = await subir(['nequi.jpg', 'daviplata.jpg', 'corresponsal.jpg', 'bold.jpg'], 4, 'cuatro_comprobantes');
    const v = [await valores(0), await valores(1), await valores(2), await valores(3)];
    reg('los 4 comprobantes aparecen como 4 operaciones', (await modal.locator('[data-c="ops"] [data-uid]').count()) === 4, { ms: ms4 });
    reg('Nequi: $50.000 · 05/01/2026 · 09:15 · M00000001', digitos(v[0].valor) === '50000' && v[0].fecha === '2026-01-05' && v[0].hora === '09:15' && v[0].ref === 'M00000001', v[0]);
    reg('DaviPlata: $200.000 · 06/01/2026 · 11:30 · aprobación 700001 (sin inventar referencia)', digitos(v[1].valor) === '200000' && v[1].fecha === '2026-01-06' && v[1].hora === '11:30' && v[1].aut === '700001' && v[1].ref === '', v[1]);
    reg('Corresponsal Nequi: $120.000 · 09/01/2026 · ID 1620000002 y autorización M06000002 por separado', digitos(v[2].valor) === '120000' && v[2].fecha === '2026-01-09' && v[2].ref === '1620000002' && v[2].aut === 'M06000002', v[2]);
    reg('Bold: $80.000 una sola vez · 08/01/2026 · origen Bold y destino Daviplata distinguidos', digitos(v[3].valor) === '80000' && v[3].fecha === '2026-01-08' && /bold/i.test(v[3].origen) && /daviplata/i.test(v[3].destino), v[3]);
    reg('Bold: remitente y destinatario leídos (origen ≠ destino)', v[3].remitente !== '' && v[3].dest !== '' && v[3].remitente !== v[3].dest, { remitente: v[3].remitente, dest: v[3].dest });
    const totalTxt = await modal.locator('[data-c="suma-ops"]').innerText();
    reg('total automático = suma de las 4 operaciones ($ 450.000)', totalTxt.includes('$ 450.000'), totalTxt);
    reg('la referencia dudosa de Bold se marca para verificar', /verifica la referencia/.test(await tarjeta(3).innerText()));
    await foto('04-cuatro-leidos');
    if (ancho >= 600) { await tarjeta(3).scrollIntoViewIfNeeded(); await foto('05-bold-detalle'); }

    // ---------- 4. Corrección manual y guardado ----------
    await campo(3, 'referencia').fill('AB8CD8EF12');
    await campo(3, 'referencia').blur();
    await modal.locator('[data-c="observacion"]').fill('RECORRIDO-COMPROBANTES cuatro');
    await modal.locator('[data-c-enviar-movimiento]').click();
    await page.waitForLoadState('domcontentloaded');
    await page.waitForTimeout(3000);
    const s1 = estadoDb();
    const mov = s1.movimientos_recorrido.find((m) => m.estado !== 'reversado') || {};
    const ops = s1.operaciones.filter((o) => o.movimiento_id === mov.id);
    const porRef = (r) => ops.find((o) => (o.referencia || o.autorizacion) === r) || {};
    reg('confirmar registró UN abono por la suma de las 4 (fecha efectiva = la más reciente de los comprobantes)', s1.movimientos_recorrido.length === 1 && Number(mov.valor) === 450000 && mov.fecha_efectiva === '2026-01-09', mov);
    reg('se guardaron 4 operaciones con ID y autorización separados y la corrección de la referencia', ops.length === 4 && porRef('1620000002').autorizacion === 'M06000002' && !!porRef('AB8CD8EF12').remitente && porRef('700001').referencia === null && porRef('M00000001').hora_operacion === '09:15:00', ops.map((o) => ({ ref: o.referencia, aut: o.autorizacion, valor: o.valor, fecha: o.fecha_operacion })));
    await foto('06-registrado');

    // ---------- 5. Duplicados ----------
    await abrir();
    await subir(['nequi.jpg'], 1, 'nequi_repetido');
    let lista = await modal.locator('[data-c="soportes-lista"]').innerText();
    reg('mismo archivo: «ya estaba cargado» (soporte) y la lectura se reutiliza; no es un pago nuevo', /ya estaba cargado/.test(lista) && /reutiliz/i.test(lista) && /no registra ningún pago/.test(lista), lista.slice(0, 200));
    let t = await tarjeta(0).innerText();
    reg('…y además la transferencia ya registrada se muestra con su movimiento anterior', /Duplicado/.test(t) && /Ya está en/.test(t), t.split('\n').filter((l) => /Ya está en/.test(l))[0]);
    await foto('07-duplicado-mismo-archivo');
    for (const [archivo, etiqueta] of [['nequi.png', 'mismo comprobante en PNG'], ['nequi.webp', 'mismo comprobante en WEBP'], ['nequi_otra_captura.jpg', 'otra captura (reescalada)']]) {
        const n = await modal.locator('[data-c="ops"] [data-uid]').count();
        await subir([archivo], 1, archivo);
        const tt = await tarjeta(n).innerText();
        const lc = await modal.locator('[data-c="soportes-lista"]').innerText();
        reg(`duplicado detectado: ${etiqueta} (archivo distinto, misma transferencia)`, /Duplicado/.test(tt) && /Ya está en/.test(tt) && !/ya estaba cargado/.test(lc.split('\n').filter((l) => l.includes(archivo)).join('')), tt.split('\n').filter((l) => /Ya está en|Duplicado/.test(l)).slice(0, 2));
    }
    const nAntes = estadoDb().movimientos_recorrido.length;
    await modal.locator('[data-c-enviar-movimiento]').click();
    await page.waitForTimeout(3000);
    reg('confirmar duplicados sin motivo no crea otro pago (validación en servidor)', estadoDb().movimientos_recorrido.length === nAntes && (await modal.locator('[data-c="errores"]:not(.hidden)').count()) === 1, (await modal.locator('[data-c="errores"]').innerText()).slice(0, 150));

    // ---------- 6. Relectura explícita ----------
    const p0 = respuestas.length;
    await modal.locator('[data-c-releer]').first().click();
    await page.waitForFunction(() => !document.querySelector('#c-modal-movimiento [data-c="estado-lectura"]').textContent.includes('Leyendo'), null, { timeout: 60000 });
    await page.waitForTimeout(500);
    const ultima = respuestas[respuestas.length - 1] || {};
    reg('«Volver a leer» fuerza una nueva lectura (sin reutilizar) sin volver a seleccionar el archivo', respuestas.length > p0 && ultima.json && ultima.json.soportes[0].lectura_reutilizada === false && !(await modal.locator('[data-c="soportes-lista"]').innerText()).includes('Se reutilizó la lectura anterior'), { respuestas: respuestas.length - p0 });
    await foto('08-duplicados-y-relectura');

    // ---------- 7. Imagen poco legible, imagen sin texto y PDF ----------
    await abrir();
    await subir(['foto_nequi.jpg', 'tela_sin_texto.jpg', 'texto.pdf', 'daviplata_escaneado.pdf'], 4, 'poco_legible_y_pdf');
    const foto1 = await valores(0);
    reg('foto de pantalla con reflejo: lo legible se completa y la referencia queda pendiente de revisión', digitos(foto1.valor) === '25000' && foto1.fecha === '2026-01-08' && foto1.ref === '' && /pendiente de revisión/.test(await tarjeta(0).innerText()), foto1);
    const sinTexto = await valores(1);
    reg('imagen sin texto: queda vacía y marcada, no se inventa nada', sinTexto.valor === '' && sinTexto.fecha === '' && sinTexto.ref === '' && /pendiente de revisión/.test(await tarjeta(1).innerText()));
    const pdfTexto = await valores(2);
    reg('PDF con texto: se leen los datos de su capa de texto', digitos(pdfTexto.valor) === '18000' && pdfTexto.fecha === '2026-11-03' && pdfTexto.ref === 'M0099001', pdfTexto);
    const pdfEsc = await valores(3);
    reg('PDF escaneado: se convierte a imagen y se lee con OCR local', digitos(pdfEsc.valor) === '200000' && pdfEsc.fecha === '2026-01-06' && pdfEsc.aut === '700001', pdfEsc);
    reg('PDF: el soporte se conserva y se puede abrir', (await tarjeta(2).locator('a', { hasText: 'Ver PDF' }).count()) === 1);
    reg('un dato pendiente no se rellena solo al confirmar', await (async () => { await modal.locator('[data-c-enviar-movimiento]').click(); await modal.locator('[data-c="errores"]:not(.hidden)').waitFor(); return /Completa el valor y la fecha/.test(await modal.locator('[data-c="errores"]').innerText()); })());
    await foto('09-poco-legible-y-pdf');
    reg(`sin desbordamiento horizontal (${ancho}px)`, (await desborde()) === 0, { px: await desborde() });

    // ---------- 8. Alternativa manual ----------
    await abrir();
    await modal.locator('input[name="c-modo"][value="total"]').check();
    reg('«Ingresar manualmente» sigue disponible (valor, fecha, medio, número de transferencias)', (await modal.locator('[data-c="campos-manual"]').isVisible()) && (await modal.locator('[data-c="ntrans"]').isVisible()) && !(await modal.locator('[data-c="archivos"]').isVisible()));
    await foto('10-manual');

    reg('sin errores de JavaScript', errores.length === 0, errores);

    // ---------- limpieza: se revierte lo creado y se restaura el arranque ----------
    console.log(php(`limpiar ${ARRANQUE_ORIGINAL}`));
    console.log(php('purgar'));
    fs.writeFileSync(path.join(salida, `resultado_${motorWebkit ? 'webkit' : 'chromium'}_${ancho}.json`), JSON.stringify({ ancho, motor: motorWebkit ? 'webkit' : 'chromium', tiempos_ms: tiempos, pasos }, null, 2));
    await browser.close();
    const fallas = pasos.filter((p) => !p.ok).length;
    console.log(`\n${pasos.length - fallas}/${pasos.length} pasos correctos · tiempos (ms): ${JSON.stringify(tiempos)}`);
    process.exit(fallas ? 1 : 0);
})().catch((e) => { try { console.log(php(`limpiar ${ARRANQUE_ORIGINAL}`)); } catch (x) { /* nada */ } console.error('ERROR', e); process.exit(2); });
