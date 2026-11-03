@php
    use ByLopez\VentasAsesor\Models\ComisionLiquidacion as Liq;

    $dinero = fn ($v) => $v === null ? '—' : \ByLopez\Logistica\Support\Moneda::formatear((float) $v);
    $input = 'w-full rounded-lg border border-slate-200 px-3 py-2 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300';
    $label = 'mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400';
    $colorLiq = [Liq::BORRADOR => 'cian', Liq::APROBADA => 'verde', Liq::ANULADA => 'gris'];
    $colorAjuste = ['pendiente_aprobacion' => 'amarillo', 'aprobado' => 'verde', 'rechazado' => 'gris'];
    $hoy = now()->toDateString();
@endphp

@once
    @push('styles')
        @include('ventasasesor::admin.cartera.estilos')
        @include('ventasasesor::admin.comisiones.estilos-panel')
    @endpush
@endonce

<x-admin::layouts>
    <x-slot:title>@lang('ventasasesor::comisiones.tabs.liquidaciones')</x-slot>

    <div class="va-orders-page bylopez-scope">
        @include('ventasasesor::admin.comisiones.encabezado')

        @if ($asesores !== [])
            <form method="GET" action="{{ route('admin.ventas_asesor.comisiones.index') }}" class="va-panel mt-4 flex flex-wrap items-end gap-3 p-4">
                <input type="hidden" name="vista" value="liquidaciones">
                <div style="min-width:9.5rem">
                    <label class="{{ $label }}" for="f-mes-cuenta">@lang('ventasasesor::comisiones.mes-comercial')</label>
                    <input id="f-mes-cuenta" type="month" name="mes" value="{{ $mes }}" class="{{ $input }}" onchange="this.form.submit()">
                </div>
                <div class="min-w-[16rem]">
                    <label class="{{ $label }}" for="f-asesor">@lang('ventasasesor::comisiones.asesor')</label>
                    <select id="f-asesor" name="asesor" class="custom-select {{ $input }}" onchange="this.form.submit()">
                        <option value="">@lang('ventasasesor::comisiones.elige-asesor')</option>
                        @foreach ($asesores as $id => $nombre)
                            <option value="{{ $id }}" @selected($asesor === (int) $id)>{{ $nombre }}</option>
                        @endforeach
                    </select>
                </div>
            </form>
        @endif

        @if ($cuenta === null)
            <p class="va-panel mt-4 p-4 text-sm text-slate-500">@lang('ventasasesor::comisiones.elige-asesor-ayuda')</p>
        @else
            @include('ventasasesor::admin.comisiones.partials.cuenta-acumulado')

            {{-- ACTIVIDAD del mes (separada del saldo acumulado): solo las ventas de ese mes comercial. --}}
            @php($act = $cuentaAsesor['actividad'])
            <section class="mt-6" data-seccion="actividad-mes" aria-labelledby="va-act-titulo">
                <h2 id="va-act-titulo" class="text-base font-semibold text-gray-900 dark:text-white">@lang('ventasasesor::comisiones.cuenta.actividad-titulo', ['mes' => \Illuminate\Support\Carbon::createFromFormat('Y-m', $mes)->locale(app()->getLocale())->isoFormat('MMMM YYYY')])</h2>
                <p class="va-sub">@lang('ventasasesor::comisiones.cuenta.actividad-ayuda')</p>
                @include('ventasasesor::admin.comisiones.partials.avisos', ['panel' => $act])
                <div class="va-etapas mt-3" data-etapas="comision">
                    @foreach (['bloqueada', 'habilitada', 'por_pagar', 'pagada'] as $e)
                        <span data-etapa="{{ $e }}">@lang('ventasasesor::comisiones.mis.etapas.'.$e): <strong>{{ $dinero($act['etapas'][$e]) }}</strong></span>
                    @endforeach
                    <p class="va-etapas__nota">@lang('ventasasesor::comisiones.panel.etapas-nota') <strong data-total-mes>{{ $dinero($act['total_mes']) }}@if ($act['total_parcial']) + @lang('ventasasesor::comisiones.panel.sin-calcular')@endif</strong></p>
                </div>
                <div class="mt-3">
                    @include('ventasasesor::admin.comisiones.partials.lista-pedidos', [
                        'pedidos' => $act['pedidos'],
                        'verCalculo' => $cuentaAsesor['ve_calculo'],
                        'urlDetalle' => fn ($id) => route('admin.ventas_asesor.comisiones.index', ['pedido' => $id]),
                    ])
                </div>
            </section>

            <div class="mt-6">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white">@lang('ventasasesor::comisiones.cuenta.liquidaciones-titulo')</h2>
                <p class="va-sub">@lang('ventasasesor::comisiones.cuenta.liquidaciones-ayuda')</p>
            </div>
            @if ($permisos['aprobar'])
                @php($ajustesLibres = $cuenta['ajustes']->where('estado', 'aprobado')->whereNull('liquidacion_id'))
                <form method="POST" action="{{ route('admin.ventas_asesor.comisiones.liquidaciones.store') }}" class="va-panel mt-4 p-4" data-form="crear-liquidacion">
                    @csrf
                    <input type="hidden" name="clave" value="liq:{{ $clave }}">
                    <input type="hidden" name="asesor_id" value="{{ $cuenta['asesor_id'] }}">
                    <p class="text-base font-semibold text-gray-900 dark:text-white">@lang('ventasasesor::comisiones.crear-liquidacion')</p>
                    <p class="va-sub">@lang('ventasasesor::comisiones.crear-liquidacion-ayuda')</p>
                    <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-12">
                        <div class="md:col-span-3"><label class="{{ $label }}">@lang('ventasasesor::comisiones.periodo-desde')</label><input type="date" name="periodo_desde" required class="{{ $input }}"></div>
                        <div class="md:col-span-3"><label class="{{ $label }}">@lang('ventasasesor::comisiones.periodo-hasta')</label><input type="date" name="periodo_hasta" value="{{ $hoy }}" required class="{{ $input }}"></div>
                        <div class="md:col-span-4"><label class="{{ $label }}">@lang('ventasasesor::comisiones.observacion')</label><input type="text" name="observacion" maxlength="500" class="{{ $input }}"></div>
                        <div class="flex items-end md:col-span-2"><button type="submit" class="primary-button w-full justify-center">@lang('ventasasesor::comisiones.crear-borrador')</button></div>
                    </div>
                    @if ($ajustesLibres->isNotEmpty())
                        <p class="mt-3 text-xs font-semibold">@lang('ventasasesor::comisiones.incluir-ajustes')</p>
                        @foreach ($ajustesLibres as $a)
                            <label class="mt-1 flex items-center gap-2 text-sm"><input type="checkbox" name="ajustes[]" value="{{ $a->id }}"> {{ $dinero($a->monto) }} · {{ $a->motivo }}</label>
                        @endforeach
                    @endif
                </form>
            @endif

            <div class="mt-4 grid gap-3" data-seccion="liquidaciones">
                @forelse ($cuenta['liquidaciones'] as $f)
                    @php($l = $f['liquidacion'])
                    <div class="va-panel p-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="text-base font-semibold">{{ $l->numero() }}
                                <span class="va-pill va-pill--sm va-pill--{{ $colorLiq[$l->estado] }}"><span>@lang('ventasasesor::comisiones.estados-liquidacion.'.$l->estado)</span></span>
                            </p>
                            <p class="va-sub">{{ $l->periodo_desde->toDateString() }} → {{ $l->periodo_hasta->toDateString() }} · {{ $l->lineas_count }} @lang('ventasasesor::comisiones.lineas')</p>
                        </div>
                        <div class="mt-2 flex flex-wrap gap-4 text-sm">
                            <span>@lang('ventasasesor::comisiones.total-lineas'): <strong>{{ $dinero($l->total_lineas) }}</strong></span>
                            <span>@lang('ventasasesor::comisiones.total-ajustes'): <strong>{{ $dinero($l->total_ajustes) }}</strong></span>
                            <span>@lang('ventasasesor::comisiones.total'): <strong>{{ $dinero($l->total) }}</strong></span>
                            <span>@lang('ventasasesor::comisiones.abonado'): <strong>{{ $dinero($f['abonado']) }}</strong></span>
                            <span>@lang('ventasasesor::comisiones.saldo'): <strong class="{{ (float) $f['saldo'] > 0 ? 'text-amber-600' : 'text-green-700' }}">{{ $dinero($f['saldo']) }}</strong></span>
                            @if ((float) $f['compensado'] > 0)
                                <span>@lang('ventasasesor::comisiones.compensado'): <strong>{{ $dinero($f['compensado']) }}</strong></span>
                            @endif
                            @if ((float) $f['retenido'] > 0)
                                <span class="text-red-600">@lang('ventasasesor::comisiones.retenido'): <strong>{{ $dinero($f['retenido']) }}</strong></span>
                            @endif
                        </div>

                        {{-- Incidencias: hechos posteriores sobre lineas ya liquidadas. Retienen lo no pagado de ESA linea; las demas siguen pagandose. --}}
                        @if ($f['incidencias']->isNotEmpty())
                            <div class="mt-3 rounded border border-red-200 bg-red-50 p-3 text-xs dark:border-red-900 dark:bg-red-950" data-seccion="incidencias">
                                <p class="font-semibold text-red-700 dark:text-red-300">@lang('ventasasesor::comisiones.incidencias')</p>
                                <p class="va-sub">@lang('ventasasesor::comisiones.incidencias-ayuda')</p>
                                @foreach ($f['incidencias'] as $inc)
                                    <div class="mt-2 flex flex-wrap items-center gap-2 border-t border-red-100 pt-2 dark:border-red-900">
                                        <span class="va-pill va-pill--sm va-pill--{{ $inc->estado === 'abierta' ? 'rojo' : 'gris' }}"><span>@lang('ventasasesor::comisiones.estados-incidencia.'.$inc->estado)</span></span>
                                        <span>#{{ $inc->id }} · @lang('ventasasesor::comisiones.tipos-incidencia.'.$inc->tipo) · <a class="text-blue-600 hover:underline" href="{{ route('admin.ventas_asesor.comisiones.index', ['pedido' => $inc->order_id]) }}">#{{ $inc->order_id }}</a></span>
                                        <span class="text-slate-500">{{ implode(', ', (array) ($inc->detalle['motivos'] ?? [])) }}</span>
                                        @if ($inc->ajuste_id)
                                            <span>@lang('ventasasesor::comisiones.ajuste-propuesto', ['id' => $inc->ajuste_id])</span>
                                        @endif
                                        @if ($inc->estado === 'resuelta')
                                            <span class="text-slate-500">@lang('ventasasesor::comisiones.resoluciones-incidencia.'.$inc->resolucion) · {{ $inc->resuelta_por_name_snapshot }} · {{ $inc->motivo_resolucion }}</span>
                                        @elseif ($permisos['aprobar'])
                                            <form method="POST" action="{{ route('admin.ventas_asesor.comisiones.incidencias.resolver', $inc->id) }}" class="flex flex-wrap gap-1">
                                                @csrf
                                                <input type="text" name="motivo" minlength="5" required placeholder="@lang('ventasasesor::comisiones.motivo')" class="{{ $input }} py-1 text-xs">
                                                <button type="submit" class="transparent-button text-xs">@lang('ventasasesor::comisiones.mantener-comision')</button>
                                            </form>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                        @if ($l->motivo_anulacion)
                            <p class="va-sub mt-1">@lang('ventasasesor::comisiones.motivo'): {{ $l->motivo_anulacion }}</p>
                        @endif

                        <div class="mt-3 flex flex-wrap gap-2">
                            @if ($permisos['aprobar'] && $l->estado === Liq::BORRADOR)
                                <form method="POST" action="{{ route('admin.ventas_asesor.comisiones.liquidaciones.aprobar', $l->id) }}">@csrf<button class="primary-button" type="submit">@lang('ventasasesor::comisiones.aprobar')</button></form>
                            @endif
                            @if ($permisos['revertir'] && $l->estado !== Liq::ANULADA && (float) $f['abonado'] <= 0)
                                <form method="POST" action="{{ route('admin.ventas_asesor.comisiones.liquidaciones.anular', $l->id) }}" class="flex gap-2">
                                    @csrf
                                    <input type="text" name="motivo" minlength="5" required placeholder="@lang('ventasasesor::comisiones.motivo-anulacion')" class="{{ $input }}">
                                    <button class="transparent-button" type="submit">@lang('ventasasesor::comisiones.anular')</button>
                                </form>
                            @endif
                        </div>

                        @if ($permisos['pagar'] && $l->estado === Liq::APROBADA && (float) $f['saldo'] > 0 && (float) $f['pagable'] <= 0)
                            <p class="va-sub mt-3 text-red-600">@lang('ventasasesor::comisiones.sin-pagable')</p>
                        @endif
                        @if ($permisos['pagar'] && $l->estado === Liq::APROBADA && (float) $f['pagable'] > 0)
                            @include('ventasasesor::admin.comisiones.partials.abono-flujo')
                        @endif

                        @if ($l->abonos->isNotEmpty())
                            <table class="mt-3 w-full text-xs">
                                <tbody>
                                    @foreach ($l->abonos as $ab)
                                        <tr class="border-t border-slate-100 dark:border-gray-800">
                                            <td class="py-1 pr-2">@lang('ventasasesor::comisiones.tipos-abono.'.$ab->tipo)</td>
                                            <td class="py-1 pr-2 font-semibold">{{ $ab->tipo === 'reverso' ? '−' : '' }}{{ $dinero($ab->monto) }}</td>
                                            <td class="py-1 pr-2">{{ $ab->fecha_pago->toDateString() }}</td>
                                            <td class="py-1 pr-2">{{ $ab->medio }} {{ $ab->referencia }}</td>
                                            <td class="py-1 pr-2">{{ $ab->actor_name_snapshot }}</td>
                                            <td class="py-1 pr-2">{{ $ab->observacion }}</td>
                                            <td class="py-1">
                                                @if ($ab->comprobante_ruta && $permisos['comprobantes'])
                                                    <a href="{{ route('admin.ventas_asesor.comisiones.abonos.comprobante', $ab->id) }}" class="text-blue-600 hover:underline">@lang('ventasasesor::comisiones.ver-comprobante')</a>
                                                @endif
                                                @if ($permisos['revertir'] && $ab->tipo === 'abono' && ! $l->abonos->contains('revierte_id', $ab->id))
                                                    <form method="POST" action="{{ route('admin.ventas_asesor.comisiones.abonos.revertir', $ab->id) }}" class="mt-1 flex gap-1">
                                                        @csrf
                                                        <input type="hidden" name="clave" value="reverso:{{ $ab->id }}:{{ $clave }}">
                                                        <input type="text" name="motivo" minlength="5" required placeholder="@lang('ventasasesor::comisiones.motivo')" class="{{ $input }} py-1 text-xs">
                                                        <button type="submit" class="transparent-button text-xs">@lang('ventasasesor::comisiones.revertir')</button>
                                                    </form>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif

                        <details class="mt-2">
                            <summary class="cursor-pointer text-xs text-blue-600">@lang('ventasasesor::comisiones.lineas-incluidas')</summary>
                            <table class="mt-2 w-full text-xs">
                                <tbody>
                                    @foreach ($l->lineas->sortBy('habilitada_en') as $ln)
                                        <tr class="border-t border-slate-100 dark:border-gray-800">
                                            <td class="py-1 pr-2"><a class="text-blue-600 hover:underline" href="{{ route('admin.ventas_asesor.comisiones.index', ['pedido' => $ln->order_id]) }}">#{{ $ln->order_id }}</a></td>
                                            <td class="py-1 pr-2">{{ $ln->referencia }} · {{ $ln->sku }}</td>
                                            <td class="py-1 pr-2">{{ $ln->unidades_comisionables }} u.</td>
                                            <td class="py-1 pr-2 font-semibold">{{ $dinero($ln->monto) }}</td>
                                            @php($ap = $f['aplicaciones'][$ln->id] ?? ['pagado' => '0', 'compensado' => '0', 'pendiente' => $ln->monto, 'retenido' => false])
                                            <td class="py-1">
                                                @lang('ventasasesor::comisiones.pagado'): {{ $dinero($ap['pagado']) }}
                                                @if ((float) $ap['compensado'] > 0) · @lang('ventasasesor::comisiones.compensado'): {{ $dinero($ap['compensado']) }} @endif
                                                · @lang('ventasasesor::comisiones.pendiente'): {{ $dinero($ap['pendiente']) }}
                                                @if ($ap['retenido']) <span class="font-semibold text-red-600">· @lang('ventasasesor::comisiones.retenido')</span> @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </details>
                    </div>
                @empty
                    <p class="va-panel p-4 text-sm text-slate-500">@lang('ventasasesor::comisiones.sin-liquidaciones')</p>
                @endforelse
            </div>

            @include('ventasasesor::admin.comisiones.partials.cuenta-movimientos')

            {{-- Ajustes: nacen pendientes; solo un aprobado entra a una liquidacion, y solo si se elige. Nada se descuenta solo. --}}
            <div class="va-panel mt-4 p-4" data-seccion="ajustes">
                <p class="text-base font-semibold">@lang('ventasasesor::comisiones.ajustes')</p>
                <p class="va-sub">@lang('ventasasesor::comisiones.ajustes-ayuda')</p>

                @if ($permisos['ajustar'])
                    <form method="POST" action="{{ route('admin.ventas_asesor.comisiones.ajustes.store') }}" class="mt-3 grid grid-cols-1 gap-2 md:grid-cols-12">
                        @csrf
                        <input type="hidden" name="clave" value="ajuste:{{ $clave }}">
                        <input type="hidden" name="asesor_id" value="{{ $cuenta['asesor_id'] }}">
                        <div class="md:col-span-2"><label class="{{ $label }}">@lang('ventasasesor::comisiones.valor')</label><input type="number" name="monto" step="0.01" required class="{{ $input }}"></div>
                        <div class="md:col-span-2"><label class="{{ $label }}">@lang('ventasasesor::comisiones.linea-opcional')</label><input type="number" name="linea_id" min="1" class="{{ $input }}"></div>
                        <div class="md:col-span-6"><label class="{{ $label }}">@lang('ventasasesor::comisiones.motivo')</label><input type="text" name="motivo" minlength="5" maxlength="500" required class="{{ $input }}"></div>
                        <div class="flex items-end md:col-span-2"><button type="submit" class="secondary-button w-full justify-center">@lang('ventasasesor::comisiones.proponer-ajuste')</button></div>
                    </form>
                @endif

                <table class="mt-3 w-full text-sm">
                    <tbody>
                        @forelse ($cuenta['ajustes'] as $a)
                            <tr class="border-t border-slate-100 align-top dark:border-gray-800">
                                <td class="py-1.5 pr-2 font-semibold">{{ $dinero($a->monto) }}</td>
                                <td class="py-1.5 pr-2"><span class="va-pill va-pill--sm va-pill--{{ $colorAjuste[$a->estado] }}"><span>@lang('ventasasesor::comisiones.estados-ajuste.'.$a->estado)</span></span></td>
                                <td class="py-1.5 pr-2">@lang('ventasasesor::comisiones.origenes.'.$a->origen)<span class="va-sub block">{{ $a->motivo }}</span></td>
                                <td class="py-1.5 pr-2">{{ $a->liquidacion_id ? '#'.$a->liquidacion_id : '' }}</td>
                                <td class="py-1.5">
                                    @if ($permisos['aprobar'] && $a->estado === 'pendiente_aprobacion')
                                        <form method="POST" action="{{ route('admin.ventas_asesor.comisiones.ajustes.resolver', $a->id) }}" class="flex flex-wrap gap-1">
                                            @csrf
                                            <input type="text" name="motivo" minlength="5" required placeholder="@lang('ventasasesor::comisiones.motivo')" class="{{ $input }} py-1 text-xs">
                                            <button type="submit" name="decision" value="aprobar" class="primary-button text-xs">@lang('ventasasesor::comisiones.aprobar')</button>
                                            <button type="submit" name="decision" value="rechazar" class="transparent-button text-xs">@lang('ventasasesor::comisiones.rechazar')</button>
                                        </form>
                                    @elseif ($a->resuelto_por_name_snapshot)
                                        <span class="va-sub">{{ $a->resuelto_por_name_snapshot }} · {{ $a->motivo_resolucion }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td class="py-2 text-sm text-slate-500">@lang('ventasasesor::comisiones.sin-ajustes')</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-admin::layouts>
