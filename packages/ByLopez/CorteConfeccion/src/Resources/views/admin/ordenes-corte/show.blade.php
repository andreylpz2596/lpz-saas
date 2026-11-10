@php
    $estadoMachine = \ByLopez\CorteConfeccion\Support\OrdenCorteEstadoMachine::class;

    $detallesSolicitud = $orden->detalles->where('es_adicional', false)->values();
    $detallesAdicionales = $orden->detalles->where('es_adicional', true)->values();

    $totalSolicitado = (int) $detallesSolicitud->sum('cantidad_proyectada');
    $totalReportado = (int) $detallesSolicitud->sum('cantidad_real_cortada');
    $totalAdicionales = (int) $detallesAdicionales->sum('cantidad_real_cortada');
    $totalCortado = $totalReportado + $totalAdicionales;
    $rollosPendientesEntrega = $orden->rollosCorte->filter(fn ($ordenRollo) => ! $ordenRollo->entregado_en)->values();
    $pesoAEntregar = round((float) $rollosPendientesEntrega->sum(fn ($ordenRollo) => (float) $ordenRollo->peso_inicial_usado), 3);
    $puedeDistribuirCorte = ($resumenDistribucion['puede_distribuir'] ?? false)
        && \ByLopez\CorteConfeccion\Support\DistribucionConfeccionGate::puedeDistribuir();
    $distribucionCompletada = $orden->recepcion && (int) ($resumenDistribucion['total_pendiente'] ?? 0) <= 0;

    $yaReportado = $estadoMachine::puedeTransicionar($orden->estado, 'cerrado')
        || in_array($orden->estado, ['reportado_por_cortador', 'recibido_bodega', 'cerrado'], true);

    $proximoPaso = match ($orden->estado) {
        \ByLopez\CorteConfeccion\Enums\EstadoOrdenCorte::PendienteEntrega->value => 'Proximo paso: haz clic en "Entregar rollos" (arriba) para que el cortador pueda empezar.',
        \ByLopez\CorteConfeccion\Enums\EstadoOrdenCorte::EntregadoACortador->value => 'Proximo paso: haz clic en "Marcar en corte" (arriba) para activar el card "Resultado real del corte".',
        \ByLopez\CorteConfeccion\Enums\EstadoOrdenCorte::EnCorte->value => 'Proximo paso: el cortador debe reportar el resultado real en el card de abajo.',
        \ByLopez\CorteConfeccion\Enums\EstadoOrdenCorte::ReportadoPorCortador->value => 'Proximo paso: Bodega o Administrador deben confirmar la recepcion en el card de abajo.',
        default => null,
    };
@endphp

@include('corteconfeccion::admin.partials.estilos')

<x-admin::layouts>
    <x-slot:title>Orden {{ $orden->codigo }}</x-slot>

    <div class="bylopez-scope">
    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <div class="flex items-center gap-3">
            <p class="text-xl font-bold text-gray-800 dark:text-white">Orden {{ $orden->codigo }}</p>
            {!! \ByLopez\CorteConfeccion\Enums\EstadoOrdenCorte::badgeHtml($orden->estado) !!}
        </div>

        <div class="flex flex-wrap gap-2">
            @if ($canDeliverRolls)
                <x-admin::modal
                    ref="entregarRollosModal"
                    panel-class="max-w-[860px]"
                    @toggle="$event.isActive && window.bylopezCenterModal($refs.entregarRollosModal, 860)"
                    @open="window.bylopezCenterModal($refs.entregarRollosModal, 860)"
                >
                    <x-slot:toggle>
                        <button
                            type="button"
                            class="primary-button"
                        >
                            Entregar rollos
                        </button>
                    </x-slot>

                    <x-slot:header>
                        <p class="text-lg font-bold text-gray-800 dark:text-white">
                            Confirmar entrega de rollos
                        </p>
                    </x-slot>

                    <x-slot:content>
                        <div class="grid gap-4">
                            <div class="rounded border border-blue-200 bg-blue-50 p-3 text-sm text-blue-800 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-200">
                                Confirma fisicamente los rollos pendientes de entrega para la orden {{ $orden->codigo }}.
                            </div>

                            <div class="overflow-x-auto rounded border dark:border-gray-800">
                                <table class="w-full min-w-[720px] border-collapse text-left text-sm">
                                    <thead>
                                        <tr class="border-b bg-gray-50 text-xs font-semibold uppercase text-gray-600 dark:border-gray-800 dark:bg-gray-950 dark:text-gray-300">
                                            <th class="px-3 py-2">Rollo</th>
                                            <th class="px-3 py-2">Color</th>
                                            <th class="px-3 py-2">Tipo de tela</th>
                                            <th class="px-3 py-2 text-right">Peso disponible / reservado</th>
                                            <th class="px-3 py-2 text-right">Peso a entregar</th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        @foreach ($rollosPendientesEntrega as $ordenRollo)
                                            <tr class="border-b last:border-b-0 dark:border-gray-800">
                                                <td class="px-3 py-3 font-semibold text-gray-800 dark:text-white">
                                                    {{ $ordenRollo->rollo?->codigo ?? 'Rollo no disponible' }}
                                                </td>

                                                <td class="px-3 py-3 text-gray-600 dark:text-gray-300">
                                                    {{ $ordenRollo->rollo?->color ?? '—' }}
                                                </td>

                                                <td class="px-3 py-3 text-gray-600 dark:text-gray-300">
                                                    {{ $ordenRollo->rollo?->tipo_tela ?? '—' }}
                                                </td>

                                                <td class="px-3 py-3 text-right text-gray-600 dark:text-gray-300">
                                                    {{ number_format((float) ($ordenRollo->rollo?->peso_disponible ?? 0), 3, ',', '.') }} kg /
                                                    {{ number_format((float) $ordenRollo->peso_inicial_usado, 3, ',', '.') }} kg
                                                </td>

                                                <td class="px-3 py-3 text-right font-semibold text-gray-800 dark:text-white">
                                                    {{ number_format((float) $ordenRollo->peso_inicial_usado, 3, ',', '.') }} kg
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            <div class="rounded border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900 dark:bg-emerald-950">
                                <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">
                                    Total peso reservado / entregado
                                </p>

                                <p class="mt-1 text-2xl font-bold text-emerald-800 dark:text-emerald-200">
                                    {{ number_format($pesoAEntregar, 3, ',', '.') }} kg
                                </p>
                            </div>
                        </div>
                    </x-slot>

                    <x-slot:footer>
                        <form
                            method="POST"
                            action="{{ route('admin.corte_confeccion.ordenes_corte.entregar', $orden->id) }}"
                            class="flex items-center gap-2.5"
                        >
                            @csrf

                            <button
                                type="button"
                                class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800"
                                @click="$refs.entregarRollosModal.close()"
                            >
                                Cancelar
                            </button>

                            <button
                                type="submit"
                                class="primary-button"
                            >
                                Confirmar entrega
                            </button>
                        </form>
                    </x-slot>
                </x-admin::modal>
            @endif
            @if ($estadoMachine::puedeTransicionar($orden->estado, 'en_corte'))
                <form method="POST" action="{{ route('admin.corte_confeccion.ordenes_corte.en_corte', $orden->id) }}">@csrf <button class="primary-button">Marcar en corte</button></form>
            @endif
            @if ($puedeDistribuirCorte)
                <a href="{{ route('admin.corte_confeccion.ordenes_confeccion.create', $orden->id) }}" class="secondary-button">Distribuir a talleres</a>
            @elseif ($distribucionCompletada)
                <div class="rounded border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-300">
                    Distribucion completada
                </div>
            @endif
            @if ($estadoMachine::puedeTransicionar($orden->estado, 'cerrado'))
                <form method="POST" action="{{ route('admin.corte_confeccion.ordenes_corte.cerrar', $orden->id) }}" data-confirm="Esta accion cierra la orden y descuenta el peso sobrante de los rollos. ¿Confirmas el cierre?" data-confirm-title="Cerrar orden">@csrf <button class="primary-button">Cerrar</button></form>
            @endif
            @if ($estadoMachine::puedeTransicionar($orden->estado, 'anulado') && ! $estadoMachine::haIniciadoCorte($orden->estado))
                <form method="POST" action="{{ route('admin.corte_confeccion.ordenes_corte.cancel', $orden->id) }}" data-confirm="Esta accion anula la orden y libera los rollos reservados. ¿Confirmas la anulacion?" data-confirm-title="Anular orden">@csrf <button class="transparent-button">Anular</button></form>
            @endif
        </div>
    </div>

    @if ($proximoPaso)
        <div class="mt-3 rounded border border-blue-200 bg-blue-50 p-3 text-sm font-medium text-blue-800 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-200">
            {{ $proximoPaso }}
        </div>
    @endif

    <div class="mt-4 grid grid-cols-[minmax(0,1fr)_320px] gap-4 max-xl:grid-cols-1">
        <div class="min-w-0">
    <div class="mt-4 grid grid-cols-5 gap-3 max-lg:grid-cols-3 max-sm:grid-cols-2">
        <div class="rounded border p-3 dark:border-gray-800">
            <p class="text-xs text-gray-500 dark:text-gray-400">Cortador</p>
            <p class="font-semibold text-gray-800 dark:text-white">{{ $orden->cortador_nombre_snapshot }}</p>
        </div>
        <div class="rounded border p-3 dark:border-gray-800">
            <p class="text-xs text-gray-500 dark:text-gray-400">Tela / Color</p>
            <p class="font-semibold text-gray-800 dark:text-white">{{ $orden->rollosCorte->first()?->rollo?->tipo_tela }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $orden->color }}</p>
        </div>
        <div class="rounded border p-3 dark:border-gray-800">
            <p class="text-xs text-gray-500 dark:text-gray-400">Peso total rollos</p>
            <p class="font-semibold text-gray-800 dark:text-white">{{ $orden->peso_total_rollos }} kg</p>
        </div>
        <div class="rounded border p-3 dark:border-gray-800">
            <p class="text-xs text-gray-500 dark:text-gray-400">Creada por</p>
            <p class="font-semibold text-gray-800 dark:text-white">{{ $orden->creadoPor?->name ?? 'No registrado' }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $orden->created_at?->format('Y-m-d H:i') }}</p>
        </div>
        <div class="rounded border p-3 dark:border-gray-800">
            <p class="text-xs text-gray-500 dark:text-gray-400">Fecha entrega</p>
            <p class="font-semibold text-gray-800 dark:text-white">{{ $orden->fecha_entrega?->format('Y-m-d') ?: '—' }}</p>
        </div>
    </div>

    {{-- Resumen visual: nunca mezclar solicitado / reportado / adicionales --}}
    <div class="mt-4 grid {{ $orden->tieneProyeccion() ? 'grid-cols-4' : 'grid-cols-3' }} gap-3 max-sm:grid-cols-2">
        @if ($orden->tieneProyeccion())
            <div class="rounded border border-gray-300 bg-gray-50 p-3 text-center dark:border-gray-700 dark:bg-gray-900">
                <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Solicitado</p>
                <p class="text-xl font-bold text-gray-800 dark:text-white">{{ number_format($totalSolicitado, 0, ',', '.') }}</p>
            </div>
        @endif
        <div class="rounded border border-blue-200 bg-blue-50 p-3 text-center dark:border-blue-900 dark:bg-blue-950">
            <p class="text-xs uppercase tracking-wide text-blue-700 dark:text-blue-300">Reportado</p>
            <p class="text-xl font-bold text-blue-800 dark:text-blue-200">{{ number_format($totalReportado, 0, ',', '.') }}</p>
        </div>
        <div class="rounded border border-amber-200 bg-amber-50 p-3 text-center dark:border-amber-900 dark:bg-amber-950">
            <p class="text-xs uppercase tracking-wide text-amber-700 dark:text-amber-300">Adicionales</p>
            <p class="text-xl font-bold text-amber-800 dark:text-amber-200">{{ number_format($totalAdicionales, 0, ',', '.') }}</p>
        </div>
        <div class="rounded border border-emerald-200 bg-emerald-50 p-3 text-center dark:border-emerald-900 dark:bg-emerald-950">
            <p class="text-xs uppercase tracking-wide text-emerald-700 dark:text-emerald-300">Total cortado</p>
            <p class="text-xl font-bold text-emerald-800 dark:text-emerald-200">{{ number_format($totalCortado, 0, ',', '.') }}</p>
        </div>
    </div>

    <div class="mt-6 flex flex-col gap-5">
        @if ($orden->recepcion)
            <div class="rounded border p-4 dark:border-gray-800">
                <div class="flex items-start justify-between gap-4 max-sm:flex-col">
                    <div>
                        <p class="text-base font-semibold text-gray-800 dark:text-white">Confeccion</p>
                        @if ($distribucionCompletada)
                            <p class="mt-1 text-sm font-medium text-emerald-700 dark:text-emerald-300">
                                Distribucion completada: {{ number_format($resumenDistribucion['total_distribuido'], 0, ',', '.') }} de {{ number_format($resumenDistribucion['total_apto_confeccion'], 0, ',', '.') }} unidades distribuidas.
                            </p>
                        @else
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                                Disponibilidad calculada desde cantidades recibidas por Bodega menos cantidades entregadas a talleres.
                            </p>
                        @endif
                    </div>
                </div>

                <div class="mt-4 grid grid-cols-3 gap-3 max-sm:grid-cols-1">
                    <div class="rounded border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-900">
                        <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Apto para distribuir</p>
                        <p class="text-xl font-bold text-gray-800 dark:text-white">{{ number_format($resumenDistribucion['total_apto_confeccion'], 0, ',', '.') }}</p>
                    </div>
                    <div class="rounded border border-blue-200 bg-blue-50 p-3 dark:border-blue-900 dark:bg-blue-950">
                        <p class="text-xs uppercase tracking-wide text-blue-700 dark:text-blue-300">Distribuido</p>
                        <p class="text-xl font-bold text-blue-800 dark:text-blue-200">{{ number_format($resumenDistribucion['total_distribuido'], 0, ',', '.') }}</p>
                    </div>
                    <div class="rounded border border-amber-200 bg-amber-50 p-3 dark:border-amber-900 dark:bg-amber-950">
                        <p class="text-xs uppercase tracking-wide text-amber-700 dark:text-amber-300">Pendiente</p>
                        <p class="text-xl font-bold text-amber-800 dark:text-amber-200">{{ number_format($resumenDistribucion['total_pendiente'], 0, ',', '.') }}</p>
                    </div>
                </div>

                <div class="mt-4">
                    <p class="text-sm font-semibold text-gray-800 dark:text-white">Ordenes de confeccion generadas</p>

                    @if ($orden->ordenesConfeccion->isEmpty())
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Aun no hay ordenes de confeccion para esta orden de corte.</p>
                    @else
                        <div class="mt-2 grid gap-2">
                            @foreach ($orden->ordenesConfeccion as $ordenConfeccion)
                                <div class="flex items-center justify-between gap-3 rounded border border-gray-200 p-3 dark:border-gray-800 max-sm:items-start max-sm:flex-col">
                                    <div>
                                        <a href="{{ route('admin.corte_confeccion.ordenes_confeccion.show', $ordenConfeccion->id) }}" class="font-semibold text-blue-600 hover:underline dark:text-blue-400">
                                            {{ $ordenConfeccion->codigo }}
                                        </a>
                                        <p class="text-sm text-gray-600 dark:text-gray-300">{{ $ordenConfeccion->taller?->nombre ?? 'Taller no registrado' }}</p>
                                    </div>
                                    <div class="text-right max-sm:text-left">
                                        <p class="font-semibold text-gray-800 dark:text-white">{{ number_format((int) $ordenConfeccion->detalles->sum('cantidad_entregada'), 0, ',', '.') }} unidades</p>
                                        {!! \ByLopez\CorteConfeccion\Enums\EstadoOrdenConfeccion::badgeHtml($ordenConfeccion->estado) !!}
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        @endif

        @include('corteconfeccion::admin.partials.tarifa-orden', [
            'orden' => $orden,
            'tarifasOrden' => $tarifasOrden,
            'tipo' => 'cortador',
            'ruta' => 'admin.corte_confeccion.ordenes_corte.tarifa',
        ])

        <div class="mt-4">
            @include('corteconfeccion::admin.partials.cuenta-orden', ['cuentaOrden' => \ByLopez\CorteConfeccion\Support\CarteraVista::cuentaOrden($orden)])
        </div>

        @include('corteconfeccion::admin.ordenes-corte.partials.card-productos', [
            'orden' => $orden,
            'detallesSolicitud' => $detallesSolicitud,
            'canEditOrder' => $canEditOrder,
        ])

        @include('corteconfeccion::admin.ordenes-corte.partials.card-rollos', [
            'orden' => $orden,
            'rollosDisponibles' => $rollosDisponibles,
            'canEditOrder' => $canEditOrder,
        ])

        @include('corteconfeccion::admin.ordenes-corte.partials.card-resultado', [
            'orden' => $orden,
            'detallesSolicitud' => $detallesSolicitud,
            'detallesAdicionales' => $detallesAdicionales,
            'products' => $products,
            'canEditCantidadCortada' => $canEditCantidadCortada,
            'yaReportado' => $yaReportado,
        ])

        @include('corteconfeccion::admin.ordenes-corte.partials.card-recepcion', [
            'orden' => $orden,
            'products' => $products,
            'canEditCantidadCortada' => $canEditCantidadCortada,
            'canConfirmarRecepcion' => $canConfirmarRecepcion,
            'usuariosRecepcion' => $usuariosRecepcion,
        ])
    </div>
        </div>

        <div class="min-w-0">
            @include('corteconfeccion::admin.ordenes-corte.partials.resumen-orden', [
                'orden' => $orden,
                'detallesSolicitud' => $detallesSolicitud,
            ])
        </div>
    </div>
    </div>

    @push('scripts')
        <script>
            window.bylopezCenterModal = function (modalRef, maxWidth) {
                let attempts = 0;

                let center = function () {
                    attempts++;

                    let root = modalRef?.$el;
                    let content = root?.querySelector('.fixed.inset-0.transform');
                    let wrapper = content?.firstElementChild;
                    let panel = wrapper?.querySelector('.box-shadow');

                    if (! content || ! wrapper || ! panel) {
                        if (attempts < 8) {
                            requestAnimationFrame(center);
                        }

                        return;
                    }

                    if (content) {
                        content.style.setProperty('height', '100dvh', 'important');
                        content.style.setProperty('max-height', '100dvh', 'important');
                        content.style.setProperty('overflow-y', 'auto', 'important');
                    }

                    if (wrapper) {
                        wrapper.classList.remove('items-end', 'sm:items-center', 'p-4', 'sm:p-0');
                        wrapper.classList.add('items-center');
                        wrapper.style.setProperty('min-height', '100dvh', 'important');
                        wrapper.style.setProperty('align-items', 'center', 'important');
                        wrapper.style.setProperty('justify-content', 'center', 'important');
                        wrapper.style.setProperty('padding', '16px', 'important');
                    }

                    if (panel) {
                        panel.classList.remove('max-lg:mb-4', 'max-lg:mt-4');
                        panel.style.setProperty('width', 'min(' + maxWidth + 'px, calc(100vw - 32px))', 'important');
                        panel.style.setProperty('max-width', maxWidth + 'px', 'important');
                        panel.style.setProperty('margin-top', 'auto', 'important');
                        panel.style.setProperty('margin-bottom', 'auto', 'important');
                    }
                };

                requestAnimationFrame(center);
            };

            document.addEventListener('DOMContentLoaded', function () {
                document.querySelectorAll('form[data-confirm]').forEach(function (form) {
                    form.addEventListener('submit', function (event) {
                        event.preventDefault();

                        window.app.config.globalProperties.$emitter.emit('open-confirm-modal', {
                            title: form.dataset.confirmTitle || 'Confirmar accion',
                            message: form.dataset.confirm,
                            agree: function () {
                                form.submit();
                            },
                        });
                    });
                });
            });
        </script>
    @endpush
</x-admin::layouts>
