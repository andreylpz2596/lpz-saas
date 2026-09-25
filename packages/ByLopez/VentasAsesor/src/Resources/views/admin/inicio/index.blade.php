<x-admin::layouts>
    <x-slot:title>
        @lang('ventasasesor::app.inicio.title')
    </x-slot>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <p class="text-xl font-bold text-gray-800 dark:text-white">
            @lang('ventasasesor::app.inicio.title')
        </p>
    </div>

    <div class="box-shadow mt-3.5 rounded bg-white p-4 dark:bg-gray-900">
        <p class="text-base font-semibold text-gray-800 dark:text-white">
            @lang('ventasasesor::app.inicio.perfil.title')
        </p>

        <dl class="mt-3 grid gap-3 sm:grid-cols-2">
            <div>
                <dt class="text-sm text-gray-600 dark:text-gray-300">
                    @lang('ventasasesor::app.inicio.perfil.asesor')
                </dt>

                <dd
                    class="text-base font-medium text-gray-800 dark:text-white"
                    data-es-asesor="{{ $esAsesor ? '1' : '0' }}"
                >
                    {{ $esAsesor ? trans('ventasasesor::app.inicio.si') : trans('ventasasesor::app.inicio.no') }}
                </dd>
            </div>

            <div>
                <dt class="text-sm text-gray-600 dark:text-gray-300">
                    @lang('ventasasesor::app.inicio.perfil.alcance')
                </dt>

                <dd
                    class="text-base font-medium text-gray-800 dark:text-white"
                    data-alcance="{{ $alcance->value }}"
                >
                    @lang('ventasasesor::app.inicio.alcance.'.$alcance->value)
                </dd>
            </div>
        </dl>

        <p class="mt-4 text-sm text-gray-600 dark:text-gray-300">
            @lang('ventasasesor::app.inicio.aviso')
        </p>
    </div>
</x-admin::layouts>
