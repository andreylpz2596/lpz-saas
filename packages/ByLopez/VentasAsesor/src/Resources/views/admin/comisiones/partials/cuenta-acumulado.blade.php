{{-- Saldo ACUMULADO de la cuenta (todos los meses). Espera $cuentaAsesor (CuentaAsesorService::deAsesor). No depende del filtro de mes. --}}
@php
    $dinCa = fn ($v) => \ByLopez\Logistica\Support\Moneda::formatear((float) $v);
    $ac = $cuentaAsesor['acumulado'];
    $pg = $ac['pagos'];
@endphp

<section class="mt-4" data-seccion="cuenta-acumulada" aria-labelledby="va-acum-titulo">
    <h2 id="va-acum-titulo" class="text-base font-semibold text-gray-900 dark:text-white">@lang('ventasasesor::comisiones.cuenta.acumulado-titulo')</h2>
    <p class="va-sub">@lang('ventasasesor::comisiones.cuenta.acumulado-ayuda')</p>

    <div class="mt-2 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
        {{-- 1. Saldo de liquidaciones aprobadas: pagable y retenido por separado. --}}
        <div class="va-panel va-kpi-box" data-kpi="saldo-aprobado">
            <p class="va-kpi-label">@lang('ventasasesor::comisiones.cuenta.saldo-aprobado')</p>
            <p class="va-kpi-value">{{ $dinCa($ac['saldo_aprobado']) }}</p>
            <p class="va-sub">@lang('ventasasesor::comisiones.cuenta.pagable'): <strong data-kpi="pagable">{{ $dinCa($ac['pagable']) }}</strong></p>
            <p class="va-sub {{ (float) $ac['retenido'] > 0 ? 'text-red-600' : '' }}">@lang('ventasasesor::comisiones.cuenta.retenido'): <strong data-kpi="retenido">{{ $dinCa($ac['retenido']) }}</strong></p>
        </div>

        {{-- 2. Habilitada y todavia sin liquidar. --}}
        <div class="va-panel va-kpi-box" data-kpi="habilitada-sin-liquidar">
            <p class="va-kpi-label">@lang('ventasasesor::comisiones.cuenta.habilitada-sin-liquidar')</p>
            <p class="va-kpi-value">{{ $dinCa($ac['habilitada_sin_liquidar']) }}</p>
            <p class="va-sub">@lang('ventasasesor::comisiones.cuenta.habilitada-sin-liquidar-ayuda')</p>
            @if ((float) $ac['habilitada_en_borrador'] > 0)
                <p class="va-sub">@lang('ventasasesor::comisiones.cuenta.en-borrador'): <strong>{{ $dinCa($ac['habilitada_en_borrador']) }}</strong></p>
            @endif
            @if ((float) $ac['habilitada_en_revision'] > 0)
                <p class="va-sub text-amber-600">@lang('ventasasesor::comisiones.cuenta.en-revision'): <strong>{{ $dinCa($ac['habilitada_en_revision']) }}</strong></p>
            @endif
        </div>

        {{-- 3. Estimacion pendiente de condiciones (todavia no es dinero disponible). --}}
        <div class="va-panel va-kpi-box" data-kpi="estimacion-pendiente">
            <p class="va-kpi-label">@lang('ventasasesor::comisiones.cuenta.estimacion')</p>
            <p class="va-kpi-value">{{ $dinCa($ac['estimacion_pendiente']) }}</p>
            <p class="va-sub">@lang('ventasasesor::comisiones.cuenta.estimacion-ayuda')</p>
            @if ($ac['estimacion_sin_calcular'] > 0)
                <p class="va-sub text-amber-600">@lang('ventasasesor::comisiones.cuenta.estimacion-sin-calcular', ['n' => $ac['estimacion_sin_calcular']])</p>
            @endif
        </div>

        {{-- 4. Pagos realizados: abonos, reversos y compensaciones, diferenciados. --}}
        <div class="va-panel va-kpi-box" data-kpi="pagos">
            <p class="va-kpi-label">@lang('ventasasesor::comisiones.cuenta.pagos-titulo')</p>
            <p class="va-kpi-value">{{ $dinCa($pg['neto_pagado']) }}</p>
            <p class="va-sub">@lang('ventasasesor::comisiones.cuenta.abonado'): <strong data-kpi="abonado">{{ $dinCa($pg['abonado']) }}</strong></p>
            <p class="va-sub">@lang('ventasasesor::comisiones.cuenta.reversado'): <strong data-kpi="reversado">{{ $dinCa($pg['reversado']) }}</strong></p>
            <p class="va-sub">@lang('ventasasesor::comisiones.cuenta.compensado'): <strong data-kpi="compensado">{{ $dinCa($pg['compensado']) }}</strong></p>
        </div>
    </div>
</section>
