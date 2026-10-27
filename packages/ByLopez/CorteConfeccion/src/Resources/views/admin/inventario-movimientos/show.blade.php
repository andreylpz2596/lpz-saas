{{--
    Mismos dos hallazgos ya documentados en ordenes-confeccion/show.blade.php:
    un <style> plano en el body es descartado por el layout de admin, y las
    clases propias de este build de Tailwind (important: ".bylopez-scope")
    solo aplican dentro de un ancestro con esa clase literal. Por eso: sin
    <style>, solo utilities, y todo envuelto en .bylopez-scope.

    Esta pantalla es exclusivamente de lectura: el movimiento es un registro
    historico ya creado por InventarioMovimientoService al recibir la
    entrega; aqui no hay ningun formulario que lo modifique.
--}}
@include('corteconfeccion::admin.partials.estilos')

<x-admin::layouts>
    <x-slot:title>Movimiento de inventario #{{ $movimiento->id }}</x-slot>

    @php
        $tipoInventarioMovimiento = \ByLopez\CorteConfeccion\Enums\TipoInventarioMovimiento::class;
        $tipo = $tipoInventarioMovimiento::tryFrom($movimiento->tipo_movimiento);
    @endphp

    <div class="bylopez-scope">
        <div class="mb-2 flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
            <span>Corte y Confeccion</span>
            <i class="icon-arrow-right text-sm"></i>
            <a href="{{ route('admin.corte_confeccion.inventario_movimientos.index') }}" class="hover:text-gray-800 hover:underline dark:hover:text-white">Movimientos de inventario</a>
            <i class="icon-arrow-right text-sm"></i>
            <span class="font-medium text-gray-700 dark:text-gray-300">#{{ $movimiento->id }}</span>
        </div>

        <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
            <div class="flex items-start justify-between gap-4 max-sm:flex-wrap">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2.5">
                        <p class="text-xl font-bold text-gray-800 dark:text-white">Movimiento #{{ $movimiento->id }}</p>
                        <p class="label-completed">{{ $tipo?->label() ?: $movimiento->tipo_movimiento }}</p>
                    </div>

                    <div class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-sm text-gray-600 dark:text-gray-300">
                        <span>Fecha: <span class="font-medium text-gray-800 dark:text-white">{{ $movimiento->created_at?->format('Y-m-d H:i') ?: '-' }}</span></span>
                        <span>Usuario: <span class="font-medium text-gray-800 dark:text-white">{{ $movimiento->nombre_usuario_snapshot ?: ($movimiento->admin?->name ?: '-') }}</span></span>
                    </div>
                </div>

                <a href="{{ route('admin.corte_confeccion.inventario_movimientos.index') }}" class="secondary-button whitespace-nowrap">Volver al listado</a>
            </div>
        </div>

        {{-- Efecto sobre stock: los tres numeros lado a lado, igual que se
        pidio auditar (anterior / entrada / nuevo), sin nada editable. --}}
        <div class="box-shadow mt-3 overflow-hidden rounded bg-white dark:bg-gray-900">
            <div class="grid grid-cols-1 sm:grid-cols-3">
                <div class="flex items-center gap-3 border-b px-4 py-3 sm:border-b-0 sm:border-r dark:border-gray-800">
                    <i class="icon-product text-2xl text-gray-400 dark:text-gray-500"></i>
                    <div class="min-w-0">
                        <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Stock anterior</p>
                        <p class="text-lg font-semibold text-gray-800 dark:text-white">{{ $movimiento->cantidad_anterior }}</p>
                    </div>
                </div>

                <div class="flex items-center gap-3 border-b px-4 py-3 sm:border-b-0 sm:border-r dark:border-gray-800">
                    <i class="icon-processing text-2xl text-gray-400 dark:text-gray-500"></i>
                    <div class="min-w-0">
                        <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Movimiento</p>
                        <p class="text-lg font-semibold text-green-600 dark:text-green-400">+{{ $movimiento->cantidad_movimiento }}</p>
                    </div>
                </div>

                <div class="flex items-center gap-3 px-4 py-3">
                    <i class="icon-tick text-2xl text-gray-400 dark:text-gray-500"></i>
                    <div class="min-w-0">
                        <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Stock nuevo</p>
                        <p class="text-lg font-semibold text-gray-800 dark:text-white">{{ $movimiento->cantidad_nueva }}</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-3 flex gap-3 max-lg:flex-col">
            {{-- Producto / variante --}}
            <div class="min-w-0 flex-1">
                <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                    <p class="text-base font-semibold text-gray-800 dark:text-white">Producto / variante</p>

                    <div class="mt-3 grid grid-cols-2 gap-4 text-sm max-sm:grid-cols-1">
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Producto</p>
                            <p class="mt-1 font-medium text-gray-800 dark:text-white">{{ $movimiento->retornoDetalle?->producto_nombre ?: $movimiento->product?->name ?: '-' }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">SKU</p>
                            <p class="mt-1 font-medium text-gray-800 dark:text-white">{{ $movimiento->retornoDetalle?->producto_sku ?: $movimiento->product?->sku ?: '-' }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Talla / variante</p>
                            <p class="mt-1 font-medium text-gray-800 dark:text-white">{{ $movimiento->retornoDetalle?->talla ?: '-' }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Inventory source</p>
                            <p class="mt-1 font-medium text-gray-800 dark:text-white">{{ $movimiento->inventorySource?->name ?: '-' }}</p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Origen: de donde viene este movimiento -- trazabilidad
            completa hacia la orden de confeccion / entrega / taller. --}}
            <div class="w-full lg:w-[320px]">
                <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                    <p class="text-base font-semibold text-gray-800 dark:text-white">Origen</p>

                    <dl class="mt-3 space-y-3 text-sm">
                        <div>
                            <dt class="text-xs text-gray-500 dark:text-gray-400">Orden de confeccion</dt>
                            <dd class="mt-1">
                                @if ($movimiento->ordenConfeccion && bouncer()->hasPermission('corte_confeccion.ordenes_confeccion'))
                                    <a href="{{ route('admin.corte_confeccion.ordenes_confeccion.show', $movimiento->orden_confeccion_id) }}" class="font-medium text-blue-600 hover:underline dark:text-blue-400">{{ $movimiento->ordenConfeccion->codigo }}</a>
                                @else
                                    <span class="font-medium text-gray-800 dark:text-white">{{ $movimiento->ordenConfeccion?->codigo ?: '-' }}</span>
                                @endif
                            </dd>
                        </div>

                        <div>
                            <dt class="text-xs text-gray-500 dark:text-gray-400">Entrega</dt>
                            <dd class="mt-1">
                                @if ($movimiento->retorno && $movimiento->ordenConfeccion && bouncer()->hasPermission('corte_confeccion.ordenes_confeccion'))
                                    <a href="{{ route('admin.corte_confeccion.ordenes_confeccion.show', $movimiento->orden_confeccion_id) }}#detalle-historial-{{ $movimiento->retorno_id }}" class="font-medium text-blue-600 hover:underline dark:text-blue-400">{{ $movimiento->retorno->codigo }}</a>
                                @else
                                    <span class="font-medium text-gray-800 dark:text-white">{{ $movimiento->retorno?->codigo ?: '-' }}</span>
                                @endif
                            </dd>
                        </div>

                        <div>
                            <dt class="text-xs text-gray-500 dark:text-gray-400">Taller</dt>
                            <dd class="mt-1 font-medium text-gray-800 dark:text-white">{{ $movimiento->ordenConfeccion?->taller?->nombre ?: '-' }}</dd>
                        </div>

                        <div>
                            <dt class="text-xs text-gray-500 dark:text-gray-400">Usuario de recepcion</dt>
                            <dd class="mt-1 font-medium text-gray-800 dark:text-white">{{ $movimiento->nombre_usuario_snapshot ?: ($movimiento->admin?->name ?: '-') }}</dd>
                        </div>
                    </dl>
                </div>
            </div>
        </div>

        @if ($movimiento->observacion)
            <div class="box-shadow mt-3 rounded bg-white p-4 dark:bg-gray-900">
                <p class="text-base font-semibold text-gray-800 dark:text-white">Observacion</p>
                <p class="mt-2 text-sm text-gray-700 dark:text-gray-300">{{ $movimiento->observacion }}</p>
            </div>
        @endif
    </div>
</x-admin::layouts>
