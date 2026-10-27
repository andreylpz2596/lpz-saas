@php
    $dinero = fn ($v) => $v === null ? '—' : \ByLopez\Logistica\Support\Moneda::formatear((float) $v);
    $label = 'mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400';
    $input = 'w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300';
    $caja = function (string $clave, ?string $valor, ?string $nota = null, string $clase = '') use ($dinero) {
        return '<div class="va-panel va-kpi-box"><p class="va-kpi-label">'.e(trans('ventasasesor::comisiones.panel.'.$clave)).'</p>'
            .'<p class="va-kpi-value '.$clase.'">'.e($valor === null ? trans('ventasasesor::comisiones.panel.incompleto') : $dinero($valor)).'</p>'
            .($nota ? '<p class="va-sub">'.e($nota).'</p>' : '').'</div>';
    };
@endphp

@once
    @push('styles')
        @include('ventasasesor::admin.cartera.estilos')
    @endpush
@endonce

<x-admin::layouts>
    <x-slot:title>@lang('ventasasesor::comisiones.rentabilidad.tabs.panel')</x-slot>

    <div class="va-orders-page bylopez-scope">
        @include('ventasasesor::admin.rentabilidad.encabezado')

        <form method="GET" action="{{ route('admin.ventas_asesor.rentabilidad.index') }}" class="va-panel mt-4 flex flex-wrap items-end gap-3 p-4">
            <input type="hidden" name="vista" value="panel">
            <div>
                <label class="{{ $label }}" for="f-mes">@lang('ventasasesor::comisiones.mes-comercial')</label>
                <input id="f-mes" type="month" name="mes" value="{{ $mes }}" class="{{ $input }}">
            </div>
            <button type="submit" class="secondary-button">@lang('ventasasesor::comisiones.filtrar')</button>
        </form>

        <p class="mt-4 text-xs font-semibold uppercase tracking-wide text-gray-500">@lang('ventasasesor::comisiones.panel.por-venta', ['pedidos' => $datos['pedidos']])</p>
        <div class="mt-2 grid grid-cols-2 gap-3 xl:grid-cols-4" data-seccion="por-venta">
            {!! $caja('ventas-netas', $datos['ventas_netas']) !!}
            {!! $caja('costo-vendido', $datos['costo_vendido'], trim(trans('ventasasesor::comisiones.panel.costo-estandar').' '.($datos['lineas_sin_costo'] > 0 ? trans('ventasasesor::comisiones.panel.lineas-sin-costo', ['n' => $datos['lineas_sin_costo']]) : ''))) !!}
            {!! $caja('comision-estimada', $datos['comision_estimada'], trans('ventasasesor::comisiones.panel.habilitada', ['monto' => $dinero($datos['comision_habilitada'])])) !!}
            {!! $caja('costos-directos', $datos['costos_directos_estimados'], trans('ventasasesor::comisiones.panel.reales', ['monto' => $dinero($datos['costos_directos_reales']), 'n' => $datos['pedidos_con_evaluacion']])) !!}
        </div>

        <div class="mt-3 grid grid-cols-1 gap-3 xl:grid-cols-3" data-seccion="resultado">
            {!! $caja('contribucion', $datos['contribucion_estimada'], $datos['contribucion_parcial'] ? trans('ventasasesor::comisiones.panel.parcial') : null) !!}
            {!! $caja('gastos-generales', $datos['gastos_generales'], trans('ventasasesor::comisiones.panel.sin-fuente-gastos'), 'text-amber-600') !!}
            {!! $caja('resultado-operativo', $datos['resultado_operativo'], trans('ventasasesor::comisiones.panel.no-disponible'), 'text-amber-600') !!}
        </div>

        <p class="mt-4 text-xs font-semibold uppercase tracking-wide text-gray-500">@lang('ventasasesor::comisiones.panel.por-recepcion')</p>
        <div class="mt-2 grid grid-cols-1 gap-3 md:grid-cols-3" data-seccion="recaudo">
            {!! $caja('recaudo-transportadoras', $datos['recaudo_transportadoras'], trans('ventasasesor::comisiones.panel.desglose-transportadoras', [
                'n' => $datos['liquidaciones'], 'servicios' => $dinero($datos['servicios_transportadoras']), 'ajustes' => $dinero($datos['ajustes_transportadoras']),
                'diferencia' => $dinero($datos['diferencias_transportadoras']), 'neto' => $dinero($datos['neto_transportadoras']),
            ])) !!}
            {!! $caja('recaudo-directo', $datos['recaudo_directo']) !!}
            {!! $caja('cobros-en-terceros', $datos['cobros_en_terceros'], trans('ventasasesor::comisiones.panel.cobros-en-terceros-ayuda')) !!}
        </div>

        <div class="va-panel mt-4 p-3 text-xs leading-5 text-slate-600 dark:text-gray-300" data-seccion="falta-medir">
            <p class="font-semibold">@lang('ventasasesor::comisiones.panel.falta-medir')</p>
            <ul class="ml-4 list-disc">
                @foreach (trans('ventasasesor::comisiones.panel.falta-medir-lista') as $item)
                    <li>{{ $item }}</li>
                @endforeach
            </ul>
        </div>

        <p class="va-panel mt-4 p-3 text-xs leading-5 text-slate-600 dark:text-gray-300">@lang('ventasasesor::comisiones.panel.ayuda')</p>
    </div>
</x-admin::layouts>
