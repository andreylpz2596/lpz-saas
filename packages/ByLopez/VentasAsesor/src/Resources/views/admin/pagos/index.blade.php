@php
    $dinero = fn (float|int|null $valor) => \ByLopez\Logistica\Support\Moneda::formatear((float) $valor);

    $iconos = [
        'check' => '<polyline points="20 6 9 17 4 12"/>',
        'alerta' => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
        'x' => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        'menos' => '<line x1="6" y1="12" x2="18" y2="12"/>',
    ];

    // Estado del PAGO (dominio financiero), nunca mezclado con el estado del pedido.
    $tonoEstado = ['sin_revisar' => 'amarillo', 'confirmado' => 'verde', 'rechazado' => 'rojo', 'reversado' => 'gris'];
    $iconoEstado = ['sin_revisar' => 'alerta', 'confirmado' => 'check', 'rechazado' => 'x', 'reversado' => 'menos'];

    $pillEstado = fn (string $estado) => '<span class="va-pill va-pill--'.($tonoEstado[$estado] ?? 'gris').'"><svg viewBox="0 0 24 24" aria-hidden="true">'.($iconos[$iconoEstado[$estado] ?? 'menos'] ?? '').'</svg><span>'.e(trans('ventasasesor::app.cartera.pagos.estado-'.$estado)).'</span></span>';

    $nombreCliente = fn (object $p) => trim($p->first_name.' '.$p->last_name);

    // Datos del pago para el modal de confirmar/rechazar: se revisa sin cerrarlo para buscar el cliente, el medio o el pedido.
    $estructuraPago = fn (object $p) => [
        'pago' => '#'.$p->id,
        'cliente' => $nombreCliente($p),
        'monto' => $dinero($p->monto),
        'medio' => $p->medio_etiqueta,
        'referencia' => $p->referencia ?: null,
        'pedidos' => collect($p->aplicaciones)->reject(fn ($a) => $a['anulada'])->map(fn ($a) => '#'.$a['increment_id'].' ('.$dinero($a['monto']).')')->values()->all(),
    ];

    // Aplicaciones compactas: «2 pedidos · #45 · #50» (maximo 3 numeros y «+n»).
    $resumenAplicaciones = function (array $aplicaciones): array {
        $numeros = collect($aplicaciones)->map(fn ($a) => '#'.$a['increment_id']);

        return [$numeros->take(3)->implode(' · ').($numeros->count() > 3 ? ' +'.($numeros->count() - 3) : ''), count($aplicaciones)];
    };

    // Tarjetas KPI clicables: ?resumen=. Conservan busqueda, medio y registrado por; reemplazan estado y fechas.
    $resumenActivo = $filtros['resumen'] ?? null;
    $conservados = array_filter(['q' => $filtros['q'], 'medio' => $filtros['medio'], 'registrador' => $filtros['registrador']]);
    $urlResumen = fn (string $r) => route('admin.ventas_asesor.cartera.index', $resumenActivo === $r ? $conservados : $conservados + ['resumen' => $r]);

    $hayFiltros = collect($filtros)->filter()->isNotEmpty();
    $okPagoId = (int) request('pago_ok');
    $ok = request('ok');
    $urlPago = fn (int $id) => route('admin.ventas_asesor.cartera.index', ['pago' => $id]);
    $urlCliente = fn (int $id) => route('admin.ventas_asesor.cartera.index', ['cliente' => $id]);
@endphp

@once
    @push('styles')
        @include('ventasasesor::admin.cartera.estilos')
    @endpush
@endonce

<x-admin::layouts>
    <x-slot:title>
        @lang('ventasasesor::app.cartera.pagos.titulo')
    </x-slot>

    <div class="va-orders-page bylopez-scope">
        <div>
            <h1 class="text-3xl font-bold leading-8 text-gray-900 dark:text-white">@lang('ventasasesor::app.cartera.pagos.titulo')</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-gray-400">@lang('ventasasesor::app.cartera.pagos.subtitulo')</p>
        </div>

        @if (in_array($ok, ['confirmado', 'rechazado'], true) && $okPagoId > 0)
            <div class="va-panel mt-4 flex items-start gap-3 p-4" style="border-color:{{ $ok === 'confirmado' ? '#86efac' : '#fda4af' }};background:{{ $ok === 'confirmado' ? '#f0fdf4' : '#fff1f2' }}" data-revision-ok="{{ $ok }}">
                <p class="text-sm font-semibold" style="color:{{ $ok === 'confirmado' ? '#166534' : '#9f1239' }}">
                    @lang('ventasasesor::app.cartera.pagos.ok-'.$ok, ['id' => $okPagoId])
                </p>
            </div>
        @endif

        @if (! $carteraActiva || $pagos === null)
            <div class="va-panel mt-5 p-5 text-sm text-gray-600 dark:text-gray-300" data-cartera-inactiva>
                @lang('ventasasesor::app.cartera.pagos.inactiva')
            </div>
        @else
            {{-- KPI clicables: cada tarjeta es un enlace ?resumen=… con el MISMO criterio que su cifra (BandejaPagosService::resumen). --}}
            <div class="mt-5 grid grid-cols-2 gap-3 xl:grid-cols-4">
                @foreach ([
                    ['sin_revisar', 'kpi-sin-revisar', $indicadores['sin_revisar'], 'kpi-sin-revisar-detalle', 'sin-revisar'],
                    ['sin_revisar', 'kpi-monto', $dinero($indicadores['monto_sin_revisar']), 'kpi-monto-detalle', 'monto'],
                    ['confirmados_hoy', 'kpi-confirmados', $indicadores['confirmados_hoy'], 'kpi-confirmados-detalle', 'confirmados-hoy'],
                    ['rechazados', 'kpi-rechazados', $indicadores['rechazados'], 'kpi-rechazados-detalle', 'rechazados'],
                ] as [$clave, $etiqueta, $valor, $detalle, $dato])
                    <a
                        href="{{ $urlResumen($clave) }}"
                        class="va-panel va-kpi va-kpi-box {{ $resumenActivo === $clave ? 'is-active' : '' }}"
                        data-resumen-link="{{ $clave }}"
                        data-kpi="{{ $dato }}"
                        title="{{ trans('ventasasesor::app.cartera.pagos.'.($resumenActivo === $clave ? 'ayuda-kpi-quitar' : 'ayuda-kpi')) }}"
                        @if ($resumenActivo === $clave) aria-current="true" @endif
                    >
                        <span class="va-kpi-check"><svg viewBox="0 0 24 24" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg></span>
                        <p class="va-kpi-label">@lang('ventasasesor::app.cartera.pagos.'.$etiqueta)</p>
                        <p class="va-kpi-value">{{ $valor }}</p>
                        <p class="va-sub">@lang('ventasasesor::app.cartera.pagos.'.$detalle)</p>
                    </a>
                @endforeach
            </div>

            {{-- Doc. 35: panel compacto de cupos de cartera (solo con la hoja cupo.ver y alcance «todos»). --}}
            @if (! empty($cupoPanel))
                @include('ventasasesor::admin.cartera.cupo-panel')
            @endif

            @if ($resumenActivo)
                <div class="va-panel mt-4 flex flex-wrap items-center justify-between gap-3 p-3" data-resumen-activo="{{ $resumenActivo }}">
                    <p class="text-sm text-gray-700 dark:text-gray-300">
                        @lang('ventasasesor::app.cartera.pagos.mostrando'):
                        <span class="font-bold text-gray-900 dark:text-white">@lang('ventasasesor::app.cartera.pagos.estado-'.($resumenActivo === 'confirmados_hoy' ? 'confirmado' : ($resumenActivo === 'rechazados' ? 'rechazado' : 'sin_revisar')))</span>
                        &middot; @lang('ventasasesor::app.cartera.pagos.pagos', ['cantidad' => $pagos->total()])
                    </p>

                    <a href="{{ route('admin.ventas_asesor.cartera.index', array_filter(request()->except(['resumen', 'ok', 'pago_ok', 'page']))) }}" class="transparent-button">
                        ✕ @lang('ventasasesor::app.cartera.pagos.quitar')
                    </a>
                </div>
            @endif

            <form method="GET" action="{{ route('admin.ventas_asesor.cartera.index') }}" class="va-panel mt-4 p-4">
                @if ($resumenActivo)
                    <input type="hidden" name="resumen" value="{{ $resumenActivo }}">
                @endif

                {{-- Movil: la busqueda y el estado siempre se ven; medio, fechas y «registrado por» se pliegan (siguen en el formulario con su valor). --}}
                @php
                    $filtrosSecundariosActivos = collect([$filtros['medio'] ?? null, $filtros['desde'] ?? null, $filtros['hasta'] ?? null, $filtros['registrador'] ?? null])->filter()->count();
                @endphp
                <input type="checkbox" id="va-filtros-mas" class="va-filtros-check">
                <label for="va-filtros-mas" class="va-filtros-btn secondary-button" data-filtros-secundarios="{{ $filtrosSecundariosActivos }}">
                    <span class="va-filtros-btn-mas">@lang('ventasasesor::app.cartera.pagos.mas-filtros')</span>
                    <span class="va-filtros-btn-menos">@lang('ventasasesor::app.cartera.pagos.menos-filtros')</span>
                    @if ($filtrosSecundariosActivos > 0)
                        <span class="va-filtros-insignia" aria-hidden="true" title="@lang('ventasasesor::app.cartera.pagos.filtros-activos', ['n' => $filtrosSecundariosActivos])">{{ $filtrosSecundariosActivos }}</span>
                        <span class="sr-only">@lang('ventasasesor::app.cartera.pagos.filtros-activos', ['n' => $filtrosSecundariosActivos])</span>
                    @endif
                </label>

                <div class="va-filters-layout">
                    <div class="va-filter-search">
                        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400" for="f-q">@lang('ventasasesor::app.cartera.pagos.buscar')</label>
                        <input id="f-q" type="search" name="q" value="{{ $filtros['q'] }}" maxlength="100" class="w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300" placeholder="Karol, 300…, TRX…">
                    </div>

                    <div class="va-filter-field">
                        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400" for="f-estado">@lang('ventasasesor::app.cartera.pagos.estado')</label>
                        <select id="f-estado" name="estado" class="custom-select w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                            <option value="">@lang('ventasasesor::app.cartera.pagos.todos')</option>
                            @foreach (['sin_revisar', 'confirmado', 'rechazado'] as $estado)
                                <option value="{{ $estado }}" @selected($filtros['estado'] === $estado)>@lang('ventasasesor::app.cartera.pagos.estado-'.$estado)</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="va-filter-field va-filter-sec">
                        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400" for="f-medio">@lang('ventasasesor::app.cartera.pagos.medio')</label>
                        <select id="f-medio" name="medio" class="custom-select w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                            <option value="">@lang('ventasasesor::app.cartera.pagos.todos')</option>
                            @foreach ($opciones['medios'] as $medio)
                                <option value="{{ $medio['codigo'] }}" @selected($filtros['medio'] === $medio['codigo'])>{{ $medio['etiqueta'] }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="va-filter-field va-filter-sec">
                        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400" for="f-desde">@lang('ventasasesor::app.cartera.pagos.desde')</label>
                        <input id="f-desde" type="date" name="desde" value="{{ $filtros['desde'] }}" class="w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                    </div>

                    <div class="va-filter-field va-filter-sec">
                        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400" for="f-hasta">@lang('ventasasesor::app.cartera.pagos.hasta')</label>
                        <input id="f-hasta" type="date" name="hasta" value="{{ $filtros['hasta'] }}" class="w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                    </div>

                    @if ($opciones['registradores'] !== [])
                        <div class="va-filter-field va-filter-sec">
                            <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400" for="f-reg">@lang('ventasasesor::app.cartera.pagos.registrado-por')</label>
                            <select id="f-reg" name="registrador" class="custom-select w-full rounded-lg border border-slate-200 px-3 py-2.5 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                                <option value="">@lang('ventasasesor::app.cartera.pagos.todos')</option>
                                @foreach ($opciones['registradores'] as $registrador)
                                    <option value="{{ $registrador['id'] }}" @selected($filtros['registrador'] === (string) $registrador['id'])>{{ $registrador['nombre'] }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    <div class="va-filter-actions">
                        @if ($hayFiltros)
                            <a href="{{ route('admin.ventas_asesor.cartera.index') }}" class="transparent-button">@lang('ventasasesor::app.cartera.pagos.limpiar')</a>
                        @endif

                        <button type="submit" class="secondary-button">@lang('ventasasesor::app.cartera.pagos.filtrar')</button>
                    </div>
                </div>
            </form>

            {{-- Escritorio / portatil --}}
            <div class="va-panel va-desktop-table mt-4 overflow-hidden">
                <table class="va-orders-table text-left text-sm">
                    <colgroup>
                        <col style="width: 13%">
                        <col style="width: 17%">
                        <col style="width: 11%">
                        <col style="width: 11%">
                        <col style="width: 10%">
                        <col style="width: 14%">
                        <col style="width: 10%">
                        <col style="width: 14%">
                    </colgroup>

                    <thead class="bg-slate-50 dark:bg-gray-950">
                        <tr>
                            <th>@lang('ventasasesor::app.cartera.pagos.col-pago')</th>
                            <th>@lang('ventasasesor::app.cartera.pagos.col-cliente')</th>
                            <th>@lang('ventasasesor::app.cartera.pagos.col-registrado')</th>
                            <th>@lang('ventasasesor::app.cartera.pagos.col-medio')</th>
                            <th class="text-right">@lang('ventasasesor::app.cartera.pagos.col-monto')</th>
                            <th>@lang('ventasasesor::app.cartera.pagos.col-aplicaciones')</th>
                            <th>@lang('ventasasesor::app.cartera.pagos.col-estado')</th>
                            <th></th>
                        </tr>
                    </thead>

                    <tbody>
                        @forelse ($pagos as $pago)
                            @php
                                [$numeros, $cantidad] = $resumenAplicaciones($pago->aplicaciones);
                                $textoPago = trans('ventasasesor::app.cartera.pagos.pago').' #'.$pago->id.' · '.$dinero($pago->monto).' · '.$nombreCliente($pago);
                                $detallePago = $estructuraPago($pago);
                            @endphp

                            <tr data-pago="{{ $pago->id }}">
                                <td>
                                    <p class="va-order-id text-gray-900 dark:text-white">#{{ $pago->id }}</p>
                                    <p class="va-sub">{{ core()->formatDate($pago->created_at, 'd/m/Y') }} &middot; {{ core()->formatDate($pago->created_at, 'h:i A') }}</p>
                                </td>
                                <td>
                                    <a href="{{ $urlCliente($pago->customer_id) }}" class="block min-w-0 hover:underline">
                                        <p class="truncate font-semibold text-gray-900 dark:text-white">{{ $nombreCliente($pago) ?: '-' }}</p>
                                        <p class="truncate text-xs text-slate-500 dark:text-gray-400">{{ $pago->phone ?: trans('ventasasesor::app.pedidos.sin-telefono') }}</p>
                                    </a>
                                </td>
                                <td class="text-sm text-gray-700 dark:text-gray-300">{{ $pago->registrado_por ?: '—' }}</td>
                                <td>
                                    <p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ $pago->medio_etiqueta }}</p>
                                    @if ($pago->referencia)
                                        <p class="va-sub truncate">{{ $pago->referencia }}</p>
                                    @endif
                                </td>
                                <td class="va-money--strong text-right text-gray-900 dark:text-white">{{ $dinero($pago->monto) }}</td>
                                <td>
                                    <p class="text-sm font-medium text-gray-800 dark:text-gray-200">@lang('ventasasesor::app.cartera.pagos.pedidos', ['cantidad' => $cantidad])</p>
                                    <p class="va-sub truncate">{{ $numeros }}</p>
                                </td>
                                <td>{!! $pillEstado($pago->estado) !!}</td>
                                <td>
                                    <div class="va-acciones">
                                        <a href="{{ $urlPago($pago->id) }}" class="va-btn">@lang('ventasasesor::app.cartera.pagos.ver')</a>

                                        @if ($puedeRevisar && $pago->estado === 'sin_revisar')
                                            <button type="button" class="va-btn va-btn--ok" data-revisar="confirmar" data-pago="{{ $pago->id }}" data-texto="{{ $textoPago }}" data-detalle="{{ json_encode($detallePago, JSON_UNESCAPED_UNICODE) }}">@lang('ventasasesor::app.cartera.pagos.confirmar')</button>
                                            <button type="button" class="va-btn va-btn--no" data-revisar="rechazar" data-pago="{{ $pago->id }}" data-texto="{{ $textoPago }}" data-detalle="{{ json_encode($detallePago, JSON_UNESCAPED_UNICODE) }}">@lang('ventasasesor::app.cartera.pagos.rechazar')</button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-4 py-10 text-center text-gray-500 dark:text-gray-400">
                                    {{ $hayFiltros ? trans('ventasasesor::app.cartera.pagos.vacio-filtros') : trans('ventasasesor::app.cartera.pagos.vacio') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Tablet y movil: tarjetas --}}
            <div class="va-mobile-orders mt-4 gap-3">
                @forelse ($pagos as $pago)
                    @php
                        [$numeros, $cantidad] = $resumenAplicaciones($pago->aplicaciones);
                        $textoPago = trans('ventasasesor::app.cartera.pagos.pago').' #'.$pago->id.' · '.$dinero($pago->monto).' · '.$nombreCliente($pago);
                                $detallePago = $estructuraPago($pago);
                    @endphp

                    <div class="va-panel p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="va-order-id text-gray-900 dark:text-white">#{{ $pago->id }}</p>
                                <p class="va-sub">{{ core()->formatDate($pago->created_at, 'd/m/Y') }} &middot; {{ core()->formatDate($pago->created_at, 'h:i A') }}</p>
                            </div>

                            {!! $pillEstado($pago->estado) !!}
                        </div>

                        <a href="{{ $urlCliente($pago->customer_id) }}" class="mt-3 block min-w-0">
                            <p class="truncate font-semibold text-gray-900 dark:text-white">{{ $nombreCliente($pago) ?: '-' }}</p>
                            <p class="truncate text-sm text-slate-500 dark:text-gray-400">{{ $pago->phone ?: trans('ventasasesor::app.pedidos.sin-telefono') }}</p>
                        </a>

                        <div class="mt-3 grid grid-cols-2 gap-3 rounded-lg bg-slate-50 p-3 dark:bg-gray-950">
                            <div>
                                <p class="va-caption">@lang('ventasasesor::app.cartera.pagos.col-monto')</p>
                                <p class="va-money--strong text-gray-900 dark:text-white">{{ $dinero($pago->monto) }}</p>
                                <p class="va-sub">{{ $pago->medio_etiqueta }}@if ($pago->referencia) · {{ $pago->referencia }}@endif</p>
                            </div>

                            <div class="text-right">
                                <p class="va-caption">@lang('ventasasesor::app.cartera.pagos.col-aplicaciones')</p>
                                <p class="text-sm font-medium text-gray-800 dark:text-gray-200">@lang('ventasasesor::app.cartera.pagos.pedidos', ['cantidad' => $cantidad])</p>
                                <p class="va-sub">{{ $numeros }}</p>
                            </div>
                        </div>

                        <p class="va-sub mt-2">@lang('ventasasesor::app.cartera.pagos.col-registrado'): {{ $pago->registrado_por ?: '—' }}</p>

                        <div class="va-acciones mt-3">
                            <a href="{{ $urlPago($pago->id) }}" class="va-btn">@lang('ventasasesor::app.cartera.pagos.ver')</a>

                            @if ($puedeRevisar && $pago->estado === 'sin_revisar')
                                <button type="button" class="va-btn va-btn--ok" data-revisar="confirmar" data-pago="{{ $pago->id }}" data-texto="{{ $textoPago }}" data-detalle="{{ json_encode($detallePago, JSON_UNESCAPED_UNICODE) }}">@lang('ventasasesor::app.cartera.pagos.confirmar')</button>
                                <button type="button" class="va-btn va-btn--no" data-revisar="rechazar" data-pago="{{ $pago->id }}" data-texto="{{ $textoPago }}" data-detalle="{{ json_encode($detallePago, JSON_UNESCAPED_UNICODE) }}">@lang('ventasasesor::app.cartera.pagos.rechazar')</button>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="va-panel p-6 text-center text-gray-500 dark:text-gray-400">
                        {{ $hayFiltros ? trans('ventasasesor::app.cartera.pagos.vacio-filtros') : trans('ventasasesor::app.cartera.pagos.vacio') }}
                    </div>
                @endforelse
            </div>

            @if ($pagos->total() > 0)
                <div class="mt-4 flex items-center justify-between gap-2.5 max-sm:flex-wrap">
                    <p class="text-sm text-slate-500 dark:text-gray-400">
                        @lang('ventasasesor::app.pedidos.resultados', ['desde' => $pagos->firstItem(), 'hasta' => $pagos->lastItem(), 'total' => $pagos->total()])
                    </p>

                    @if ($pagos->hasPages())
                        <div class="flex items-center gap-2">
                            @if ($pagos->onFirstPage())
                                <span class="secondary-button cursor-not-allowed opacity-50">@lang('ventasasesor::app.pedidos.anterior')</span>
                            @else
                                <a href="{{ $pagos->previousPageUrl() }}" class="secondary-button">@lang('ventasasesor::app.pedidos.anterior')</a>
                            @endif

                            <span class="text-sm text-slate-500 dark:text-gray-400">{{ $pagos->currentPage() }} / {{ $pagos->lastPage() }}</span>

                            @if ($pagos->hasMorePages())
                                <a href="{{ $pagos->nextPageUrl() }}" class="secondary-button">@lang('ventasasesor::app.pedidos.siguiente')</a>
                            @else
                                <span class="secondary-button cursor-not-allowed opacity-50">@lang('ventasasesor::app.pedidos.siguiente')</span>
                            @endif
                        </div>
                    @endif
                </div>
            @endif

            @if ($puedeRevisar)
                @include('ventasasesor::admin.pagos.modales')
            @endif
        @endif
    </div>
</x-admin::layouts>
