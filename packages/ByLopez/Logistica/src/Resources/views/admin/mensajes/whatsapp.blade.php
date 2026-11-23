{{--
    Componente UNICO de «Mensaje de WhatsApp» del pedido: botones `[data-whatsapp-pedido]` + modal (hoja inferior en movil). Lo incluyen
    UNA vez por pagina Pedidos (VentasAsesor), Despachos y Entregas; todos consumen el mismo contrato (MensajesPedidoCliente) y solo
    cambian las URL. Variables: $urlOpciones y $urlMensaje (con __ID__ y __CLAVE__ de relleno).

    Flujo: clic → el servidor ofrece SOLO los mensajes que corresponden al estado real → se previsualiza → «Copiar mensaje».
    JavaScript puro y delegado (sin Vue): el modal se crea en <body>, fuera de la raiz Vue de Bagisto (que reemplaza nodos e ignora
    <style>/<script> internos). El texto siempre se arma en el servidor y se vuelve a pedir al copiar para avisar si el pedido cambio.
--}}
@pushOnce('styles')
    <style>
        .lg-wa-btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; min-width: 36px; height: 36px; padding: 0 8px; border: 1px solid #e2e8f0; border-radius: 8px; background: #fff; color: #128c7e; cursor: pointer; transition: background .15s; }
        .lg-wa-btn:hover { background: #f0fdf4; }
        .lg-wa-btn:focus-visible { outline: 2px solid #25d366; outline-offset: 2px; }
        .lg-wa-btn svg { width: 20px; height: 20px; flex: none; fill: currentColor; }
        .lg-wa-btn__txt { font-size: 13px; font-weight: 600; }
        .lg-wa-btn--txt { padding: 0 12px; }
        @media (max-width: 640px) { .lg-wa-btn { min-width: 44px; height: 44px; } }
        html.dark .lg-wa-btn { background: #111827; border-color: #1f2937; color: #34d399; }

        dialog.lg-wa { padding: 0; border: 0; border-radius: 14px; background: #fff; color: #0f172a; width: min(520px, calc(100vw - 32px)); max-height: min(88vh, 720px); overflow: hidden; box-shadow: 0 24px 60px rgba(15, 23, 42, .35); }
        dialog.lg-wa[open] { display: flex; flex-direction: column; }
        dialog.lg-wa::backdrop { background: rgba(15, 23, 42, .5); }
        .lg-wa__cab { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; padding: 14px 16px 8px; }
        .lg-wa__tit { font-size: 16px; font-weight: 700; margin: 0; }
        .lg-wa__sub { margin: 2px 0 0; font-size: 13px; color: #64748b; }
        .lg-wa__x { flex: none; width: 44px; height: 44px; margin: -8px -10px 0 0; border: 0; background: transparent; font-size: 28px; line-height: 1; color: #64748b; cursor: pointer; border-radius: 8px; }
        .lg-wa__cuerpo { flex: 1 1 auto; min-height: 0; overflow-y: auto; overscroll-behavior: contain; padding: 0 16px 8px; }
        .lg-wa__chips { display: flex; flex-wrap: wrap; gap: 6px; margin: 4px 0 10px; }
        .lg-wa__chips[hidden] { display: none; }
        .lg-wa__chip { min-height: 36px; padding: 6px 12px; border: 1px solid #cbd5e1; border-radius: 999px; background: #fff; color: #334155; font-size: 13px; font-weight: 600; cursor: pointer; text-align: left; }
        .lg-wa__chip[aria-selected="true"] { background: #2563eb; border-color: #2563eb; color: #fff; }
        .lg-wa__aviso { min-height: 0; margin: 0 0 8px; font-size: 13px; font-weight: 600; }
        .lg-wa__aviso:empty { display: none; }
        .lg-wa__aviso--ok { color: #15803d; }
        .lg-wa__aviso--alerta { padding: 6px 10px; border-radius: 8px; background: #fffbeb; color: #92400e; }
        .lg-wa__aviso--error { color: #b91c1c; }
        .lg-wa__texto { margin: 0; padding: 12px; border: 1px solid #e2e8f0; border-radius: 10px; background: #f8fafc; font: inherit; font-size: 15px; line-height: 1.5; white-space: pre-wrap; overflow-wrap: anywhere; -webkit-user-select: text; user-select: text; }
        .lg-wa__estado { margin: 0; padding: 14px 4px; font-size: 14px; color: #64748b; }
        .lg-wa__tel { margin: 8px 0 0; font-size: 13px; color: #64748b; }
        .lg-wa__pie { padding: 8px 16px 16px; border-top: 1px solid transparent; }
        .lg-wa__copiar { width: 100%; min-height: 48px; border: 0; border-radius: 10px; background: #2563eb; color: #fff; font-size: 16px; font-weight: 700; cursor: pointer; }
        .lg-wa__copiar:disabled { opacity: .5; cursor: not-allowed; }
        .lg-wa__copiar[data-ok="1"] { background: #15803d; }
        html.dark dialog.lg-wa { background: #111827; color: #f1f5f9; }
        html.dark .lg-wa__texto { background: #0b1220; border-color: #1f2937; }
        html.dark .lg-wa__chip { background: #111827; border-color: #374151; color: #e2e8f0; }
        html.dark .lg-wa__chip[aria-selected="true"] { background: #2563eb; border-color: #2563eb; color: #fff; }

        @media (max-width: 640px) {
            dialog.lg-wa { position: fixed; inset: auto 0 0 0; margin: 0; width: 100%; max-width: 100%; max-height: 92vh; max-height: 92dvh; border-radius: 16px 16px 0 0; }
            .lg-wa__texto { font-size: 16px; }
            .lg-wa__pie { padding-bottom: max(16px, env(safe-area-inset-bottom)); }
        }
    </style>
@endPushOnce

@php
    // Fuera de @json(): Blade no analiza bien un arreglo multilinea con llamadas dentro de la directiva.
    $textosWhatsApp = [];

    foreach (['titulo', 'pedido', 'cargando', 'sin_mensajes', 'elige', 'copiar', 'copiado', 'no_copiado', 'cambio', 'telefono', 'cerrar', 'error', 'ayuda'] as $clave) {
        $textosWhatsApp[\Illuminate\Support\Str::camel($clave)] = __('logistica::app.whatsapp.'.$clave);
    }

    // Otra entidad que reutiliza el componente (p. ej. el estado de cuenta del cliente en Cartera) puede cambiar textos.
    $textosWhatsApp = array_merge($textosWhatsApp, $textos ?? []);
@endphp

@push('scripts')
    <script>
        window.ByLopezWhatsApp = {
            urlOpciones: @json($urlOpciones),
            urlMensaje: @json($urlMensaje),
            t: @json($textosWhatsApp),
        };
    </script>
@endpush

@pushOnce('scripts')
    <script>
        (function () {
            const cfg = () => window.ByLopezWhatsApp || null;
            const estado = { pedido: null, numero: '', opciones: [], clave: null, texto: '', token: 0 };
            let dlg = null;

            const url = (plantilla, id, clave) => plantilla.replace('__ID__', encodeURIComponent(id)).replace('__CLAVE__', encodeURIComponent(clave || ''));
            const q = (s) => dlg.querySelector(s);

            const pedirJson = async (u) => {
                const r = await fetch(u, { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                let cuerpo = null;
                try { cuerpo = await r.json(); } catch (e) { /* respuesta no JSON (sesion vencida, 401...) */ }
                if (! r.ok) { const err = new Error((cuerpo && cuerpo.mensaje) || cfg().t.error); err.status = r.status; throw err; }
                return cuerpo;
            };

            const construir = () => {
                if (dlg) return;
                const t = cfg().t;
                dlg = document.createElement('dialog');
                dlg.className = 'lg-wa';
                dlg.setAttribute('aria-labelledby', 'lg-wa-titulo');
                dlg.innerHTML =
                    '<div class="lg-wa__cab"><div><p class="lg-wa__tit" id="lg-wa-titulo"></p><p class="lg-wa__sub" data-wa-sub></p></div>' +
                    '<button type="button" class="lg-wa__x" data-wa-cerrar></button></div>' +
                    '<div class="lg-wa__cuerpo">' +
                    '<div class="lg-wa__chips" data-wa-chips role="tablist" hidden></div>' +
                    '<p class="lg-wa__aviso" data-wa-aviso role="status" aria-live="polite"></p>' +
                    '<p class="lg-wa__estado" data-wa-estado></p>' +
                    '<pre class="lg-wa__texto" data-wa-texto tabindex="0" hidden></pre>' +
                    '<p class="lg-wa__tel" data-wa-tel hidden></p></div>' +
                    '<div class="lg-wa__pie"><button type="button" class="lg-wa__copiar" data-wa-copiar disabled></button></div>';
                document.body.appendChild(dlg);
                q('#lg-wa-titulo').textContent = t.titulo;
                q('[data-wa-cerrar]').textContent = '×';
                q('[data-wa-cerrar]').setAttribute('aria-label', t.cerrar);
                q('[data-wa-copiar]').textContent = t.copiar;
                q('[data-wa-cerrar]').addEventListener('click', () => dlg.close());
                q('[data-wa-copiar]').addEventListener('click', copiar);
                // Clic en el fondo (fuera de la hoja) cierra.
                dlg.addEventListener('click', (e) => { if (e.target === dlg) dlg.close(); });
            };

            const aviso = (texto, tipo) => {
                const a = q('[data-wa-aviso]');
                a.textContent = texto || '';
                a.className = 'lg-wa__aviso' + (tipo ? ' lg-wa__aviso--' + tipo : '');
            };

            // Avisos para quien genera el mensaje (lo que NO entra en el texto); nunca forman parte de lo copiado.
            const avisosInternos = (m) => { if (m && Array.isArray(m.avisos) && m.avisos.length) aviso(m.avisos.join(' '), 'alerta'); };

            const ponerEstado = (texto) => { const e = q('[data-wa-estado]'); e.textContent = texto || ''; e.hidden = ! texto; };

            const mostrarTexto = (texto, telefono) => {
                estado.texto = texto;
                const p = q('[data-wa-texto]');
                p.textContent = texto;
                p.hidden = ! texto;
                const tel = q('[data-wa-tel]');
                tel.textContent = telefono ? cfg().t.telefono + ': ' + telefono : '';
                tel.hidden = ! telefono;
                const boton = q('[data-wa-copiar]');
                boton.disabled = ! texto;
                boton.removeAttribute('data-ok');
                boton.textContent = cfg().t.copiar;
            };

            const pintarChips = () => {
                const cont = q('[data-wa-chips]');
                cont.innerHTML = '';
                cont.hidden = estado.opciones.length < 2;
                estado.opciones.forEach((o) => {
                    const b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'lg-wa__chip';
                    b.setAttribute('role', 'tab');
                    b.setAttribute('aria-selected', o.clave === estado.clave ? 'true' : 'false');
                    b.textContent = o.etiqueta;
                    b.addEventListener('click', () => elegir(o.clave));
                    cont.appendChild(b);
                });
            };

            const elegir = async (clave) => {
                const ticket = ++estado.token;
                estado.clave = clave;
                pintarChips();
                aviso('');
                mostrarTexto('', null);
                ponerEstado(cfg().t.cargando);
                const opcion = estado.opciones.find((o) => o.clave === clave);
                q('[data-wa-sub]').textContent = cfg().t.pedido + ' ' + estado.numero + (opcion ? ' · ' + opcion.etiqueta : '');
                try {
                    const m = await pedirJson(url(cfg().urlMensaje, estado.pedido, clave));
                    if (ticket !== estado.token) return;
                    ponerEstado('');
                    mostrarTexto(m.texto, m.telefono);
                    avisosInternos(m);
                } catch (e) {
                    if (ticket !== estado.token) return;
                    ponerEstado('');
                    aviso(e.message, 'error');
                }
            };

            const abrir = async (id, numero) => {
                if (! cfg()) return;
                construir();
                estado.pedido = id; estado.numero = numero || ('#' + id); estado.opciones = []; estado.clave = null;
                const ticket = ++estado.token;
                q('[data-wa-chips]').hidden = true;
                q('[data-wa-sub]').textContent = cfg().t.pedido + ' ' + estado.numero;
                mostrarTexto('', null);
                aviso('');
                ponerEstado(cfg().t.cargando);
                if (! dlg.open) dlg.showModal();
                try {
                    const r = await pedirJson(url(cfg().urlOpciones, id));
                    if (ticket !== estado.token) return;
                    estado.opciones = r.opciones || [];
                    if (! estado.opciones.length) { ponerEstado(cfg().t.sinMensajes); return; }
                    // El mas avanzado del pedido primero (el ultimo de la lista): despacho/entrega antes que el resumen.
                    await elegir(estado.opciones[estado.opciones.length - 1].clave);
                } catch (e) {
                    if (ticket !== estado.token) return;
                    ponerEstado('');
                    aviso(e.message, 'error');
                }
            };

            // Respaldo cuando no hay Clipboard API (HTTP, navegador antiguo): seleccion + execCommand sobre el propio texto.
            const seleccionarTexto = () => {
                const p = q('[data-wa-texto]');
                const rango = document.createRange();
                rango.selectNodeContents(p);
                const sel = window.getSelection();
                sel.removeAllRanges();
                sel.addRange(rango);
            };
            const copiarRespaldo = () => {
                try { seleccionarTexto(); return document.execCommand('copy'); } catch (e) { return false; }
            };

            const copiar = async () => {
                const texto = estado.texto;
                if (! texto) return;
                let ok = false;
                if (navigator.clipboard && window.isSecureContext) {
                    try { await navigator.clipboard.writeText(texto); ok = true; } catch (e) { ok = copiarRespaldo(); }
                } else {
                    ok = copiarRespaldo();
                }
                const boton = q('[data-wa-copiar]');
                if (ok) {
                    aviso(cfg().t.copiado, 'ok');
                    boton.setAttribute('data-ok', '1');
                    boton.textContent = '✓ ' + cfg().t.copiado;
                } else {
                    seleccionarTexto();
                    aviso(cfg().t.noCopiado, 'error');
                }
                // Verifica que el texto copiado siga vigente (guia corregida, despacho nuevo...): si cambio, lo actualiza y avisa.
                const ticket = estado.token;
                try {
                    const m = await pedirJson(url(cfg().urlMensaje, estado.pedido, estado.clave));
                    if (ticket === estado.token && m.texto !== texto) {
                        mostrarTexto(m.texto, m.telefono);
                        aviso(cfg().t.cambio, 'alerta');
                    }
                } catch (e) { /* la verificacion es informativa: no desmiente una copia ya hecha */ }
            };

            document.addEventListener('click', (e) => {
                const b = e.target.closest('[data-whatsapp-pedido]');
                if (! b) return;
                e.preventDefault();
                abrir(b.getAttribute('data-whatsapp-pedido'), b.getAttribute('data-numero'));
            });
        })();
    </script>
@endPushOnce
