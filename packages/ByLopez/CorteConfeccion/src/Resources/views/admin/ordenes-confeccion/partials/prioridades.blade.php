{{-- Reparto por prioridad de una cantidad por confeccionar. Recibe $p = ['urgente' => n, 'alta' => n, 'normal' => n]. --}}
<span class="inline-flex flex-wrap items-center gap-1">
    @if ($p['urgente'] > 0)
        <span class="label-canceled whitespace-nowrap">Urgente {{ $p['urgente'] }}</span>
    @endif
    @if ($p['alta'] > 0)
        <span class="label-pending whitespace-nowrap">Alta {{ $p['alta'] }}</span>
    @endif
    @if ($p['normal'] > 0 && ($conNormal ?? true))
        <span class="label-info whitespace-nowrap">Normal {{ $p['normal'] }}</span>
    @endif
    @if ($p['urgente'] + $p['alta'] + $p['normal'] === 0)
        <span class="text-xs text-gray-400">—</span>
    @endif
</span>
