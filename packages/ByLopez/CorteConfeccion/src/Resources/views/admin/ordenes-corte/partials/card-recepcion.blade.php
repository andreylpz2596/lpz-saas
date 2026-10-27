@php
    $listaParaRecibir = \ByLopez\CorteConfeccion\Support\OrdenCorteEstadoMachine::puedeTransicionar($orden->estado, 'recibido_bodega');
    $recepcion = $orden->recepcion;
@endphp

<div class="rounded border dark:border-gray-800">
    <div class="border-b p-4 dark:border-gray-800">
        <p class="text-base font-semibold text-gray-800 dark:text-white">Recepcion / validacion de Bodega</p>
        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">Confirma las cantidades fisicas recibidas.</p>
    </div>

    <div class="p-4">
        @if ($recepcion)
            {{-- Ya confirmada: solo lectura --}}
            <div class="mb-4 grid grid-cols-4 gap-3 max-md:grid-cols-2">
                @if ($orden->merma_unica)
                    {{-- F3: una sola merma total de la orden, sin sobrante que vuelva a rollos. --}}
                    <div class="rounded border p-3 dark:border-gray-800">
                        <p class="text-xs text-gray-500 dark:text-gray-400">Tela asignada</p>
                        <p class="font-semibold text-gray-800 dark:text-white">{{ number_format($orden->pesoBase(), 3, '.', '') }} kg</p>
                    </div>
                    <div class="rounded border p-3 dark:border-gray-800">
                        <p class="text-xs text-gray-500 dark:text-gray-400">Merma no reutilizable</p>
                        <p class="font-semibold text-gray-800 dark:text-white">{{ $orden->merma_total }} kg</p>
                    </div>
                    <div class="rounded border p-3 dark:border-gray-800">
                        <p class="text-xs text-gray-500 dark:text-gray-400">Peso aprovechado</p>
                        <p class="font-semibold text-gray-800 dark:text-white">{{ $orden->peso_consumido_total }} kg</p>
                    </div>
                @else
                    <div class="rounded border p-3 dark:border-gray-800">
                        <p class="text-xs text-gray-500 dark:text-gray-400">Peso sobrante</p>
                        <p class="font-semibold text-gray-800 dark:text-white">{{ $orden->peso_sobrante_total }} kg</p>
                    </div>
                    <div class="rounded border p-3 dark:border-gray-800">
                        <p class="text-xs text-gray-500 dark:text-gray-400">Merma</p>
                        <p class="font-semibold text-gray-800 dark:text-white">{{ $orden->merma_total }} kg</p>
                    </div>
                    <div class="rounded border p-3 dark:border-gray-800">
                        <p class="text-xs text-gray-500 dark:text-gray-400">Peso consumido</p>
                        <p class="font-semibold text-gray-800 dark:text-white">{{ $orden->peso_consumido_total }} kg</p>
                    </div>
                @endif
                <div class="rounded border p-3 dark:border-gray-800">
                    <p class="text-xs text-gray-500 dark:text-gray-400">Recibido por</p>
                    <p class="font-semibold text-gray-800 dark:text-white">{{ $recepcion->recibido_por }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $recepcion->created_at?->format('Y-m-d H:i') }}</p>
                </div>
            </div>

            @php
                $comparativo = $recepcion->detalles->map(function ($detalle) use ($orden) {
                    // orden_corte_detalle_id es la identidad real (F0). El
                    // match por product_id+talla es SOLO fallback para
                    // recepciones historicas creadas antes de F0, donde esa
                    // columna quedo NULL a proposito (no se reescribe
                    // historico) -- ver docs/ORDEN_CORTE_CREACION_V2_AUDITORIA_02.md §12.2.
                    $reportado = $detalle->orden_corte_detalle_id
                        ? $orden->detalles->firstWhere('id', $detalle->orden_corte_detalle_id)
                        : $orden->detalles->first(
                            fn ($d) => (int) $d->product_id === (int) $detalle->product_id
                                && trim((string) $d->talla) === trim((string) $detalle->talla)
                        );

                    $cantidadReportada = (int) ($reportado->cantidad_real_cortada ?? 0);
                    $cantidadRecibida = (int) $detalle->cantidad_recibida;

                    return [
                        'producto_nombre' => $detalle->producto_nombre,
                        'producto_sku' => $detalle->producto_sku,
                        'color' => $reportado->color ?? null,
                        'talla' => $detalle->talla,
                        'reportado' => $cantidadReportada,
                        'recibido' => $cantidadRecibida,
                        'diferencia' => $cantidadRecibida - $cantidadReportada,
                        'defectuosa' => (int) $detalle->cantidad_defectuosa,
                        'observacion' => $detalle->observacion,
                        'es_adicional' => (bool) ($reportado->es_adicional ?? false),
                    ];
                });

                // F4: una tarjeta por linea/color (nombre); cada fila conserva su SKU y talla.
                $gruposComparativo = $comparativo->groupBy(fn ($fila) => $fila['producto_nombre'] ?: $fila['producto_sku']);
            @endphp

            <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Cantidades fisicas por producto y talla</p>

            @foreach ($gruposComparativo as $sku => $filas)
                @php $totalReportado = (int) $filas->sum('reportado'); @endphp

                <details class="mb-3 rounded-lg border dark:border-gray-800" @if ($loop->first) open @endif>
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-3 p-4 hover:bg-gray-50 dark:hover:bg-gray-950 max-md:flex-wrap">
                        <span class="min-w-0 flex-1 break-words text-base font-semibold text-gray-900 dark:text-white">{{ $filas->first()['producto_nombre'] }}</span>
                        <span class="shrink-0 text-sm text-gray-500 dark:text-gray-400">{{ number_format($totalReportado, 0, ',', '.') }} reportadas</span>
                    </summary>

                    <div class="divide-y border-t dark:divide-gray-800 dark:border-gray-800">
                        @foreach ($filas as $fila)
                            @php
                                $claseDiferencia = match (true) {
                                    $fila['diferencia'] === 0 => 'border-gray-200 bg-gray-50 text-gray-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300',
                                    $fila['diferencia'] < 0 => 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300',
                                    default => 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300',
                                };
                            @endphp

                            <div class="p-4">
                                <div class="mb-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-gray-500 dark:text-gray-400">
                                    <span>SKU: <span class="font-medium text-gray-700 dark:text-gray-300">{{ $fila['producto_sku'] }}</span></span>
                                    @if ($fila['color'])
                                        <span>Color: <span class="font-medium text-gray-700 dark:text-gray-300">{{ $fila['color'] }}</span></span>
                                    @endif
                                    <span>Talla: <span class="font-medium text-gray-700 dark:text-gray-300">{{ $fila['talla'] ?: '—' }}</span></span>
                                    @if ($fila['es_adicional'])
                                        <span class="rounded bg-amber-200 px-1.5 py-0.5 text-[10px] font-bold uppercase text-amber-800 dark:bg-amber-800 dark:text-amber-100">Adicional</span>
                                    @endif
                                </div>

                                <div class="grid grid-cols-4 gap-4 max-md:grid-cols-2">
                                    <div>
                                        <p class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Reportado</p>
                                        <p class="mt-1 text-lg font-semibold text-gray-800 dark:text-white">{{ $fila['reportado'] }}</p>
                                    </div>
                                    <div>
                                        <p class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Recibido</p>
                                        <p class="mt-1 text-lg font-semibold text-gray-800 dark:text-white">{{ $fila['recibido'] }}</p>
                                    </div>
                                    <div>
                                        <p class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Diferencia</p>
                                        <span class="mt-1 inline-flex items-center rounded-full border px-3 py-1 text-sm font-semibold {{ $claseDiferencia }}">{{ $fila['diferencia'] > 0 ? '+'.$fila['diferencia'] : $fila['diferencia'] }}</span>
                                    </div>
                                    <div>
                                        <p class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Defectuosa</p>
                                        <p class="mt-1 text-lg font-semibold text-gray-800 dark:text-white">{{ $fila['defectuosa'] }}</p>
                                    </div>
                                </div>

                                @if ($fila['observacion'])
                                    <p class="mt-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Observacion</p>
                                    <p class="text-sm text-gray-700 dark:text-gray-300">{{ $fila['observacion'] }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </details>
            @endforeach
        @elseif (! $listaParaRecibir)
            <p class="text-sm text-gray-500 dark:text-gray-400">Disponible cuando el cortador reporte el resultado del corte.</p>
        @elseif (! $canConfirmarRecepcion)
            <p class="text-sm text-gray-500 dark:text-gray-400">Solo Bodega o Administrador pueden confirmar la recepcion.</p>
        @else
            {{-- Reportado y sin recepcion aun: formulario embebido --}}
            @php
                $gruposRecepcion = \ByLopez\CorteConfeccion\Support\AgrupadorDetallesCorte::agrupar($orden->detalles);
            @endphp

            <form method="POST" action="{{ route('admin.corte_confeccion.recepciones_corte.store', $orden->id) }}">
                @csrf

                @if ($errors->any())
                    <div class="mb-4 rounded border border-red-300 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                        <p class="font-semibold">No se pudo confirmar la recepcion. Revisa lo siguiente:</p>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <p class="mb-3 text-sm text-gray-600 dark:text-gray-300">Tela asignada a la orden (entregada al cortador): <strong>{{ number_format($orden->pesoBase(), 3, '.', '') }} kg</strong></p>

                <div class="mb-4 grid grid-cols-3 gap-4 max-md:grid-cols-1">
                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label class="required">Fecha recepcion</x-admin::form.control-group.label>
                        <x-admin::form.control-group.control
                            type="date"
                            name="fecha_recepcion"
                            value="{{ old('fecha_recepcion', now()->toDateString()) }}"
                        />
                        <x-admin::form.control-group.error control-name="fecha_recepcion" />
                    </x-admin::form.control-group>

                    {{-- F3: UN solo peso para toda la orden; nunca por rollo, color ni gramaje. --}}
                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label class="required">Merma / sobrante no reutilizable (kg)</x-admin::form.control-group.label>
                        <x-admin::form.control-group.control
                            type="number"
                            step="0.001"
                            name="merma_total"
                            value="{{ old('merma_total') }}"
                        />
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Peso total de tela que no puede volver al inventario de rollos. Registre el total de toda la orden, sin separarlo por color.</p>
                        <x-admin::form.control-group.error control-name="merma_total" />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label class="required">Recibido por</x-admin::form.control-group.label>
                        <select name="recibido_por" class="custom-select w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white">
                            <option value="">Seleccionar usuario</option>
                            @foreach ($usuariosRecepcion as $usuario)
                                <option value="{{ $usuario->name }}" @selected(old('recibido_por') === $usuario->name)>
                                    {{ $usuario->name }} ({{ $usuario->role->name ?? 'Admin' }})
                                </option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Solo usuarios con rol Bodega o Administrador.</p>
                        <x-admin::form.control-group.error control-name="recibido_por" />
                    </x-admin::form.control-group>
                </div>

                <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Cantidades fisicas por producto y talla</p>

                @foreach ($gruposRecepcion as $grupo)
                    @php
                        $grupoTieneError = collect($grupo['filas'])->keys()->contains(fn ($i) => $errors->has("detalles.$i.*"));
                    @endphp
                    <details class="mb-3 rounded-lg border dark:border-gray-800" @if ($loop->first || $grupoTieneError) open @endif>
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 p-4 hover:bg-gray-50 dark:hover:bg-gray-950 max-md:flex-wrap">
                            <span class="min-w-0 flex-1 break-words text-base font-semibold text-gray-900 dark:text-white">
                                {{ $grupo['genero_linea'] ? $grupo['genero_linea'].' · ' : '' }}{{ $grupo['producto_nombre'] }}
                            </span>
                            <span class="shrink-0 text-sm text-gray-500 dark:text-gray-400">{{ number_format($grupo['total_real'], 0, ',', '.') }} reportadas</span>
                        </summary>

                        <div class="divide-y border-t dark:divide-gray-800 dark:border-gray-800">
                            @foreach ($grupo['filas'] as $i => $detalle)
                                @php $cantidadReportada = (int) $detalle->cantidad_real_cortada; @endphp

                                <div class="p-4" data-diferencia-row data-reportado="{{ $cantidadReportada }}">
                                    <div class="mb-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-gray-500 dark:text-gray-400">
                                        <span>SKU: <span class="font-medium text-gray-700 dark:text-gray-300">{{ $detalle->producto_sku }}</span></span>
                                        @if ($detalle->color)
                                            <span>Color: <span class="font-medium text-gray-700 dark:text-gray-300">{{ $detalle->color }}</span></span>
                                        @endif
                                        <span>Talla: <span class="font-medium text-gray-700 dark:text-gray-300">{{ $detalle->talla ?: '—' }}</span></span>
                                        @if ($detalle->es_adicional)
                                            <span class="rounded bg-amber-200 px-1.5 py-0.5 text-[10px] font-bold uppercase text-amber-800 dark:bg-amber-800 dark:text-amber-100">Adicional</span>
                                        @endif
                                    </div>

                                    {{-- Identidad real de la fila (F0): el servidor deriva producto,
                                         SKU y talla desde este OrdenCorteDetalle -- nunca se envian ni
                                         se confian del cliente. Ver StoreRecepcionCorteRequest y
                                         RecepcionCorteService::create(). --}}
                                    <input type="hidden" name="detalles[{{ $i }}][orden_corte_detalle_id]" value="{{ $detalle->id }}">
                                    <input type="hidden" name="detalles[{{ $i }}][cantidad_defectuosa]" value="{{ old("detalles.$i.cantidad_defectuosa", 0) }}">
                                    <x-admin::form.control-group.error control-name="detalles.{{ $i }}.orden_corte_detalle_id" />
                                    <x-admin::form.control-group.error control-name="detalles.{{ $i }}.cantidad_defectuosa" />

                                    <div class="grid grid-cols-3 gap-4 max-md:grid-cols-1">
                                        <div>
                                            <p class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Reportado</p>
                                            <p class="mt-1 text-lg font-semibold text-gray-800 dark:text-white">{{ $cantidadReportada }}</p>
                                        </div>

                                        <div>
                                            <p class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Recibido</p>
                                            @if ($canEditCantidadCortada)
                                                <input data-recibida-input name="detalles[{{ $i }}][cantidad_recibida]" value="{{ old("detalles.$i.cantidad_recibida", $cantidadReportada) }}" type="number" min="0" class="mt-1 w-24 rounded-md border px-2 py-1.5 text-sm font-semibold dark:border-gray-800 dark:bg-gray-900 dark:text-white">
                                            @else
                                                <input type="hidden" name="detalles[{{ $i }}][cantidad_recibida]" value="{{ old("detalles.$i.cantidad_recibida", $cantidadReportada) }}">
                                                <p class="mt-1 text-lg font-semibold text-gray-800 dark:text-white">{{ $cantidadReportada }}</p>
                                            @endif
                                            <x-admin::form.control-group.error control-name="detalles.{{ $i }}.cantidad_recibida" />
                                        </div>

                                        <div>
                                            <p class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">Diferencia</p>
                                            <span data-diferencia-output class="mt-1 inline-flex items-center rounded-full border border-gray-200 bg-gray-50 px-3 py-1 text-sm font-semibold text-gray-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">0</span>
                                        </div>
                                    </div>

                                    <div class="mt-3">
                                        <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">
                                            Observacion <span class="font-normal text-gray-400">(requerida solo si hay diferencia)</span>
                                        </label>
                                        <input name="detalles[{{ $i }}][observacion]" value="{{ old("detalles.$i.observacion") }}" placeholder="Ej. faltante por merma en bodega" class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white">
                                        <x-admin::form.control-group.error control-name="detalles.{{ $i }}.observacion" />
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </details>
                @endforeach

                <div
                    data-diferencia-warning
                    class="mt-3 hidden rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
                >
                    ⚠ Se detectaron diferencias entre lo reportado y lo recibido. Agrega una observacion en cada producto con diferencia.
                </div>

                <x-admin::form.control-group class="mt-4">
                    <x-admin::form.control-group.label>Observacion general</x-admin::form.control-group.label>
                    <x-admin::form.control-group.control type="textarea" name="observacion" value="{{ old('observacion') }}" />
                </x-admin::form.control-group>

                <button
                    type="submit"
                    class="primary-button mt-4"
                    data-confirm="Vas a confirmar la recepcion de bodega. Esta accion mueve la orden a 'Recibido en bodega' y no se puede deshacer. ¿Confirmas?"
                    data-confirm-title="Confirmar recepcion"
                >
                    Confirmar recepcion
                </button>
            </form>

            @push('scripts')
                <script>
                    document.addEventListener('DOMContentLoaded', function () {
                        var warning = document.querySelector('[data-diferencia-warning]');

                        var CLASES_NEUTRO = ['border-gray-200', 'bg-gray-50', 'text-gray-600', 'dark:border-gray-700', 'dark:bg-gray-900', 'dark:text-gray-300'];
                        var CLASES_FALTANTE = ['border-red-200', 'bg-red-50', 'text-red-700', 'dark:border-red-900', 'dark:bg-red-950', 'dark:text-red-300'];
                        var CLASES_SOBRANTE = ['border-amber-200', 'bg-amber-50', 'text-amber-700', 'dark:border-amber-900', 'dark:bg-amber-950', 'dark:text-amber-300'];

                        function actualizarFila(row) {
                            var reportado = parseInt(row.dataset.reportado || '0', 10);
                            var input = row.querySelector('[data-recibida-input]');
                            var output = row.querySelector('[data-diferencia-output]');

                            if (!output) return;

                            var recibido = input ? parseInt(input.value || '0', 10) : reportado;
                            var diferencia = recibido - reportado;

                            output.textContent = (diferencia > 0 ? '+' : '') + diferencia;
                            output.classList.remove.apply(output.classList, CLASES_NEUTRO.concat(CLASES_FALTANTE, CLASES_SOBRANTE));

                            if (diferencia === 0) {
                                output.classList.add.apply(output.classList, CLASES_NEUTRO);
                            } else if (diferencia < 0) {
                                output.classList.add.apply(output.classList, CLASES_FALTANTE);
                            } else {
                                output.classList.add.apply(output.classList, CLASES_SOBRANTE);
                            }

                            return diferencia !== 0;
                        }

                        function evaluarTodas() {
                            var hayDiferencias = false;

                            document.querySelectorAll('[data-diferencia-row]').forEach(function (row) {
                                if (actualizarFila(row)) {
                                    hayDiferencias = true;
                                }
                            });

                            if (warning) {
                                warning.classList.toggle('hidden', !hayDiferencias);
                            }
                        }

                        document.querySelectorAll('[data-recibida-input]').forEach(function (input) {
                            input.addEventListener('input', evaluarTodas);
                        });

                        evaluarTodas();
                    });
                </script>
            @endpush
        @endif
    </div>
</div>
