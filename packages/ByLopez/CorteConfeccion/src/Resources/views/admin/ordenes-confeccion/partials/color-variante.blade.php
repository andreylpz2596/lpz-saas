{{--
    Swatch + nombre del color REAL de la variante (atributo color de Bagisto).
    Recibe: $color (etiqueta o null) y $hex (color del swatch o null; gris si no hay).
--}}
@if ($color)
    <span class="inline-flex items-center gap-1.5 rounded-full border bg-white px-2 py-0.5 text-xs text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" data-testid="color-variante">
        <span class="inline-block h-3.5 w-3.5 shrink-0 rounded-full border border-black/20" style="background-color: {{ $hex ?: '#e5e7eb' }}" title="{{ $hex ?: 'Sin color registrado' }}"></span>
        <span class="font-medium">{{ $color }}</span>
    </span>
@else
    <span class="text-gray-400">-</span>
@endif
