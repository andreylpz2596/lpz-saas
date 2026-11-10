{{-- Resumen de cuenta para la ficha del prestador. Sin scripts: se incluye dentro de una plantilla de Vue. --}}
@if (! empty($carteraResumen))
    @php
        $fmR = fn ($v) => \ByLopez\CorteConfeccion\Support\Dinero::formatear((string) $v);
        $r = $carteraResumen;
    @endphp

    <div class="box-shadow min-w-0 overflow-hidden rounded bg-white p-4 dark:bg-gray-900">
        <div class="flex items-center justify-between gap-2">
            <p class="text-base font-semibold leading-none text-gray-800 dark:text-white">{{ $r['propia'] ? 'Mi cuenta' : 'Cuenta' }}</p>
            <a href="{{ $r['url'] }}" class="text-sm font-semibold text-blue-600 hover:underline">Ver cuenta completa</a>
        </div>

        @if (! $r['iniciada'])
            <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">La cartera todavía no tiene fecha de arranque.</p>
        @else
            <div class="mt-4 grid gap-1">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ $r['a_favor'] ? 'Saldo a favor de ByLopez' : 'Faltaría por pagar · estimado' }}
                </p>
                <p class="text-2xl font-bold tabular-nums text-gray-900 dark:text-white">{{ $fmR($r['a_favor'] ? \ByLopez\CorteConfeccion\Support\Dinero::absoluto($r['por_pagar_estimado']) : $r['por_pagar_estimado']) }}</p>
                @if (! $r['a_favor'] && \ByLopez\CorteConfeccion\Support\Dinero::esPositivo($r['trabajo_pendiente']))
                    <p class="text-sm text-gray-600 dark:text-gray-300">{{ $fmR($r['por_pagar_recibido']) }} por lo ya recibido · {{ number_format($r['cantidad_pendiente'], 0, ',', '.') }} pendientes de recibir</p>
                @endif
                @if (\ByLopez\CorteConfeccion\Support\Dinero::esPositivo($r['prestamo_pendiente']))
                    <p class="text-sm text-gray-600 dark:text-gray-300">Préstamo pendiente: <b>{{ $fmR($r['prestamo_pendiente']) }}</b></p>
                @endif
                @if (! empty($r['sin_tarifa']))
                    <p class="text-xs text-amber-700">Importe parcial: faltan por valorar {{ implode(', ', $r['sin_tarifa']) }}.</p>
                @endif
            </div>
        @endif
    </div>
@endif
