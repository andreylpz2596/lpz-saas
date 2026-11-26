{{--
    Doc. 35 · CUPO DE CARTERA del cliente. Todas las cifras salen de CupoCarteraClienteService (derivadas de Cartera; nada se guarda salvo
    la politica); aqui no se calcula nada. Los permisos se vuelven a validar en el servidor: los botones solo reflejan lo que el actor puede.
    Variables: $cupo (DataObjects\CupoCartera con detalle), $cupoPuedeEditar, $cupoPuedeHistorial, $cupoPuedeExceso, $cupoBloqueoActivo,
    $cupoVerDetalle, $cliente (array con id y nombre), $dinero (closure).
--}}
@php
    use ByLopez\VentasAsesor\DataObjects\CupoCartera;

    $tonoCupo = ['sin_cupo' => 'gris', 'activo' => 'verde', 'cerca' => 'amarillo', 'agotado' => 'rojo', 'excedido' => 'rojo', 'bloqueado' => 'rojo'][$cupo->estado] ?? 'gris';
    $porcentajeBarra = $cupo->utilizacionPct === null ? 0 : max(0, min(100, $cupo->utilizacionPct));
    $textosCupo = trans('ventasasesor::cupo');
    $rutasCupo = [
        'editar' => route('admin.ventas_asesor.cartera.cupo.editar', ['cliente' => $cliente['id']]),
        'historial' => route('admin.ventas_asesor.cartera.cupo.historial', ['cliente' => $cliente['id']]),
        'exceso' => route('admin.ventas_asesor.cartera.cupo.exceso', ['cliente' => $cliente['id']]),
        'excesoAnular' => url(config('app.admin_url').'/ventas-asesor/cartera/cupo/exceso'),
        'pedido' => url(config('app.admin_url').'/ventas-asesor/pedidos'),
    ];
    $datosCupo = [
        'cliente_id' => (int) $cliente['id'], 'nombre' => $cliente['nombre'] ?: ('#'.$cliente['id']),
        'autorizado' => $cupo->autorizado, 'tiene_politica' => $cupo->tienePolitica, 'estado_politica' => $cupo->estadoPolitica ?? 'activo',
        'exposicion' => $cupo->exposicion, 'bloqueado' => $cupo->bloqueado(), 'dias_exceso' => (int) config('ventasasesor.cupo.dias_vigencia_excepcion', 2),
    ];
    $iconoPunto = '<circle cx="12" cy="12" r="5"/>';
@endphp

@pushOnce('styles')
<style>
    .va-cupo { margin-top: 20px; }
    .va-cupo-caja { padding: 16px; }
    .va-cupo-caja--verde { border-color: #86efac; }
    .va-cupo-caja--amarillo { border-color: #fcd34d; background: #fffbeb; }
    .va-cupo-caja--rojo { border-color: #fda4af; background: #fff1f2; }
    .dark .va-cupo-caja--amarillo { background: #451a03; border-color: #92400e; }
    .dark .va-cupo-caja--rojo { background: #4c0519; border-color: #9f1239; }
    .va-cupo-enc { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: 8px 16px; }
    .va-cupo-titulo { margin: 0; font-size: 12px; font-weight: 800; letter-spacing: .06em; color: #475569; }
    .dark .va-cupo-titulo { color: #cbd5e1; }
    .va-cupo-alerta { margin: 12px 0 0; padding: 10px 12px; border-radius: 8px; font-size: 14px; font-weight: 700; }
    .va-cupo-alerta small { display: block; margin-top: 2px; font-size: 12px; font-weight: 500; }
    .va-cupo-alerta--rojo { background: #be123c; color: #fff; }
    .va-cupo-alerta--ambar { background: #fde68a; color: #78350f; }
    .va-cupo-alerta--gris { background: #e2e8f0; color: #334155; }
    .va-cupo-nota { margin: 10px 0 0; font-size: 12px; color: #92400e; }
    .dark .va-cupo-nota { color: #fcd34d; }
    .va-cupo-cifras { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px 16px; margin: 14px 0 0; }
    .va-cupo-cifras dt { font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: #64748b; }
    .va-cupo-cifras dd { margin: 2px 0 0; font-size: 20px; font-weight: 800; line-height: 1.2; color: #0f172a; word-break: break-word; }
    .dark .va-cupo-cifras dd { color: #fff; }
    @media (min-width: 768px) { .va-cupo-cifras { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
    .va-cupo-barra { height: 10px; margin-top: 14px; overflow: hidden; border-radius: 999px; background: #e2e8f0; }
    .dark .va-cupo-barra { background: #1f2937; }
    .va-cupo-barra > span { display: block; height: 100%; border-radius: 999px; background: #16a34a; }
    .va-cupo-barra--amarillo > span { background: #f59e0b; }
    .va-cupo-barra--rojo > span { background: #e11d48; }
    .va-cupo-meta { margin: 12px 0 0; font-size: 12px; color: #64748b; }
    .dark .va-cupo-meta { color: #9ca3af; }
    .va-cupo-acc { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; }
    .va-cupo-acc .va-btn { min-height: 40px; padding: 8px 14px; font-size: 13px; }
    .va-cupo-exceso { margin: 12px 0 0; padding: 8px 12px; border-radius: 8px; background: #e0e7ff; color: #3730a3; font-size: 13px; font-weight: 600; }
    .dark .va-cupo-exceso { background: #1e1b4b; color: #c7d2fe; }
    .va-cupo-lista { margin-top: 12px; overflow: hidden; }
    .va-cupo-cab { padding: 12px 16px; font-size: 14px; font-weight: 700; color: #1e293b; }
    .dark .va-cupo-cab { color: #fff; }
    .va-cupo-fila { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 16px; padding: 12px 16px; border-top: 1px solid #e2e8f0; }
    .dark .va-cupo-fila { border-color: #1f2937; }
    .va-cupo-fila-id { flex: 1 1 9rem; min-width: 0; }
    .va-cupo-fila-id a { font-weight: 800; color: #1d4ed8; text-decoration: underline; }
    .va-cupo-fila-cifras { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 4px 16px; flex: 3 1 18rem; margin: 0; font-size: 12px; }
    .va-cupo-fila-cifras dt { color: #64748b; }
    .va-cupo-fila-cifras dd { margin: 0; font-weight: 700; }
    .va-cupo-fila-cifras .va-cupo-expo { color: #9f1239; font-weight: 800; }
    .dark .va-cupo-fila-cifras .va-cupo-expo { color: #fda4af; }
    @media (min-width: 768px) { .va-cupo-fila-cifras { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
    .va-cupo-total { display: flex; justify-content: space-between; gap: 12px; padding: 12px 16px; border-top: 2px solid #cbd5e1; font-weight: 800; }
    .dark .va-cupo-total { border-color: #374151; }

    .va-cupo-dlg { width: calc(100vw - 32px); max-width: 36rem; max-height: calc(100dvh - 32px); overflow-y: auto; border: 1px solid #cbd5e1; border-radius: 12px; padding: 0; }
    .va-cupo-dlg::backdrop { background: rgba(15, 23, 42, .45); }
    .dark .va-cupo-dlg { background: #111827; border-color: #374151; color: #e5e7eb; }
    .va-cupo-form { padding: 16px; }
    .va-cupo-tit { margin: 0; font-size: 16px; font-weight: 700; color: #0f172a; }
    .dark .va-cupo-tit { color: #fff; }
    .va-cupo-ayuda { margin: 6px 0 0; font-size: 12px; color: #64748b; }
    .va-cupo-campos { display: grid; gap: 12px; margin-top: 12px; }
    .va-cupo-lbl { display: block; font-size: 12px; font-weight: 600; color: #475569; }
    .dark .va-cupo-lbl { color: #cbd5e1; }
    .va-cupo-in { display: block; width: 100%; box-sizing: border-box; margin-top: 4px; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 16px; background: #fff; color: #0f172a; }
    .va-cupo-in[readonly] { background: #f1f5f9; color: #475569; }
    .dark .va-cupo-in { background: #1f2937; color: #fff; border-color: #374151; }
    .dark .va-cupo-in[readonly] { background: #0b1220; color: #9ca3af; }
    .va-cupo-aviso { margin: 0; padding: 8px 10px; border-radius: 8px; background: #fef3c7; color: #92400e; font-size: 12px; font-weight: 600; }
    .va-cupo-aviso--rojo { background: #ffe4e6; color: #9f1239; }
    .va-cupo-err { margin: 12px 0 0; min-height: 1em; font-size: 14px; font-weight: 500; color: #9f1239; }
    .va-cupo-pie { display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; }
    .va-cupo-pie .va-btn { min-height: 42px; padding: 9px 16px; font-size: 14px; }
    .va-cupo-hist { display: grid; gap: 8px; margin: 12px 0 0; padding: 0; list-style: none; }
    .va-cupo-hist li { border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px 10px; font-size: 12px; }
    .dark .va-cupo-hist li { border-color: #374151; }
    .va-cupo-hist-cab { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 4px 8px; font-weight: 700; }
    .va-cupo-sub { margin: 14px 0 0; font-size: 13px; font-weight: 700; color: #334155; }
    .dark .va-cupo-sub { color: #e5e7eb; }
    @media (max-width: 479px) { .va-cupo-pie { flex-direction: column-reverse; } .va-cupo-pie .va-btn { width: 100%; } }
</style>
@endpushOnce

<section class="va-cupo" data-cupo-bloque data-cupo-estado="{{ $cupo->estado }}" aria-labelledby="cupo-titulo">
    <div class="va-panel va-cupo-caja va-cupo-caja--{{ $tonoCupo }}">
        <header class="va-cupo-enc">
            <div>
                <h2 class="va-cupo-titulo" id="cupo-titulo">@lang('ventasasesor::cupo.bloque.titulo')</h2>
                <p class="va-sub">@lang('ventasasesor::cupo.bloque.subtitulo')</p>
            </div>
            <span class="va-pill va-pill--{{ $tonoCupo }}" data-cupo-pill="{{ $cupo->estado }}">
                <svg viewBox="0 0 24 24" aria-hidden="true" style="fill: currentColor">{!! $iconoPunto !!}</svg>
                <span>@lang('ventasasesor::cupo.estados.'.$cupo->estado)</span>
            </span>
        </header>

        @if (! $cupoBloqueoActivo)
            <p class="va-cupo-nota" data-cupo-no-bloquea>@lang('ventasasesor::cupo.bloque.no_bloquea')</p>
        @endif

        @if (! $cupo->carteraActiva)
            <p class="va-cupo-alerta va-cupo-alerta--gris" role="status" data-cupo-cartera-inactiva>@lang('ventasasesor::cupo.bloque.cartera_inactiva')</p>
        @elseif ($cupo->estado === CupoCartera::BLOQUEADO)
            <p class="va-cupo-alerta va-cupo-alerta--rojo" role="alert" data-cupo-alerta="bloqueado">@lang('ventasasesor::cupo.bloque.bloqueado_aviso')</p>
        @elseif ($cupo->estado === CupoCartera::EXCEDIDO)
            <p class="va-cupo-alerta va-cupo-alerta--rojo" role="alert" data-cupo-alerta="excedido">
                @lang('ventasasesor::cupo.bloque.excedido_en', ['monto' => $dinero($cupo->exceso)])
                <small>@lang('ventasasesor::cupo.bloque.excedido_ayuda')</small>
            </p>
        @elseif ($cupo->estado === CupoCartera::AGOTADO)
            <p class="va-cupo-alerta va-cupo-alerta--rojo" role="alert" data-cupo-alerta="agotado">@lang('ventasasesor::cupo.bloque.agotado_aviso')</p>
        @elseif ($cupo->estado === CupoCartera::CERCA)
            <p class="va-cupo-alerta va-cupo-alerta--ambar" role="status" data-cupo-alerta="cerca">@lang('ventasasesor::cupo.bloque.cerca_aviso', ['pct' => rtrim(rtrim(number_format((float) $cupo->utilizacionPct, 1, ',', '.'), '0'), ',').' %'])</p>
        @elseif ($cupo->estado === CupoCartera::SIN_CUPO)
            <p class="va-cupo-alerta va-cupo-alerta--ambar" role="status" data-cupo-alerta="sin_cupo">@lang('ventasasesor::cupo.bloque.sin_cupo_aviso')</p>
        @endif

        <dl class="va-cupo-cifras">
            <div><dt>@lang('ventasasesor::cupo.bloque.autorizado')</dt><dd data-cupo-autorizado>{{ $dinero($cupo->autorizado) }}</dd></div>
            <div><dt>@lang('ventasasesor::cupo.bloque.exposicion')</dt><dd data-cupo-exposicion>{{ $cupo->carteraActiva ? $dinero($cupo->exposicion) : '—' }}</dd></div>
            <div><dt>@lang('ventasasesor::cupo.bloque.disponible')</dt><dd data-cupo-disponible>{{ $dinero($cupo->disponible) }}</dd></div>
            <div><dt>@lang('ventasasesor::cupo.bloque.utilizacion')</dt><dd data-cupo-utilizacion>{{ $cupo->utilizacionPct === null ? '—' : rtrim(rtrim(number_format($cupo->utilizacionPct, 1, ',', '.'), '0'), ',').' %' }}</dd></div>
        </dl>

        <div class="va-cupo-barra va-cupo-barra--{{ $tonoCupo }}" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ (int) round($porcentajeBarra) }}" aria-label="@lang('ventasasesor::cupo.bloque.utilizacion')">
            <span style="width: {{ $porcentajeBarra }}%"></span>
        </div>

        <p class="va-cupo-meta" data-cupo-meta>
            @if ($cupo->tienePolitica)
                @lang('ventasasesor::cupo.bloque.ultima_modificacion'): {{ \Illuminate\Support\Carbon::parse($cupo->actualizadoEn)->format('d/m/Y H:i') }}
                @if ($cupo->actualizadoPorNombre) &middot; @lang('ventasasesor::cupo.bloque.por', ['nombre' => $cupo->actualizadoPorNombre]) @endif
                @if ($cupo->motivo) &middot; «{{ $cupo->motivo }}» @endif
            @else
                @lang('ventasasesor::cupo.bloque.nunca')
            @endif
            @if ($cupo->diasPedidoMasAntiguo !== null)
                <br>@lang('ventasasesor::cupo.bloque.mas_antiguo', ['n' => $cupo->diasPedidoMasAntiguo])
            @endif
        </p>

        @if ($cupo->excesoVigente !== null)
            <p class="va-cupo-exceso" data-cupo-exceso-vigente>
                @lang('ventasasesor::cupo.bloque.exceso_vigente', ['monto' => $dinero($cupo->excesoVigente['exceso_autorizado']), 'fecha' => \Illuminate\Support\Carbon::parse($cupo->excesoVigente['vence_en'])->format('d/m/Y H:i')])
            </p>
        @endif

        @if ($cupoPuedeEditar || $cupoPuedeExceso || $cupoPuedeHistorial)
            <div class="va-cupo-acc">
                @if ($cupoPuedeEditar)
                    <button type="button" class="va-btn va-btn--ok" data-cupo-accion="editar">@lang('ventasasesor::cupo.bloque.editar')</button>
                @endif
                @if ($cupoPuedeExceso)
                    <button type="button" class="va-btn" data-cupo-accion="exceso">@lang('ventasasesor::cupo.bloque.autorizar_exceso')</button>
                @endif
                @if ($cupoPuedeHistorial)
                    <button type="button" class="va-btn" data-cupo-accion="historial">@lang('ventasasesor::cupo.bloque.historial')</button>
                @endif
            </div>
        @endif
    </div>

    @if ($cupoVerDetalle && $cupo->carteraActiva)
        <div class="va-panel va-cupo-lista" data-cupo-detalle>
            <div class="va-cupo-cab">@lang('ventasasesor::cupo.bloque.pedidos_titulo')</div>

            @forelse ($cupo->pedidos ?? [] as $p)
                <div class="va-cupo-fila" data-cupo-pedido="{{ $p['order_id'] }}">
                    <div class="va-cupo-fila-id">
                        <a href="{{ route('admin.ventas_asesor.pedidos.index', ['pedido' => $p['order_id']]) }}">#{{ $p['increment_id'] }}</a>
                        <p class="va-sub">
                            {{ \Illuminate\Support\Carbon::parse($p['creado'])->format('d/m/Y') }}
                            &middot; {{ $p['dias'] > 0 ? trans('ventasasesor::cupo.bloque.dias', ['n' => $p['dias']]) : trans('ventasasesor::cupo.bloque.hoy') }}
                        </p>
                        @if ($p['requiere_revision'])
                            <span class="va-pill va-pill--indigo va-pill--sm">@lang('ventasasesor::cupo.bloque.requiere_revision')</span>
                        @endif
                    </div>
                    <dl class="va-cupo-fila-cifras">
                        <div><dt>@lang('ventasasesor::cupo.bloque.total_operativo')</dt><dd>{{ $dinero($p['total_operativo']) }}</dd></div>
                        <div><dt>@lang('ventasasesor::cupo.bloque.verificado')</dt><dd>{{ $dinero($p['verificado']) }}</dd></div>
                        <div><dt>@lang('ventasasesor::cupo.bloque.sin_revisar')</dt><dd>{{ $dinero($p['sin_revisar']) }}</dd></div>
                        <div><dt>@lang('ventasasesor::cupo.bloque.exposicion_pedido')</dt><dd class="va-cupo-expo">{{ $dinero($p['exposicion']) }}</dd></div>
                    </dl>
                </div>
            @empty
                <p class="va-cupo-fila va-sub" data-cupo-sin-pedidos>@lang('ventasasesor::cupo.bloque.sin_pedidos')</p>
            @endforelse

            @if (($cupo->pedidos ?? []) !== [])
                <div class="va-cupo-total" data-cupo-total><span>@lang('ventasasesor::cupo.bloque.total')</span><span>{{ $dinero($cupo->exposicion) }}</span></div>
            @endif
        </div>
    @endif

    @if ($cupoPuedeEditar || $cupoPuedeExceso || $cupoPuedeHistorial)
        <dialog data-cupo-dialogo class="va-cupo-dlg">
            <form method="dialog" class="va-cupo-form" data-cupo-form>
                <h2 class="va-cupo-tit" data-cupo-titulo></h2>
                <p class="va-cupo-ayuda" data-cupo-ayuda></p>
                <div class="va-cupo-campos" data-cupo-campos></div>
                <p class="va-cupo-err" data-cupo-error role="alert"></p>
                <div class="va-cupo-pie">
                    <button type="button" class="va-btn" data-cupo-cerrar>@lang('ventasasesor::cupo.modal.cancelar')</button>
                    <button type="submit" class="va-btn va-btn--ok" data-cupo-enviar>@lang('ventasasesor::cupo.modal.guardar')</button>
                </div>
            </form>
        </dialog>

        @pushOnce('scripts')
            <script>
                (() => {
                    const T = @json($textosCupo);
                    const R = @json($rutasCupo);
                    const D = @json($datosCupo);
                    const PUEDE_EXCESO = @json($cupoPuedeExceso);
                    const XSRF = () => decodeURIComponent((document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN=')) ?? '').split('=').slice(1).join('='));
                    // Bagisto recompila este DOM al montar Vue y reemplaza los nodos: todo es delegado y el dialogo se busca al usarlo.
                    const dlgEl = () => document.querySelector('[data-cupo-dialogo]');
                    if (! dlgEl()) return;
                    const $ = (s) => dlgEl().querySelector(s);
                    const dinero = (v) => '$ ' + Number(v).toLocaleString('es-CO', { maximumFractionDigits: 0 });
                    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
                    const digitos = (s) => String(s ?? '').replace(/\D/g, '');
                    const campo = (id, etiqueta, html) => `<label class="va-cupo-lbl" for="${id}">${etiqueta}${html}</label>`;
                    let ctx = null;

                    const abrir = (titulo, ayuda, campos, enviar, textoEnviar) => {
                        $('[data-cupo-titulo]').textContent = titulo;
                        $('[data-cupo-ayuda]').textContent = ayuda || '';
                        $('[data-cupo-campos]').innerHTML = campos;
                        $('[data-cupo-error]').textContent = '';
                        $('[data-cupo-enviar]').style.display = enviar ? '' : 'none';
                        $('[data-cupo-enviar]').textContent = textoEnviar || T.modal.guardar;
                        $('[data-cupo-enviar]').disabled = false;
                        $('[data-cupo-cerrar]').textContent = enviar ? T.modal.cancelar : T.modal.cerrar;
                        ctx = { enviar };
                        dlgEl().showModal();
                    };

                    const llamar = async (url, metodo, datos) => {
                        const r = await fetch(url, {
                            method: metodo,
                            headers: { 'Accept': 'application/json', 'X-XSRF-TOKEN': XSRF(), ...(datos ? { 'Content-Type': 'application/json' } : {}) },
                            body: datos ? JSON.stringify(datos) : undefined,
                        });
                        const j = await r.json().catch(() => ({}));
                        if (! r.ok) throw new Error(j.mensaje || (j.errors ? Object.values(j.errors).flat().join(' ') : '') || j.message || T.modal.error_generico);
                        return j;
                    };

                    document.addEventListener('click', (e) => { if (e.target.closest('[data-cupo-cerrar]')) dlgEl()?.close(); });
                    document.addEventListener('input', (e) => {
                        const el = e.target.closest('[data-cupo-monto]');
                        if (! el) return;
                        const d = digitos(el.value);
                        el.value = d ? Number(d).toLocaleString('es-CO') : '';
                        el.dispatchEvent(new CustomEvent('cupo-monto', { bubbles: true }));
                    });
                    document.addEventListener('submit', async (e) => {
                        if (! e.target.matches('[data-cupo-form]')) return;
                        e.preventDefault();
                        if (! ctx?.enviar) return;
                        const boton = $('[data-cupo-enviar]');
                        boton.disabled = true;
                        $('[data-cupo-error]').textContent = '';
                        try { await ctx.enviar(); location.reload(); }
                        catch (err) { $('[data-cupo-error]').textContent = err.message; boton.disabled = false; }
                    });

                    const estadoOpciones = (actual) => ['activo', 'bloqueado'].map((k) => `<option value="${k}" ${k === actual ? 'selected' : ''}>${T.modal[k]}</option>`).join('');

                    const editar = () => {
                        abrir(`${T.modal.editar_titulo} · ${esc(D.nombre)}`, T.modal.editar_ayuda,
                            campo('cupo-actual', T.modal.cupo_actual, `<input id="cupo-actual" class="va-cupo-in" readonly value="${D.tiene_politica ? dinero(D.autorizado) : T.modal.sin_valor}">`)
                            + campo('cupo-nuevo', T.modal.nuevo_cupo, `<input id="cupo-nuevo" class="va-cupo-in" data-cupo-monto inputmode="numeric" autocomplete="off" required value="${D.tiene_politica ? Number(D.autorizado).toLocaleString('es-CO', { maximumFractionDigits: 0 }) : ''}">`)
                            + campo('cupo-estado', T.modal.estado, `<select id="cupo-estado" class="va-cupo-in">${estadoOpciones(D.estado_politica)}</select>`)
                            + campo('cupo-motivo', T.modal.motivo, `<textarea id="cupo-motivo" class="va-cupo-in" rows="2" maxlength="500" required></textarea>`)
                            + '<p class="va-cupo-aviso" data-cupo-aviso hidden></p>',
                            async () => {
                                const nuevo = digitos($('#cupo-nuevo').value);
                                if (nuevo === '') throw new Error(T.modal.nuevo_cupo + ' *');
                                if (! $('#cupo-motivo').value.trim()) throw new Error(T.modal.motivo + ' *');
                                await llamar(R.editar, 'POST', {
                                    cupo: nuevo, estado: $('#cupo-estado').value, motivo: $('#cupo-motivo').value.trim(),
                                    cupo_esperado: String(D.autorizado), estado_esperado: D.estado_politica,
                                });
                            });

                        const avisar = () => {
                            const nuevo = Number(digitos($('#cupo-nuevo').value) || 0);
                            const textos = [];
                            if ($('#cupo-estado').value === 'bloqueado') textos.push(T.modal.aviso_bloqueo);
                            if (D.exposicion > 0 && nuevo < D.exposicion) textos.push(T.modal.aviso_menor_deuda.replace(':exposicion', dinero(D.exposicion)));
                            const aviso = $('[data-cupo-aviso]');
                            aviso.textContent = textos.join(' ');
                            aviso.hidden = textos.length === 0;
                        };
                        dlgEl().addEventListener('cupo-monto', avisar);
                        $('#cupo-estado').addEventListener('change', avisar);
                        avisar();
                    };

                    const exceso = () => {
                        if (D.bloqueado) {
                            abrir(T.modal.exceso_titulo, '', `<p class="va-cupo-aviso va-cupo-aviso--rojo">${T.modal.exceso_bloqueado}</p>`, null);
                            return;
                        }
                        abrir(`${T.modal.exceso_titulo} · ${esc(D.nombre)}`, T.modal.exceso_ayuda.replace(':dias', D.dias_exceso),
                            campo('cupo-exceso', T.modal.exceso_monto, `<input id="cupo-exceso" class="va-cupo-in" data-cupo-monto inputmode="numeric" autocomplete="off" required>`)
                            + campo('cupo-exceso-motivo', T.modal.motivo, `<textarea id="cupo-exceso-motivo" class="va-cupo-in" rows="2" maxlength="500" required></textarea>`),
                            async () => {
                                const monto = digitos($('#cupo-exceso').value);
                                if (monto === '' || Number(monto) <= 0) throw new Error(T.modal.exceso_monto + ' *');
                                if (! $('#cupo-exceso-motivo').value.trim()) throw new Error(T.modal.motivo + ' *');
                                await llamar(R.exceso, 'POST', { exceso: monto, motivo: $('#cupo-exceso-motivo').value.trim() });
                            }, T.bloque.autorizar_exceso);
                    };

                    const historial = async () => {
                        abrir(`${T.modal.historial_titulo} · ${esc(D.nombre)}`, '', '<p class="va-cupo-ayuda">…</p>', null);
                        let j;
                        try { j = await llamar(R.historial, 'GET'); }
                        catch (err) { $('[data-cupo-campos]').innerHTML = `<p class="va-cupo-aviso va-cupo-aviso--rojo">${esc(err.message)}</p>`; return; }

                        const cambios = (j.historial ?? []).map((h) => {
                            const cupo = h.cupo_anterior === null ? `${dinero(h.cupo_nuevo)} (${T.modal.primera_vez})` : (h.cupo_anterior === h.cupo_nuevo ? dinero(h.cupo_nuevo) : `${dinero(h.cupo_anterior)} → ${dinero(h.cupo_nuevo)}`);
                            const estado = h.estado_anterior === null || h.estado_anterior === h.estado_nuevo ? T.modal[h.estado_nuevo] : `${T.modal[h.estado_anterior]} → ${T.modal[h.estado_nuevo]}`;

                            return `<li><div class="va-cupo-hist-cab"><span>${T.modal.acciones_estados[h.accion] ?? esc(h.accion)}</span><span>${esc(h.fecha)}</span></div>`
                                + `<div>${T.modal.cupo_cambio}: <strong>${cupo}</strong> · ${T.modal.estado_cambio}: <strong>${estado}</strong></div>`
                                + `<div class="va-sub">${esc(h.actor ?? '')} · ${T.modal.exposicion_al_cambiar}: ${h.exposicion_al_cambiar === null ? '—' : dinero(h.exposicion_al_cambiar)}</div>`
                                + `<div>${esc(h.motivo)}</div></li>`;
                        }).join('') || `<li class="va-sub">${T.modal.sin_historial}</li>`;

                        const excesos = (j.excesos ?? []).map((x) => {
                            const pedido = x.order_id ? ` · ${T.modal.pedido}: <a href="${R.pedido}?pedido=${x.order_id}" class="va-cr-link" style="color:#1d4ed8;text-decoration:underline">#${esc(x.increment_id)}</a>` : '';
                            const uso = x.estado === 'consumida' ? `<div class="va-sub">${T.modal.exposicion_antes}: ${dinero(x.exposicion_antes ?? 0)} · ${T.modal.exceso_consumido}: ${dinero(x.exceso_consumido ?? 0)}${pedido}</div>` : '';
                            const anul = x.estado === 'anulada' ? `<div class="va-sub">${T.modal.anulada_por}: ${esc(x.anulada_motivo ?? '')} (${esc(x.anulada_en ?? '')})</div>` : '';
                            const boton = x.estado === 'vigente' && PUEDE_EXCESO
                                ? `<div style="margin-top:6px"><input class="va-cupo-in" data-cupo-anular-motivo="${x.id}" maxlength="500" placeholder="${T.modal.motivo_anular}" style="font-size:13px;padding:6px 8px"> <button type="button" class="va-btn va-btn--no" data-cupo-anular="${x.id}" style="margin-top:6px">${T.bloque.anular}</button></div>` : '';

                            return `<li data-cupo-exceso-fila="${x.id}"><div class="va-cupo-hist-cab"><span>${dinero(x.exceso_autorizado)} · ${T.modal.exceso_estados[x.estado] ?? esc(x.estado)}</span><span>${esc(x.creada ?? '')}</span></div>`
                                + `<div class="va-sub">${esc(x.autorizado_por ?? '')} · ${T.modal.vence}: ${esc(x.vence_en ?? '')}</div><div>${esc(x.motivo)}</div>${uso}${anul}${boton}</li>`;
                        }).join('') || `<li class="va-sub">${T.modal.sin_historial}</li>`;

                        $('[data-cupo-campos]').innerHTML = `<p class="va-cupo-sub">${T.modal.cambios}</p><ul class="va-cupo-hist">${cambios}</ul><p class="va-cupo-sub">${T.modal.excesos}</p><ul class="va-cupo-hist">${excesos}</ul>`;
                    };

                    document.addEventListener('click', async (ev) => {
                        const accion = ev.target.closest('[data-cupo-accion]')?.dataset.cupoAccion;
                        if (accion === 'editar') editar();
                        else if (accion === 'exceso') exceso();
                        else if (accion === 'historial') historial();

                        const anular = ev.target.closest('[data-cupo-anular]');
                        if (anular) {
                            const id = anular.dataset.cupoAnular;
                            const motivo = dlgEl().querySelector(`[data-cupo-anular-motivo="${id}"]`)?.value.trim();
                            if (! motivo) { $('[data-cupo-error]').textContent = T.modal.motivo_anular + ' *'; return; }
                            try { await llamar(`${R.excesoAnular}/${id}/anular`, 'POST', { motivo }); location.reload(); }
                            catch (err) { $('[data-cupo-error]').textContent = err.message; }
                        }
                    });
                })();
            </script>
        @endpushOnce
    @endif
</section>
