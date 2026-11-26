{{-- Tarjeta de Ventas Asesor dentro de la ficha nativa del cliente (hook de Bagisto). Las cifras vienen de CarteraConsultaService y CupoCarteraClienteService. --}}
@php
    $tonoCupoFicha = ['sin_cupo' => 'gris', 'activo' => 'verde', 'cerca' => 'amarillo', 'agotado' => 'rojo', 'excedido' => 'rojo', 'bloqueado' => 'rojo'];
@endphp
<div class="box-shadow flex flex-wrap items-center justify-between gap-3 rounded bg-white p-4 dark:bg-gray-900" data-tarjeta-cartera>
    <div>
        <p class="text-base font-semibold text-gray-800 dark:text-white">@lang('ventasasesor::app.cartera.bloque.titulo')</p>

        @if ($cartera->carteraActiva)
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                @lang('ventasasesor::app.cartera.cliente.deuda-total'):
                <span class="font-bold text-gray-900 dark:text-white">{{ \ByLopez\Logistica\Support\Moneda::formatear($cartera->carteraTotal) }}</span>
                &middot; @lang('ventasasesor::app.cartera.cliente.sin-revisar'): {{ \ByLopez\Logistica\Support\Moneda::formatear($cartera->sinRevisarTotal()) }}
            </p>
        @else
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">@lang('ventasasesor::app.cartera.bloque.inactiva')</p>
        @endif

        {{-- Doc. 35: cupo de cartera (una linea; el detalle y las acciones estan en «Ver cartera»). --}}
        @if (! empty($cupo))
            @php $tono = $tonoCupoFicha[$cupo->estado] ?? 'gris'; @endphp
            <p class="mt-2 flex flex-wrap items-center gap-2 text-sm text-gray-600 dark:text-gray-300" data-tarjeta-cupo="{{ $cupo->estado }}">
                <span class="font-semibold text-gray-800 dark:text-white">@lang('ventasasesor::cupo.ficha.linea'):</span>
                <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-bold"
                    style="{{ [
                        'verde' => 'background:#dcfce7;border-color:#86efac;color:#166534',
                        'amarillo' => 'background:#fef3c7;border-color:#fcd34d;color:#92400e',
                        'rojo' => 'background:#ffe4e6;border-color:#fda4af;color:#9f1239',
                        'gris' => 'background:#f1f5f9;border-color:#cbd5e1;color:#475569',
                    ][$tono] }}">@lang('ventasasesor::cupo.estados.'.$cupo->estado)</span>
                @if ($cupo->tienePolitica && $cupo->carteraActiva)
                    <span>
                        @if ($cupo->estado === 'excedido')
                            @lang('ventasasesor::cupo.bloque.excedido_en', ['monto' => \ByLopez\Logistica\Support\Moneda::formatear($cupo->exceso)])
                        @else
                            @lang('ventasasesor::cupo.ficha.disponible_de', ['disponible' => \ByLopez\Logistica\Support\Moneda::formatear($cupo->disponible), 'autorizado' => \ByLopez\Logistica\Support\Moneda::formatear($cupo->autorizado)])
                        @endif
                    </span>
                @endif
            </p>
        @endif
    </div>

    <a href="{{ route('admin.ventas_asesor.cartera.index', ['cliente' => $clienteId]) }}" class="secondary-button">
        @lang('ventasasesor::app.cartera.cliente.ver-cartera')
    </a>
</div>
