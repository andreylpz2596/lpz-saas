{{--
    Doc. 35 · Panel COMPACTO de cupos dentro de Cartera (bandeja). Solo clientes con cupo configurado; las cifras salen de
    CupoCarteraPanelService (derivadas de Cartera, por lote). No es un tablero aparte: un resumen y los clientes que conviene mirar.
    Variables: $cupoPanel (array del servicio), $dinero (closure).
--}}
@php
    $tonoPanel = ['sin_cupo' => 'gris', 'activo' => 'verde', 'cerca' => 'amarillo', 'agotado' => 'rojo', 'excedido' => 'rojo', 'bloqueado' => 'rojo'];
    $pctPanel = fn (?float $p) => $p === null ? '—' : rtrim(rtrim(number_format($p, 1, ',', '.'), '0'), ',').' %';
@endphp

@pushOnce('styles')
<style>
    .va-cupo-panel { margin-top: 16px; padding: 14px 16px; }
    .va-cupo-panel-cifras { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px 14px; margin: 12px 0 0; }
    @media (min-width: 640px) { .va-cupo-panel-cifras { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
    @media (min-width: 1100px) { .va-cupo-panel-cifras { grid-template-columns: repeat(6, minmax(0, 1fr)); } }
    .va-cupo-panel-cifras dt { font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: #64748b; }
    .va-cupo-panel-cifras dd { margin: 2px 0 0; font-size: 20px; font-weight: 800; color: #0f172a; line-height: 1.2; word-break: break-word; }
    .dark .va-cupo-panel-cifras dd { color: #fff; }
    .va-cupo-panel-cifras .va-cupo-rojo { color: #be123c; }
    .dark .va-cupo-panel-cifras .va-cupo-rojo { color: #fda4af; }
    .va-cupo-panel-cifras small { display: block; font-size: 11px; font-weight: 500; color: #64748b; }
    .va-cupo-panel-listas { display: grid; grid-template-columns: minmax(0, 1fr); gap: 14px 24px; margin-top: 14px; }
    @media (min-width: 900px) { .va-cupo-panel-listas { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    .va-cupo-panel-sub { margin: 0; font-size: 12px; font-weight: 700; color: #334155; }
    .dark .va-cupo-panel-sub { color: #e5e7eb; }
    .va-cupo-panel-lista { margin: 6px 0 0; padding: 0; list-style: none; }
    .va-cupo-panel-lista li { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 4px 10px; padding: 7px 0; border-top: 1px solid #f1f5f9; font-size: 13px; }
    .dark .va-cupo-panel-lista li { border-color: #1f2937; }
    .va-cupo-panel-lista a.va-cupo-cli { font-weight: 700; color: #1d4ed8; text-decoration: underline; min-width: 0; overflow-wrap: anywhere; }
    .dark .va-cupo-panel-lista a.va-cupo-cli { color: #93c5fd; }
    .va-cupo-panel-lista .va-cupo-num { font-weight: 700; white-space: nowrap; }
</style>
@endpushOnce

<section class="va-panel va-cupo-panel" data-cupo-panel aria-labelledby="cupo-panel-titulo">
    <div style="display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:6px 12px">
        <div>
            <h2 class="va-cupo-titulo" id="cupo-panel-titulo">@lang('ventasasesor::cupo.panel.titulo')</h2>
            <p class="va-sub">@lang('ventasasesor::cupo.panel.subtitulo')</p>
        </div>
        @if (! $cupoPanel['bloqueo_activo'])
            <span class="va-pill va-pill--gris va-pill--sm" data-cupo-panel-no-bloquea>@lang('ventasasesor::cupo.panel.no_bloquea')</span>
        @endif
    </div>

    <dl class="va-cupo-panel-cifras">
        <div><dt>@lang('ventasasesor::cupo.panel.con_cupo')</dt><dd data-cupo-panel-con-cupo>{{ $cupoPanel['con_cupo'] }}</dd></div>
        <div><dt>@lang('ventasasesor::cupo.panel.cerca')</dt><dd data-cupo-panel-cerca>{{ $cupoPanel['cerca'] }}</dd></div>
        <div><dt>@lang('ventasasesor::cupo.panel.agotados')</dt><dd data-cupo-panel-agotados>{{ $cupoPanel['agotados'] }}</dd></div>
        <div><dt>@lang('ventasasesor::cupo.panel.excedidos')</dt><dd class="{{ $cupoPanel['excedidos'] > 0 ? 'va-cupo-rojo' : '' }}" data-cupo-panel-excedidos>{{ $cupoPanel['excedidos'] }}</dd></div>
        <div><dt>@lang('ventasasesor::cupo.panel.bloqueados')</dt><dd class="{{ $cupoPanel['bloqueados'] > 0 ? 'va-cupo-rojo' : '' }}" data-cupo-panel-bloqueados>{{ $cupoPanel['bloqueados'] }}</dd></div>
        <div>
            <dt>@lang('ventasasesor::cupo.panel.expuesto')</dt>
            <dd data-cupo-panel-expuesto>{{ $dinero($cupoPanel['exposicion_total']) }}</dd>
            <small>@lang('ventasasesor::cupo.panel.de', ['autorizado' => $dinero($cupoPanel['autorizado_total'])])</small>
        </div>
    </dl>

    <div class="va-cupo-panel-listas">
        <div>
            <p class="va-cupo-panel-sub">@lang('ventasasesor::cupo.panel.alertas')</p>
            <ul class="va-cupo-panel-lista" data-cupo-panel-alertas>
                @forelse ($cupoPanel['alertas'] as $a)
                    <li data-cupo-panel-fila="{{ $a['customer_id'] }}">
                        <a class="va-cupo-cli" href="{{ route('admin.ventas_asesor.cartera.index', ['cliente' => $a['customer_id']]) }}">{{ $a['nombre'] }}</a>
                        <span class="va-pill va-pill--sm va-pill--{{ $tonoPanel[$a['estado']] ?? 'gris' }}">@lang('ventasasesor::cupo.estados.'.$a['estado'])</span>
                        <span class="va-cupo-num">
                            @if ($a['exceso'] > 0)
                                @lang('ventasasesor::cupo.panel.exceso_de', ['monto' => $dinero($a['exceso'])])
                            @else
                                {{ $dinero($a['exposicion']) }} / {{ $dinero($a['autorizado']) }} · {{ $pctPanel($a['utilizacion_pct']) }}
                            @endif
                        </span>
                    </li>
                @empty
                    <li class="va-sub" data-cupo-panel-sin-alertas>@lang('ventasasesor::cupo.panel.sin_alertas')</li>
                @endforelse
            </ul>
        </div>

        <div>
            <p class="va-cupo-panel-sub">@lang('ventasasesor::cupo.panel.mayores')</p>
            <ul class="va-cupo-panel-lista" data-cupo-panel-mayores>
                @forelse ($cupoPanel['mayores_saldos'] as $m)
                    <li data-cupo-panel-saldo="{{ $m['customer_id'] }}">
                        <a class="va-cupo-cli" href="{{ route('admin.ventas_asesor.cartera.index', ['cliente' => $m['customer_id']]) }}">{{ $m['nombre'] }}</a>
                        <span class="va-pill va-pill--sm va-pill--{{ $tonoPanel[$m['estado']] ?? 'gris' }}">@lang('ventasasesor::cupo.estados.'.$m['estado'])</span>
                        <span class="va-cupo-num">{{ $dinero($m['exposicion']) }}</span>
                    </li>
                @empty
                    <li class="va-sub">@lang('ventasasesor::cupo.panel.sin_alertas')</li>
                @endforelse
            </ul>
        </div>
    </div>
</section>
