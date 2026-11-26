{{-- Doc. 33 P1: «Acceso al aplicativo» en la ficha nativa del cliente. JavaScript nativo (sin Vue) para no depender del arbol de la ficha.
     Toda regla vive en ClienteAccesoService; aqui solo se muestra el estado y se llaman las rutas ventas_asesor.clientes_acceso.*. --}}
<div class="box-shadow rounded bg-white p-4 dark:bg-gray-900" id="acceso-cliente-{{ $clienteId }}" data-tarjeta-acceso data-cliente="{{ $clienteId }}">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-base font-semibold text-gray-800 dark:text-white">Acceso al aplicativo</p>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300" data-acceso-estado>Cargando…</p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400" data-acceso-detalle></p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400" data-acceso-resumen></p>
        </div>

        <button type="button" class="secondary-button" data-acceso-abrir aria-label="Administrar acceso al aplicativo">
            <span class="icon-setting text-xl"></span> Administrar
        </button>
    </div>

    <dialog class="rounded-lg p-0 shadow-xl backdrop:bg-black/40" data-acceso-modal style="max-width: 32rem; width: 92vw;">
        <div class="bg-white p-5 dark:bg-gray-900 dark:text-gray-200">
            <p class="text-lg font-bold text-gray-800 dark:text-white">Acceso al aplicativo</p>
            <dl class="mt-3 grid gap-1 text-sm" data-acceso-ficha></dl>

            <p class="mt-3 hidden rounded bg-red-50 p-2 text-sm text-red-700" data-acceso-error role="alert"></p>
            <p class="mt-3 hidden rounded bg-green-50 p-2 text-sm text-green-800" data-acceso-ok></p>

            <div class="mt-4 grid gap-3" data-acceso-acciones></div>

            <div class="mt-4 text-right">
                <button type="button" class="transparent-button" data-acceso-cerrar>Cerrar</button>
            </div>
        </div>
    </dialog>
</div>

@pushOnce('scripts')
    <script>
        (function () {
            // La tarjeta vive DENTRO del x-template de la ficha (v-customer-view): al correr este script aun no existe en el DOM y
            // Vue la crea despues (y puede recrearla). Se busca SIEMPRE la tarjeta vigente y se espera a que aparezca.
            const tarjeta = () => document.querySelector('[data-tarjeta-acceso]');
            const cliente = @json((string) $clienteId);
            const base = @json(url(config('app.admin_url').'/ventas-asesor/clientes/__ID__/acceso')).replace('__ID__', cliente);
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || @json(csrf_token());
            const modal = () => tarjeta().querySelector('[data-acceso-modal]');
            const q = (s) => tarjeta().querySelector(s);
            const ETIQUETA = { sin_acceso: 'Sin acceso', activo: 'Activo', suspendido: 'Suspendido' };
            let datos = null;

            const texto = (el, valor) => { el.textContent = valor; };

            async function llamar(ruta, cuerpo) {
                const r = await fetch(base + ruta, {
                    method: cuerpo === undefined ? 'GET' : 'POST',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
                    body: cuerpo === undefined ? undefined : JSON.stringify(cuerpo),
                    credentials: 'same-origin',
                });
                const json = await r.json().catch(() => ({}));

                if (! r.ok) {
                    throw new Error(json.mensaje || (json.errors ? Object.values(json.errors).flat().join(' ') : 'No se pudo completar la operación.'));
                }

                return json;
            }

            function mensaje(clase, valor) {
                const err = q('[data-acceso-error]'); const ok = q('[data-acceso-ok]');
                err.classList.add('hidden'); ok.classList.add('hidden');
                if (valor) { const el = clase === 'error' ? err : ok; texto(el, valor); el.classList.remove('hidden'); }
            }

            function boton(etiqueta, clase, alHacer) {
                const b = document.createElement('button');
                b.type = 'button'; b.className = clase; b.textContent = etiqueta;
                b.addEventListener('click', async () => {
                    b.disabled = true;
                    try { await alHacer(); } catch (e) { mensaje('error', e.message); } finally { b.disabled = false; }
                });

                return b;
            }

            function campo(etiqueta, atributos) {
                const wrap = document.createElement('label');
                wrap.className = 'grid gap-1 text-sm';
                const span = document.createElement('span'); span.textContent = etiqueta;
                const input = document.createElement('input');
                input.className = 'rounded-md border px-3 py-2 dark:border-gray-800 dark:bg-gray-900';
                Object.assign(input, atributos);
                wrap.append(span, input);

                return { wrap, input };
            }

            function pintar() {
                const estado = datos.estado;
                texto(q('[data-acceso-estado]'), 'Estado: ' + (ETIQUETA[estado] || estado));
                texto(q('[data-acceso-detalle]'), datos.usuario ? 'Usuario: ' + datos.usuario.email : '');
                const r = datos.resumen;
                texto(q('[data-acceso-resumen]'), 'Clientes con acceso activo: ' + r.activos + ' · suspendidos: ' + r.suspendidos + ' · sin acceso: ' + r.sin_acceso);

                const ficha = q('[data-acceso-ficha]'); ficha.replaceChildren();
                const filas = [['Estado', ETIQUETA[estado] || estado]];
                if (datos.usuario) {
                    filas.push(['Usuario', datos.usuario.email], ['Nombre', datos.usuario.nombre], ['Rol', datos.usuario.rol || '—']);
                    if (datos.habilitado_at) { filas.push(['Habilitado', new Date(datos.habilitado_at).toLocaleString('es-CO')]); }
                    if (datos.suspendido_motivo) { filas.push(['Motivo de suspensión', datos.suspendido_motivo]); }
                }
                filas.push(['Último acceso', datos.ultimo_acceso || 'Sin información (Bagisto no lo registra)']);
                filas.forEach(([k, v]) => {
                    const dt = document.createElement('dt'); dt.className = 'text-gray-500'; dt.textContent = k;
                    const dd = document.createElement('dd'); dd.className = 'font-medium'; dd.textContent = v;
                    ficha.append(dt, dd);
                });

                const acc = q('[data-acceso-acciones]'); acc.replaceChildren();
                const recargar = async (ruta, cuerpo, ok) => { datos = await llamar(ruta, cuerpo); pintar(); mensaje('ok', ok); };

                if (estado === 'sin_acceso') {
                    const correo = campo('Correo del usuario (si lo dejas vacío se usa el del cliente: ' + (datos.email_cliente || 'sin correo') + ')', { type: 'email', placeholder: datos.email_cliente || 'correo@ejemplo.com' });
                    acc.append(correo.wrap, boton('Habilitar acceso y crear usuario', 'primary-button', async () => {
                        const r = await llamar('/crear-usuario', { email: correo.input.value.trim() || null });
                        datos = r; pintar();
                        mensaje('ok', r.enlace_enviado ? 'Acceso habilitado. Se envió al cliente el enlace para crear su contraseña.' : 'Acceso habilitado, pero no se pudo enviar el correo: el cliente puede usar «¿Olvidaste tu contraseña?» en el ingreso.');
                    }));
                    const existente = campo('…o vincular un usuario que ya tiene el rol de autoservicio (ID)', { type: 'number', min: 1, placeholder: 'ID del usuario' });
                    acc.append(existente.wrap, boton('Vincular usuario existente', 'secondary-button', () => recargar('/vincular', { admin_id: Number(existente.input.value) }, 'Usuario vinculado.')));
                } else {
                    const motivo = campo('Motivo (obligatorio para suspender o desvincular)', { type: 'text', maxLength: 500 });
                    acc.append(motivo.wrap);
                    if (estado === 'activo') {
                        acc.append(boton('Suspender acceso', 'secondary-button', () => recargar('/suspender', { motivo: motivo.input.value }, 'Acceso suspendido.')));
                    } else {
                        acc.append(boton('Reactivar acceso', 'primary-button', () => recargar('/reactivar', {}, 'Acceso reactivado.')));
                    }
                    acc.append(boton('Desvincular usuario', 'transparent-button text-red-600', () => recargar('/desvincular', { motivo: motivo.input.value }, 'Usuario desvinculado.')));

                    // Solo el administrador: poner la contrasena a mano (otra opcion ademas del enlace por correo).
                    if (datos.puede_cambiar_contrasena) {
                        const caja = document.createElement('div');
                        caja.className = 'mt-2 grid gap-2 border-t pt-3 dark:border-gray-800';
                        const titulo = document.createElement('p'); titulo.className = 'text-sm font-semibold'; titulo.textContent = 'Cambiar contraseña manualmente';
                        const nota = document.createElement('p'); nota.className = 'text-xs text-gray-500 dark:text-gray-400'; nota.textContent = 'Entrégasela al cliente por un medio seguro. Mínimo 8 caracteres.';
                        const clave = campo('Nueva contraseña', { type: 'password', autocomplete: 'new-password', minLength: 8, maxLength: 100 });
                        const repetir = campo('Repite la contraseña', { type: 'password', autocomplete: 'new-password', minLength: 8, maxLength: 100 });
                        const ver = document.createElement('label'); ver.className = 'flex items-center gap-2 text-xs';
                        const check = document.createElement('input'); check.type = 'checkbox';
                        check.addEventListener('change', () => { clave.input.type = repetir.input.type = check.checked ? 'text' : 'password'; });
                        ver.append(check, document.createTextNode('Mostrar'));
                        caja.append(titulo, nota, clave.wrap, repetir.wrap, ver, boton('Guardar contraseña', 'primary-button', async () => {
                            if (clave.input.value.length < 8) { throw new Error('La contraseña debe tener al menos 8 caracteres.'); }
                            if (clave.input.value !== repetir.input.value) { throw new Error('Las contraseñas no coinciden.'); }
                            await recargar('/contrasena', { contrasena: clave.input.value, contrasena_confirmation: repetir.input.value }, 'Contraseña actualizada. El cliente ya puede ingresar con ella.');
                        }));
                        acc.append(caja);
                    }
                }
            }

            let pedido = null;
            let errorCarga = null;

            // Pinta la tarjeta vigente si aun no lo esta (nueva o recreada por Vue). La consulta al servidor se hace UNA vez.
            function asegurar() {
                const t = tarjeta();

                if (! t || t.dataset.accesoListo === '1') {
                    return;
                }

                t.dataset.accesoListo = '1';

                if (datos) { pintar(); return; }
                if (errorCarga) { texto(q('[data-acceso-estado]'), errorCarga); return; }

                pedido = pedido || llamar('')
                    .then((r) => { datos = r; })
                    .catch((e) => { errorCarga = e.message; });

                pedido.then(() => {
                    if (! tarjeta()) { return; }
                    if (datos) { pintar(); } else { texto(q('[data-acceso-estado]'), errorCarga); }
                });
            }

            document.addEventListener('click', (e) => {
                if (e.target.closest('[data-acceso-abrir]')) { mensaje('error', null); if (datos) { pintar(); } modal().showModal(); }
                if (e.target.closest('[data-acceso-cerrar]')) { modal().close(); }
            });

            new MutationObserver(asegurar).observe(document.documentElement, { childList: true, subtree: true });

            if (document.readyState === 'complete') { setTimeout(asegurar, 0); } else { window.addEventListener('load', () => setTimeout(asegurar, 0)); }
        })();
    </script>
@endPushOnce
