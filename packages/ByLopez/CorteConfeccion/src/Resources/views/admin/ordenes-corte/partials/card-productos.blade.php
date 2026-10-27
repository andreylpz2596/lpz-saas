@php
    $totalSolicitado = (int) $detallesSolicitud->sum('cantidad_proyectada');

    // F4: una orden V2 no tiene cantidad proyectada (siempre 0): no se muestra ni se agregan productos a mano.
    $conProyeccion = $orden->tieneProyeccion();

    // Solo presentacion: una entrada por linea con colores y tallas unicas (orden de aparicion).
    // No altera $detallesSolicitud, que sigue alimentando la tabla tecnica.
    $consolidadoLineas = $detallesSolicitud
        ->groupBy(fn ($detalle) => trim((string) $detalle->genero_linea) ?: (trim((string) $detalle->producto_nombre) ?: 'p'.$detalle->product_id))
        ->map(fn ($grupo, $nombre) => [
            'nombre' => trim((string) $grupo->first()->genero_linea) ?: (trim((string) $grupo->first()->producto_nombre) ?: '-'),
            'colores' => $grupo->map(fn ($d) => $d->color ?: $orden->color)->filter()->unique()->values()->all(),
            'tallas' => $grupo->map(fn ($d) => $d->talla ?: '-')->unique()->values()->all(),
        ])
        ->values();
    $totalTallasConsolidadas = $consolidadoLineas->sum(fn ($linea) => count($linea['tallas']));
@endphp

<div class="rounded border bg-white dark:border-gray-800 dark:bg-gray-900">
    <div class="flex items-center justify-between gap-4 border-b p-4 dark:border-gray-800 max-sm:flex-wrap">
        <div>
            <p class="text-base font-semibold text-gray-800 dark:text-white">Productos a cortar</p>
            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $conProyeccion ? 'Solicitud de corte: productos, tallas y cantidades solicitadas para esta orden.' : 'Productos exactos a cortar (linea, color y talla). La cantidad real la reporta el cortador.' }}</p>
        </div>

        @unless ($conProyeccion)
            <span class="text-sm font-medium text-gray-600 dark:text-gray-300">{{ $consolidadoLineas->count() }} {{ $consolidadoLineas->count() === 1 ? 'línea' : 'líneas' }} · {{ $totalTallasConsolidadas }} {{ $totalTallasConsolidadas === 1 ? 'talla consolidada' : 'tallas consolidadas' }}</span>
        @endunless

        @if (($canEditOrder ?? false) && $conProyeccion)
            <x-admin::modal
                ref="agregarProductoOrdenModal"
                panel-class="max-w-[720px]"
                @toggle="$event.isActive && window.bylopezCenterModal($refs.agregarProductoOrdenModal, 720)"
                @open="window.bylopezCenterModal($refs.agregarProductoOrdenModal, 720)"
            >
                <x-slot:toggle>
                    <button type="button" class="secondary-button whitespace-nowrap">Agregar producto</button>
                </x-slot>

                <x-slot:header>
                    <p class="text-lg font-bold text-gray-800 dark:text-white">Agregar producto</p>
                </x-slot>

                <x-slot:content>
                    <form
                        id="agregar-producto-orden-form"
                        method="POST"
                        action="{{ route('admin.corte_confeccion.ordenes_corte.detalles.store', $orden->id) }}"
                    >
                        @csrf

                        <div class="grid grid-cols-2 gap-4 max-sm:grid-cols-1">
                            <x-admin::form.control-group class="col-span-2 max-sm:col-span-1">
                                <x-admin::form.control-group.label class="required">Producto</x-admin::form.control-group.label>
                                <x-corteconfeccion::record-search
                                    name="product_id"
                                    :search-url="route('admin.corte_confeccion.ordenes_corte.productos')"
                                    placeholder="Buscar producto..."
                                    rules="required"
                                    label="Producto"
                                />
                                <x-admin::form.control-group.error control-name="product_id" />
                            </x-admin::form.control-group>

                            <x-admin::form.control-group>
                                <x-admin::form.control-group.label>Genero / linea</x-admin::form.control-group.label>
                                <x-admin::form.control-group.control type="text" name="genero_linea" placeholder="Dama, Hombre, Nino..." />
                                <x-admin::form.control-group.error control-name="genero_linea" />
                            </x-admin::form.control-group>

                            <x-admin::form.control-group>
                                <x-admin::form.control-group.label>Talla</x-admin::form.control-group.label>
                                <x-admin::form.control-group.control type="text" name="talla" placeholder="S, M, L..." />
                                <x-admin::form.control-group.error control-name="talla" />
                            </x-admin::form.control-group>

                            <x-admin::form.control-group>
                                <x-admin::form.control-group.label class="required">Cantidad</x-admin::form.control-group.label>
                                <x-admin::form.control-group.control type="number" name="cantidad_proyectada" rules="required|min_value:1" min="1" placeholder="0" />
                                <x-admin::form.control-group.error control-name="cantidad_proyectada" />
                            </x-admin::form.control-group>

                            <x-admin::form.control-group>
                                <x-admin::form.control-group.label>Observacion</x-admin::form.control-group.label>
                                <x-admin::form.control-group.control type="text" name="observacion" placeholder="Opcional" />
                                <x-admin::form.control-group.error control-name="observacion" />
                            </x-admin::form.control-group>
                        </div>
                    </form>
                </x-slot>

                <x-slot:footer>
                    <div class="flex items-center gap-2.5">
                        <button type="button" class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800" @click="$refs.agregarProductoOrdenModal.close()">Cancelar</button>
                        <button type="submit" form="agregar-producto-orden-form" class="primary-button">Guardar producto</button>
                    </div>
                </x-slot>
            </x-admin::modal>
        @endif
    </div>

    <div class="p-4">
        @unless ($conProyeccion)
            {{-- Consolidado visual por linea (solo presentacion; la tabla de abajo usa los mismos $detallesSolicitud). --}}
            <div class="mb-3 space-y-2">
                @foreach ($consolidadoLineas as $linea)
                    <div class="flex items-center justify-between gap-4 rounded border px-3 py-2.5 dark:border-gray-800 max-md:flex-col max-md:items-start max-md:gap-2">
                        <div class="min-w-0 md:w-1/3">
                            <p class="break-words font-semibold text-gray-900 dark:text-white">{{ $linea['nombre'] }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ count($linea['colores']) }} {{ count($linea['colores']) === 1 ? 'color' : 'colores' }}
                                @if (count($linea['colores']))
                                    <span class="block break-words">{{ implode(' · ', $linea['colores']) }}</span>
                                @endif
                            </p>
                        </div>

                        <div class="min-w-0 flex-1">
                            <p class="text-xs text-gray-500 dark:text-gray-400">Tallas incluidas</p>
                            <div class="mt-1 flex flex-wrap gap-1.5">
                                @foreach ($linea['tallas'] as $talla)
                                    <span class="rounded border border-blue-200 bg-blue-50 px-2.5 py-0.5 text-sm font-medium text-blue-700 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-300">{{ $talla }}</span>
                                @endforeach
                            </div>
                        </div>

                        <span class="shrink-0 rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-600 dark:bg-gray-800 dark:text-gray-300">{{ count($linea['tallas']) }} {{ count($linea['tallas']) === 1 ? 'talla' : 'tallas' }}</span>
                    </div>
                @endforeach
            </div>

            <div id="detalle-variantes-barra" class="mb-3 flex items-center justify-center gap-3">
                <p id="detalle-variantes-titulo" class="mr-auto text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400" hidden>Detalle de variantes ({{ $detallesSolicitud->count() }})</p>
                <button
                    type="button"
                    id="detalle-variantes-boton"
                    class="secondary-button"
                    aria-expanded="false"
                    aria-controls="detalle-variantes"
                    onclick="var d = document.getElementById('detalle-variantes'); var abierto = d.hidden; d.hidden = ! abierto; this.setAttribute('aria-expanded', abierto ? 'true' : 'false'); this.textContent = abierto ? 'Ocultar detalle' : 'Ver detalle de variantes'; document.getElementById('detalle-variantes-titulo').hidden = ! abierto; document.getElementById('detalle-variantes-barra').classList.toggle('justify-center', ! abierto);"
                >Ver detalle de variantes</button>
            </div>
        @endunless

        <div id="detalle-variantes" @unless ($conProyeccion) hidden @endunless>
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <div class="relative w-full max-w-[320px]">
                <input type="text" class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900 dark:text-white" placeholder="Buscar" disabled>
                <span class="icon-search pointer-events-none absolute top-1.5 flex items-center text-2xl text-gray-400 ltr:right-3 rtl:left-3"></span>
            </div>

            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $detallesSolicitud->count() }} resultados</p>
        </div>

        <div class="overflow-x-auto rounded border dark:border-gray-800">
            <table class="w-full min-w-[900px] border-collapse text-left text-sm">
                <thead>
                    <tr class="border-b bg-gray-50 text-xs font-semibold uppercase text-gray-600 dark:border-gray-800 dark:bg-gray-950 dark:text-gray-300">
                        <th class="px-3 py-2">Producto / Variante</th>
                        <th class="px-3 py-2">Referencia / SKU</th>
                        <th class="px-3 py-2">Color</th>
                        <th class="px-3 py-2">Talla</th>
                        @if ($conProyeccion)
                            <th class="px-3 py-2 text-right">Cantidad</th>
                        @endif
                        <th class="px-3 py-2">Estado</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($detallesSolicitud as $detalle)
                        <tr class="border-b last:border-b-0 dark:border-gray-800">
                            <td class="px-3 py-3">
                                <p class="min-w-0 flex-1 break-words text-base font-semibold text-gray-900 dark:text-white">{{ $detalle->producto_nombre }}</p>
                                @if ($detalle->genero_linea)
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $detalle->genero_linea }}</p>
                                @endif
                            </td>
                            <td class="px-3 py-3 font-mono text-xs text-gray-600 dark:text-gray-300">{{ $detalle->producto_sku }}</td>
                            <td class="px-3 py-3 text-gray-600 dark:text-gray-300">{{ $detalle->color ?: ($orden->color ?: '-') }}</td>
                            <td class="px-3 py-3 text-gray-600 dark:text-gray-300">{{ $detalle->talla ?: '-' }}</td>
                            @if ($conProyeccion)
                                <td class="px-3 py-3 text-right font-semibold text-gray-800 dark:text-white">{{ number_format((int) $detalle->cantidad_proyectada, 0, ',', '.') }}</td>
                            @endif
                            <td class="px-3 py-3">
                                <span class="label-pending">{{ $conProyeccion ? 'Solicitado' : 'Por cortar' }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $conProyeccion ? 6 : 5 }}" class="px-3 py-8 text-center text-sm text-gray-500 dark:text-gray-400">Sin productos solicitados.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        </div>

        <div class="mt-3 flex flex-wrap items-center justify-between gap-3 text-sm">
            <span class="text-gray-500 dark:text-gray-400">Total productos: <strong class="text-gray-800 dark:text-white">{{ $detallesSolicitud->count() }}</strong></span>
            @if ($conProyeccion)
                <span class="text-gray-500 dark:text-gray-400">Cantidad total solicitada: <strong class="text-gray-800 dark:text-white">{{ number_format($totalSolicitado, 0, ',', '.') }}</strong></span>
            @endif
        </div>
    </div>
</div>
