@php
    $proveedoresCatalogo = $proveedores
        ->mapWithKeys(fn ($proveedor) => [
            (string) $proveedor->id => [
                'tipos' => $proveedor->tiposTela
                    ->map(fn ($tipoTela) => [
                        'id' => (int) $tipoTela->id,
                        'nombre' => $tipoTela->admin_name,
                    ])
                    ->values()
                    ->all(),
                'referencias' => $proveedor->referenciasTela
                    ->map(fn ($referencia) => [
                        'id' => (int) $referencia->id,
                        'tipo_tela_id' => (int) $referencia->tipo_tela_id,
                        'tipo_tela' => $referencia->tipoTela?->admin_name,
                        'color' => $referencia->color,
                        'referencia' => $referencia->referencia,
                        'gramaje' => $referencia->gramaje,
                        'valor_kilo_referencia' => $referencia->valor_kilo_referencia,
                        'estado' => $referencia->estado,
                    ])
                    ->values()
                    ->all(),
            ],
        ])
        ->all();

    $proveedorSeleccionadoId = (string) old('proveedor_id', $compra?->proveedor_id ?? '');
    $rollosIniciales = old('rollos');
    $referenciaLabel = function ($referencia, $rollo = null) {
        if (! $referencia && $rollo) {
            $label = trim(implode(' - ', array_filter([
                $rollo->color,
                $rollo->referencia,
                $rollo->gramaje ? $rollo->gramaje.'gr' : null,
            ])));

            return $label ? $label.' (historico)' : '';
        }

        if (! $referencia) {
            return '';
        }

        $valor = $referencia->valor_kilo_referencia === null
            ? 'sin valor ref.'
            : '$'.number_format((float) $referencia->valor_kilo_referencia, 0, ',', '.').'/kg';

        return "{$referencia->color} - {$referencia->referencia} - {$referencia->gramaje}gr - {$valor}";
    };

    if ($rollosIniciales === null && $compra) {
        $rollosIniciales = $compra->rollos->map(fn ($rollo) => [
            'id' => $rollo->id,
            'codigo' => $rollo->codigo,
            'tipo_tela_id' => $rollo->referenciaTela?->tipo_tela_id ?? $rollo->tipo_tela_id,
            'referencia_tela_id' => $rollo->proveedor_tela_referencia_id,
            'referencia_label' => $referenciaLabel($rollo->referenciaTela, $rollo),
            'historico' => blank($rollo->proveedor_tela_referencia_id),
            'bloqueado' => $rollo->ordenesCorte->isNotEmpty(),
            'tipo_tela' => $rollo->referenciaTela?->tipoTela?->admin_name ?? $rollo->tipoTela?->admin_name ?? $rollo->tipo_tela,
            'color' => $rollo->color,
            'referencia' => $rollo->referencia,
            'gramaje' => $rollo->gramaje,
            'peso_inicial' => $rollo->peso_inicial,
            'valor_kilo' => $rollo->valor_kilo,
            'observacion' => $rollo->observacion,
        ])->values()->all();
    }

    $rollosIniciales = $rollosIniciales ?: [[
        'codigo' => '',
        'tipo_tela_id' => '',
        'referencia_tela_id' => '',
        'referencia_label' => '',
        'historico' => false,
        'bloqueado' => false,
        'tipo_tela' => '',
        'color' => '',
        'referencia' => '',
        'gramaje' => '',
        'peso_inicial' => '',
        'valor_kilo' => '',
        'observacion' => '',
    ]];
@endphp

<div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
    <div>
        <p class="text-xl font-bold text-gray-800 dark:text-white">{{ $compra ? 'Editar compra de tela' : 'Crear compra de tela' }}</p>
        @if ($compra)
            <p class="text-sm text-gray-600 dark:text-gray-300">{{ $compra->codigo }} - {{ $compra->estado }}</p>
        @endif
    </div>

    <button class="primary-button">Guardar compra</button>
</div>

<div class="mt-4 rounded border p-4 dark:border-gray-800">
    <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">Encabezado</p>

    <div class="grid grid-cols-4 gap-4 max-lg:grid-cols-2 max-md:grid-cols-1">
        <x-admin::form.control-group>
            <x-admin::form.control-group.label class="required">Numero compra</x-admin::form.control-group.label>
            <x-admin::form.control-group.control type="text" name="codigo" value="{{ old('codigo', $compra?->codigo ?? ($codigoSugerido ?? '')) }}" />
            <x-admin::form.control-group.error control-name="codigo" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label class="required">Proveedor</x-admin::form.control-group.label>
            <select
                name="proveedor_id"
                class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white"
                data-proveedor
            >
                <option value="">Seleccionar</option>
                @foreach ($proveedores as $proveedor)
                    <option value="{{ $proveedor->id }}" @selected($proveedorSeleccionadoId === (string) $proveedor->id)>{{ $proveedor->nombre }}{{ $proveedor->nit ? ' - '.$proveedor->nit : '' }}</option>
                @endforeach
            </select>
            <x-admin::form.control-group.error control-name="proveedor_id" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label>Numero factura</x-admin::form.control-group.label>
            <x-admin::form.control-group.control type="text" name="numero_factura" value="{{ old('numero_factura', $compra?->numero_factura) }}" />
            <x-admin::form.control-group.error control-name="numero_factura" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label class="required">Fecha compra</x-admin::form.control-group.label>
            <x-admin::form.control-group.control type="date" name="fecha_compra" value="{{ old('fecha_compra', $compra?->fecha_compra?->format('Y-m-d')) }}" />
            <x-admin::form.control-group.error control-name="fecha_compra" />
        </x-admin::form.control-group>

        <x-admin::form.control-group class="col-span-4 max-lg:col-span-2 max-md:col-span-1">
            <x-admin::form.control-group.label>Observacion</x-admin::form.control-group.label>
            <x-admin::form.control-group.control type="textarea" name="observacion" value="{{ old('observacion', $compra?->observacion) }}" />
            <x-admin::form.control-group.error control-name="observacion" />
        </x-admin::form.control-group>
    </div>
</div>

<div class="mt-4 rounded border p-4 dark:border-gray-800" id="compra-tela-form">
    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <p class="text-base font-semibold text-gray-800 dark:text-white">Rollos comprados</p>
        <button type="button" class="secondary-button" data-add-rollo>Agregar rollo</button>
    </div>

    <x-admin::form.control-group.error control-name="rollos" />

    <div class="mt-4 overflow-x-auto">
        <table class="w-full min-w-[1250px] border-collapse text-left text-sm">
            <thead>
                <tr class="border-b bg-gray-50 text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
                    <th class="px-3 py-2">Tipo de tela</th>
                    <th class="px-3 py-2">Referencia / Color</th>
                    <th class="px-3 py-2">Gramaje</th>
                    <th class="px-3 py-2">Peso inicial</th>
                    <th class="px-3 py-2">Valor kilo</th>
                    <th class="px-3 py-2">Subtotal</th>
                    <th class="px-3 py-2">Observacion</th>
                    <th class="px-3 py-2">Quitar</th>
                </tr>
            </thead>

            <tbody data-rollos-body></tbody>
        </table>
    </div>

    <div class="mt-4 grid grid-cols-5 gap-4 max-lg:grid-cols-2 max-md:grid-cols-1">
        <div class="rounded border p-4 dark:border-gray-800">
            <p class="text-sm text-gray-600 dark:text-gray-300">Cantidad rollos</p>
            <p class="font-semibold text-gray-800 dark:text-white" data-total-rollos>0</p>
        </div>

        <div class="rounded border p-4 dark:border-gray-800">
            <p class="text-sm text-gray-600 dark:text-gray-300">Peso total</p>
            <p class="font-semibold text-gray-800 dark:text-white"><span data-total-peso>0.000</span> kg</p>
        </div>

        <div class="rounded border p-4 dark:border-gray-800">
            <p class="text-sm text-gray-600 dark:text-gray-300">Subtotal</p>
            <p class="font-semibold text-gray-800 dark:text-white" data-subtotal>0.00</p>
        </div>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label>Descuento</x-admin::form.control-group.label>
            <x-admin::form.control-group.control type="number" name="descuento" step="0.01" value="{{ old('descuento', $compra?->descuento ?? 0) }}" data-descuento />
            <x-admin::form.control-group.error control-name="descuento" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label>Impuesto</x-admin::form.control-group.label>
            <x-admin::form.control-group.control type="number" name="impuesto" step="0.01" value="{{ old('impuesto', $compra?->impuesto ?? 0) }}" data-impuesto />
            <x-admin::form.control-group.error control-name="impuesto" />
        </x-admin::form.control-group>
    </div>

    <div class="mt-4 flex justify-end">
        <div class="w-full max-w-sm rounded border p-4 dark:border-gray-800">
            <p class="text-sm text-gray-600 dark:text-gray-300">Total factura</p>
            <p class="text-2xl font-bold text-gray-800 dark:text-white" data-total-factura>0.00</p>
        </div>
    </div>
</div>

@pushOnce('scripts')
    <script>
        window.addEventListener('load', function () {
            window.setTimeout(function () {
                var catalogo = @json($proveedoresCatalogo);
                var referenciasUrl = @json(route('admin.corte_confeccion.compras_tela.referencias'));
                var proveedorSeleccionadoInicial = @json($proveedorSeleccionadoId);
                var root = document.getElementById('compra-tela-form');

                if (! root) {
                    return;
                }

                var body = root.querySelector('[data-rollos-body]');
                var addButton = root.querySelector('[data-add-rollo]');
                var descuento = root.querySelector('[data-descuento]');
                var impuesto = root.querySelector('[data-impuesto]');
                var proveedor = document.querySelector('[data-proveedor]');
                var rollos = normalizarRollos(@json($rollosIniciales));
                var busquedas = {};
                var busquedasTokens = {};

                if (proveedor && proveedorSeleccionadoInicial && ! proveedor.value) {
                    proveedor.value = proveedorSeleccionadoInicial;
                }

                function valor(value) {
                    return value === null || value === undefined ? '' : value;
                }

                function normalizarBoolean(value) {
                    return value === true || value === 1 || value === '1';
                }

                function referenciaLabel(referencia) {
                    var valorReferencia = referencia.valor_kilo_referencia === null || referencia.valor_kilo_referencia === undefined || referencia.valor_kilo_referencia === ''
                        ? 'sin valor ref.'
                        : '$' + Number(referencia.valor_kilo_referencia).toLocaleString('es-CO') + '/kg';

                    return referencia.color + ' - ' + referencia.referencia + ' - ' + referencia.gramaje + 'gr - ' + valorReferencia;
                }

                function normalizarRollos(items) {
                    if (! Array.isArray(items)) {
                        return [emptyRollo()];
                    }

                    return items.map(function (rollo) {
                        var normalizado = {
                            id: valor(rollo.id),
                            codigo: valor(rollo.codigo),
                            tipo_tela_id: valor(rollo.tipo_tela_id),
                            referencia_tela_id: valor(rollo.referencia_tela_id),
                            referencia_label: valor(rollo.referencia_label),
                            historico: normalizarBoolean(rollo.historico),
                            bloqueado: normalizarBoolean(rollo.bloqueado),
                            tipo_tela: valor(rollo.tipo_tela),
                            color: valor(rollo.color),
                            referencia: valor(rollo.referencia),
                            gramaje: valor(rollo.gramaje),
                            peso_inicial: valor(rollo.peso_inicial),
                            valor_kilo: valor(rollo.valor_kilo),
                            observacion: valor(rollo.observacion),
                        };

                        normalizado.referencia_label = normalizado.referencia_label
                            ? normalizado.referencia_label
                            : normalizado.color && normalizado.referencia
                            ? normalizado.color + ' - ' + normalizado.referencia + ' - ' + normalizado.gramaje + 'gr'
                            : '';

                        return normalizado;
                    });
                }

                function numberValue(value) {
                    return parseFloat(value || 0) || 0;
                }

                function money(value) {
                    return numberValue(value).toFixed(2);
                }

                function weight(value) {
                    return numberValue(value).toFixed(3);
                }

                function escapeAttr(value) {
                    return String(value === null || value === undefined ? '' : value).replace(/[&<>"']/g, function (char) {
                        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char];
                    });
                }

                function catalogoProveedor() {
                    return catalogo[String(proveedor.value)] || { tipos: [], referencias: [] };
                }

                function tiposProveedor() {
                    return catalogoProveedor().tipos;
                }

                function referenciasActivas() {
                    return catalogoProveedor().referencias.filter(function (referencia) {
                        return referencia.estado === 'activo'
                            || rollos.some(function (rollo) {
                                return String(rollo.referencia_tela_id) === String(referencia.id);
                            });
                    });
                }

                function tipoNombre(tipoTelaId) {
                    var tipos = tiposProveedor();

                    for (var i = 0; i < tipos.length; i++) {
                        if (String(tipos[i].id) === String(tipoTelaId)) {
                            return tipos[i].nombre;
                        }
                    }

                    return '';
                }

                function referenciasPorTipo(tipoTelaId, search) {
                    var referencias = referenciasActivas();
                    var term = String(search || '').toLowerCase();

                    return referencias.filter(function (referencia) {
                        var matchesTipo = String(referencia.tipo_tela_id) === String(tipoTelaId);
                        var label = referenciaLabel(referencia).toLowerCase();

                        return matchesTipo && (! term || label.indexOf(term) !== -1);
                    });
                }

                function referenciaPorId(referenciaId) {
                    var referencias = catalogoProveedor().referencias;

                    for (var i = 0; i < referencias.length; i++) {
                        if (String(referencias[i].id) === String(referenciaId)) {
                            return referencias[i];
                        }
                    }

                    return null;
                }

                function hydrateRollo(rollo) {
                    if (! rollo.tipo_tela_id && rollo.tipo_tela) {
                        var tipos = tiposProveedor();

                        for (var i = 0; i < tipos.length; i++) {
                            if (tipos[i].nombre === rollo.tipo_tela || String(tipos[i].id) === String(rollo.tipo_tela)) {
                                rollo.tipo_tela_id = tipos[i].id;
                                break;
                            }
                        }
                    }

                    if (rollo.tipo_tela_id && ! rollo.tipo_tela) {
                        rollo.tipo_tela = tipoNombre(rollo.tipo_tela_id);
                    }

                    if (rollo.referencia_tela_id && (! rollo.referencia || ! rollo.gramaje || ! rollo.color)) {
                        aplicarReferencia(rollo, rollo.referencia_tela_id);
                    }
                }

                function limpiarReferencia(rollo) {
                    rollo.referencia_tela_id = '';
                    rollo.color = '';
                    rollo.referencia = '';
                    rollo.referencia_label = '';
                    rollo.gramaje = '';
                    rollo.valor_kilo = '';
                }

                function aplicarReferencia(rollo, referenciaId) {
                    var referencia = referenciaPorId(referenciaId);

                    rollo.referencia_tela_id = referenciaId || '';

                    if (! referencia) {
                        limpiarReferencia(rollo);
                        return;
                    }

                    rollo.tipo_tela_id = referencia.tipo_tela_id;
                    rollo.tipo_tela = referencia.tipo_tela || tipoNombre(referencia.tipo_tela_id);
                    rollo.color = referencia.color;
                    rollo.referencia = referencia.referencia;
                    rollo.referencia_label = referenciaLabel(referencia);
                    rollo.gramaje = referencia.gramaje;
                    rollo.valor_kilo = referencia.valor_kilo_referencia === null || referencia.valor_kilo_referencia === undefined
                        ? ''
                        : referencia.valor_kilo_referencia;
                }

                function emptyRollo() {
                    return {
                        id: '',
                        codigo: '',
                        tipo_tela_id: '',
                        referencia_tela_id: '',
                        tipo_tela: '',
                        referencia_label: '',
                        historico: false,
                        bloqueado: false,
                        color: '',
                        referencia: '',
                        gramaje: '',
                        peso_inicial: '',
                        valor_kilo: '',
                        observacion: '',
                    };
                }

                function updateTotals() {
                    var subtotal = 0;
                    var pesoTotal = 0;

                    for (var i = 0; i < rollos.length; i++) {
                        subtotal += numberValue(rollos[i].peso_inicial) * numberValue(rollos[i].valor_kilo);
                        pesoTotal += numberValue(rollos[i].peso_inicial);
                    }

                    root.querySelector('[data-total-rollos]').textContent = rollos.length;
                    root.querySelector('[data-total-peso]').textContent = weight(pesoTotal);
                    root.querySelector('[data-subtotal]').textContent = money(subtotal);
                    root.querySelector('[data-total-factura]').textContent = money(Math.max(subtotal - numberValue(descuento.value) + numberValue(impuesto.value), 0));
                }

                function selectOptions(options, selectedValue, emptyLabel) {
                    var html = '<option value="">' + escapeAttr(emptyLabel) + '</option>';

                    for (var i = 0; i < options.length; i++) {
                        html += '<option value="' + escapeAttr(options[i].value) + '"' + (String(selectedValue) === String(options[i].value) ? ' selected' : '') + '>' + escapeAttr(options[i].label) + '</option>';
                    }

                    return html;
                }

                function sugerenciasIniciales(rollo) {
                    return referenciasPorTipo(rollo.tipo_tela_id, rollo.referencia_label).slice(0, 20);
                }

                function optionsDatalist(index, rollo) {
                    var sugerencias = busquedas[index] || sugerenciasIniciales(rollo);
                    var html = '';

                    for (var i = 0; i < sugerencias.length; i++) {
                        html += '<option value="' + escapeAttr(sugerencias[i].label || referenciaLabel(sugerencias[i])) + '"></option>';
                    }

                    return html;
                }

                function actualizarDatalist(index, sugerencias) {
                    busquedas[index] = sugerencias;

                    var datalist = document.getElementById('referencias-rollo-' + index);

                    if (! datalist) {
                        return;
                    }

                    datalist.innerHTML = optionsDatalist(index, rollos[index]);
                }

                function buscarReferencias(index, search) {
                    if (! proveedor.value || ! rollos[index] || ! rollos[index].tipo_tela_id) {
                        actualizarDatalist(index, []);
                        return;
                    }

                    var token = Date.now() + '-' + Math.random();
                    busquedasTokens[index] = token;

                    var params = new URLSearchParams({
                        proveedor_tela_id: proveedor.value,
                        tipo_tela_id: rollos[index].tipo_tela_id,
                        search: search || '',
                    });

                    fetch(referenciasUrl + '?' + params.toString(), {
                        headers: {
                            'Accept': 'application/json',
                        },
                    })
                        .then(function (response) {
                            return response.ok ? response.json() : [];
                        })
                        .then(function (items) {
                            if (busquedasTokens[index] !== token || ! rollos[index]) {
                                return;
                            }

                            actualizarDatalist(index, items);
                            aplicarSiCoincide(index, false);
                        })
                        .catch(function () {
                            if (busquedasTokens[index] !== token || ! rollos[index]) {
                                return;
                            }

                            var locales = referenciasPorTipo(rollos[index].tipo_tela_id, search).map(function (referencia) {
                                return Object.assign({}, referencia, { label: referenciaLabel(referencia) });
                            });

                            actualizarDatalist(index, locales);
                            aplicarSiCoincide(index, false);
                        });
                }

                function aplicarSiCoincide(index, limpiarSiNoCoincide) {
                    var rollo = rollos[index];
                    if (! rollo) {
                        return;
                    }

                    var sugerencias = busquedas[index] || [];

                    for (var i = 0; i < sugerencias.length; i++) {
                        if (String(sugerencias[i].label) === String(rollo.referencia_label)) {
                            if (! referenciaPorId(sugerencias[i].id)) {
                                catalogoProveedor().referencias.push({
                                    id: sugerencias[i].id,
                                    tipo_tela_id: rollo.tipo_tela_id,
                                    tipo_tela: tipoNombre(rollo.tipo_tela_id),
                                    color: sugerencias[i].color,
                                    referencia: sugerencias[i].referencia,
                                    gramaje: sugerencias[i].gramaje,
                                    valor_kilo_referencia: sugerencias[i].valor_kilo_referencia,
                                    estado: 'activo',
                                });
                            }

                            aplicarReferencia(rollo, sugerencias[i].id);
                            render();
                            return;
                        }
                    }

                    if (limpiarSiNoCoincide && rollo.referencia_tela_id) {
                        limpiarReferencia(rollo);
                        rollo.referencia_label = '';
                        render();
                    }
                }

                function render() {
                    var proveedorSeleccionado = !! proveedor.value;
                    var html = '';

                    for (var i = 0; i < rollos.length; i++) {
                        hydrateRollo(rollos[i]);

                        var rollo = rollos[i];
                        var tipoOptions = tiposProveedor().map(function (tipo) {
                            return { value: tipo.id, label: tipo.nombre };
                        });
                        var tipoExisteEnCatalogo = tipoOptions.some(function (tipo) {
                            return String(tipo.value) === String(rollo.tipo_tela_id);
                        });
                        var bloqueado = normalizarBoolean(rollo.bloqueado);

                        if (rollo.tipo_tela_id && ! tipoExisteEnCatalogo) {
                            tipoOptions.push({
                                value: rollo.tipo_tela_id,
                                label: rollo.tipo_tela || ('Tipo #' + rollo.tipo_tela_id),
                            });
                        }

                        var referenciaDisabled = ! proveedorSeleccionado || ! rollo.tipo_tela_id || bloqueado;
                        var tipoDisabled = ! proveedorSeleccionado || bloqueado;
                        var bloqueoNota = bloqueado ? '<p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Rollo usado en ordenes de corte.</p>' : '';
                        var historicoNota = rollo.historico ? '<p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Dato historico sin referencia vinculada.</p>' : '';

                        html += ''
                            + '<tr class="border-b last:border-b-0 dark:border-gray-800">'
                            + '<td class="px-3 py-2">'
                            + (rollo.id ? '<input type="hidden" name="rollos[' + i + '][id]" value="' + escapeAttr(rollo.id) + '">' : '')
                            + '<input type="hidden" name="rollos[' + i + '][codigo]" value="' + escapeAttr(rollo.codigo) + '">'
                            + '<input type="hidden" name="rollos[' + i + '][tipo_tela]" value="' + escapeAttr(rollo.tipo_tela) + '">'
                            + (bloqueado ? '<input type="hidden" name="rollos[' + i + '][tipo_tela_id]" value="' + escapeAttr(rollo.tipo_tela_id) + '">' : '')
                            + '<select class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white" name="rollos[' + i + '][tipo_tela_id]" data-field="tipo_tela_id" data-index="' + i + '"' + (tipoDisabled ? ' disabled' : '') + '>'
                            + selectOptions(tipoOptions, rollo.tipo_tela_id, proveedorSeleccionado ? 'Seleccionar' : 'Seleccione proveedor')
                            + '</select>'
                            + bloqueoNota
                            + '</td>'
                            + '<td class="px-3 py-2">'
                            + '<input type="hidden" name="rollos[' + i + '][referencia_tela_id]" value="' + escapeAttr(rollo.referencia_tela_id) + '">'
                            + '<input type="hidden" name="rollos[' + i + '][color]" value="' + escapeAttr(rollo.color) + '">'
                            + '<input type="hidden" name="rollos[' + i + '][referencia]" value="' + escapeAttr(rollo.referencia) + '">'
                            + '<input list="referencias-rollo-' + i + '" class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white" value="' + escapeAttr(rollo.referencia_label) + '" data-field="referencia_search" data-index="' + i + '" placeholder="' + (referenciaDisabled ? 'Seleccione tipo de tela' : 'Buscar color, referencia o gramaje') + '"' + (referenciaDisabled ? ' disabled' : '') + ' autocomplete="off">'
                            + '<datalist id="referencias-rollo-' + i + '">' + optionsDatalist(i, rollo) + '</datalist>'
                            + historicoNota
                            + '</td>'
                            + '<td class="px-3 py-2"><input readonly class="w-full rounded-md border bg-gray-50 px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-950 dark:text-white" name="rollos[' + i + '][gramaje]" value="' + escapeAttr(rollo.gramaje) + '"></td>'
                            + '<td class="px-3 py-2"><input type="number" step="0.001" min="0" class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white" name="rollos[' + i + '][peso_inicial]" value="' + escapeAttr(rollo.peso_inicial) + '" data-field="peso_inicial" data-index="' + i + '"' + (bloqueado ? ' readonly' : '') + '></td>'
                            + '<td class="px-3 py-2"><input type="number" step="0.01" min="0" class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white" name="rollos[' + i + '][valor_kilo]" value="' + escapeAttr(rollo.valor_kilo) + '" data-field="valor_kilo" data-index="' + i + '"' + (bloqueado ? ' readonly' : '') + '></td>'
                            + '<td class="px-3 py-2 font-semibold text-gray-800 dark:text-white" data-row-subtotal="' + i + '">' + money(numberValue(rollo.peso_inicial) * numberValue(rollo.valor_kilo)) + '</td>'
                            + '<td class="px-3 py-2"><input class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white" name="rollos[' + i + '][observacion]" value="' + escapeAttr(rollo.observacion) + '" data-field="observacion" data-index="' + i + '"></td>'
                            + '<td class="px-3 py-2 text-right"><button type="button" class="transparent-button" data-remove-rollo="' + i + '"' + (bloqueado ? ' disabled title="Rollo usado en ordenes de corte"' : '') + '>Quitar</button></td>'
                            + '</tr>';
                    }

                    body.innerHTML = html;
                    updateTotals();
                }

                body.addEventListener('focusin', function (event) {
                    if (event.target.getAttribute('data-field') === 'referencia_search') {
                        buscarReferencias(event.target.getAttribute('data-index'), event.target.value);
                    }
                });

                body.addEventListener('input', function (event) {
                    var field = event.target.getAttribute('data-field');
                    var index = event.target.getAttribute('data-index');

                    if (field === null || index === null) {
                        return;
                    }

                    if (rollos[index] && rollos[index].bloqueado && field !== 'observacion') {
                        return;
                    }

                    if (field === 'referencia_search') {
                        rollos[index].referencia_label = event.target.value;
                        buscarReferencias(index, event.target.value);
                        return;
                    }

                    rollos[index][field] = event.target.value;

                    if (field === 'peso_inicial' || field === 'valor_kilo') {
                        var rowSubtotal = body.querySelector('[data-row-subtotal="' + index + '"]');

                        if (rowSubtotal) {
                            rowSubtotal.textContent = money(numberValue(rollos[index].peso_inicial) * numberValue(rollos[index].valor_kilo));
                        }

                        updateTotals();
                    }
                });

                body.addEventListener('change', function (event) {
                    var field = event.target.getAttribute('data-field');
                    var index = event.target.getAttribute('data-index');

                    if (field === null || index === null) {
                        return;
                    }

                    if (rollos[index] && rollos[index].bloqueado && field !== 'observacion') {
                        return;
                    }

                    if (field === 'tipo_tela_id') {
                        rollos[index].tipo_tela_id = event.target.value;
                        rollos[index].tipo_tela = tipoNombre(event.target.value);
                        limpiarReferencia(rollos[index]);
                        busquedas[index] = [];
                        render();
                        return;
                    }

                    if (field === 'referencia_search') {
                        aplicarSiCoincide(index, true);
                    }
                });

                body.addEventListener('click', function (event) {
                    var index = event.target.getAttribute('data-remove-rollo');

                    if (index === null) {
                        return;
                    }

                    if (rollos[index] && rollos[index].bloqueado) {
                        alert('Este rollo ya tiene ordenes de corte y no puede quitarse desde la compra.');
                        return;
                    }

                    rollos.splice(index, 1);
                    busquedas = {};
                    busquedasTokens = {};

                    if (! rollos.length) {
                        rollos.push(emptyRollo());
                    }

                    render();
                });

                addButton.addEventListener('click', function () {
                    rollos.push(emptyRollo());
                    render();
                });

                proveedor.addEventListener('change', function () {
                    rollos = [emptyRollo()];
                    busquedas = {};
                    busquedasTokens = {};
                    render();
                });

                descuento.addEventListener('input', updateTotals);
                impuesto.addEventListener('input', updateTotals);

                render();
            }, 0);
        });
    </script>
@endPushOnce
