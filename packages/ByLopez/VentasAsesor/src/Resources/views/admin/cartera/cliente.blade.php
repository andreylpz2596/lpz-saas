@php
    use ByLopez\VentasAsesor\Support\Pedidos\EstadoPagoPedido;

    $moneda = core()->getBaseCurrencyCode();
    $dinero = fn (float|int|null $valor) => \ByLopez\Logistica\Support\Moneda::formatear((float) $valor);

    $tonoPago = ['pagado' => 'verde', 'parcial' => 'cian', 'pendiente' => 'amarillo', 'revisar' => 'indigo', 'no_aplica' => 'gris', 'devuelto' => 'gris'];
    $iconoPago = ['pagado' => 'check', 'parcial' => 'moneda', 'pendiente' => 'reloj', 'revisar' => 'alerta', 'no_aplica' => 'menos', 'devuelto' => 'menos'];

    $iconos = [
        'check' => '<polyline points="20 6 9 17 4 12"/>',
        'alerta' => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
        'reloj' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'moneda' => '<line x1="12" y1="2" x2="12" y2="22"/><path d="M17 6H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
        'menos' => '<line x1="6" y1="12" x2="18" y2="12"/>',
    ];

    $pill = fn (string $tono, string $texto, string $icono, string $extra = '') => '<span class="va-pill va-pill--'.$tono.' '.$extra.'"><svg viewBox="0 0 24 24" aria-hidden="true">'.($iconos[$icono] ?? '').'</svg><span>'.e($texto).'</span></span>';

    // Estado de pago del pedido (la misma regla de Mis ventas: EstadoPagoPedido::desdeCartera) y la señal de revision.
    $pillPago = function ($pedido) use ($pill, $tonoPago, $iconoPago): string {
        [$estado] = EstadoPagoPedido::desdeCartera($pedido);

        return $pill($tonoPago[$estado] ?? 'gris', trans('ventasasesor::app.pedidos.pago.'.$estado), $iconoPago[$estado] ?? 'menos');
    };

    $pillRevision = function ($pedido) use ($pill): string {
        if ($pedido->requiereRevision) {
            return $pill('indigo', trans('ventasasesor::app.cartera.cliente.requiere-revision'), 'alerta', 'va-pill--sm');
        }

        if (($pedido->sinRevisar ?? 0) > 0) {
            return $pill('amarillo', trans('ventasasesor::app.cartera.cliente.sin-revisar'), 'alerta', 'va-pill--sm');
        }

        return ($pedido->verificado ?? 0) > 0 ? $pill('verde', trans('ventasasesor::app.cartera.cliente.verificado'), 'check', 'va-pill--sm') : '';
    };

    // Solo se listan pedidos con saldo o en revision; los saldados se cuentan aparte.
    $pendientes = array_values(array_filter($cartera->pedidos, fn ($p) => ($p->saldo ?? 0) > 0 || $p->requiereRevision));
    $saldados = count($cartera->pedidos) - count($pendientes);

    $hayElegibles = $puedeAbonar && $cartera->carteraActiva && $elegibles !== [];
    $urlPedido = fn (int $id) => route('admin.ventas_asesor.pedidos.index', ['pedido' => $id]);
    $urlCartera = route('admin.ventas_asesor.cartera.index', ['cliente' => $cliente['id']]);
@endphp

@once
    @push('styles')
        @include('ventasasesor::admin.cartera.estilos')
    @endpush
@endonce

<x-admin::layouts>
    <x-slot:title>
        @lang('ventasasesor::app.cartera.cliente.titulo') · {{ $cliente['nombre'] }}
    </x-slot>

    <div class="va-orders-page bylopez-scope">
        <div class="flex items-start justify-between gap-4 max-sm:flex-wrap">
            <div class="min-w-0">
                <a href="{{ route('admin.ventas_asesor.pedidos.index') }}" class="text-sm text-blue-600 hover:underline">
                    ← @lang('ventasasesor::app.cartera.cliente.volver')
                </a>

                <p class="mt-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-gray-400">@lang('ventasasesor::app.cartera.cliente.titulo')</p>
                <h1 class="text-2xl font-bold leading-8 text-gray-900 dark:text-white" data-cliente>{{ $cliente['nombre'] ?: '#'.$cliente['id'] }}</h1>

                <p class="mt-1 text-sm text-slate-500 dark:text-gray-400">
                    {{ $cliente['telefono'] ?: trans('ventasasesor::app.pedidos.sin-telefono') }}
                    @if ($cliente['grupo'])
                        &middot; @lang('ventasasesor::app.cartera.cliente.grupo'): {{ $cliente['grupo'] }}
                    @endif
                </p>
            </div>

            @if ($puedeMensaje ?? false)
                <div class="flex items-center gap-2" data-estado-cuenta>
                    @include('logistica::admin.mensajes.boton', [
                        'pedidoId' => $cliente['id'],
                        'numero' => $cliente['id'],
                        'etiqueta' => $cliente['nombre'] ?: '#'.$cliente['id'],
                        'titulo' => trans('ventasasesor::app.mensajes.etiquetas.estado_cuenta'),
                        'conTexto' => true,
                    ])
                </div>

                {{-- Mismo componente de los mensajes del pedido; solo cambian las URL y los textos del sujeto. --}}
                @include('logistica::admin.mensajes.whatsapp', [
                    'urlOpciones' => route('admin.ventas_asesor.cartera.mensajes.opciones', ['cliente' => '__ID__']),
                    'urlMensaje' => route('admin.ventas_asesor.cartera.mensajes.mensaje', ['cliente' => '__ID__', 'clave' => '__CLAVE__']),
                    'textos' => [
                        'pedido' => trans('ventasasesor::app.mensajes.cliente'),
                        'sinMensajes' => trans('ventasasesor::app.cartera.cliente.inactiva'),
                        'cambio' => trans('ventasasesor::app.mensajes.cambio_cartera'),
                    ],
                ])
            @endif

            @if ($hayElegibles)
                <v-registrar-abono
                    :elegibles='@json($elegibles)'
                    :medios='@json($mediosCobro)'
                    :plataformas='@json($plataformasTransferencia)'
                    :deuda="{{ $deuda_elegible }}"
                    moneda="{{ $moneda }}"
                    url="{{ route('admin.ventas_asesor.cartera.abonos.store', ['cliente' => $cliente['id']]) }}"
                    url-cartera="{{ $urlCartera }}"
                    :textos='@json(trans('ventasasesor::app.cartera'))'
                ></v-registrar-abono>
            @endif
        </div>

        @if ($abono)
            <div class="va-panel mt-4 flex items-start gap-3 p-4" style="border-color:#86efac;background:#f0fdf4" data-abono-registrado="{{ $abono['id'] }}">
                <span class="va-pill va-pill--verde va-pill--sm"><svg viewBox="0 0 24 24" aria-hidden="true">{!! $iconos['check'] !!}</svg></span>

                <div>
                    <p class="font-bold" style="color:#166534">@lang('ventasasesor::app.cartera.cliente.abono-registrado')</p>
                    <p class="text-sm" style="color:#166534">
                        @lang($abono['estado'] === 'confirmado' ? 'ventasasesor::app.cartera.cliente.abono-detalle-confirmado' : 'ventasasesor::app.cartera.cliente.abono-detalle', ['monto' => $dinero($abono['monto']), 'pedidos' => $abono['pedidos']])
                    </p>
                </div>
            </div>
        @endif

        {{-- Doc. 35: CUPO DE CARTERA (limite autorizado − exposicion derivada de Cartera). Solo con la hoja cartera.cupo.ver. --}}
        @if (! empty($cupo))
            @include('ventasasesor::admin.cartera.cupo')
        @endif

        @if (! $cartera->carteraActiva)
            <div class="va-panel mt-5 p-5 text-sm text-gray-600 dark:text-gray-300" data-cartera-inactiva>
                @lang('ventasasesor::app.cartera.cliente.inactiva')
            </div>
        @else
            {{-- Todas las cifras salen de CarteraConsultaService (carteraDelCliente): aqui no se calcula nada. --}}
            <div class="mt-5 grid grid-cols-2 gap-3 xl:grid-cols-4">
                <div class="va-panel va-kpi-box va-kpi-box--saldo">
                    <p class="va-kpi-label">@lang('ventasasesor::app.cartera.cliente.deuda-total')</p>
                    <p class="va-kpi-value" data-cartera-deuda>{{ $dinero($cartera->carteraTotal) }}</p>
                </div>

                <div class="va-panel va-kpi-box">
                    <p class="va-kpi-label">@lang('ventasasesor::app.cartera.cliente.sin-revisar')</p>
                    <p class="va-kpi-value" data-cartera-sin-revisar>{{ $dinero($cartera->sinRevisarTotal()) }}</p>
                </div>

                <div class="va-panel va-kpi-box">
                    <p class="va-kpi-label">@lang('ventasasesor::app.cartera.cliente.verificado')</p>
                    <p class="va-kpi-value" data-cartera-verificado>{{ $dinero($cartera->verificadoTotal()) }}</p>
                </div>

                <div class="va-panel va-kpi-box">
                    <p class="va-kpi-label">@lang('ventasasesor::app.cartera.cliente.pedidos-con-saldo')</p>
                    <p class="va-kpi-value" data-cartera-pedidos>{{ $cartera->pedidosConSaldo() }}</p>
                </div>
            </div>

            {{-- D10 V1: saldo a favor del cliente (solo con permiso de ver credito y alcance «todos»). --}}
            @if (! empty($credito))
                @include('ventasasesor::admin.cartera.credito')
            @endif

            @if ($cartera->porRevisarTotal > 0 || $cartera->pedidosPorRevisar() > 0)
                <p class="mt-3 text-sm font-medium" style="color:#3730a3">
                    @lang('ventasasesor::app.cartera.cliente.por-revisar', ['monto' => $dinero($cartera->porRevisarTotal)])
                </p>
            @endif

            @if ($pendientes === [])
                <div class="va-panel mt-5 p-6 text-center text-gray-500 dark:text-gray-400">
                    @lang('ventasasesor::app.cartera.cliente.vacio')
                </div>
            @else
                <p class="mt-5 text-base font-semibold text-gray-900 dark:text-white">@lang('ventasasesor::app.cartera.cliente.pedidos')</p>

                {{-- Escritorio / portatil --}}
                <div class="va-panel va-desktop-table mt-2 overflow-hidden">
                    <table class="va-orders-table text-left text-sm">
                        <colgroup>
                            <col style="width: 17%">
                            <col style="width: 12%">
                            <col style="width: 12%">
                            <col style="width: 12%">
                            <col style="width: 12%">
                            <col style="width: 12%">
                            <col style="width: 15%">
                            <col style="width: 8%">
                        </colgroup>

                        <thead class="bg-slate-50 dark:bg-gray-950">
                            <tr>
                                <th>@lang('ventasasesor::app.cartera.cliente.pedido-fecha')</th>
                                <th class="text-right">@lang('ventasasesor::app.cartera.cliente.total')</th>
                                <th class="text-right">@lang('ventasasesor::app.cartera.cliente.aplicado')</th>
                                <th class="text-right">@lang('ventasasesor::app.cartera.cliente.sin-revisar')</th>
                                <th class="text-right">@lang('ventasasesor::app.cartera.cliente.verificado')</th>
                                <th class="text-right">@lang('ventasasesor::app.cartera.cliente.saldo')</th>
                                <th>@lang('ventasasesor::app.cartera.cliente.pago')</th>
                                <th></th>
                            </tr>
                        </thead>

                        <tbody>
                            @foreach ($pendientes as $pedido)
                                <tr data-pedido="{{ $pedido->orderId }}">
                                    <td>
                                        <p class="va-order-id text-gray-900 dark:text-white">#{{ $pedido->incrementId }}</p>
                                        <p class="va-sub">{{ core()->formatDate($fechas[$pedido->orderId] ?? null, 'd/m/Y') }}</p>
                                    </td>
                                    <td class="va-money text-right text-gray-800 dark:text-gray-200">{{ $dinero($pedido->total) }}</td>
                                    <td class="va-money text-right text-gray-800 dark:text-gray-200">{{ $dinero($pedido->aplicadoTotal) }}</td>
                                    <td class="va-money text-right text-gray-800 dark:text-gray-200">{{ $dinero($pedido->sinRevisar) }}</td>
                                    <td class="va-money text-right text-gray-800 dark:text-gray-200">{{ $dinero($pedido->verificado) }}</td>
                                    <td class="va-money--strong text-right text-gray-900 dark:text-white">{{ $dinero($pedido->saldo) }}</td>
                                    <td>
                                        <div class="va-stack">
                                            {!! $pillPago($pedido) !!}
                                            {!! $pillRevision($pedido) !!}
                                        </div>
                                    </td>
                                    <td class="text-right">
                                        <a
                                            href="{{ $urlPedido($pedido->orderId) }}"
                                            class="icon-view inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 bg-white text-xl text-blue-600 transition-all hover:bg-blue-50 dark:border-gray-800 dark:bg-gray-900 dark:hover:bg-gray-950"
                                            title="@lang('ventasasesor::app.pedidos.ver-detalle')"
                                            aria-label="@lang('ventasasesor::app.pedidos.ver-detalle') #{{ $pedido->incrementId }}"
                                        ></a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Tablet y movil: tarjetas --}}
                <div class="va-mobile-orders mt-2 gap-3">
                    @foreach ($pendientes as $pedido)
                        <div class="va-panel p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="va-order-id text-gray-900 dark:text-white">#{{ $pedido->incrementId }}</p>
                                    <p class="va-sub">{{ core()->formatDate($fechas[$pedido->orderId] ?? null, 'd/m/Y') }}</p>
                                </div>

                                <a href="{{ $urlPedido($pedido->orderId) }}" class="secondary-button shrink-0">
                                    <span class="icon-view text-xl"></span>
                                    @lang('ventasasesor::app.pedidos.ver-detalle')
                                </a>
                            </div>

                            <div class="mt-3 grid grid-cols-2 gap-3 rounded-lg bg-slate-50 p-3 dark:bg-gray-950">
                                <div>
                                    <p class="va-caption">@lang('ventasasesor::app.cartera.cliente.total')</p>
                                    <p class="va-money text-gray-900 dark:text-white">{{ $dinero($pedido->total) }}</p>
                                    <p class="va-caption mt-2">@lang('ventasasesor::app.cartera.cliente.aplicado')</p>
                                    <p class="va-money text-gray-900 dark:text-white">{{ $dinero($pedido->aplicadoTotal) }}</p>
                                </div>

                                <div class="text-right">
                                    <p class="va-caption">@lang('ventasasesor::app.cartera.cliente.saldo')</p>
                                    <p class="va-money--strong text-gray-900 dark:text-white">{{ $dinero($pedido->saldo) }}</p>
                                    <p class="va-caption mt-2">@lang('ventasasesor::app.cartera.cliente.sin-revisar')</p>
                                    <p class="va-money text-gray-900 dark:text-white">{{ $dinero($pedido->sinRevisar) }}</p>
                                </div>
                            </div>

                            <div class="va-stack mt-3">
                                <span class="va-caption">@lang('ventasasesor::app.cartera.cliente.pago')</span>
                                {!! $pillPago($pedido) !!}
                                {!! $pillRevision($pedido) !!}
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($saldados > 0 || $cartera->fueraDeCartera > 0)
                <p class="mt-3 text-xs text-slate-500 dark:text-gray-400">
                    @if ($saldados > 0)
                        @lang('ventasasesor::app.cartera.cliente.saldados', ['cantidad' => $saldados])
                    @endif
                    @if ($cartera->fueraDeCartera > 0)
                        @lang('ventasasesor::app.cartera.cliente.fuera', ['cantidad' => $cartera->fueraDeCartera])
                    @endif
                </p>
            @endif
        @endif
    </div>

    @if ($hayElegibles)
        @pushOnce('scripts')
            <script type="text/x-template" id="v-registrar-abono-template">
                <div>
                    <button type="button" class="primary-button whitespace-nowrap" @click="abrir">
                        @{{ textos.cliente['registrar-abono'] }}
                    </button>

                    <x-admin::modal ref="modalAbono">
                        <x-slot:header>
                            <p class="text-lg font-bold text-gray-800 dark:text-white">@{{ textos.abono.titulo }}</p>
                        </x-slot>

                        <x-slot:content>
                            <div class="grid gap-3 text-sm">
                                <p class="rounded-lg bg-gray-100 px-3 py-2 dark:bg-gray-950">
                                    @{{ textos.abono['deuda-elegible'] }}:
                                    <span class="font-bold">@{{ dinero(deudaCent) }}</span>
                                </p>

                                <div>
                                    <label class="text-gray-600 dark:text-gray-300">@{{ textos.abono.monto }} *</label>
                                    <input
                                        type="text"
                                        inputmode="decimal"
                                        class="mt-1 w-full rounded-lg border px-3 py-2 dark:border-gray-800 dark:bg-gray-900"
                                        v-model="form.monto"
                                    />
                                </div>

                                <div class="grid grid-cols-2 gap-3 max-sm:grid-cols-1">
                                    <div>
                                        <label class="text-gray-600 dark:text-gray-300">@{{ textos.modal.medio }} *</label>
                                        <select
                                            class="mt-1 w-full rounded-lg border px-3 py-2 dark:border-gray-800 dark:bg-gray-900"
                                            v-model="form.medio_cobro"
                                        >
                                            <option v-for="m in medios" :key="m.codigo" :value="m.codigo">@{{ m.etiqueta }}</option>
                                        </select>
                                    </div>

                                    <div v-if="exigePlataforma">
                                        <label class="text-gray-600 dark:text-gray-300">@{{ textos.modal.plataforma }} *</label>
                                        <select
                                            class="mt-1 w-full rounded-lg border px-3 py-2 dark:border-gray-800 dark:bg-gray-900"
                                            v-model="form.plataforma"
                                        >
                                            <option value="">@{{ textos.modal['plataforma-elegir'] }}</option>
                                            <option v-for="p in plataformas" :key="p.codigo" :value="p.codigo">@{{ p.etiqueta }}</option>
                                        </select>
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
                                </div>

                                <div v-if="politica !== 'no_aplica'">
                                    <label class="text-gray-600 dark:text-gray-300">
                                        @{{ textos.modal.referencia }}
                                        <span v-if="politica === 'requerida'">*</span>
                                        <span v-else class="text-xs">(@{{ textos.modal.opcional }})</span>
                                    </label>
                                    <input
                                        type="text"
                                        maxlength="100"
                                        class="mt-1 w-full rounded-lg border px-3 py-2 dark:border-gray-800 dark:bg-gray-900"
                                        v-model="form.referencia"
                                    />
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

                                <div>
                                    <div class="flex items-center justify-between gap-2">
                                        <p class="font-semibold text-gray-800 dark:text-white">@{{ textos.abono.distribucion }}</p>

                                        <button type="button" class="transparent-button" @click="proponer(true)">
                                            @{{ textos.abono.proponer }}
                                        </button>
                                    </div>

                                    <p class="text-xs text-gray-500 dark:text-gray-400">@{{ textos.abono.ayuda }}</p>

                                    <div class="mt-1 max-h-[34vh] overflow-y-auto">
                                        <div v-for="p in elegibles" :key="p.id" class="va-linea">
                                            <div class="min-w-0">
                                                <p class="font-bold text-gray-900 dark:text-white">#@{{ p.increment_id }}</p>
                                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                                    @{{ textos.abono.saldo }} @{{ dinero(centavos(p.saldo)) }}
                                                </p>
                                            </div>

                                            <input
                                                type="text"
                                                inputmode="decimal"
                                                class="w-full rounded-lg border px-3 py-2 text-right dark:border-gray-800 dark:bg-gray-900"
                                                :class="centavos(lineas[p.id]) > centavos(p.saldo) ? 'border-red-500' : ''"
                                                v-model="lineas[p.id]"
                                                @input="editado = true"
                                            />
                                        </div>
                                    </div>

                                    <p class="mt-2 text-sm font-medium" :class="cuadra ? 'text-green-700 dark:text-green-400' : 'text-amber-700 dark:text-amber-300'">
                                        @{{ textos.abono.asignado }}: @{{ dinero(asignadoCent) }}
                                        <span v-if="diferenciaCent > 0"> · @{{ textos.abono.falta }}: @{{ dinero(diferenciaCent) }}</span>
                                        <span v-else-if="diferenciaCent < 0"> · @{{ textos.abono.sobra }}: @{{ dinero(-diferenciaCent) }}</span>
                                    </p>
                                </div>

                                <p v-if="error" class="text-sm font-medium text-red-600 dark:text-red-400">@{{ error }}</p>
                            </div>
                        </x-slot:content>

                        <x-slot:footer>
                            <button type="button" class="transparent-button" :disabled="enviando" @click="$refs.modalAbono.close()">
                                @{{ textos.modal.cancelar }}
                            </button>

                            <button type="button" class="primary-button" :disabled="enviando || ! puedeEnviar" @click="registrar">
                                @{{ enviando ? textos.modal.registrando : textos.abono.titulo }}
                            </button>
                        </x-slot:footer>
                    </x-admin::modal>
                </div>
            </script>

            <script type="module">
                app.component('v-registrar-abono', {
                    template: '#v-registrar-abono-template',

                    props: ['elegibles', 'medios', 'plataformas', 'deuda', 'moneda', 'url', 'urlCartera', 'textos'],

                    data() {
                        return {
                            form: {},
                            lineas: {},
                            // true cuando el usuario edito una linea a mano: dejar de re-proponer al cambiar el monto.
                            editado: false,
                            hoy: new Date(new Date().getTime() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10),
                            // Una clave por apertura: un reintento (doble clic, red) la reutiliza y el servidor no duplica.
                            clave: null,
                            enviando: false,
                            error: null,
                        };
                    },

                    computed: {
                        exigePlataforma() {
                            return !! (this.medios.find(m => m.codigo === this.form.medio_cobro) || {}).plataforma;
                        },

                        politica() {
                            return (this.medios.find(m => m.codigo === this.form.medio_cobro) || {}).referencia || 'opcional';
                        },

                        deudaCent() {
                            return this.centavos(this.deuda);
                        },

                        montoCent() {
                            return this.centavos(this.form.monto);
                        },

                        asignadoCent() {
                            return this.elegibles.reduce((suma, p) => suma + this.centavos(this.lineas[p.id]), 0);
                        },

                        diferenciaCent() {
                            return this.montoCent - this.asignadoCent;
                        },

                        cuadra() {
                            return this.montoCent > 0 && this.diferenciaCent === 0;
                        },

                        // Solo ayudas de interfaz: el servidor valida de nuevo todo con el saldo recalculado bajo bloqueo.
                        puedeEnviar() {
                            return this.cuadra
                                && this.montoCent <= this.deudaCent
                                && this.elegibles.every(p => this.centavos(this.lineas[p.id]) <= this.centavos(p.saldo));
                        },
                    },

                    watch: {
                        'form.monto'() {
                            if (! this.editado) {
                                this.proponer(false);
                            }
                        },
                    },

                    methods: {
                        centavos(valor) {
                            const numero = parseFloat(String(valor ?? '').replace(',', '.'));

                            return Number.isFinite(numero) ? Math.round(numero * 100) : 0;
                        },

                        dinero(centavos) {
                            return new Intl.NumberFormat('es-CO', { style: 'currency', currency: this.moneda, maximumFractionDigits: 0 }).format(centavos / 100);
                        },

                        texto(centavos) {
                            return centavos > 0 ? String(centavos / 100) : '';
                        },

                        // Propuesta FIFO (el pedido mas antiguo primero; `elegibles` llega ya ordenado). Es solo una propuesta.
                        proponer(forzar) {
                            if (forzar) {
                                this.editado = false;
                            }

                            let restante = this.montoCent;
                            const propuesta = {};

                            this.elegibles.forEach(p => {
                                const aplicar = Math.max(0, Math.min(restante, this.centavos(p.saldo)));

                                propuesta[p.id] = this.texto(aplicar);
                                restante -= aplicar;
                            });

                            this.lineas = propuesta;
                        },

                        abrir() {
                            this.form = {
                                // Por defecto la deuda elegible total (se puede bajar para un abono parcial).
                                monto: String(this.deuda),
                                medio_cobro: this.medios.length ? this.medios[0].codigo : '',
                                fecha_pago: this.hoy,
                                plataforma: '',
                                referencia: '',
                                observacion: '',
                            };
                            this.editado = false;
                            this.proponer(true);
                            this.clave = (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : ('abono-' + Date.now() + '-' + Math.random().toString(36).slice(2, 12));
                            this.error = null;
                            this.$refs.modalAbono.open();
                        },

                        validar() {
                            if (this.montoCent <= 0) {
                                return this.textos.modal.error;
                            }

                            if (this.montoCent > this.deudaCent) {
                                return this.textos.abono['monto-mayor'];
                            }

                            if (this.elegibles.some(p => this.centavos(this.lineas[p.id]) > this.centavos(p.saldo))) {
                                return this.textos.abono['linea-mayor'];
                            }

                            if (! this.cuadra) {
                                return this.textos.abono['no-cuadra'];
                            }

                            if (this.exigePlataforma && ! this.form.plataforma) {
                                return this.textos.modal['plataforma-requerida'];
                            }

                            if (this.politica === 'requerida' && ! String(this.form.referencia || '').trim()) {
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

                            const distribucion = this.elegibles
                                .filter(p => this.centavos(this.lineas[p.id]) > 0)
                                .map(p => ({ pedido_id: p.id, monto: (this.centavos(this.lineas[p.id]) / 100).toFixed(2) }));

                            this.$axios.post(this.url, {
                                ...this.form,
                                plataforma: this.exigePlataforma ? this.form.plataforma : '',
                                monto: (this.montoCent / 100).toFixed(2),
                                distribucion,
                                idempotency_key: this.clave,
                            })
                                .then(respuesta => {
                                    // La pantalla de cartera se recarga desde el servidor y muestra el resumen del abono.
                                    window.location.href = this.urlCartera + '&abono=' + respuesta.data.pago_id;
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
