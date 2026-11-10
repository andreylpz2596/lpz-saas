{{-- «Cuenta de esta orden». $cuentaOrden viene de CarteraVista::cuentaOrden() (ya autorizado en servidor). --}}
@if (! empty($cuentaOrden))
    @php
        $fmO = fn ($v) => \ByLopez\CorteConfeccion\Support\Dinero::formatear((string) $v);
        $d = $cuentaOrden['detalle'] ?? null;
    @endphp

    <div class="box-shadow min-w-0 overflow-hidden rounded bg-white p-4 dark:bg-gray-900">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-base font-semibold leading-none text-gray-800 dark:text-white">Cuenta de esta orden</p>
            <div class="flex flex-wrap items-center gap-2">
                @if ($d && ($d['cerrada_y_pagada'] ?? false))
                    <span class="label-active">Cerrada y pagada</span>
                @elseif ($d)
                    <span class="{{ ['sin_tarifa' => 'label-canceled', 'pendiente' => 'label-pending', 'abonada' => 'label-info', 'pagada' => 'label-active'][$d['estado_pago']] }}">{{ ['sin_tarifa' => 'Sin tarifa', 'pendiente' => 'Pago pendiente', 'abonada' => 'Abonada', 'pagada' => 'Pagada'][$d['estado_pago']] }}</span>
                @endif
                <a href="{{ $cuentaOrden['url'] }}" class="text-sm font-semibold text-blue-600 hover:underline">{{ $cuentaOrden['propia'] ? 'Mi cuenta' : 'Ver cuenta de la persona' }}</a>
            </div>
        </div>

        @if (! $cuentaOrden['iniciada'])
            <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">La cartera todavía no tiene fecha de arranque.</p>
        @elseif (! $d)
            <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">Todavía no hay trabajo recibido ni pendiente de recibir en esta orden desde el arranque de la cartera. Las prendas solo asignadas no cuentan.</p>
        @else
            <div class="mt-4 grid gap-3 text-sm">
                @if (! empty($cuentaOrden['origen']))
                    <p class="text-gray-600 dark:text-gray-300">Corte de origen: <a href="{{ $cuentaOrden['origen']['url'] }}" class="font-semibold text-blue-600 hover:underline">{{ $cuentaOrden['origen']['codigo'] }}</a></p>
                @endif

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[520px] text-left">
                        <thead class="text-xs uppercase text-gray-500 dark:text-gray-400">
                            <tr><th class="px-2 py-1.5 font-medium">Referencia</th><th class="px-2 py-1.5 text-right font-medium">Tarifa</th><th class="px-2 py-1.5 text-right font-medium">Reconocidas</th><th class="px-2 py-1.5 text-right font-medium">Pendientes de recibir</th><th class="px-2 py-1.5 text-right font-medium">Valor</th></tr>
                        </thead>
                        <tbody class="text-gray-800 dark:text-gray-200">
                            @foreach ($d['lineas'] as $l)
                                <tr class="border-t dark:border-gray-800">
                                    <td class="px-2 py-1.5">{{ $l['referencia'] }}</td>
                                    <td class="px-2 py-1.5 text-right tabular-nums">{{ $l['tarifa'] !== null ? $fmO($l['tarifa']) : 'Sin tarifa' }}</td>
                                    <td class="px-2 py-1.5 text-right tabular-nums">{{ number_format($l['cantidad_reconocida'], 0, ',', '.') }}</td>
                                    <td class="px-2 py-1.5 text-right tabular-nums">{{ $l['cantidad_pendiente'] ? number_format($l['cantidad_pendiente'], 0, ',', '.') : '—' }}</td>
                                    <td class="px-2 py-1.5 text-right tabular-nums">{{ $fmO($l['valor_reconocido']) }}@if ($l['cantidad_pendiente'])<span class="block text-xs text-gray-500">+ {{ $fmO($l['valor_pendiente']) }} est.</span>@endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if (! empty($cuentaOrden['recepciones']))
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Recepciones que originan el trabajo</p>
                        @foreach ($cuentaOrden['recepciones'] as $rc)
                            <p class="text-gray-700 dark:text-gray-300">{{ $rc['codigo'] }} · {{ \Illuminate\Support\Carbon::parse($rc['fecha'])->format('d-m-Y') }} · {{ number_format($rc['cantidad'], 0, ',', '.') }} {{ $rc['recibida'] ? 'recibidas por bodega'.(! empty($rc['defectuosas']) ? ' (incluye '.$rc['defectuosas'].' defectuosas)' : '') : 'pendientes de recibir' }}</p>
                        @endforeach
                    </div>
                @endif

                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Pagos aplicados y descuentos</p>
                    @forelse ($d['aplicaciones'] as $ap)
                        <p class="flex justify-between gap-2 text-gray-700 dark:text-gray-300">
                            <span>{{ $ap->concepto === 'descuento_prestamo' ? 'Descuento de préstamo '.$ap->movimiento.($ap->liquidacion ? ' · '.$ap->liquidacion : '') : 'Abono '.$ap->movimiento.' · '.\Illuminate\Support\Carbon::parse($ap->fecha_efectiva)->format('d-m-Y') }}</span>
                            <b class="whitespace-nowrap tabular-nums text-red-700 dark:text-red-400">{{ $fmO(\ByLopez\CorteConfeccion\Support\Dinero::negar((string) $ap->valor)) }}</b>
                        </p>
                    @empty
                        <p class="text-gray-500 dark:text-gray-400">Sin pagos aplicados todavía.</p>
                    @endforelse
                </div>

                <div class="grid gap-1 border-t pt-2 dark:border-gray-800">
                    <p class="flex justify-between gap-2"><span>Trabajo reconocido</span><b class="tabular-nums">{{ $fmO($d['valor_reconocido']) }}</b></p>
                    @if (\ByLopez\CorteConfeccion\Support\Dinero::esPositivo($d['valor_pendiente']))<p class="flex justify-between gap-2"><span>Pendiente de recibir (estimado)</span><b class="tabular-nums">{{ $fmO($d['valor_pendiente']) }}</b></p>@endif
                    <p class="flex justify-between gap-2"><span>Pagos y descuentos aplicados</span><b class="tabular-nums">{{ $fmO(\ByLopez\CorteConfeccion\Support\Dinero::negar($d['aplicado'])) }}</b></p>
                    <p class="flex justify-between gap-2 text-base font-bold"><span>Saldo por pagar de lo recibido</span><span class="tabular-nums">{{ $fmO($d['saldo_recibido']) }}</span></p>
                    @if (\ByLopez\CorteConfeccion\Support\Dinero::esPositivo($d['anticipo_ligado_pendiente']))<p class="text-xs text-gray-500">Incluye un anticipo de {{ $fmO($d['anticipo_ligado_pendiente']) }} ligado a prendas pendientes de recibir; se concilia una sola vez al recibirlas.</p>@endif
                    @if (\ByLopez\CorteConfeccion\Support\Dinero::esPositivo($d['credito']))<p class="text-xs text-amber-700">Crédito de {{ $fmO($d['credito']) }}: bodega aceptó menos de lo estimado. No se traslada solo a otra orden.</p>@endif
                    @if ($d['cerrada_y_pagada'])
                        @php $ultima = collect($d['aplicaciones'])->filter(fn ($a) => $a->liquidacion_fecha)->last(); @endphp
                        <p class="text-xs text-gray-500">Cerrada y pagada{{ $ultima ? ' · liquidada el '.\Illuminate\Support\Carbon::parse($ultima->liquidacion_fecha)->format('d-m-Y').' en '.$ultima->liquidacion : '' }}. Los movimientos de arriba explican el cierre.</p>
                    @endif
                </div>
            </div>
        @endif
    </div>
@endif
