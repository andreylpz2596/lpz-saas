{{--
    D10 V1 · Saldo a favor (credito) del cliente. Todas las cifras salen de CreditoClienteService; aqui no se calcula nada.
    Aplicar un credito NO es recibir un pago: es una compensacion (no genera caja, recaudo ni custodia). Los permisos se vuelven a
    validar en el servidor; los botones solo reflejan lo que el actor puede hacer.
    Variables: $credito (delCliente o, en modo pedido, una sola fila), $elegibles, $puedeAplicar, $puedeReembolsar, $puedeReversar, $dinero (closure),
    $modo ('cliente' | 'pedido'), $puedeAjustar, $pedidoFlete (flete cobrado del pedido, solo modo pedido).
--}}
@php
    $modo = $modo ?? 'cliente';
    $puedeAjustar = $puedeAjustar ?? false;
    $filas = $credito['pedidos'];
    $sumar = fn (string $k) => array_sum(array_column($filas, $k));
    $textos = trans('ventasasesor::credito');
    $rutas = [
        'aplicar' => route('admin.ventas_asesor.cartera.credito.aplicar'),
        'reembolsar' => route('admin.ventas_asesor.cartera.credito.reembolsar'),
        'pedido' => url(config('app.admin_url').'/ventas-asesor/cartera/credito/pedido'),
        'reversar' => url(config('app.admin_url').'/ventas-asesor/cartera/credito'),
        'ajustar' => route('admin.ventas_asesor.cartera.credito.ajustar'),
        'ajustes' => url(config('app.admin_url').'/ventas-asesor/cartera/credito/ajustes'),
        'comprobante' => url(config('app.admin_url').'/ventas-asesor/cartera/credito'),
    ];
    $causas = ['error_bylopez' => trans('ventasasesor::credito.causas.error_bylopez'), 'transportadora' => trans('ventasasesor::credito.causas.transportadora'), 'decision_administrativa' => trans('ventasasesor::credito.causas.decision_administrativa')];
@endphp

@pushOnce('styles')
<style>
    .va-cr { margin-top: 20px; }
    .va-cr-lista { margin-top: 12px; overflow: hidden; }
    .va-cr-cab { padding: 12px 16px; font-size: 14px; font-weight: 700; color: #1e293b; }
    .va-cr-fila { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 16px; border-top: 1px solid #e2e8f0; }
    .va-cr-id { min-width: 8rem; }
    .va-cr-num { font-weight: 700; color: #0f172a; margin: 0; }
    .va-cr-sub { font-size: 12px; color: #64748b; margin: 2px 0 0; }
    .va-cr-cifras { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 4px 16px; flex: 1 1 16rem; margin: 0; font-size: 12px; }
    .va-cr-cifras dt { color: #64748b; } .va-cr-cifras dd { margin: 0; font-weight: 600; }
    .va-cr-cifras .va-cr-disp { font-weight: 800; color: #3730a3; }
    .va-cr-acc { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
    .va-cr-dlg { width: calc(100vw - 32px); max-width: 32rem; border: 1px solid #cbd5e1; border-radius: 12px; padding: 0; }
    .va-cr-dlg::backdrop { background: rgba(15, 23, 42, .45); }
    .va-cr-form { padding: 16px; }
    .va-cr-tit { margin: 0; font-size: 16px; font-weight: 700; color: #0f172a; }
    .va-cr-campos { display: grid; gap: 12px; margin-top: 12px; }
    .va-cr-lbl { display: block; font-size: 12px; font-weight: 600; color: #475569; }
    .va-cr-in { display: block; width: 100%; box-sizing: border-box; margin-top: 4px; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 16px; background: #fff; color: #0f172a; }
    .va-cr-err { margin: 12px 0 0; min-height: 1em; font-size: 14px; font-weight: 500; color: #9f1239; }
    .va-cr-pie { display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; }
    .va-cr-movs { display: grid; gap: 8px; margin: 0; padding: 0; list-style: none; }
    .va-cr-mov { border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px; font-size: 12px; }
    .va-cr-link { color: #3730a3; text-decoration: underline; font-size: 12px; }
    .va-cr-dlg { max-height: calc(100dvh - 32px); overflow-y: auto; }
    .va-cr-mov-cab { display: flex; justify-content: space-between; gap: 8px; }
    @media (min-width: 640px) { .va-cr-cifras { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
    .dark .va-cr-cab, .dark .va-cr-num, .dark .va-cr-tit { color: #fff; } .dark .va-cr-fila { border-color: #1f2937; }
    .dark .va-cr-dlg { background: #111827; border-color: #374151; } .dark .va-cr-in { background: #1f2937; color: #fff; border-color: #374151; }
</style>
@endpushOnce
<section class="va-cr" data-credito-cliente aria-labelledby="credito-titulo">
    <div class="va-panel va-kpi-box" style="border-color:#a5b4fc">
        <p class="va-kpi-label" id="credito-titulo">@lang('ventasasesor::credito.disponible_cliente')</p>
        <p class="va-kpi-value" data-credito-disponible>{{ $dinero($credito['total_disponible']) }}</p>
        <p class="va-sub">@lang('ventasasesor::credito.no_es_pago')</p>
        @if ($modo === 'cliente' && $filas !== [])
            <dl class="va-cr-cifras" style="margin-top:8px" data-credito-totales>
                <div><dt>@lang('ventasasesor::credito.generado')</dt><dd>{{ $dinero($sumar('generado')) }}</dd></div>
                <div><dt>@lang('ventasasesor::credito.utilizado')</dt><dd>{{ $dinero($sumar('aplicado')) }}</dd></div>
                <div><dt>@lang('ventasasesor::credito.reembolsado')</dt><dd>{{ $dinero($sumar('reembolsado')) }}</dd></div>
                <div><dt>@lang('ventasasesor::credito.disponible')</dt><dd class="va-cr-disp">{{ $dinero($credito['total_disponible']) }}</dd></div>
            </dl>
        @endif
    </div>

    @if ($filas !== [])
        <div class="va-panel va-cr-lista">
            <div class="va-cr-cab">@lang('ventasasesor::credito.titulo')</div>

            <div>
                @foreach ($filas as $f)
                    <div class="va-cr-fila" data-credito-fila="{{ $f['order_id'] }}">
                        <div class="va-cr-id">
                            <p class="va-cr-num">#{{ $f['increment_id'] }}</p>
                            <p class="va-cr-sub">@lang($modo === 'pedido' ? 'ventasasesor::credito.este_pedido' : 'ventasasesor::credito.origen')</p>
                        </div>

                        <dl class="va-cr-cifras">
                            <div><dt>@lang('ventasasesor::credito.generado')</dt><dd>{{ $dinero($f['generado']) }}</dd></div>
                            <div><dt>@lang('ventasasesor::credito.utilizado')</dt><dd>{{ $dinero($f['aplicado']) }}</dd></div>
                            <div><dt>@lang('ventasasesor::credito.reembolsado')</dt><dd>{{ $dinero($f['reembolsado']) }}</dd></div>
                            <div><dt>@lang('ventasasesor::credito.disponible')</dt><dd class="va-cr-disp">{{ $dinero($f['disponible']) }}</dd></div>
                            @if (($f['aplicado_a_este_pedido'] ?? 0) > 0)
                                <div><dt>@lang('ventasasesor::credito.recibido_de_otros')</dt><dd>{{ $dinero($f['aplicado_a_este_pedido']) }}</dd></div>
                            @endif
                        </dl>

                        <div class="va-cr-acc">
                            @if ($f['en_custodia'] > 0)
                                <span class="va-pill va-pill--amarillo va-pill--sm"><span>@lang('ventasasesor::credito.en_custodia'): {{ $dinero($f['en_custodia']) }}</span></span>
                            @endif
                            @if ($f['sin_recibir'] > 0 && $f['en_custodia'] <= 0)
                                <span class="va-pill va-pill--amarillo va-pill--sm"><span>@lang('ventasasesor::credito.sin_recibir'): {{ $dinero($f['sin_recibir']) }}</span></span>
                            @endif

                            @if ($puedeAplicar && $f['disponible'] > 0 && $elegibles !== [])
                                <button type="button" class="va-btn va-btn--ok" data-credito-accion="aplicar" data-origen="{{ $f['order_id'] }}" data-inc="{{ $f['increment_id'] }}" data-max="{{ $f['disponible'] }}">@lang('ventasasesor::credito.aplicar')</button>
                            @endif
                            @if ($puedeReembolsar && $f['disponible'] > 0)
                                <button type="button" class="va-btn" data-credito-accion="reembolsar" data-origen="{{ $f['order_id'] }}" data-inc="{{ $f['increment_id'] }}" data-max="{{ $f['disponible'] }}">@lang('ventasasesor::credito.reembolsar')</button>
                            @endif
                            @if ($modo === 'pedido' && $puedeAjustar && ($pedidoFlete ?? 0) > 0)
                                <button type="button" class="va-btn" data-credito-accion="ajustar" data-origen="{{ $f['order_id'] }}" data-inc="{{ $f['increment_id'] }}" data-max="{{ $pedidoFlete }}">@lang('ventasasesor::credito.condonar_flete')</button>
                            @endif
                            <button type="button" class="va-btn" data-credito-accion="movimientos" data-origen="{{ $f['order_id'] }}" data-inc="{{ $f['increment_id'] }}">@lang('ventasasesor::credito.movimientos')</button>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <dialog data-credito-dialogo class="va-cr-dlg">
        <form method="dialog" class="va-cr-form" data-credito-form>
            <h2 class="va-cr-tit" data-credito-titulo></h2>
            <p class="va-cr-sub" data-credito-ayuda></p>
            <div class="va-cr-campos" data-credito-campos></div>
            <p class="va-cr-err" data-credito-error role="alert"></p>
            <div class="va-cr-pie">
                <button type="button" class="va-btn" data-credito-cerrar>@lang('ventasasesor::credito.campos.cancelar')</button>
                <button type="submit" class="va-btn va-btn--ok" data-credito-enviar>@lang('ventasasesor::credito.campos.confirmar')</button>
            </div>
        </form>
    </dialog>

    @pushOnce('scripts')
        <script>
            (() => {
                const T = @json($textos);
                const R = @json($rutas);
                const ELEGIBLES = @json($elegibles);
                const PUEDE_REVERSAR = @json($puedeReversar);
                const PUEDE_AJUSTAR = @json($puedeAjustar);
                const CAUSAS = @json($causas);
                const ARCHIVOS = @json(['mimes' => array_values((array) config('ventasasesor.comisiones.comprobantes.mimes')), 'max_kb' => (int) config('ventasasesor.comisiones.comprobantes.max_kb')]);
                const CSRF = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
                // El layout de Bagisto no trae la meta csrf-token: axios usa la cookie XSRF-TOKEN (Laravel la acepta en X-XSRF-TOKEN).
                const XSRF = () => decodeURIComponent((document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN=')) ?? '').split('=').slice(1).join('='));
                // Bagisto recompila este DOM al montar Vue y reemplaza los nodos: nada se enlaza a nodos concretos; todo es delegado y el dialogo se busca al usarlo.
                const dlgEl = () => document.querySelector('[data-credito-dialogo]');
                if (! dlgEl()) return;
                const $ = (s) => dlgEl().querySelector(s);
                const dinero = (v) => '$ ' + Number(v).toLocaleString('es-CO', { maximumFractionDigits: 0 });
                const campo = (id, etiqueta, html) => `<label class="va-cr-lbl" for="${id}">${etiqueta}${html}</label>`;
                const entrada = 'class="va-cr-in"';
                let ctx = null;

                const abrir = (titulo, ayuda, campos, enviar) => {
                    $('[data-credito-titulo]').textContent = titulo;
                    $('[data-credito-ayuda]').textContent = ayuda || '';
                    $('[data-credito-campos]').innerHTML = campos;
                    $('[data-credito-error]').textContent = '';
                    $('[data-credito-enviar]').style.display = enviar ? '' : 'none';
                    ctx = { enviar, clave: crypto.randomUUID() };
                    dlgEl().showModal();
                };

                const post = async (url, datos, archivo = null) => {
                    const f = archivo ? Object.entries({ ...datos, idempotency_key: ctx.clave }).reduce((fd, [k, v]) => (fd.append(k, v ?? ''), fd), new FormData()) : null;
                    if (f) f.append('comprobante', archivo);
                    const r = await fetch(url, { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF, 'X-XSRF-TOKEN': XSRF(), ...(f ? {} : { 'Content-Type': 'application/json' }) }, body: f ?? JSON.stringify({ ...datos, idempotency_key: ctx.clave }) });
                    const j = await r.json().catch(() => ({}));
                    if (! r.ok) throw new Error(j.mensaje || j.message || ('Error ' + r.status));
                    return j;
                };

                document.addEventListener('click', (e) => { if (e.target.closest('[data-credito-cerrar]')) dlgEl()?.close(); });
                document.addEventListener('submit', async (e) => {
                    if (! e.target.matches('[data-credito-form]')) return;
                    e.preventDefault();
                    if (! ctx?.enviar) return;
                    const boton = $('[data-credito-enviar]');
                    boton.disabled = true;
                    $('[data-credito-error]').textContent = '';
                    try { await ctx.enviar(); location.reload(); }
                    catch (err) { $('[data-credito-error]').textContent = err.message; boton.disabled = false; }
                });

                document.addEventListener('click', async (ev) => {
                    const b = ev.target.closest('[data-credito-accion]');
                    if (! b) return;
                    const origen = b.dataset.origen, inc = b.dataset.inc, max = Number(b.dataset.max || 0);

                    if (b.dataset.creditoAccion === 'aplicar') {
                        const opciones = ELEGIBLES.filter((p) => String(p.id) !== origen).map((p) => `<option value="${p.id}" data-saldo="${p.saldo}">#${p.increment_id} · ${dinero(p.saldo)}</option>`).join('');
                        abrir(`${T.aplicar} · #${inc}`, T.ayuda_aplicar,
                            campo('cd-destino', T.campos.pedido_destino, `<select id="cd-destino" ${entrada}>${opciones}</select>`)
                            + campo('cd-monto', `${T.campos.monto} (máx. ${dinero(max)})`, `<input id="cd-monto" type="number" inputmode="decimal" min="1" step="0.01" max="${max}" value="${max}" ${entrada}>`)
                            + campo('cd-motivo', T.campos.motivo, `<input id="cd-motivo" type="text" maxlength="500" ${entrada}>`),
                            () => post(R.aplicar, { origen: Number(origen), destino: Number($('#cd-destino').value), monto: $('#cd-monto').value, motivo: $('#cd-motivo').value }));
                        const tope = () => { const o = $('#cd-destino').selectedOptions[0]; if (o) $('#cd-monto').value = Math.min(max, Number(o.dataset.saldo)); };
                        $('#cd-destino').addEventListener('change', tope); tope();
                    } else if (b.dataset.creditoAccion === 'reembolsar') {
                        const medios = Object.entries(T.medios).map(([k, v]) => `<option value="${k}">${v}</option>`).join('');
                        abrir(`${T.reembolsar} · #${inc}`, T.ayuda_reembolsar,
                            campo('cd-monto', `${T.campos.monto} (máx. ${dinero(max)})`, `<input id="cd-monto" type="number" inputmode="decimal" min="1" step="0.01" max="${max}" value="${max}" ${entrada}>`)
                            + campo('cd-medio', T.campos.medio, `<select id="cd-medio" ${entrada}>${medios}</select>`)
                            + campo('cd-ref', T.campos.referencia, `<input id="cd-ref" type="text" maxlength="100" ${entrada}>`)
                            + campo('cd-motivo', T.campos.motivo, `<input id="cd-motivo" type="text" maxlength="500" required ${entrada}>`)
                            + campo('cd-arch', `${T.campos.comprobante} (${ARCHIVOS.mimes.join(', ')} · ${Math.round(ARCHIVOS.max_kb / 1024)} MB)`, `<input id="cd-arch" type="file" accept="${ARCHIVOS.mimes.map((m) => '.' + m).join(',')}" ${entrada}><span class="va-cr-sub" data-credito-arch-estado></span>`),
                            () => {
                                const a = $('#cd-arch').files[0] ?? null;
                                if (a && a.size > ARCHIVOS.max_kb * 1024) throw new Error(T.campos.archivo_grande);
                                $('[data-credito-arch-estado]').textContent = a ? `${a.name} · ${T.campos.subiendo}` : '';
                                return post(R.reembolsar, { origen: Number(origen), monto: $('#cd-monto').value, medio: $('#cd-medio').value, referencia: $('#cd-ref').value, motivo: $('#cd-motivo').value }, a);
                            });
                    } else if (b.dataset.creditoAccion === 'ajustar') {
                        const causas = Object.entries(CAUSAS).map(([k, v]) => `<option value="${k}">${v}</option>`).join('');
                        abrir(`${T.condonar_flete} · #${inc}`, T.ayuda_ajustar,
                            campo('cd-monto', `${T.campos.monto} (máx. ${dinero(max)})`, `<input id="cd-monto" type="number" inputmode="decimal" min="1" step="0.01" max="${max}" value="${max}" ${entrada}>`)
                            + campo('cd-causa', T.campos.causa, `<select id="cd-causa" ${entrada}>${causas}</select>`)
                            + campo('cd-motivo', T.campos.motivo, `<input id="cd-motivo" type="text" maxlength="500" required ${entrada}>`),
                            () => post(R.ajustar, { pedido: Number(origen), monto: $('#cd-monto').value, causa: $('#cd-causa').value, motivo: $('#cd-motivo').value }));
                    } else {
                        abrir(`${T.movimientos} · #${inc}`, '', '<p class="va-cr-sub">…</p>', null);
                        const j = await (await fetch(`${R.pedido}/${origen}`, { headers: { 'Accept': 'application/json' } })).json();
                        const filas = j.movimientos.map((m) => {
                            const reversado = m.reverso_id !== null;
                            const estado = m.tipo === 'reverso_aplicacion' ? '' : (reversado ? T.estado.reversado : T.estado.activo);
                            const boton = PUEDE_REVERSAR && m.tipo === 'aplicacion' && ! reversado ? `<button type="button" class="va-btn" data-rev="${m.id}">${T.reversar}</button>` : '';
                            return `<li class="va-cr-mov"><div class="va-cr-mov-cab"><strong>${T.tipos[m.tipo] ?? m.tipo}</strong><span>${dinero(m.monto)}</span></div>`
                                + `<div class="va-cr-sub">#${m.origen} → ${m.destino ? '#' + m.destino : '—'} · ${m.created_at} · ${m.actor_nombre ?? ''} ${estado ? '· ' + estado : ''}</div>`
                                + (m.tiene_comprobante ? `<div><a class="va-cr-link" target="_blank" rel="noopener" href="${R.comprobante}/${m.id}/comprobante">${T.campos.ver_comprobante}</a></div>` : '')
                                + (m.motivo ? `<div>${m.motivo}</div>` : '') + (reversado && m.reverso_motivo ? `<div class="va-cr-sub">↩ ${m.reverso_motivo}</div>` : '') + `<div class="mt-1">${boton}</div></li>`;
                        }).join('') || '<li class="va-cr-sub">—</li>';
                        const ajustes = (j.ajustes ?? []).map((a) => `<li class="va-cr-mov"><div class="va-cr-mov-cab"><strong>${T.condonacion} · ${CAUSAS[a.causa] ?? a.causa}</strong><span>${dinero(a.monto)}</span></div>`
                            + `<div class="va-cr-sub">${a.created_at} · ${a.actor_nombre ?? ''} · ${T.estado[a.estado] ?? a.estado}</div><div>${a.motivo}</div>`
                            + (PUEDE_AJUSTAR && a.estado === 'activo' ? `<div class="mt-1"><button type="button" class="va-btn" data-rev-ajuste="${a.id}">${T.reversar_ajuste}</button></div>` : '') + '</li>').join('');
                        $('[data-credito-campos]').innerHTML = `<ul class="va-cr-movs">${filas}${ajustes}</ul>`
                            + ((PUEDE_REVERSAR || PUEDE_AJUSTAR) ? campo('cd-motivo-rev', T.campos.motivo, `<input id="cd-motivo-rev" type="text" maxlength="500" ${entrada}>`) : '');
                        $('[data-credito-campos]').querySelectorAll('[data-rev-ajuste]').forEach((r) => r.addEventListener('click', async () => {
                            const motivo = $('#cd-motivo-rev').value.trim();
                            if (! motivo) { $('[data-credito-error]').textContent = T.campos.motivo + ' *'; return; }
                            ctx = { enviar: null, clave: crypto.randomUUID() };
                            try { await post(`${R.ajustes}/${r.dataset.revAjuste}/reversar`, { motivo }); location.reload(); }
                            catch (err) { $('[data-credito-error]').textContent = err.message; }
                        }));
                        $('[data-credito-campos]').querySelectorAll('[data-rev]').forEach((r) => r.addEventListener('click', async () => {
                            const motivo = $('#cd-motivo-rev').value.trim();
                            if (! motivo) { $('[data-credito-error]').textContent = T.campos.motivo + ' *'; return; }
                            ctx = { enviar: null, clave: crypto.randomUUID() };
                            try { await post(`${R.reversar}/${r.dataset.rev}/reversar`, { motivo }); location.reload(); }
                            catch (err) { $('[data-credito-error]').textContent = err.message; }
                        }));
                    }
                });
            })();
        </script>
    @endpushOnce
</section>
