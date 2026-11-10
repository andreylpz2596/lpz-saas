{{--
    «Sugerencia por stock» de una variante (SugerenciaStockService). Solo informa: nunca cambia la prioridad asignada.
    $s: sugerencia o null. $conCifras: mostrar disponible/minimo (solo personal con acceso a inventario; un taller ve el
    nivel y el motivo). $enlaceRecepcion: url al detalle de lo pendiente de recibir (solo administracion) o null.
--}}
@php
    $nivel = $s['nivel'] ?? \ByLopez\CorteConfeccion\Services\SugerenciaStockService::SIN_INFORMACION;
    $estilo = [
        'urgente' => 'border-red-300 bg-red-50 text-red-700 dark:border-red-800 dark:bg-red-950 dark:text-red-300',
        'alta' => 'border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-300',
        'normal' => 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    ][$nivel] ?? 'border-dashed border-gray-300 text-gray-600 dark:border-gray-700 dark:text-gray-300';
    $conCifras = $conCifras ?? false;
@endphp
<span class="inline-flex min-w-0 flex-col gap-0.5" data-sugerencia="{{ $nivel }}">
    <span class="inline-flex flex-wrap items-center gap-1 text-xs">
        <span class="whitespace-nowrap rounded-full border px-2 py-0.5 font-semibold {{ $estilo }}">{{ \ByLopez\CorteConfeccion\Services\SugerenciaStockService::etiqueta($nivel) }}</span>
        <span class="text-gray-600 dark:text-gray-300">{{ $s['motivo'] ?? 'Sin información de inventario' }}</span>
    </span>
    @if ($conCifras && $s)
        <span class="text-[11px] text-gray-500 dark:text-gray-400" data-sugerencia-detalle>{{ $s['detalle'] }}</span>
    @endif
    @if ($s && $s['priorizar_recepcion'])
        <span class="text-[11px] font-semibold text-amber-800 dark:text-amber-300" data-priorizar-recepcion>
            @if (! empty($enlaceRecepcion))
                <a href="{{ $enlaceRecepcion }}" class="underline hover:no-underline">Priorizar recepción · {{ $s['pendiente_recibir'] }} {{ $s['pendiente_recibir'] === 1 ? 'prenda pendiente' : 'prendas pendientes' }}</a>
            @else
                Priorizar recepción · {{ $s['pendiente_recibir'] }} {{ $s['pendiente_recibir'] === 1 ? 'prenda pendiente' : 'prendas pendientes' }}
            @endif
        </span>
    @endif
</span>
