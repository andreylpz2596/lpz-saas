@php
    $dinero = fn ($v) => $v === null ? '—' : \ByLopez\Logistica\Support\Moneda::formatear((float) $v);
    $nu = fn ($v) => number_format((int) $v, 0, ',', '.');
    $situaciones = ['bloqueada', 'habilitada', 'por_pagar', 'pagada', 'dentro_del_umbral', 'sin_calcular'];
    $colorEtapa = ['bloqueada' => 'amarillo', 'habilitada' => 'verde', 'por_pagar' => 'indigo', 'pagada' => 'gris'];
    $variasAsesoras = count($panel['asesoras']) > 1;
    $hayFiltroSec = $situacion !== null || ($asesores !== [] && $asesor !== null);
@endphp

@once
    @push('styles')
        @include('ventasasesor::admin.cartera.estilos')
        @include('ventasasesor::admin.comisiones.estilos-panel')
    @endpush
@endonce

<x-admin::layouts>
    <x-slot:title>@lang('ventasasesor::comisiones.titulo')</x-slot>

    <div class="va-orders-page bylopez-scope">
        @include('ventasasesor::admin.comisiones.encabezado')

        {{-- Filtros: una sola fila compacta en escritorio; en el celular el boton los pliega (el estado no se pierde al plegar). --}}
        <div class="va-panel mt-4 p-4" data-seccion="filtros">
            <input type="checkbox" id="va-f-toggle" class="va-fcheck" @checked($hayFiltroSec)>
            <label for="va-f-toggle" class="va-fbtn">@lang('ventasasesor::comisiones.panel.filtros') @if ($hayFiltroSec)<span class="va-filtros-insignia">{{ ($situacion !== null ? 1 : 0) + ($asesor !== null && $asesores !== [] ? 1 : 0) }}</span>@endif</label>
            <form method="GET" action="{{ route('admin.ventas_asesor.comisiones.index') }}" class="va-fcompacto">
                <div class="va-fcompacto__campo">
                    <label for="f-mes">@lang('ventasasesor::comisiones.mes-comercial')</label>
                    <input id="f-mes" type="month" name="mes" value="{{ $mes }}">
                </div>
                @if ($asesores !== [])
                    <div class="va-fcompacto__campo va-fcompacto__campo--ancho">
                        <label for="f-asesor">@lang('ventasasesor::comisiones.asesor')</label>
                        <select id="f-asesor" name="asesor" class="custom-select">
                            <option value="">@lang('ventasasesor::comisiones.todos')</option>
                            @foreach ($asesores as $id => $nombre)
                                <option value="{{ $id }}" @selected($asesor === (int) $id)>{{ $nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div class="va-fcompacto__campo">
                    <label for="f-situacion">@lang('ventasasesor::comisiones.panel.situacion')</label>
                    <select id="f-situacion" name="situacion" class="custom-select">
                        <option value="">@lang('ventasasesor::comisiones.todos')</option>
                        @foreach ($situaciones as $e)
                            <option value="{{ $e }}" @selected($situacion === $e)>@lang('ventasasesor::comisiones.situacion.'.$e)</option>
                        @endforeach
                    </select>
                </div>
                <div class="va-fcompacto__campo" style="flex:0 0 auto">
                    <button type="submit" class="secondary-button justify-center">@lang('ventasasesor::comisiones.filtrar')</button>
                </div>
            </form>
        </div>

        {{-- Avisos GENERALES: una sola vez aqui; los motivos de cada pedido van junto al pedido. --}}
        @if (! $panel['comisiones_activas'])
            <p class="va-aviso mt-4" data-aviso="comisiones-inactivas" role="status">⚠ @lang('ventasasesor::comisiones.panel.inactivas')</p>
        @endif
        @php
            $avisosCuenta = [];
            foreach ($panel['asesoras'] as $a) {
                foreach (array_diff($a['avisos'], ['sin_fecha_inicio']) as $m) {
                    $avisosCuenta[] = ['asesora' => $a['asesora'], 'motivo' => $m];
                }
                $a['total_parcial'] && $avisosCuenta[] = ['asesora' => $a['asesora'], 'motivo' => 'total_parcial', 'n' => $a['sin_calcular']];
            }
        @endphp
        @if ($avisosCuenta !== [])
            <div class="va-aviso mt-4" data-aviso="cuenta" role="status">
                <strong>@lang('ventasasesor::comisiones.panel.avisos-titulo')</strong>
                <ul>
                    @foreach ($avisosCuenta as $av)
                        <li data-aviso-motivo="{{ $av['motivo'] }}">
                            @if ($variasAsesoras)<strong>{{ $av['asesora'] }}:</strong> @endif
                            {{ $av['motivo'] === 'total_parcial' ? trans('ventasasesor::comisiones.panel.total-parcial', ['n' => $av['n']]) : (trans()->has('ventasasesor::comisiones.motivos.'.$av['motivo']) ? trans('ventasasesor::comisiones.motivos.'.$av['motivo']) : $av['motivo']) }}
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Resumen por asesora y mes: umbral del acuerdo, avance, unidades computables y comisionables; etapas que se reparten el MISMO importe. --}}
        <div class="mt-4 grid grid-cols-1 gap-3 xl:grid-cols-2" data-seccion="resumen-mes">
            @forelse ($panel['asesoras'] as $r)
                @php($u = $r['umbral'])
                <section class="va-panel va-asesora" data-asesora="{{ $r['asesor_id'] }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="text-base font-semibold text-gray-900 dark:text-white">{{ $r['asesora'] }}</p>
                        <span class="va-pill va-pill--sm va-pill--{{ $u['estado'] === 'definido' ? 'verde' : 'amarillo' }}"><span>@lang('ventasasesor::comisiones.umbral-estados.'.$u['estado'])</span></span>
                    </div>

                    <dl class="va-asesora__cifras">
                        <div><dt>@lang('ventasasesor::comisiones.umbral')</dt><dd>{{ $u['valor'] === null ? '—' : $nu($u['valor']) }}</dd></div>
                        <div><dt>@lang('ventasasesor::comisiones.computables')</dt><dd data-cifra="computables">{{ $nu($u['computables']) }}</dd></div>
                        <div><dt>@lang('ventasasesor::comisiones.faltan')</dt><dd>{{ $u['faltante'] === null ? '—' : $nu($u['faltante']) }}</dd></div>
                        <div><dt>@lang('ventasasesor::comisiones.comisionables')</dt><dd data-cifra="comisionables">{{ $nu($u['comisionables']) }}</dd></div>
                    </dl>

                    @if ($u['avance'] !== null)
                        <div class="va-barra" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $u['avance'] }}" aria-label="{{ trans('ventasasesor::comisiones.avance.barra', ['pct' => $u['avance']]) }}">
                            <div class="va-barra__relleno {{ $u['avance'] >= 100 ? 'va-barra__relleno--ok' : '' }}" style="width: {{ $u['avance'] }}%"></div>
                        </div>
                    @endif

                    {{-- Estimada y habilitada son etapas del MISMO importe: aqui se reparten en cuatro y se pueden sumar. --}}
                    <div class="va-etapas mt-3" data-etapas="comision">
                        @foreach (['bloqueada', 'habilitada', 'por_pagar', 'pagada'] as $e)
                            <span data-etapa="{{ $e }}">@lang('ventasasesor::comisiones.mis.etapas.'.$e): <strong>{{ $dinero($r['etapas'][$e]) }}</strong></span>
                        @endforeach
                        <p class="va-etapas__nota">
                            @lang('ventasasesor::comisiones.panel.etapas-nota')
                            <strong data-total-mes>{{ $dinero($r['total_mes']) }}@if ($r['total_parcial']) + @lang('ventasasesor::comisiones.panel.sin-calcular')@endif</strong>
                        </p>
                    </div>

                    <div class="mt-2 flex flex-wrap items-center justify-between gap-2 text-sm">
                        <span>@lang('ventasasesor::comisiones.panel.saldo-aprobado'): <strong data-saldo-aprobado>{{ $dinero($r['saldo_aprobado']) }}</strong></span>
                        <a class="text-blue-600 hover:underline" href="{{ route('admin.ventas_asesor.comisiones.index', ['vista' => 'liquidaciones', 'asesor' => $r['asesor_id'], 'mes' => $mes]) }}">@lang('ventasasesor::comisiones.panel.ver-cuenta')</a>
                    </div>
                </section>
            @empty
                <p class="va-panel p-4 text-sm text-slate-500">@lang('ventasasesor::comisiones.sin-lineas-mes')</p>
            @endforelse
        </div>

        <div class="mt-4">
            @include('ventasasesor::admin.comisiones.partials.lista-pedidos', [
                'pedidos' => $panel['pedidos'],
                'verCalculo' => $panel['ve_calculo'],
                'mostrarAsesora' => $variasAsesoras || $asesor === null,
                'urlDetalle' => fn ($id) => route('admin.ventas_asesor.comisiones.index', ['pedido' => $id]),
            ])
        </div>
    </div>
</x-admin::layouts>
