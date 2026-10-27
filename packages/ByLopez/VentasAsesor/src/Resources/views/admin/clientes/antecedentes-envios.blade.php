{{-- Historial reportado por el proveedor intermediario (99 Envios) en la ficha nativa del cliente. HECHOS con procedencia: no es una
     calificacion del cliente, ni entregas registradas/validadas en Logistica, ni pagos. Conteos por ENVIO UNICO. --}}
<div class="box-shadow rounded bg-white p-4 dark:bg-gray-900" data-tarjeta-antecedentes>
    <p class="text-base font-semibold text-gray-800 dark:text-white">@lang('ventasasesor::app.antecedentes.titulo')</p>

    @if ($nombresReportados !== [])
        <p class="mt-2 text-sm text-gray-700 dark:text-gray-200" data-nombre-reportado>
            @lang('ventasasesor::app.antecedentes.nombre-reportado'): <span class="font-semibold">{{ implode(' · ', $nombresReportados) }}</span>
        </p>
        <p class="text-xs text-amber-700 dark:text-amber-400">@lang('ventasasesor::app.antecedentes.nombre-propuesta')</p>
    @endif

    @if ($telefonos !== [])
        <p class="mt-2 text-sm text-gray-700 dark:text-gray-200" data-antecedentes-telefonos>
            @lang('ventasasesor::app.antecedentes.telefonos'):
            @foreach ($telefonos as $t)
                <span>{{ $t->telefono_normalizado }}@if ($t->telefono_original !== $t->telefono_normalizado) <span class="text-gray-500">({{ $t->telefono_original }})</span>@endif @if ($t->principal) <span class="text-gray-500">· @lang('ventasasesor::app.antecedentes.principal')</span> @endif</span>@if (! $loop->last), @endif
            @endforeach
        </p>
    @endif

    @if ($datosPendientes !== [])
        <div class="mt-2 rounded border border-amber-300 bg-amber-50 p-2 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-200" data-datos-pendientes>
            <p class="font-semibold">@lang('ventasasesor::app.antecedentes.datos-pendientes')</p>
            @foreach ($datosPendientes as $d)
                <p data-dato-pendiente="{{ $d['campo'] }}">@lang('ventasasesor::app.antecedentes.campo-pendiente.'.$d['campo']): «{{ $d['texto_original'] }}» <span class="text-xs">· @lang('ventasasesor::app.antecedentes.motivo-dato.'.$d['motivo'])</span></p>
            @endforeach
        </div>
    @endif

    @if ($pendientes !== [])
        <div class="mt-2 rounded border border-amber-300 bg-amber-50 p-2 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-200" data-direcciones-pendientes>
            <p class="font-semibold">@lang('ventasasesor::app.antecedentes.direccion-pendiente')</p>
            @foreach ($pendientes as $p)
                <p data-direccion-pendiente>{{ $p['direccion'] }}@if ($p['ciudad']) — {{ $p['ciudad'] }}@endif
                    <span class="text-xs">· @lang('ventasasesor::app.antecedentes.motivo-pendiente.'.$p['motivo'])@if ($p['carga_id']) · @lang('ventasasesor::app.antecedentes.origen-cargue', ['carga' => $p['carga_id'], 'fila' => $p['fila_origen'] ?? '—'])@endif</span></p>
            @endforeach
        </div>
    @endif

    <p class="mt-2 text-sm text-gray-700 dark:text-gray-200" data-antecedentes-conteo>
        <span class="font-bold">@lang('ventasasesor::app.antecedentes.entregados'): {{ $antecedentes['entregados'] }}</span>
        &nbsp;|&nbsp;
        <span class="font-bold">@lang('ventasasesor::app.antecedentes.devoluciones'): {{ $antecedentes['devoluciones'] }}</span>
        @if ($antecedentes['pendientes'] > 0)
            &nbsp;|&nbsp; @lang('ventasasesor::app.antecedentes.pendientes'): {{ $antecedentes['pendientes'] }}
        @endif
        @if ($antecedentes['desconocidos'] > 0)
            &nbsp;|&nbsp; @lang('ventasasesor::app.antecedentes.desconocidos'): {{ $antecedentes['desconocidos'] }}
        @endif
    </p>
    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">@lang('ventasasesor::app.antecedentes.aviso', ['total' => $antecedentes['total']])</p>

    <details class="mt-3">
        <summary class="cursor-pointer text-sm text-blue-600">@lang('ventasasesor::app.antecedentes.ver-evidencia')</summary>
        <div class="mt-2 overflow-x-auto">
            <table class="w-full text-left text-xs" data-antecedentes-evidencia>
                <thead>
                    <tr class="text-gray-500">
                        <th class="py-1 pr-3">@lang('ventasasesor::app.antecedentes.col-proveedor')</th>
                        <th class="py-1 pr-3">@lang('ventasasesor::app.antecedentes.col-transportadora')</th>
                        <th class="py-1 pr-3">@lang('ventasasesor::app.antecedentes.col-guia')</th>
                        <th class="py-1 pr-3">@lang('ventasasesor::app.antecedentes.col-estado')</th>
                        <th class="py-1 pr-3">@lang('ventasasesor::app.antecedentes.col-fecha')</th>
                        <th class="py-1">@lang('ventasasesor::app.antecedentes.col-procedencia')</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($antecedentes['envios'] as $e)
                        <tr class="border-t border-gray-100 dark:border-gray-800" data-clase="{{ $e['clase'] }}">
                            <td class="py-1 pr-3">{{ $e['proveedor'] === '99envios' ? '99 Envíos' : $e['proveedor'] }}</td>
                            <td class="py-1 pr-3">{{ $e['transportadora'] ?? ($e['transportadora_original'] ? '«'.$e['transportadora_original'].'» ('.trans('ventasasesor::app.antecedentes.transportadora-sin-equivalencia').')' : trans('ventasasesor::app.antecedentes.sin-transportadora')) }}</td>
                            <td class="py-1 pr-3">{{ $e['guia'] }}</td>
                            <td class="py-1 pr-3">{{ $e['estado'] }} <span class="text-gray-500">({{ trans('ventasasesor::app.antecedentes.clase.'.$e['clase']) }})</span></td>
                            <td class="py-1 pr-3">{{ $e['fecha_estado'] ? \Illuminate\Support\Carbon::parse($e['fecha_estado'])->format('Y-m-d') : '—' }}</td>
                            <td class="py-1">
                                @if ($e['origen'] === 'logistica')
                                    @lang('ventasasesor::app.antecedentes.origen-logistica')
                                @else
                                    @lang('ventasasesor::app.antecedentes.origen-cargue', ['carga' => $e['carga_id'] ?? '—', 'fila' => $e['fila_origen'] ?? '—'])
                                    @if (! empty($e['primer_carga_id']) && $e['primer_carga_id'] !== $e['carga_id'])
                                        <span class="text-gray-500">· @lang('ventasasesor::app.antecedentes.primer-cargue', ['carga' => $e['primer_carga_id']])</span>
                                    @endif
                                @endif
                                @if ($e['en_logistica'] && $e['origen'] !== 'logistica')
                                    · @lang('ventasasesor::app.antecedentes.ya-en-logistica')
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </details>
</div>
