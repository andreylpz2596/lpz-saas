{{--
    Doc. 32: comportamiento de «Precios por grupo» (JavaScript simple, sin dependencias). Sin JavaScript la pantalla sigue
    funcionando: las casillas de la pagina se envian con el formulario y el editor se abre con su enlace.

    OJO: Bagisto monta Vue sobre #app en el evento `load` y RE-CREA el DOM de la pagina. Por eso todo va por delegacion en
    `document` (que no se reemplaza), los nodos se buscan en cada evento y la inicializacion corre despues del montaje.

    Seleccion entre paginas: se recuerda por grupo y filtros en sessionStorage (comodidad del navegador). La autoridad es el
    servidor: la vista previa muestra la lista exacta que se editara.
--}}
@pushOnce('scripts')
    <script>
        (() => {
            const raiz = () => document.querySelector('[data-pantalla="precios-grupo"]');
            const formulario = () => document.getElementById('pg-masivo');
            const casillas = () => [...document.querySelectorAll('[data-sel-var]')];
            const campos = { fijo: ['valor'], porcentaje: ['valor'], completar: ['tipo', 'valor'], ajustar: ['modo', 'valor', 'redondeo'], quitar: [] };
            const ayudas = @json(trans('ventasasesor::app.precios.ayudas-valor'));
            const textoFuera = @json(trans('ventasasesor::app.precios.en-otras-paginas'));
            const textoSinSeleccion = @json(trans('ventasasesor::app.precios.sin-seleccion'));
            const textoCambiosSinGuardar = @json(trans('ventasasesor::app.precios.cambios-sin-guardar'));
            const redondeos = @json(trans('ventasasesor::app.precios.redondeo-explicacion'));

            // ---- Cambios sin guardar (formulario masivo o editor individual con datos) y envio en curso ----
            let sucio = false;
            let enviando = false;
            let grupoInicial = null;

            // ---- Seleccion por grupo que sobrevive al cambio de pagina ----
            // La seleccion pertenece al grupo Y a los filtros: al cambiar Publico, color, etc. empieza vacia (no arrastra
            // variantes marcadas con otro filtro). Entre paginas del MISMO filtro se conserva.
            const clave = () => 'va-precios-grupo-sel:' + (formulario()?.dataset.grupo ?? '') + ':' + (formulario()?.dataset.firmaFiltros ?? '');
            const leer = () => { try { return new Set(JSON.parse(sessionStorage.getItem(clave()) || '[]').map(String)); } catch (e) { return new Set(); } };
            const guardar = (s) => { try { sessionStorage.setItem(clave(), JSON.stringify([...s])); } catch (e) {} };
            let seleccion = new Set();

            const pintar = () => {
                const todas = casillas();
                const enPagina = new Set(todas.map((c) => c.value));
                todas.forEach((c) => { c.checked = seleccion.has(c.value); });
                document.querySelectorAll('[data-sel-ref]').forEach((r) => {
                    const hijas = todas.filter((c) => c.dataset.ref === r.dataset.selRef);
                    const marcadas = hijas.filter((c) => c.checked).length;
                    r.checked = hijas.length > 0 && marcadas === hijas.length;
                    r.indeterminate = marcadas > 0 && marcadas < hijas.length;
                });
                const pagina = document.querySelector('[data-sel-pagina]');
                if (pagina) {
                    const marcadas = todas.filter((c) => c.checked).length;
                    pagina.checked = todas.length > 0 && marcadas === todas.length;
                    pagina.indeterminate = marcadas > 0 && marcadas < todas.length;
                }
                document.querySelectorAll('[data-sel-total]').forEach((t) => { t.textContent = seleccion.size; });
                const fuera = [...seleccion].filter((id) => ! enPagina.has(id)).length;
                const nota = document.querySelector('[data-sel-fuera]');
                if (nota) nota.textContent = fuera > 0 ? textoFuera.replace(':n', fuera) : '';
            };
            const marcar = (ids, valor) => { ids.forEach((id) => valor ? seleccion.add(id) : seleccion.delete(id)); guardar(seleccion); pintar(); };

            // ---- Campos de la edicion masiva segun la accion ----
            const actualizarCampos = () => {
                const form = formulario();
                if (! form) return;
                const op = form.querySelector('[data-operacion]').value;
                form.querySelectorAll('[data-campo]').forEach((c) => {
                    const visible = campos[op].includes(c.dataset.campo);
                    c.classList.toggle('pg-oculto', ! visible);
                    c.querySelectorAll('input, select').forEach((i) => { i.disabled = ! visible; });
                });
                const tipo = form.querySelector('[name="tipo"]').value;
                const modo = form.querySelector('[name="modo_ajuste"]').value;
                const c = op === 'completar' ? 'completar_' + tipo : (op === 'ajustar' ? 'ajustar_' + modo : op);
                form.querySelector('[data-ayuda-valor]').textContent = ayudas[c] ?? '';
                const valor = form.querySelector('[name="valor"]');
                valor.required = campos[op].includes('valor');
                valor.min = op === 'ajustar' ? '' : '0.01';
                const ayudaRedondeo = form.querySelector('[data-ayuda-redondeo]');
                if (ayudaRedondeo) ayudaRedondeo.textContent = redondeos[form.querySelector('[name="redondeo"]').value] ?? '';
            };

            document.addEventListener('change', (e) => {
                if (! raiz()) return;
                const t = e.target;
                if (t.matches('[data-sel-var]')) marcar([t.value], t.checked);
                else if (t.matches('[data-sel-ref]')) marcar(casillas().filter((c) => c.dataset.ref === t.dataset.selRef).map((c) => c.value), t.checked);
                else if (t.matches('[data-sel-pagina]')) marcar(casillas().map((c) => c.value), t.checked);
                else if (t.closest('#pg-masivo') && t.matches('[data-operacion], [name="tipo"], [name="modo_ajuste"], [name="redondeo"]')) actualizarCampos();
                else if (t.matches('[data-select-grupo]')) {
                    // Cambiar de grupo con una seleccion o una edicion pendiente: se pregunta. La seleccion es POR grupo
                    // (no pasa al otro), y se aplica el grupo nuevo de inmediato para que nunca quede un grupo «a medias».
                    if ((seleccion.size > 0 || sucio) && grupoInicial !== null && ! confirm(textoCambiosSinGuardar)) {
                        t.value = grupoInicial;
                        return;
                    }
                    sucio = false;
                    enviando = true;
                    t.form.requestSubmit ? t.form.requestSubmit() : t.form.submit();
                }
            });

            // Un formulario de edicion con datos cuenta como «sin guardar» hasta previsualizar.
            document.addEventListener('input', (e) => {
                if (raiz() && e.target.closest('#pg-masivo, [data-form="individual"]') && e.target.matches('input[type="number"], input[type="text"]')) sucio = true;
            });

            window.addEventListener('beforeunload', (e) => {
                if (raiz() && sucio && ! enviando) { e.preventDefault(); e.returnValue = ''; }
            });

            // Estado «ocupado» de los botones de envio (evita doble clic y muestra que se esta trabajando).
            document.addEventListener('submit', (e) => {
                // Enviar es la salida esperada (no se avisa al salir); si otro manejador cancela el envio (seleccion vacia),
                // se restaura el estado en el siguiente ciclo.
                const antes = sucio;
                sucio = false;
                setTimeout(() => {
                    if (e.defaultPrevented) { sucio = antes; return; }
                    enviando = true;
                    const boton = e.target.querySelector('[data-ocupado]');
                    if (boton && ! boton.dataset.enviado) {
                        boton.dataset.enviado = '1';
                        boton.setAttribute('aria-busy', 'true');
                        boton.textContent = boton.dataset.ocupado;
                        boton.disabled = true;
                    }
                }, 0);
            });

            document.addEventListener('input', (e) => {
                if (! e.target.matches('[data-buscar-grupo]')) return;
                // Grupo buscable: filtra las opciones del selector; si queda una sola, la elige.
                const texto = e.target.value.trim().toLowerCase();
                const selector = document.querySelector('[data-select-grupo]');
                [...selector.options].forEach((o) => { o.hidden = o.value !== '' && texto !== '' && ! o.text.toLowerCase().includes(texto); });
                const visibles = [...selector.options].filter((o) => o.value !== '' && ! o.hidden);
                if (visibles.length === 1) selector.value = visibles[0].value;
            });

            document.addEventListener('submit', (e) => {
                const form = e.target;
                if (form.id !== 'pg-masivo') return;
                // «Variantes seleccionadas»: se agregan las marcadas en OTRAS paginas.
                form.querySelectorAll('[data-sel-extra]').forEach((n) => n.remove());
                if (form.querySelector('[name="alcance"]:checked')?.value !== 'seleccion') return;
                if (seleccion.size === 0) { e.preventDefault(); alert(textoSinSeleccion); return; }
                const enPagina = new Set(casillas().map((c) => c.value));
                [...seleccion].filter((id) => ! enPagina.has(id)).forEach((id) => {
                    const oculto = document.createElement('input');
                    Object.assign(oculto, { type: 'hidden', name: 'ids[]', value: id });
                    oculto.dataset.selExtra = '1';
                    form.appendChild(oculto);
                });
            });

            document.addEventListener('click', (e) => {
                if (! raiz()) return;
                const t = e.target;

                if (t.closest('[data-sel-limpiar]')) { seleccion = new Set(); guardar(seleccion); pintar(); return; }

                const expandir = t.closest('[data-expandir]');
                if (expandir) { document.querySelectorAll('details.pg-ref').forEach((d) => { d.open = expandir.dataset.expandir === '1'; }); return; }

                // La casilla de la referencia vive dentro de <summary>: marcarla no debe abrir ni cerrar la tarjeta.
                if (t.matches('[data-sel-ref]')) { e.stopPropagation(); return; }

                const boton = t.closest('[data-editar]');
                if (boton) {
                    const fila = document.querySelector('[data-editor="' + boton.dataset.editar + '"]');
                    const abrir = fila.classList.contains('pg-oculto');
                    fila.classList.toggle('pg-oculto', ! abrir);
                    boton.setAttribute('aria-expanded', abrir ? 'true' : 'false');
                    if (! abrir) return;
                    fila.querySelector('[data-campo-valor]')?.focus();
                    const comparar = fila.querySelector('[data-comparar]');
                    if (comparar && ! comparar.dataset.cargado) {
                        comparar.dataset.cargado = '1';
                        fetch(comparar.dataset.comparar, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                            .then((r) => r.ok ? r.text() : Promise.reject(r.status))
                            .then((html) => { comparar.innerHTML = html; })
                            .catch(() => { delete comparar.dataset.cargado; });
                    }
                    return;
                }

                const pre = t.closest('[data-precargar]');
                if (pre) {
                    const f = document.querySelector('[data-form="individual"][data-variante="' + pre.dataset.precargar + '"]');
                    f.querySelector('[data-campo-qty]').value = pre.dataset.qty;
                    f.querySelector('[data-campo-op]').value = pre.dataset.tipo === 'discount' ? 'porcentaje' : 'fijo';
                    f.querySelector('[data-campo-valor]').value = pre.dataset.valor;
                    f.querySelector('[data-campo-valor]').focus();
                }
            });

            const iniciar = () => {
                if (! raiz()) return;
                seleccion = formulario() ? leer() : new Set();
                grupoInicial = document.querySelector('[data-select-grupo]')?.value ?? null;
                actualizarCampos();
                pintar();
            };

            // Despues de que Bagisto monte Vue (window load) para trabajar sobre el DOM definitivo.
            if (document.readyState === 'complete') setTimeout(iniciar, 0);
            else window.addEventListener('load', () => setTimeout(iniciar, 0));
        })();
    </script>
@endPushOnce
