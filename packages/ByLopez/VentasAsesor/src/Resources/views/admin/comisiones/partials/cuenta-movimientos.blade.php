{{-- Historial de movimientos de la cuenta (abonos, reversos y compensaciones). Espera $cuentaAsesor y $permisos. --}}
@php
    $dinCm = fn ($v) => \ByLopez\Logistica\Support\Moneda::formatear((float) $v);
    $tonoMov = ['abono' => 'verde', 'reverso' => 'rojo', 'compensacion' => 'cian'];
@endphp

<section class="va-panel mt-4 p-4" data-seccion="movimientos" aria-labelledby="va-mov-titulo">
    <h2 id="va-mov-titulo" class="text-base font-semibold text-gray-900 dark:text-white">@lang('ventasasesor::comisiones.cuenta.movimientos-titulo')</h2>
    <p class="va-sub">@lang('ventasasesor::comisiones.cuenta.movimientos-ayuda')</p>

    @forelse ($cuentaAsesor['movimientos'] as $m)
        <div class="va-ped__fila mt-3 border-t border-slate-100 pt-3 dark:border-gray-800" data-movimiento="{{ $m['tipo'] }}" style="grid-template-columns: 110px 120px 130px minmax(160px,1.3fr) minmax(160px,1.5fr) 110px">
            <div><span class="va-ped__rot">@lang('ventasasesor::comisiones.cuenta.col-fecha')</span>{{ $m['fecha'] }}</div>
            <div>
                <span class="va-ped__rot">@lang('ventasasesor::comisiones.cuenta.col-tipo')</span>
                <span class="va-pill va-pill--sm va-pill--{{ $tonoMov[$m['tipo']] ?? 'gris' }}"><span>@lang('ventasasesor::comisiones.cuenta.tipos.'.$m['tipo'])</span></span>
                @if ($m['reversado'])<span class="va-sub block">@lang('ventasasesor::comisiones.cuenta.reversado-marca')</span>@endif
            </div>
            <div><span class="va-ped__rot">@lang('ventasasesor::comisiones.cuenta.col-valor')</span><strong>{{ $m['tipo'] === 'reverso' ? '−' : '' }}{{ $dinCm($m['monto']) }}</strong></div>
            <div>
                <span class="va-ped__rot">@lang('ventasasesor::comisiones.cuenta.col-medio')</span>
                {{ trim(($m['medio'] ?? '').' '.($m['referencia'] ?? '')) ?: '—' }}
                @if ($m['observacion'])<span class="va-sub block">{{ $m['observacion'] }}</span>@endif
            </div>
            <div>
                <span class="va-ped__rot">@lang('ventasasesor::comisiones.cuenta.col-aplicado')</span>
                {{ $m['liquidacion'] ?? '—' }}
                @if ($m['pedidos'] !== [])<span class="va-sub block">@lang('ventasasesor::comisiones.cuenta.pedidos'): {{ '#'.implode(', #', $m['pedidos']) }}</span>@endif
            </div>
            <div>
                <span class="va-ped__rot">@lang('ventasasesor::comisiones.cuenta.col-soporte')</span>
                @if ($m['soporte'] && ($permisos['comprobantes'] ?? false) && $m['tipo'] !== 'compensacion')
                    <a href="{{ route('admin.ventas_asesor.comisiones.abonos.comprobante', $m['id']) }}" class="text-blue-600 hover:underline" target="_blank" rel="noopener">@lang('ventasasesor::comisiones.cuenta.ver-soporte')</a>
                @else
                    —
                @endif
            </div>
        </div>
    @empty
        <p class="va-sub mt-2" data-vacio>@lang('ventasasesor::comisiones.cuenta.sin-movimientos')</p>
    @endforelse
</section>
