<x-admin::layouts>
    <x-slot:title>Rollo {{ $rollo->codigo }}</x-slot>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <p class="text-xl font-bold text-gray-800 dark:text-white">Rollo {{ $rollo->codigo }}</p>

        <div class="flex gap-2">
            <form method="POST" action="{{ route('admin.corte_confeccion.rollos.cancel', $rollo->id) }}" data-confirm="Esta accion anula el rollo y ya no podra usarse en nuevas ordenes de corte. ¿Confirmas la anulacion?" data-confirm-title="Anular rollo">@csrf <button class="transparent-button">Anular</button></form>
        </div>
    </div>

    <div class="mt-4 grid grid-cols-3 gap-4 max-md:grid-cols-1">
        <div class="rounded border p-4 dark:border-gray-800">
            <p class="text-sm text-gray-600 dark:text-gray-300">Estado</p>
            <div class="mt-1">{!! \ByLopez\CorteConfeccion\Enums\EstadoRollo::badgeHtml($rollo->estado) !!}</div>
        </div>

        @foreach (['proveedor', 'tipo_tela', 'referencia', 'color', 'peso_inicial', 'peso_disponible', 'costo_total', 'fecha_compra'] as $field)
            <div class="rounded border p-4 dark:border-gray-800">
                <p class="text-sm text-gray-600 dark:text-gray-300">{{ ucfirst(str_replace('_', ' ', $field)) }}</p>
                <p class="font-semibold text-gray-800 dark:text-white">{{ $rollo->{$field} }}</p>
            </div>
        @endforeach
    </div>

    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', function () {
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
