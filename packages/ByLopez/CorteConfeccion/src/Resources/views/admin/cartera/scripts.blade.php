@pushOnce('scripts')
    <script>
        (function () {
            'use strict';

            // Bagisto monta Vue DESPUES de este script y puede reemplazar los nodos: todo se resuelve por delegacion en
            // document y el contenedor se vuelve a buscar en cada evento (en WebKit/Safari los listeners directos se perdian).
            var raiz = function () { return document.getElementById('cartera-cuenta'); };
            var cfg = function () { try { return JSON.parse(raiz().dataset.config || '{}'); } catch (e) { return {}; } };
            var q = function (modal, clave) { return modal.querySelector('[data-c="' + clave + '"]'); };
            var el = function (tag, clase, texto) {
                var n = document.createElement(tag);
                if (clase) { n.className = clase; }
                if (texto !== undefined && texto !== null) { n.textContent = texto; }
                return n;
            };

            var estado = { llave: null, ops: [], soportes: [], uid: 0, motivo: null, aplicar: null, enviando: false };
            var CAMPO = 'w-full rounded-lg border px-2 py-1.5 text-base dark:border-gray-800 dark:bg-gray-900 dark:text-white sm:text-sm';

            function nuevaLlave() {
                if (window.crypto && crypto.randomUUID) { return crypto.randomUUID(); }
                return 'k' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
            }

            // ---- dinero (formatos colombianos) ----
            function parseDinero(txt) {
                if (txt === null || txt === undefined) { return null; }
                var t = String(txt).replace(/[$\s]|COP/gi, '');
                var neg = /^[-−]/.test(t);
                t = t.replace(/^[-−+]/, '');
                if (!t || !/^[0-9.,]+$/.test(t)) { return null; }
                var p = t.lastIndexOf('.'), c = t.lastIndexOf(','), dec = null;
                if (p >= 0 && c >= 0) { dec = c > p ? ',' : '.'; }
                else if (c >= 0) { dec = ((t.match(/,/g) || []).length === 1 && t.length - c - 1 <= 2) ? ',' : null; }
                else if (p >= 0) { dec = ((t.match(/\./g) || []).length === 1 && t.length - p - 1 <= 2) ? '.' : null; }
                var num;
                if (dec) { var partes = t.split(dec); var d = partes.pop(); num = partes.join('').replace(/[.,]/g, '') + '.' + d; }
                else { num = t.replace(/[.,]/g, ''); }
                if (!/^[0-9]+(\.[0-9]+)?$/.test(num)) { return null; }
                var cents = Math.round(parseFloat(num) * 100);
                return neg ? -cents : cents;
            }
            function fmt(cents) {
                var neg = cents < 0; var n = Math.abs(cents);
                var pesos = Math.floor(n / 100), rest = n % 100;
                var txt = '$ ' + pesos.toLocaleString('es-CO') + (rest ? ',' + String(rest).padStart(2, '0') : '');
                return (neg ? '− ' : '') + txt;
            }
            function aCadena(cents) { return (cents / 100).toFixed(2); }

            function toast(msg) {
                var t = document.getElementById('cartera-toast');
                if (!t) { return; }
                t.textContent = msg; t.classList.remove('hidden');
                setTimeout(function () { t.classList.add('hidden'); }, 2600);
            }

            function mostrarErrores(modal, lista) {
                var caja = q(modal, 'errores');
                caja.textContent = '';
                (lista || []).forEach(function (m) { caja.appendChild(el('p', '', m)); });
                caja.classList.toggle('hidden', !(lista && lista.length));
                if (lista && lista.length) { caja.scrollIntoView({ block: 'nearest' }); }
            }

            function mensajesDe(datos, status) {
                var m = [];
                if (datos && datos.errors) { Object.keys(datos.errors).forEach(function (k) { [].concat(datos.errors[k]).forEach(function (x) { m.push(x); }); }); }
                if (!m.length) { m.push((datos && datos.message) || ('Error ' + status + (status === 419 ? ': la sesión expiró, recarga la página.' : ''))); }
                return m;
            }

            function post(url, cuerpo, esFormData) {
                var headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': cfg().csrf };
                if (!esFormData) { headers['Content-Type'] = 'application/json'; }
                return fetch(url, { method: 'POST', credentials: 'same-origin', headers: headers, body: esFormData ? cuerpo : JSON.stringify(cuerpo) })
                    .then(function (r) { return r.json().catch(function () { return {}; }).then(function (d) { return { ok: r.ok, status: r.status, d: d }; }); });
            }

            function abrir(id) { var m = document.getElementById(id); m.classList.remove('hidden'); document.body.style.removeProperty('overflow'); return m; }
            function cerrar(m) { m.classList.add('hidden'); }

            // ============================ REGISTRAR MOVIMIENTO ============================
            function tipoActual(m) {
                var r = m.querySelector('input[name="c-tipo"]:checked');
                var v = r ? r.value : 'abono';
                return v === 'saldo_inicial' ? q(m, 'saldo-tipo').value : v;
            }
            function modoActual(m) { var r = m.querySelector('input[name="c-modo"]:checked'); return r ? r.value : 'detalle'; }

            function preparar(m) {
                var c = cfg();
                estado.llave = nuevaLlave(); estado.ops = []; estado.soportes = []; estado.enviando = false; estado.uid = 0;
                mostrarErrores(m, []);
                q(m, 'valor').value = ''; q(m, 'referencia').value = ''; q(m, 'observacion').value = ''; q(m, 'ntrans').value = '';
                q(m, 'fecha').value = c.hoy; q(m, 'fecha').min = c.arranque || '';
                q(m, 'soportes-lista').textContent = ''; q(m, 'estado-lectura').textContent = '';
                var archivos = q(m, 'archivos'); if (archivos) { archivos.value = ''; }
                var inicial = m.querySelector('input[name="c-tipo"]'); if (inicial) { inicial.checked = true; }
                m.querySelector('input[name="c-modo"][value="detalle"]').checked = true;
                var prestamos = q(m, 'prestamo-id'); prestamos.textContent = '';
                (c.prestamos || []).forEach(function (p) { var o = el('option', '', p.codigo + ' · pendiente ' + fmt(parseDinero(p.pendiente))); o.value = p.id; prestamos.appendChild(o); });
                q(m, 'aplicar-modo').value = 'auto';
                refrescarTipo(m);
            }

            function refrescarTipo(m) {
                var c = cfg(); var t = tipoActual(m); var inicial = m.querySelector('input[name="c-tipo"]:checked') && m.querySelector('input[name="c-tipo"]:checked').value === 'saldo_inicial';
                q(m, 'bloque-saldo-inicial').classList.toggle('hidden', !inicial);
                q(m, 'bloque-prestamo').classList.toggle('hidden', t !== 'devolucion');
                var conDetalle = ['abono', 'prestamo', 'devolucion'].indexOf(t) >= 0;
                                q(m, 'bloque-aplicar').classList.toggle('hidden', t !== 'abono');
                var detalle = conDetalle && modoActual(m) === 'detalle';
                q(m, 'selector-modo').classList.toggle('hidden', !conDetalle);
                q(m, 'modo-detalle').classList.toggle('hidden', !detalle);
                q(m, 'campos-manual').classList.toggle('hidden', detalle);
                q(m, 'modo-total').classList.toggle('hidden', !(conDetalle && !detalle));
                if (inicial) {
                    q(m, 'fecha').max = c.arranque ? new Date(new Date(c.arranque + 'T00:00:00').getTime() - 86400000).toISOString().slice(0, 10) : '';
                    q(m, 'fecha').min = '';
                    if (c.arranque && q(m, 'fecha').value >= c.arranque) { q(m, 'fecha').value = q(m, 'fecha').max; }
                } else { q(m, 'fecha').max = c.hoy; q(m, 'fecha').min = c.arranque || ''; if (q(m, 'fecha').value < (c.arranque || '')) { q(m, 'fecha').value = c.hoy; } }
                renderOps(m);
            }

            function renderLineasAplicar(m) {
                var c = cfg(); var cont = q(m, 'aplicar-lineas'); var modo = q(m, 'aplicar-modo').value;
                cont.textContent = '';
                cont.classList.toggle('hidden', modo !== 'elegir');
                if (modo !== 'elegir') { return; }
                var hay = false;
                if (c.deudaPrevia && parseDinero(c.deudaPrevia.pendiente) > 0) {
                    hay = true; cont.appendChild(filaAplicar('saldo_inicial', c.deudaPrevia.id, 'Deuda previa por trabajo', c.deudaPrevia.pendiente, false));
                }
                (c.ordenes || []).forEach(function (o) {
                    if (parseDinero(o.saldoEstimado) > 0) { hay = true; cont.appendChild(filaAplicar('orden', o.id, o.codigo, o.saldoEstimado, o.pendiente > 0)); }
                });
                if (!hay) { cont.appendChild(el('p', 'text-sm text-gray-500', 'No hay saldo por pagar en ninguna orden.')); }
            }
            function filaAplicar(destino, id, nombre, saldo, pendiente) {
                var f = el('div', 'flex flex-wrap items-center justify-between gap-2 text-sm');
                f.dataset.destino = destino; f.dataset.id = id;
                var t = el('span', 'min-w-0', nombre + ' · saldo ' + fmt(parseDinero(saldo)) + (pendiente ? ' (incluye pendiente de recibir)' : ''));
                var i = el('input', CAMPO + ' max-w-[10rem]'); i.type = 'text'; i.inputMode = 'decimal'; i.placeholder = '$ 0'; i.setAttribute('aria-label', 'Valor para ' + nombre); i.dataset.c = 'aplicar-valor-linea';
                f.appendChild(t); f.appendChild(i); return f;
            }

            // ---- operaciones (una por transferencia) ----
            function etiquetaAnalisis(op) {
                if (op.analisis === 'duplicado') { return ['Duplicado', 'label-canceled']; }
                if (op.analisis === 'posible_duplicado') { return ['Posible duplicado', 'label-pending']; }
                return [op.manual ? 'Manual' : 'Nueva', 'label-info'];
            }
            function campoOp(etiqueta, input, pendiente) {
                var w = el('label', 'grid gap-1 text-xs font-medium text-gray-600 dark:text-gray-300');
                w.appendChild(el('span', '', etiqueta + (pendiente ? ' · pendiente de revisión' : ''))); w.appendChild(input);
                if (pendiente) { input.className += ' !border-amber-500 bg-amber-50'; }
                return w;
            }
            function infoSoporte(id) { return estado.soportes.filter(function (s) { return s.id === id; })[0]; }
            // Medio del movimiento a partir del texto leido o escrito: nunca se supone uno distinto del que aparece.
            function medioDe(texto) {
                var t = String(texto || '').toLowerCase();
                if (t.indexOf('nequi') >= 0) { return 'nequi'; }
                if (t.indexOf('daviplata') >= 0) { return 'daviplata'; }
                return t.trim() ? 'transferencia' : 'otro';
            }
            function fechaCorta(iso) { var p = String(iso || '').split('-'); return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : iso; }

            function renderOps(m) {
                var cuerpo = q(m, 'ops'); if (!cuerpo) { return; }
                var c = cfg();
                cuerpo.textContent = '';
                if (!estado.ops.length) { cuerpo.appendChild(el('p', 'rounded-lg border border-dashed p-3 text-sm text-gray-500 dark:border-gray-700', 'Elige los comprobantes arriba o agrega una operación manual.')); }
                estado.ops.forEach(function (op, idx) {
                    var tarjeta = el('div', 'grid gap-2 rounded-lg border p-3 dark:border-gray-800'); tarjeta.dataset.uid = op.uid;
                    var cab = el('div', 'flex flex-wrap items-center justify-between gap-2');
                    var lbl = el('label', 'flex items-center gap-2 text-sm font-semibold'); var chk = el('input'); chk.type = 'checkbox'; chk.checked = op.aceptada; chk.dataset.f = 'aceptada';
                    lbl.appendChild(chk); lbl.appendChild(el('span', '', 'Operación ' + (idx + 1) + ' · incluir')); cab.appendChild(lbl);
                    var et = etiquetaAnalisis(op); cab.appendChild(el('span', et[1], et[0])); tarjeta.appendChild(cab);

                    var cuerpoOp = el('div', 'grid gap-3 sm:grid-cols-[9rem_1fr]');
                    var sop = op.soporte_id ? infoSoporte(op.soporte_id) : null;
                    if (sop) {
                        var vista = el('div', 'grid content-start gap-1');
                        if (/^image\//.test(sop.mime)) {
                            var a = el('a'); a.href = sop.url; a.target = '_blank'; a.rel = 'noopener';
                            var im = el('img', 'max-h-44 w-full rounded border object-contain object-top'); im.src = sop.url; im.alt = 'Soporte: ' + sop.nombre; im.loading = 'lazy'; a.appendChild(im); vista.appendChild(a);
                        } else { var l = el('a', 'text-sm font-semibold text-blue-600 hover:underline', 'Ver PDF'); l.href = sop.url; l.target = '_blank'; l.rel = 'noopener'; vista.appendChild(l); }
                        vista.appendChild(el('p', 'truncate text-xs text-gray-500', sop.nombre + (op.pagina ? ' · pág. ' + op.pagina : '')));
                        cuerpoOp.appendChild(vista);
                    } else { cuerpoOp.classList.remove('sm:grid-cols-[9rem_1fr]'); }

                    var texto = function (k, ph, tipo) { var i = el('input', CAMPO); i.type = tipo || 'text'; i.value = op[k] || ''; i.placeholder = ph || ''; i.dataset.f = k; if (tipo === 'time') { i.step = 1; } return i; };
                    var val = texto('valor', '$ 0'); val.inputMode = 'decimal'; val.value = op.valor ? fmt(parseDinero(op.valor)) : '';
                    var leida = !op.manual;
                    var ident = !!(op.referencia || op.autorizacion);
                    var rejilla = el('div', 'grid gap-2 sm:grid-cols-2');
                    rejilla.appendChild(campoOp('Valor', val, leida && !op.valor)); rejilla.appendChild(campoOp('Medio', texto('medio', 'Nequi, DaviPlata, Bancolombia…'), leida && !op.medio));
                    rejilla.appendChild(campoOp('Fecha', texto('fecha', '', 'date'), leida && !op.fecha)); rejilla.appendChild(campoOp('Hora', texto('hora', '', 'time'), false));
                    rejilla.appendChild(campoOp('Referencia / ID de la transacción', texto('referencia', 'Número de operación'), leida && !ident)); rejilla.appendChild(campoOp('Autorización / aprobación', texto('autorizacion', 'Si el comprobante la trae'), false));
                    var mas = el('details', 'sm:col-span-2'); mas.open = !!(op.banco_origen || op.banco_destino || op.remitente || op.contraparte);
                    mas.appendChild(el('summary', 'cursor-pointer text-xs font-semibold text-blue-600', 'Origen y destino (banco, remitente, destinatario)'));
                    var masGrid = el('div', 'mt-2 grid gap-2 sm:grid-cols-2');
                    masGrid.appendChild(campoOp('Banco o entidad de origen', texto('banco_origen', 'Solo si aparece'), false)); masGrid.appendChild(campoOp('Banco o entidad de destino', texto('banco_destino', 'Solo si aparece'), false));
                    masGrid.appendChild(campoOp('Remitente', texto('remitente', 'Quién envía'), false)); masGrid.appendChild(campoOp('Destinatario', texto('contraparte', 'Quién recibe'), false));
                    mas.appendChild(masGrid); rejilla.appendChild(mas);
                    cuerpoOp.appendChild(rejilla); tarjeta.appendChild(cuerpoOp);

                    if (op.fecha && c.arranque && op.fecha < c.arranque) {
                        tarjeta.appendChild(el('p', 'rounded bg-amber-50 p-2 text-xs text-amber-800', 'La fecha del comprobante (' + fechaCorta(op.fecha) + ') es anterior al arranque de la cartera (' + fechaCorta(c.arranque) + '). Se conserva tal cual; al registrar, el sistema aplicará esa regla y no se guardará como abono nuevo.'));
                    }
                    if ((op.advertencias || []).length) { tarjeta.appendChild(el('p', 'text-xs text-amber-700', 'Revisar: ' + op.advertencias.join(' · '))); }
                    (op.coincidencias || []).forEach(function (cc) {
                        tarjeta.appendChild(el('p', 'text-xs ' + (cc.estado_movimiento === 'reversado' ? 'text-gray-500' : 'text-red-700'),
                            cc.visible ? ('Ya está en ' + cc.movimiento_codigo + ' (' + cc.fecha + ', ' + fmt(parseDinero(String(cc.valor))) + ')' + (cc.estado_movimiento === 'reversado' ? ' · reversado: para registrarla de nuevo indica el motivo de la corrección' : '')) : cc.mensaje));
                    });
                    if (op.analisis === 'posible_duplicado' || op.analisis === 'duplicado') {
                        var mot = el('input', CAMPO); mot.type = 'text'; mot.value = op.motivo_duplicado || ''; mot.placeholder = 'Motivo para continuar pese a la coincidencia'; mot.dataset.f = 'motivo_duplicado';
                        tarjeta.appendChild(campoOp('Motivo (obligatorio para continuar)', mot));
                    }
                    cuerpo.appendChild(tarjeta);
                });
                actualizarSuma(m);
            }
            function leerFilas(m) {
                m.querySelectorAll('[data-c="ops"] [data-uid]').forEach(function (tr) {
                    var op = estado.ops.filter(function (o) { return String(o.uid) === tr.dataset.uid; })[0]; if (!op) { return; }
                    op.aceptada = tr.querySelector('[data-f="aceptada"]').checked;
                    ['medio', 'banco_origen', 'banco_destino', 'referencia', 'autorizacion', 'fecha', 'hora', 'remitente', 'contraparte'].forEach(function (k) { op[k] = tr.querySelector('[data-f="' + k + '"]').value; });
                    var v = tr.querySelector('[data-f="valor"]').value; var cents = parseDinero(v); op.valor = cents === null ? null : aCadena(cents);
                    var mot = tr.querySelector('[data-f="motivo_duplicado"]'); op.motivo_duplicado = mot ? mot.value : (op.motivo_duplicado || '');
                });
            }
            function totalOps() {
                var total = 0, n = 0;
                estado.ops.forEach(function (op) { if (op.aceptada && op.valor) { total += parseDinero(op.valor) || 0; n++; } });
                return { total: total, n: n };
            }
            function actualizarSuma(m) {
                var p = q(m, 'suma-ops'); if (!p) { return; }
                var s = totalOps();
                p.textContent = 'Total: ' + fmt(s.total) + ' · ' + s.n + (s.n === 1 ? ' operación incluida' : ' operaciones incluidas');
            }

            // ---- leer comprobantes (automatico al elegir el archivo; se puede reintentar sin volver a elegirlo) ----
            function listarSoportes(m) {
                var lista = q(m, 'soportes-lista'); lista.textContent = '';
                estado.soportes.forEach(function (s) {
                    var f = el('div', 'flex flex-wrap items-center gap-2 ' + (s.ya_cargado ? 'text-amber-700' : 'text-gray-700 dark:text-gray-300'));
                    var txt = s.nombre + (s.ya_cargado ? ' · Este archivo ya estaba cargado' + (s.mov ? ' (' + s.mov + ')' : '') + ': se reutiliza el soporte; subirlo no registra ningún pago.' : '') + (s.reutilizada ? ' · Se reutilizó la lectura anterior (usa «Volver a leer» para leerlo de nuevo).' : '') + (s.mensaje ? ' · ' + s.mensaje : '');
                    f.appendChild(el('span', 'min-w-0', txt));
                    var b = el('button', 'text-xs font-semibold text-blue-600 hover:underline', 'Volver a leer'); b.type = 'button'; b.dataset.cReleer = s.id; f.appendChild(b);
                    lista.appendChild(f);
                });
            }
            // Un archivo por peticion: el progreso se ve archivo a archivo y un OCR lento no agota el tiempo de una sola peticion.
            function leerComprobantes(m, reintentarId) {
                var c = cfg(); var entrada = q(m, 'archivos'); var archivos = reintentarId ? [] : [].slice.call(entrada.files);
                if (!reintentarId && !archivos.length) { return; }
                var cola = reintentarId ? [{ reintentar: reintentarId }] : archivos.map(function (a) { return { archivo: a }; });
                var estadoLectura = q(m, 'estado-lectura'); mostrarErrores(m, []); entrada.disabled = true;

                function recibir(d) {
                    var ids = [];
                    (d.soportes || []).forEach(function (s) {
                        ids.push(s.id);
                        var previa = s.coincidencias_archivo && s.coincidencias_archivo[0] && s.coincidencias_archivo[0].visible ? s.coincidencias_archivo[0].movimiento_codigo : null;
                        var info = { id: s.id, nombre: s.nombre, mime: s.mime, url: s.url, ya_cargado: s.ya_cargado, reutilizada: s.lectura_reutilizada, mov: previa, mensaje: s.mensaje };
                        var pos = estado.soportes.map(function (z) { return z.id; }).indexOf(s.id);
                        if (pos >= 0) { estado.soportes[pos] = info; } else { estado.soportes.push(info); }
                    });
                    // Al releer un soporte se reemplazan SUS operaciones (las de otros soportes y las manuales no se tocan).
                    estado.ops = estado.ops.filter(function (o) { return ids.indexOf(o.soporte_id) < 0; });
                    (d.borradores || []).forEach(function (b) {
                        estado.ops.push({
                            uid: ++estado.uid, aceptada: b.analisis !== 'duplicado', manual: false, valor: b.valor, fecha: b.fecha, hora: b.hora, medio: b.medio || '', banco_destino: b.banco_destino || '', autorizacion: b.autorizacion || '', remitente: b.remitente || '',
                            banco_origen: b.banco_origen || '', referencia: b.referencia || '', contraparte: b.contraparte, moneda: b.moneda, soporte_id: b.soporte_id, pagina: b.pagina,
                            advertencias: b.advertencias, analisis: b.analisis, coincidencias: b.coincidencias, motivo_duplicado: ''
                        });
                    });
                    // Un soporte sin datos legibles deja una operacion vacia para completarla a mano (el comprobante se ve al lado).
                    ids.forEach(function (id) {
                        if (!estado.ops.some(function (o) { return o.soporte_id === id; })) {
                            estado.ops.push({ uid: ++estado.uid, aceptada: true, manual: false, valor: null, fecha: null, medio: '', banco_destino: '', referencia: '', soporte_id: id, analisis: 'nueva', coincidencias: [], advertencias: ['No se pudo leer: completa los datos mirando el comprobante'] });
                        }
                    });
                    listarSoportes(m); renderOps(m);
                }

                function siguiente(i) {
                    if (i >= cola.length) { estadoLectura.textContent = ''; entrada.value = ''; entrada.disabled = false; return Promise.resolve(); }
                    estadoLectura.textContent = 'Leyendo comprobante…' + (cola.length > 1 ? ' (' + (i + 1) + ' de ' + cola.length + ')' : '');
                    var fd = new FormData();
                    if (cola[i].reintentar) { fd.append('reintentar[]', cola[i].reintentar); } else { fd.append('comprobantes[]', cola[i].archivo); }
                    return post(c.urls.comprobantes, fd, true).then(function (x) {
                        if (!x.ok) { mostrarErrores(m, mensajesDe(x.d, x.status).map(function (t) { return (cola[i].archivo ? cola[i].archivo.name + ': ' : '') + t; })); } else { recibir(x.d); }
                        return siguiente(i + 1);
                    }).catch(function () {
                        estadoLectura.textContent = ''; entrada.disabled = false;
                        mostrarErrores(m, ['No se pudo contactar al servidor. Los comprobantes ya guardados no se perdieron: intenta de nuevo.']);
                    });
                }
                siguiente(0);
            }

            function enviarMovimiento(m) {
                if (estado.enviando) { return; }
                var c = cfg(); var t = tipoActual(m); var modo = (['abono', 'prestamo', 'devolucion'].indexOf(t) >= 0) ? modoActual(m) : 'total';
                leerFilas(m);
                var valor = parseDinero(q(m, 'valor').value); var fechaMov = q(m, 'fecha').value; var medioMov = q(m, 'medio').value;
                if (modo === 'detalle') {
                    var inc = estado.ops.filter(function (o) { return o.aceptada; });
                    var faltan = inc.filter(function (o) { return !(parseDinero(o.valor) > 0) || !o.fecha; });
                    if (!inc.length) { mostrarErrores(m, ['Incluye al menos una operación.']); return; }
                    if (faltan.length) { mostrarErrores(m, ['Completa el valor y la fecha de cada operación incluida (los datos pendientes de revisión no se rellenan solos).']); return; }
                    valor = totalOps().total; fechaMov = inc.map(function (o) { return o.fecha; }).sort().pop();
                    var medios = inc.map(function (o) { return medioDe(o.medio || o.banco_origen || o.banco_destino); }).filter(function (v, i, a) { return a.indexOf(v) === i; });
                    medioMov = medios.length === 1 ? medios[0] : 'transferencia';
                }
                if (valor === null || valor <= 0) { mostrarErrores(m, ['Escribe un valor positivo.']); return; }
                var cuerpo = {
                    tipo: t, valor: aCadena(valor), fecha_efectiva: fechaMov, medio: medioMov,
                    referencia: modo === 'total' ? (q(m, 'referencia').value || null) : null, observacion: q(m, 'observacion').value || null,
                    modo_detalle: modo, num_transferencias: modo === 'total' ? (q(m, 'ntrans').value || null) : null,
                    prestamo_id: t === 'devolucion' ? q(m, 'prestamo-id').value : null, soportes: estado.soportes.map(function (s) { return s.id; }), idempotency_key: estado.llave
                };
                if (modo === 'detalle') {
                    cuerpo.operaciones = estado.ops.map(function (o) { return { estado: o.aceptada ? 'aceptada' : 'descartada', valor: o.valor, fecha: o.fecha || null, hora: o.hora || null, banco_destino: o.banco_destino || null, banco_origen: o.banco_origen || null, medio: o.medio || null, referencia: o.referencia || null, autorizacion: o.autorizacion || null, remitente: o.remitente || null, contraparte: o.contraparte || null, moneda: o.moneda || 'COP', soporte_id: o.soporte_id || null, pagina: o.pagina || null, motivo_duplicado: o.motivo_duplicado || null }; });
                }
                if (t === 'abono') {
                    var am = q(m, 'aplicar-modo').value;
                    if (am === 'auto') { cuerpo.aplicar_automatico = true; }
                    if (am === 'elegir') {
                        cuerpo.aplicaciones = [];
                        q(m, 'aplicar-lineas').querySelectorAll('[data-destino]').forEach(function (f) {
                            var v = parseDinero(f.querySelector('input').value);
                            if (v) { cuerpo.aplicaciones.push({ destino: f.dataset.destino, id: parseInt(f.dataset.id, 10), valor: aCadena(v) }); }
                        });
                    }
                }
                var url = ['prestamo', 'devolucion', 'saldo_inicial_prestamo'].indexOf(t) >= 0 ? c.urls.prestamos : c.urls.movimientos;
                estado.enviando = true; var boton = m.querySelector('[data-c-enviar-movimiento]'); boton.disabled = true; boton.textContent = 'Registrando…';
                post(url, cuerpo).then(function (x) {
                    if (x.ok) { toast(x.d.message || 'Registrado.'); window.location.reload(); return; }
                    mostrarErrores(m, mensajesDe(x.d, x.status));
                    if (x.status === 409) { estado.llave = nuevaLlave(); }
                }).catch(function () { mostrarErrores(m, ['No se pudo contactar al servidor. Si reintentas no se duplicará el registro.']); })
                    .then(function () { estado.enviando = false; boton.disabled = false; boton.textContent = 'Registrar'; });
            }

            // ============================ CORTE DE CUENTAS ============================
            function prepararCorte(m) {
                var c = cfg(); estado.llave = nuevaLlave(); estado.enviando = false; mostrarErrores(m, []);
                var sel = q(m, 'corte-prestamo'); sel.textContent = '';
                (c.prestamos || []).forEach(function (p) { var o = el('option', '', p.codigo + ' · pendiente ' + fmt(parseDinero(p.pendiente))); o.value = p.id; sel.appendChild(o); });
                q(m, 'corte-bloque-prestamo').classList.toggle('hidden', !(c.prestamos || []).length);
                q(m, 'corte-bloque-descuento').classList.toggle('hidden', !(c.prestamos || []).length);
                q(m, 'corte-descuento').value = '0'; q(m, 'corte-obs').value = '';
                previaCorte(m);
            }
            function previaCorte(m) {
                var c = cfg(); var d = parseDinero(q(m, 'corte-descuento').value); var boton = m.querySelector('[data-c-enviar-corte]');
                var url = c.urls.corteVista + '?descuento=' + encodeURIComponent(d === null ? '0' : aCadena(d)) + '&prestamo_id=' + encodeURIComponent(q(m, 'corte-prestamo').value || '');
                fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, d: j, status: r.status }; }); })
                    .then(function (x) {
                        var res = q(m, 'corte-resumen'); res.textContent = ''; var av = q(m, 'corte-avisos'); av.textContent = '';
                        if (!x.ok) { mostrarErrores(m, mensajesDe(x.d, x.status)); boton.disabled = true; return; }
                        var p = x.d; mostrarErrores(m, p.errores || []);
                        var filas = [['Trabajo recibido sin liquidar', p.trabajo_reconocido, false], ['Abonos ya aplicados a esas órdenes', '-' + p.abonos_aplicados, true], ['Descuento de préstamo acordado', '-' + p.descuento, true], ['Queda por pagar en este corte', p.queda_por_pagar, false]];
                        filas.forEach(function (f, i) {
                            var l = el('div', 'flex justify-between gap-3 border-b border-dashed py-1.5 dark:border-gray-800' + (i === filas.length - 1 ? ' border-t border-solid border-gray-400 pt-2 font-bold' : ''));
                            l.appendChild(el('span', '', f[0])); l.appendChild(el('span', 'whitespace-nowrap tabular-nums' + (f[2] ? ' text-red-700' : ''), fmt(parseDinero(String(f[1]).replace('-', '')) * (f[2] ? -1 : 1)))); res.appendChild(l);
                        });
                        res.appendChild(el('p', 'mt-2 text-xs text-gray-500', 'Préstamo pendiente después de este corte: ' + fmt(parseDinero(p.prestamo_pendiente_despues)) + '.'));
                        if ((p.sin_tarifa || []).length) { av.appendChild(el('p', 'rounded bg-red-50 p-2 text-xs text-red-800', 'Corte incompleto. Excluidas por no tener tarifa (no valen $ 0): ' + p.sin_tarifa.map(function (s) { return s.codigo + ' (' + s.referencias.join(', ') + ')'; }).join('; ') + '.')); }
                        if ((p.con_pendiente || []).length) { av.appendChild(el('p', 'rounded bg-slate-50 p-2 text-xs text-gray-700', 'Sigue pendiente de recibir y no entra en este corte: ' + p.con_pendiente.map(function (s) { return s.codigo + ' (' + s.cantidad + ')'; }).join(', ') + '.')); }
                        boton.disabled = (p.errores || []).length > 0;
                    }).catch(function () { mostrarErrores(m, ['No se pudo calcular el corte.']); boton.disabled = true; });
            }
            function enviarCorte(m) {
                if (estado.enviando) { return; } var c = cfg(); var d = parseDinero(q(m, 'corte-descuento').value);
                var cuerpo = { descuento: d === null ? '0' : aCadena(d), prestamo_id: q(m, 'corte-prestamo').value || null, observacion: q(m, 'corte-obs').value || null, idempotency_key: estado.llave };
                estado.enviando = true; var boton = m.querySelector('[data-c-enviar-corte]'); boton.disabled = true;
                post(c.urls.corte, cuerpo).then(function (x) {
                    if (x.ok) { toast(x.d.message); window.location.reload(); return; }
                    mostrarErrores(m, mensajesDe(x.d, x.status)); if (x.status === 409) { estado.llave = nuevaLlave(); }
                }).catch(function () { mostrarErrores(m, ['No se pudo contactar al servidor. Si reintentas no se duplicará el corte.']); })
                    .then(function () { estado.enviando = false; boton.disabled = false; });
            }

            // ============================ REVERSOS / APLICAR ============================
            function enviarMotivo(m) {
                var motivo = q(m, 'motivo').value.trim(); if (!motivo) { mostrarErrores(m, ['Indica el motivo.']); return; }
                var url = cfg().urls[estado.motivo.clave].replace('__ID__', estado.motivo.id);
                post(url, { motivo: motivo }).then(function (x) {
                    if (x.ok) { toast(x.d.message); window.location.reload(); return; } mostrarErrores(m, mensajesDe(x.d, x.status));
                });
            }
            function prepararAplicar(m, id, disponible) {
                var c = cfg(); estado.aplicar = { id: id }; mostrarErrores(m, []);
                q(m, 'aplicar-disponible').textContent = fmt(parseDinero(disponible));
                var sel = q(m, 'aplicar-destino'); sel.textContent = '';
                if (c.deudaPrevia && parseDinero(c.deudaPrevia.pendiente) > 0) { var o = el('option', '', 'Deuda previa por trabajo · saldo ' + fmt(parseDinero(c.deudaPrevia.pendiente))); o.value = 'saldo_inicial:' + c.deudaPrevia.id; sel.appendChild(o); }
                (c.ordenes || []).forEach(function (x) { if (parseDinero(x.saldoEstimado) > 0) { var op = el('option', '', x.codigo + ' · saldo ' + fmt(parseDinero(x.saldoEstimado)) + (x.pendiente > 0 ? ' (incluye pendiente de recibir)' : '')); op.value = 'orden:' + x.id; sel.appendChild(op); } });
                q(m, 'aplicar-valor').value = fmt(Math.min(parseDinero(disponible), sel.options.length ? parseDinero(((c.ordenes || []).filter(function (x) { return 'orden:' + x.id === sel.value; })[0] || { saldoEstimado: disponible }).saldoEstimado) : 0));
            }
            function enviarAplicar(m) {
                var v = parseDinero(q(m, 'aplicar-valor').value); var d = q(m, 'aplicar-destino').value.split(':');
                if (!v || !d[1]) { mostrarErrores(m, ['Elige el destino y un valor positivo.']); return; }
                post(cfg().urls.aplicar.replace('__ID__', estado.aplicar.id), { aplicaciones: [{ destino: d[0], id: parseInt(d[1], 10), valor: aCadena(v) }] }).then(function (x) {
                    if (x.ok) { toast(x.d.message); window.location.reload(); return; } mostrarErrores(m, mensajesDe(x.d, x.status));
                });
            }

            // ============================ DELEGACION ============================
            document.addEventListener('click', function (e) {
                if (!raiz()) { return; }
                var t = e.target;
                var ab = t.closest('[data-c-abrir]');
                if (ab) { var nombre = ab.dataset.cAbrir; var m = abrir('c-modal-' + nombre); if (nombre === 'movimiento') { preparar(m); } else { prepararCorte(m); } return; }
                var fl = t.closest('[data-c-filtro]');
                if (fl) {
                    var k = fl.dataset.cFiltro;
                    document.querySelectorAll('[data-c-filtro]').forEach(function (b) { var on = b === fl; b.setAttribute('aria-pressed', on ? 'true' : 'false'); b.classList.toggle('bg-blue-600', on); b.classList.toggle('text-white', on); b.classList.toggle('border-blue-600', on); });
                    document.querySelectorAll('#cartera-historial [data-c-grupo]').forEach(function (n) { n.classList.toggle('hidden', k !== 'todos' && n.dataset.cGrupo !== k); });
                    return;
                }
                var rv = t.closest('[data-c-reversar]');
                if (rv) { var mm = abrir('c-modal-motivo'); estado.motivo = { id: rv.dataset.cReversar, clave: rv.dataset.cUrl }; q(mm, 'motivo').value = ''; mostrarErrores(mm, []); return; }
                var ap = t.closest('[data-c-aplicar]');
                if (ap) { prepararAplicar(abrir('c-modal-aplicar'), ap.dataset.cAplicar, ap.dataset.cDisponible); return; }

                var modal = t.closest('[id^="c-modal-"]');
                if (!modal) { return; }
                if (t.closest('[data-c-cerrar]')) { cerrar(modal); return; }
                var re = t.closest('[data-c-releer]'); if (re) { leerComprobantes(modal, re.dataset.cReleer); return; }
                if (t.closest('[data-c-agregar-op]')) { estado.ops.push({ uid: ++estado.uid, aceptada: true, manual: true, valor: null, fecha: null, medio: '', banco_destino: '', referencia: '', analisis: 'nueva', coincidencias: [], advertencias: [] }); renderOps(modal); return; }
                if (t.closest('[data-c-enviar-movimiento]')) { enviarMovimiento(modal); return; }
                if (t.closest('[data-c-enviar-corte]')) { enviarCorte(modal); return; }
                if (t.closest('[data-c-enviar-motivo]')) { enviarMotivo(modal); return; }
                if (t.closest('[data-c-enviar-aplicar]')) { enviarAplicar(modal); }
            });

            document.addEventListener('change', function (e) {
                if (!raiz()) { return; }
                var modal = e.target.closest('#c-modal-movimiento'); var corte = e.target.closest('#c-modal-corte');
                if (modal) {
                    estado.llave = nuevaLlave(); // cualquier cambio vuelve a generar la llave: un doble clic sin cambios reutiliza la misma
                    if (e.target.matches('input[name="c-tipo"], input[name="c-modo"], [data-c="saldo-tipo"]')) { refrescarTipo(modal); }
                    if (e.target.matches('[data-c="aplicar-modo"]')) { renderLineasAplicar(modal); }
                    // No se reconstruye la tabla al salir de un campo: se perderia el foco (y el teclado en celular).
                    if (e.target.closest('[data-c="ops"]')) {
                        if (e.target.matches('[data-f="valor"]')) { var cts = parseDinero(e.target.value); if (cts !== null) { e.target.value = fmt(cts); } }
                        leerFilas(modal); actualizarSuma(modal);
                    }
                    if (e.target.matches('[data-c="archivos"]')) { leerComprobantes(modal); }
                }
                if (corte && e.target.matches('[data-c="corte-prestamo"], [data-c="corte-descuento"]')) { estado.llave = nuevaLlave(); previaCorte(corte); }
            });

            document.addEventListener('input', function (e) {
                var modal = e.target.closest('#c-modal-movimiento');
                if (modal && e.target.matches('[data-c="valor"], [data-c="ntrans"], [data-c="referencia"], [data-c="observacion"]')) { estado.llave = nuevaLlave(); }
            });

            document.addEventListener('keydown', function (e) {
                if (e.key !== 'Escape') { return; }
                document.querySelectorAll('[id^="c-modal-"]:not(.hidden)').forEach(cerrar);
            });

            // Arranque: el formulario del listado esta en otra vista; aqui no hay nada mas que registrar.
        })();
    </script>
@endPushOnce
