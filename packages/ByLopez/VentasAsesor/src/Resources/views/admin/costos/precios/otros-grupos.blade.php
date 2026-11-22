{{-- Fragmento bajo demanda: precio de una variante en cada grupo (comparacion secundaria). Sin layout. --}}
@php use ByLopez\VentasAsesor\Support\Precios\FormatoPrecioGrupo as F; @endphp
<ul class="pg-escalones" data-otros-grupos>
    @forelse ($filas as $o)
        <li style="display: flex; justify-content: space-between; gap: 12px" @if ($o['actual']) aria-current="true" @endif>
            <span @class(['pg-fuerte' => $o['actual']])>{{ $o['nombre'] }}</span>
            <span><span class="pg-fuerte">{{ F::dinero($o['precio']) }}</span> <span class="va-sub">{{ F::origen($o['origen']) }}</span></span>
        </li>
    @empty
        <li class="pg-muted">—</li>
    @endforelse
</ul>
