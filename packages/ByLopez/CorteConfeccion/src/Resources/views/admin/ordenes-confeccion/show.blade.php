{{--
    Este paquete compila su propio bundle de Tailwind, separado del de
    packages/Webkul/Admin, con `important: ".bylopez-scope"` -- toda clase
    generada por ESE build solo aplica dentro de un ancestro con la clase
    literal "bylopez-scope". Sin este include/wrapper, las utilidades que
    tambien existen (por coincidencia) en el bundle de Webkul se ven bien,
    pero cualquier clase propia de este build (valores arbitrarios como
    grid-cols-[...], variantes [&_th]:, etc.) queda invisible en silencio.
    Se confirmo exactamente este problema al redisenar esta vista.
--}}
@include('corteconfeccion::admin.partials.estilos')

<x-admin::layouts>
    <x-slot:title>Orden confeccion {{ $orden->codigo }}</x-slot>

    @php
        $estadoOrdenConfeccion = \ByLopez\CorteConfeccion\Enums\EstadoOrdenConfeccion::class;
        $estadoOrdenConfeccionRetorno = \ByLopez\CorteConfeccion\Enums\EstadoOrdenConfeccionRetorno::class;
        $prioridadOrdenConfeccion = \ByLopez\CorteConfeccion\Enums\PrioridadOrdenConfeccion::class;
        $puedeReportarTaller = \ByLopez\CorteConfeccion\Support\OrdenConfeccionGate::puedeReportarTaller($orden);
        $puedeRecibirBodega = \ByLopez\CorteConfeccion\Support\OrdenConfeccionGate::puedeRecibirBodega();
        $estadoActiva = \ByLopez\CorteConfeccion\Enums\EstadoOrdenConfeccion::Activa->value;
        $estadoEnConfeccion = \ByLopez\CorteConfeccion\Enums\EstadoOrdenConfeccion::EnConfeccion->value;
        $estadoCerrada = \ByLopez\CorteConfeccion\Enums\EstadoOrdenConfeccion::Cerrada->value;
        $estadoAnulada = \ByLopez\CorteConfeccion\Enums\EstadoOrdenConfeccion::Anulada->value;
        $estadoRetornoPendiente = \ByLopez\CorteConfeccion\Enums\EstadoOrdenConfeccionRetorno::PendienteBodega->value;
        $prioridadPeso = ['urgente' => 0, 'alta' => 1, 'normal' => 2];

        // Color/talla reales por variante (llegan resueltos en lote desde el
        // controlador; aqui solo se leen, sin consultas por fila).
        $datosVariante = fn ($productId) => $variantes->get($productId, []);
        $hexDeColor = fn (?string $color) => $color ? ($hexPorColor[mb_strtolower(trim($color))] ?? null) : null;

        $detallesResumen = $orden->detalles
            ->map(function ($detalle) use ($prioridadPeso, $datosVariante, $hexDeColor, $sugerencias) {
                $reportada = (int) $detalle->retornosDetalles->sum('cantidad_reportada');
                $recibida = (int) $detalle->retornosDetalles->sum('cantidad_recibida');
                $defectuosa = (int) $detalle->retornosDetalles->sum('cantidad_defectuosa');
                $faltante = (int) $detalle->retornosDetalles->sum('cantidad_faltante');
                $pendiente = $detalle->pendientePorReportar();
                // Prioridad POR CANTIDAD de lo pendiente (Doc. 09); la de asignacion queda como respaldo del orden.
                $prioridades = $detalle->prioridadesPorConfeccionar();
                $prioridad = match (true) {
                    $prioridades['urgente'] > 0 => 'urgente',
                    $prioridades['alta'] > 0 => 'alta',
                    $pendiente > 0 => 'normal',
                    default => $detalle->prioridad ?: \ByLopez\CorteConfeccion\Enums\PrioridadOrdenConfeccion::Normal->value,
                };
                $variante = $datosVariante($detalle->product_id);

                return [
                    'detalle' => $detalle,
                    'sku' => $detalle->producto_sku,
                    'nombre' => $detalle->producto_nombre,
                    'color' => $variante['color'] ?? null,
                    'color_hex' => $hexDeColor($variante['color'] ?? null),
                    'talla' => $detalle->talla ?: ($variante['talla'] ?? null) ?: '-',
                    'prioridad' => $prioridad,
                    'prioridades' => $prioridades,
                    'sugerencia' => $sugerencias->get((int) $detalle->product_id),
                    'prioridad_orden' => $prioridadPeso[$prioridad] ?? 2,
                    'entregada' => $detalle->cantidad_vigente,
                    'original' => (int) $detalle->cantidad_entregada,
                    'cedida' => (int) $detalle->cantidad_reasignada_salida,
                    'en_transito' => (int) $detalle->cantidad_en_transito,
                    'reportada' => $reportada,
                    'recibida' => $recibida,
                    'defectuosa' => $defectuosa,
                    'faltante' => $faltante,
                    'pendiente' => $pendiente,
                ];
            })
            // La prioridad asignada manda; entre iguales, la sugerencia por stock.
            ->sortBy(fn ($fila) => sprintf('%02d-%02d-%s-%s', $fila['prioridad_orden'], \ByLopez\CorteConfeccion\Services\SugerenciaStockService::PESO[$fila['sugerencia']['nivel'] ?? 'sin_informacion'], $fila['sku'], $fila['talla']))
            ->values();
        $horaSugerencia = $sugerencias->first()['calculada_en'] ?? null;

        $totalEntregado = (int) $detallesResumen->sum('entregada');
        $totalReportado = (int) $detallesResumen->sum('reportada');
        $totalRecibido = (int) $detallesResumen->sum('recibida');
        $totalDefectuoso = (int) $detallesResumen->sum('defectuosa');
        $totalFaltante = (int) $detallesResumen->sum('faltante');
        // Pendiente = lo que el taller aun puede reportar (vigente - reportado - en transito); misma definicion que el backend.
        $totalPendiente = (int) $detallesResumen->sum('pendiente');
        $porcentajeReportado = $totalEntregado > 0 ? min(100, round(($totalReportado / $totalEntregado) * 100)) : 0;
        $variantesCompletas = $detallesResumen->where('pendiente', 0)->count();
        $variantesConPendiente = $detallesResumen->count() - $variantesCompletas;
        $retornos = $orden->retornos->sortByDesc('numero')->values();
        $retornosPendientesLista = $retornos->where('estado', $estadoRetornoPendiente)->values();
        $retornosPendientes = $retornosPendientesLista->count();
        $puedeReasignar = \ByLopez\CorteConfeccion\Support\ReasignacionGate::puedeReasignar();
        $puedeReasignarAhora = $puedeReasignar && in_array($orden->estado, [$estadoActiva, $estadoEnConfeccion], true) && $totalPendiente > 0;
        $puedeReportarAhora = $puedeReportarTaller && in_array($orden->estado, [$estadoActiva, $estadoEnConfeccion], true) && $totalPendiente > 0;
        // Una orden anulada o cerrada nunca debe exponer la recepcion como
        // mutable, aunque quedara historicamente un retorno en
        // pendiente_bodega (recibirBodega() ya lo rechaza en el Service; esto
        // evita mostrar un formulario que el backend va a rechazar igual).
        $ordenPermiteRecibir = ! in_array($orden->estado, [$estadoCerrada, $estadoAnulada], true);
        $listadoVolverRoute = 'admin.corte_confeccion.ordenes_confeccion.index';

        $movimientosOrden = $orden->movimientosInventario->sortByDesc('created_at')->values();
        $filasMovimientos = $movimientosOrden->map(fn ($movimiento) => \ByLopez\CorteConfeccion\Support\InventarioMovimientoHtml::filaDesdeModelo(
            $movimiento,
            $orden,
            $datosVariante($movimiento->product_id)['talla'] ?? null
        ));

        // Pestana inicial: ?tab= explicito; si se vuelve de un formulario
        // fallido (old input) se abre la pestana de ese formulario.
        $tabsValidas = ['produccion', 'recepciones', 'historial', 'movimientos'];
        $tabInicial = request()->query('tab');

        if (! in_array($tabInicial, $tabsValidas, true)) {
            $tabInicial = old('fecha_recepcion_bodega') !== null ? 'recepciones' : 'produccion';
        }

        $tabs = [
            'produccion' => ['Produccion', $detallesResumen->count(), 'icon-processing'],
            'recepciones' => ['Recepciones', $retornosPendientes, 'icon-store'],
            'historial' => ['Historial', $retornos->count(), 'icon-calendar'],
            'movimientos' => ['Movimientos', $movimientosOrden->count(), 'icon-product'],
        ];

        $inputClase = 'w-full rounded-lg border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white';
        $tarjeta = 'rounded-xl border border-slate-200 bg-white shadow-[0_1px_2px_rgba(15,23,42,0.04)] dark:border-gray-800 dark:bg-gray-900';
    @endphp

    {{--
        Este bundle de Tailwind es propio del paquete y solo aplica dentro de
        .bylopez-scope (ver el comentario de arriba). Un <style> plano dentro
        del slot del layout se descarta, por eso todo usa utilities.
    --}}
    @include('corteconfeccion::admin.partials.debugbar-local')

    <div class="bylopez-scope cc-pagina -m-4 min-h-[calc(100vh-64px)] bg-slate-50 p-4 max-sm:-m-3 max-sm:p-3 dark:bg-gray-950">
        <div class="mb-3 flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
            <span>Corte y Confeccion</span>
            <i class="icon-arrow-right text-sm"></i>
            <a href="{{ route($listadoVolverRoute) }}" class="hover:text-gray-800 hover:underline dark:hover:text-white">Ordenes de confeccion</a>
            <i class="icon-arrow-right text-sm"></i>
            <span class="font-medium text-gray-700 dark:text-gray-300">{{ $orden->codigo }}</span>
        </div>

        {{-- Encabezado (siempre visible). La prioridad vive por variante, no
        a nivel de orden: no se fabrica un badge de prioridad global. --}}
        <div class="{{ $tarjeta }} p-5">
            <div class="flex items-start justify-between gap-4 max-sm:flex-wrap">
                <div class="flex min-w-0 items-start gap-4">
                    <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-2xl text-blue-600 dark:bg-blue-950 dark:text-blue-300">
                        <span class="icon-processing"></span>
                    </span>

                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2.5">
                            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">{{ $orden->codigo }}</h1>
                            {!! $estadoOrdenConfeccion::badgeHtml($orden->estado) !!}
                        </div>

                        <div class="mt-2 flex flex-wrap gap-x-6 gap-y-1.5 text-sm text-gray-600 dark:text-gray-300">
                            @if ($orden->ordenCorte)
                                <span class="inline-flex items-center gap-1.5"><span class="icon-list text-base text-gray-400"></span>Orden de corte: <span class="font-medium text-gray-800 dark:text-white">{{ $orden->ordenCorte->codigo }}</span></span>
                            @endif

                            @if ($orden->taller)
                                <span class="inline-flex items-center gap-1.5"><span class="icon-store text-base text-gray-400"></span>Taller: <span class="font-medium text-gray-800 dark:text-white">{{ $orden->taller->nombre }}</span></span>
                            @endif

                            <span class="inline-flex items-center gap-1.5"><span class="icon-calendar text-base text-gray-400"></span>Fecha de asignacion: <span class="font-medium text-gray-800 dark:text-white">{{ $orden->created_at?->format('Y-m-d') ?: '-' }}</span></span>

                            @if ($orden->taller?->contacto)
                                <span class="inline-flex items-center gap-1.5"><span class="icon-users text-base text-gray-400"></span>Responsable: <span class="font-medium text-gray-800 dark:text-white">{{ $orden->taller->contacto }}</span></span>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    <a href="{{ route($listadoVolverRoute) }}" class="secondary-button whitespace-nowrap">Volver al listado</a>

                    @if ($puedeReasignarAhora)
                        <button type="button" class="primary-button whitespace-nowrap" data-reasignar-abrir>Reasignar prendas</button>
                    @endif

                    @if ($orden->estado === $estadoActiva && bouncer()->hasPermission('corte_confeccion.ordenes_confeccion.operar'))
                        <form
                            method="POST"
                            action="{{ route('admin.corte_confeccion.ordenes_confeccion.cancel', $orden->id) }}"
                            data-confirm="Esta accion anula la orden de confeccion. Confirmas la anulacion?"
                            data-confirm-title="Anular orden de confeccion"
                        >
                            @csrf
                            <button class="transparent-button">Anular</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>

        {{-- KPI (siempre visibles): mismos totales que ya calculaba esta
        vista (backend/Blade); no se recalcula nada en el navegador. --}}
        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <div class="{{ $tarjeta }} flex items-center gap-3 p-4">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-xl text-blue-600 dark:bg-blue-950 dark:text-blue-300"><span class="icon-cart"></span></span>
                <div class="min-w-0">
                    <p class="text-xs font-medium uppercase text-slate-500 dark:text-gray-400">Asignadas</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ $totalEntregado }}</p>
                </div>
            </div>

            <div class="{{ $tarjeta }} flex items-center gap-3 p-4">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-violet-50 text-xl text-violet-600 dark:bg-violet-950 dark:text-violet-300"><span class="icon-processing"></span></span>
                <div class="min-w-0">
                    <p class="text-xs font-medium uppercase text-slate-500 dark:text-gray-400">Reportadas</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ $totalReportado }}</p>
                </div>
            </div>

            <div class="{{ $tarjeta }} flex items-center gap-3 p-4">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-green-50 text-xl text-green-600 dark:bg-green-950 dark:text-green-300"><span class="icon-tick"></span></span>
                <div class="min-w-0">
                    <p class="text-xs font-medium uppercase text-slate-500 dark:text-gray-400">Buenas / aprobadas</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ $totalRecibido }}</p>
                    @if ($totalDefectuoso > 0 || $totalFaltante > 0)
                        <p class="text-xs text-slate-500 dark:text-gray-400">{{ $totalDefectuoso }} defect. / {{ $totalFaltante }} falt.</p>
                    @endif
                </div>
            </div>

            <div class="{{ $tarjeta }} flex items-center gap-3 p-4">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-orange-50 text-xl text-orange-600 dark:bg-orange-950 dark:text-orange-300"><span class="icon-pause"></span></span>
                <div class="min-w-0">
                    <p class="text-xs font-medium uppercase text-slate-500 dark:text-gray-400">Pendientes</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ $totalPendiente }}</p>
                    @if ($retornosPendientes > 0)
                        <p class="text-xs text-slate-500 dark:text-gray-400">{{ $retornosPendientes }} por recibir</p>
                    @endif
                </div>
            </div>

            <div class="{{ $tarjeta }} flex flex-col justify-center gap-1.5 p-4 sm:col-span-2 xl:col-span-1">
                <div class="flex items-center justify-between gap-3 text-sm">
                    <span class="font-medium text-gray-800 dark:text-white">Progreso de confeccion</span>
                    <span class="font-semibold text-gray-800 dark:text-white">{{ $porcentajeReportado }}%</span>
                </div>
                <div class="h-2 rounded-full bg-slate-200 dark:bg-gray-800">
                    <div class="h-2 rounded-full bg-blue-600" style="width: {{ $porcentajeReportado }}%"></div>
                </div>
                <p class="text-xs text-slate-500 dark:text-gray-400">{{ $totalReportado }} / {{ $totalEntregado }} reportadas</p>
            </div>
        </div>

        <div class="mt-4">
            @include('corteconfeccion::admin.partials.tarifa-orden', [
                'orden' => $orden,
                'tarifasOrden' => $tarifasOrden,
                'tipo' => 'taller',
                'ruta' => 'admin.corte_confeccion.ordenes_confeccion.tarifa',
            ])
        </div>

        <div class="mt-4">
            @include('corteconfeccion::admin.partials.cuenta-orden', ['cuentaOrden' => \ByLopez\CorteConfeccion\Support\CarteraVista::cuentaOrden($orden)])
        </div>

        @include('corteconfeccion::admin.partials.reasignaciones-orden', [
            'orden' => $orden,
            'reasignaciones' => $reasignaciones,
            'puedeReasignar' => $puedeReasignar,
            'puedeReasignarAhora' => $puedeReasignarAhora,
        ])

        {{-- Navegacion por pestanas: una sola visible a la vez. Todos los
        paneles se renderizan (solo se ocultan) para conservar formularios,
        anclas y validaciones existentes. Los contadores salen de datos ya
        cargados. --}}
        <div class="mt-5 border-b border-slate-200 dark:border-gray-800" role="tablist" data-tabs>
            <div class="-mb-px flex flex-wrap gap-1">
                @foreach ($tabs as $clave => [$etiqueta, $cantidad, $icono])
                    <button
                        type="button"
                        role="tab"
                        data-tab="{{ $clave }}"
                        aria-selected="{{ $tabInicial === $clave ? 'true' : 'false' }}"
                        class="{{ $tabInicial === $clave ? 'border-blue-600 text-blue-600 dark:text-blue-400' : 'border-transparent text-slate-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-white' }} inline-flex items-center gap-2 border-b-2 px-4 py-3 text-sm font-semibold transition-all"
                    >
                        <span class="{{ $icono }} text-base"></span>
                        {{ $etiqueta }}
                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-600 dark:bg-gray-800 dark:text-gray-300 {{ $clave === 'recepciones' && $cantidad > 0 ? '!bg-amber-100 !text-amber-700' : '' }}">{{ $cantidad }}</span>
                    </button>
                @endforeach
            </div>
        </div>

        {{-- ===================== PRODUCCION ===================== --}}
        <section data-tab-panel="produccion" class="{{ $tabInicial === 'produccion' ? '' : 'hidden' }} mt-4">
            <div class="flex gap-4 max-lg:flex-col">
                <div class="min-w-0 flex-1">
                    <div class="{{ $tarjeta }} p-5">
                        <p class="text-base font-semibold text-gray-900 dark:text-white">Produccion por variante</p>
                        <p class="text-xs text-slate-500 dark:text-gray-400">Cantidades asignadas, reportadas y pendientes por variante.</p>
                        <p class="mt-1 text-xs text-slate-500 dark:text-gray-400" data-testid="sugerencia-calculada">
                            La <b>prioridad asignada</b> manda. La <b>sugerencia por stock</b> solo ayuda a elegir entre variantes con la misma prioridad asignada.
                            @if ($horaSugerencia)
                                Calculada a las {{ $horaSugerencia->format('H:i:s') }} con el disponible actual.
                            @endif
                        </p>

                        @if ($puedeReportarTaller && ! $puedeReportarAhora && $totalPendiente === 0 && $ordenPermiteRecibir)
                            {{-- El taller ya reporto todo lo asignado. No es un estado nuevo:
                            usa terminada_taller / recibida_bodega ya existentes. --}}
                            <div class="mt-3 rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-300">
                                <p class="font-semibold">Produccion reportada completamente.</p>
                                @if ($retornosPendientes > 0)
                                    <p class="mt-1">Pendiente de recepcion por Bodega ({{ $retornosPendientes }} {{ $retornosPendientes === 1 ? 'entrega' : 'entregas' }}).</p>
                                @endif
                            </div>
                        @endif

                        @if ($puedeReportarAhora)
                            <form method="POST" action="{{ route('admin.corte_confeccion.ordenes_confeccion.reportar_taller', $orden->id) }}" data-reporte-taller>
                                @csrf

                                <div data-reporte-edit>
                                    @error('detalles')
                                        <p class="mt-3 text-sm text-red-600">{{ $message }}</p>
                                    @enderror
                                    @if (collect($errors->keys())->contains(fn ($k) => str_ends_with($k, '.reparto')))
                                        <p class="mt-3 rounded-md border border-red-200 bg-red-50 p-2 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300" role="alert">No se registró la entrega: revisa el reparto por prioridad marcado abajo. Tus cantidades se conservaron.</p>
                                    @endif
                                    <p class="mt-3 text-xs text-slate-500 dark:text-gray-400">Al entregar se descuenta primero lo <b>Urgente</b>, luego <b>Alta</b> y al final <b>Normal</b>. Si confeccionaste otro nivel, usa «Ajustar».</p>

                                    <div class="mt-3 overflow-x-auto">
                                        {{-- Compacta: Color y Talla en una sola columna, SKU bajo el nombre
                                        en anchos medios, padding reducido. En movil (<md) cada fila pasa a
                                        una tarjeta apilada (sin scroll lateral). --}}
                                        <table class="w-full text-left text-sm [&_th]:whitespace-nowrap [&_th]:px-2 [&_th]:py-2 [&_td]:px-2 [&_td]:py-2 [&_td]:align-middle max-md:block max-md:[&_tbody]:block max-md:[&_thead]:hidden max-md:[&_tfoot]:hidden">
                                            <thead>
                                                <tr class="border-b bg-slate-50 text-xs uppercase text-slate-500 dark:border-gray-800 dark:bg-gray-950 dark:text-gray-400">
                                                    <th class="w-8"></th>
                                                    <th>Producto / variante</th>
                                                    <th>Color / Talla</th>
                                                    <th class="max-lg:hidden">SKU</th>
                                                    <th>Prioridad asignada</th>
                                                    <th>Sugerencia por stock</th>
                                                    <th>Asig.</th>
                                                    <th>Report.</th>
                                                    <th>Pend.</th>
                                                    <th class="min-w-[150px]">Entrega actual</th>
                                                    <th>Acciones</th>
                                                </tr>
                                            </thead>

                                            <tbody>
                                                @foreach ($detallesResumen as $fila)
                                                    @php
                                                        $detalle = $fila['detalle'];
                                                        // Indice = id del detalle: el orden de las filas puede cambiar entre envios (prioridad,
                                                        // sugerencia) y lo escrito debe volver a SU variante tras un error.
                                                        $i = $detalle->id;
                                                        $cantidadOld = old("detalles.$i.cantidad");
                                                        $disp = $fila['prioridades'];
                                                        $soloNormal = $disp['urgente'] + $disp['alta'] === 0;
                                                        $ajusteOld = collect(['urgente', 'alta', 'normal'])->contains(fn ($n) => old("detalles.$i.$n") !== null);
                                                    @endphp

                                                    <tr class="border-b dark:border-gray-800 {{ $fila['pendiente'] === 0 ? 'bg-green-50/40 dark:bg-green-950/20' : '' }} max-md:mb-2 max-md:grid max-md:grid-cols-2 max-md:gap-x-3 max-md:rounded-lg max-md:border max-md:p-1" data-reporte-card data-sku="{{ $fila['sku'] }}" data-talla="{{ $fila['talla'] }}">
                                                        <td class="max-md:col-span-2">
                                                            <input type="checkbox" class="rounded border-gray-300" data-select-row @disabled($fila['pendiente'] === 0)>
                                                        </td>
                                                        <td class="text-gray-800 dark:text-white max-md:col-span-2">
                                                            <p class="font-medium">{{ $fila['nombre'] ?: $fila['sku'] }}</p>
                                                            <p class="font-mono text-[11px] text-gray-500 dark:text-gray-400 lg:hidden">{{ $fila['sku'] }}</p>
                                                        </td>
                                                        <td class="max-md:col-span-2">
                                                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                                                @include('corteconfeccion::admin.ordenes-confeccion.partials.color-variante', ['color' => $fila['color'], 'hex' => $fila['color_hex']])
                                                                <span class="text-xs text-gray-600 dark:text-gray-300">Talla {{ $fila['talla'] }}</span>
                                                            </div>
                                                        </td>
                                                        <td class="font-mono text-xs text-gray-600 dark:text-gray-300 max-lg:hidden">{{ $fila['sku'] }}</td>
                                                        <td class="max-md:col-span-2 max-md:before:block max-md:before:text-[10px] max-md:before:uppercase max-md:before:text-slate-400 max-md:before:content-['Prioridad_asignada']" data-testid="prioridad-asignada">@include('corteconfeccion::admin.ordenes-confeccion.partials.prioridades', ['p' => $fila['prioridades']])</td>
                                                        <td class="max-md:col-span-2 max-md:before:block max-md:before:text-[10px] max-md:before:uppercase max-md:before:text-slate-400 max-md:before:content-['Sugerencia_por_stock']" data-testid="sugerencia-stock">@include('corteconfeccion::admin.ordenes-confeccion.partials.sugerencia-stock', ['s' => $fila['sugerencia'], 'conCifras' => $verCifrasStock])</td>
                                                        <td class="text-gray-800 dark:text-white max-md:before:block max-md:before:text-[10px] max-md:before:uppercase max-md:before:text-slate-400 max-md:before:content-['Asignadas']">{{ $fila['entregada'] }}@if ($fila['cedida'] > 0)<span class="block text-[10px] font-normal text-amber-600">orig. {{ $fila['original'] }} · cedidas {{ $fila['cedida'] }}</span>@endif @if ($fila['en_transito'] > 0)<span class="block text-[10px] font-normal text-amber-600">{{ $fila['en_transito'] }} en transito</span>@endif</td>
                                                        <td class="text-gray-800 dark:text-white max-md:before:block max-md:before:text-[10px] max-md:before:uppercase max-md:before:text-slate-400 max-md:before:content-['Reportadas']">{{ $fila['reportada'] }}</td>
                                                        <td class="font-semibold text-gray-800 dark:text-white max-md:before:block max-md:before:text-[10px] max-md:before:font-normal max-md:before:uppercase max-md:before:text-slate-400 max-md:before:content-['Pendientes']">
                                                            @if ($fila['pendiente'] === 0)
                                                                <span class="inline-flex items-center gap-1 text-green-600 dark:text-green-400" title="Completada"><span class="icon-tick text-base"></span>0</span>
                                                            @else
                                                                {{ $fila['pendiente'] }}
                                                            @endif
                                                        </td>
                                                        <td class="max-md:col-span-2">
                                                            <input type="hidden" name="detalles[{{ $i }}][id]" value="{{ $detalle->id }}" data-report-id>
                                                            <input
                                                                name="detalles[{{ $i }}][cantidad]"
                                                                value="{{ $cantidadOld }}"
                                                                type="number"
                                                                min="0"
                                                                max="{{ $fila['pendiente'] }}"
                                                                placeholder="Entrega actual"
                                                                class="{{ $inputClase }} disabled:cursor-not-allowed disabled:bg-slate-50"
                                                                data-report-input
                                                                data-max="{{ $fila['pendiente'] }}"
                                                                @disabled($fila['pendiente'] === 0)
                                                            >
                                                            @error("detalles.$i.cantidad")
                                                                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                                            @enderror

                                                            {{-- P1: reparto por prioridad de lo que se entrega. Sin «Ajustar», el servidor aplica el sugerido
                                                            (Urgente -> Alta -> Normal); `base` permite detectar si el saldo cambio entretanto. --}}
                                                            <div class="mt-1 text-[11px] text-gray-600 dark:text-gray-300" data-reparto data-u="{{ $disp['urgente'] }}" data-a="{{ $disp['alta'] }}" data-n="{{ $disp['normal'] }}" data-solo-normal="{{ $soloNormal ? '1' : '0' }}">
                                                                <input type="hidden" name="detalles[{{ $i }}][base]" value="{{ implode(':', $disp) }}" data-reparto-campo>
                                                                <span data-reparto-texto></span>
                                                                @unless ($soloNormal)
                                                                    <button type="button" class="ml-1 font-semibold text-blue-600 hover:underline dark:text-blue-400" data-reparto-ajustar aria-expanded="{{ $ajusteOld ? 'true' : 'false' }}">{{ $ajusteOld ? 'Usar sugerido' : 'Ajustar' }}</button>
                                                                    <div class="mt-1 grid grid-cols-3 gap-1 {{ $ajusteOld ? '' : 'hidden' }}" data-reparto-panel>
                                                                        @foreach (['urgente' => 'Urgente', 'alta' => 'Alta', 'normal' => 'Normal'] as $nivel => $nombreNivel)
                                                                            <label class="block text-[10px] text-gray-500 dark:text-gray-400">
                                                                                {{ $nombreNivel }} <span class="whitespace-nowrap">(máx. {{ $disp[$nivel] }})</span>
                                                                                <input
                                                                                    type="text"
                                                                                    inputmode="numeric"
                                                                                    autocomplete="off"
                                                                                    name="detalles[{{ $i }}][{{ $nivel }}]"
                                                                                    value="{{ old("detalles.$i.$nivel") }}"
                                                                                    class="mt-0.5 w-full rounded border px-1.5 py-1 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                                                                                    data-reparto-campo
                                                                                    data-reparto-nivel="{{ $nivel }}"
                                                                                    @disabled(! $ajusteOld)
                                                                                >
                                                                            </label>
                                                                        @endforeach
                                                                    </div>
                                                                @endunless
                                                                <p class="mt-0.5 hidden text-red-600" data-reparto-error role="alert"></p>
                                                                @error("detalles.$i.reparto")
                                                                    <p class="mt-0.5 text-xs text-red-600" role="alert">{{ $message }}</p>
                                                                @enderror
                                                            </div>
                                                        </td>
                                                        <td class="max-md:col-span-1">
                                                            <button type="button" class="secondary-button whitespace-nowrap {{ $fila['pendiente'] === 0 ? 'cursor-not-allowed opacity-50' : '' }}" data-fill-row @disabled($fila['pendiente'] === 0) @if ($fila['pendiente'] === 0) title="Sin pendiente: variante completada" @endif>Entregar todo</button>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>

                                            <tfoot>
                                                <tr class="border-t-2 dark:border-gray-700">
                                                    <td colspan="5" class="font-semibold text-gray-800 dark:text-white lg:hidden">Total</td>
                                                    <td colspan="6" class="font-semibold text-gray-800 dark:text-white max-lg:hidden">Total</td>
                                                    <td class="font-semibold text-gray-800 dark:text-white">{{ $totalEntregado }}</td>
                                                    <td class="font-semibold text-gray-800 dark:text-white">{{ $totalReportado }}</td>
                                                    <td class="font-semibold text-gray-800 dark:text-white">{{ $totalPendiente }}</td>
                                                    <td></td>
                                                    <td></td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>

                                    <div class="mt-3 flex flex-wrap items-center justify-between gap-3 border-t pt-3 dark:border-gray-800">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <button type="button" class="secondary-button" data-select-pending>Seleccionar pendientes</button>
                                            <button type="button" class="transparent-button" data-clear-report>Limpiar</button>
                                            <button type="button" class="secondary-button" data-fill-selected>Entregar todo seleccionado</button>
                                        </div>

                                        <div class="flex flex-wrap items-center gap-2">
                                            <input name="fecha_reporte_taller" type="date" value="{{ old('fecha_reporte_taller', now()->toDateString()) }}" class="rounded-lg border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white">
                                            <button type="button" class="primary-button h-10 justify-center whitespace-nowrap" data-review-report>Revisar entrega</button>
                                        </div>
                                    </div>

                                    <div class="mt-3">
                                        <label class="text-xs text-gray-500 dark:text-gray-400">Observacion general del taller</label>
                                        <textarea name="observacion_taller" rows="2" class="mt-1 {{ $inputClase }}">{{ old('observacion_taller') }}</textarea>
                                    </div>
                                </div>

                                <dialog class="entrega-review-modal rounded-lg bg-white p-0 text-gray-800 shadow-xl dark:bg-gray-900 dark:text-white" data-reporte-review aria-labelledby="entrega-review-title" aria-describedby="entrega-review-description">
                                    <div class="flex items-center justify-between gap-3 border-b px-4 py-3 dark:border-gray-800">
                                        <div class="flex items-center gap-2.5">
                                            <span class="icon-product text-2xl text-blue-600" aria-hidden="true"></span>
                                            <h2 id="entrega-review-title" class="text-lg font-semibold">Confirmar entrega a Bodega</h2>
                                        </div>
                                        <button type="button" class="icon-cancel-1 rounded-md text-3xl hover:bg-gray-100 dark:hover:bg-gray-950" data-back-report aria-label="Cerrar revisión"></button>
                                    </div>
                                    <div class="entrega-review-content px-4 py-4">
                                        <p id="entrega-review-description" class="text-sm text-gray-500 dark:text-gray-400">Verifica las cantidades antes de registrar la entrega.</p>
                                        <div class="mt-4 space-y-2" data-review-list></div>
                                        <div class="mt-4 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 dark:border-gray-700 dark:bg-gray-950">
                                            <span class="text-sm font-semibold">Total a entregar:</span>
                                            <span class="text-xl font-bold text-blue-600 dark:text-blue-400"><span data-review-total>0</span> prendas</span>
                                        </div>
                                    </div>

                                    <div class="entrega-review-footer flex justify-end gap-2 border-t px-4 py-3 dark:border-gray-800">
                                        <button type="button" class="secondary-button" data-back-report autofocus>Volver</button>
                                        <button type="submit" class="primary-button">Confirmar entrega</button>
                                    </div>
                                </dialog>
                            </form>
                        @else
                            <div class="mt-3 overflow-x-auto">
                                <table class="w-full text-left text-sm [&_th]:whitespace-nowrap [&_th]:px-2 [&_th]:py-2 [&_td]:px-2 [&_td]:py-2 [&_td]:align-middle max-md:block max-md:[&_tbody]:block max-md:[&_thead]:hidden max-md:[&_tfoot]:hidden">
                                    <thead>
                                        <tr class="border-b bg-slate-50 text-xs uppercase text-slate-500 dark:border-gray-800 dark:bg-gray-950 dark:text-gray-400">
                                            <th>Producto / variante</th>
                                            <th>Color / Talla</th>
                                            <th class="max-lg:hidden">SKU</th>
                                            <th>Prioridad asignada</th>
                                            <th>Sugerencia por stock</th>
                                            <th>Asig.</th>
                                            <th>Report.</th>
                                            <th>Pend.</th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        @foreach ($detallesResumen as $fila)
                                            <tr class="border-b dark:border-gray-800 {{ $fila['pendiente'] === 0 ? 'bg-green-50/40 dark:bg-green-950/20' : '' }} max-md:mb-2 max-md:grid max-md:grid-cols-3 max-md:gap-x-3 max-md:rounded-lg max-md:border max-md:p-1">
                                                <td class="text-gray-800 dark:text-white max-md:col-span-3">
                                                    <p class="font-medium">{{ $fila['nombre'] ?: $fila['sku'] }}</p>
                                                    <p class="font-mono text-[11px] text-gray-500 dark:text-gray-400 lg:hidden">{{ $fila['sku'] }}</p>
                                                </td>
                                                <td class="max-md:col-span-3">
                                                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                                        @include('corteconfeccion::admin.ordenes-confeccion.partials.color-variante', ['color' => $fila['color'], 'hex' => $fila['color_hex']])
                                                        <span class="text-xs text-gray-600 dark:text-gray-300">Talla {{ $fila['talla'] }}</span>
                                                    </div>
                                                </td>
                                                <td class="font-mono text-xs text-gray-600 dark:text-gray-300 max-lg:hidden">{{ $fila['sku'] }}</td>
                                                <td class="max-md:col-span-3 max-md:before:block max-md:before:text-[10px] max-md:before:uppercase max-md:before:text-slate-400 max-md:before:content-['Prioridad_asignada']" data-testid="prioridad-asignada">@include('corteconfeccion::admin.ordenes-confeccion.partials.prioridades', ['p' => $fila['prioridades']])</td>
                                                <td class="max-md:col-span-3 max-md:before:block max-md:before:text-[10px] max-md:before:uppercase max-md:before:text-slate-400 max-md:before:content-['Sugerencia_por_stock']" data-testid="sugerencia-stock">@include('corteconfeccion::admin.ordenes-confeccion.partials.sugerencia-stock', ['s' => $fila['sugerencia'], 'conCifras' => $verCifrasStock])</td>
                                                <td class="text-gray-800 dark:text-white max-md:before:block max-md:before:text-[10px] max-md:before:uppercase max-md:before:text-slate-400 max-md:before:content-['Asignadas']">{{ $fila['entregada'] }}@if ($fila['cedida'] > 0)<span class="block text-[10px] font-normal text-amber-600">orig. {{ $fila['original'] }} · cedidas {{ $fila['cedida'] }}</span>@endif @if ($fila['en_transito'] > 0)<span class="block text-[10px] font-normal text-amber-600">{{ $fila['en_transito'] }} en transito</span>@endif</td>
                                                <td class="text-gray-800 dark:text-white max-md:before:block max-md:before:text-[10px] max-md:before:uppercase max-md:before:text-slate-400 max-md:before:content-['Reportadas']">{{ $fila['reportada'] }}</td>
                                                <td class="font-semibold text-gray-800 dark:text-white max-md:before:block max-md:before:text-[10px] max-md:before:font-normal max-md:before:uppercase max-md:before:text-slate-400 max-md:before:content-['Pendientes']">
                                                    @if ($fila['pendiente'] === 0)
                                                        <span class="inline-flex items-center gap-1 text-green-600 dark:text-green-400" title="Completada"><span class="icon-tick text-base"></span>0</span>
                                                    @else
                                                        {{ $fila['pendiente'] }}
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>

                                    <tfoot>
                                        <tr class="border-t-2 dark:border-gray-700">
                                            <td colspan="4" class="font-semibold text-gray-800 dark:text-white lg:hidden">Total</td>
                                            <td colspan="5" class="font-semibold text-gray-800 dark:text-white max-lg:hidden">Total</td>
                                            <td class="font-semibold text-gray-800 dark:text-white">{{ $totalEntregado }}</td>
                                            <td class="font-semibold text-gray-800 dark:text-white">{{ $totalReportado }}</td>
                                            <td class="font-semibold text-gray-800 dark:text-white">{{ $totalPendiente }}</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Panel derecho: secundario respecto a la tabla. --}}
                <div class="w-[300px] shrink-0 space-y-4 max-lg:w-full">
                    <div class="{{ $tarjeta }} p-4">
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">Informacion del taller</p>

                        <div class="mt-3 grid gap-y-2.5 text-sm">
                            <div>
                                <p class="text-xs uppercase text-slate-500 dark:text-gray-400">Nombre</p>
                                <p class="font-semibold text-gray-800 dark:text-white">{{ $orden->taller?->nombre ?: '-' }}</p>
                            </div>

                            @if ($orden->taller?->contacto)
                                <div>
                                    <p class="text-xs uppercase text-slate-500 dark:text-gray-400">Responsable</p>
                                    <p class="text-gray-700 dark:text-gray-300">{{ $orden->taller->contacto }}</p>
                                </div>
                            @endif

                            @if ($orden->taller?->telefono)
                                <div>
                                    <p class="text-xs uppercase text-slate-500 dark:text-gray-400">Telefono</p>
                                    <p class="text-gray-700 dark:text-gray-300"><i class="icon-phone mr-1 align-middle"></i>{{ $orden->taller->telefono }}</p>
                                </div>
                            @endif

                            @if ($orden->taller?->direccion)
                                <div>
                                    <p class="text-xs uppercase text-slate-500 dark:text-gray-400">Direccion</p>
                                    <p class="text-gray-700 dark:text-gray-300"><i class="icon-location mr-1 align-middle"></i>{{ $orden->taller->direccion }}</p>
                                </div>
                            @endif

                            @if ($orden->taller?->usuario)
                                <div>
                                    <p class="text-xs uppercase text-slate-500 dark:text-gray-400">Usuario asociado</p>
                                    <p class="break-all text-gray-700 dark:text-gray-300">{{ $orden->taller->usuario->email }}</p>
                                </div>
                            @endif

                            <div>
                                <p class="text-xs uppercase text-slate-500 dark:text-gray-400">Estado</p>
                                <p class="mt-0.5 {{ $orden->taller?->estado === 'activo' ? 'label-active' : 'label-canceled' }} w-max text-sm">
                                    {{ $orden->taller?->estado === 'activo' ? 'Activo' : 'Inactivo' }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="{{ $tarjeta }} p-4">
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">Resumen de estado</p>

                        <dl class="mt-3 grid gap-y-2 text-sm">
                            <div class="flex justify-between gap-3"><dt class="text-slate-500 dark:text-gray-400">Variantes completas</dt><dd class="font-semibold text-green-600 dark:text-green-400">{{ $variantesCompletas }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-slate-500 dark:text-gray-400">Variantes con pendiente</dt><dd class="font-semibold text-gray-800 dark:text-white">{{ $variantesConPendiente }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-slate-500 dark:text-gray-400">Total variantes</dt><dd class="font-semibold text-gray-800 dark:text-white">{{ $detallesResumen->count() }}</dd></div>
                            <div class="flex justify-between gap-3 border-t pt-2 dark:border-gray-800"><dt class="text-slate-500 dark:text-gray-400">Total asignadas</dt><dd class="font-semibold text-gray-800 dark:text-white">{{ $totalEntregado }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-slate-500 dark:text-gray-400">Total reportadas</dt><dd class="font-semibold text-gray-800 dark:text-white">{{ $totalReportado }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-slate-500 dark:text-gray-400">Total pendientes</dt><dd class="font-semibold text-gray-800 dark:text-white">{{ $totalPendiente }}</dd></div>
                        </dl>

                        @if ($variantesConPendiente > 0)
                            <p class="mt-3 rounded-lg border border-blue-100 bg-blue-50 p-2.5 text-xs text-blue-800 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-200">
                                Aun hay {{ $variantesConPendiente }} {{ $variantesConPendiente === 1 ? 'variante' : 'variantes' }} con cantidades pendientes por entregar.
                            </p>
                        @endif
                    </div>
                </div>
            </div>

            @if ($orden->observacion)
                <div class="{{ $tarjeta }} mt-4 p-5">
                    <p class="text-base font-semibold text-gray-900 dark:text-white">Informacion adicional</p>
                    <p class="mt-2 text-sm text-gray-700 dark:text-gray-300">{{ $orden->observacion }}</p>
                </div>
            @endif
        </section>

        {{-- ===================== RECEPCIONES ===================== --}}
        <section data-tab-panel="recepciones" class="{{ $tabInicial === 'recepciones' ? '' : 'hidden' }} mt-4 space-y-4">
            {{-- A. Bandeja de trabajo pendiente --}}
            <div class="{{ $tarjeta }} p-4 sm:p-5">
                <div class="flex flex-wrap items-center gap-2">
                    <p class="text-base font-semibold text-gray-900 dark:text-white">Entregas pendientes de recepcion</p>
                    @if ($retornosPendientes > 0)
                        <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700">Trabajo pendiente</span>
                    @endif
                </div>
                <p class="text-xs text-slate-500 dark:text-gray-400">Entregas reportadas por el taller que requieren revision fisica en Bodega. Elige una para registrar la recepcion.</p>

                @if ($retornosPendientes > 0)
                    {{-- Escritorio/tablet: tabla compacta. --}}
                    <div class="mt-3 overflow-hidden rounded-lg border dark:border-gray-800 max-md:hidden">
                        <table class="w-full text-left text-sm [&_th]:whitespace-nowrap [&_th]:px-3 [&_th]:py-2 [&_td]:px-3 [&_td]:py-2 [&_td]:align-middle">
                            <thead>
                                <tr class="border-b bg-slate-50 text-xs uppercase text-slate-500 dark:border-gray-800 dark:bg-gray-950 dark:text-gray-400">
                                    <th>Codigo</th>
                                    <th>Fecha entrega</th>
                                    <th>Reportadas</th>
                                    <th>Estado</th>
                                    <th class="text-right">Acciones</th>
                                </tr>
                            </thead>

                            <tbody>
                                @foreach ($retornosPendientesLista as $indicePendiente => $retornoPendiente)
                                    <tr class="border-b last:border-b-0 dark:border-gray-800 {{ $indicePendiente === 0 ? 'bg-blue-50/60 dark:bg-blue-950/30' : '' }}" data-retorno-fila="{{ $retornoPendiente->id }}">
                                        <td class="font-medium text-gray-800 dark:text-white">{{ $retornoPendiente->codigo }}</td>
                                        <td class="text-gray-700 dark:text-gray-300">{{ $retornoPendiente->fecha_reporte_taller?->format('Y-m-d H:i') ?: '-' }}</td>
                                        <td class="font-semibold text-gray-800 dark:text-white">{{ (int) $retornoPendiente->detalles->sum('cantidad_reportada') }}</td>
                                        <td>{!! $estadoOrdenConfeccionRetorno::badgeHtml($retornoPendiente->estado) !!}</td>
                                        <td class="text-right">
                                            @if ($puedeRecibirBodega && $ordenPermiteRecibir)
                                                <a href="#retorno-{{ $retornoPendiente->id }}" class="inline-flex items-center gap-1 rounded-md border border-blue-200 px-2.5 py-1 text-xs font-semibold text-blue-600 hover:bg-blue-50 dark:border-blue-900 dark:text-blue-400">Revisar entrega</a>
                                            @else
                                                <span class="text-gray-400 dark:text-gray-500">-</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    {{-- Movil: una card compacta por entrega. --}}
                    <div class="mt-3 space-y-2 md:hidden">
                        @foreach ($retornosPendientesLista as $indicePendiente => $retornoPendiente)
                            <div class="rounded-lg border p-3 dark:border-gray-800 {{ $indicePendiente === 0 ? 'border-blue-300 bg-blue-50/60 dark:bg-blue-950/30' : '' }}" data-retorno-fila="{{ $retornoPendiente->id }}">
                                <div class="flex items-start justify-between gap-2">
                                    <p class="font-semibold text-gray-900 dark:text-white">{{ $retornoPendiente->codigo }}</p>
                                    {!! $estadoOrdenConfeccionRetorno::badgeHtml($retornoPendiente->estado) !!}
                                </div>
                                <p class="mt-1 text-xs text-slate-500 dark:text-gray-400">{{ $retornoPendiente->fecha_reporte_taller?->format('Y-m-d H:i') ?: '-' }}</p>
                                <div class="mt-2 flex items-center justify-between gap-2">
                                    <p class="text-sm text-gray-700 dark:text-gray-300">Reportadas: <span class="font-semibold">{{ (int) $retornoPendiente->detalles->sum('cantidad_reportada') }}</span></p>
                                    @if ($puedeRecibirBodega && $ordenPermiteRecibir)
                                        <a href="#retorno-{{ $retornoPendiente->id }}" class="rounded-md border border-blue-200 px-3 py-1.5 text-xs font-semibold text-blue-600 dark:border-blue-900 dark:text-blue-400">Revisar entrega</a>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="mt-3 rounded-lg border p-4 text-sm text-gray-600 dark:border-gray-800 dark:text-gray-300">
                        No hay entregas pendientes de recepcion.
                    </div>
                @endif
            </div>

            {{-- B/C/D/E/F/G. Entrega en revision: una a la vez (la primera por
            defecto; "Revisar entrega" cambia de entrega). Todas las
            recepciones siguen siendo formularios independientes. --}}
            @foreach ($retornosPendientesLista as $indicePendiente => $retorno)
                @if ($puedeRecibirBodega && $ordenPermiteRecibir)
                    <div class="{{ $indicePendiente === 0 ? '' : 'hidden' }} space-y-3" id="retorno-{{ $retorno->id }}" data-recepcion-card="{{ $retorno->id }}">
                        {{-- B. Encabezado de la entrega seleccionada --}}
                        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950">
                            <div class="flex items-center gap-3">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-blue-600 text-xl text-white"><span class="icon-store"></span></span>
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-wide text-blue-700 dark:text-blue-300">Entrega en revision</p>
                                    <p class="text-lg font-bold text-gray-900 dark:text-white">{{ $retorno->codigo }}</p>
                                </div>
                            </div>
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-gray-600 dark:text-gray-300">
                                <span>Reportada el <span class="font-medium text-gray-800 dark:text-white">{{ $retorno->fecha_reporte_taller?->format('Y-m-d H:i') ?: '-' }}</span></span>
                                {!! $estadoOrdenConfeccionRetorno::badgeHtml($retorno->estado) !!}
                            </div>
                            @if ($retorno->observacion_taller)
                                <p class="w-full text-sm text-gray-700 dark:text-gray-300">{{ $retorno->observacion_taller }}</p>
                            @endif
                        </div>

                        <form method="POST" action="{{ route('admin.corte_confeccion.ordenes_confeccion.recibir_bodega', $retorno->id) }}" class="{{ $tarjeta }} p-4 sm:p-5" data-recepcion-bodega>
                            @csrf

                            <div class="flex flex-wrap items-end justify-between gap-3">
                                <div>
                                    <p class="text-base font-semibold text-gray-900 dark:text-white">Recepcion fisica de bodega</p>
                                    <p class="text-xs text-slate-500 dark:text-gray-400">Faltantes se calcula automaticamente: reportadas menos buenas/aprobadas menos defectuosas.</p>
                                </div>
                                <label class="text-xs text-gray-500 dark:text-gray-400">
                                    Fecha de recepcion
                                    <input name="fecha_recepcion_bodega" type="date" value="{{ old('fecha_recepcion_bodega', now()->toDateString()) }}" class="mt-1 block rounded-lg border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white">
                                </label>
                            </div>

                            @if ($errors->any())
                                <div class="mt-3 rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-800" role="alert">
                                    <p class="font-semibold">No se guardo la recepcion. Corrige lo siguiente:</p>
                                    <ul class="mt-1 list-disc pl-5">
                                        @foreach (array_unique($errors->all()) as $mensajeError)
                                            <li>{{ $mensajeError }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            <div class="mt-4 flex gap-4 max-lg:flex-col">
                                {{-- D. Tarjetas de variantes (izquierda). En movil, colapsables:
                                solo la primera queda abierta (ver script). --}}
                                <div class="min-w-0 flex-1 space-y-2.5">
                                    @foreach ($retorno->detalles as $i => $detalleRetorno)
                                        @php
                                            $varianteRetorno = $datosVariante($detalleRetorno->product_id);
                                            $tallaRetorno = $detalleRetorno->talla ?: ($varianteRetorno['talla'] ?? null) ?: '-';
                                        @endphp

                                        <details open class="group rounded-lg border dark:border-gray-800" data-recepcion-fila data-reportada="{{ $detalleRetorno->cantidad_reportada }}" data-recepcion-variante>
                                            <summary class="flex cursor-pointer list-none items-start justify-between gap-3 p-3">
                                                <div class="min-w-0">
                                                    <p class="font-mono text-[11px] text-slate-500 dark:text-gray-400">{{ $detalleRetorno->producto_sku }}</p>
                                                    <p class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $detalleRetorno->producto_nombre }}</p>
                                                    <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                                                        @include('corteconfeccion::admin.ordenes-confeccion.partials.color-variante', ['color' => $varianteRetorno['color'] ?? null, 'hex' => $hexDeColor($varianteRetorno['color'] ?? null)])
                                                        <span>Talla {{ $tallaRetorno }}</span>
                                                    </p>
                                                </div>
                                                <span class="shrink-0 rounded-lg bg-slate-100 px-3 py-1.5 text-center dark:bg-gray-800">
                                                    <span class="block text-[10px] font-semibold uppercase text-slate-500 dark:text-gray-400">Reportadas</span>
                                                    <span class="block text-lg font-bold leading-5 text-gray-900 dark:text-white">{{ $detalleRetorno->cantidad_reportada }}</span>
                                                </span>
                                            </summary>

                                            <div class="border-t p-3 dark:border-gray-800">
                                                <input type="hidden" name="detalles[{{ $i }}][id]" value="{{ $detalleRetorno->id }}">

                                                <div class="grid grid-cols-3 gap-2.5 max-[420px]:grid-cols-1">
                                                    <div>
                                                        <label class="text-[11px] font-medium text-gray-500 dark:text-gray-400">Buenas / aprobadas</label>
                                                        <input name="detalles[{{ $i }}][cantidad_recibida]" value="{{ old("detalles.$i.cantidad_recibida", $detalleRetorno->cantidad_reportada) }}" type="number" min="0" max="{{ $detalleRetorno->cantidad_reportada }}" class="mt-1 {{ $inputClase }}" data-campo-recibida>
                                                    </div>
                                                    <div>
                                                        <label class="text-[11px] font-medium text-gray-500 dark:text-gray-400">Defectuosas</label>
                                                        <input name="detalles[{{ $i }}][cantidad_defectuosa]" value="{{ old("detalles.$i.cantidad_defectuosa", 0) }}" type="number" min="0" max="{{ $detalleRetorno->cantidad_reportada }}" class="mt-1 {{ $inputClase }}" data-campo-defectuosa>
                                                    </div>
                                                    <div>
                                                        <label class="flex items-center gap-1 text-[11px] font-medium text-gray-400 dark:text-gray-500"><span class="icon-lock text-xs"></span>Faltantes (automatico)</label>
                                                        <input name="detalles[{{ $i }}][cantidad_faltante]" value="{{ old("detalles.$i.cantidad_faltante", 0) }}" type="number" min="0" max="{{ $detalleRetorno->cantidad_reportada }}" class="mt-1 cursor-not-allowed {{ $inputClase }} border-dashed bg-slate-100 text-slate-500 dark:bg-gray-800" data-campo-faltante readonly tabindex="-1">
                                                    </div>
                                                </div>

                                                <p class="mt-2 hidden text-xs text-red-600" data-recepcion-error>La suma de buenas y defectuosas no puede superar lo reportado. Se ajusto automaticamente.</p>

                                                <div class="mt-2.5">
                                                    <label class="text-[11px] font-medium text-gray-500 dark:text-gray-400">Comentario bodega</label>
                                                    <textarea name="detalles[{{ $i }}][observacion_bodega]" rows="1" placeholder="Opcional (obligatorio si hay defectuosas o faltantes)" class="mt-1 resize-y {{ $inputClase }}">{{ old("detalles.$i.observacion_bodega") }}</textarea>
                                                    @error("detalles.$i.observacion_bodega")
                                                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                                    @enderror
                                                </div>
                                            </div>
                                        </details>
                                    @endforeach
                                </div>

                                {{-- E. Resumen de recepcion (derecha; debajo en movil). Se
                                actualiza en vivo desde los mismos campos, sin recalcular reglas. --}}
                                <aside class="w-[270px] shrink-0 max-lg:w-full" data-recepcion-resumen data-total-variantes="{{ $retorno->detalles->count() }}">
                                    <div class="rounded-lg border bg-slate-50 p-4 dark:border-gray-800 dark:bg-gray-950 lg:sticky lg:top-4">
                                        <p class="text-sm font-semibold text-gray-900 dark:text-white">Resumen de recepcion</p>

                                        <dl class="mt-3 grid gap-y-2 text-sm">
                                            <div class="flex justify-between gap-3"><dt class="text-slate-500 dark:text-gray-400">Total variantes</dt><dd class="font-semibold text-gray-800 dark:text-white">{{ $retorno->detalles->count() }}</dd></div>
                                            <div class="flex justify-between gap-3"><dt class="text-slate-500 dark:text-gray-400">Total reportadas</dt><dd class="font-semibold text-gray-800 dark:text-white" data-resumen="reportadas">0</dd></div>
                                            <div class="flex justify-between gap-3"><dt class="text-slate-500 dark:text-gray-400">Buenas / aprobadas</dt><dd class="font-semibold text-green-600 dark:text-green-400" data-resumen="buenas">0</dd></div>
                                            <div class="flex justify-between gap-3"><dt class="text-slate-500 dark:text-gray-400">Defectuosas</dt><dd class="font-semibold text-gray-800 dark:text-white" data-resumen="defectuosas">0</dd></div>
                                            <div class="flex justify-between gap-3"><dt class="text-slate-500 dark:text-gray-400">Faltantes (auto)</dt><dd class="font-semibold text-gray-800 dark:text-white" data-resumen="faltantes">0</dd></div>
                                        </dl>

                                        <div class="mt-4 border-t pt-3 dark:border-gray-800">
                                            <div class="flex items-center justify-between text-xs">
                                                <span class="font-medium text-gray-700 dark:text-gray-300">Avance de recepcion</span>
                                                <span class="font-semibold text-gray-800 dark:text-white" data-resumen="porcentaje">0%</span>
                                            </div>
                                            <div class="mt-1.5 h-2 rounded-full bg-slate-200 dark:bg-gray-800"><div class="h-2 rounded-full bg-green-500" style="width: 0%" data-resumen="barra"></div></div>
                                            <p class="mt-1.5 text-[11px] text-slate-500 dark:text-gray-400">Buenas / aprobadas sobre lo reportado.</p>
                                        </div>
                                    </div>
                                </aside>
                            </div>

                            {{-- F. Observacion general: cierre del proceso --}}
                            <div class="mt-5 border-t pt-4 dark:border-gray-800">
                                <label class="text-sm font-semibold text-gray-800 dark:text-white">Observacion general bodega</label>
                                <textarea name="observacion_bodega" rows="2" placeholder="Notas generales de la recepcion (opcional)" class="mt-1.5 {{ $inputClase }}">{{ old('observacion_bodega') }}</textarea>
                            </div>

                            {{-- G. CTA principal --}}
                            <div class="mt-4 flex justify-end max-sm:justify-stretch">
                                <button class="primary-button h-11 justify-center px-6 max-sm:w-full">Confirmar recepcion</button>
                            </div>
                        </form>
                    </div>
                @endif
            @endforeach
        </section>
        {{-- ===================== HISTORIAL ===================== --}}
        <section data-tab-panel="historial" class="{{ $tabInicial === 'historial' ? '' : 'hidden' }} mt-4">
            <div class="{{ $tarjeta }} p-5">
                <p class="text-base font-semibold text-gray-900 dark:text-white">Historial de entregas</p>
                <p class="text-xs text-slate-500 dark:text-gray-400">Taller reporta &rarr; Bodega recibe. Las cifras de recepcion solo existen cuando Bodega ya recibio la entrega.</p>

                @if ($retornos->isEmpty())
                    <div class="mt-3 rounded-lg border p-4 text-sm text-gray-600 dark:border-gray-800 dark:text-gray-300">
                        El taller aun no ha reportado entregas.
                    </div>
                @else
                    <div class="mt-4 space-y-3">
                        @foreach ($retornos as $retorno)
                            @php
                                $yaRecibido = $retorno->estado !== $estadoRetornoPendiente;
                                $totalRetorno = (int) $retorno->detalles->sum('cantidad_reportada');
                                $totalRetornoRecibido = (int) $retorno->detalles->sum('cantidad_recibida');
                                $totalRetornoDefectuoso = (int) $retorno->detalles->sum('cantidad_defectuosa');
                                $totalRetornoFaltante = (int) $retorno->detalles->sum('cantidad_faltante');
                            @endphp

                            <div class="rounded-lg border {{ $yaRecibido ? 'border-slate-200' : 'border-amber-200 bg-amber-50/30' }} p-4 dark:border-gray-800" id="detalle-historial-{{ $retorno->id }}">
                                <div class="flex items-start justify-between gap-3 max-sm:flex-wrap">
                                    <div>
                                        <div class="flex flex-wrap items-center gap-2.5">
                                            <p class="font-semibold text-gray-900 dark:text-white">{{ $retorno->codigo }}</p>
                                            {!! $estadoOrdenConfeccionRetorno::badgeHtml($retorno->estado) !!}
                                        </div>
                                        <p class="mt-1 text-xs text-slate-500 dark:text-gray-400">Reportada el {{ $retorno->fecha_reporte_taller?->format('Y-m-d H:i') ?: '-' }}</p>
                                        @if ($retorno->observacion_taller)
                                            <p class="mt-1.5 text-sm text-gray-700 dark:text-gray-300">{{ $retorno->observacion_taller }}</p>
                                        @endif
                                    </div>

                                    <dl class="flex flex-wrap gap-2 text-center text-xs">
                                        <div class="min-w-[76px] rounded-md border bg-slate-50 px-2.5 py-1.5 dark:border-gray-800 dark:bg-gray-950"><dt class="font-semibold uppercase text-slate-500">Reportadas</dt><dd class="text-base font-bold text-gray-900 dark:text-white">{{ $totalRetorno }}</dd></div>
                                        @if ($yaRecibido)
                                            <div class="min-w-[76px] rounded-md border border-green-200 bg-green-50 px-2.5 py-1.5 text-green-700"><dt class="font-semibold uppercase">Buenas</dt><dd class="text-base font-bold">{{ $totalRetornoRecibido }}</dd></div>
                                            <div class="min-w-[76px] rounded-md border bg-slate-50 px-2.5 py-1.5 dark:border-gray-800 dark:bg-gray-950"><dt class="font-semibold uppercase text-slate-500">Defectuosas</dt><dd class="text-base font-bold text-gray-900 dark:text-white">{{ $totalRetornoDefectuoso }}</dd></div>
                                            <div class="min-w-[76px] rounded-md border bg-slate-50 px-2.5 py-1.5 dark:border-gray-800 dark:bg-gray-950"><dt class="font-semibold uppercase text-slate-500">Faltantes</dt><dd class="text-base font-bold text-gray-900 dark:text-white">{{ $totalRetornoFaltante }}</dd></div>
                                        @endif
                                    </dl>
                                </div>

                                <div class="mt-3 divide-y rounded-md border text-sm dark:divide-gray-800 dark:border-gray-800">
                                    @foreach ($retorno->detalles as $detalleRetorno)
                                        @php
                                            $varianteHist = $datosVariante($detalleRetorno->product_id);
                                            $tallaHist = $detalleRetorno->talla ?: ($varianteHist['talla'] ?? null) ?: '-';
                                        @endphp

                                        <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 px-3 py-2">
                                            <div class="min-w-0 flex-1">
                                                <p class="truncate font-medium text-gray-800 dark:text-white">{{ $detalleRetorno->producto_nombre ?: $detalleRetorno->producto_sku }}</p>
                                                <p class="flex flex-wrap items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                                                    <span class="font-mono">{{ $detalleRetorno->producto_sku }}</span>
                                                    @include('corteconfeccion::admin.ordenes-confeccion.partials.color-variante', ['color' => $varianteHist['color'] ?? null, 'hex' => $hexDeColor($varianteHist['color'] ?? null)])
                                                    <span>Talla {{ $tallaHist }}</span>
                                                </p>
                                            </div>
                                            <p class="text-gray-700 dark:text-gray-300">
                                                Reportadas: <span class="font-semibold">{{ $detalleRetorno->cantidad_reportada }}</span>
                                                {{-- Reparto confirmado al reportar (P1). Los reportes anteriores no lo registraban y no se reinterpretan. --}}
                                                @if ($detalleRetorno->cantidad_urgente !== null)
                                                    <span class="block text-xs text-gray-500 dark:text-gray-400" data-reparto-reportado>{{ \ByLopez\CorteConfeccion\Services\PrioridadConfeccionService::repartoTexto(['urgente' => (int) $detalleRetorno->cantidad_urgente, 'alta' => (int) $detalleRetorno->cantidad_alta, 'normal' => (int) $detalleRetorno->cantidad_reportada - (int) $detalleRetorno->cantidad_urgente - (int) $detalleRetorno->cantidad_alta]) }}</span>
                                                @else
                                                    <span class="block text-xs text-gray-400">Reparto por prioridad no registrado (reporte anterior)</span>
                                                @endif
                                            </p>
                                            @if ($yaRecibido)
                                                <p class="text-gray-700 dark:text-gray-300">Buenas / aprobadas: {{ (int) $detalleRetorno->cantidad_recibida }}</p>
                                                <p class="text-gray-700 dark:text-gray-300">Defectuosas: {{ (int) $detalleRetorno->cantidad_defectuosa }}</p>
                                                <p class="text-gray-700 dark:text-gray-300">Faltantes: {{ (int) $detalleRetorno->cantidad_faltante }}</p>
                                                @if ($detalleRetorno->observacion_bodega)
                                                    <p class="w-full text-xs text-gray-500 dark:text-gray-400">{{ $detalleRetorno->observacion_bodega }}</p>
                                                @endif
                                            @endif
                                        </div>
                                    @endforeach
                                </div>

                                @if ($yaRecibido)
                                    <div class="mt-3 rounded-md border border-green-100 bg-green-50/50 p-3 text-sm dark:border-gray-800 dark:bg-gray-950">
                                        <p class="font-medium text-gray-800 dark:text-white">Recepcion bodega</p>
                                        <p class="mt-1 text-gray-600 dark:text-gray-300">{{ $retorno->fecha_recepcion_bodega?->format('Y-m-d H:i') ?: '-' }} / {{ $totalRetornoRecibido }} buenas/aprobadas, {{ $totalRetornoDefectuoso }} defectuosas, {{ $totalRetornoFaltante }} faltantes</p>
                                        @if ($retorno->observacion_bodega)
                                            <p class="mt-1.5 text-gray-700 dark:text-gray-300">{{ $retorno->observacion_bodega }}</p>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>

        {{-- ===================== MOVIMIENTOS ===================== --}}
        {{-- Misma visual aprobada de Movimientos de inventario (partial
        compartido + Support\InventarioMovimientoHtml). Solo lectura: el efecto
        real sobre el stock que ya generaba InventarioMovimientoService. --}}
        <section data-tab-panel="movimientos" class="{{ $tabInicial === 'movimientos' ? '' : 'hidden' }} mt-4">
            <div class="{{ $tarjeta }}">
                <div class="flex min-h-14 items-center justify-between gap-4 border-b border-slate-200 px-5 py-4 dark:border-gray-800 max-sm:flex-wrap">
                    <div class="flex items-center gap-2.5">
                        <span class="icon-list text-xl text-blue-600"></span>
                        <div>
                            <p class="text-base font-semibold text-gray-900 dark:text-white">Movimientos de inventario</p>
                            <p class="text-xs text-slate-500 dark:text-gray-400">Entradas de stock generadas por las entregas recibidas de esta orden.</p>
                        </div>
                    </div>

                    @if (bouncer()->hasPermission('corte_confeccion.inventario_movimientos'))
                        {{-- El datagrid admin solo reconoce ?search= como parametro de URL inicial. --}}
                        <a href="{{ route('admin.corte_confeccion.inventario_movimientos.index', ['search' => $orden->codigo]) }}" class="text-sm text-blue-600 hover:underline dark:text-blue-400">Ver todos los movimientos</a>
                    @endif
                </div>

                <div class="px-5 pb-5 pt-4">
                    @if ($filasMovimientos->isEmpty())
                        <div class="rounded-lg border p-4 text-sm text-gray-600 dark:border-gray-800 dark:text-gray-300">
                            Todavia no hay movimientos de inventario para esta orden.
                        </div>
                    @else
                        @include('corteconfeccion::admin.inventario-movimientos.partials.tabla-movimientos', ['filas' => $filasMovimientos])
                    @endif
                </div>
            </div>
        </section>
    </div>

    @push('styles')
        <style>
            .entrega-review-modal { width: min(568px, calc(100% - 32px)); max-height: calc(100dvh - 32px); margin: auto; border: 0; }
            .entrega-review-modal::backdrop { background: rgb(0 0 0 / 50%); }
            .entrega-review-modal[open] { display: flex; flex-direction: column; }
            .entrega-review-modal > :first-child, .entrega-review-modal > :last-child { flex-shrink: 0; }
            .entrega-review-content { overflow-y: auto; }
            body:has(.entrega-review-modal[open]) { overflow: hidden; }
            @media (max-width: 640px) {
                .entrega-review-footer > button { flex: 1; justify-content: center; }
            }
        </style>
    @endpush

    @push('scripts')
        <script>
            /*
             * Delegacion de eventos sobre document (no attachment directo a
             * elementos individuales dentro de un callback de
             * DOMContentLoaded). Se cambio de patron: se detecto que el
             * layout de admin puede terminar de insertar el contenido de
             * esta vista DESPUES de que DOMContentLoaded ya disparo (race
             * real, reproducido con timing preciso), asi que un
             * document.querySelectorAll(...).forEach(el => el.addEventListener(...))
             * corrido en ese momento podia no encontrar nada que enlazar,
             * sin lanzar ningun error - el formulario quedaba con la
             * apariencia correcta pero sin ninguna funcionalidad real. Un
             * listener delegado en document siempre existe desde el primer
             * instante y resuelve el elemento real en el momento del click,
             * no en el momento del arranque del script.
             */
            (function () {
                function cantidadValida(input) {
                    var cantidad = parseInt(input?.value || '0', 10);

                    return Number.isFinite(cantidad) ? cantidad : 0;
                }

                function pendienteFila(input) {
                    var pendiente = parseInt(input?.dataset.max || '0', 10);

                    return Number.isFinite(pendiente) ? pendiente : 0;
                }

                /*
                 * P1: reparto por prioridad de lo que se entrega. Sugerido Urgente -> Alta -> Normal sobre el saldo de
                 * cada nivel; con «Ajustar» el taller lo corrige (debe sumar la cantidad y no superar cada nivel). El
                 * servidor vuelve a validar todo bajo bloqueo.
                 */
                var NIVELES = [['urgente', 'Urgente', 'Urgentes'], ['alta', 'Alta', 'Altas'], ['normal', 'Normal', 'Normales']];

                function repartoTexto(r) {
                    var partes = NIVELES.filter(function (n) { return r[n[0]] > 0; }).map(function (n) {
                        return r[n[0]] + ' ' + (r[n[0]] === 1 ? n[1] : n[2]);
                    });

                    return partes.length ? partes.join(' + ') : '';
                }

                function estadoReparto(card) {
                    var caja = card.querySelector('[data-reparto]');
                    var cantidad = cantidadValida(card.querySelector('[data-report-input]'));

                    if (! caja) {
                        return { valido: true, texto: '', ajustado: false };
                    }

                    var disp = { urgente: +caja.dataset.u || 0, alta: +caja.dataset.a || 0, normal: +caja.dataset.n || 0 };
                    var panel = caja.querySelector('[data-reparto-panel]');
                    var ajustado = !! panel && ! panel.classList.contains('hidden');
                    var r = {};
                    var error = '';

                    if (ajustado) {
                        NIVELES.forEach(function (n) {
                            var v = caja.querySelector('[data-reparto-nivel="' + n[0] + '"]').value.trim();
                            r[n[0]] = v === '' ? 0 : (/^\d+$/.test(v) ? parseInt(v, 10) : NaN);
                        });

                        var suma = r.urgente + r.alta + r.normal;

                        if ([r.urgente, r.alta, r.normal].some(function (v) { return ! Number.isFinite(v); })) {
                            error = 'Usa números enteros (0 o más).';
                        } else if (cantidad > 0 && suma !== cantidad) {
                            error = 'El reparto suma ' + suma + ' y debe sumar ' + cantidad + '.';
                        } else {
                            NIVELES.forEach(function (n) {
                                if (! error && r[n[0]] > disp[n[0]]) { error = 'Solo hay ' + disp[n[0]] + ' en ' + n[1] + '.'; }
                            });
                        }
                    } else {
                        r.urgente = Math.min(cantidad, disp.urgente);
                        r.alta = Math.min(cantidad - r.urgente, disp.alta);
                        r.normal = Math.max(cantidad - r.urgente - r.alta, 0);
                    }

                    return { valido: ! error || cantidad === 0, error: cantidad > 0 ? error : '', texto: cantidad > 0 && ! error ? repartoTexto(r) : '', ajustado: ajustado, reparto: r };
                }

                function pintarReparto(card) {
                    var caja = card.querySelector('[data-reparto]');

                    if (! caja) {
                        return;
                    }

                    var estado = estadoReparto(card);
                    var texto = caja.querySelector('[data-reparto-texto]');
                    var error = caja.querySelector('[data-reparto-error]');

                    texto.textContent = estado.texto ? 'Descuenta: ' + estado.texto + (estado.ajustado ? '' : ' (sugerido)') : '';
                    error.textContent = estado.error || '';
                    error.classList.toggle('hidden', ! estado.error);
                }

                function alternarAjuste(card, abrir) {
                    var caja = card.querySelector('[data-reparto]');
                    var panel = caja && caja.querySelector('[data-reparto-panel]');
                    var boton = caja && caja.querySelector('[data-reparto-ajustar]');

                    if (! panel) {
                        return;
                    }

                    var sugerido = estadoReparto(card).reparto;

                    panel.classList.toggle('hidden', ! abrir);
                    boton.setAttribute('aria-expanded', abrir ? 'true' : 'false');
                    boton.textContent = abrir ? 'Usar sugerido' : 'Ajustar';

                    panel.querySelectorAll('[data-reparto-nivel]').forEach(function (campo) {
                        campo.disabled = ! abrir;
                        // Al abrir se parte del sugerido; al cerrar se vacia (el servidor aplicara el sugerido).
                        campo.value = abrir ? String(sugerido[campo.dataset.repartoNivel] || 0) : '';
                    });

                    pintarReparto(card);

                    if (abrir) {
                        panel.querySelector('[data-reparto-nivel]').focus();
                    }
                }

                function filasConCantidad(form) {
                    var rows = [];
                    var total = 0;
                    var excedePendiente = false;
                    var repartoInvalido = false;

                    form.querySelectorAll('[data-reporte-card]').forEach(function (card) {
                        var input = card.querySelector('[data-report-input]');
                        var cantidad = cantidadValida(input);
                        var pendiente = pendienteFila(input);

                        if (cantidad > pendiente) {
                            excedePendiente = true;
                        }

                        if (cantidad > 0) {
                            var reparto = estadoReparto(card);

                            if (! reparto.valido) {
                                repartoInvalido = true;
                                pintarReparto(card);
                            }

                            total += cantidad;
                            rows.push({
                                card: card,
                                input: input,
                                sku: card.dataset.sku,
                                talla: card.dataset.talla,
                                cantidad: cantidad,
                                reparto: reparto.texto,
                            });
                        }
                    });

                    return { rows: rows, total: total, excedePendiente: excedePendiente, repartoInvalido: repartoInvalido };
                }

                function prepararEnvio(form) {
                    var seleccion = filasConCantidad(form);

                    form.querySelectorAll('[data-reporte-card]').forEach(function (card) {
                        var input = card.querySelector('[data-report-input]');
                        var hiddenId = card.querySelector('[data-report-id]');
                        var enviarFila = cantidadValida(input) > 0;
                        var ajustado = estadoReparto(card).ajustado;

                        if (hiddenId) {
                            hiddenId.disabled = ! enviarFila;
                        }

                        if (input) {
                            input.disabled = ! enviarFila;
                        }

                        card.querySelectorAll('[data-reparto-campo]').forEach(function (campo) {
                            campo.disabled = ! enviarFila || (campo.hasAttribute('data-reparto-nivel') && ! ajustado);
                        });
                    });

                    return seleccion;
                }

                function restaurarCampos(form) {
                    form.querySelectorAll('[data-reporte-card]').forEach(function (card) {
                        var ajustado = estadoReparto(card).ajustado;

                        card.querySelectorAll('[data-report-id], [data-report-input], [data-reparto-campo]').forEach(function (campo) {
                            campo.disabled = campo.hasAttribute('data-reparto-nivel') ? ! ajustado : (campo.matches('[data-report-input]') && pendienteFila(campo) === 0);
                        });
                    });
                }

                // Resumen lateral de recepcion: solo LEE los campos ya calculados
                // (no reimplementa ninguna regla; faltante lo sigue calculando la fila).
                function actualizarResumenRecepcion(form) {
                    var resumen = form.querySelector('[data-recepcion-resumen]');

                    if (! resumen) {
                        return;
                    }

                    var totales = { reportadas: 0, buenas: 0, defectuosas: 0, faltantes: 0 };

                    form.querySelectorAll('[data-recepcion-fila]').forEach(function (fila) {
                        totales.reportadas += parseInt(fila.dataset.reportada || '0', 10) || 0;
                        totales.buenas += parseInt(fila.querySelector('[data-campo-recibida]')?.value || '0', 10) || 0;
                        totales.defectuosas += parseInt(fila.querySelector('[data-campo-defectuosa]')?.value || '0', 10) || 0;
                        totales.faltantes += parseInt(fila.querySelector('[data-campo-faltante]')?.value || '0', 10) || 0;
                    });

                    var porcentaje = totales.reportadas > 0 ? Math.round((totales.buenas / totales.reportadas) * 100) : 0;

                    Object.keys(totales).forEach(function (clave) {
                        var nodo = resumen.querySelector('[data-resumen="' + clave + '"]');

                        if (nodo) { nodo.textContent = totales[clave]; }
                    });

                    resumen.querySelector('[data-resumen="porcentaje"]').textContent = porcentaje + '%';
                    resumen.querySelector('[data-resumen="barra"]').style.width = porcentaje + '%';
                }

                function recalcularFilaRecepcion(fila) {
                    var reportada = parseInt(fila.dataset.reportada || '0', 10);
                    var recibidaInput = fila.querySelector('[data-campo-recibida]');
                    var defectuosaInput = fila.querySelector('[data-campo-defectuosa]');
                    var faltanteInput = fila.querySelector('[data-campo-faltante]');
                    var errorMsg = fila.querySelector('[data-recepcion-error]');

                    var recibida = parseInt(recibidaInput?.value || '0', 10);
                    var defectuosa = parseInt(defectuosaInput?.value || '0', 10);

                    if (! Number.isFinite(recibida) || recibida < 0) {
                        recibida = 0;
                    }

                    if (! Number.isFinite(defectuosa) || defectuosa < 0) {
                        defectuosa = 0;
                    }

                    var excedeReportada = recibida + defectuosa > reportada;

                    if (excedeReportada) {
                        if (document.activeElement === defectuosaInput) {
                            defectuosa = Math.max(0, reportada - recibida);
                            defectuosaInput.value = defectuosa;
                        } else {
                            recibida = Math.max(0, reportada - defectuosa);
                            recibidaInput.value = recibida;
                        }
                    }

                    if (errorMsg) {
                        errorMsg.classList.toggle('hidden', ! excedeReportada);
                    }

                    [recibidaInput, defectuosaInput].forEach(function (input) {
                        input?.classList.toggle('border-red-600', excedeReportada);
                    });

                    if (faltanteInput) {
                        faltanteInput.value = Math.max(0, reportada - recibida - defectuosa);
                    }

                    var formRecepcion = fila.closest('[data-recepcion-bodega]');

                    if (formRecepcion) {
                        actualizarResumenRecepcion(formRecepcion);
                    }
                }

                function filaValidaRecepcion(fila) {
                    var reportada = parseInt(fila.dataset.reportada || '0', 10);
                    var recibida = parseInt(fila.querySelector('[data-campo-recibida]')?.value || '0', 10);
                    var defectuosa = parseInt(fila.querySelector('[data-campo-defectuosa]')?.value || '0', 10);

                    return recibida >= 0 && defectuosa >= 0 && (recibida + defectuosa) <= reportada;
                }

                document.addEventListener('input', function (event) {
                    var reportInput = event.target.closest('[data-report-input]');

                    if (reportInput) {
                        var card = reportInput.closest('[data-reporte-card]');
                        var checkbox = card?.querySelector('[data-select-row]');

                        if (checkbox && ! checkbox.disabled) {
                            checkbox.checked = cantidadValida(reportInput) > 0;
                        }

                        pintarReparto(card);

                        return;
                    }

                    var campoReparto = event.target.closest('[data-reparto-nivel]');

                    if (campoReparto) {
                        pintarReparto(campoReparto.closest('[data-reporte-card]'));

                        return;
                    }

                    var campoRecepcion = event.target.closest('[data-campo-recibida], [data-campo-defectuosa]');

                    if (campoRecepcion) {
                        var fila = campoRecepcion.closest('[data-recepcion-fila]');

                        if (fila) {
                            // Al escribir defectuosas, se descuentan de las buenas/aprobadas
                            // (los faltantes ya digitados no cambian): buenas = reportadas - faltantes - defectuosas.
                            if (campoRecepcion.matches('[data-campo-defectuosa]')) {
                                var reportadaFila = parseInt(fila.dataset.reportada || '0', 10) || 0;
                                var faltanteFila = parseInt(fila.querySelector('[data-campo-faltante]')?.value || '0', 10) || 0;
                                var defectuosaFila = Math.max(0, parseInt(campoRecepcion.value || '0', 10) || 0);
                                var recibidaFila = fila.querySelector('[data-campo-recibida]');

                                if (recibidaFila) {
                                    recibidaFila.value = Math.max(0, reportadaFila - faltanteFila - defectuosaFila);
                                }
                            }

                            recalcularFilaRecepcion(fila);
                        }
                    }
                });

                document.addEventListener('click', function (event) {
                    var ajustarButton = event.target.closest('[data-reparto-ajustar]');

                    if (ajustarButton) {
                        alternarAjuste(ajustarButton.closest('[data-reporte-card]'), ajustarButton.getAttribute('aria-expanded') !== 'true');

                        return;
                    }

                    var fillRowButton = event.target.closest('[data-fill-row]');

                    if (fillRowButton) {
                        var card = fillRowButton.closest('[data-reporte-card]');
                        var input = card.querySelector('[data-report-input]');

                        input.value = input.dataset.max || 0;
                        card.querySelector('[data-select-row]').checked = true;
                        pintarReparto(card);

                        return;
                    }

                    var selectPendingButton = event.target.closest('[data-select-pending]');

                    if (selectPendingButton) {
                        var form = selectPendingButton.closest('[data-reporte-taller]');

                        form.querySelectorAll('[data-reporte-card]').forEach(function (card) {
                            var checkbox = card.querySelector('[data-select-row]');
                            var input = card.querySelector('[data-report-input]');

                            if (checkbox && input && ! input.disabled) {
                                checkbox.checked = true;
                                input.value = input.dataset.max || '';
                                pintarReparto(card);
                            }
                        });

                        return;
                    }

                    var fillSelectedButton = event.target.closest('[data-fill-selected]');

                    if (fillSelectedButton) {
                        var form = fillSelectedButton.closest('[data-reporte-taller]');

                        form.querySelectorAll('[data-reporte-card]').forEach(function (card) {
                            var checkbox = card.querySelector('[data-select-row]');
                            var input = card.querySelector('[data-report-input]');

                            if (checkbox && checkbox.checked && input && ! input.disabled) {
                                input.value = input.dataset.max || 0;
                                pintarReparto(card);
                            }
                        });

                        return;
                    }

                    var clearButton = event.target.closest('[data-clear-report]');

                    if (clearButton) {
                        var form = clearButton.closest('[data-reporte-taller]');

                        form.querySelectorAll('[data-reporte-card]').forEach(function (card) {
                            var checkbox = card.querySelector('[data-select-row]');
                            var input = card.querySelector('[data-report-input]');
                            var hiddenId = card.querySelector('[data-report-id]');

                            if (checkbox && ! checkbox.disabled) {
                                checkbox.checked = false;
                            }

                            if (input && pendienteFila(input) > 0) {
                                input.disabled = false;
                                input.value = '';
                            }

                            if (hiddenId) {
                                hiddenId.disabled = false;
                            }

                            if (card.querySelector('[data-reparto-panel]:not(.hidden)')) {
                                alternarAjuste(card, false);
                            }

                            pintarReparto(card);
                        });

                        return;
                    }

                    var reviewButton = event.target.closest('[data-review-report]');

                    if (reviewButton) {
                        var form = reviewButton.closest('[data-reporte-taller]');
                        var reviewPanel = form.querySelector('[data-reporte-review]');
                        var reviewList = form.querySelector('[data-review-list]');
                        var reviewTotal = form.querySelector('[data-review-total]');
                        var seleccion = filasConCantidad(form);

                        if (seleccion.excedePendiente) {
                            window.alert('La cantidad no puede superar el pendiente de la variante.');

                            return;
                        }

                        if (! seleccion.rows.length) {
                            window.alert('Ingresa al menos una cantidad para reportar.');

                            return;
                        }

                        if (seleccion.repartoInvalido) {
                            var primerError = form.querySelector('[data-reparto-error]:not(.hidden)');

                            window.alert('Revisa el reparto por prioridad marcado en rojo: debe sumar la cantidad entregada.');
                            primerError?.closest('[data-reparto]')?.querySelector('[data-reparto-nivel]')?.focus();

                            return;
                        }

                        reviewList.innerHTML = '';

                        seleccion.rows.forEach(function (row) {
                            var item = document.createElement('div');
                            var label = document.createElement('span');
                            var quantity = document.createElement('span');
                            var sku = document.createElement('p');
                            var variant = document.createElement('div');

                            item.className = 'flex items-center justify-between gap-3 rounded-lg border px-3 py-3 dark:border-gray-800';
                            variant.className = 'min-w-0';
                            sku.className = 'mt-1 break-all font-mono text-xs text-gray-500 dark:text-gray-400';
                            label.className = 'text-base font-semibold text-gray-800 dark:text-white';
                            quantity.className = 'shrink-0 text-lg font-bold text-gray-800 dark:text-white';

                            label.textContent = 'Talla ' + row.talla;
                            sku.textContent = row.sku;
                            quantity.textContent = row.cantidad + ' unidades';

                            variant.appendChild(label);
                            variant.appendChild(sku);

                            if (row.reparto) {
                                var reparto = document.createElement('p');

                                reparto.className = 'mt-1 text-xs text-gray-600 dark:text-gray-300';
                                reparto.dataset.reviewReparto = '';
                                reparto.textContent = 'Descuenta: ' + row.reparto;
                                variant.appendChild(reparto);
                            }
                            item.appendChild(variant);
                            item.appendChild(quantity);
                            reviewList.appendChild(item);
                        });

                        reviewTotal.textContent = seleccion.total;
                        reviewPanel.showModal();

                        return;
                    }

                    var backButton = event.target.closest('[data-back-report]');

                    if (backButton) {
                        var form = backButton.closest('[data-reporte-taller]');

                        form.querySelector('[data-reporte-review]').close();
                    }
                });

                document.addEventListener('submit', function (event) {
                    var confirmForm = event.target.closest('form[data-confirm]');

                    if (confirmForm) {
                        event.preventDefault();

                        window.app.config.globalProperties.$emitter.emit('open-confirm-modal', {
                            title: confirmForm.dataset.confirmTitle || 'Confirmar accion',
                            message: confirmForm.dataset.confirm,
                            agree: function () {
                                confirmForm.submit();
                            },
                        });

                        return;
                    }

                    var reporteForm = event.target.closest('[data-reporte-taller]');

                    if (reporteForm) {
                        if (reporteForm.dataset.enviando) {
                            // Doble clic en «Confirmar entrega»: el primer envio ya va en camino.
                            event.preventDefault();

                            return;
                        }

                        var seleccion = prepararEnvio(reporteForm);

                        if (seleccion.excedePendiente || ! seleccion.rows.length || seleccion.repartoInvalido) {
                            event.preventDefault();
                            restaurarCampos(reporteForm);

                            window.alert(seleccion.excedePendiente
                                ? 'La cantidad no puede superar el pendiente de la variante.'
                                : (seleccion.repartoInvalido ? 'Revisa el reparto por prioridad marcado en rojo.' : 'Ingresa al menos una cantidad para reportar.')
                            );

                            return;
                        }

                        reporteForm.dataset.enviando = '1';
                        reporteForm.querySelectorAll('button[type="submit"]').forEach(function (b) { b.disabled = true; });

                        return;
                    }

                    // Defensa adicional en el submit de recepcion: la UI ya
                    // evita que buenas + defectuosas supere lo reportado
                    // mientras se escribe, pero el backend sigue siendo la
                    // autoridad final de esta validacion.
                    var recepcionForm = event.target.closest('[data-recepcion-bodega]');

                    if (recepcionForm) {
                        var filas = Array.from(recepcionForm.querySelectorAll('[data-recepcion-fila]'));
                        var todasValidas = filas.every(filaValidaRecepcion);

                        if (! todasValidas) {
                            event.preventDefault();
                            window.alert('Revisa las cantidades: buenas + defectuosas no puede superar lo reportado en ninguna fila.');
                        }
                    }
                });

                // Unico paso que si necesita el DOM ya insertado: fijar el
                // valor inicial de "Faltantes" en cada fila de recepcion (por
                // ejemplo, tras un redirect por validacion fallida con
                // valores old() distintos al default). Se reintenta tanto en
                // DOMContentLoaded como en window.load para cubrir el mismo
                // retraso de insercion que motivo este cambio.
                function inicializarFilasRecepcion() {
                    // Reparto por prioridad: texto inicial (incluido lo que volvio tras un error de validacion).
                    document.querySelectorAll('[data-reporte-card]').forEach(pintarReparto);

                    document.querySelectorAll('[data-recepcion-fila]').forEach(function (fila) {
                        if (fila.dataset.recepcionInicializada) {
                            return;
                        }

                        fila.dataset.recepcionInicializada = '1';
                        recalcularFilaRecepcion(fila);

                        // Movil: solo la primera variante de cada recepcion queda abierta.
                        if (window.innerWidth < 768 && fila.tagName === 'DETAILS' && fila.previousElementSibling) {
                            fila.removeAttribute('open');
                        }
                    });
                }

                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', inicializarFilasRecepcion);
                } else {
                    inicializarFilasRecepcion();
                }

                window.addEventListener('load', inicializarFilasRecepcion);

                /*
                 * Pestanas (Produccion | Recepciones | Historial | Movimientos).
                 * Todos los paneles existen en el DOM (solo se ocultan) para no
                 * perder formularios ni validaciones. Delegado en document, por
                 * la misma razon que el resto de este script.
                 */
                var CLASES_TAB_ACTIVA = ['border-blue-600', 'text-blue-600', 'dark:text-blue-400'];
                var CLASES_TAB_INACTIVA = ['border-transparent', 'text-slate-500', 'hover:text-gray-800', 'dark:text-gray-400', 'dark:hover:text-white'];

                function activarTab(nombre, actualizarUrl) {
                    var panel = document.querySelector('[data-tab-panel="' + nombre + '"]');

                    if (! panel) {
                        return false;
                    }

                    document.querySelectorAll('[data-tab-panel]').forEach(function (otro) {
                        otro.classList.toggle('hidden', otro !== panel);
                    });

                    document.querySelectorAll('[data-tab]').forEach(function (boton) {
                        var activo = boton.dataset.tab === nombre;

                        boton.setAttribute('aria-selected', activo ? 'true' : 'false');
                        CLASES_TAB_ACTIVA.forEach(function (clase) { boton.classList.toggle(clase, activo); });
                        CLASES_TAB_INACTIVA.forEach(function (clase) { boton.classList.toggle(clase, ! activo); });
                    });

                    if (actualizarUrl && window.history && window.history.replaceState) {
                        var url = new URL(window.location.href);

                        url.searchParams.set('tab', nombre);
                        url.hash = '';
                        window.history.replaceState(null, '', url.toString());
                    }

                    return true;
                }

                document.addEventListener('click', function (event) {
                    var boton = event.target.closest('[data-tab]');

                    if (boton) {
                        activarTab(boton.dataset.tab, true);

                        return;
                    }

                    // Enlaces internos (#retorno-N, #detalle-historial-N): abrir
                    // la pestana que contiene el destino antes de desplazarse.
                    var enlace = event.target.closest('a[href^="#"]');

                    if (! enlace || enlace.getAttribute('href').length < 2) {
                        return;
                    }

                    var destino = document.getElementById(enlace.getAttribute('href').slice(1));
                    var panel = destino ? destino.closest('[data-tab-panel]') : null;

                    if (panel) {
                        event.preventDefault();
                        activarTab(panel.dataset.tabPanel, true);

                        // "Revisar entrega": mostrar solo la recepcion elegida y marcar su fila.
                        if (destino.hasAttribute('data-recepcion-card')) {
                            document.querySelectorAll('[data-recepcion-card]').forEach(function (card) {
                                card.classList.toggle('hidden', card !== destino);
                            });

                            document.querySelectorAll('[data-retorno-fila]').forEach(function (fila) {
                                var activa = fila.dataset.retornoFila === destino.dataset.recepcionCard;

                                ['bg-blue-50/60', 'dark:bg-blue-950/30'].forEach(function (clase) { fila.classList.toggle(clase, activa); });
                            });
                        }
                        destino.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                });
            })();
        </script>
    @endpush
</x-admin::layouts>
