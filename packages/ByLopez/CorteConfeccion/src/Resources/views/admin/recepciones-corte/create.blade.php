{{--
    RETIRADO (F0, docs/ORDEN_CORTE_CREACION_V2_AUDITORIA_02.md §12.2 y §13).

    Esta vista ya NO se renderiza: RecepcionCorteController::create() redirige
    siempre a ordenes-corte/show.blade.php (card-recepcion), que es el unico
    flujo oficial de recepcion. Se conserva el archivo sin eliminar --no hay
    ningun link ni test que dependa de el-- porque la instruccion de esta fase
    fue documentar el retiro seguro en vez de borrar a ciegas; puede eliminarse
    en una fase posterior si se aprueba.

    Motivo del retiro: el <select> de abajo permitia a Bodega elegir CUALQUIER
    producto del catalogo por fila, sin validar que perteneciera a la orden que
    se estaba recibiendo -- exactamente el hueco que F0 cierra en el flujo
    oficial (que ahora identifica cada fila por orden_corte_detalle_id y deriva
    producto/talla del lado servidor, nunca del cliente). NO reactivar ni
    adaptar este archivo para seguir permitiendo seleccion manual de producto.
--}}
<x-admin::layouts>
    <x-slot:title>Recibir corte {{ $orden->codigo }}</x-slot>

    <form method="POST" action="{{ route('admin.corte_confeccion.recepciones_corte.store', $orden->id) }}" data-confirm="Esta accion registra la recepcion definitiva de bodega para esta orden y no se puede deshacer. ¿Confirmas el registro?" data-confirm-title="Registrar recepcion">
        @csrf

        <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
            <p class="text-xl font-bold text-gray-800 dark:text-white">Recibir corte {{ $orden->codigo }}</p>
            <button class="primary-button">Guardar recepcion</button>
        </div>

        <p class="mt-4 text-sm text-gray-600 dark:text-gray-300">Peso entregado: {{ $orden->peso_entregado }} kg</p>

        <div class="mt-4 grid grid-cols-3 gap-4 max-md:grid-cols-1">
            @foreach ([
                'fecha_recepcion' => 'Fecha recepcion',
                'peso_sobrante_usable' => 'Peso sobrante usable',
                'peso_retasos' => 'Peso retazos',
                'peso_desperdicio' => 'Peso desperdicio',
                'recibido_por' => 'Recibido por',
            ] as $field => $label)
                <x-admin::form.control-group>
                    <x-admin::form.control-group.label class="required">{{ $label }}</x-admin::form.control-group.label>
                    <x-admin::form.control-group.control
                        type="{{ $field === 'fecha_recepcion' ? 'date' : (str_starts_with($field, 'peso') ? 'number' : 'text') }}"
                        step="0.001"
                        name="{{ $field }}"
                        value="{{ old($field, $field === 'fecha_recepcion' ? now()->toDateString() : null) }}"
                    />
                    <x-admin::form.control-group.error control-name="{{ $field }}" />
                </x-admin::form.control-group>
            @endforeach
        </div>

        <div class="mt-6">
            <p class="mb-3 text-base font-semibold text-gray-800 dark:text-white">Piezas reales por producto y talla</p>
            <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">"Reportado por cortador" es el dato que registro el cortador al terminar el corte y nunca se modifica desde aqui. "Diferencia" = recibido en bodega menos reportado por cortador.</p>

            <div class="mb-1 hidden grid-cols-[2fr_1fr_1fr_1fr_1fr_1fr_1fr_2fr] gap-2 text-xs font-semibold uppercase text-gray-500 dark:text-gray-400 md:grid">
                <span>Producto</span>
                <span>Genero/linea</span>
                <span>Talla</span>
                <span>Reportado cortador</span>
                <span>Recibido bodega</span>
                <span>Diferencia</span>
                <span>Defectuosa</span>
                <span>Observacion</span>
            </div>

            @foreach ($orden->detalles as $i => $proyectado)
                @php
                    $cantidadReportada = (int) $proyectado->cantidad_real_cortada;
                @endphp

                <div
                    class="mb-2 grid grid-cols-[2fr_1fr_1fr_1fr_1fr_1fr_1fr_2fr] gap-2 max-md:grid-cols-1"
                    data-diferencia-row
                    data-reportado="{{ $cantidadReportada }}"
                >
                    <select name="detalles[{{ $i }}][product_id]" class="custom-select w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white">
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}" @selected(old("detalles.$i.product_id", $proyectado->product_id) == $product->id)>{{ $product->sku }} - {{ $product->name }}</option>
                        @endforeach
                    </select>

                    <input value="{{ $proyectado->genero_linea }}" disabled class="w-full rounded-md border bg-gray-50 px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-950 dark:text-white">
                    <input name="detalles[{{ $i }}][talla]" value="{{ old("detalles.$i.talla", $proyectado->talla) }}" placeholder="Talla" class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white">

                    <div class="flex items-center rounded-md border bg-gray-50 px-3 py-2 text-sm text-gray-600 dark:border-gray-800 dark:bg-gray-950 dark:text-gray-300" title="Reportado por el cortador, no editable aqui">
                        {{ $cantidadReportada }}
                    </div>

                    @if ($canEditCantidadCortada)
                        <input data-recibida-input name="detalles[{{ $i }}][cantidad_recibida]" value="{{ old("detalles.$i.cantidad_recibida", $cantidadReportada) }}" type="number" min="0" placeholder="Recibida" class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white">
                    @else
                        <input type="hidden" name="detalles[{{ $i }}][cantidad_recibida]" value="{{ old("detalles.$i.cantidad_recibida", $cantidadReportada) }}">
                        <div class="rounded-md border bg-gray-50 px-3 py-2 text-sm text-gray-600 dark:border-gray-800 dark:bg-gray-950 dark:text-gray-300">
                            {{ old("detalles.$i.cantidad_recibida", $cantidadReportada) }}
                        </div>
                    @endif

                    <div data-diferencia-output class="flex items-center rounded-md border px-3 py-2 text-sm font-semibold dark:border-gray-800">
                        0
                    </div>

                    <input name="detalles[{{ $i }}][cantidad_defectuosa]" value="{{ old("detalles.$i.cantidad_defectuosa", 0) }}" type="number" min="0" placeholder="Defectuosa" class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white">
                    <input name="detalles[{{ $i }}][observacion]" value="{{ old("detalles.$i.observacion") }}" placeholder="Observacion" class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white">
                </div>
            @endforeach
        </div>

        <x-admin::form.control-group class="mt-4">
            <x-admin::form.control-group.label>Observacion general</x-admin::form.control-group.label>
            <x-admin::form.control-group.control type="textarea" name="observacion" value="{{ old('observacion') }}" />
        </x-admin::form.control-group>
    </form>

    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                function pintarDiferencia(row) {
                    var reportado = parseInt(row.dataset.reportado || '0', 10);
                    var input = row.querySelector('[data-recibida-input]');
                    var output = row.querySelector('[data-diferencia-output]');

                    if (! output) {
                        return;
                    }

                    var recibido = input ? parseInt(input.value || '0', 10) : reportado;
                    var diferencia = recibido - reportado;

                    output.textContent = (diferencia > 0 ? '+' : '') + diferencia;

                    output.classList.remove(
                        'text-red-600', 'dark:text-red-400', 'border-red-300', 'dark:border-red-800',
                        'text-amber-600', 'dark:text-amber-400', 'border-amber-300', 'dark:border-amber-800',
                        'text-gray-500', 'dark:text-gray-400'
                    );

                    if (diferencia === 0) {
                        output.classList.add('text-gray-500', 'dark:text-gray-400');
                    } else if (diferencia < 0) {
                        output.classList.add('text-red-600', 'dark:text-red-400', 'border-red-300', 'dark:border-red-800');
                    } else {
                        output.classList.add('text-amber-600', 'dark:text-amber-400', 'border-amber-300', 'dark:border-amber-800');
                    }
                }

                document.querySelectorAll('[data-diferencia-row]').forEach(function (row) {
                    var input = row.querySelector('[data-recibida-input]');

                    if (input) {
                        input.addEventListener('input', function () {
                            pintarDiferencia(row);
                        });
                    }

                    pintarDiferencia(row);
                });

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
