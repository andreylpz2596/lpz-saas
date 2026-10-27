{{--
    Formulario pequeño de UNA referencia comercial (crear o editar), separado a
    proposito del formulario completo de edicion del proveedor.

    No es una ruta ni una regla de validacion nueva: reenvia al MISMO endpoint
    admin.corte_confeccion.proveedores_tela.update que ya usa el formulario
    grande, con los demas campos del proveedor sin cambios (ocultos) y un unico
    elemento en referencias[]. El servicio solo modifica lo que llega en ese
    arreglo: nada de esto puede tocar los rollos fisicos ya comprados.
--}}
@php
    $esEdicion = $referencia !== null;

    // F1-B: identidad real del color = attribute_options.id (atributo Bagisto
    // 'color'). Las referencias historicas sin vincular arrancan en el
    // placeholder y muestran su snapshot solo como aviso: al guardar deben
    // elegir una opcion real, nunca texto libre ni sentinel.
    $colorOptionIdActual = old('referencias.0.color_option_id', $referencia?->color_option_id);
    $colorSeleccionInicial = $colorOptionIdActual ?: '';
    $referenciaHistoricaSinVincular = $referencia && ! $referencia->color_option_id;
@endphp

{{-- Mascara monetaria visual compartida (data-moneda): el valor que se envia no cambia. --}}
@include('corteconfeccion::admin.partials.moneda')

@include('corteconfeccion::admin.partials.estilos')

<x-admin::layouts>
    <x-slot:title>{{ $esEdicion ? 'Editar referencia' : 'Agregar referencia' }} — {{ $proveedor->nombre }}</x-slot:title>

    <div class="bylopez-scope">
    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <p class="text-xl font-bold text-gray-800 dark:text-white">
            {{ $esEdicion ? 'Editar referencia' : 'Agregar referencia' }}
        </p>

        <a
            href="{{ route('admin.corte_confeccion.proveedores_tela.show', $proveedor->id) }}"
            class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800"
        >
            Volver a {{ $proveedor->nombre }}
        </a>
    </div>

    <form
        method="POST"
        action="{{ route('admin.corte_confeccion.proveedores_tela.update', $proveedor->id) }}"
        class="mt-4 max-w-2xl rounded border p-4 dark:border-gray-800"
    >
        @csrf
        @method('PUT')

        {{-- Vuelve a la ficha, no al formulario grande de edicion. --}}
        <input type="hidden" name="redirect_to" value="show">

        {{-- Resto del proveedor sin cambios: este formulario solo toca una referencia. --}}
        <input type="hidden" name="nombre" value="{{ $proveedor->nombre }}">
        <input type="hidden" name="nit" value="{{ $proveedor->nit }}">
        <input type="hidden" name="telefono" value="{{ $proveedor->telefono }}">
        <input type="hidden" name="contacto" value="{{ $proveedor->contacto }}">
        <input type="hidden" name="direccion" value="{{ $proveedor->direccion }}">
        <input type="hidden" name="estado" value="{{ $proveedor->estado }}">
        <input type="hidden" name="observacion" value="{{ $proveedor->observacion }}">

        @foreach ($proveedor->tiposTela as $tipoTela)
            <input type="hidden" name="tipo_tela_ids[]" value="{{ $tipoTela->id }}">
        @endforeach

        @if ($esEdicion)
            <input type="hidden" name="referencias[0][id]" value="{{ $referencia->id }}">
        @endif

        <div class="grid grid-cols-2 gap-4 max-md:grid-cols-1">
            <x-admin::form.control-group>
                <x-admin::form.control-group.label class="required">Tipo de tela</x-admin::form.control-group.label>
                <select
                    name="referencias[0][tipo_tela_id]"
                    class="custom-select w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                >
                    @foreach ($proveedor->tiposTela as $tipoTela)
                        <option
                            value="{{ $tipoTela->id }}"
                            @selected(old('referencias.0.tipo_tela_id', $referencia?->tipo_tela_id) == $tipoTela->id)
                        >
                            {{ $tipoTela->admin_name }}
                        </option>
                    @endforeach
                </select>
                <x-admin::form.control-group.error control-name="referencias.0.tipo_tela_id" />
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Solo se muestran los tipos de tela ya asociados a este proveedor. Para agregar otro tipo, usa "Editar proveedor".
                </p>
            </x-admin::form.control-group>

            <x-admin::form.control-group>
                <x-admin::form.control-group.label class="required">Color</x-admin::form.control-group.label>

                <div class="flex items-center gap-2">
                    <span
                        class="h-6 w-6 shrink-0 rounded-full border dark:border-gray-700"
                        data-color-swatch
                        style="background-color: {{ $referencia?->colorOption?->swatch_value ?: '#e5e7eb' }}"
                    ></span>

                    <select
                        name="referencias[0][color_option_id]"
                        class="custom-select w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                        data-color-select
                    >
                        <option value="" @selected($colorSeleccionInicial === '')>Seleccionar color del catálogo</option>
                        @foreach ($coloresTela as $color)
                            <option
                                value="{{ $color->id }}"
                                data-swatch="{{ $color->swatch_value }}"
                                @selected((string) $colorSeleccionInicial === (string) $color->id)
                            >
                                {{ $color->admin_name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                @if ($referenciaHistoricaSinVincular)
                    <div class="mt-2 rounded border border-amber-200 bg-amber-50 p-2 text-xs text-amber-700 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300">
                        <p class="font-semibold">Color sin vincular al catálogo</p>

                        @if (filled($referencia->color))
                            <p class="mt-1">Color histórico: {{ $referencia->color }}</p>
                        @endif
                    </div>
                @endif

                <x-admin::form.control-group.error control-name="referencias.0.color_option_id" />
            </x-admin::form.control-group>

            <x-admin::form.control-group>
                <x-admin::form.control-group.label class="required">Referencia</x-admin::form.control-group.label>
                <x-admin::form.control-group.control
                    type="text"
                    name="referencias[0][referencia]"
                    value="{{ old('referencias.0.referencia', $referencia?->referencia) }}"
                    label="Referencia"
                    placeholder="Codigo de referencia"
                />
                <x-admin::form.control-group.error control-name="referencias.0.referencia" />
            </x-admin::form.control-group>

            <x-admin::form.control-group>
                <x-admin::form.control-group.label class="required">Gramaje</x-admin::form.control-group.label>
                <x-admin::form.control-group.control
                    type="text"
                    name="referencias[0][gramaje]"
                    value="{{ old('referencias.0.gramaje', $referencia?->gramaje) }}"
                    label="Gramaje"
                    placeholder="Ej. 175"
                />
                <x-admin::form.control-group.error control-name="referencias.0.gramaje" />
            </x-admin::form.control-group>

            <x-admin::form.control-group>
                <x-admin::form.control-group.label>Precio referencia (por kilo)</x-admin::form.control-group.label>
                <x-admin::form.control-group.control
                    type="text"
                    name="referencias[0][valor_kilo_referencia]"
                    value="{{ old('referencias.0.valor_kilo_referencia', $referencia?->valor_kilo_referencia) }}"
                    label="Precio referencia"
                    placeholder="Opcional"
                    data-moneda
                />
                <x-admin::form.control-group.error control-name="referencias.0.valor_kilo_referencia" />
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Valor sugerido del catalogo. Cada compra de tela conserva su propio valor real pagado.
                </p>
            </x-admin::form.control-group>

            <x-admin::form.control-group>
                <x-admin::form.control-group.label class="required">Estado</x-admin::form.control-group.label>
                <select
                    name="referencias[0][estado]"
                    class="custom-select w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                >
                    <option value="activo" @selected(old('referencias.0.estado', $referencia?->estado ?? 'activo') === 'activo')>Activo</option>
                    <option value="inactivo" @selected(old('referencias.0.estado', $referencia?->estado) === 'inactivo')>Inactivo</option>
                </select>
                <x-admin::form.control-group.error control-name="referencias.0.estado" />
            </x-admin::form.control-group>
        </div>

        <div class="mt-4 flex justify-end gap-2.5">
            <a
                href="{{ route('admin.corte_confeccion.proveedores_tela.show', $proveedor->id) }}"
                class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800"
            >
                Cancelar
            </a>

            <button class="primary-button">Guardar referencia</button>
        </div>
    </form>
    </div>

    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                var select = document.querySelector('[data-color-select]');
                var swatch = document.querySelector('[data-color-swatch]');

                if (! select) {
                    return;
                }

                function actualizar() {
                    var opcion = select.options[select.selectedIndex];

                    if (swatch) {
                        swatch.style.backgroundColor = (opcion && opcion.dataset.swatch) ? opcion.dataset.swatch : '#e5e7eb';
                    }
                }

                select.addEventListener('change', actualizar);
                actualizar();
            });
        </script>
    @endpush
</x-admin::layouts>
