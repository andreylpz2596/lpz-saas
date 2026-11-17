{{-- Edicion de asesor (nombre, telefono y fecha de ingreso; el usuario asociado es inmutable para proteger el historial). --}}
<x-admin::modal>
    <x-slot:toggle>
        <button type="button" class="va-btn">@lang('ventasasesor::app.asesores.editar')</button>
    </x-slot>

    <x-slot:header>
        <p class="text-lg font-bold text-gray-800 dark:text-white">@lang('ventasasesor::app.asesores.editar')</p>
    </x-slot>

    <x-slot:content>
        <form method="POST" action="{{ route('admin.ventas_asesor.asesores.update', ['asesor' => $asesor->id]) }}" id="form-editar-asesor" class="grid gap-3 text-sm">
            @csrf

            <div>
                <p class="text-gray-600 dark:text-gray-300">@lang('ventasasesor::app.asesores.usuario')</p>
                <p class="font-semibold text-gray-900 dark:text-white">{{ $asesor->usuario }} · {{ $asesor->email }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400">@lang('ventasasesor::app.asesores.inmutable')</p>
            </div>

            <div>
                <label class="text-gray-600 dark:text-gray-300" for="e-nombre">@lang('ventasasesor::app.asesores.nombre') *</label>
                <input id="e-nombre" name="nombre" required maxlength="120" value="{{ old('nombre', $asesor->nombre) }}" class="mt-1 w-full rounded-lg border px-3 py-2 dark:border-gray-800 dark:bg-gray-900">
            </div>

            <div>
                <label class="text-gray-600 dark:text-gray-300" for="e-telefono">@lang('ventasasesor::app.asesores.telefono')</label>
                <input id="e-telefono" name="telefono" maxlength="30" inputmode="tel" value="{{ old('telefono', $asesor->telefono) }}" class="mt-1 w-full rounded-lg border px-3 py-2 dark:border-gray-800 dark:bg-gray-900">
            </div>

            <div>
                <label class="text-gray-600 dark:text-gray-300" for="e-fecha-ingreso">@lang('ventasasesor::app.asesores.fecha-ingreso')</label>
                <input id="e-fecha-ingreso" type="date" name="fecha_ingreso" max="{{ now()->toDateString() }}" value="{{ old('fecha_ingreso', $asesor->fecha_ingreso) }}" class="mt-1 w-full rounded-lg border px-3 py-2 dark:border-gray-800 dark:bg-gray-900">
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">@lang('ventasasesor::app.asesores.fecha-ingreso-ayuda')</p>
            </div>
        </form>
    </x-slot>

    <x-slot:footer>
        <button type="submit" form="form-editar-asesor" class="primary-button">@lang('ventasasesor::app.asesores.guardar')</button>
    </x-slot>
</x-admin::modal>
