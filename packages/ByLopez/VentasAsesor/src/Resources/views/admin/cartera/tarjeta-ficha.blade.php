{{-- Tarjeta de Ventas Asesor dentro de la ficha nativa del cliente (hook de Bagisto). Las cifras vienen de CarteraConsultaService. --}}
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
    </div>

    <a href="{{ route('admin.ventas_asesor.cartera.index', ['cliente' => $clienteId]) }}" class="secondary-button">
        @lang('ventasasesor::app.cartera.cliente.ver-cartera')
    </a>
</div>
