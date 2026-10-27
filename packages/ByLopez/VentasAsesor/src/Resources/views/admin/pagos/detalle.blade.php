@php
    use ByLopez\VentasAsesor\Support\Pedidos\EstadoPagoPedido;

    $dinero = fn (float|int|null $valor) => \ByLopez\Logistica\Support\Moneda::formatear((float) $valor);

    $iconos = [
        'check' => '<polyline points="20 6 9 17 4 12"/>',
        'alerta' => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
        'x' => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        'menos' => '<line x1="6" y1="12" x2="18" y2="12"/>',
        'reloj' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'moneda' => '<line x1="12" y1="2" x2="12" y2="22"/><path d="M17 6H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
        'punto' => '<circle cx="12" cy="12" r="5" fill="currentColor"/>',
    ];

    $pill = fn (string $tono, string $texto, string $icono) => '<span class="va-pill va-pill--'.$tono.'"><svg viewBox="0 0 24 24" aria-hidden="true">'.($iconos[$icono] ?? '').'</svg><span>'.e($texto).'</span></span>';

    $tonoEstado = ['sin_revisar' => 'amarillo', 'confirmado' => 'verde', 'rechazado' => 'rojo', 'reversado' => 'gris'];
    $iconoEstado = ['sin_revisar' => 'alerta', 'confirmado' => 'check', 'rechazado' => 'x', 'reversado' => 'menos'];

    $pillEstadoPago = fn (string $estado) => $pill($tonoEstado[$estado] ?? 'gris', trans('ventasasesor::app.cartera.pagos.estado-'.$estado), $iconoEstado[$estado] ?? 'menos');

    // Estado del PEDIDO (dominio operativo) y estado de pago del pedido (derivado de Cartera): dominios distintos.
    $tonoPedido = ['pending' => 'amarillo', 'pending_payment' => 'amarillo', 'processing' => 'cian', 'completed' => 'verde', 'closed' => 'indigo', 'canceled' => 'rojo', 'fraud' => 'rojo'];
    $pillPedido = fn (string $estado) => $pill($tonoPedido[$estado] ?? 'gris', trans('admin::app.sales.orders.index.datagrid.'.str_replace('_', '-', $estado)), 'punto');

    $tonoPagoPedido = ['pagado' => 'verde', 'parcial' => 'cian', 'pendiente' => 'amarillo', 'revisar' => 'indigo', 'no_aplica' => 'gris', 'devuelto' => 'gris'];
    $iconoPagoPedido = ['pagado' => 'check', 'parcial' => 'moneda', 'pendiente' => 'reloj', 'revisar' => 'alerta', 'no_aplica' => 'menos', 'devuelto' => 'menos'];

    $pillPagoPedido = function ($cartera) use ($pill, $tonoPagoPedido, $iconoPagoPedido): string {
        if ($cartera === null || ! $cartera->enCartera) {
            return '';
        }

        [$estado] = EstadoPagoPedido::desdeCartera($cartera);

        return $pill($tonoPagoPedido[$estado] ?? 'gris', trans('ventasasesor::app.pedidos.pago.'.$estado), $iconoPagoPedido[$estado] ?? 'menos');
    };

    $comprobantes = $eventos->where('tipo', 'evidencia_agregada')->filter(fn ($e) => isset($e->datos['ruta']));
    $cliente = trim($pago->first_name.' '.$pago->last_name);
    $textoPago = trans('ventasasesor::app.cartera.pagos.pago').' #'.$pago->id.' · '.$dinero($pago->monto).' · '.$cliente;
    $detallePago = [
        'pago' => '#'.$pago->id,
        'cliente' => $cliente,
        'monto' => $dinero($pago->monto),
        'medio' => $medio_etiqueta,
        'referencia' => $pago->referencia ?: null,
        'pedidos' => collect($aplicaciones)->whereNull('anulada_en')->map(fn ($a) => '#'.$a->increment_id.' ('.$dinero($a->monto).')')->values()->all(),
    ];
    $okPagoId = (int) request('pago_ok');
    $ok = request('ok');
    $dato = fn ($valor) => $valor === null || $valor === '' ? trans('ventasasesor::app.cartera.detalle.sin-datos') : $valor;
@endphp

@once
    @push('styles')
        @include('ventasasesor::admin.cartera.estilos')
    @endpush
@endonce

<x-admin::layouts>
    <x-slot:title>
        @lang('ventasasesor::app.cartera.detalle.titulo', ['id' => $pago->id])
    </x-slot>

    <div class="va-orders-page bylopez-scope">
        <a href="{{ route('admin.ventas_asesor.cartera.index') }}" class="text-sm text-blue-600 hover:underline">← @lang('ventasasesor::app.cartera.detalle.volver')</a>

        <div class="mt-2 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-2xl font-bold leading-8 text-gray-900 dark:text-white">@lang('ventasasesor::app.cartera.detalle.titulo', ['id' => $pago->id])</h1>
                {!! $pillEstadoPago($pago->estado) !!}
            </div>

            @if ($puedeRevisar && $pago->estado === 'sin_revisar')
                <div class="va-acciones">
                    <button type="button" class="va-btn va-btn--ok" data-revisar="confirmar" data-pago="{{ $pago->id }}" data-texto="{{ $textoPago }}" data-detalle="{{ json_encode($detallePago, JSON_UNESCAPED_UNICODE) }}">@lang('ventasasesor::app.cartera.pagos.confirmar')</button>
                    <button type="button" class="va-btn va-btn--no" data-revisar="rechazar" data-pago="{{ $pago->id }}" data-texto="{{ $textoPago }}" data-detalle="{{ json_encode($detallePago, JSON_UNESCAPED_UNICODE) }}">@lang('ventasasesor::app.cartera.pagos.rechazar')</button>
                </div>
            @endif
        </div>

        @if (in_array($ok, ['confirmado', 'rechazado'], true) && $okPagoId === (int) $pago->id)
            <div class="va-panel mt-4 p-4" style="border-color:{{ $ok === 'confirmado' ? '#86efac' : '#fda4af' }};background:{{ $ok === 'confirmado' ? '#f0fdf4' : '#fff1f2' }}" data-revision-ok="{{ $ok }}">
                <p class="text-sm font-semibold" style="color:{{ $ok === 'confirmado' ? '#166534' : '#9f1239' }}">@lang('ventasasesor::app.cartera.pagos.ok-'.$ok, ['id' => $pago->id])</p>
            </div>
        @endif

        <div class="va-panel mt-4 p-4">
            <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm md:grid-cols-4">
                <div>
                    <dt class="va-caption">@lang('ventasasesor::app.cartera.detalle.cliente')</dt>
                    <dd class="font-semibold text-gray-900 dark:text-white">
                        <a href="{{ route('admin.ventas_asesor.cartera.index', ['cliente' => $pago->customer_id]) }}" class="hover:underline">{{ $cliente ?: '#'.$pago->customer_id }}</a>
                        <span class="block text-xs font-normal text-slate-500 dark:text-gray-400">{{ $pago->phone }}</span>
                    </dd>
                </div>
                <div>
                    <dt class="va-caption">@lang('ventasasesor::app.cartera.detalle.monto')</dt>
                    <dd class="va-total text-gray-900 dark:text-white" style="font-size:20px;font-weight:800" data-pago-monto>{{ $dinero($pago->monto) }}</dd>
                </div>
                <div>
                    <dt class="va-caption">@lang('ventasasesor::app.cartera.detalle.medio')</dt>
                    <dd class="text-gray-800 dark:text-gray-200">{{ $medio_etiqueta }}</dd>
                </div>
                <div>
                    <dt class="va-caption">@lang('ventasasesor::app.cartera.detalle.fecha-declarada')</dt>
                    <dd class="text-gray-800 dark:text-gray-200">{{ core()->formatDate($pago->fecha_pago, 'd/m/Y') }}</dd>
                </div>
                <div>
                    <dt class="va-caption">@lang('ventasasesor::app.cartera.detalle.referencia')</dt>
                    <dd class="text-gray-800 dark:text-gray-200">{{ $dato($pago->referencia) }}</dd>
                    @foreach ($comprobantes as $comprobante)
                        <dd><a class="text-blue-600 hover:underline" href="{{ route('admin.ventas_asesor.cartera.index', ['pago' => $pago->id, 'comprobante' => $comprobante->id]) }}">{{ $comprobante->datos['nombre'] }}</a></dd>
                    @endforeach
                </div>
                <div class="md:col-span-3">
                    <dt class="va-caption">@lang('ventasasesor::app.cartera.detalle.observacion')</dt>
                    <dd class="text-gray-800 dark:text-gray-200">{{ $dato($pago->observacion) }}</dd>
                </div>
                <div>
                    <dt class="va-caption">@lang('ventasasesor::app.cartera.detalle.registrado-por')</dt>
                    <dd class="text-gray-800 dark:text-gray-200">{{ $dato($pago->registrado_por) }}</dd>
                </div>
                <div>
                    <dt class="va-caption">@lang('ventasasesor::app.cartera.detalle.registrado-en')</dt>
                    <dd class="text-gray-800 dark:text-gray-200">{{ core()->formatDate($pago->created_at, 'd/m/Y h:i A') }}</dd>
                </div>

                @if ($pago->revisado_en)
                    <div>
                        <dt class="va-caption">@lang('ventasasesor::app.cartera.detalle.revisado-por')</dt>
                        <dd class="text-gray-800 dark:text-gray-200">{{ $dato($pago->revisado_por) }} · {{ core()->formatDate($pago->revisado_en, 'd/m/Y h:i A') }}</dd>
                    </div>
                @endif

                @if ($pago->estado === 'rechazado' && $pago->motivo_revision)
                    <div class="md:col-span-2">
                        <dt class="va-caption">@lang('ventasasesor::app.cartera.detalle.motivo')</dt>
                        <dd class="font-medium text-rose-700 dark:text-rose-300" data-pago-motivo>{{ $pago->motivo_revision }}</dd>
                    </div>
                @endif
            </dl>
        </div>

        <p class="mt-5 text-base font-semibold text-gray-900 dark:text-white">@lang('ventasasesor::app.cartera.detalle.aplicaciones')</p>

        <div class="va-panel mt-2 overflow-x-auto">
            <table class="va-orders-table text-left text-sm" style="table-layout:auto;min-width:720px">
                <thead class="bg-slate-50 dark:bg-gray-950">
                    <tr>
                        <th>@lang('ventasasesor::app.cartera.detalle.pedido')</th>
                        <th class="text-right">@lang('ventasasesor::app.cartera.detalle.total')</th>
                        <th class="text-right">@lang('ventasasesor::app.cartera.detalle.aplicado')</th>
                        <th class="text-right">@lang('ventasasesor::app.cartera.detalle.saldo-antes')</th>
                        <th class="text-right">@lang('ventasasesor::app.cartera.detalle.saldo-despues')</th>
                        <th>@lang('ventasasesor::app.cartera.detalle.estado-pedido')</th>
                        <th>@lang('ventasasesor::app.cartera.detalle.transaccion')</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($aplicaciones as $aplicacion)
                        <tr data-aplicacion="{{ $aplicacion->id }}">
                            <td>
                                <a href="{{ route('admin.ventas_asesor.pedidos.index', ['pedido' => $aplicacion->order_id]) }}" class="va-order-id text-blue-600 hover:underline">#{{ $aplicacion->increment_id }}</a>
                                @if ($aplicacion->anulada_en)
                                    <span class="va-pill va-pill--gris va-pill--sm">@lang('ventasasesor::app.cartera.pagos.anulada')</span>
                                @endif
                            </td>
                            <td class="va-money text-right text-gray-800 dark:text-gray-200">{{ $dinero($aplicacion->base_grand_total) }}</td>
                            <td class="va-money--strong text-right text-gray-900 dark:text-white">{{ $dinero($aplicacion->monto) }}</td>
                            <td class="va-money text-right text-gray-800 dark:text-gray-200">{{ $aplicacion->saldo_antes === null ? '—' : $dinero($aplicacion->saldo_antes) }}</td>
                            <td class="va-money text-right text-gray-800 dark:text-gray-200">{{ $aplicacion->saldo_despues === null ? '—' : $dinero($aplicacion->saldo_despues) }}</td>
                            <td>
                                <div class="va-stack">
                                    {!! $pillPedido($aplicacion->status) !!}
                                    {!! $pillPagoPedido($aplicacion->cartera) !!}
                                    @if ($aplicacion->cartera?->enCartera)
                                        <span class="va-sub">@lang('ventasasesor::app.cartera.cliente.saldo') {{ $dinero($aplicacion->cartera->saldo) }}</span>
                                    @endif
                                </div>
                            </td>
                            <td class="text-sm text-gray-700 dark:text-gray-300">
                                @if ($aplicacion->order_transaction_id)
                                    <span class="font-medium">#{{ $aplicacion->order_transaction_id }}</span>
                                    <span class="va-sub">{{ $aplicacion->transaction_id }}</span>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="mt-5 text-base font-semibold text-gray-900 dark:text-white">@lang('ventasasesor::app.cartera.detalle.historial')</p>

        <div class="va-panel mt-2 p-4">
            <ol class="grid gap-3">
                @foreach ($eventos as $evento)
                    <li class="flex items-start gap-3" data-evento="{{ $evento->tipo }}">
                        <span class="va-pill va-pill--{{ ['registrado' => 'amarillo', 'confirmado' => 'verde', 'rechazado' => 'rojo'][$evento->tipo] ?? 'gris' }} va-pill--sm"><span>@lang('ventasasesor::app.cartera.detalle.evento-'.$evento->tipo)</span></span>

                        <div class="min-w-0 text-sm">
                            <p class="text-gray-800 dark:text-gray-200">
                                {{ $dato($evento->actor) }}
                                <span class="va-sub">· {{ core()->formatDate($evento->created_at, 'd/m/Y h:i A') }}</span>
                            </p>

                            @if ($evento->motivo)
                                <p class="text-gray-600 dark:text-gray-300">
                                    @if (! empty($evento->datos['categoria']))
                                        <span class="font-semibold">{{ trans((string) config('ventasasesor.pagos.motivos_rechazo.'.$evento->datos['categoria'], $evento->datos['categoria'])) }}:</span>
                                    @endif
                                    {{ $evento->motivo }}
                                </p>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>
        </div>

        @if ($puedeRevisar)
            @include('ventasasesor::admin.pagos.modales')
        @endif
    </div>
</x-admin::layouts>
