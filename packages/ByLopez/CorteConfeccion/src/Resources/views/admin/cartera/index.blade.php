@include('corteconfeccion::admin.partials.estilos')

<x-admin::layouts>
    <x-slot:title>Cartera de prestadores</x-slot>

    @php
        $fm = fn ($v) => \ByLopez\CorteConfeccion\Support\Dinero::formatear((string) $v);
    @endphp

    <div class="bylopez-scope grid gap-4">
        <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
            <h1 class="text-xl font-bold leading-6 text-gray-800 dark:text-white">Cartera de cortadores y satélites</h1>
        </div>

        @if (! $arranque)
            <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                <p class="text-sm font-semibold text-gray-800 dark:text-white">La cartera todavía no tiene fecha de arranque</p>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Hasta definirla no se calculan cuentas ni se registran movimientos. Todo lo anterior a esa fecha entra como saldo inicial por persona.</p>

                @if ($puedeArranque)
                    <form id="cartera-arranque" class="mt-3 flex flex-wrap items-end gap-3" data-url="{{ route('admin.corte_confeccion.cartera.arranque') }}" data-csrf="{{ csrf_token() }}">
                        <div class="grid gap-1">
                            <label for="arranque-fecha" class="text-sm font-medium text-gray-700 dark:text-gray-300">Fecha de arranque</label>
                            <input id="arranque-fecha" type="date" required class="rounded-lg border px-3 py-2 text-base dark:border-gray-800 dark:bg-gray-900 dark:text-white sm:text-sm">
                        </div>
                        <button type="submit" class="primary-button">Iniciar cartera</button>
                        <p class="w-full text-sm text-red-600" data-r="error" hidden></p>
                    </form>
                @endif
            </div>
        @else
            <p class="text-sm text-gray-600 dark:text-gray-300">Cartera iniciada el <b>{{ $arranque->format('d-m-Y') }}</b>. Los saldos anteriores a esa fecha se cargan como saldo inicial en la ficha de cada persona.</p>
        @endif

        <div class="box-shadow overflow-x-auto rounded bg-white p-4 dark:bg-gray-900">
            <table class="w-full min-w-[640px] text-left text-sm">
                <thead class="text-xs uppercase text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="px-2 py-2 font-medium">Persona</th>
                        <th class="px-2 py-2 font-medium">Tipo</th>
                        <th class="px-2 py-2 text-right font-medium">Trabajo estimado</th>
                        <th class="px-2 py-2 text-right font-medium">Resultado</th>
                        <th class="px-2 py-2 text-right font-medium">Préstamo pendiente</th>
                    </tr>
                </thead>
                <tbody class="text-gray-800 dark:text-gray-200">
                    @forelse ($filas as $fila)
                        @php $c = $fila['cuenta']; @endphp
                        <tr class="border-t dark:border-gray-800">
                            <td class="px-2 py-2"><a class="font-semibold text-blue-600 hover:underline" href="{{ route('admin.corte_confeccion.cartera.cuenta', ['tipo' => $fila['tipo'], 'id' => $fila['prestador']->id]) }}">{{ $fila['prestador']->nombre }}</a></td>
                            <td class="px-2 py-2">{{ $fila['tipo'] === 'taller' ? 'Satélite / taller' : 'Cortador' }}</td>
                            <td class="px-2 py-2 text-right tabular-nums">{{ $c['iniciada'] ? $fm($c['trabajo_estimado']) : '—' }}</td>
                            <td class="px-2 py-2 text-right tabular-nums">
                                @if (! $c['iniciada'])
                                    —
                                @elseif ($c['a_favor_bylopez'])
                                    <span class="font-semibold">{{ $fm(\ByLopez\CorteConfeccion\Support\Dinero::absoluto($c['por_pagar_estimado'])) }}</span> <span class="text-xs text-gray-500">a favor de ByLopez</span>
                                @else
                                    <span class="font-semibold">{{ $fm($c['por_pagar_estimado']) }}</span> <span class="text-xs text-gray-500">faltaría pagar</span>
                                @endif
                            </td>
                            <td class="px-2 py-2 text-right tabular-nums">{{ $c['iniciada'] ? $fm($c['prestamo_pendiente']) : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-2 py-4 text-gray-500">No hay talleres ni cortadores registrados.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @pushOnce('scripts')
        <script>
            (function () {
                // Delegacion en document: Bagisto monta Vue despues de este script y puede reemplazar los nodos.
                document.addEventListener('submit', function (e) {
                    var f = e.target.closest('#cartera-arranque');
                    if (! f) { return; }
                    e.preventDefault();
                    var error = f.querySelector('[data-r="error"]');
                    error.hidden = true;

                    fetch(f.dataset.url, {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': f.dataset.csrf },
                        body: JSON.stringify({ fecha: f.querySelector('#arranque-fecha').value })
                    }).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); }).then(function (x) {
                        if (x.ok) { window.location.reload(); return; }
                        error.textContent = (x.d.errors ? Object.values(x.d.errors).flat().join(' ') : x.d.message) || 'No se pudo guardar.';
                        error.hidden = false;
                    }).catch(function () { error.textContent = 'No se pudo contactar al servidor.'; error.hidden = false; });
                });
            })();
        </script>
    @endPushOnce
</x-admin::layouts>
