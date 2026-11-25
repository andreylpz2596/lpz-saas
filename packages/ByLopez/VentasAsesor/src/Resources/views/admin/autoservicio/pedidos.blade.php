<x-admin::layouts>
    <x-slot:title>Mis pedidos</x-slot>

    @push('styles')
        <style>
            .cfp-page { margin: -16px; min-height: calc(100vh - 64px); background: #f8fafc; padding: 16px; }
            .dark .cfp-page { background: #030712; }
            .cfp-panel { border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; box-shadow: 0 1px 2px rgba(15, 23, 42, .04); }
            .dark .cfp-panel { border-color: #1f2937; background: #111827; }
            .cfp-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; }
            .cfp-title { font-size: 20px; font-weight: 700; color: #111827; }
            .dark .cfp-title { color: #fff; }
            .cfp-sub { font-size: 13px; color: #64748b; }
            .cfp-barra { display: flex; flex-wrap: wrap; gap: 8px; padding: 12px; border-bottom: 1px solid #e2e8f0; }
            .dark .cfp-barra { border-color: #1f2937; }
            .cfp-input { min-width: 220px; flex: 1; border: 1px solid #cbd5e1; border-radius: 8px; padding: 8px 12px; font-size: 14px; background: #fff; color: #111827; }
            .dark .cfp-input { border-color: #374151; background: #030712; color: #e5e7eb; }
            .cfp-tabla { width: 100%; border-collapse: collapse; font-size: 14px; }
            .cfp-tabla th { padding: 10px 12px; text-align: left; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .03em; color: #64748b; border-bottom: 1px solid #e2e8f0; white-space: nowrap; }
            .cfp-tabla td { padding: 12px; border-bottom: 1px solid #f1f5f9; color: #1f2937; vertical-align: middle; }
            .dark .cfp-tabla th, .dark .cfp-tabla td { border-color: #1f2937; color: #e5e7eb; }
            .cfp-tabla tbody tr:hover { background: #f8fafc; cursor: pointer; }
            .dark .cfp-tabla tbody tr:hover { background: #0b1220; }
            .cfp-num { font-weight: 700; color: #1d4ed8 !important; }
            .dark .cfp-num { color: #93c5fd !important; }
            .cfp-der { text-align: right !important; white-space: nowrap; }
            .cfp-pill { display: inline-flex; align-items: center; gap: 5px; border-radius: 999px; padding: 3px 10px; font-size: 12px; font-weight: 600; white-space: nowrap; }
            .cfp-pill svg { width: 12px; height: 12px; fill: none; stroke: currentColor; stroke-width: 2.4; stroke-linecap: round; stroke-linejoin: round; }
            .cfp-pill--verde { background: #dcfce7; color: #166534; }
            .cfp-pill--amarillo { background: #fef3c7; color: #92400e; }
            .cfp-pill--cian { background: #cffafe; color: #155e75; }
            .cfp-pill--rojo { background: #fee2e2; color: #991b1b; }
            .cfp-pill--gris { background: #f1f5f9; color: #475569; }
            .dark .cfp-pill--verde { background: #052e16; color: #86efac; }
            .dark .cfp-pill--amarillo { background: #451a03; color: #fcd34d; }
            .dark .cfp-pill--cian { background: #083344; color: #67e8f9; }
            .dark .cfp-pill--rojo { background: #450a0a; color: #fca5a5; }
            .dark .cfp-pill--gris { background: #1f2937; color: #cbd5e1; }
            .cfp-vacio { padding: 40px 16px; text-align: center; color: #64748b; }
            .cfp-pie { display: flex; align-items: center; justify-content: space-between; padding: 12px; font-size: 13px; color: #64748b; }
            .cfp-ocultar { display: none !important; }

            .cfp-modal { width: min(46rem, 94vw); max-height: 92vh; padding: 0; border: 0; border-radius: 14px; box-shadow: 0 20px 50px rgba(0, 0, 0, .3); }
            .cfp-modal::backdrop { background: rgba(0, 0, 0, .45); }
            .cfp-cuerpo { padding: 20px; background: #fff; color: #1f2937; max-height: 92vh; overflow-y: auto; }
            .dark .cfp-cuerpo { background: #111827; color: #e5e7eb; }
            .cfp-mtitulo { font-size: 18px; font-weight: 700; }
            .cfp-pills { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
            .cfp-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 10px; margin-top: 14px; }
            .cfp-dato { border: 1px solid #e2e8f0; border-radius: 10px; padding: 8px 12px; }
            .dark .cfp-dato { border-color: #1f2937; }
            .cfp-dato b { display: block; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .03em; color: #64748b; }
            .cfp-dato span { font-size: 14px; font-weight: 600; }
            .cfp-seccion { margin-top: 16px; font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; color: #64748b; }
            .cfp-lineas { width: 100%; margin-top: 6px; border-collapse: collapse; font-size: 14px; }
            .cfp-lineas th { padding: 6px 8px; text-align: left; font-size: 12px; color: #64748b; border-bottom: 1px solid #e2e8f0; }
            .cfp-lineas td { padding: 7px 8px; border-bottom: 1px solid #f1f5f9; }
            .dark .cfp-lineas th, .dark .cfp-lineas td { border-color: #1f2937; }
            .cfp-tot { margin-top: 10px; margin-left: auto; max-width: 280px; font-size: 14px; }
            .cfp-tot div { display: flex; justify-content: space-between; padding: 3px 0; }
            .cfp-tot .cfp-total { border-top: 1px solid #e2e8f0; margin-top: 4px; padding-top: 8px; font-size: 16px; font-weight: 800; }
            .cfp-nota { margin-top: 14px; border-radius: 10px; background: #fffbeb; color: #92400e; padding: 10px 12px; font-size: 13px; }
            .dark .cfp-nota { background: #451a03; color: #fcd34d; }
            .cfp-guia { margin-top: 14px; border-radius: 10px; background: #eff6ff; color: #1e3a8a; padding: 10px 12px; font-size: 13px; }
            .dark .cfp-guia { background: #172554; color: #bfdbfe; }
            .cfp-acciones { margin-top: 18px; text-align: right; }

            @media (max-width: 760px) {
                .cfp-tabla thead { display: none; }
                .cfp-tabla, .cfp-tabla tbody, .cfp-tabla tr, .cfp-tabla td { display: block; width: 100%; }
                .cfp-tabla tr { border-bottom: 1px solid #e2e8f0; padding: 10px 12px; }
                .cfp-tabla td { border: 0; padding: 3px 0; display: flex; justify-content: space-between; gap: 10px; }
                .cfp-tabla td::before { content: attr(data-et); font-size: 12px; color: #64748b; }
            }
        </style>
    @endpush

    {{-- Doc. 33 P5. JavaScript nativo (sin Vue). Solo muestra lo que devuelve autoservicio.pedidos.data / .show: nunca costos, comisiones ni cartera interna. --}}
    <div class="cfp-page" data-mis-pedidos>
        <div class="cfp-head">
            <div>
                <p class="cfp-title">Mis pedidos</p>
                <p class="cfp-sub">Aquí ves el estado de tus pedidos, su despacho y tu pago.</p>
            </div>
            <a href="{{ route('admin.autoservicio.pantalla') }}" class="primary-button">+ Hacer un pedido</a>
        </div>

        <div class="cfp-panel" style="margin-top: 14px;">
            <div class="cfp-barra">
                <input type="search" class="cfp-input" placeholder="Buscar por número de pedido" data-buscar autocomplete="off" />
            </div>

            <p class="cfp-vacio" data-estado>Cargando…</p>

            <div style="overflow-x: auto;">
                <table class="cfp-tabla cfp-ocultar" data-tabla>
                    <thead>
                        <tr>
                            <th>Pedido</th><th>Fecha</th><th>Entrega</th><th class="cfp-der">Unidades</th><th class="cfp-der">Total</th>
                            <th>Estado</th><th>Despacho</th><th>Pago</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>

            <div class="cfp-pie">
                <button type="button" class="transparent-button" data-ant>&larr; Anteriores</button>
                <span data-pag></span>
                <button type="button" class="transparent-button" data-sig>Siguientes &rarr;</button>
            </div>
        </div>
    </div>

    <dialog class="cfp-modal" data-detalle>
        <div class="cfp-cuerpo">
            <p class="cfp-mtitulo" data-det-titulo></p>
            <div class="cfp-pills" data-det-pills></div>
            <div class="cfp-grid" data-det-datos></div>

            <p class="cfp-seccion">Prendas</p>
            <div style="overflow-x: auto;">
                <table class="cfp-lineas">
                    <thead><tr><th>Prenda</th><th>Color</th><th>Talla</th><th style="text-align:right">Cant.</th><th style="text-align:right">Total</th></tr></thead>
                    <tbody data-det-lineas></tbody>
                </table>
            </div>

            <div class="cfp-tot" data-det-totales></div>

            <div class="cfp-guia cfp-ocultar" data-det-guias></div>
            <div class="cfp-nota cfp-ocultar" data-det-nota></div>

            <div class="cfp-acciones"><button type="button" class="primary-button" data-det-cerrar>Cerrar</button></div>
        </div>
    </dialog>

    @pushOnce('scripts')
        <script>
            function iniciarMisPedidos() {
                const raiz = document.querySelector('[data-mis-pedidos]');
                const datosUrl = @json(route('admin.autoservicio.pedidos.data'));
                const detalleUrl = @json(route('admin.autoservicio.pedidos.show', ['pedido' => '__ID__']));
                const modal = document.querySelector('[data-detalle]');
                let pagina = 1; let total = 0; let busqueda = ''; let temporizador = null; const porPagina = 15;

                const dinero = (n) => '$ ' + Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
                const fecha = (s) => new Date(String(s).replace(' ', 'T')).toLocaleDateString('es-CO', { day: '2-digit', month: 'short', year: 'numeric' });
                const el = (tag, clase, texto) => { const e = document.createElement(tag); if (clase) { e.className = clase; } if (texto !== undefined) { e.textContent = texto; } return e; };

                // Tono por significado: verde = listo/pagado, amarillo = pendiente, cian = en proceso, rojo = cancelado, gris = sin dato.
                const ICONOS = {
                    check: '<polyline points="20 6 9 17 4 12"/>',
                    reloj: '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
                    caja: '<path d="M21 16V8l-9-5-9 5v8l9 5 9-5z"/><path d="M3.3 7L12 12l8.7-5M12 22V12"/>',
                    x: '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
                    menos: '<line x1="6" y1="12" x2="18" y2="12"/>',
                };
                const TONOS = {
                    'Recibido': ['amarillo', 'reloj'], 'En preparación': ['cian', 'caja'], 'Completado': ['verde', 'check'],
                    'Cancelado': ['rojo', 'x'], 'Cerrado': ['gris', 'menos'], 'En revisión': ['amarillo', 'reloj'],
                    'Despachado': ['verde', 'check'], 'Despacho parcial': ['cian', 'caja'], 'Pendiente de despacho': ['amarillo', 'caja'],
                    'Pagado': ['verde', 'check'], 'Abonado': ['cian', 'reloj'], 'Pendiente': ['amarillo', 'reloj'],
                };
                function pill(texto) {
                    const [tono, icono] = TONOS[texto] || ['gris', 'menos'];
                    const p = el('span', 'cfp-pill cfp-pill--' + tono);
                    p.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true">' + (ICONOS[icono] || '') + '</svg>';
                    p.append(el('span', '', texto));
                    return p;
                }
                function celda(tr, et, contenido, clase) {
                    const td = el('td', clase || ''); td.dataset.et = et;
                    if (contenido instanceof Node) { td.append(contenido); } else { td.textContent = contenido; }
                    tr.append(td); return td;
                }

                async function pedir(url) {
                    const r = await fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
                    if (! r.ok) { throw new Error('No pudimos cargar la información. Inténtalo de nuevo.'); }
                    return r.json();
                }

                async function cargar() {
                    const estado = raiz.querySelector('[data-estado]'); const tabla = raiz.querySelector('[data-tabla]');
                    try {
                        const r = await pedir(datosUrl + '?pagina=' + pagina + (busqueda ? '&q=' + encodeURIComponent(busqueda) : ''));
                        total = r.total; const cuerpo = tabla.querySelector('tbody'); cuerpo.replaceChildren();

                        r.data.forEach((p) => {
                            const tr = el('tr'); tr.addEventListener('click', () => ver(p.id));
                            celda(tr, 'Pedido', '#' + p.numero, 'cfp-num');
                            celda(tr, 'Fecha', fecha(p.fecha));
                            celda(tr, 'Entrega', p.tipo_entrega);
                            celda(tr, 'Unidades', String(p.unidades), 'cfp-der');
                            celda(tr, 'Total', dinero(p.total), 'cfp-der');
                            celda(tr, 'Estado', pill(p.estado));
                            celda(tr, 'Despacho', pill(p.estado_despacho));
                            celda(tr, 'Pago', pill(p.estado_pago));
                            cuerpo.append(tr);
                        });

                        estado.textContent = r.data.length ? '' : (busqueda ? 'No encontramos pedidos con ese número.' : 'Todavía no tienes pedidos.');
                        estado.classList.toggle('cfp-ocultar', r.data.length > 0);
                        tabla.classList.toggle('cfp-ocultar', ! r.data.length);
                        raiz.querySelector('[data-pag]').textContent = r.total + (r.total === 1 ? ' pedido' : ' pedidos') + ' · página ' + pagina + ' de ' + Math.max(1, Math.ceil(total / porPagina));
                    } catch (e) { estado.classList.remove('cfp-ocultar'); estado.textContent = e.message; }
                }

                function dato(titulo, valor) { const d = el('div', 'cfp-dato'); d.append(el('b', '', titulo), el('span', '', valor)); return d; }

                async function ver(id) {
                    try {
                        const p = await pedir(detalleUrl.replace('__ID__', id));
                        modal.querySelector('[data-det-titulo]').textContent = 'Pedido #' + p.numero;
                        modal.querySelector('[data-det-pills]').replaceChildren(pill(p.estado), pill(p.estado_despacho), pill(p.estado_pago));

                        const datos = modal.querySelector('[data-det-datos]'); datos.replaceChildren(dato('Fecha', fecha(p.fecha)), dato('Entrega', p.entrega));
                        if (p.metodo_envio) { datos.append(dato('Método de envío', p.metodo_envio)); }
                        if (p.destino) { datos.append(dato('Dirección de entrega', p.destino)); }

                        const cuerpo = modal.querySelector('[data-det-lineas]'); cuerpo.replaceChildren();
                        p.prendas.forEach((l) => {
                            const tr = el('tr');
                            tr.append(el('td', '', l.nombre), el('td', '', l.color), el('td', '', l.talla), el('td', '', String(l.cantidad)), el('td', '', dinero(l.total)));
                            tr.children[3].style.textAlign = 'right'; tr.children[4].style.textAlign = 'right';
                            cuerpo.append(tr);
                        });

                        const tot = modal.querySelector('[data-det-totales]'); tot.replaceChildren();
                        const fila = (a, b, c) => { const d = el('div', c || ''); d.append(el('span', '', a), el('span', '', b)); tot.append(d); };
                        fila('Productos (' + p.unidades + ' uds.)', dinero(p.subtotal));
                        fila('Envío', p.envio > 0 ? dinero(p.envio) : 'Por pagar al recibir');
                        fila('Total', dinero(p.total), 'cfp-total');

                        const guias = modal.querySelector('[data-det-guias]');
                        guias.textContent = p.guias.length ? 'Guía de envío: ' + p.guias.map((g) => [g.transportadora, g.numero_guia].filter(Boolean).join(' ')).join(' · ') : '';
                        guias.classList.toggle('cfp-ocultar', ! p.guias.length);

                        const nota = modal.querySelector('[data-det-nota]');
                        nota.textContent = p.estado_pago === 'Pagado' ? '' : 'El pago se confirma cuando recibimos tu transferencia. Te avisaremos cuando tu pedido esté listo.';
                        nota.classList.toggle('cfp-ocultar', p.estado_pago === 'Pagado');

                        modal.showModal();
                    } catch (e) { raiz.querySelector('[data-estado]').classList.remove('cfp-ocultar'); raiz.querySelector('[data-estado]').textContent = e.message; }
                }

                raiz.querySelector('[data-ant]').addEventListener('click', () => { if (pagina > 1) { pagina--; cargar(); } });
                raiz.querySelector('[data-sig]').addEventListener('click', () => { if (pagina * porPagina < total) { pagina++; cargar(); } });
                raiz.querySelector('[data-buscar]').addEventListener('input', (e) => {
                    clearTimeout(temporizador);
                    temporizador = setTimeout(() => { busqueda = e.target.value.trim(); pagina = 1; cargar(); }, 300);
                });
                modal.querySelector('[data-det-cerrar]').addEventListener('click', () => modal.close());
                modal.addEventListener('click', (e) => { if (e.target === modal) { modal.close(); } });
                cargar();
            }
            // Bagisto monta Vue al terminar de cargar y reconstruye el DOM: hay que enganchar DESPUES, o los nodos quedan huerfanos.
            const arrancar = () => setTimeout(iniciarMisPedidos, 150);
            if (document.readyState === "complete") { arrancar(); } else { window.addEventListener("load", arrancar); }
        </script>
    @endPushOnce
</x-admin::layouts>
