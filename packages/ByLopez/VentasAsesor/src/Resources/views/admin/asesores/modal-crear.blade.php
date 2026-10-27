{{-- Alta de asesor (solo con `ventas_asesor.asesores.manage`). Asocia un usuario EXISTENTE de Bagisto; no crea usuarios. --}}
<x-admin::modal>
    <x-slot:toggle>
        <button type="button" class="primary-button whitespace-nowrap">@lang('ventasasesor::app.asesores.crear')</button>
    </x-slot>

    <x-slot:header>
        <p class="text-lg font-bold text-gray-800 dark:text-white">@lang('ventasasesor::app.asesores.crear')</p>
    </x-slot>

    <x-slot:content>
        @if ($elegibles === [])
            <p class="text-sm text-gray-600 dark:text-gray-300">@lang('ventasasesor::app.asesores.sin-elegibles')</p>
        @else
            <form method="POST" action="{{ route('admin.ventas_asesor.asesores.store') }}" id="form-crear-asesor" class="grid gap-3 text-sm">
                @csrf

                <div>
                    <label class="text-gray-600 dark:text-gray-300" for="c-admin">@lang('ventasasesor::app.asesores.usuario-elegible') *</label>
                    <select id="c-admin" name="admin_id" required class="mt-1 w-full rounded-lg border px-3 py-2 dark:border-gray-800 dark:bg-gray-900">
                        @foreach ($elegibles as $u)
                            <option value="{{ $u['id'] }}" @selected((string) old('admin_id') === (string) $u['id'])>{{ $u['nombre'] }} · {{ $u['email'] }}@if ($u['rol']) · {{ $u['rol'] }}@endif</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">@lang('ventasasesor::app.asesores.usuario-ayuda')</p>
                </div>

                <div>
                    <label class="text-gray-600 dark:text-gray-300" for="c-nombre">@lang('ventasasesor::app.asesores.nombre') *</label>
                    <input id="c-nombre" name="nombre" required maxlength="120" value="{{ old('nombre') }}" class="mt-1 w-full rounded-lg border px-3 py-2 dark:border-gray-800 dark:bg-gray-900">
                </div>

                <div>
                    <label class="text-gray-600 dark:text-gray-300" for="c-telefono">@lang('ventasasesor::app.asesores.telefono')</label>
                    <input id="c-telefono" name="telefono" maxlength="30" inputmode="tel" value="{{ old('telefono') }}" class="mt-1 w-full rounded-lg border px-3 py-2 dark:border-gray-800 dark:bg-gray-900">
                </div>

                <label class="flex items-center gap-2 text-gray-700 dark:text-gray-300">
                    <input type="hidden" name="activo" value="0">
                    <input type="checkbox" name="activo" value="1" checked>
                    @lang('ventasasesor::app.asesores.activo')
                </label>
            </form>
        @endif
    </x-slot>

    <x-slot:footer>
        @if ($elegibles !== [])
            <button type="submit" form="form-crear-asesor" class="primary-button">@lang('ventasasesor::app.asesores.guardar')</button>
        @endif
    </x-slot>
</x-admin::modal>
