{{--
    «Simular próxima venta»: función secundaria, desplegable y cerrada de entrada. El cálculo lo hace el servidor
    (POST admin.ventas_asesor.comisiones.simular): no crea pedidos, no reserva stock y no guarda nada. El resultado se rotula SIMULACIÓN.
--}}
@php
    $dineroSim = fn ($v) => \ByLopez\Logistica\Support\Moneda::formatear((float) $v);
    $textos = [
        'resultado' => trans('ventasasesor::comisiones.simulador.resultado'),
        'resultado_cero' => trans('ventasasesor::comisiones.simulador.resultado-cero'),
        'no_calculable' => trans('ventasasesor::comisiones.simulador.no-calculable'),
        'calculando' => trans('ventasasesor::comisiones.simulador.calculando'),
        'simular' => trans('ventasasesor::comisiones.simulador.simular'),
        'error' => trans('ventasasesor::comisiones.simulador.error'),
        'limitaciones' => array_merge(
            (array) trans('ventasasesor::comisiones.motivos'),
            (array) trans('ventasasesor::comisiones.simulador.limitaciones'),
        ),
    ];
@endphp

<details class="va-simula" data-simulador data-url="{{ route('admin.ventas_asesor.comisiones.simular') }}" data-token="{{ csrf_token() }}" data-textos='@json($textos)'>
    <summary>@lang('ventasasesor::comisiones.simulador.titulo')</summary>
    <p class="va-avance__nota">@lang('ventasasesor::comisiones.simulador.ayuda')</p>

    <form class="va-simula__form" novalidate>
        <div data-filas>
            <div class="va-simula__fila" data-fila>
                <div><label>@lang('ventasasesor::comisiones.simulador.sku')</label><input type="text" name="sku" maxlength="100" autocomplete="off"></div>
                <div><label>@lang('ventasasesor::comisiones.simulador.cantidad')</label><input type="number" name="cantidad" min="1" step="1" inputmode="numeric"></div>
                <div><label>@lang('ventasasesor::comisiones.simulador.precio')</label><input type="number" name="precio_neto" min="0.01" step="0.01" inputmode="decimal"></div>
                <button type="button" class="transparent-button" data-quitar aria-label="@lang('ventasasesor::comisiones.simulador.quitar')">✕</button>
            </div>
        </div>
        <div class="va-simula__acciones">
            <button type="button" class="transparent-button" data-agregar>@lang('ventasasesor::comisiones.simulador.agregar')</button>
            <button type="submit" class="secondary-button" data-simular>@lang('ventasasesor::comisiones.simulador.simular')</button>
        </div>
    </form>

    <div class="va-simula__resultado" data-resultado hidden role="status" aria-live="polite"></div>
</details>

@once
    @push('styles')
        <style>
            .va-simula { margin-top: 14px; padding-top: 10px; border-top: 1px dashed #cbd5e1; }
            .dark .va-simula { border-color: #374151; }
            .va-simula > summary { display: inline-flex; align-items: center; min-height: 36px; cursor: pointer; font-size: 14px; font-weight: 600; color: #2563eb; }
            .dark .va-simula > summary { color: #93c5fd; }
            .va-simula__fila { display: grid; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr) minmax(0, 1.2fr) auto; align-items: end; gap: 8px; margin-top: 8px; }
            .va-simula__fila label { display: block; margin-bottom: 3px; font-size: 11px; font-weight: 600; letter-spacing: .03em; text-transform: uppercase; color: #64748b; }
            .dark .va-simula__fila label { color: #9ca3af; }
            .va-simula__fila input { width: 100%; min-height: 40px; padding: 6px 10px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; color: #334155; background: #fff; }
            .dark .va-simula__fila input { border-color: #1f2937; color: #d1d5db; background: #111827; }
            .va-simula__acciones { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 8px; margin-top: 10px; }
            .va-simula__resultado { margin-top: 12px; padding: 12px 14px; border: 1px solid #bfdbfe; border-radius: 10px; background: #eff6ff; color: #1e3a8a; font-size: 14px; line-height: 1.5; }
            .dark .va-simula__resultado { border-color: #1e3a8a; background: #0b1730; color: #dbeafe; }
            .va-simula__resultado--limite { border-color: #fcd34d; background: #fffbeb; color: #92400e; }
            .dark .va-simula__resultado--limite { border-color: #78350f; background: #1c1308; color: #fcd34d; }
            .va-simula__etiqueta { display: inline-block; margin-bottom: 4px; padding: 1px 8px; border-radius: 999px; background: #1d4ed8; color: #fff; font-size: 11px; font-weight: 700; letter-spacing: .05em; }
            @media (max-width: 640px) { .va-simula__fila { grid-template-columns: 1fr 1fr; } .va-simula__fila > div:first-child { grid-column: 1 / -1; } }
        </style>
    @endpush

    @pushOnce('scripts')
        <script>
            (function () {
                var raiz = document.querySelector('[data-simulador]');
                if (!raiz) { return; }
                var textos = JSON.parse(raiz.getAttribute('data-textos'));
                var url = raiz.getAttribute('data-url');
                var filas = raiz.querySelector('[data-filas]');
                var resultado = raiz.querySelector('[data-resultado]');
                var plantilla = filas.querySelector('[data-fila]').cloneNode(true);
                var etiqueta = {!! json_encode(trans('ventasasesor::comisiones.simulador.etiqueta')) !!};
                var nota = {!! json_encode(trans('ventasasesor::comisiones.simulador.nota')) !!};

                function moneda(v) {
                    return '$ ' + Number(v).toLocaleString('es-CO', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
                }

                function mostrar(html, limite) {
                    resultado.hidden = false;
                    resultado.className = 'va-simula__resultado' + (limite ? ' va-simula__resultado--limite' : '');
                    resultado.innerHTML = '<span class="va-simula__etiqueta">' + etiqueta + '</span><div>' + html + '</div><p class="va-avance__nota">' + nota + '</p>';
                }

                function escapar(t) {
                    var d = document.createElement('div'); d.textContent = t; return d.innerHTML;
                }

                raiz.querySelector('[data-agregar]').addEventListener('click', function () {
                    var nueva = plantilla.cloneNode(true);
                    nueva.querySelectorAll('input').forEach(function (i) { i.value = ''; });
                    filas.appendChild(nueva);
                });

                filas.addEventListener('click', function (e) {
                    var q = e.target.closest('[data-quitar]');
                    if (q && filas.querySelectorAll('[data-fila]').length > 1) { q.closest('[data-fila]').remove(); }
                });

                raiz.querySelector('form').addEventListener('submit', function (e) {
                    e.preventDefault();
                    var boton = raiz.querySelector('[data-simular]');
                    var lineas = Array.prototype.map.call(filas.querySelectorAll('[data-fila]'), function (f) {
                        return { sku: f.querySelector('[name=sku]').value, cantidad: f.querySelector('[name=cantidad]').value, precio_neto: f.querySelector('[name=precio_neto]').value };
                    });
                    var token = raiz.getAttribute('data-token');
                    boton.disabled = true; boton.textContent = textos.calculando;

                    fetch(url, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': token || '' },
                        body: JSON.stringify({ lineas: lineas })
                    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
                    .then(function (x) {
                        var j = x.j;
                        if (!x.ok) { mostrar(escapar(j.error || textos.error), true); return; }
                        if (j.comision === null) {
                            var lista = (j.limitaciones || []).map(function (c) { return '<li>' + escapar(textos.limitaciones[c] || c) + '</li>'; }).join('');
                            mostrar(escapar(textos.no_calculable) + '<ul style="margin:4px 0 0;padding-left:18px">' + lista + '</ul>', true);
                            return;
                        }
                        var texto = (j.comisionables > 0 ? textos.resultado : textos.resultado_cero)
                            .replace(':unidades', j.unidades).replace(':comisionables', j.comisionables).replace(':valor', moneda(j.comision));
                        mostrar(escapar(texto), false);
                    }).catch(function () { mostrar(escapar(textos.error), true); })
                    .finally(function () { boton.disabled = false; boton.textContent = textos.simular; });
                });
            })();
        </script>
    @endPushOnce
@endonce
