@php
    $selectedTipoTelaIds = collect(old('tipo_tela_ids', $proveedor?->tiposTela?->pluck('id')->all() ?: ($proveedor?->tipo ? [$proveedor->tipo] : [])))
        ->map(fn ($id) => (int) $id)
        ->values()
        ->all();

    $referenciasIniciales = old('referencias');

    if ($referenciasIniciales === null && $proveedor) {
        $referenciasIniciales = $proveedor->referenciasTela
            ->map(fn ($referencia) => [
                'id' => $referencia->id,
                'tipo_tela_id' => $referencia->tipo_tela_id,
                'tipo_tela_nombre' => $referencia->tipoTela?->admin_name,
                'color' => $referencia->color,
                'referencia' => $referencia->referencia,
                'gramaje' => $referencia->gramaje,
                'valor_kilo_referencia' => $referencia->valor_kilo_referencia,
                'estado' => $referencia->estado,
                'usada' => $referencia->rollos->isNotEmpty(),
                '_delete' => false,
            ])
            ->values()
            ->all();
    }

    $referenciasIniciales = $referenciasIniciales ?: [];

    $estadoProveedor = strtolower(trim((string) old('estado', $proveedor?->estado ?? 'activo')));
    $estadoProveedor = in_array($estadoProveedor, ['activo', 'inactivo'], true) ? $estadoProveedor : 'activo';

    $tiposTelaCatalogo = $tiposTela
        ->map(fn ($tipoTela) => [
            'id' => (int) $tipoTela->id,
            'nombre' => $tipoTela->admin_name,
        ])
        ->values()
        ->all();
@endphp

<div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
    <p class="text-xl font-bold text-gray-800 dark:text-white">{{ $proveedor ? 'Editar proveedor de tela' : 'Crear proveedor de tela' }}</p>

    <button class="primary-button">Guardar</button>
</div>

<div class="mt-4 grid grid-cols-3 gap-4 max-lg:grid-cols-2 max-md:grid-cols-1">
    <x-admin::form.control-group>
        <x-admin::form.control-group.label class="required">Nombre</x-admin::form.control-group.label>
        <x-admin::form.control-group.control type="text" name="nombre" value="{{ old('nombre', $proveedor?->nombre) }}" />
        <x-admin::form.control-group.error control-name="nombre" />
    </x-admin::form.control-group>

    @foreach ([
        'nit' => 'NIT',
        'telefono' => 'Telefono',
        'contacto' => 'Contacto',
        'direccion' => 'Direccion',
    ] as $field => $label)
        <x-admin::form.control-group>
            <x-admin::form.control-group.label class="{{ in_array($field, ['nit', 'telefono'], true) ? 'required' : '' }}">{{ $label }}</x-admin::form.control-group.label>
            <x-admin::form.control-group.control type="text" name="{{ $field }}" value="{{ old($field, $proveedor?->{$field}) }}" />
            <x-admin::form.control-group.error control-name="{{ $field }}" />
        </x-admin::form.control-group>
    @endforeach

    <x-admin::form.control-group>
        <x-admin::form.control-group.label class="required">Estado</x-admin::form.control-group.label>
        <x-admin::form.control-group.control type="select" name="estado">
            @foreach (['activo' => 'Activo', 'inactivo' => 'Inactivo'] as $value => $label)
                <option value="{{ $value }}" @selected($estadoProveedor === $value)>{{ $label }}</option>
            @endforeach
        </x-admin::form.control-group.control>
        <x-admin::form.control-group.error control-name="estado" />
    </x-admin::form.control-group>

    <x-admin::form.control-group class="col-span-3 max-lg:col-span-2 max-md:col-span-1">
        <x-admin::form.control-group.label class="required">Tipos de tela</x-admin::form.control-group.label>

        <div class="grid grid-cols-4 gap-3 rounded border p-4 dark:border-gray-800 max-xl:grid-cols-3 max-lg:grid-cols-2 max-sm:grid-cols-1" id="proveedor-tela-tipos">
            @forelse ($tiposTela as $tipoTela)
                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <input
                        type="checkbox"
                        class="rounded border-gray-300"
                        name="tipo_tela_ids[]"
                        value="{{ $tipoTela->id }}"
                        data-tipo-checkbox
                        @checked(in_array((int) $tipoTela->id, $selectedTipoTelaIds, true))
                    >
                    <span>{{ $tipoTela->admin_name }}</span>
                </label>
            @empty
                <p class="text-sm text-gray-600 dark:text-gray-300">No hay opciones registradas para el atributo tipo_tela.</p>
            @endforelse
        </div>

        <x-admin::form.control-group.error control-name="tipo_tela_ids" />
        <x-admin::form.control-group.error control-name="tipo_tela_ids.0" />
    </x-admin::form.control-group>

    <x-admin::form.control-group class="col-span-3 max-lg:col-span-2 max-md:col-span-1">
        <x-admin::form.control-group.label>Observacion</x-admin::form.control-group.label>
        <x-admin::form.control-group.control type="textarea" name="observacion" value="{{ old('observacion', $proveedor?->observacion) }}" />
        <x-admin::form.control-group.error control-name="observacion" />
    </x-admin::form.control-group>
</div>

<div class="mt-6" id="proveedor-tela-referencias">
    <div class="mb-3 flex items-center justify-between gap-4 max-sm:flex-wrap">
        <div>
            <p class="text-base font-semibold text-gray-800 dark:text-white">Referencias por tipo de tela</p>
            <p class="text-sm text-gray-600 dark:text-gray-300">El valor kilo referencia es sugerido; la compra guarda su propio valor kilo real.</p>
        </div>
    </div>

    <x-admin::form.control-group.error control-name="referencias" />

    <div data-referencias-hidden></div>
    <div class="grid gap-4" data-referencias-cards></div>
</div>

@pushOnce('scripts')
    <script>
        window.addEventListener('load', function () {
            window.setTimeout(function () {
        var tipos = @json($tiposTelaCatalogo);
        var referencias = normalizarReferencias(@json($referenciasIniciales));
        var cardsRoot = document.querySelector('[data-referencias-cards]');
        var hiddenRoot = document.querySelector('[data-referencias-hidden]');
        var checkboxRoot = document.getElementById('proveedor-tela-tipos');

        if (! cardsRoot || ! hiddenRoot || ! checkboxRoot) {
            return;
        }

            function normalizarBoolean(value) {
                return value === true || value === 1 || value === '1';
            }

            function valorONulo(value) {
                return value === null || value === undefined ? '' : value;
            }

            function normalizarEstado(value) {
                value = String(valorONulo(value)).toLowerCase().trim();

                return value === 'inactivo' ? 'inactivo' : 'activo';
            }

            function normalizarReferencias(items) {
                if (! Array.isArray(items)) {
                    return [];
                }

                return items.map(function (referencia) {
                    return {
                        id: valorONulo(referencia.id) || null,
                        tipo_tela_id: valorONulo(referencia.tipo_tela_id),
                        tipo_tela_nombre: valorONulo(referencia.tipo_tela_nombre),
                        color: valorONulo(referencia.color),
                        referencia: valorONulo(referencia.referencia),
                        gramaje: valorONulo(referencia.gramaje),
                        valor_kilo_referencia: valorONulo(referencia.valor_kilo_referencia),
                        estado: normalizarEstado(referencia.estado),
                        usada: normalizarBoolean(referencia.usada),
                        _delete: normalizarBoolean(referencia._delete),
                    };
                });
            }

            function closestWithAttribute(element, attribute) {
                while (element && element !== cardsRoot) {
                    if (element.hasAttribute && element.hasAttribute(attribute)) {
                        return element;
                    }

                    element = element.parentNode;
                }

                return null;
            }

            function escapeAttr(value) {
                return String(value === null || value === undefined ? '' : value).replace(/[&<>"']/g, function (char) {
                    return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char];
                });
            }

            function selectedTipoIds() {
                var selected = [];
                var checkboxes = checkboxRoot.querySelectorAll('[data-tipo-checkbox]:checked');

                for (var i = 0; i < checkboxes.length; i++) {
                    selected.push(parseInt(checkboxes[i].value, 10));
                }

                return selected;
            }

            function tipoNombre(tipoId) {
                for (var i = 0; i < tipos.length; i++) {
                    if (parseInt(tipos[i].id, 10) === parseInt(tipoId, 10)) {
                        return tipos[i].nombre;
                    }
                }

                return '';
            }

            function referenciasVisibles(tipoId) {
                var visibles = [];

                for (var i = 0; i < referencias.length; i++) {
                    if (parseInt(referencias[i].tipo_tela_id, 10) === parseInt(tipoId, 10) && ! referencias[i]._delete) {
                        visibles.push({
                            index: i,
                            data: referencias[i],
                        });
                    }
                }

                return visibles;
            }

            function renderHiddenFields() {
                var html = '';

                for (var i = 0; i < referencias.length; i++) {
                    var referencia = referencias[i];

                    if (referencia.id) {
                        html += '<input type="hidden" name="referencias[' + i + '][id]" value="' + escapeAttr(referencia.id) + '">';
                    }

                    html += '<input type="hidden" name="referencias[' + i + '][tipo_tela_id]" value="' + escapeAttr(referencia.tipo_tela_id) + '">';
                    html += '<input type="hidden" name="referencias[' + i + '][color]" value="' + escapeAttr(referencia.color) + '">';
                    html += '<input type="hidden" name="referencias[' + i + '][referencia]" value="' + escapeAttr(referencia.referencia) + '">';
                    html += '<input type="hidden" name="referencias[' + i + '][gramaje]" value="' + escapeAttr(referencia.gramaje) + '">';
                    html += '<input type="hidden" name="referencias[' + i + '][valor_kilo_referencia]" value="' + escapeAttr(referencia.valor_kilo_referencia) + '">';
                    html += '<input type="hidden" name="referencias[' + i + '][estado]" value="' + escapeAttr(normalizarEstado(referencia.estado)) + '">';
                    html += '<input type="hidden" name="referencias[' + i + '][_delete]" value="' + (referencia._delete ? 1 : 0) + '">';
                }

                hiddenRoot.innerHTML = html;
            }

            function rowHtml(item) {
                var referencia = item.data;
                var disabled = referencia.usada ? ' disabled title="Referencia usada en compras"' : '';
                var estado = normalizarEstado(referencia.estado);

                return ''
                    + '<tr class="border-b last:border-b-0 dark:border-gray-800">'
                    + '<td class="px-3 py-2"><input required class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white" value="' + escapeAttr(referencia.color) + '" data-ref-index="' + item.index + '" data-ref-field="color"></td>'
                    + '<td class="px-3 py-2"><input required class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white" value="' + escapeAttr(referencia.referencia) + '" data-ref-index="' + item.index + '" data-ref-field="referencia"></td>'
                    + '<td class="px-3 py-2"><input required class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white" value="' + escapeAttr(referencia.gramaje) + '" data-ref-index="' + item.index + '" data-ref-field="gramaje"></td>'
                    + '<td class="px-3 py-2"><input type="number" step="0.01" min="0" class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white" value="' + escapeAttr(referencia.valor_kilo_referencia) + '" data-ref-index="' + item.index + '" data-ref-field="valor_kilo_referencia"></td>'
                    + '<td class="px-3 py-2">'
                    + '<select required class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white" data-ref-index="' + item.index + '" data-ref-field="estado">'
                    + '<option value="activo"' + (estado === 'activo' ? ' selected' : '') + '>Activo</option>'
                    + '<option value="inactivo"' + (estado === 'inactivo' ? ' selected' : '') + '>Inactivo</option>'
                    + '</select>'
                    + '</td>'
                    + '<td class="px-3 py-2 text-right"><button type="button" class="transparent-button" data-delete-ref="' + item.index + '"' + disabled + '>Quitar</button></td>'
                    + '</tr>';
            }

            function renderCards() {
                var selected = selectedTipoIds();
                var html = '';

                for (var i = 0; i < selected.length; i++) {
                    var tipoId = selected[i];
                    var visibles = referenciasVisibles(tipoId);
                    var rows = '';

                    for (var j = 0; j < visibles.length; j++) {
                        rows += rowHtml(visibles[j]);
                    }

                    if (! rows) {
                        rows = '<tr><td colspan="6" class="px-3 py-4 text-sm text-gray-600 dark:text-gray-300">Sin referencias registradas.</td></tr>';
                    }

                    html += ''
                        + '<div class="rounded border p-4 dark:border-gray-800">'
                        + '<div class="mb-4 flex items-center justify-between gap-4 max-sm:flex-wrap">'
                        + '<p class="text-base font-semibold text-gray-800 dark:text-white">' + escapeAttr(tipoNombre(tipoId)) + '</p>'
                        + '<button type="button" class="secondary-button" data-add-ref="' + tipoId + '">Agregar referencia</button>'
                        + '</div>'
                        + '<div class="overflow-x-auto">'
                        + '<table class="w-full min-w-[900px] border-collapse text-left text-sm">'
                        + '<thead>'
                        + '<tr class="border-b bg-gray-50 text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">'
                        + '<th class="px-3 py-2">Color</th>'
                        + '<th class="px-3 py-2">Referencia</th>'
                        + '<th class="px-3 py-2">Gramaje</th>'
                        + '<th class="px-3 py-2">Valor kilo referencia</th>'
                        + '<th class="px-3 py-2">Estado</th>'
                        + '<th class="px-3 py-2"></th>'
                        + '</tr>'
                        + '</thead>'
                        + '<tbody>' + rows + '</tbody>'
                        + '</table>'
                        + '</div>'
                        + '</div>';
                }

                cardsRoot.innerHTML = html || '<div class="rounded border p-4 text-sm text-gray-600 dark:border-gray-800 dark:text-gray-300">Seleccione al menos un tipo de tela.</div>';
                renderHiddenFields();
            }

            function agregarReferencia(tipoId) {
                referencias.push({
                    tipo_tela_id: parseInt(tipoId, 10),
                    color: '',
                    referencia: '',
                    gramaje: '',
                    valor_kilo_referencia: '',
                    estado: 'activo',
                    usada: false,
                    _delete: false,
                });

                renderCards();
            }

            function eliminarReferencia(index) {
                if (! referencias[index]) {
                    return;
                }

                if (referencias[index].usada) {
                    alert(@json(trans('corteconfeccion::app.errors.referencia-usada')));
                    return;
                }

                if (referencias[index].id) {
                    referencias[index]._delete = true;
                } else {
                    referencias.splice(index, 1);
                }

                renderCards();
            }

            checkboxRoot.addEventListener('change', renderCards);

            cardsRoot.addEventListener('input', function (event) {
                var index = event.target.getAttribute('data-ref-index');
                var field = event.target.getAttribute('data-ref-field');

                if (index === null || ! field) {
                    return;
                }

                referencias[index][field] = event.target.value;
                renderHiddenFields();
            });

            cardsRoot.addEventListener('change', function (event) {
                var index = event.target.getAttribute('data-ref-index');
                var field = event.target.getAttribute('data-ref-field');

                if (index === null || ! field) {
                    return;
                }

                referencias[index][field] = event.target.value;
                renderHiddenFields();
            });

            cardsRoot.addEventListener('click', function (event) {
                var addButton = closestWithAttribute(event.target, 'data-add-ref');
                var deleteButton = closestWithAttribute(event.target, 'data-delete-ref');

                if (addButton) {
                    event.preventDefault();
                    agregarReferencia(addButton.getAttribute('data-add-ref'));
                    return;
                }

                if (deleteButton) {
                    event.preventDefault();
                    eliminarReferencia(parseInt(deleteButton.getAttribute('data-delete-ref'), 10));
                }
            });

            renderCards();
            }, 0);
        });
    </script>
@endPushOnce
