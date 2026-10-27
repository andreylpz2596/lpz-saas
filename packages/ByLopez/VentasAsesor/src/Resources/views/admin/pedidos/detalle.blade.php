@php
    $moneda = $pedido->order_currency_code;
    $etiquetaEstado = trans('admin::app.sales.orders.index.datagrid.'.str_replace('_', '-', $pedido->status));
    $clasePago = ['devuelto' => 'label-closed', 'pagado' => 'label-completed', 'parcial' => 'label-processing', 'pendiente' => 'label-pending', 'revisar' => 'label-closed'][$estado_pago] ?? 'label-info';
    $claseDespacho = ['despachado' => 'label-completed', 'parcial' => 'label-processing', 'pendiente' => 'label-pending'][$estado_despacho] ?? 'label-info';
    $titulo = trans('ventasasesor::app.pedidos.detalle.title', ['numero' => $pedido->increment_id]);
@endphp

<x-admin::layouts>
    <x-slot:title>
        {{ $titulo }}
    </x-slot>

    <!-- Cabecera -->
    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <div class="flex flex-wrap items-center gap-2.5" data-refrescable="cabecera">
            <p class="text-xl font-bold text-gray-800 dark:text-white">{{ $titulo }}</p>

            <span class="label-{{ $pedido->status }}">{{ $etiquetaEstado }}</span>
            <span class="{{ $clasePago }}">@lang('ventasasesor::app.pedidos.columnas.pago'): @lang('ventasasesor::app.pedidos.pago.'.$estado_pago)</span>
            <span class="{{ $claseDespacho }}">@lang('ventasasesor::app.pedidos.despacho.'.$estado_despacho)</span>
        </div>

        <a href="{{ $volver }}" class="transparent-button border-2 border-gray-200 dark:border-gray-800">
            ← @lang('ventasasesor::app.pedidos.detalle.volver')
        </a>
    </div>

    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ core()->formatDate($pedido->created_at, 'd/m/Y H:i') }}</p>

    <div class="mt-3.5 flex gap-2.5 max-xl:flex-wrap">
        <!-- Productos y totales -->
        <div class="flex flex-1 flex-col gap-2 max-xl:flex-auto">
            <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900" data-refrescable="productos">
                <p class="mb-3 text-base font-semibold text-gray-800 dark:text-white">@lang('ventasasesor::app.pedidos.detalle.productos')</p>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b text-xs uppercase text-gray-600 dark:border-gray-800 dark:text-gray-300">
                            <tr>
                                <th class="py-2 pr-2">@lang('ventasasesor::app.pedidos.detalle.producto') / @lang('ventasasesor::app.pedidos.detalle.talla')</th>
                                <th class="px-2 py-2 text-right">@lang('ventasasesor::app.pedidos.detalle.cantidad')</th>
                                <th class="px-2 py-2 text-right">@lang('ventasasesor::app.pedidos.detalle.enviadas')</th>
                                <th class="px-2 py-2 text-right">@lang('ventasasesor::app.pedidos.detalle.precio')</th>
                                <th class="py-2 pl-2 text-right">@lang('ventasasesor::app.pedidos.detalle.total')</th>
                            </tr>
                        </thead>

                        <tbody>
                            {{-- Un bloque por producto (linea x color) con una fila por talla. --}}
                            @foreach ($productos as $producto)
                                <tr class="bg-gray-50 dark:bg-gray-950" data-pedido-producto="{{ $producto['producto_id'] }}">
                                    <td colspan="5" class="px-2 py-2">
                                        <p class="font-semibold text-gray-800 dark:text-white">{{ $producto['nombre'] }}</p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">@lang('ventasasesor::app.pedidos.detalle.sku'): {{ $producto['sku'] }}</p>
                                    </td>
                                </tr>

                                @foreach ($producto['filas'] as $fila)
                                    <tr class="border-b dark:border-gray-800">
                                        <td class="py-2 pl-4 pr-2 font-medium text-gray-800 dark:text-white">{{ $fila['talla'] }}</td>
                                        <td class="px-2 py-2 text-right text-gray-700 dark:text-gray-300">{{ $fila['qty_ordered'] }}</td>
                                        <td class="px-2 py-2 text-right text-gray-700 dark:text-gray-300">{{ $fila['qty_shipped'] }}</td>
                                        <td class="px-2 py-2 text-right text-gray-700 dark:text-gray-300">{{ \ByLopez\Logistica\Support\Moneda::formatear($fila['price']) }}</td>
                                        <td class="py-2 pl-2 text-right font-semibold text-gray-800 dark:text-white">{{ \ByLopez\Logistica\Support\Moneda::formatear($fila['total']) }}</td>
                                    </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <dl class="ml-auto mt-3 grid max-w-xs grid-cols-2 gap-x-4 gap-y-1 text-sm">
                    <dt class="text-gray-600 dark:text-gray-300">@lang('ventasasesor::app.pedidos.detalle.subtotal')</dt>
                    <dd class="text-right text-gray-800 dark:text-white">{{ \ByLopez\Logistica\Support\Moneda::formatear($pedido->sub_total) }}</dd>

                    <dt class="text-gray-600 dark:text-gray-300">@lang('ventasasesor::app.pedidos.detalle.envio')</dt>
                    <dd class="text-right text-gray-800 dark:text-white">{{ \ByLopez\Logistica\Support\Moneda::formatear($pedido->shipping_amount) }}</dd>

                    @if ((float) $pedido->discount_amount > 0)
                        <dt class="text-gray-600 dark:text-gray-300">@lang('ventasasesor::app.pedidos.detalle.descuento')</dt>
                        <dd class="text-right text-gray-800 dark:text-white">-{{ \ByLopez\Logistica\Support\Moneda::formatear($pedido->discount_amount) }}</dd>
                    @endif

                    <dt class="font-bold text-gray-800 dark:text-white">@lang('ventasasesor::app.pedidos.detalle.total')</dt>
                    <dd class="text-right font-bold text-gray-800 dark:text-white">{{ \ByLopez\Logistica\Support\Moneda::formatear($pedido->grand_total) }}</dd>

                    @if ($saldo > 0 && $estado_pago !== 'no_aplica')
                        <dt class="text-gray-600 dark:text-gray-300">@lang('ventasasesor::app.pedidos.detalle.saldo')</dt>
                        <dd class="text-right font-semibold text-yellow-700 dark:text-yellow-300">{{ \ByLopez\Logistica\Support\Moneda::formatear($saldo) }}</dd>
                    @endif
                </dl>
            </div>

            {{-- Despacho V1: envio y guia. El numero/tracking es el DATO del envio; el PDF es el documento (aparte). --}}
            @php
                $envioGuia = (object) [
                    'id' => $pedido->id, 'increment_id' => $pedido->increment_id, 'estado_despacho' => $estado_despacho,
                    'shipping_method' => $pedido->shipping_method, 'guias' => $guias,
                ];
            @endphp

            <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900" data-bloque-envio data-refrescable="envio">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <p class="text-base font-semibold text-gray-800 dark:text-white">@lang('logistica::app.despachos.modal.guias')</p>

                    @include('ventasasesor::admin.pedidos.acciones-envio', ['pedido' => $envioGuia])
                </div>

                @forelse ($guias as $g)
                    <div class="mb-2 grid gap-0.5 rounded-lg border p-3 text-sm dark:border-gray-800" data-guia="{{ $g['id'] }}">
                        <p class="font-bold text-gray-800 dark:text-white">{{ $g['transportadora'] }} &middot; {{ $g['numero_guia'] }}</p>

                        @if ($g['tracking'])
                            <p class="text-xs text-gray-600 dark:text-gray-300">
                                @lang('logistica::app.despachos.modal.tracking-l'):
                                @if ($g['tracking_es_url'])
                                    <a href="{{ $g['tracking'] }}" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:underline">{{ $g['tracking'] }}</a>
                                @else
                                    {{ $g['tracking'] }}
                                @endif
                            </p>
                        @endif

                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            @lang('logistica::app.despachos.modal.registrada', ['nombre' => $g['registrada_por'], 'fecha' => \Illuminate\Support\Str::of($g['registrada_en'])->substr(0, 16)])
                            &middot;
                            @if ($g['tiene_pdf'])
                                @lang('logistica::app.despachos.modal.pdf-l'): @lang('logistica::app.despachos.modal.version', ['n' => $g['archivo_version']])
                            @else
                                @lang('logistica::app.despachos.modal.sin-pdf')
                            @endif
                        </p>
                    </div>
                @empty
                    <p class="text-sm text-gray-600 dark:text-gray-300">@lang('logistica::app.despachos.modal.sin-guias')</p>
                @endforelse
            </div>

            @if ($puedeDespachar || $puedeVerEnvio)
                @include('logistica::admin.despachos.componente')
            @endif

            @if ($verCartera && $cartera !== null)
                {{-- PAG-C: todas las cifras salen de CarteraConsultaService; aqui no se calcula nada. --}}
                <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900" data-bloque-cartera>
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <p class="text-base font-semibold text-gray-800 dark:text-white">@lang('ventasasesor::app.cartera.bloque.titulo')</p>

                        @if ($puedeRegistrarPago && $cartera->enCartera && ! $cartera->requiereRevision && $cartera->saldo > 0)
                            <v-registrar-pago
                                :saldo="{{ $cartera->saldo }}"
                                saldo-formateado="{{ \ByLopez\Logistica\Support\Moneda::formatear($cartera->saldo) }}"
                                :medios='@json($mediosCobro)'
                                :plataformas='@json($plataformasTransferencia)'
                                url="{{ route('admin.ventas_asesor.pedidos.pagos.store', ['pedido' => $pedido->id]) }}"
                                :textos='@json(trans('ventasasesor::app.cartera'))'
                            ></v-registrar-pago>
                        @endif
                    </div>

                    @if (! $cartera->enCartera)
                        <p class="text-sm text-gray-600 dark:text-gray-300" data-cartera-fuera="{{ $cartera->motivoFuera }}">
                            {{ $cartera->motivoFuera === \ByLopez\VentasAsesor\DataObjects\SaldoPedido::FUERA_CARTERA_INACTIVA
                                ? trans('ventasasesor::app.cartera.bloque.inactiva')
                                : trans('ventasasesor::app.cartera.bloque.fuera') }}
                        </p>
                    @else
                        {{-- C5: el numero protagonista es SIEMPRE lo que realmente falta por cobrar (el total original es dato secundario, abajo),
                             para que nadie vuelva a cobrar mercancia que Bodega ya recibio devuelta. Un saldo a favor NUNCA se muestra como deuda negativa. --}}
                        <div class="mb-3" data-cartera-protagonista>
                            <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">@lang('ventasasesor::app.cartera.bloque.saldo-real')</p>
                            <p class="text-3xl font-bold leading-tight {{ $cartera->saldo > 0 ? 'text-gray-900 dark:text-white' : 'text-green-700 dark:text-green-400' }}" data-cartera-saldo-real>{{ \ByLopez\Logistica\Support\Moneda::formatear($cartera->saldo) }}</p>
                            <p class="mt-1 text-sm font-medium {{ $cartera->saldo > 0 ? 'text-yellow-700 dark:text-yellow-300' : ($cartera->saldoAFavor > 0 ? 'text-blue-700 dark:text-blue-300' : 'text-green-700 dark:text-green-400') }}" data-cartera-estado-humano>
                                @if ($cartera->saldo > 0)
                                    @lang('ventasasesor::app.cartera.bloque.estado-pendiente', ['monto' => \ByLopez\Logistica\Support\Moneda::formatear($cartera->saldo)])
                                @elseif ($cartera->saldoAFavor > 0)
                                    @lang('ventasasesor::app.cartera.bloque.estado-a-favor', ['monto' => \ByLopez\Logistica\Support\Moneda::formatear($cartera->saldoAFavor)])
                                @elseif ($cartera->devolucionTotal() && (float) $cartera->aplicadoTotal <= 0)
                                    @lang('ventasasesor::app.cartera.bloque.estado-devuelto')
                                @else
                                    @lang('ventasasesor::app.cartera.bloque.estado-pagado')
                                @endif
                            </p>
                            <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">
                                @lang('ventasasesor::app.cartera.bloque.pagado'): {{ \ByLopez\Logistica\Support\Moneda::formatear($cartera->aplicadoTotal) }}
                                @if ($cartera->devuelto > 0)
                                    &middot; <span data-cartera-devoluciones>@lang('ventasasesor::app.cartera.bloque.devoluciones'): −{{ \ByLopez\Logistica\Support\Moneda::formatear($cartera->devuelto) }}</span>
                                @endif
                            </p>
                            @if ($cartera->devuelto > 0)
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400" data-cartera-secundario>
                                    @lang('ventasasesor::app.cartera.bloque.total-original'): {{ \ByLopez\Logistica\Support\Moneda::formatear($cartera->total) }}
                                    &middot; @lang('ventasasesor::app.cartera.bloque.mercancia-devuelta', ['n' => $cartera->unidadesDevueltas, 'monto' => \ByLopez\Logistica\Support\Moneda::formatear($cartera->devuelto)])
                                </p>
                                @if ($cartera->saldoAFavor > 0)
                                    <p class="mt-1 text-xs text-blue-700 dark:text-blue-300" data-cartera-a-favor>@lang('ventasasesor::app.cartera.bloque.a-favor-nota', ['monto' => \ByLopez\Logistica\Support\Moneda::formatear($cartera->saldoAFavor)])</p>
                                @endif
                                @if ($cartera->devolucionTotal())
                                    <p class="mt-1 text-xs font-semibold text-gray-700 dark:text-gray-200" data-cartera-devolucion-total>@lang('ventasasesor::app.cartera.bloque.devolucion-total-nota')</p>
                                @endif
                            @endif
                        </div>

                        <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm sm:grid-cols-5">
                            <div>
                                <dt class="text-gray-600 dark:text-gray-300">@lang($cartera->devuelto > 0 ? 'ventasasesor::app.cartera.bloque.total-original' : 'ventasasesor::app.cartera.bloque.total')</dt>
                                <dd class="font-semibold text-gray-800 dark:text-white">{{ \ByLopez\Logistica\Support\Moneda::formatear($cartera->total) }}</dd>
                            </div>
                            <div>
                                <dt class="text-gray-600 dark:text-gray-300">@lang('ventasasesor::app.cartera.bloque.aplicado')</dt>
                                <dd class="font-semibold text-gray-800 dark:text-white" data-cartera-aplicado>{{ \ByLopez\Logistica\Support\Moneda::formatear($cartera->aplicadoTotal) }}</dd>
                            </div>
                            <div>
                                <dt class="text-gray-600 dark:text-gray-300">@lang('ventasasesor::app.cartera.bloque.sin-revisar')</dt>
                                <dd class="font-semibold {{ $cartera->sinRevisar > 0 ? 'text-yellow-700 dark:text-yellow-300' : 'text-gray-800 dark:text-white' }}" data-cartera-sin-revisar>{{ \ByLopez\Logistica\Support\Moneda::formatear($cartera->sinRevisar) }}</dd>
                            </div>
                            <div>
                                <dt class="text-gray-600 dark:text-gray-300">@lang('ventasasesor::app.cartera.bloque.verificado')</dt>
                                <dd class="font-semibold {{ $cartera->verificado > 0 ? 'text-green-700 dark:text-green-400' : 'text-gray-800 dark:text-white' }}" data-cartera-verificado>{{ \ByLopez\Logistica\Support\Moneda::formatear($cartera->verificado) }}</dd>
                            </div>
                            <div>
                                <dt class="text-gray-600 dark:text-gray-300">@lang('ventasasesor::app.cartera.bloque.saldo')</dt>
                                <dd class="font-bold text-gray-900 dark:text-white" data-cartera-saldo>{{ \ByLopez\Logistica\Support\Moneda::formatear($cartera->saldo) }}</dd>
                            </div>
                        </dl>

                        @if ($cartera->sinRevisar > 0)
                            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">@lang('ventasasesor::app.cartera.bloque.nota-sin-revisar')</p>
                        @endif

                        @if ($cartera->requiereRevision)
                            <p class="mt-2 text-sm font-medium text-red-600 dark:text-red-400">@lang('ventasasesor::app.cartera.bloque.revision')</p>
                        @elseif ($cartera->saldo <= 0)
                            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">@lang('ventasasesor::app.cartera.bloque.sin-saldo')</p>
                        @endif
                    @endif
                </div>
            @endif

            @if ($verCartera && $caja !== null && ($caja['aplica'] || $caja['historico_sin_vinculo'] > 0))
                {{-- C6: ¿dónde está el dinero? Complementa a Cartera (¿el cliente debe?); no la reemplaza ni cambia el saldo. --}}
                <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900" data-bloque-caja>
                    <p class="mb-2 text-base font-semibold text-gray-800 dark:text-white">@lang('ventasasesor::app.caja.titulo')</p>

                    @if ($caja['aplica'])
                        @php($quien = collect($caja['guias'])->pluck('transportadora')->filter()->unique()->implode(', '))
                        <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm max-sm:grid-cols-1">
                            <div>
                                <dt class="text-gray-600 dark:text-gray-300">@lang('ventasasesor::app.caja.cliente-pago')</dt>
                                <dd class="font-semibold text-gray-800 dark:text-white" data-caja-cobrado>{{ \ByLopez\Logistica\Support\Moneda::formatear($caja['cobrado']) }}</dd>
                                <dd class="text-xs text-gray-500 dark:text-gray-400">@lang('ventasasesor::app.caja.a') {{ $quien }} &middot; {{ collect($caja['guias'])->pluck('guia')->filter()->implode(', ') }}</dd>
                            </div>
                            <div>
                                <dt class="text-gray-600 dark:text-gray-300">@lang('ventasasesor::app.caja.en-custodia', ['transportadora' => $quien])</dt>
                                <dd class="font-semibold {{ $caja['en_custodia'] > 0 ? 'text-yellow-700 dark:text-yellow-300' : 'text-gray-800 dark:text-white' }}" data-caja-custodia>{{ \ByLopez\Logistica\Support\Moneda::formatear($caja['en_custodia']) }}</dd>
                            </div>
                            <div>
                                <dt class="text-gray-600 dark:text-gray-300">@lang('ventasasesor::app.caja.conciliado')</dt>
                                <dd class="font-semibold text-gray-800 dark:text-white" data-caja-conciliado>{{ \ByLopez\Logistica\Support\Moneda::formatear($caja['conciliado']) }}</dd>
                                @if ($caja['conciliado'] > 0)
                                    <dd class="text-xs text-gray-500 dark:text-gray-400">
                                        @lang('ventasasesor::app.caja.efectivo'): <span data-caja-efectivo>{{ \ByLopez\Logistica\Support\Moneda::formatear($caja['efectivo']) }}</span>
                                        &middot; @lang('ventasasesor::app.caja.compensado'): <span data-caja-compensado>{{ \ByLopez\Logistica\Support\Moneda::formatear($caja['compensado']) }}</span>
                                    </dd>
                                @endif
                            </div>
                            @if (! empty($caja['liquidaciones']))
                                <div>
                                    <dt class="text-gray-600 dark:text-gray-300">@lang('ventasasesor::app.caja.liquidacion')</dt>
                                    <dd class="font-semibold text-gray-800 dark:text-white">{{ implode(', ', $caja['liquidaciones']) }}</dd>
                                </div>
                            @endif
                        </dl>

                        @if ($caja['pendiente_transportador'] > 0)
                            <p class="mt-3 text-sm font-medium text-red-600 dark:text-red-400" data-caja-pendiente>
                                @lang('ventasasesor::app.caja.pendiente', ['transportadora' => $quien]): {{ \ByLopez\Logistica\Support\Moneda::formatear($caja['pendiente_transportador']) }}
                            </p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">@lang('ventasasesor::app.caja.pendiente-nota')</p>
                        @endif
                    @endif

                    @if ($caja['historico_sin_vinculo'] > 0)
                        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400" data-caja-historico>@lang('ventasasesor::app.caja.historico', ['monto' => \ByLopez\Logistica\Support\Moneda::formatear($caja['historico_sin_vinculo'])])</p>
                    @endif
                </div>
            @endif
            <p class="text-xs text-gray-500 dark:text-gray-400">@lang('ventasasesor::app.pedidos.detalle.solo-lectura')</p>
        </div>

        <!-- Cliente, entrega y propietario -->
        <div class="flex w-[360px] max-w-full flex-col gap-2 max-sm:w-full">
            <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                <p class="mb-2 text-base font-semibold text-gray-800 dark:text-white">@lang('ventasasesor::app.pedidos.detalle.cliente')</p>

                <p class="font-medium text-gray-800 dark:text-white">{{ trim($pedido->customer_first_name.' '.$pedido->customer_last_name) }}</p>
                <p class="text-sm text-gray-600 dark:text-gray-300">{{ $envio?->phone ?: trans('ventasasesor::app.pedidos.sin-telefono') }}</p>

                @if ($pedido->customer_email)
                    <p class="text-sm text-gray-600 dark:text-gray-300">{{ $pedido->customer_email }}</p>
                @endif

                {{-- PAG-D: acceso contextual a la cartera del cliente (solo con alcance de cartera sobre este pedido). --}}
                @if ($verCartera && $pedido->customer_id)
                    <a
                        href="{{ route('admin.ventas_asesor.cartera.index', ['cliente' => $pedido->customer_id]) }}"
                        class="secondary-button mt-3"
                        data-ver-cartera-cliente
                    >
                        @lang('ventasasesor::app.cartera.cliente.ver-cartera-detalle')
                    </a>
                @endif
            </div>

            <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                <p class="mb-2 text-base font-semibold text-gray-800 dark:text-white">@lang('ventasasesor::app.pedidos.detalle.entrega')</p>

                <p class="font-medium text-gray-800 dark:text-white">{{ $pedido->shipping_title ?: '—' }}</p>

                @if ($envio)
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                        {{ $envio->address }}<br>
                        {{ collect([$envio->city, $envio->state])->filter()->implode(', ') }}
                    </p>
                @endif

                @if ($metodo_pago)
                    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">@lang('ventasasesor::app.pedidos.detalle.metodo-pago')</p>
                    <p class="text-sm font-medium text-gray-800 dark:text-white">{{ $metodo_pago }}</p>
                @endif
            </div>

            @if ($verTodos)
                <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                    <p class="mb-2 text-base font-semibold text-gray-800 dark:text-white">@lang('ventasasesor::app.pedidos.detalle.propietario')</p>

                    <p class="font-medium text-gray-800 dark:text-white" data-propietario>{{ $pedido->propietario }}</p>
                </div>
            @endif
        </div>
    </div>

    @if ($verCartera && $puedeRegistrarPago)
        {{-- Mascara monetaria visual compartida (<v-moneda>): el valor que se envia no cambia. --}}
        @include('corteconfeccion::admin.partials.moneda')

        @pushOnce('scripts')
            <script type="text/x-template" id="v-registrar-pago-template">
                <div>
                    <button type="button" class="primary-button" @click="abrir">
                        @{{ textos.bloque.registrar }}
                    </button>

                    <x-admin::modal ref="modalPago">
                        <x-slot:header>
                            <p class="text-lg font-bold text-gray-800 dark:text-white">@{{ textos.modal.titulo }}</p>
                        </x-slot>

                        <x-slot:content>
                            <div class="grid gap-3 text-sm">
                                <p class="rounded-lg bg-gray-100 px-3 py-2 dark:bg-gray-950">
                                    @{{ textos.modal['saldo-actual'] }}:
                                    <span class="font-bold">@{{ saldoFormateado }}</span>
                                </p>

                                <div v-if="! esCombinado">
                                    <label class="text-gray-600 dark:text-gray-300">@{{ textos.modal.monto }} *</label>
                                    {{-- Solo visual: se muestra «$ 51.000» y form.monto sigue siendo el texto crudo de siempre ("51000"). --}}
                                    <v-moneda
                                        class="mt-1 w-full rounded-lg border px-3 py-2 dark:border-gray-800 dark:bg-gray-900"
                                        v-model="form.monto"
                                        :max-enteros="10"
                                    ></v-moneda>
                                </div>

                                <div>
                                    <label class="text-gray-600 dark:text-gray-300">@{{ textos.modal.medio }} *</label>
                                    <select
                                        class="mt-1 w-full rounded-lg border px-3 py-2 dark:border-gray-800 dark:bg-gray-900"
                                        v-model="form.medio_cobro"
                                    >
                                        <option v-for="m in medios" :key="m.codigo" :value="m.codigo">@{{ m.etiqueta }}</option>
                                        <option v-if="puedeCombinar" value="__combinado">@{{ textos.modal.combinado }}</option>
                                    </select>
                                </div>

                                <div v-if="esTransferencia">
                                    <label class="text-gray-600 dark:text-gray-300">@{{ textos.modal.plataforma }} *</label>
                                    <select
                                        class="mt-1 w-full rounded-lg border px-3 py-2 dark:border-gray-800 dark:bg-gray-900"
                                        v-model="form.plataforma"
                                    >
                                        <option value="">@{{ textos.modal['plataforma-elegir'] }}</option>
                                        <option v-for="p in plataformas" :key="p.codigo" :value="p.codigo">@{{ p.etiqueta }}</option>
                                    </select>
                                </div>

                                <div v-if="esCombinado" class="grid gap-3">
                                    <p class="text-xs text-gray-500 dark:text-gray-400">@{{ textos.modal['combinado-ayuda'] }}</p>

                                    <div
                                        v-for="(parte, i) in partes"
                                        :key="i"
                                        class="grid gap-2 rounded-lg border p-3 dark:border-gray-800"
                                    >
                                        <div class="flex items-center justify-between">
                                            <p class="text-xs font-semibold uppercase text-gray-500">@{{ textos.modal.parte.replace(':n', i + 1) }}</p>
                                            <button v-if="partes.length > 2" type="button" class="text-xs font-semibold text-red-600" @click="quitarParte(i)">@{{ textos.modal['quitar-parte'] }}</button>
                                        </div>
                                        <select class="w-full rounded-lg border px-3 py-2 dark:border-gray-800 dark:bg-gray-900" v-model="parte.plataforma">
                                            <option value="">@{{ textos.modal['plataforma-elegir'] }}</option>
                                            <option v-for="p in plataformas" :key="p.codigo" :value="p.codigo">@{{ p.etiqueta }}</option>
                                        </select>
                                        <v-moneda :placeholder="textos.modal.monto" class="w-full rounded-lg border px-3 py-2 dark:border-gray-800 dark:bg-gray-900" v-model="parte.monto" :max-enteros="10"></v-moneda>
                                        <input type="text" maxlength="100" :placeholder="textos.modal.referencia" class="w-full rounded-lg border px-3 py-2 dark:border-gray-800 dark:bg-gray-900" v-model="parte.referencia" />
                                        <label class="text-gray-600 dark:text-gray-300">@{{ textos.modal.comprobante }}</label>
                                        <label class="secondary-button relative cursor-pointer">
                                            @{{ textos.modal['comprobante-adjuntar'] }}
                                            <input type="file" :key="clave" :aria-label="textos.modal.comprobante" accept="image/jpeg,image/png,image/webp,application/pdf" class="absolute inset-0 w-full cursor-pointer opacity-0" @change="parte.comprobante = $event.target.files[0] || null" :disabled="enviando" />
                                        </label>
                                        <p v-if="parte.comprobante" class="break-all text-xs text-gray-500">@{{ parte.comprobante.name }}</p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">@{{ textos.modal['comprobante-ayuda'] }}</p>
                                    </div>

                                    <button v-if="partes.length < 6" type="button" class="secondary-button" @click="agregarParte">@{{ textos.modal['agregar-parte'] }}</button>

                                    <div class="rounded-lg bg-gray-100 px-3 py-2 text-xs dark:bg-gray-950">
                                        <p>@{{ textos.modal.suma }}: <span class="font-bold">@{{ sumaPartes }}</span></p>
                                        <p>@{{ textos.modal.falta }}: <span class="font-bold" :class="faltaPartes < 0 ? 'text-red-600' : ''">@{{ faltaPartes }}</span></p>
                                    </div>
                                </div>
                                <div>
                                    <label class="text-gray-600 dark:text-gray-300">@{{ textos.modal.fecha }} *</label>
                                    <input
                                        type="date"
                                        :max="hoy"
                                        class="mt-1 w-full rounded-lg border px-3 py-2 dark:border-gray-800 dark:bg-gray-900"
                                        v-model="form.fecha_pago"
                                    />
                                </div>

                                <div v-if="! esCombinado && politica !== 'no_aplica'">
                                    <label class="text-gray-600 dark:text-gray-300">
                                        @{{ textos.modal.referencia }}
                                        <span v-if="politica === 'requerida' && ! esTransferencia">*</span>
                                        <span v-else class="text-xs">(@{{ textos.modal.opcional }})</span>
                                    </label>
                                    <input
                                        type="text"
                                        maxlength="100"
                                        class="mt-1 w-full rounded-lg border px-3 py-2 dark:border-gray-800 dark:bg-gray-900"
                                        v-model="form.referencia"
                                    />
                                </div>

                                <div v-if="esTransferencia" class="grid gap-2">
                                    <label class="text-gray-600 dark:text-gray-300">@{{ textos.modal.comprobante }}</label>
                                        <label class="secondary-button relative cursor-pointer">
                                            @{{ textos.modal['comprobante-adjuntar'] }}
                                            <input type="file" :key="clave" :aria-label="textos.modal.comprobante" accept="image/jpeg,image/png,image/webp,application/pdf" class="absolute inset-0 w-full cursor-pointer opacity-0" @change="comprobante = $event.target.files[0] || null" :disabled="enviando" />
                                        </label>
                                        <p v-if="comprobante" class="break-all text-xs text-gray-500">@{{ comprobante.name }}</p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">@{{ textos.modal['comprobante-ayuda'] }}</p>
                                </div>
                                <div>
                                    <label class="text-gray-600 dark:text-gray-300">
                                        @{{ textos.modal.observacion }}
                                        <span class="text-xs">(@{{ textos.modal.opcional }})</span>
                                    </label>
                                    <textarea
                                        rows="2"
                                        maxlength="1000"
                                        class="mt-1 w-full rounded-lg border px-3 py-2 dark:border-gray-800 dark:bg-gray-900"
                                        v-model="form.observacion"
                                    ></textarea>
                                </div>

                                <p v-if="error" class="text-sm font-medium text-red-600 dark:text-red-400">@{{ error }}</p>
                            </div>
                        </x-slot:content>

                        <x-slot:footer>
                            <button type="button" class="transparent-button" :disabled="enviando" @click="$refs.modalPago.close()">
                                @{{ textos.modal.cancelar }}
                            </button>

                            <button type="button" class="primary-button" :disabled="enviando" @click="registrar">
                                @{{ enviando ? textos.modal.registrando : textos.modal.guardar }}
                            </button>
                        </x-slot:footer>
                    </x-admin::modal>
                </div>
            </script>

            <script type="module">
                app.component('v-registrar-pago', {
                    template: '#v-registrar-pago-template',

                    props: ['saldo', 'saldoFormateado', 'medios', 'plataformas', 'url', 'textos'],

                    data() {
                        return {
                            form: {},
                            comprobante: null,
                            partes: [],
                            hoy: new Date(new Date().getTime() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10),
                            // Una clave por intento de registro: un reintento (doble clic, red) la reutiliza y el
                            // servidor no duplica el pago; se renueva al abrir de nuevo.
                            clave: null,
                            enviando: false,
                            error: null,
                        };
                    },

                    computed: {
                        // «Combinado» solo se ofrece si existe el medio transferencia y hay plataformas configuradas.
                        puedeCombinar() {
                            return this.medios.some(m => m.plataforma) && this.plataformas.length > 0;
                        },

                        esCombinado() {
                            return this.form.medio_cobro === '__combinado';
                        },

                        esTransferencia() {
                            return !! (this.medios.find(m => m.codigo === this.form.medio_cobro) || {}).plataforma;
                        },

                        sumaPartes() {
                            return this.sumaNumerica().toLocaleString('es-CO', { maximumFractionDigits: 2 });
                        },

                        faltaPartes() {
                            return Math.round((this.saldo - this.sumaNumerica()) * 100) / 100;
                        },

                        politica() {
                            return (this.medios.find(m => m.codigo === this.form.medio_cobro) || {}).referencia || 'opcional';
                        },
                    },

                    methods: {
                        sumaNumerica() {
                            return this.partes.reduce((t, p) => t + (parseFloat(String(p.monto || '').trim()) || 0), 0);
                        },

                        agregarParte() {
                            this.partes.push({ plataforma: '', monto: '', referencia: '', comprobante: null });
                        },

                        quitarParte(i) {
                            this.partes.splice(i, 1);
                        },

                        abrir() {
                            this.partes = [{ plataforma: '', monto: '', referencia: '', comprobante: null }, { plataforma: '', monto: '', referencia: '', comprobante: null }];
                            this.comprobante = null;
                            this.form = {
                                // Pago total por defecto (mas rapido); se puede editar para un pago parcial.
                                monto: String(this.saldo),
                                medio_cobro: this.medios.length ? this.medios[0].codigo : '',
                                fecha_pago: this.hoy,
                                plataforma: '',
                                referencia: '',
                                observacion: '',
                            };
                            this.clave = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : ('pago-' + Date.now() + '-' + Math.random().toString(36).slice(2, 12));
                            this.error = null;
                            this.$refs.modalPago.open();
                        },

                        // Solo ayudas de interfaz: el servidor valida de nuevo todo con el saldo recalculado.
                        validar() {
                            if (this.esCombinado) {
                                if (this.partes.length < 2) {
                                    return this.textos.modal['combinado-minimo'];
                                }

                                for (const p of this.partes) {
                                    const texto = String(p.monto || '').trim();

                                    if (! /^\d{1,10}(\.\d{1,2})?$/.test(texto) || parseFloat(texto) <= 0) {
                                        return this.textos.modal['combinado-monto'];
                                    }

                                    if (! p.plataforma) {
                                        return this.textos.modal['plataforma-requerida'];
                                    }

                                    if (! String(p.referencia || '').trim() && ! p.comprobante) {
                                        return this.textos.modal['evidencia-requerida'];
                                    }
                                }

                                return this.sumaNumerica() > this.saldo + 0.000001 ? this.textos.modal['combinado-excede'] : null;
                            }

                            if (this.esTransferencia && ! this.form.plataforma) {
                                return this.textos.modal['plataforma-requerida'];
                            }

                            const monto = parseFloat(this.form.monto);

                            if (! /^\d{1,10}(\.\d{1,2})?$/.test(String(this.form.monto).trim()) || monto <= 0) {
                                return this.textos.modal.error;
                            }

                            if (monto > this.saldo) {
                                return this.textos.modal['monto-mayor'];
                            }

                            if (this.esTransferencia && ! String(this.form.referencia || '').trim() && ! this.comprobante) {
                                return this.textos.modal['evidencia-requerida'];
                            }
                            if (! this.esTransferencia && this.politica === 'requerida' && ! String(this.form.referencia || '').trim()) {
                                return this.textos.modal['referencia-requerida'];
                            }

                            return null;
                        },

                        registrar() {
                            if (this.enviando) {
                                return;
                            }

                            this.error = this.validar();

                            if (this.error) {
                                return;
                            }

                            this.enviando = true;

                            const carga = this.esCombinado
                                ? { partes: this.partes, fecha_pago: this.form.fecha_pago, observacion: this.form.observacion, idempotency_key: this.clave }
                                : { ...this.form, plataforma: this.esTransferencia ? this.form.plataforma : '', idempotency_key: this.clave };

                            const datos = new FormData();
                            Object.entries(carga).forEach(([clave, valor]) => {
                                if (clave === 'partes') {
                                    valor.forEach((parte, i) => Object.entries(parte).forEach(([campo, contenido]) => {
                                        if (contenido !== null && contenido !== undefined) datos.append('partes[' + i + '][' + campo + ']', contenido);
                                    }));
                                } else if (valor !== null && valor !== undefined) datos.append(clave, valor);
                            });
                            if (this.esTransferencia && this.comprobante) datos.append('comprobante', this.comprobante);
                            this.$axios.post(this.url, datos)
                                .then(respuesta => {
                                    this.$emitter.emit('add-flash', {
                                        type: 'success',
                                        message: respuesta.data.duplicado ? this.textos.modal.duplicado : (respuesta.data.combinado ? this.textos.modal['exito-combinado'] : this.textos.modal.exito),
                                    });

                                    // Las cifras del bloque salen del servidor: se recarga en vez de recalcular aqui.
                                    window.location.reload();
                                })
                                .catch(e => {
                                    const datos = (e.response && e.response.data) || {};
                                    const primero = datos.errors ? Object.values(datos.errors)[0][0] : null;

                                    this.error = datos.mensaje || primero || this.textos.modal.error;
                                    this.enviando = false;
                                });
                        },
                    },
                });
            </script>
        @endPushOnce
    @endif
</x-admin::layouts>
