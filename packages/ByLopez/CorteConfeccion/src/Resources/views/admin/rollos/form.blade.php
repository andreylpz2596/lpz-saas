<nav class="mb-2 flex items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400">
    <a href="{{ route('admin.corte_confeccion.rollos.index') }}" class="hover:text-gray-800 hover:underline dark:hover:text-white">Corte y Confeccion</a>
    <span>/</span>
    <a href="{{ route('admin.corte_confeccion.rollos.index') }}" class="hover:text-gray-800 hover:underline dark:hover:text-white">Rollos</a>
    <span>/</span>
    <span class="text-gray-800 dark:text-white">{{ $rollo ? 'Editar rollo' : 'Crear rollo' }}</span>
</nav>

<div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
    <div class="flex items-center gap-3">
        <a href="{{ route('admin.corte_confeccion.rollos.index') }}" class="secondary-button" title="Volver a Rollos">
            <span class="icon-arrow-left text-lg"></span>
            Volver a Rollos
        </a>

        <p class="text-xl font-bold text-gray-800 dark:text-white">{{ $rollo ? 'Editar rollo' : 'Crear rollo' }}</p>
    </div>

    <button class="primary-button">Guardar</button>
</div>

@php
    $colorOptionIdActual = old('color_option_id', $rollo?->color_option_id);
    $colorSeleccionInicial = $colorOptionIdActual ?: '';
    $colorHistoricoSinVincular = $rollo && ! $rollo->color_option_id;

    $colorBloqueado = $rollo && (
        $rollo->proveedor_tela_referencia_id
        || $rollo->estado === \ByLopez\CorteConfeccion\Enums\EstadoRollo::ReservadoEnCorte->value
        || $rollo->ordenesCorteRollos()->exists()
        || $rollo->ordenesCorte()->exists()
    );

    $motivoColorBloqueado = match (true) {
        ! $colorBloqueado => null,
        (bool) $rollo?->proveedor_tela_referencia_id => 'El color de este rollo proviene de su referencia de proveedor. Para corregirlo, edita la referencia.',
        default => 'No se puede modificar el color de un rollo reservado o que ya participo en una orden de corte.',
    };
@endphp

<div class="mt-4 grid grid-cols-2 gap-4 max-md:grid-cols-1">
    @foreach ([
        'codigo' => 'Codigo',
        'proveedor' => 'Proveedor',
        'tipo_tela' => 'Tipo de tela',
        'peso_inicial' => 'Peso inicial',
        'peso_disponible' => 'Peso disponible',
        'costo_total' => 'Costo total',
        'fecha_compra' => 'Fecha compra',
    ] as $field => $label)
        <x-admin::form.control-group>
            <x-admin::form.control-group.label class="{{ in_array($field, ['codigo', 'proveedor', 'tipo_tela', 'peso_inicial', 'costo_total'], true) ? 'required' : '' }}">{{ $label }}</x-admin::form.control-group.label>
            <x-admin::form.control-group.control
                type="{{ $field === 'fecha_compra' ? 'date' : (str_starts_with($field, 'peso') || $field === 'costo_total' ? 'number' : 'text') }}"
                name="{{ $field }}"
                step="0.001"
                value="{{ old($field, $rollo?->{$field}) }}"
            />
            <x-admin::form.control-group.error control-name="{{ $field }}" />
        </x-admin::form.control-group>

        @if ($field === 'proveedor')
            <x-admin::form.control-group>
                <x-admin::form.control-group.label class="required">Color</x-admin::form.control-group.label>

                <div class="flex items-center gap-2">
                    <span
                        class="h-6 w-6 shrink-0 rounded-full border dark:border-gray-700"
                        data-color-swatch
                        style="background-color: {{ $rollo?->colorOption?->swatch_value ?: '#e5e7eb' }}"
                    ></span>

                    <select
                        name="color_option_id"
                        class="custom-select w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                        data-color-select
                        @disabled($colorBloqueado)
                        @required(! $colorBloqueado)
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

                @if ($colorHistoricoSinVincular)
                    <div class="mt-1 text-xs text-amber-600 dark:text-amber-400">
                        <p>Color sin vincular al catalogo</p>
                        @if ($rollo?->color)
                            <p>Color historico: {{ $rollo->color }}</p>
                        @endif
                    </div>
                @endif

                @if ($colorBloqueado)
                    <p class="mt-1 text-xs text-amber-600 dark:text-amber-400">{{ $motivoColorBloqueado }}</p>
                @endif

                <x-admin::form.control-group.error control-name="color_option_id" />
                <x-admin::form.control-group.error control-name="color" />
            </x-admin::form.control-group>
        @endif
    @endforeach

    <x-admin::form.control-group class="col-span-2 max-md:col-span-1">
        <x-admin::form.control-group.label>Observacion</x-admin::form.control-group.label>
        <x-admin::form.control-group.control type="textarea" name="observacion" value="{{ old('observacion', $rollo?->observacion) }}" />
        <x-admin::form.control-group.error control-name="observacion" />
    </x-admin::form.control-group>
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
