@include('corteconfeccion::admin.partials.estilos')
@include('corteconfeccion::admin.partials.tarifas-prestador')

<x-admin::layouts>
    <x-slot:title>{{ $cortador->nombre }}</x-slot>

    <v-cortador-view>
        <div class="grid">
            <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
                <div class="flex items-center gap-2.5">
                    <p class="shimmer w-40 p-2.5"></p>
                    <p class="shimmer w-16 p-2.5"></p>
                </div>
            </div>
        </div>
    </v-cortador-view>

    @pushOnce('scripts')
        <script
            type="text/x-template"
            id="v-cortador-view-template"
        >
            <div class="bylopez-scope grid">
                <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
                    <div class="flex items-center gap-2.5">
                        <h1
                            class="text-xl font-bold leading-6 text-gray-800 dark:text-white"
                            v-text="cortador.nombre"
                        ></h1>

                        <span
                            v-if="cortador.estado === 'activo'"
                            class="label-active mx-1.5 text-sm"
                        >
                            Activo
                        </span>

                        <span
                            v-else
                            class="label-canceled mx-1.5 text-sm"
                        >
                            Inactivo
                        </span>
                    </div>

                    <a
                        href="{{ route('admin.corte_confeccion.cortadores.index') }}"
                        class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800"
                    >
                        Volver
                    </a>
                </div>

                <div class="mt-7 flex flex-wrap items-center gap-x-1 gap-y-2">
                    <div class="inline-flex w-full max-w-max items-center gap-x-2 px-1 py-1.5 text-center font-semibold text-gray-600 dark:text-gray-300">
                        <span class="icon-processing text-2xl"></span>

                        @{{ resumen.ordenes_activas }} orden@{{ Number(resumen.ordenes_activas) === 1 ? '' : 'es' }} activa@{{ Number(resumen.ordenes_activas) === 1 ? '' : 's' }}
                    </div>

                    <div class="inline-flex w-full max-w-max items-center gap-x-2 px-1 py-1.5 text-center font-semibold text-gray-600 dark:text-gray-300">
                        <span class="icon-product text-2xl"></span>

                        @{{ Number(resumen.prendas_cortadas).toLocaleString('es-CO') }} prendas cortadas
                    </div>

                    <div class="inline-flex w-full max-w-max items-center gap-x-2 px-1 py-1.5 text-center font-semibold text-gray-600 dark:text-gray-300">
                        <span class="icon-pause text-2xl"></span>

                        @{{ Number(resumen.pendiente_cortador).toLocaleString('es-CO') }} pendiente cortador
                    </div>

                    <div class="inline-flex w-full max-w-max items-center gap-x-2 px-1 py-1.5 text-center font-semibold text-gray-600 dark:text-gray-300">
                        <span class="icon-ship text-2xl"></span>

                        @{{ Number(resumen.pendiente_recepcion).toLocaleString('es-CO') }} pendiente recepcion
                    </div>
                </div>

                <div class="mt-3.5 flex items-start gap-2.5 max-xl:flex-wrap">
                    <div class="flex min-w-0 flex-1 flex-col gap-2 max-xl:flex-auto">
                        <div class="box-shadow min-w-0 overflow-hidden rounded bg-white p-4 dark:bg-gray-900">
                            <p class="text-base font-semibold leading-none text-gray-800 dark:text-white">
                                Ordenes de corte
                            </p>

                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                Ordenes asignadas a este cortador.
                            </p>

                            <div class="mt-4">
                                <x-admin::datagrid
                                    :src="route('admin.corte_confeccion.ordenes_corte.index', ['cortador_id' => $cortador->usuario_id])"
                                    :isMultiRow="true"
                                />
                            </div>
                        </div>

                        <v-referencias-tarifa
                            titulo="Referencias y precios de corte"
                            subtitulo="Valor de corte por unidad para cada tipo de tela y referencia de producto. Son tarifas propias del cortador, independientes de las del taller."
                            :puede-gestionar="{{ $puedeGestionarTarifas ? 'true' : 'false' }}"
                            grid-url="{{ route('admin.corte_confeccion.cortadores.referencias.index', $cortador->id) }}"
                            historial-url="{{ route('admin.corte_confeccion.cortadores.referencias.historial', $cortador->id) }}"
                            store-url="{{ route('admin.corte_confeccion.cortadores.tarifas.store', $cortador->id) }}"
                            anular-url="{{ route('admin.corte_confeccion.cortadores.tarifas.anular', ['id' => $cortador->id, 'tarifaId' => '__ID__']) }}"
                            :opciones="opcionesTarifa"
                            :aviso="tarifaGeneralAnterior"
                        ></v-referencias-tarifa>

                        @include('corteconfeccion::admin.partials.cartera-resumen', ['carteraResumen' => \ByLopez\CorteConfeccion\Support\CarteraVista::resumenFicha($cortador)])

                        <div class="box-shadow min-w-0 overflow-hidden rounded bg-white p-4 dark:bg-gray-900">
                            <p class="text-base font-semibold leading-none text-gray-800 dark:text-white">
                                Historial del cortador
                            </p>

                            <div class="mt-6 grid justify-center justify-items-center gap-3 px-2.5 py-6 text-center">
                                <span class="icon-report text-4xl text-gray-300 dark:text-gray-700"></span>

                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                    Aqui se mostraran entregas, recepciones o incidencias del cortador cuando el modulo los registre.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="flex w-[360px] max-w-full shrink-0 flex-col gap-2 max-xl:w-full max-sm:w-full">
                        <x-admin::accordion>
                            <x-slot:header>
                                <div class="flex w-full">
                                    <p class="w-full p-2.5 text-base font-semibold text-gray-800 dark:text-white">
                                        Cortador
                                    </p>

                                    @if (bouncer()->hasPermission('corte_confeccion.cortadores.edit'))
                                        <v-cortador-edit
                                            :cortador="cortador"
                                            :usuarios="usuariosCortador"
                                            variant="link"
                                            @update-cortador="updateCortador"
                                        ></v-cortador-edit>
                                    @endif
                                </div>
                            </x-slot>

                            <x-slot:content>
                                <div class="grid gap-y-2.5">
                                    <p
                                        class="break-all font-semibold text-gray-800 dark:text-white"
                                        v-text="cortador.nombre"
                                    ></p>

                                    <p class="text-gray-600 dark:text-gray-300">
                                        Responsable: @{{ cortador.contacto || 'N/A' }}
                                    </p>

                                    <p class="text-gray-600 dark:text-gray-300">
                                        Telefono: @{{ cortador.telefono || 'N/A' }}
                                    </p>

                                    <p class="text-gray-600 dark:text-gray-300">
                                        Direccion: @{{ cortador.direccion || 'Sin direccion registrada' }}
                                    </p>

                                    <p
                                        class="break-all text-gray-600 dark:text-gray-300"
                                        v-if="cortador.usuario"
                                    >
                                        Usuario asociado: @{{ cortador.usuario.name }} (@{{ cortador.usuario.email }})
                                    </p>

                                    <p
                                        v-if="cortador.estado === 'activo'"
                                        class="label-active w-max text-sm"
                                    >
                                        Activo
                                    </p>

                                    <p
                                        v-else
                                        class="label-canceled w-max text-sm"
                                    >
                                        Inactivo
                                    </p>
                                </div>
                            </x-slot>
                        </x-admin::accordion>
                    </div>
                </div>
            </div>
        </script>

        @if (bouncer()->hasPermission('corte_confeccion.cortadores.edit'))
            @include('corteconfeccion::admin.cortadores.edit-modal')
        @endif

        <script type="module">
            app.component('v-cortador-view', {
                template: '#v-cortador-view-template',

                data() {
                    return {
                        cortador: @json($cortadorPayload),

                        usuariosCortador: @json($usuariosCortador),

                        resumen: @json($resumen),

                        opcionesTarifa: @json($opcionesTarifa),

                        tarifaGeneralAnterior: @json($tarifaGeneralAnterior),
                    };
                },

                methods: {
                    updateCortador(cortador) {
                        this.cortador = {
                            ...this.cortador,
                            ...cortador,
                        };
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
