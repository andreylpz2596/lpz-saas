@php
    $pendientes = $resumen['pendientes'];
    $fecha = fn ($v) => $v ? core()->formatDate($v, 'd M Y') : '—';
    $fechaHora = fn ($v) => $v ? core()->formatDate($v, 'd M Y H:i') : '—';
    $ultimaEntrega = $entregas->filter->vigente()->sortByDesc('fecha_entrega')->first();
    $skus = collect($items);
@endphp

@once
    @push('styles')
        @include('logistica::admin.estilos')
    @endpush
@endonce

<x-admin::layouts>
    <x-slot:title>
        @lang('logistica::app.entregas.envio-titulo', ['envio' => $envio->id, 'pedido' => $envio->increment_id])
    </x-slot>

    <div class="lg-page" data-envio="{{ $envio->id }}">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <a href="{{ route('admin.logistica.entregas.index') }}" class="lg-sub">← @lang('logistica::app.entregas.volver')</a>
                <h1 class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">@lang('logistica::app.entregas.envio-titulo', ['envio' => $envio->id, 'pedido' => $envio->increment_id])</h1>
                <p class="lg-sub">{{ $envio->cliente }} · @lang('logistica::app.entregas.despachado-el', ['fecha' => $fecha($envio->created_at)])</p>
            </div>

            @if ($permisos['registrar'] && $pendientes > 0)
                <div class="lg-acciones">
                    <a href="{{ route('admin.logistica.entregas.crear', $envio->id) }}" class="lg-btn lg-btn--ok" data-accion="registrar-entrega">@lang('logistica::app.entregas.registrar-entrega')</a>
                    <a href="{{ route('admin.logistica.retornos.crear', $envio->id) }}" class="lg-btn lg-btn--aviso" data-accion="registrar-retorno">@lang('logistica::app.entregas.registrar-retorno')</a>
                </div>
            @endif
        </div>

        @include('logistica::admin.flash')

        {{-- Estados: la ENTREGA (derivada de lo registrado) y la VALIDACION ByLopez son cosas distintas y se muestran separadas. --}}
        <div class="lg-estados mt-4" data-estados>
            <div class="lg-estado"><span class="lg-estado-tit">@lang('logistica::app.entregas.lbl-entrega')</span>@include('logistica::admin.entregas.estado-entrega', ['estado' => $estado_entrega])</div>
            <div class="lg-estado"><span class="lg-estado-tit">ByLopez</span>@include('logistica::admin.entregas.estado-validacion', ['estado' => $validacion])<span class="lg-sub">{{ trans('logistica::app.entregas.val-ayuda.'.$validacion) }}</span></div>
        </div>

        <h2 class="lg-seccion">@lang('logistica::app.entregas.sec-resumen')</h2>
        @include('logistica::admin.entregas.cifras', ['clase' => 'lg-stats--grande', 'despachadas' => $resumen['despachadas'], 'entregadas' => $resumen['entregadas'], 'retornadas' => $resumen['retornadas'], 'pendientes' => $pendientes])
        @if ($pendientes === 0)
            <p class="lg-sub mt-2">@lang('logistica::app.entregas.completo')</p>
        @endif

        <h2 class="lg-seccion">@lang('logistica::app.entregas.sec-general')</h2>
        <dl class="lg-panel lg-info" data-general>
            <div><dt>@lang('logistica::app.entregas.pedido-l')</dt><dd>#{{ $envio->increment_id }}</dd></div>
            <div><dt>@lang('logistica::app.entregas.envio-l')</dt><dd>#{{ $envio->id }}</dd></div>
            <div><dt>@lang('logistica::app.entregas.despachado-l')</dt><dd>{{ $fecha($envio->created_at) }}</dd></div>
            <div class="lg-span2"><dt>@lang('logistica::app.entregas.col-cliente-d')</dt><dd>{{ $envio->cliente ?: '—' }}</dd></div>
            <div><dt>@lang('logistica::app.entregas.telefono')</dt><dd>{{ $contacto['telefono'] ?: '—' }}</dd></div>
            <div class="lg-span2"><dt>@lang('logistica::app.entregas.direccion')</dt><dd>{{ $contacto['direccion'] ?: '—' }}</dd></div>
            <div><dt>@lang('logistica::app.entregas.destino')</dt><dd>{{ $contacto['destino'] ?: '—' }}</dd></div>
            <div><dt>@lang('logistica::app.entregas.col-transportadora')</dt><dd>@include('logistica::admin.entregas.transportadora', ['codigo' => $envio->carrier_code, 'nombre' => $transportadora, 'titulo' => $envio->carrier_title])</dd></div>
            <div><dt>@lang('logistica::app.entregas.col-guia')</dt><dd>{{ $guia->numero_guia ?? ($envio->track_number ?: trans('logistica::app.entregas.sin-guia')) }}</dd></div>
            <div><dt>@lang('logistica::app.entregas.tracking')</dt><dd>{{ ($guia->tracking ?? null) ?: '—' }}</dd></div>
            <div><dt>@lang('logistica::app.entregas.metodo-envio')</dt><dd>{{ $envio->shipping_title ?: $envio->shipping_method }}</dd></div>
            <div><dt>@lang('logistica::app.entregas.bodega')</dt><dd>{{ $envio->inventory_source_name ?: '—' }}</dd></div>
            <div><dt>@lang('logistica::app.entregas.ultima-entrega')</dt><dd>{{ $ultimaEntrega ? $fecha($ultimaEntrega->fecha_entrega) : '—' }}</dd></div>
        </dl>

        @if ($cita_recogida)
            <p class="lg-panel mt-3 p-3 text-sm" data-cita-recogida>
                <span class="lg-pill lg-pill--gris">@lang('logistica::app.entregas.cita-recogida', ['fecha' => $fecha($cita_recogida->pickup_date), 'desde' => substr((string) $cita_recogida->start_time, 0, 5), 'hasta' => substr((string) $cita_recogida->end_time, 0, 5)])</span>
            </p>
        @endif

        {{-- Aqui SI van las tallas: producto (linea × color) y una fila por talla con sus cantidades. --}}
        <h2 class="lg-seccion">@lang('logistica::app.entregas.sec-productos')</h2>
        <div class="lg-prods" data-productos>
            @foreach ($productos as $p)
                <div class="lg-panel p-4" data-producto="{{ $p['clave'] }}">
                    <div class="lg-prod-cab">
                        <p class="font-bold text-gray-900 dark:text-white">{{ $p['nombre'] }}</p>
                        <p class="lg-sub">{{ $p['sku'] }}@if ($p['color']) · @lang('logistica::app.entregas.color'): {{ $p['color'] }}@endif</p>
                    </div>
                    <div class="lg-tallas">
                        @foreach ($p['filas'] as $f)
                            <div class="lg-talla {{ $f['pendientes'] > 0 ? 'lg-talla--pend' : '' }}" data-item="{{ $f['shipment_item_id'] }}">
                                <b>{{ $f['talla'] }}</b>
                                <span><small>@lang('logistica::app.entregas.abr-desp')</small>{{ $f['despachadas'] }}</span>
                                <span><small>@lang('logistica::app.entregas.abr-entr')</small>{{ $f['entregadas'] }}</span>
                                <span><small>@lang('logistica::app.entregas.abr-ret')</small>{{ $f['retornadas'] }}</span>
                                <span><small>@lang('logistica::app.entregas.abr-pend')</small>{{ $f['pendientes'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        <h2 class="lg-seccion">@lang('logistica::app.entregas.sec-trazabilidad')</h2>
        @foreach (['entregas' => $entregas, 'retornos' => $retornos] as $tipo => $registros)
            <h3 class="mt-4 text-sm font-bold text-gray-900 dark:text-white">@lang('logistica::app.entregas.historial-'.$tipo)</h3>

            @if ($registros->isEmpty())
                <p class="lg-sub mt-2">@lang('logistica::app.entregas.sin-'.$tipo)</p>
            @endif

            <div class="lg-grid2 mt-2">
                @foreach ($registros as $r)
                    @php($esEntrega = $tipo === 'entregas')
                    <div class="lg-panel p-4" data-{{ $esEntrega ? 'entrega' : 'retorno' }}="{{ $r->id }}">
                        <div class="flex items-start justify-between gap-2">
                            <p class="font-bold text-gray-900 dark:text-white">@lang('logistica::app.entregas.'.($esEntrega ? 'entrega-n' : 'retorno-n'), ['id' => $r->id])</p>
                            <span class="lg-pill lg-pill--punto {{ $r->vigente() ? 'lg-pill--verde' : 'lg-pill--gris' }}">@lang('logistica::app.entregas.'.($r->vigente() ? 'vigente' : 'anulado'))</span>
                        </div>
                        <p class="mt-1 text-sm"><span class="lg-caption">@lang('logistica::app.entregas.fecha-real')</span> {{ $fecha($esEntrega ? $r->fecha_entrega : $r->fecha_retorno) }}</p>
                        <ul class="mt-2 text-sm">
                            @foreach ($r->items as $ri)
                                @php($it = $skus[$ri->shipment_item_id] ?? null)
                                <li>{{ $it['sku'] ?? '#'.$ri->shipment_item_id }} — @lang('logistica::app.entregas.unidades-n', ['cantidad' => $ri->cantidad])</li>
                            @endforeach
                        </ul>
                        @unless ($esEntrega)
                            <p class="mt-2 text-sm"><span class="lg-caption">@lang('logistica::app.entregas.motivo')</span> {{ $r->motivo }}</p>
                        @endunless
                        <p class="lg-sub mt-2">@lang('logistica::app.entregas.fuente'): @lang('logistica::app.fuentes.'.$r->fuente->value) · {{ $r->carrier_code_snapshot ?? '—' }}</p>
                        @if ($r->evidencia_referencia)
                            <p class="lg-sub">@lang('logistica::app.entregas.evidencia'): {{ $r->evidencia_referencia }}</p>
                        @endif
                        <p class="lg-sub">@lang('logistica::app.entregas.registrado', ['nombre' => $r->actor_name_snapshot, 'fecha' => $fechaHora($r->created_at)])</p>

                        @if (! $r->vigente())
                            <p class="mt-2 text-sm text-rose-700 dark:text-rose-300">@lang('logistica::app.entregas.anulado-por', ['nombre' => $r->anulada_por_name_snapshot, 'fecha' => $fechaHora($r->anulada_en), 'motivo' => $r->motivo_anulacion])</p>
                        @elseif ($permisos['anular'])
                            <form method="POST" action="{{ route($esEntrega ? 'admin.logistica.entregas.anular' : 'admin.logistica.retornos.anular', $r->id) }}" class="mt-3 flex flex-wrap gap-2" onsubmit="return confirm(@js(trans('logistica::app.entregas.anular-confirmar')))">
                                @csrf
                                <input type="text" name="motivo" required maxlength="500" placeholder="{{ trans('logistica::app.entregas.anular-motivo') }}" class="lg-input" style="flex: 1 1 200px">
                                <button type="submit" class="lg-btn lg-btn--no">@lang('logistica::app.entregas.anular')</button>
                            </form>
                        @endif
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>
</x-admin::layouts>