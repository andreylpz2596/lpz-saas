@php
    $esEntrega = $modo === 'entrega';
    $titulo = trans('logistica::app.entregas.'.($esEntrega ? 'form-entrega-titulo' : 'form-retorno-titulo'));
    $conPendientes = array_filter($items, fn ($i) => $i['pendientes'] > 0);
@endphp

@once
    @push('styles')
        @include('logistica::admin.estilos')
    @endpush
@endonce

<x-admin::layouts>
    <x-slot:title>
        {{ $titulo }}
    </x-slot>

    <div class="lg-page" data-formulario="{{ $modo }}">
        <a href="{{ route('admin.logistica.entregas.index', ['envio' => $envio->id]) }}" class="lg-sub">← @lang('logistica::app.entregas.envio-titulo', ['envio' => $envio->id, 'pedido' => $envio->increment_id])</a>
        <h1 class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">{{ $titulo }}</h1>
        <p class="lg-sub mt-1">@lang('logistica::app.entregas.'.($esEntrega ? 'form-entrega-ayuda' : 'form-retorno-ayuda'))</p>

        @include('logistica::admin.flash')

        <div class="lg-panel mt-4 grid grid-cols-2 gap-3 p-4 text-sm md:grid-cols-4">
            <div><p class="lg-caption">@lang('logistica::app.entregas.col-pedido')</p>#{{ $envio->increment_id }}</div>
            <div><p class="lg-caption">@lang('logistica::app.entregas.col-cliente')</p>{{ $envio->cliente }}</div>
            <div><p class="lg-caption">@lang('logistica::app.entregas.col-transportadora')</p>@include('logistica::admin.entregas.transportadora', ['codigo' => $envio->carrier_code, 'nombre' => $transportadora, 'titulo' => $envio->carrier_title])</div>
            <div><p class="lg-caption">@lang('logistica::app.entregas.col-guia')</p>{{ $envio->track_number ?: trans('logistica::app.entregas.sin-guia') }}</div>
        </div>

        <form method="POST" action="{{ route($esEntrega ? 'admin.logistica.entregas.store' : 'admin.logistica.retornos.store', $envio->id) }}"
              class="lg-panel mt-4 p-4" data-form="{{ $modo }}"
              onsubmit="if (this.dataset.enviado) return false; if (! confirm(@js(trans('logistica::app.entregas.form-confirmar')))) return false; this.dataset.enviado = 1; return true;">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
            <input type="hidden" name="order_id" value="{{ $envio->order_id }}">

            <div class="lg-grid2">
                <div>
                    <label for="f-fecha" class="lg-caption">@lang('logistica::app.entregas.'.($esEntrega ? 'form-fecha-entrega' : 'form-fecha-retorno'))</label>
                    <input id="f-fecha" type="date" name="fecha" required value="{{ old('fecha', now()->toDateString()) }}" max="{{ now()->toDateString() }}" min="{{ \Illuminate\Support\Carbon::parse($envio->created_at)->toDateString() }}" class="lg-input">
                </div>

                @if (! $envio->carrier_code)
                    <div>
                        <label for="f-transportadora" class="lg-caption">@lang('logistica::app.entregas.form-transportadora')</label>
                        <select id="f-transportadora" name="carrier_code" required class="lg-input">
                            <option value="">@lang('logistica::app.entregas.form-transportadora-elegir')</option>
                            @foreach ($transportadoras as $t)
                                <option value="{{ $t->codigo }}" @selected(old('carrier_code') === $t->codigo)>{{ $t->nombre }}</option>
                            @endforeach
                        </select>
                        <p class="lg-sub mt-1">@lang('logistica::app.entregas.form-transportadora-ayuda', ['texto' => $envio->carrier_title ?: trans('logistica::app.transportadoras.vacio')])</p>
                    </div>
                @endif
            </div>

            <div class="mt-4 overflow-x-auto">
                <table class="lg-table">
                    <thead>
                        <tr>
                            <th>@lang('logistica::app.entregas.col-sku')</th>
                            <th>@lang('logistica::app.entregas.col-producto')</th>
                            <th class="lg-num">@lang('logistica::app.entregas.col-despachadas')</th>
                            <th class="lg-num">@lang('logistica::app.entregas.col-pendientes')</th>
                            <th style="width: 130px">@lang('logistica::app.entregas.form-cantidad')</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($conPendientes as $i)
                            <tr>
                                <td class="font-semibold">{{ $i['sku'] }}</td>
                                <td class="text-xs">{{ $i['nombre'] }}</td>
                                <td class="lg-num">{{ $i['despachadas'] }}</td>
                                <td class="lg-num">{{ $i['pendientes'] }}</td>
                                <td>
                                    <input type="number" name="items[{{ $i['shipment_item_id'] }}]" min="0" max="{{ $i['pendientes'] }}" step="1"
                                           value="{{ old('items.'.$i['shipment_item_id'], $esEntrega ? $i['pendientes'] : 0) }}"
                                           data-pendientes="{{ $i['pendientes'] }}" class="lg-input lg-num" aria-label="{{ $i['sku'] }}">
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-2 flex gap-2">
                <button type="button" class="lg-btn" onclick="this.form.querySelectorAll('[data-pendientes]').forEach(i => i.value = i.dataset.pendientes)">@lang('logistica::app.entregas.form-todo')</button>
                <button type="button" class="lg-btn" onclick="this.form.querySelectorAll('[data-pendientes]').forEach(i => i.value = 0)">@lang('logistica::app.entregas.form-nada')</button>
            </div>

            <div class="lg-grid2 mt-4">
                @unless ($esEntrega)
                    <div>
                        <label for="f-motivo" class="lg-caption">@lang('logistica::app.entregas.form-motivo-retorno')</label>
                        <input id="f-motivo" type="text" name="motivo" required maxlength="500" value="{{ old('motivo') }}" placeholder="{{ trans('logistica::app.entregas.form-motivo-retorno-placeholder') }}" class="lg-input">
                    </div>
                @endunless
                <div>
                    <label for="f-evidencia" class="lg-caption">@lang('logistica::app.entregas.form-evidencia')</label>
                    <input id="f-evidencia" type="text" name="evidencia_referencia" maxlength="500" value="{{ old('evidencia_referencia') }}" class="lg-input">
                    <p class="lg-sub mt-1">@lang('logistica::app.entregas.form-evidencia-ayuda')</p>
                </div>
            </div>

            <div class="mt-5 flex flex-wrap justify-end gap-2">
                <a href="{{ route('admin.logistica.entregas.index', ['envio' => $envio->id]) }}" class="lg-btn">@lang('logistica::app.entregas.form-cancelar')</a>
                <button type="submit" class="lg-btn {{ $esEntrega ? 'lg-btn--ok' : 'lg-btn--aviso' }}">@lang('logistica::app.entregas.'.($esEntrega ? 'form-guardar-entrega' : 'form-guardar-retorno'))</button>
            </div>
        </form>
    </div>
</x-admin::layouts>
