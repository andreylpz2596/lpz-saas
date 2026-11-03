{{--
    Lista principal de pedidos de comisión: UNA fila por pedido (número, fecha, unidades, comisión, situación) y «Ver cálculo» cerrado.
    Espera: $pedidos (de PanelComisionesService), $verCalculo (bool: solo administración autorizada recibe `calculo` por línea),
    $urlDetalle (callable order_id => url), opcional $mostrarAsesora (bool, con `asesora` en cada pedido).
    Los motivos particulares van junto al pedido afectado; los avisos generales se dicen una vez arriba (no aquí).
--}}
@php
    $dineroLp = fn ($v) => \ByLopez\Logistica\Support\Moneda::formatear((float) $v);
    $colorSit = ['bloqueada' => 'amarillo', 'habilitada' => 'verde', 'por_pagar' => 'indigo', 'pagada' => 'gris', 'dentro_del_umbral' => 'cian', 'sin_calcular' => 'rojo'];
    $textoMotivo = fn (string $m) => trans()->has('ventasasesor::comisiones.corto.'.$m)
        ? trans('ventasasesor::comisiones.corto.'.$m)
        : (trans()->has('ventasasesor::comisiones.motivos.'.$m) ? trans('ventasasesor::comisiones.motivos.'.$m) : trans('ventasasesor::comisiones.motivos.sin_distribucion'));
    $mostrarAsesora ??= false;
@endphp

<div class="grid gap-3" data-seccion="pedidos-mes">
    @forelse ($pedidos as $p)
        <article class="va-panel va-ped" data-pedido="{{ $p['order_id'] }}" data-situacion="{{ $p['situacion'] }}">
            <div class="va-ped__fila">
                <div>
                    <span class="va-ped__rot">@lang('ventasasesor::comisiones.panel.pedido')</span>
                    <a href="{{ $urlDetalle($p['order_id']) }}" class="va-ped__num text-blue-600 hover:underline">#{{ $p['increment_id'] }}</a>
                    @if ($mostrarAsesora && ! empty($p['asesora']))
                        <span class="va-sub block">{{ $p['asesora'] }}</span>
                    @endif
                </div>
                <div>
                    <span class="va-ped__rot">@lang('ventasasesor::comisiones.panel.fecha')</span>
                    <span class="va-sub">{{ \Illuminate\Support\Carbon::parse($p['confirmado_en'])->isoFormat('D MMM YYYY, HH:mm') }}</span>
                </div>
                <div>
                    <span class="va-ped__rot">@lang('ventasasesor::comisiones.panel.unidades-vendidas')</span>
                    <span>{{ $p['vendidas'] }}</span>
                </div>
                <div>
                    <span class="va-ped__rot">@lang('ventasasesor::comisiones.panel.unidades-comisionables')</span>
                    <span data-comisionables>{{ $p['comisionables'] }}</span>
                </div>
                <div>
                    <span class="va-ped__rot">@lang('ventasasesor::comisiones.panel.comision')</span>
                    {{-- Un importe desconocido NUNCA se muestra como $ 0: se dice que no se puede calcular. --}}
                    @if ($p['importe'] !== null)
                        <span class="va-ped__monto" data-importe>{{ $dineroLp($p['importe']) }}</span>
                    @else
                        <span class="va-ped__monto" data-importe="sin-calcular">
                            @lang('ventasasesor::comisiones.panel.sin-calcular')
                            @if ($p['importe_parcial'] !== null)
                                <span class="va-sub block">@lang('ventasasesor::comisiones.panel.comision-parcial', ['valor' => $dineroLp($p['importe_parcial'])])</span>
                            @endif
                        </span>
                    @endif
                </div>
                <div>
                    <span class="va-ped__rot">@lang('ventasasesor::comisiones.panel.situacion')</span>
                    <span class="va-pill va-pill--sm va-pill--{{ $colorSit[$p['situacion']] ?? 'gris' }}"><span>@lang('ventasasesor::comisiones.situacion.'.$p['situacion'])</span></span>
                </div>
            </div>

            @if ($p['motivos'] !== [])
                <ul class="va-ped__motivos" data-motivos>
                    @foreach ($p['motivos'] as $m)
                        <li data-motivo="{{ $m }}">⚠ {{ $textoMotivo($m) }}</li>
                    @endforeach
                </ul>
            @endif

            <details class="va-calculo" data-calculo>
                <summary>@lang('ventasasesor::comisiones.panel.ver-calculo')</summary>
                <div class="va-calculo__scroll">
                    <table class="va-calculo__tabla">
                        <thead>
                            <tr>
                                <th>@lang('ventasasesor::comisiones.referencia')</th>
                                <th>@lang('ventasasesor::comisiones.panel.unidades-vendidas')</th>
                                <th>@lang('ventasasesor::comisiones.panel.unidades-comisionables')</th>
                                @if ($verCalculo)
                                    <th>@lang('ventasasesor::comisiones.panel.precio-neto')</th>
                                    <th>@lang('ventasasesor::comisiones.panel.costo')</th>
                                    <th>@lang('ventasasesor::comisiones.panel.base')</th>
                                    <th>@lang('ventasasesor::comisiones.panel.porcentaje')</th>
                                @endif
                                <th>@lang('ventasasesor::comisiones.panel.comision')</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($p['lineas'] as $l)
                                <tr data-linea="{{ $l['id'] }}">
                                    <td>
                                        {{ $l['referencia'] ?? '—' }}<span class="va-sub block">{{ $l['sku'] }}</span>
                                        @if ($l['motivos'] !== [] && count($p['lineas']) > 1)
                                            <ul class="va-calculo__linea-motivos">
                                                @foreach ($l['motivos'] as $m)<li>⚠ {{ $textoMotivo($m) }}</li>@endforeach
                                            </ul>
                                        @endif
                                    </td>
                                    <td>{{ $l['vendidas'] }}</td>
                                    <td>{{ $l['comisionables'] ?? '—' }}</td>
                                    @if ($verCalculo)
                                        @php($c = $l['calculo'] ?? [])
                                        <td>{{ $dineroLp($c['precio_neto'] ?? 0) }}</td>
                                        <td>{{ ($c['costo'] ?? null) === null ? trans('ventasasesor::comisiones.panel.sin-dato') : $dineroLp($c['costo']) }}</td>
                                        <td>{{ ($c['base'] ?? null) === null ? trans('ventasasesor::comisiones.panel.sin-dato') : $dineroLp($c['base']) }}</td>
                                        <td>{{ ($c['porcentaje'] ?? null) === null ? trans('ventasasesor::comisiones.panel.sin-dato') : $c['porcentaje'].' %' }}</td>
                                    @endif
                                    <td><strong>{{ $l['importe'] === null ? trans('ventasasesor::comisiones.panel.sin-calcular') : $dineroLp($l['importe']) }}</strong></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($verCalculo)
                    <p class="va-calculo__formula">@lang('ventasasesor::comisiones.panel.formula')</p>
                @endif
            </details>
        </article>
    @empty
        <p class="va-panel p-4 text-sm text-slate-500" data-vacio>@lang('ventasasesor::comisiones.panel.sin-pedidos')</p>
    @endforelse
</div>
