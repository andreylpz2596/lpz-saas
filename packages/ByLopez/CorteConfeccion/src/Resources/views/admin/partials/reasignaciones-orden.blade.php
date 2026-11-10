{{--
    Reasignacion de prendas pendientes entre talleres (plan Corte y Confeccion, seccion 6).
    Variables:
      $orden               OrdenConfeccion de la ficha
      $reasignaciones      Collection<Reasignacion> donde esta orden cedio o recibio prendas
      $puedeReasignar      bool (ReasignacionGate; la autorizacion real esta en backend)
      $puedeReasignarAhora bool (estado y saldo permiten abrir el modal)

    Renderiza: (1) historial enlazado, (2) el modal «Reasignar prendas». El boton que lo abre vive en el encabezado de la
    ficha (data-reasignar-abrir). El modal consulta el saldo REAL al abrirse (nunca confia en la pantalla) y envia una
    llave de idempotencia por apertura. Todo texto de usuario se pinta con textContent (nada se interpreta como HTML).
--}}
@php
    $estadoReasignacion = \ByLopez\CorteConfeccion\Enums\EstadoReasignacion::class;
    // Un usuario de TALLER no ve a que otro taller se movieron (ni enlaza a su orden): solo que hubo un movimiento.
    $ocultarContraparte = \ByLopez\CorteConfeccion\Support\TallerConfeccionAccess::esUsuarioTaller();
@endphp

@if ($reasignaciones->isNotEmpty())
    <div class="mt-4 rounded-xl border border-slate-200 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)] dark:border-gray-800 dark:bg-gray-900" id="reasignaciones-historial">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b p-4 dark:border-gray-800">
            <div>
                <p class="text-base font-semibold text-gray-800 dark:text-white">Reasignaciones</p>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Prendas que esta orden cedio a otro taller o recibio de otro. La asignacion original no se modifica.</p>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase text-gray-500 dark:text-gray-400">
                    <tr class="border-b dark:border-gray-800">
                        <th class="px-4 py-2 font-medium">Codigo</th>
                        <th class="px-4 py-2 font-medium">Movimiento</th>
                        <th class="px-4 py-2 text-right font-medium">Unidades</th>
                        <th class="px-4 py-2 font-medium">Estado</th>
                        <th class="px-4 py-2 font-medium">Motivo</th>
                        @if ($puedeReasignar)
                            <th class="px-4 py-2 font-medium">Acciones</th>
                        @endif
                    </tr>
                </thead>

                <tbody>
                    @foreach ($reasignaciones as $reasignacion)
                        @php
                            $esOrigen = (int) $reasignacion->orden_confeccion_origen_id === (int) $orden->id;
                            $estadoEnum = $reasignacion->estadoEnum();
                            $otraOrden = $esOrigen ? $reasignacion->ordenDestino : $reasignacion->ordenOrigen;
                            $otroTaller = $esOrigen ? $reasignacion->tallerDestino : $reasignacion->tallerOrigen;
                        @endphp

                        <tr class="border-b align-top last:border-0 dark:border-gray-800">
                            <td class="px-4 py-2.5 font-medium text-gray-800 dark:text-white">
                                {{ $reasignacion->codigo }}
                                <p class="text-xs font-normal text-gray-500 dark:text-gray-400">{{ $reasignacion->created_at?->format('Y-m-d H:i') }}</p>
                            </td>
                            <td class="px-4 py-2.5 text-gray-700 dark:text-gray-300">
                                {{ $esOrigen ? 'Cedio a' : 'Recibio de' }}
                                @if ($ocultarContraparte)
                                    <span class="font-medium text-gray-800 dark:text-white">otro taller</span>
                                @else
                                    <span class="font-medium text-gray-800 dark:text-white">{{ $otroTaller?->nombre }}</span>
                                    <a href="{{ route('admin.corte_confeccion.ordenes_confeccion.show', $otraOrden->id) }}" class="ml-1 text-blue-600 hover:underline dark:text-blue-400">{{ $otraOrden->codigo }}</a>
                                @endif
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ $reasignacion->situacion_fisica === 'entregada_verificada' ? 'Ya entregadas al taller; verificadas sin confeccionar' : 'Sin entrega fisica al taller' }}
                                </p>
                            </td>
                            <td class="px-4 py-2.5 text-right font-semibold text-gray-800 dark:text-white">
                                {{ number_format($reasignacion->totalUnidades(), 0, ',', '.') }}
                                {{-- Reparto por prioridad trasladado (P1). Las reasignaciones anteriores no lo registraban. --}}
                                @if ($reasignacion->lineas->contains(fn ($l) => $l->cantidad_urgente !== null))
                                    @php
                                        $urgentesTrasladadas = (int) $reasignacion->lineas->sum('cantidad_urgente');
                                        $altasTrasladadas = (int) $reasignacion->lineas->sum('cantidad_alta');
                                    @endphp
                                    <p class="text-xs font-normal text-gray-500 dark:text-gray-400" data-reparto-trasladado>
                                        {{ \ByLopez\CorteConfeccion\Services\PrioridadConfeccionService::repartoTexto(['urgente' => $urgentesTrasladadas, 'alta' => $altasTrasladadas, 'normal' => $reasignacion->totalUnidades() - $urgentesTrasladadas - $altasTrasladadas]) }}
                                    </p>
                                @endif
                            </td>
                            <td class="px-4 py-2.5"><span class="{{ $estadoEnum?->badgeClass() ?? 'label-info' }}">{{ $estadoEnum?->label() ?? $reasignacion->estado }}</span></td>
                            <td class="px-4 py-2.5 text-xs text-gray-600 dark:text-gray-300">
                                {{ $reasignacion->motivo }}
                                @if ($reasignacion->motivo_cierre)
                                    <p class="mt-1 text-gray-500 dark:text-gray-400">Cierre: {{ $reasignacion->motivo_cierre }}</p>
                                @endif
                                @if ($reasignacion->constancia_cierre)
                                    <p class="mt-1 text-gray-500 dark:text-gray-400">Constancia de devolución: {{ $reasignacion->constancia_cierre }}</p>
                                @endif
                            </td>

                            @if ($puedeReasignar)
                                <td class="px-4 py-2.5">
                                    @if ($estadoEnum === $estadoReasignacion::EnTransito)
                                        <form method="POST" action="{{ route('admin.corte_confeccion.ordenes_confeccion.reasignaciones.recibir', $reasignacion->id) }}" data-confirm="Confirmas que las prendas ya llegaron al taller destino?" data-confirm-title="Confirmar traslado">
                                            @csrf
                                            <button class="secondary-button whitespace-nowrap">Confirmar traslado</button>
                                        </form>
                                    @endif

                                    @if ($estadoEnum?->vigente())
                                        <details class="mt-2">
                                            <summary class="cursor-pointer text-xs font-semibold text-red-600 dark:text-red-400">{{ $estadoEnum === $estadoReasignacion::EnTransito ? 'Cancelar traslado' : 'Revertir' }}</summary>
                                            <form method="POST" action="{{ route('admin.corte_confeccion.ordenes_confeccion.reasignaciones.revertir', $reasignacion->id) }}" class="mt-2 grid gap-2">
                                                @csrf
                                                <label class="text-xs text-gray-600 dark:text-gray-300" for="motivo-revertir-{{ $reasignacion->id }}">Motivo (obligatorio)</label>
                                                <textarea id="motivo-revertir-{{ $reasignacion->id }}" name="motivo" required rows="2" class="w-full rounded-lg border px-3 py-2 text-base sm:text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"></textarea>
                                                @if ($reasignacion->situacion_fisica === 'entregada_verificada')
                                                    <p class="text-xs text-amber-700 dark:text-amber-400">Estas prendas ya salieron del taller origen. Solo vuelven a su saldo cuando estén físicamente de regreso.</p>
                                                    <label class="text-xs text-gray-600 dark:text-gray-300" for="constancia-revertir-{{ $reasignacion->id }}">Constancia de devolución (quién las devolvió / referencia) *</label>
                                                    <textarea id="constancia-revertir-{{ $reasignacion->id }}" name="constancia_devolucion" required rows="2" class="w-full rounded-lg border px-3 py-2 text-base sm:text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"></textarea>
                                                    <label class="flex items-start gap-2 text-xs text-gray-700 dark:text-gray-300">
                                                        <input type="checkbox" name="devolucion_fisica_confirmada" value="1" required class="mt-0.5">
                                                        <span>Confirmo que las prendas ya están físicamente de regreso en el taller origen.</span>
                                                    </label>
                                                @endif
                                                <button class="transparent-button justify-self-start">{{ $estadoEnum === $estadoReasignacion::EnTransito ? 'Cancelar traslado' : 'Revertir reasignacion' }}</button>
                                            </form>
                                        </details>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

@if ($puedeReasignarAhora)
    <div
        id="reasignar-modal"
        class="fixed inset-0 z-[10010] hidden overflow-y-auto overscroll-contain bg-gray-900/60"
        role="dialog"
        aria-modal="true"
        aria-labelledby="reasignar-titulo"
        data-contexto-url="{{ route('admin.corte_confeccion.ordenes_confeccion.reasignar.contexto', $orden->id) }}"
        data-store-url="{{ route('admin.corte_confeccion.ordenes_confeccion.reasignar.store', $orden->id) }}"
        data-csrf="{{ csrf_token() }}"
    >
        <div class="mx-auto my-6 w-full max-w-4xl px-3 max-sm:my-0 max-sm:px-0">
            <div class="rounded-xl bg-white shadow-xl max-sm:rounded-none dark:bg-gray-900">
                <div class="flex items-start justify-between gap-3 border-b p-4 dark:border-gray-800">
                    <div class="min-w-0">
                        <h2 id="reasignar-titulo" class="text-lg font-bold text-gray-900 dark:text-white">Reasignar prendas</h2>
                        <p class="mt-0.5 text-sm text-gray-600 dark:text-gray-300" data-r="resumen-origen"></p>
                    </div>
                    <button type="button" class="rounded-md px-2 py-1 text-xl leading-none text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-white" data-r="cerrar" aria-label="Cerrar">&times;</button>
                </div>

                <div class="p-4">
                    <p class="rounded-lg bg-slate-50 p-3 text-sm text-slate-600 dark:bg-gray-800 dark:text-gray-300" data-r="cargando">Cargando saldo real...</p>

                    <div class="hidden space-y-4" data-r="contenido">
                        <div class="hidden rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300" role="alert" data-r="errores"></div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label for="r-destino" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Taller destino <span class="text-red-600">*</span></label>
                                <select id="r-destino" class="w-full rounded-lg border px-3 py-2 text-base sm:text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white" data-r="destino"></select>
                            </div>

                            <fieldset>
                                <legend class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Situacion fisica de las prendas <span class="text-red-600">*</span></legend>
                                <label class="flex items-start gap-2 text-sm text-gray-700 dark:text-gray-300"><input type="radio" name="r-situacion" value="sin_entrega_fisica" checked class="mt-1"> <span>Aun no se han entregado fisicamente al taller (solo cambia la responsabilidad)</span></label>
                                <label class="mt-1 flex items-start gap-2 text-sm text-gray-700 dark:text-gray-300"><input type="radio" name="r-situacion" value="entregada_verificada" class="mt-1"> <span>Ya estan en el taller y se trasladan al nuevo taller</span></label>
                            </fieldset>
                        </div>

                        <div class="hidden space-y-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm dark:border-amber-900 dark:bg-amber-950" data-r="bloque-fisico">
                            <label class="flex items-start gap-2 text-amber-900 dark:text-amber-200"><input type="checkbox" class="mt-1" data-r="confirmacion"> <span>Verifique fisicamente que las prendas que muevo <strong>no han sido cortadas, cosidas ni trabajadas</strong>, y que las que estan en proceso quedaron declaradas abajo.</span></label>
                            <div>
                                <label for="r-traslado" class="mb-1 block text-amber-900 dark:text-amber-200">Traslado</label>
                                <select id="r-traslado" class="rounded-lg border px-3 py-2 text-base sm:text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white" data-r="traslado">
                                    <option value="completado">Ya llegaron al taller destino</option>
                                    <option value="en_transito">En camino: el destino las confirmara al recibirlas</option>
                                </select>
                            </div>
                        </div>

                        <div class="overflow-x-auto rounded-lg border dark:border-gray-800">
                            <table class="w-full text-left text-sm">
                                <thead class="bg-slate-50 text-xs uppercase text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                                    <tr>
                                        <th class="px-3 py-2 font-medium">Variante</th>
                                        <th class="hidden px-3 py-2 text-right font-medium" data-r="col-proceso">En proceso</th>
                                        <th class="px-3 py-2 text-right font-medium">Maximo</th>
                                        <th class="px-3 py-2 text-right font-medium">Mover</th>
                                    </tr>
                                </thead>
                                <tbody data-r="filas"></tbody>
                            </table>
                        </div>

                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <button type="button" class="secondary-button" data-r="todo">Mover todo lo elegible</button>
                            <p class="text-sm text-gray-600 dark:text-gray-300">A mover: <strong class="text-gray-900 dark:text-white" data-r="total-mover">0</strong> unidades</p>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div class="rounded-lg bg-slate-50 p-3 text-sm dark:bg-gray-800" data-r="antes-despues" aria-live="polite"></div>
                            <div class="rounded-lg bg-slate-50 p-3 text-sm dark:bg-gray-800" data-r="impacto-tarifa" aria-live="polite"></div>
                        </div>

                        <div>
                            <label for="r-motivo" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Motivo <span class="text-red-600">*</span></label>
                            <textarea id="r-motivo" rows="2" maxlength="2000" class="w-full rounded-lg border px-3 py-2 text-base sm:text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white" data-r="motivo"></textarea>
                        </div>

                        <div>
                            <label for="r-observacion" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">Observacion</label>
                            <textarea id="r-observacion" rows="2" maxlength="2000" class="w-full rounded-lg border px-3 py-2 text-base sm:text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white" data-r="observacion"></textarea>
                        </div>
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-2 border-t p-4 pb-[max(1rem,env(safe-area-inset-bottom))] dark:border-gray-800">
                    <button type="button" class="secondary-button" data-r="cerrar">Cancelar</button>
                    <button type="button" class="primary-button" data-r="enviar" disabled>Reasignar prendas</button>
                </div>
            </div>
        </div>
    </div>

    @pushOnce('scripts')
        <script>
            (function () {
                var modal = document.getElementById('reasignar-modal');

                if (! modal) {
                    return;
                }

                var q = function (clave) { return modal.querySelector('[data-r="' + clave + '"]'); };
                // El layout admin de Bagisto no publica meta csrf-token: el token viaja en el propio modal (data-csrf).
                var csrf = modal.dataset.csrf || '';
                var ctx = null;
                var llave = null;
                var enviando = false;
                var guardado = {};

                var fmt = function (n) { return Number(n || 0).toLocaleString('es-CO'); };
                var dinero = function (n) { return n === null || n === undefined ? 'Sin tarifa' : '$ ' + Number(n).toLocaleString('es-CO', { maximumFractionDigits: 2 }); };
                var el = function (tag, clase, texto) {
                    var nodo = document.createElement(tag);
                    if (clase) { nodo.className = clase; }
                    if (texto !== undefined && texto !== null) { nodo.textContent = texto; }
                    return nodo;
                };

                var situacion = function () { return modal.querySelector('input[name="r-situacion"]:checked').value; };
                var entregada = function () { return situacion() === 'entregada_verificada'; };

                function mostrarErrores(lista) {
                    var caja = q('errores');
                    caja.textContent = '';
                    (lista || []).forEach(function (m) { caja.appendChild(el('p', '', m)); });
                    caja.classList.toggle('hidden', ! (lista && lista.length));
                    if (lista && lista.length) { caja.scrollIntoView({ block: 'nearest' }); }
                }

                function maximoDe(linea, fila) {
                    var proceso = entregada() ? Math.max(parseInt(fila.proceso.value, 10) || 0, 0) : 0;
                    return Math.max(linea.elegible - proceso, 0);
                }

                /*
                 * P1: cantidades por prioridad que viajan al destino. Sugerido Urgente -> Alta -> Normal sobre el saldo de
                 * cada nivel; «Ajustar» permite elegirlas (deben sumar lo que se mueve). El servidor lo revalida bajo
                 * bloqueo y rechaza si el saldo por nivel cambio (`base`), sin perder lo digitado.
                 */
                var NIVELES = [['urgente', 'Urgente', 'Urgentes'], ['alta', 'Alta', 'Altas'], ['normal', 'Normal', 'Normales']];

                function textoReparto(r, conector) {
                    var partes = NIVELES.filter(function (n) { return r[n[0]] > 0; }).map(function (n) { return fmt(r[n[0]]) + ' ' + (r[n[0]] === 1 ? n[1] : n[2]); });
                    return partes.join(conector || ' + ');
                }

                function repartoDeFila(f) {
                    var cantidad = parseInt(f.mover.value, 10) || 0;
                    var disp = f.disp;
                    var ajustado = !! f.panel && ! f.panel.classList.contains('hidden');
                    var r = {};
                    var error = '';

                    if (ajustado) {
                        NIVELES.forEach(function (n) {
                            var v = f.campos[n[0]].value.trim();
                            r[n[0]] = v === '' ? 0 : (/^\d+$/.test(v) ? parseInt(v, 10) : NaN);
                        });
                        var suma = r.urgente + r.alta + r.normal;
                        if ([r.urgente, r.alta, r.normal].some(function (v) { return ! Number.isFinite(v); })) { error = 'Usa números enteros (0 o más).'; }
                        else if (cantidad > 0 && suma !== cantidad) { error = 'El reparto suma ' + fmt(suma) + ' y debe sumar ' + fmt(cantidad) + '.'; }
                        else { NIVELES.forEach(function (n) { if (! error && r[n[0]] > disp[n[0]]) { error = 'Solo hay ' + fmt(disp[n[0]]) + ' en ' + n[1] + '.'; } }); }
                    } else {
                        r.urgente = Math.min(cantidad, disp.urgente);
                        r.alta = Math.min(cantidad - r.urgente, disp.alta);
                        r.normal = Math.max(cantidad - r.urgente - r.alta, 0);
                    }

                    return { cantidad: cantidad, r: r, ajustado: ajustado, error: cantidad > 0 ? error : '' };
                }

                function alternarAjuste(f, abrir) {
                    var sugerido = repartoDeFila(f).r;
                    f.panel.classList.toggle('hidden', ! abrir);
                    f.ajustar.setAttribute('aria-expanded', abrir ? 'true' : 'false');
                    f.ajustar.textContent = abrir ? 'Usar sugerido' : 'Ajustar';
                    NIVELES.forEach(function (n) { f.campos[n[0]].value = abrir ? String(sugerido[n[0]] || 0) : ''; });
                    actualizar();
                    if (abrir) { f.campos.urgente.focus(); }
                }

                function pintarFilas() {
                    var cuerpo = q('filas');
                    var previo = {};
                    (ctx.filasUI || []).forEach(function (f) {
                        previo[f.linea.detalle_id] = {
                            mover: f.mover.value,
                            proceso: f.proceso.value,
                            ajustado: !! f.panel && ! f.panel.classList.contains('hidden'),
                            valores: f.panel ? { urgente: f.campos.urgente.value, alta: f.campos.alta.value, normal: f.campos.normal.value } : null,
                        };
                    });
                    cuerpo.textContent = '';
                    ctx.filasUI = [];

                    ctx.lineas.forEach(function (linea) {
                        var tr = el('tr', 'border-t dark:border-gray-800');
                        var celdaVariante = el('td', 'min-w-0 break-all px-3 py-2');
                        var nombre = el('p', 'font-medium text-gray-800 dark:text-white', linea.sku || 'Variante');
                        var detalle = [linea.color, linea.talla_etiqueta || linea.talla].filter(Boolean).join(' · ');
                        celdaVariante.appendChild(nombre);
                        if (detalle) { celdaVariante.appendChild(el('p', 'text-xs text-gray-500 dark:text-gray-400', detalle)); }
                        if (linea.cedida > 0) { celdaVariante.appendChild(el('p', 'text-xs text-amber-600', 'Ya cedidas: ' + fmt(linea.cedida))); }
                        if (linea.en_transito > 0) { celdaVariante.appendChild(el('p', 'text-xs text-amber-600', 'En transito hacia este taller: ' + fmt(linea.en_transito))); }
                        tr.appendChild(celdaVariante);

                        // Original / reportado / pendiente viajan como una linea de apoyo bajo la variante: la tabla cabe en 360 px sin desplazarse.
                        celdaVariante.appendChild(el('p', 'text-xs text-gray-500 dark:text-gray-400', 'Original ' + fmt(linea.original) + ' · Reportado ' + fmt(linea.reportada) + ' · Pendiente ' + fmt(linea.elegible)));

                        // Prioridad asignada de lo elegible y lo que se trasladaria (P1).
                        var disp = linea.prioridades || { urgente: 0, alta: 0, normal: linea.elegible };
                        celdaVariante.appendChild(el('p', 'text-xs text-gray-600 dark:text-gray-300', 'Prioridad: ' + (textoReparto(disp, ' · ') || 'sin pendiente')));
                        var cajaReparto = el('div', 'mt-1 text-xs');
                        cajaReparto.setAttribute('data-r-reparto', String(linea.detalle_id));
                        var textoMover = el('span', 'text-gray-800 dark:text-gray-100', '');
                        cajaReparto.appendChild(textoMover);
                        var ajustar = null, panel = null, campos = {};
                        if (disp.urgente + disp.alta > 0) {
                            ajustar = el('button', 'ml-1 font-semibold text-blue-600 hover:underline dark:text-blue-400', 'Ajustar');
                            ajustar.type = 'button';
                            ajustar.setAttribute('aria-expanded', 'false');
                            ajustar.setAttribute('data-r-ajustar', '1');
                            cajaReparto.appendChild(ajustar);
                            panel = el('div', 'mt-1 grid hidden max-w-xs grid-cols-3 gap-1');
                            NIVELES.forEach(function (n) {
                                var etiqueta = el('label', 'block text-[10px] text-gray-500 dark:text-gray-400', n[1] + ' (máx. ' + fmt(disp[n[0]]) + ')');
                                var campo = el('input', 'mt-0.5 w-full rounded border px-1.5 py-1 text-right text-base sm:text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white');
                                campo.type = 'text'; campo.inputMode = 'numeric'; campo.autocomplete = 'off';
                                campo.setAttribute('data-r-nivel', n[0]);
                                campo.addEventListener('input', actualizar);
                                etiqueta.appendChild(campo);
                                panel.appendChild(etiqueta);
                                campos[n[0]] = campo;
                            });
                            cajaReparto.appendChild(panel);
                        }
                        var errorReparto = el('p', 'hidden text-red-600', '');
                        errorReparto.setAttribute('role', 'alert');
                        cajaReparto.appendChild(errorReparto);
                        celdaVariante.appendChild(cajaReparto);
                        var tdProceso = el('td', 'hidden px-3 py-2 text-right', '');
                        var proceso = el('input', 'w-20 rounded-lg border px-2 py-1 text-right text-base sm:text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white');
                        proceso.type = 'number'; proceso.min = '0'; proceso.step = '1'; proceso.inputMode = 'numeric';
                        proceso.setAttribute('aria-label', 'Prendas en proceso de ' + (linea.sku || 'variante'));
                        proceso.value = previo[linea.detalle_id] ? previo[linea.detalle_id].proceso : '';
                        tdProceso.appendChild(proceso);
                        tdProceso.setAttribute('data-col-proceso', '1');
                        tr.appendChild(tdProceso);

                        var tdMax = el('td', 'px-3 py-2 text-right font-semibold text-gray-800 dark:text-white', '');
                        tr.appendChild(tdMax);

                        var tdMover = el('td', 'px-3 py-2 text-right');
                        var mover = el('input', 'w-20 rounded-lg border px-2 py-1 text-right text-base sm:text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white');
                        mover.type = 'number'; mover.min = '0'; mover.step = '1'; mover.inputMode = 'numeric';
                        mover.setAttribute('aria-label', 'Cantidad a mover de ' + (linea.sku || 'variante'));
                        mover.value = previo[linea.detalle_id] ? previo[linea.detalle_id].mover : '';
                        var todo = el('button', 'ml-1 rounded-md border px-1.5 py-1 text-xs font-semibold text-blue-600 dark:border-gray-700 dark:text-blue-400', 'Todo');
                        todo.type = 'button';
                        tdMover.appendChild(mover); tdMover.appendChild(todo);
                        tr.appendChild(tdMover);

                        var fila = { linea: linea, mover: mover, proceso: proceso, tdMax: tdMax, tdProceso: tdProceso, disp: disp, textoMover: textoMover, ajustar: ajustar, panel: panel, campos: campos, errorReparto: errorReparto };
                        var refrescar = function () { tdMax.textContent = fmt(maximoDe(linea, fila)); actualizar(); };
                        mover.addEventListener('input', actualizar);
                        proceso.addEventListener('input', refrescar);
                        todo.addEventListener('click', function () { mover.value = maximoDe(linea, fila); actualizar(); });
                        if (ajustar) { ajustar.addEventListener('click', function () { alternarAjuste(fila, ajustar.getAttribute('aria-expanded') !== 'true'); }); }
                        // Tras un rechazo se conserva el reparto que la persona habia escrito.
                        var antes = previo[linea.detalle_id];
                        if (panel && antes && antes.ajustado && antes.valores) {
                            panel.classList.remove('hidden');
                            ajustar.setAttribute('aria-expanded', 'true');
                            ajustar.textContent = 'Usar sugerido';
                            NIVELES.forEach(function (n) { campos[n[0]].value = antes.valores[n[0]]; });
                        }
                        tdMax.textContent = fmt(maximoDe(linea, fila));

                        cuerpo.appendChild(tr);
                        ctx.filasUI.push(fila);
                    });
                }

                function destinoActual() {
                    var id = parseInt(q('destino').value, 10);
                    return ctx.talleres.filter(function (t) { return t.id === id; })[0] || null;
                }

                function seleccion() {
                    return ctx.filasUI.map(function (f) {
                        return { fila: f, cantidad: parseInt(f.mover.value, 10) || 0 };
                    }).filter(function (s) { return s.cantidad > 0; });
                }

                function actualizar() {
                    var fisica = entregada();
                    q('bloque-fisico').classList.toggle('hidden', ! fisica);
                    q('col-proceso').classList.toggle('hidden', ! fisica);
                    var repartoInvalido = false;
                    ctx.filasUI.forEach(function (f) {
                        f.tdProceso.classList.toggle('hidden', ! fisica);
                        f.tdMax.textContent = fmt(maximoDe(f.linea, f));
                        var rep = repartoDeFila(f);
                        f.textoMover.textContent = rep.cantidad > 0 && ! rep.error ? 'Se trasladan: ' + textoReparto(rep.r) + (rep.ajustado ? '' : ' (sugerido)') : '';
                        f.errorReparto.textContent = rep.error;
                        f.errorReparto.classList.toggle('hidden', ! rep.error);
                        if (rep.error) { repartoInvalido = true; }
                    });

                    var sel = seleccion();
                    var total = sel.reduce(function (a, s) { return a + s.cantidad; }, 0);
                    q('total-mover').textContent = fmt(total);

                    var destino = destinoActual();
                    var antesDespues = q('antes-despues');
                    antesDespues.textContent = '';
                    antesDespues.appendChild(el('p', 'mb-1 font-semibold text-gray-800 dark:text-white', 'Antes y despues'));
                    antesDespues.appendChild(el('p', 'text-gray-700 dark:text-gray-300', ctx.orden.taller + ': ' + fmt(ctx.total_origen) + ' → ' + fmt(ctx.total_origen - total)));
                    if (destino) { antesDespues.appendChild(el('p', 'text-gray-700 dark:text-gray-300', destino.nombre + ': ' + fmt(destino.total) + ' → ' + fmt(destino.total + total))); }

                    var impacto = q('impacto-tarifa');
                    impacto.textContent = '';
                    impacto.appendChild(el('p', 'mb-1 font-semibold text-gray-800 dark:text-white', 'Impacto de tarifa (referencial)'));
                    var importeOrigen = 0, importeDestino = 0, sinTarifaDestino = false, sinTarifaOrigen = false;
                    sel.forEach(function (s) {
                        var t = ctx.tarifas.filter(function (x) { return x.attribute_family_id === s.fila.linea.attribute_family_id; })[0];
                        if (! t) { return; }
                        if (t.origen === null) { sinTarifaOrigen = true; } else { importeOrigen += t.origen * s.cantidad; }
                        var tarifaDestino = destino ? t.destinos[destino.id] : null;
                        if (tarifaDestino === null || tarifaDestino === undefined) { sinTarifaDestino = true; } else { importeDestino += tarifaDestino * s.cantidad; }
                    });
                    impacto.appendChild(el('p', 'text-gray-700 dark:text-gray-300', 'Con ' + ctx.orden.taller + ': ' + (sinTarifaOrigen ? 'Sin tarifa (parcial ' + dinero(importeOrigen) + ')' : dinero(importeOrigen))));
                    impacto.appendChild(el('p', 'text-gray-700 dark:text-gray-300', 'Con ' + (destino ? destino.nombre : 'el destino') + ': ' + (sinTarifaDestino ? 'Sin tarifa (parcial ' + dinero(importeDestino) + ')' : dinero(importeDestino))));
                    impacto.appendChild(el('p', 'mt-1 text-xs text-gray-500 dark:text-gray-400', 'El destino usa su propia tarifa vigente; no hereda la del taller de origen.'));

                    q('enviar').disabled = enviando || total <= 0 || ! destino || repartoInvalido;
                }

                function pintarContexto() {
                    q('resumen-origen').textContent = ctx.orden.codigo + ' · ' + ctx.orden.taller + (ctx.orden.orden_corte ? ' · Corte ' + ctx.orden.orden_corte : '');
                    var sel = q('destino');
                    var actual = sel.value;
                    sel.textContent = '';
                    sel.appendChild(el('option', '', 'Selecciona un taller')).value = '';
                    ctx.talleres.forEach(function (t) { var o = el('option', '', t.nombre); o.value = t.id; sel.appendChild(o); });
                    if (actual) { sel.value = actual; }
                    pintarFilas();
                    q('cargando').classList.add('hidden');
                    q('contenido').classList.remove('hidden');
                    actualizar();
                }

                async function cargar() {
                    q('cargando').classList.remove('hidden');
                    q('cargando').textContent = 'Cargando saldo real...';
                    q('contenido').classList.add('hidden');
                    var r = await fetch(modal.dataset.contextoUrl, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });

                    if (! r.ok) {
                        q('cargando').textContent = 'No se pudo cargar el saldo (' + r.status + '). Recarga la pagina e intenta de nuevo.';
                        return;
                    }

                    var datos = await r.json();
                    ctx = Object.assign(datos, { filasUI: ctx ? ctx.filasUI : [] });
                    llave = llave || datos.idempotency_key;
                    pintarContexto();
                }

                function abrir() {
                    modal.classList.remove('hidden');
                    llave = null;
                    ctx = ctx || { filasUI: [] };
                    cargar();
                    q('cerrar').focus();
                }

                function cerrar() {
                    if (enviando) { return; }
                    modal.classList.add('hidden');
                    mostrarErrores([]);
                    ctx = null;
                }

                async function enviar() {
                    if (enviando) { return; }
                    var sel = seleccion();
                    var destino = destinoActual();
                    var errores = [];

                    if (! destino) { errores.push('Selecciona el taller destino.'); }
                    if (! sel.length) { errores.push('Indica al menos una cantidad a mover.'); }
                    if (! q('motivo').value.trim()) { errores.push('El motivo es obligatorio.'); }
                    if (entregada() && ! q('confirmacion').checked) { errores.push('Debes confirmar la verificacion fisica de las prendas.'); }

                    sel.forEach(function (s) {
                        if (s.cantidad > maximoDe(s.fila.linea, s.fila)) { errores.push((s.fila.linea.sku || 'Variante') + ': supera el maximo elegible (' + fmt(maximoDe(s.fila.linea, s.fila)) + ').'); }
                        var rep = repartoDeFila(s.fila);
                        if (rep.error) { errores.push((s.fila.linea.sku || 'Variante') + ': ' + rep.error); }
                    });

                    mostrarErrores(errores);
                    if (errores.length) { return; }

                    enviando = true;
                    q('enviar').disabled = true;
                    q('enviar').textContent = 'Procesando...';

                    var cuerpo = {
                        taller_destino_id: destino.id,
                        idempotency_key: llave,
                        motivo: q('motivo').value.trim(),
                        observacion: q('observacion').value.trim() || null,
                        situacion_fisica: situacion(),
                        confirmacion_fisica: entregada() ? q('confirmacion').checked : false,
                        traslado: entregada() ? q('traslado').value : 'completado',
                        lineas: sel.map(function (s) {
                            var l = { detalle_id: s.fila.linea.detalle_id, cantidad: s.cantidad };
                            if (entregada() && s.fila.proceso.value !== '') { l.cantidad_en_proceso = parseInt(s.fila.proceso.value, 10) || 0; }
                            // Saldo por nivel que se vio: si cambio, el servidor pide revisar. Sin «Ajustar» aplica el sugerido.
                            l.base = [s.fila.disp.urgente, s.fila.disp.alta, s.fila.disp.normal].join(':');
                            var rep = repartoDeFila(s.fila);
                            if (rep.ajustado) { l.urgente = rep.r.urgente; l.alta = rep.r.alta; l.normal = rep.r.normal; }
                            return l;
                        })
                    };

                    try {
                        var r = await fetch(modal.dataset.storeUrl, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf },
                            credentials: 'same-origin',
                            body: JSON.stringify(cuerpo)
                        });
                        var datos = await r.json().catch(function () { return {}; });

                        if (r.ok) {
                            window.location.href = datos.redirect_url || window.location.href;
                            return;
                        }

                        var mensajes = [];
                        if (datos.errors) { Object.keys(datos.errors).forEach(function (k) { [].concat(datos.errors[k]).forEach(function (m) { mensajes.push(m); }); }); }
                        if (! mensajes.length) { mensajes.push(datos.message || ('Error ' + r.status + (r.status === 419 ? ': la sesion expiro, recarga la pagina.' : ''))); }
                        mostrarErrores(mensajes);

                        // 422/409: se conserva lo digitado y se refresca el saldo real.
                        if (r.status === 409) { llave = null; }
                        enviando = false;
                        await cargar();
                    } catch (e) {
                        mostrarErrores(['No se pudo contactar al servidor. Tu solicitud no se duplicara si la reintentas.']);
                        enviando = false;
                    } finally {
                        enviando = false;
                        q('enviar').textContent = 'Reasignar prendas';
                        if (ctx) { actualizar(); }
                    }
                }

                // Delegacion en document + modal resuelto en cada evento: Bagisto monta Vue sobre la pagina DESPUES de este script
                // y puede reemplazar los nodos (en WebKit/Safari se perdian los listeners ligados directamente).
                var actual = function () { modal = document.getElementById('reasignar-modal') || modal; csrf = modal.dataset.csrf || csrf; return modal; };

                document.addEventListener('click', function (e) {
                    var m = actual();

                    if (e.target.closest('[data-reasignar-abrir]')) { abrir(); return; }
                    if (! m.contains(e.target)) { return; }
                    if (e.target.closest('[data-r="cerrar"]')) { cerrar(); return; }
                    if (e.target.closest('[data-r="todo"]')) { if (ctx) { ctx.filasUI.forEach(function (f) { f.mover.value = maximoDe(f.linea, f); }); actualizar(); } return; }
                    if (e.target.closest('[data-r="enviar"]')) { enviar(); }
                });
                document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && actual() && ! modal.classList.contains('hidden')) { cerrar(); } });
                document.addEventListener('change', function (e) {
                    if (actual().contains(e.target) && ctx && e.target.matches('input[name="r-situacion"], [data-r="destino"]')) { actualizar(); }
                });
            })();
        </script>
    @endPushOnce
@endif
