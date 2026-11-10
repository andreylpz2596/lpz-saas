{{--
    Avisos de Corte y Confeccion en el listado NATIVO de notificaciones de Bagisto (evento
    `bagisto.admin.marketing.notifications.create.before`). Solo los del usuario autenticado. v-pre: la nota es texto.
--}}
@php
    $ccAvisos = auth()->guard('admin')->check() && \ByLopez\CorteConfeccion\Support\ConsolidadoConfeccionGate::puedeVer()
        ? \ByLopez\CorteConfeccion\Models\Notificacion::query()->where('admin_id', auth()->guard('admin')->id())->latest('id')->limit(30)->get()
        : collect();
@endphp

@if ($ccAvisos->isNotEmpty())
    @include('corteconfeccion::admin.partials.estilos')

    <div class="bylopez-scope mb-5" v-pre data-testid="avisos-corte-confeccion">
        <div class="box-shadow rounded-md bg-white dark:bg-gray-900">
            <div class="border-b p-4 dark:border-gray-800">
                <p class="text-base font-semibold text-gray-800 dark:text-white">Corte y confección</p>
                <p class="text-xs text-gray-600 dark:text-gray-300">Cambios de prioridad de tus prendas. Abrir un aviso lo marca como leído; eso no indica que el trabajo haya comenzado.</p>
            </div>

            <div class="grid">
                @foreach ($ccAvisos as $notificacion)
                    @php $a = \ByLopez\CorteConfeccion\Support\NotificacionPresentador::presentar($notificacion); @endphp
                    <a href="{{ $a['url'] }}" class="flex items-start gap-2 border-b p-4 last:border-b-0 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-950">
                        <span class="icon-information h-fit rounded-full text-2xl {{ $a['hacia'] === 'urgente' ? 'bg-red-100 text-red-600' : 'bg-amber-100 text-amber-600' }}"></span>
                        <div class="grid min-w-0 gap-0.5">
                            <p class="text-gray-800 dark:text-white {{ $a['leida'] ? 'font-normal' : 'font-semibold' }}">{{ $a['titulo'] }}</p>
                            <p class="text-sm text-gray-700 dark:text-gray-200">{{ $a['texto'] }}</p>
                            @if ($a['nota'])
                                <p class="break-words text-sm text-gray-600 dark:text-gray-300">Nota: {{ $a['nota'] }}</p>
                            @endif
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $a['autor'] ? $a['autor'].' · ' : '' }}{{ $a['fecha'] }}{{ $a['leida'] ? '' : ' · sin leer' }}</p>
                        </div>
                    </a>
                @endforeach
            </div>
        </div>
    </div>
@endif
