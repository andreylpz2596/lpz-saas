<x-admin::layouts>
    <x-slot:title>Mis pedidos</x-slot>

    {{-- Doc. 33 P5. JavaScript nativo (sin Vue). Solo muestra lo que devuelve autoservicio.pedidos.data / .show: nunca costos, comisiones ni cartera interna. --}}
    <div class="flex items-center justify-between gap-3">
        <p class="text-xl font-bold text-gray-800 dark:text-white">Mis pedidos</p>
        <a href="{{ route('admin.autoservicio.pantalla') }}" class="primary-button">Hacer un pedido</a>
    </div>

    <div class="mt-4 rounded bg-white p-4 shadow dark:bg-gray-900" data-mis-pedidos>
        <p class="text-sm text-gray-500" data-estado>Cargando…</p>
        <div class="overflow-x-auto"><table class="hidden w-full text-left text-sm" data-tabla>
            <thead><tr class="border-b text-gray-500">
                <th class="py-2 pr-3">Pedido</th><th class="py-2 pr-3">Fecha</th><th class="py-2 pr-3">Unidades</th><th class="py-2 pr-3">Total</th>
                <th class="py-2 pr-3">Estado</th><th class="py-2 pr-3">Despacho</th><th class="py-2 pr-3">Pago</th><th class="py-2"></th>
            </tr></thead>
            <tbody></tbody>
        </table></div>
        <div class="mt-3 flex items-center justify-between text-sm"><button type="button" class="transparent-button" data-ant>&larr; Anteriores</button><span data-pag></span><button type="button" class="transparent-button" data-sig>Siguientes &rarr;</button></div>
    </div>

    <dialog class="rounded-lg p-0 shadow-xl backdrop:bg-black/40" data-detalle style="max-width: 40rem; width: 94vw;">
        <div class="bg-white p-5 dark:bg-gray-900 dark:text-gray-200">
            <p class="text-lg font-bold text-gray-800 dark:text-white" data-det-titulo></p>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300" data-det-meta></p>
            <div class="mt-3 overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr class="border-b text-gray-500"><th class="py-1 pr-3">Prenda</th><th class="py-1 pr-3">Color</th><th class="py-1 pr-3">Talla</th><th class="py-1 pr-3 text-right">Cant.</th><th class="py-1 text-right">Total</th></tr></thead><tbody data-det-lineas></tbody></table></div>
            <p class="mt-3 text-sm" data-det-guias></p>
            <div class="mt-4 text-right"><button type="button" class="transparent-button" data-det-cerrar>Cerrar</button></div>
        </div>
    </dialog>

    @pushOnce('scripts')
        <script>
            (function () {
                const raiz = document.querySelector('[data-mis-pedidos]');
                const datosUrl = @json(route('admin.autoservicio.pedidos.data'));
                const detalleUrl = @json(route('admin.autoservicio.pedidos.show', ['pedido' => '__ID__']));
                const modal = document.querySelector('[data-detalle]');
                let pagina = 1; let total = 0; const porPagina = 15;
                const dinero = (n) => '$ ' + Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
                const celda = (tr, texto, clase) => { const td = document.createElement('td'); td.className = clase || 'py-2 pr-3'; td.textContent = texto; tr.append(td); return td; };

                async function pedir(url) {
                    const r = await fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
                    if (! r.ok) { throw new Error('No pudimos cargar la información.'); }
                    return r.json();
                }

                async function cargar() {
                    const estado = raiz.querySelector('[data-estado]'); const tabla = raiz.querySelector('[data-tabla]');
                    try {
                        const r = await pedir(datosUrl + '?pagina=' + pagina);
                        total = r.total; const cuerpo = tabla.querySelector('tbody'); cuerpo.replaceChildren();
                        r.data.forEach((p) => {
                            const tr = document.createElement('tr'); tr.className = 'border-b';
                            celda(tr, '#' + p.numero, 'py-2 pr-3 font-semibold'); celda(tr, new Date(p.fecha.replace(' ', 'T')).toLocaleDateString('es-CO')); celda(tr, p.unidades);
                            celda(tr, dinero(p.total)); celda(tr, p.estado); celda(tr, p.estado_despacho); celda(tr, p.estado_pago);
                            const td = document.createElement('td'); const b = document.createElement('button'); b.type = 'button'; b.className = 'secondary-button'; b.textContent = 'Ver';
                            b.addEventListener('click', () => ver(p.id)); td.append(b); tr.append(td); cuerpo.append(tr);
                        });
                        estado.textContent = r.data.length ? '' : 'Todavía no tienes pedidos.';
                        tabla.classList.toggle('hidden', ! r.data.length);
                        raiz.querySelector('[data-pag]').textContent = 'Página ' + pagina + ' de ' + Math.max(1, Math.ceil(total / porPagina));
                    } catch (e) { estado.textContent = e.message; }
                }

                async function ver(id) {
                    try {
                        const p = await pedir(detalleUrl.replace('__ID__', id));
                        modal.querySelector('[data-det-titulo]').textContent = 'Pedido #' + p.numero;
                        modal.querySelector('[data-det-meta]').textContent = [p.estado, p.entrega, 'Total ' + dinero(p.total), 'Despacho: ' + p.estado_despacho, 'Pago: ' + p.estado_pago].join(' · ');
                        const cuerpo = modal.querySelector('[data-det-lineas]'); cuerpo.replaceChildren();
                        p.prendas.forEach((l) => { const tr = document.createElement('tr'); tr.className = 'border-b'; celda(tr, l.nombre, 'py-1 pr-3'); celda(tr, l.color, 'py-1 pr-3'); celda(tr, l.talla, 'py-1 pr-3'); celda(tr, l.cantidad, 'py-1 pr-3 text-right'); celda(tr, dinero(l.total), 'py-1 text-right'); cuerpo.append(tr); });
                        modal.querySelector('[data-det-guias]').textContent = p.guias.length ? 'Guía: ' + p.guias.map((g) => [g.transportadora, g.numero_guia].filter(Boolean).join(' ')).join(' · ') : '';
                        modal.showModal();
                    } catch (e) { raiz.querySelector('[data-estado]').textContent = e.message; }
                }

                raiz.querySelector('[data-ant]').addEventListener('click', () => { if (pagina > 1) { pagina--; cargar(); } });
                raiz.querySelector('[data-sig]').addEventListener('click', () => { if (pagina * porPagina < total) { pagina++; cargar(); } });
                modal.querySelector('[data-det-cerrar]').addEventListener('click', () => modal.close());
                cargar();
            })();
        </script>
    @endPushOnce
</x-admin::layouts>
