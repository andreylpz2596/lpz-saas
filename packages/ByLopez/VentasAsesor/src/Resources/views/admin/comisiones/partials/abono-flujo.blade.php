{{--
    «Registrar abono» con lectura asistida del comprobante. Flujo: subir comprobante → leer → REVISAR → CONFIRMAR → registrar.
    Cargar y leer NO registran ni aprueban el pago: el abono solo existe cuando administración pulsa «Confirmar y registrar abono».
    Sin comprobante (o si la lectura falla) el formulario se diligencia a mano, igual que siempre.
    Espera: $l (liquidación), $f (fila de cuenta con `pagable`), $clave, $hoy, $dinero.
--}}
@php
    $tx = fn (string $k) => trans('ventasasesor::comisiones.abono.'.$k);
    $textosAb = [
        'leyendo' => $tx('leyendo'), 'cargar' => $tx('cargar'), 'sin_lectura' => $tx('sin-lectura'), 'no_detectado' => $tx('no-detectado'), 'dudoso' => $tx('dudoso'),
        'archivo_repetido' => $tx('archivo-repetido'), 'duplicado' => $tx('duplicado'), 'posible' => $tx('posible'), 'destinatario' => $tx('destinatario'),
        'error' => $tx('error'), 'coincidencia' => $tx('coincidencia'), 'aviso' => $tx('aviso-lectura'),
    ];
@endphp

<form method="POST" action="{{ route('admin.ventas_asesor.comisiones.abonos.store', $l->id) }}" class="va-abono" data-form="abono" data-abono-flujo
      data-url-leer="{{ route('admin.ventas_asesor.comisiones.soportes.leer', $l->id) }}" data-token="{{ csrf_token() }}" data-pagable="{{ $f['pagable'] }}"
      data-textos='@json($textosAb)'>
    @csrf
    <input type="hidden" name="clave" value="abono:{{ $l->id }}:{{ $clave }}">
    <input type="hidden" name="soporte_id" value="" data-campo="soporte_id">
    <input type="hidden" name="confirmar_misma_operacion" value="0" data-campo="confirmar_misma_operacion">

    <p class="va-abono__titulo">@lang('ventasasesor::comisiones.abono.titulo')</p>

    <div class="va-abono__paso">
        <p class="va-abono__rot">@lang('ventasasesor::comisiones.abono.paso-1')</p>
        <div class="va-abono__subida">
            <input type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" data-archivo aria-label="@lang('ventasasesor::comisiones.comprobante')">
            <span class="va-sub" data-estado-lectura role="status" aria-live="polite"></span>
        </div>
        <p class="va-sub">@lang('ventasasesor::comisiones.abono.aviso-lectura')</p>
        <div data-avisos-lectura></div>
    </div>

    <div class="va-abono__cuerpo">
        <div class="va-abono__datos">
            <p class="va-abono__rot">@lang('ventasasesor::comisiones.abono.paso-2')</p>
            <div class="va-abono__grid">
                <div data-grupo="valor">
                    <label>@lang('ventasasesor::comisiones.abono.valor') <span class="va-sub">(≤ {{ $dinero($f['pagable']) }})</span></label>
                    <input type="number" name="monto" min="0.01" step="0.01" max="{{ $f['pagable'] }}" required data-campo="monto">
                    <span class="va-abono__nota" data-nota="valor"></span>
                </div>
                <div data-grupo="valor-operacion">
                    <label>@lang('ventasasesor::comisiones.abono.valor-operacion')</label>
                    <input type="number" name="operacion_valor" min="0.01" step="0.01" data-campo="operacion_valor">
                    <span class="va-sub">@lang('ventasasesor::comisiones.abono.valor-operacion-ayuda')</span>
                </div>
                <div data-grupo="fecha">
                    <label>@lang('ventasasesor::comisiones.abono.fecha')</label>
                    <input type="date" name="fecha_pago" value="{{ $hoy }}" required data-campo="fecha_pago">
                    <span class="va-abono__nota" data-nota="fecha"></span>
                </div>
                <div data-grupo="medio">
                    <label>@lang('ventasasesor::comisiones.abono.medio')</label>
                    <input type="text" name="medio" maxlength="40" data-campo="medio">
                    <span class="va-abono__nota" data-nota="medio"></span>
                </div>
                <div data-grupo="referencia">
                    <label>@lang('ventasasesor::comisiones.abono.referencia')</label>
                    <input type="text" name="referencia" maxlength="120" data-campo="referencia">
                    <span class="va-abono__nota" data-nota="referencia"></span>
                </div>
                <div data-grupo="observacion">
                    <label>@lang('ventasasesor::comisiones.abono.observacion')</label>
                    <input type="text" name="observacion" maxlength="500" data-campo="observacion">
                </div>
            </div>

            <p class="va-sub" data-destinatario hidden></p>

            <label class="va-abono__confirmar" data-bloque-misma hidden>
                <input type="checkbox" data-check-misma> @lang('ventasasesor::comisiones.abono.misma-operacion')
            </label>

            <p class="va-abono__rot">@lang('ventasasesor::comisiones.abono.paso-3')</p>
            <p class="va-sub">@lang('ventasasesor::comisiones.abono.revisar-antes')</p>
            <button type="submit" class="primary-button" data-registrar>@lang('ventasasesor::comisiones.abono.registrar')</button>
        </div>

        {{-- El soporte, al lado del formulario, para revisarlo mientras se confirman los datos. --}}
        <div class="va-abono__soporte" data-soporte hidden>
            <p class="va-abono__rot">@lang('ventasasesor::comisiones.abono.archivo')</p>
            <div data-visor></div>
        </div>
    </div>
</form>

@once
    @push('styles')
        <style>
            .va-abono { margin-top: 14px; padding: 14px; border: 1px solid #e2e8f0; border-radius: 10px; background: #f8fafc; }
            .dark .va-abono { border-color: #1f2937; background: #0b1220; }
            .va-abono__titulo { margin: 0 0 6px; font-size: 15px; font-weight: 700; }
            .va-abono__rot { margin: 10px 0 4px; font-size: 11px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: #64748b; }
            .dark .va-abono__rot { color: #9ca3af; }
            .va-abono__subida { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px; }
            .va-abono__cuerpo { display: grid; grid-template-columns: minmax(0, 1fr); gap: 14px; }
            @media (min-width: 1024px) { .va-abono__cuerpo.va-abono__cuerpo--con-soporte { grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr); } }
            .va-abono__grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 10px 12px; }
            .va-abono__grid label { display: block; margin-bottom: 3px; font-size: 11px; font-weight: 600; letter-spacing: .03em; text-transform: uppercase; color: #64748b; }
            .dark .va-abono__grid label { color: #9ca3af; }
            .va-abono__grid input { width: 100%; min-height: 40px; padding: 6px 10px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; color: #334155; background: #fff; }
            .dark .va-abono__grid input { border-color: #374151; color: #d1d5db; background: #111827; }
            .va-abono__grid .va-dudoso input { border-color: #f59e0b; box-shadow: 0 0 0 2px rgba(245, 158, 11, .25); }
            .va-abono__nota { display: block; margin-top: 3px; font-size: 12px; color: #92400e; }
            .dark .va-abono__nota { color: #fcd34d; }
            .va-abono__confirmar { display: flex; align-items: flex-start; gap: 8px; margin-top: 10px; font-size: 13px; }
            .va-abono__soporte img { display: block; max-width: 100%; max-height: 460px; border: 1px solid #e2e8f0; border-radius: 8px; }
            .va-abono__soporte iframe { width: 100%; height: 460px; border: 1px solid #e2e8f0; border-radius: 8px; background: #fff; }
            .va-abono .va-aviso { margin-top: 8px; }
        </style>
    @endpush

    @pushOnce('scripts')
        <script>
            (function () {
                function iniciar(form) {
                    var T = JSON.parse(form.getAttribute('data-textos'));
                    var url = form.getAttribute('data-url-leer');
                    var token = form.getAttribute('data-token');
                    var cuerpo = form.querySelector('.va-abono__cuerpo');
                    var $ = function (sel) { return form.querySelector(sel); };
                    var campo = function (n) { return form.querySelector('[data-campo=' + n + ']'); };
                    var estado = $('[data-estado-lectura]');
                    var avisos = $('[data-avisos-lectura]');

                    function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
                    function aviso(html) { var d = document.createElement('div'); d.className = 'va-aviso'; d.innerHTML = html; avisos.appendChild(d); }
                    function marca(grupo, texto) {
                        var g = $('[data-grupo=' + grupo + ']'); if (!g) { return; }
                        g.classList.add('va-dudoso');
                        var n = g.querySelector('[data-nota]'); if (n) { n.textContent = texto; }
                    }
                    function limpiar() {
                        avisos.innerHTML = '';
                        form.querySelectorAll('.va-dudoso').forEach(function (g) { g.classList.remove('va-dudoso'); });
                        form.querySelectorAll('[data-nota]').forEach(function (n) { n.textContent = ''; });
                        $('[data-bloque-misma]').hidden = true; campo('confirmar_misma_operacion').value = '0';
                        var ch = $('[data-check-misma]'); if (ch) { ch.checked = false; }
                    }

                    $('[data-check-misma]').addEventListener('change', function (e) { campo('confirmar_misma_operacion').value = e.target.checked ? '1' : '0'; });

                    $('[data-archivo]').addEventListener('change', function (e) {
                        var archivo = e.target.files[0];
                        if (!archivo) { return; }
                        limpiar();
                        estado.textContent = T.leyendo;
                        var datos = new FormData(); datos.append('comprobante', archivo); datos.append('_token', token);

                        fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': token }, body: datos })
                            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
                            .then(function (x) {
                                var j = x.j;
                                if (!x.ok) { estado.textContent = ''; aviso(esc(j.error || T.error)); return; }
                                estado.textContent = '';
                                campo('soporte_id').value = j.soporte_id;
                                mostrarSoporte(j);
                                rellenar(j);
                            }).catch(function () { estado.textContent = ''; aviso(esc(T.error)); });
                    });

                    function mostrarSoporte(j) {
                        var visor = $('[data-visor]'); visor.innerHTML = '';
                        var el = j.mime === 'application/pdf' ? document.createElement('iframe') : document.createElement('img');
                        el.src = j.url; el.alt = j.nombre; el.title = j.nombre;
                        visor.appendChild(el);
                        $('[data-soporte]').hidden = false;
                        cuerpo.classList.add('va-abono__cuerpo--con-soporte');
                    }

                    function rellenar(j) {
                        var c = (j.lectura && j.lectura.campos) || {};
                        var pagable = parseFloat(form.getAttribute('data-pagable')) || 0;
                        var dudosos = ((j.lectura && j.lectura.advertencias) || []).join(' ').toLowerCase();

                        if (j.lectura.estado !== 'leida' || !Object.keys(c).length) { aviso(esc(j.lectura.mensaje || T.sin_lectura)); }

                        if (c.valor) {
                            var v = parseFloat(c.valor);
                            campo('operacion_valor').value = v;
                            campo('monto').value = Math.min(v, pagable);
                            if (v > pagable) { marca('valor', 'El comprobante dice ' + v.toLocaleString('es-CO') + ' pero hoy solo se puede pagar ' + pagable.toLocaleString('es-CO') + '.'); }
                        } else { marca('valor', T.no_detectado); }
                        if (c.fecha) { campo('fecha_pago').value = c.fecha; } else { marca('fecha', T.no_detectado); }
                        if (c.medio) { campo('medio').value = c.medio; } else { marca('medio', T.no_detectado); }
                        if (c.referencia) { campo('referencia').value = c.referencia; } else { marca('referencia', T.no_detectado); }
                        if (c.referencia && /referencia|autorizaci/.test(dudosos)) { marca('referencia', T.dudoso); }
                        if (/valor/.test(dudosos)) { marca('valor', T.dudoso); }
                        if (/fecha/.test(dudosos)) { marca('fecha', T.dudoso); }

                        var d = $('[data-destinatario]');
                        if (c.destinatario) { d.hidden = false; d.textContent = T.destinatario + ': ' + c.destinatario; } else { d.hidden = true; }

                        if (j.ya_cargado) { aviso(esc(T.archivo_repetido)); }

                        var dup = j.duplicados || {};
                        var lista = function (arr) { return arr.map(function (x) { return T.coincidencia.replace(':abono', x.abono).replace(':fecha', x.fecha).replace(':valor', Number(x.valor).toLocaleString('es-CO')); }).join('<br>'); };
                        if (dup.estado === 'duplicado') {
                            aviso('<strong>' + esc(T.duplicado) + '</strong><br>' + lista(dup.coincidencias || []));
                            $('[data-bloque-misma]').hidden = false;
                        } else if (dup.estado === 'posible_duplicado') {
                            aviso(esc(T.posible) + '<br>' + lista(dup.coincidencias || []));
                            $('[data-bloque-misma]').hidden = false;
                        }
                        if ((dup.archivo_repetido || []).length) {
                            aviso(esc(T.archivo_repetido) + '<br>' + lista(dup.archivo_repetido));
                            $('[data-bloque-misma]').hidden = false;
                        }
                    }

                    // Doble clic: el boton se bloquea al enviar (el servidor ademas es idempotente por clave y por operacion).
                    form.addEventListener('submit', function () { var b = $('[data-registrar]'); setTimeout(function () { b.disabled = true; }, 0); });
                }

                document.querySelectorAll('[data-abono-flujo]').forEach(iniciar);
            })();
        </script>
    @endPushOnce
@endonce
