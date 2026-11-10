@include('corteconfeccion::admin.partials.estilos')

<x-admin::layouts>
    <x-slot:title>{{ $propia ? 'Mi cuenta' : 'Cuenta de '.$prestador->nombre }}</x-slot>

    @php
        $fm = fn ($v) => \ByLopez\CorteConfeccion\Support\Dinero::formatear((string) $v);
        $esTaller = $tipo === 'taller';
        $favor = $cuenta['a_favor_bylopez'];
        $verbo = $esTaller ? 'a confección' : 'a corte';
        $quien = $propia ? 'ti' : $prestador->nombre;
        $tiposMov = [
            'abono' => 'Abono por trabajo', 'prestamo' => 'Préstamo', 'devolucion' => 'Devolución de préstamo',
            'saldo_inicial_trabajo' => 'Saldo inicial · deuda previa por trabajo', 'saldo_inicial_anticipo' => 'Saldo inicial · anticipo previo',
            'saldo_inicial_prestamo' => 'Saldo inicial · préstamo anterior',
        ];
        $estadoProduccion = ['activa' => 'Activa', 'en_confeccion' => 'En confección', 'terminada_taller' => 'Terminada por el taller', 'recibida_bodega' => 'Recibida por bodega', 'cerrada' => 'Cerrada', 'cerrado' => 'Cerrada', 'anulada' => 'Anulada', 'reasignada' => 'Reasignada', 'pendiente_entrega' => 'Pendiente de entrega', 'entregado_a_cortador' => 'Entregada al cortador', 'en_corte' => 'En corte', 'reportado_por_cortador' => 'Reportado por el cortador', 'recibido_bodega' => 'Recibida por bodega'];
        $estadoPago = ['sin_tarifa' => ['Sin tarifa', 'label-canceled'], 'pendiente' => ['Pago pendiente', 'label-pending'], 'abonada' => ['Abonada', 'label-info'], 'pagada' => ['Pagada', 'label-active']];

        $datosJs = [
            'tipo' => $tipo, 'prestadorId' => $prestador->id, 'csrf' => csrf_token(), 'hoy' => now()->toDateString(), 'arranque' => $cuenta['fecha_arranque'],
            'urls' => [
                'movimientos' => route('admin.corte_confeccion.cartera.movimientos.store', ['tipo' => $tipo, 'id' => $prestador->id]),
                'prestamos' => route('admin.corte_confeccion.cartera.prestamos.store', ['tipo' => $tipo, 'id' => $prestador->id]),
                'comprobantes' => route('admin.corte_confeccion.cartera.comprobantes.leer', ['tipo' => $tipo, 'id' => $prestador->id]),
                'corteVista' => route('admin.corte_confeccion.cartera.corte.vista', ['tipo' => $tipo, 'id' => $prestador->id]),
                'corte' => route('admin.corte_confeccion.cartera.corte.confirmar', ['tipo' => $tipo, 'id' => $prestador->id]),
                'aplicar' => route('admin.corte_confeccion.cartera.movimientos.aplicar', ['id' => '__ID__']),
                'reversar' => route('admin.corte_confeccion.cartera.movimientos.reversar', ['id' => '__ID__']),
                'reversarCorte' => route('admin.corte_confeccion.cartera.corte.reversar', ['id' => '__ID__']),
                'arranque' => route('admin.corte_confeccion.cartera.arranque'),
            ],
            'ordenes' => collect($cuenta['ordenes'])->map(fn ($o) => [
                'tipo' => $o['tipo'], 'id' => $o['orden_id'], 'codigo' => $o['codigo'], 'saldoEstimado' => $o['saldo_estimado'],
                'saldoRecibido' => $o['saldo_recibido'], 'pendiente' => (int) $o['cantidad_pendiente'],
            ])->values()->all(),
            'deudaPrevia' => collect($cuenta['movimientos'])->where('tipo', 'saldo_inicial_trabajo')->where('estado', 'registrado')->map(fn ($m) => ['id' => $m->id, 'pendiente' => $cuenta['deuda_previa_pendiente']])->first(),
            'prestamos' => collect($cuenta['prestamos'])->map(fn ($p, $id) => ['id' => $id, 'codigo' => $p['codigo'], 'pendiente' => $p['pendiente']])->filter(fn ($p) => \ByLopez\CorteConfeccion\Support\Dinero::esPositivo($p['pendiente']))->values()->all(),
            'puedePrestamos' => $puedePrestamos, 'puedeArranque' => $puedeArranque, 'puedeRegistrar' => $puedeRegistrar, 'lector' => $lectorDisponible,
        ];
    @endphp

    <div class="bylopez-scope grid gap-4" id="cartera-cuenta" data-config="{{ json_encode($datosJs) }}">
        {{-- Encabezado --}}
        <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
            <div class="flex items-center gap-2.5">
                <h1 class="text-xl font-bold leading-6 text-gray-800 dark:text-white">{{ $propia ? 'Mi cuenta' : $prestador->nombre }}</h1>
                <span class="{{ $prestador->estado === 'activo' ? 'label-active' : 'label-canceled' }} mx-1.5 text-sm">{{ $prestador->estado === 'activo' ? 'Activo' : 'Inactivo' }}</span>
                <span class="text-sm text-gray-500 dark:text-gray-400">{{ $esTaller ? 'Satélite / taller de confección' : 'Cortador' }}</span>
            </div>

            <div class="flex flex-wrap gap-2">
                @if ($cuenta['iniciada'] && ($puedeRegistrar || $puedePrestamos))
                    <button type="button" class="secondary-button" data-c-abrir="movimiento">Registrar movimiento</button>
                @endif
                @if ($cuenta['iniciada'] && $puedeCorte)
                    <button type="button" class="primary-button" data-c-abrir="corte">Hacer corte de cuentas</button>
                @endif
                @unless ($propia)
                    <a href="{{ route($esTaller ? 'admin.corte_confeccion.talleres.show' : 'admin.corte_confeccion.cortadores.show', $prestador->id) }}" class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800">Ver ficha</a>
                @endunless
            </div>
        </div>

        @if ($propia)
            <p class="text-sm text-gray-600 dark:text-gray-300">Aquí ves tu cuenta con ByLopez. Es solo de consulta: no puedes registrar ni modificar pagos, préstamos, tarifas ni saldos.</p>
        @endif

        @if (! $cuenta['iniciada'])
            <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                <p class="text-sm font-semibold text-gray-800 dark:text-white">La cartera todavía no ha iniciado</p>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Cuando se defina la fecha de arranque aparecerá aquí la cuenta, con los saldos anteriores como saldo inicial.</p>
                @if ($puedeArranque)
                    <a href="{{ route('admin.corte_confeccion.cartera.index') }}" class="mt-2 inline-block text-sm font-semibold text-blue-600 hover:underline">Definir la fecha de arranque</a>
                @endif
            </div>
        @else
            {{-- Franja de indicadores --}}
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm font-semibold text-gray-600 dark:text-gray-300">
                @php $nOrd = count($cuenta['ordenes']); $nAct = collect($cuenta['ordenes'])->filter(fn ($o) => ! in_array($o['estado_produccion'], ['cerrada', 'cerrado', 'anulada', 'anulado'], true))->count(); @endphp
                <span>{{ $nOrd }} {{ $nOrd === 1 ? 'orden' : 'órdenes' }}: {{ $nAct }} {{ $nAct === 1 ? 'activa' : 'activas' }} · {{ $nOrd - $nAct }} {{ ($nOrd - $nAct) === 1 ? 'cerrada' : 'cerradas' }}</span>
                <span>{{ number_format($cuenta['cantidad_reconocida'], 0, ',', '.') }} {{ $esTaller ? 'prendas' : 'piezas' }} recibidas</span>
                <span>{{ number_format($cuenta['cantidad_pendiente'], 0, ',', '.') }} pendientes de recibir</span>
                <span class="font-normal text-gray-500">Cartera iniciada el {{ \Illuminate\Support\Carbon::parse($cuenta['fecha_arranque'])->format('d-m-Y') }}</span>
            </div>

            @if (! empty($cuenta['sin_tarifa']))
                <div class="rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">
                    <b>Importe parcial.</b> Faltan por valorar (sin tarifa): {{ implode(', ', $cuenta['sin_tarifa']) }}. No se cuentan como $ 0: quedan fuera de los importes hasta pactar su tarifa.
                </div>
            @endif

            {{-- CUENTA --}}
            <div class="box-shadow grid gap-4 rounded bg-white p-4 dark:bg-gray-900">
                <div class="flex items-center justify-between gap-2">
                    <p class="text-base font-semibold text-gray-800 dark:text-white">Cuenta</p>
                    <span class="text-xs text-gray-500 dark:text-gray-400">al {{ now()->format('d-m-Y') }}</span>
                </div>

                {{-- Resultado: una cifra principal y la cuenta al tocarla --}}
                <details class="overflow-hidden rounded border dark:border-gray-800">
                    <summary class="grid cursor-pointer gap-1 bg-slate-50 p-4 dark:bg-gray-800">
                        <span class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            @if ($favor)
                                {{ $propia ? 'Le debes a ByLopez' : 'Saldo a favor de ByLopez' }}
                            @else
                                {{ $propia ? 'ByLopez te pagaría · estimado' : 'Faltaría por pagar a '.$prestador->nombre.' · estimado' }}
                            @endif
                        </span>
                        <span class="text-3xl font-bold tabular-nums text-gray-900 dark:text-white">{{ $fm($favor ? \ByLopez\CorteConfeccion\Support\Dinero::absoluto($cuenta['por_pagar_estimado']) : $cuenta['por_pagar_estimado']) }}</span>
                        <span class="text-sm text-gray-600 dark:text-gray-300">
                            @if ($favor)
                                Se abonó más de lo que vale el trabajo: queda como anticipo disponible para próximas órdenes.
                            @elseif (\ByLopez\CorteConfeccion\Support\Dinero::esPositivo($cuenta['trabajo_pendiente']))
                                {{ $fm($cuenta['por_pagar_recibido']) }} por lo ya recibido por bodega · el resto depende de {{ number_format($cuenta['cantidad_pendiente'], 0, ',', '.') }} {{ $esTaller ? 'prendas' : 'piezas' }} pendientes de recibir
                            @else
                                Todo lo que cuenta ya está recibido por bodega
                            @endif
                        </span>
                        <span class="text-xs font-semibold text-blue-600">Tocar para ver la cuenta</span>
                    </summary>

                    <div class="grid gap-0 p-4 text-sm">
                        @php
                            $lineas = [];
                            if (\ByLopez\CorteConfeccion\Support\Dinero::esPositivo($cuenta['deuda_previa'])) { $lineas[] = ['Saldo inicial · deuda previa por trabajo', 'Anterior al arranque', $cuenta['deuda_previa'], false]; }
                            $lineas[] = ['Trabajo recibido por bodega', number_format($cuenta['cantidad_reconocida'], 0, ',', '.').($esTaller ? ' buenas' : ' piezas (incluye defectuosas)'), $cuenta['trabajo_reconocido'], false];
                            if (\ByLopez\CorteConfeccion\Support\Dinero::esPositivo($cuenta['trabajo_pendiente'])) { $lineas[] = ['Trabajo pendiente de recibir (estimado)', number_format($cuenta['cantidad_pendiente'], 0, ',', '.').($esTaller ? ' reportadas por el taller' : ' reportadas por el cortador'), $cuenta['trabajo_pendiente'], false]; }
                            $lineas[] = ['Abonos por trabajo', 'Total abonado '.$fm($cuenta['total_abonado']), \ByLopez\CorteConfeccion\Support\Dinero::negar(\ByLopez\CorteConfeccion\Support\Dinero::sumar($cuenta['total_abonado'], $cuenta['anticipo_previo'])), true];
                            if (\ByLopez\CorteConfeccion\Support\Dinero::esPositivo($cuenta['descuento_prestamo'])) { $lineas[] = ['Descuento de préstamo acordado', 'Aplicado en un corte de cuentas', \ByLopez\CorteConfeccion\Support\Dinero::negar($cuenta['descuento_prestamo']), true]; }
                            $lineas[] = ['Resultado: '.($favor ? 'a favor de ByLopez' : 'faltaría por pagar'), '', \ByLopez\CorteConfeccion\Support\Dinero::absoluto($cuenta['por_pagar_estimado']), false];
                        @endphp
                        @foreach ($lineas as $i => $l)
                            <div class="flex justify-between gap-3 border-b border-dashed py-1.5 dark:border-gray-800 {{ $loop->last ? 'border-t border-solid border-gray-400 pt-2 font-bold' : '' }}">
                                <span class="min-w-0">{{ $l[0] }}@if ($l[1])<small class="block text-xs font-normal text-gray-500 dark:text-gray-400">{{ $l[1] }}</small>@endif</span>
                                <span class="whitespace-nowrap tabular-nums {{ $l[3] ? 'text-red-700 dark:text-red-400' : '' }}">{{ $fm($l[2]) }}</span>
                            </div>
                        @endforeach
                        @if (\ByLopez\CorteConfeccion\Support\Dinero::esPositivo($cuenta['creditos']))
                            <p class="mt-2 rounded bg-amber-50 p-2 text-xs text-amber-900 dark:bg-amber-950 dark:text-amber-100">Incluye {{ $fm($cuenta['creditos']) }} de crédito: se abonó por trabajo que bodega aceptó en menor cantidad. Se muestra en su orden y no se traslada solo a otra.</p>
                        @endif
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Cada importe se cuenta una sola vez. «Faltaría por pagar» por lo ya recibido: {{ $fm($cuenta['por_pagar_recibido']) }}.</p>
                    </div>
                </details>

                {{-- Trabajo · Abonos · Préstamos --}}
                <div class="grid grid-cols-3 gap-3 max-lg:grid-cols-1">
                    <div class="grid content-start gap-2 rounded border p-3 dark:border-gray-800">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Trabajo</p>
                        <div class="flex justify-between gap-2 text-sm"><span>Recibido por bodega</span><b class="whitespace-nowrap tabular-nums">{{ number_format($cuenta['cantidad_reconocida'], 0, ',', '.') }} · {{ $fm($cuenta['trabajo_reconocido']) }}</b></div>
                        <div class="flex justify-between gap-2 text-sm"><span>Pendiente de recibir</span><b class="whitespace-nowrap tabular-nums">{{ number_format($cuenta['cantidad_pendiente'], 0, ',', '.') }} · {{ $fm($cuenta['trabajo_pendiente']) }}</b></div>
                        <div class="flex justify-between gap-2 border-t pt-2 text-sm font-bold dark:border-gray-800"><span>Valor estimado del trabajo</span><span class="whitespace-nowrap tabular-nums">{{ $fm($cuenta['trabajo_estimado']) }}</span></div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $esTaller ? 'Las prendas solo asignadas todavía no cuentan.' : 'Si bodega recibe otra cantidad, vale lo recibido.' }}</p>
                    </div>

                    <div class="grid content-start gap-2 rounded border p-3 dark:border-gray-800">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Abonos</p>
                        <div class="flex justify-between gap-2 text-sm"><span>Total abonado por trabajo</span><b class="whitespace-nowrap tabular-nums">{{ $fm($cuenta['total_abonado']) }}</b></div>
                        <details class="text-xs">
                            <summary class="cursor-pointer font-semibold text-blue-600">Ver aplicado y disponible</summary>
                            <div class="mt-2 grid gap-1 text-gray-700 dark:text-gray-300">
                                <div class="flex justify-between gap-2"><span>Aplicado a trabajo recibido</span><b class="tabular-nums">{{ $fm(\ByLopez\CorteConfeccion\Support\Dinero::restar($cuenta['pagos_aplicados'], $cuenta['anticipo_ligado_pendiente'])) }}</b></div>
                                @if (\ByLopez\CorteConfeccion\Support\Dinero::esPositivo($cuenta['anticipo_ligado_pendiente']))
                                    <div class="flex justify-between gap-2"><span>Aplicado a pendientes de recibir</span><b class="tabular-nums">{{ $fm($cuenta['anticipo_ligado_pendiente']) }}</b></div>
                                @endif
                                <div class="flex justify-between gap-2"><span>Disponible como anticipo</span><b class="tabular-nums">{{ $fm($cuenta['anticipo_disponible']) }}</b></div>
                                @if (\ByLopez\CorteConfeccion\Support\Dinero::esPositivo($cuenta['anticipo_previo']))
                                    <div class="flex justify-between gap-2"><span>Anticipo previo al arranque (no suma al total)</span><b class="tabular-nums">{{ $fm($cuenta['anticipo_previo']) }}</b></div>
                                @endif
                                <p>Usar un anticipo o descontar un préstamo no aumenta el total abonado.</p>
                            </div>
                        </details>
                    </div>

                    <div class="grid content-start gap-2 rounded border p-3 dark:border-gray-800">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Préstamos</p>
                        <div class="flex justify-between gap-2 text-sm"><span>Total prestado</span><b class="whitespace-nowrap tabular-nums">{{ $fm($cuenta['total_prestado']) }}</b></div>
                        <div class="flex justify-between gap-2 text-sm"><span>Devuelto o descontado</span><b class="whitespace-nowrap tabular-nums">{{ $fm(\ByLopez\CorteConfeccion\Support\Dinero::sumar($cuenta['devuelto'], $cuenta['descontado'])) }}</b></div>
                        <div class="flex justify-between gap-2 border-t pt-2 text-sm font-bold dark:border-gray-800"><span>{{ $propia ? 'Le debes a ByLopez' : 'Préstamo pendiente' }}</span><span class="whitespace-nowrap tabular-nums">{{ $fm($cuenta['prestamo_pendiente']) }}</span></div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Aparte del trabajo. Solo baja con una devolución real o un descuento acordado en un corte de cuentas.</p>
                    </div>
                </div>
            </div>

            {{-- ÓRDENES --}}
            <div class="box-shadow grid gap-3 rounded bg-white p-4 dark:bg-gray-900">
                <p class="text-base font-semibold text-gray-800 dark:text-white">Órdenes y estado de pago</p>

                @if (count($cuenta['ordenes']) === 0)
                    <p class="text-sm text-gray-500 dark:text-gray-400">Todavía no hay trabajo recibido ni pendiente de recibir desde el arranque.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[640px] text-left text-sm">
                            <thead class="text-xs uppercase text-gray-500 dark:text-gray-400">
                                <tr><th class="px-2 py-2 font-medium">Orden</th><th class="px-2 py-2 font-medium">Producción</th><th class="px-2 py-2 font-medium">Pago</th><th class="px-2 py-2 text-right font-medium">Recibido</th><th class="px-2 py-2 text-right font-medium">Pendiente de recibir</th><th class="px-2 py-2 text-right font-medium">Saldo</th></tr>
                            </thead>
                            <tbody class="text-gray-800 dark:text-gray-200">
                                @foreach ($cuenta['ordenes'] as $o)
                                    @php $ruta = $o['tipo'] === 'confeccion' ? route('admin.corte_confeccion.ordenes_confeccion.show', $o['orden_id']) : route('admin.corte_confeccion.ordenes_corte.show', $o['orden_id']); @endphp
                                    <tr class="border-t align-top dark:border-gray-800">
                                        <td class="px-2 py-2"><a href="{{ $ruta }}" class="font-semibold text-blue-600 hover:underline">{{ $o['codigo'] }}</a></td>
                                        <td class="px-2 py-2">{{ $estadoProduccion[$o['estado_produccion']] ?? $o['estado_produccion'] }}</td>
                                        <td class="px-2 py-2">
                                            @if ($o['cerrada_y_pagada'])
                                                <span class="label-active">Cerrada y pagada</span>
                                            @else
                                                <span class="{{ $estadoPago[$o['estado_pago']][1] }}">{{ $estadoPago[$o['estado_pago']][0] }}</span>
                                            @endif
                                            @if (\ByLopez\CorteConfeccion\Support\Dinero::esPositivo($o['anticipo_ligado_pendiente']))<p class="mt-1 text-xs text-gray-500">Anticipo ligado a pendiente: {{ $fm($o['anticipo_ligado_pendiente']) }}</p>@endif
                                            @if (\ByLopez\CorteConfeccion\Support\Dinero::esPositivo($o['credito']))<p class="mt-1 text-xs text-amber-700">Crédito de la orden: {{ $fm($o['credito']) }}</p>@endif
                                        </td>
                                        <td class="px-2 py-2 text-right tabular-nums">{{ number_format($o['cantidad_reconocida'], 0, ',', '.') }} · {{ $fm($o['valor_reconocido']) }}</td>
                                        <td class="px-2 py-2 text-right tabular-nums">{{ $o['cantidad_pendiente'] ? number_format($o['cantidad_pendiente'], 0, ',', '.').' · '.$fm($o['valor_pendiente']) : '—' }}</td>
                                        <td class="px-2 py-2 text-right tabular-nums">
                                            {{ $fm($o['saldo_recibido']) }}
                                            @if ($o['cantidad_pendiente'] && \ByLopez\CorteConfeccion\Support\Dinero::esPositivo(\ByLopez\CorteConfeccion\Support\Dinero::restar($o['saldo_estimado'], $o['saldo_recibido'])))<span class="block text-xs text-gray-500">+ {{ $fm(\ByLopez\CorteConfeccion\Support\Dinero::restar($o['saldo_estimado'], $o['saldo_recibido'])) }} est.</span>@endif
                                            @if (! empty($o['sin_tarifa']))<span class="block text-xs text-red-600">Sin tarifa: {{ implode(', ', $o['sin_tarifa']) }}</span>@endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Una orden cerrada en producción con saldo por pagar sigue visible aquí hasta quedar «Cerrada y pagada».</p>
                @endif
            </div>

            {{-- HISTORIAL --}}
            <div class="box-shadow grid gap-3 rounded bg-white p-4 dark:bg-gray-900">
                <p class="text-base font-semibold text-gray-800 dark:text-white">{{ $propia ? 'Mis movimientos' : 'Historial de la cuenta' }}</p>
                <div class="flex flex-wrap gap-1.5" role="group" aria-label="Filtrar movimientos">
                    @foreach (['todos' => 'Todos', 'abono' => 'Abonos', 'prestamo' => 'Préstamos y devoluciones', 'saldo' => 'Saldos iniciales', 'corte' => 'Cortes de cuentas'] as $k => $t)
                        <button type="button" class="rounded-full border px-3 py-1 text-xs font-semibold {{ $k === 'todos' ? 'border-blue-600 bg-blue-600 text-white' : 'text-gray-700 dark:border-gray-700 dark:text-gray-300' }}" data-c-filtro="{{ $k }}" aria-pressed="{{ $k === 'todos' ? 'true' : 'false' }}">{{ $t }}</button>
                    @endforeach
                </div>

                <div class="grid gap-2" id="cartera-historial">
                    @foreach ($movs as $m)
                        @php
                            $grupo = in_array($m->tipo, ['prestamo', 'devolucion'], true) ? 'prestamo' : (str_starts_with($m->tipo, 'saldo_inicial') ? 'saldo' : 'abono');
                            $ops = $operacionesPorMov->get($m->id, collect());
                            $sops = $soportesPorMov->get($m->id, collect());
                            $dispo = $m->detalle_anticipo['disponible'] ?? null;
                        @endphp
                        <div class="rounded border p-3 text-sm dark:border-gray-800 {{ $m->estado === 'reversado' ? 'opacity-60' : '' }}" data-c-grupo="{{ $grupo }}">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="font-semibold text-gray-800 dark:text-white">{{ $m->codigo }} · {{ $tiposMov[$m->tipo] ?? $m->tipo }}
                                        @if ($m->estado === 'reversado')<span class="label-canceled ml-1">Reversado</span>@endif
                                    </p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ \Illuminate\Support\Carbon::parse($m->fecha_efectiva)->format('d-m-Y') }}{{ $m->medio ? ' · '.ucfirst($m->medio) : '' }}{{ $m->referencia ? ' · ref. '.$m->referencia : '' }}
                                        @if ($m->modo_detalle === 'detalle' || $m->num_transferencias) · 1 registro · {{ $m->num_transferencias ?? $ops->count() }} {{ ($m->num_transferencias ?? $ops->count()) === 1 ? 'transferencia' : 'transferencias' }}@endif
                                    </p>
                                    @if ($m->observacion)<p class="mt-1 text-xs text-gray-600 dark:text-gray-300">{{ $m->observacion }}</p>@endif
                                </div>
                                <p class="whitespace-nowrap text-base font-bold tabular-nums">{{ $fm($m->valor) }}</p>
                            </div>

                            @if ($m->modo_detalle === 'total' && $m->num_transferencias && $m->tipo === 'abono')
                                <p class="mt-1 text-xs text-gray-500">Registrado solo con el total: no se pueden comparar referencias individuales.</p>
                            @endif

                            @if ($ops->isNotEmpty())
                                <details class="mt-2 text-xs">
                                    <summary class="cursor-pointer font-semibold text-blue-600">Ver las {{ $ops->count() }} {{ $ops->count() === 1 ? 'operación' : 'operaciones' }} que cubre</summary>
                                    <div class="mt-1 grid gap-1">
                                        @foreach ($ops as $op)
                                            <div class="flex justify-between gap-2 border-b border-dashed pb-1 dark:border-gray-800"><span>{{ $op->referencia ?: 'Sin referencia' }} · {{ $op->banco_destino ?: ($op->banco_origen ?: ucfirst((string) $op->medio)) }}{{ $op->fecha_operacion ? ' · '.\Illuminate\Support\Carbon::parse($op->fecha_operacion)->format('d-m-Y') : '' }}</span><b class="tabular-nums">{{ $fm($op->valor) }}</b></div>
                                        @endforeach
                                    </div>
                                </details>
                            @endif

                            @if ($sops->isNotEmpty())
                                <p class="mt-1 text-xs text-gray-500">Soportes:
                                    @foreach ($sops as $s)<a class="font-semibold text-blue-600 hover:underline" target="_blank" rel="noopener" href="{{ route('admin.corte_confeccion.cartera.soporte', $s->id) }}">{{ $s->nombre_original }}</a>{{ ! $loop->last ? ', ' : '' }}@endforeach
                                </p>
                            @endif

                            @if ($dispo !== null && $m->estado === 'registrado')
                                <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">Aplicado {{ $fm($m->detalle_anticipo['aplicado']) }} · disponible como anticipo <b>{{ $fm($dispo) }}</b></p>
                            @endif

                            @if ($m->estado === 'reversado')
                                <p class="mt-1 text-xs text-red-700">Reversado el {{ \Illuminate\Support\Carbon::parse($m->reversado_en)->format('d-m-Y') }}: {{ $m->motivo_reverso }}</p>
                            @endif

                            @if ($m->estado === 'registrado' && ($puedeRegistrar || $puedeReversar) && ! $propia)
                                <div class="mt-2 flex flex-wrap gap-3 text-xs font-semibold">
                                    @if ($dispo !== null && \ByLopez\CorteConfeccion\Support\Dinero::esPositivo($dispo) && $puedeRegistrar)
                                        <button type="button" class="text-blue-600 hover:underline" data-c-aplicar="{{ $m->id }}" data-c-disponible="{{ $dispo }}">Aplicar anticipo</button>
                                    @endif
                                    @if ($puedeReversar)
                                        <button type="button" class="text-red-600 hover:underline" data-c-reversar="{{ $m->id }}" data-c-url="reversar">Reversar</button>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endforeach

                    @foreach ($cortes as $c)
                        <div class="rounded border p-3 text-sm dark:border-gray-800 {{ $c->estado === 'reversado' ? 'opacity-60' : '' }}" data-c-grupo="corte">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div>
                                    <p class="font-semibold text-gray-800 dark:text-white">{{ $c->codigo }} · Corte de cuentas @if ($c->estado === 'reversado')<span class="label-canceled ml-1">Reversado</span>@endif @if (! ($c->cifras['completo'] ?? true))<span class="label-pending ml-1">Incompleto</span>@endif</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $c->fecha->format('d-m-Y') }} · Órdenes: {{ implode(', ', $c->cifras['ordenes_incluidas'] ?? []) ?: '—' }}</p>
                                    @if (! empty($c->cifras['ordenes_excluidas_sin_tarifa']))<p class="text-xs text-red-600">Excluidas por no tener tarifa: {{ implode(', ', $c->cifras['ordenes_excluidas_sin_tarifa']) }}</p>@endif
                                    @if ($c->observacion)<p class="mt-1 text-xs">{{ $c->observacion }}</p>@endif
                                </div>
                                <div class="text-right text-xs tabular-nums">
                                    <p>Trabajo reconocido {{ $fm($c->trabajo_reconocido) }}</p>
                                    <p>Descuento de préstamo {{ $fm($c->descuento_prestamo) }}</p>
                                    <p class="text-sm font-bold">Quedó por pagar {{ $fm($c->queda_por_pagar) }}</p>
                                </div>
                            </div>
                            <p class="mt-1 text-xs text-gray-500">Confirmar un corte no registra dinero nuevo: el pago se registra aparte como abono.</p>
                            @if ($c->estado === 'registrado' && $puedeReversar && ! $propia)
                                <button type="button" class="mt-2 text-xs font-semibold text-red-600 hover:underline" data-c-reversar="{{ $c->id }}" data-c-url="reversarCorte">Reversar corte</button>
                            @endif
                        </div>
                    @endforeach

                    @if ($movs->isEmpty() && $cortes->isEmpty())
                        <p class="text-sm text-gray-500 dark:text-gray-400">Sin movimientos todavía.</p>
                    @endif
                </div>
            </div>
        @endif

        {{-- MODALES (solo personal autorizado; el servidor vuelve a verificar cada accion) --}}
        @if ($cuenta['iniciada'] && ! $esPrestador)
            @include('corteconfeccion::admin.cartera.modales', ['esTaller' => $esTaller, 'puedeRegistrar' => $puedeRegistrar, 'puedePrestamos' => $puedePrestamos, 'puedeArranque' => $puedeArranque, 'puedeCorte' => $puedeCorte, 'lectorDisponible' => $lectorDisponible, 'maxArchivos' => $maxArchivos, 'cuenta' => $cuenta])
        @endif

        <div id="cartera-toast" class="fixed bottom-4 left-1/2 z-[10020] hidden -translate-x-1/2 rounded bg-gray-900 px-4 py-2 text-sm text-white shadow-lg" role="status"></div>
    </div>

    @if ($cuenta['iniciada'])
        @include('corteconfeccion::admin.cartera.scripts')
    @endif
</x-admin::layouts>
