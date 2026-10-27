@php
    use ByLopez\VentasAsesor\Models\CostoDirecto;

    $dinero = fn ($v) => $v === null ? '—' : \ByLopez\Logistica\Support\Moneda::formatear((float) $v);
    $pct = fn ($v) => $v === null ? '—' : rtrim(rtrim((string) $v, '0'), '.').' %';
    $fecha = fn ($f) => $f ? \Illuminate\Support\Carbon::parse($f)->isoFormat('D MMM YYYY, HH:mm') : '—';
    $label = 'mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400';
    $input = 'w-full rounded-lg border border-slate-200 px-3 py-2 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300';
    $ahora = now();
    $estadoVersion = fn ($m) => ! ($m->activo ?? true) ? 'cancelada' : ($m->vigente_desde->gt($ahora) ? 'programada' : ($m->vigente_hasta !== null && $m->vigente_hasta->lte($ahora) ? 'cerrada' : 'vigente'));
    $colorVersion = ['vigente' => 'verde', 'programada' => 'cian', 'cerrada' => 'gris', 'cancelada' => 'gris'];
    $familia = fn ($id) => $id === null ? trans('ventasasesor::comisiones.rentabilidad.por-defecto') : ($familias[$id] ?? '#'.$id);
    $comunes = function () use ($label, $input) {
        return '<div class="md:col-span-3"><label class="'.$label.'">'.e(trans('ventasasesor::comisiones.vigente-desde')).'</label><input type="datetime-local" name="vigente_desde" class="'.$input.'"></div>'
            .'<div class="md:col-span-5"><label class="'.$label.'">'.e(trans('ventasasesor::comisiones.motivo')).'</label><input type="text" name="motivo" minlength="5" maxlength="500" required class="'.$input.'"></div>';
    };
@endphp

@once
    @push('styles')
        @include('ventasasesor::admin.cartera.estilos')
    @endpush
@endonce

<x-admin::layouts>
    <x-slot:title>@lang('ventasasesor::comisiones.rentabilidad.tabs.configuracion')</x-slot>

    @include('corteconfeccion::admin.partials.moneda')

    <div class="va-orders-page bylopez-scope">
        @include('ventasasesor::admin.rentabilidad.encabezado')

        <p class="va-panel mt-4 p-3 text-xs leading-5 text-slate-600 dark:text-gray-300">@lang('ventasasesor::comisiones.rentabilidad.configuracion-ayuda')</p>

        {{-- 1. Politicas de ganancia minima (opcion B: despues de comision y costos variables). --}}
        <div class="va-panel mt-4 p-4" data-seccion="politicas">
            <p class="text-base font-semibold text-gray-900 dark:text-white">@lang('ventasasesor::comisiones.rentabilidad.politicas')</p>
            <p class="va-sub">@lang('ventasasesor::comisiones.rentabilidad.politicas-ayuda')</p>

            <form method="POST" action="{{ route('admin.ventas_asesor.rentabilidad.politicas.store') }}" class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-12">
                @csrf
                <div class="md:col-span-3">
                    <label class="{{ $label }}">@lang('ventasasesor::comisiones.referencia')</label>
                    <select name="familia_id" class="custom-select {{ $input }}">
                        <option value="">@lang('ventasasesor::comisiones.rentabilidad.cualquier-referencia')</option>
                        @foreach ($familias as $id => $nombre)
                            <option value="{{ $id }}">{{ $nombre }}</option>
                        @endforeach
                    </select>
                </div>
                {{-- Doc. 23 §10.4: la familia de Bagisto no garantiza una sola tela; ambas opcionales, gana la mas especifica. --}}
                <div class="md:col-span-3">
                    <label class="{{ $label }}">@lang('ventasasesor::comisiones.rentabilidad.tipo-tela')</label>
                    <select name="tipo_tela_id" class="custom-select {{ $input }}">
                        <option value="">@lang('ventasasesor::comisiones.rentabilidad.cualquier-tela')</option>
                        @foreach ($panel['telas'] as $id => $nombre)
                            <option value="{{ $id }}">{{ $nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="md:col-span-2"><label class="{{ $label }}">@lang('ventasasesor::comisiones.rentabilidad.ganancia-minima')</label><input type="number" name="ganancia_minima" min="0" step="0.01" required data-moneda class="{{ $input }}"></div>
                <div class="md:col-span-2"><label class="{{ $label }}">@lang('ventasasesor::comisiones.rentabilidad.margen-minimo')</label><input type="number" name="margen_minimo" min="0" max="99.9999" step="0.0001" class="{{ $input }}"></div>
                <div class="md:col-span-2"><label class="{{ $label }}">@lang('ventasasesor::comisiones.rentabilidad.redondeo')</label><input type="number" name="redondeo" min="1" step="1" placeholder="100" class="{{ $input }}"></div>
                <p class="va-sub md:col-span-12">@lang('ventasasesor::comisiones.rentabilidad.politica-precedencia')</p>
                {!! $comunes() !!}
                <div class="flex items-end md:col-span-4"><button type="submit" class="primary-button w-full justify-center">@lang('ventasasesor::comisiones.guardar')</button></div>
            </form>

            <table class="mt-4 w-full text-xs">
                <tbody>
                    @forelse ($panel['politicas'] as $p)
                        <tr class="border-t border-slate-100 dark:border-gray-800">
                            <td class="py-1 pr-2">
                                {{ $p->familia_id === null && $p->tipo_tela_id === null ? trans('ventasasesor::comisiones.rentabilidad.por-defecto') : ($p->familia_id === null ? trans('ventasasesor::comisiones.rentabilidad.cualquier-referencia') : $familia($p->familia_id)) }}
                                @if ($p->tipo_tela_id !== null) · {{ $panel['telas'][$p->tipo_tela_id] ?? '#'.$p->tipo_tela_id }} @endif
                                · v{{ $p->version }}
                            </td>
                            <td class="py-1 pr-2">G {{ $dinero($p->ganancia_minima_unidad) }} · m {{ $pct($p->margen_minimo_pct) }} · ↑{{ $p->redondeo_escalon ?? '—' }}</td>
                            <td class="py-1 pr-2">{{ $fecha($p->vigente_desde) }} → {{ $p->vigente_hasta ? $fecha($p->vigente_hasta) : trans('ventasasesor::comisiones.abierta') }}</td>
                            <td class="py-1 pr-2"><span class="va-pill va-pill--sm va-pill--{{ $colorVersion[$estadoVersion($p)] }}"><span>@lang('ventasasesor::comisiones.rentabilidad.versiones.'.$estadoVersion($p))</span></span></td>
                            <td class="py-1">{{ $p->creado_por_name_snapshot }} · {{ $p->motivo }}</td>
                        </tr>
                    @empty
                        <tr><td class="py-2 text-amber-700 dark:text-amber-300">⚠ @lang('ventasasesor::comisiones.rentabilidad.sin-politicas')</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- 2. Costos directos de venta ESTIMADOS (no son costo del producto; se comparan despues con su fuente real). --}}
        <div class="va-panel mt-4 p-4" data-seccion="costos-directos">
            <p class="text-base font-semibold text-gray-900 dark:text-white">@lang('ventasasesor::comisiones.rentabilidad.costos-directos')</p>
            <p class="va-sub">@lang('ventasasesor::comisiones.rentabilidad.costos-directos-ayuda')</p>

            <form method="POST" action="{{ route('admin.ventas_asesor.rentabilidad.costos_directos.store') }}" class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-12">
                @csrf
                <div class="md:col-span-3">
                    <label class="{{ $label }}">@lang('ventasasesor::comisiones.rentabilidad.costo-existente')</label>
                    <select name="codigo" class="custom-select {{ $input }}">
                        <option value="">@lang('ventasasesor::comisiones.rentabilidad.costo-nuevo')</option>
                        @foreach ($panel['costos_directos']->unique('codigo') as $c)
                            <option value="{{ $c->codigo }}">{{ $c->nombre }} ({{ $c->codigo }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="md:col-span-3"><label class="{{ $label }}">@lang('ventasasesor::comisiones.rentabilidad.nombre')</label><input type="text" name="nombre" maxlength="120" required class="{{ $input }}"></div>
                <div class="md:col-span-2">
                    <label class="{{ $label }}">@lang('ventasasesor::comisiones.rentabilidad.concepto')</label>
                    <select name="concepto" class="custom-select {{ $input }}">
                        @foreach (CostoDirecto::CONCEPTOS as $c)
                            <option value="{{ $c }}">@lang('ventasasesor::comisiones.rentabilidad.conceptos.'.$c)</option>
                        @endforeach
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="{{ $label }}">@lang('ventasasesor::comisiones.rentabilidad.tipo-calculo')</label>
                    <select name="tipo_calculo" class="custom-select {{ $input }}">
                        @foreach (CostoDirecto::TIPOS as $t)
                            <option value="{{ $t }}">@lang('ventasasesor::comisiones.rentabilidad.tipos.'.$t)</option>
                        @endforeach
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="{{ $label }}">@lang('ventasasesor::comisiones.rentabilidad.base-porcentaje')</label>
                    <select name="base_porcentaje" class="custom-select {{ $input }}">
                        <option value="">—</option>
                        @foreach (CostoDirecto::BASES as $b)
                            <option value="{{ $b }}">@lang('ventasasesor::comisiones.rentabilidad.bases.'.$b)</option>
                        @endforeach
                    </select>
                </div>
                <div class="md:col-span-2"><label class="{{ $label }}">@lang('ventasasesor::comisiones.valor')</label><input type="number" name="valor" min="0" step="0.0001" required class="{{ $input }}"></div>
                <div class="md:col-span-3">
                    <label class="{{ $label }}">@lang('ventasasesor::comisiones.referencia')</label>
                    <select name="familia_id" class="custom-select {{ $input }}">
                        <option value="">@lang('ventasasesor::comisiones.rentabilidad.todas-referencias')</option>
                        @foreach ($familias as $id => $nombre)
                            <option value="{{ $id }}">{{ $nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="{{ $label }}">@lang('ventasasesor::comisiones.rentabilidad.metodo-envio')</label>
                    <select name="metodo_envio" class="custom-select {{ $input }}">
                        <option value="">@lang('ventasasesor::comisiones.todos')</option>
                        @foreach ($panel['metodos_envio'] as $codigo => $titulo)
                            <option value="{{ $codigo }}">{{ $titulo }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label class="{{ $label }}">@lang('ventasasesor::comisiones.rentabilidad.metodo-pago')</label>
                    <select name="metodo_pago" class="custom-select {{ $input }}">
                        <option value="">@lang('ventasasesor::comisiones.todos')</option>
                        @foreach ($panel['metodos_pago'] as $codigo => $titulo)
                            <option value="{{ $codigo }}">{{ $titulo }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="md:col-span-3">
                    <label class="{{ $label }}">@lang('ventasasesor::comisiones.rentabilidad.fuente-real')</label>
                    <select name="fuente_real" class="custom-select {{ $input }}">
                        @foreach (CostoDirecto::FUENTES_REALES as $f)
                            <option value="{{ $f }}">@lang('ventasasesor::comisiones.rentabilidad.fuentes.'.$f)</option>
                        @endforeach
                    </select>
                </div>
                {!! $comunes() !!}
                <div class="flex items-end md:col-span-4"><button type="submit" class="primary-button w-full justify-center">@lang('ventasasesor::comisiones.guardar')</button></div>
            </form>

            <table class="mt-4 w-full text-xs">
                <tbody>
                    @forelse ($panel['costos_directos'] as $c)
                        <tr class="border-t border-slate-100 dark:border-gray-800">
                            <td class="py-1 pr-2">{{ $c->nombre }} · v{{ $c->version }}<span class="va-sub block">@lang('ventasasesor::comisiones.rentabilidad.conceptos.'.$c->concepto) · @lang('ventasasesor::comisiones.rentabilidad.fuentes.'.$c->fuente_real)</span></td>
                            <td class="py-1 pr-2">{{ $c->tipo_calculo === 'porcentaje' ? $pct($c->valor).' · '.trans('ventasasesor::comisiones.rentabilidad.bases.'.$c->base_porcentaje) : $dinero($c->valor).' · '.trans('ventasasesor::comisiones.rentabilidad.tipos.'.$c->tipo_calculo) }}</td>
                            <td class="py-1 pr-2">{{ $c->familia_id ? $familia($c->familia_id) : trans('ventasasesor::comisiones.rentabilidad.todas-referencias') }}{{ $c->metodo_envio ? ' · '.$c->metodo_envio : '' }}{{ $c->metodo_pago ? ' · '.$c->metodo_pago : '' }}</td>
                            <td class="py-1 pr-2">{{ $fecha($c->vigente_desde) }} → {{ $c->vigente_hasta ? $fecha($c->vigente_hasta) : trans('ventasasesor::comisiones.abierta') }}</td>
                            <td class="py-1"><span class="va-pill va-pill--sm va-pill--{{ $colorVersion[$estadoVersion($c)] }}"><span>@lang('ventasasesor::comisiones.rentabilidad.versiones.'.$estadoVersion($c))</span></span></td>
                        </tr>
                    @empty
                        <tr><td class="py-2 text-slate-500">@lang('ventasasesor::comisiones.rentabilidad.sin-costos-directos')</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- 3. Estado del costo estandar por referencia (el valor sigue en Control de costos). --}}
        <div class="va-panel mt-4 p-4" data-seccion="estados-costo">
            <p class="text-base font-semibold text-gray-900 dark:text-white">@lang('ventasasesor::comisiones.rentabilidad.estados-costo')</p>
            <p class="va-sub">@lang('ventasasesor::comisiones.rentabilidad.estados-costo-ayuda')</p>

            <form method="POST" action="{{ route('admin.ventas_asesor.rentabilidad.estados_costo.store') }}" class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-12">
                @csrf
                <div class="md:col-span-4">
                    <label class="{{ $label }}">@lang('ventasasesor::comisiones.referencia')</label>
                    <select name="familia_id" required class="custom-select {{ $input }}">
                        @foreach ($familias as $id => $nombre)
                            <option value="{{ $id }}">{{ $nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="md:col-span-4">
                    <label class="{{ $label }}">@lang('ventasasesor::comisiones.estado')</label>
                    <select name="estado" class="custom-select {{ $input }}">
                        <option value="confirmado">@lang('ventasasesor::comisiones.estado-costo.confirmado')</option>
                        <option value="provisional">@lang('ventasasesor::comisiones.estado-costo.provisional')</option>
                    </select>
                </div>
                <div class="md:col-span-4"><label class="{{ $label }}">@lang('ventasasesor::comisiones.rentabilidad.revisar-hasta')</label><input type="date" name="revisar_hasta" class="{{ $input }}"></div>
                {!! $comunes() !!}
                <div class="flex items-end md:col-span-4"><button type="submit" class="primary-button w-full justify-center">@lang('ventasasesor::comisiones.guardar')</button></div>
            </form>

            <table class="mt-4 w-full text-xs">
                <tbody>
                    @forelse ($panel['estados_costo'] as $e)
                        <tr class="border-t border-slate-100 dark:border-gray-800">
                            <td class="py-1 pr-2">{{ $familia($e->familia_id) }} · v{{ $e->version }}</td>
                            <td class="py-1 pr-2">@lang('ventasasesor::comisiones.estado-costo.'.$e->estado){{ $e->revisar_hasta ? ' · '.trans('ventasasesor::comisiones.rentabilidad.revisar-hasta').' '.$e->revisar_hasta->toDateString() : '' }}</td>
                            <td class="py-1 pr-2">{{ $fecha($e->vigente_desde) }} → {{ $e->vigente_hasta ? $fecha($e->vigente_hasta) : trans('ventasasesor::comisiones.abierta') }}</td>
                            <td class="py-1">{{ $e->creado_por_name_snapshot }} · {{ $e->motivo }}</td>
                        </tr>
                    @empty
                        <tr><td class="py-2 text-slate-500">@lang('ventasasesor::comisiones.rentabilidad.sin-estados')</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-admin::layouts>
