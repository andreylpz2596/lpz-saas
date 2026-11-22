{{--
    Acciones CONTEXTUALES de envio en una fila/tarjeta de «Mis ventas / Pedidos». Variables: $pedido, $puedeDespachar,
    $puedeVerEnvio. El boton «Despachar» abre el MISMO drawer de Logistica (logistica::admin.despachos.componente) sin
    salir del listado; no hay logica de despacho aqui.

      pendiente  → «Despachar»  (recogida: «Entregar en bodega»)           solo con logistica.despachos.despachar
      parcial    → «Completar despacho» (recogida: «Entregar en bodega»)   solo con logistica.despachos.despachar
      despachado → «Ver envio»                                             solo con logistica.despachos
      Ver guia / Descargar guia: SOLO si hay PDF; el asesor las ve en sus pedidos (la ruta valida la propiedad).
--}}
@php
    $estadoEnvio = $pedido->estado_despacho;
    $esRecogida = $pedido->shipping_method === \ByLopez\Logistica\Support\PuntosRecogida::METODO;
    $conPdf = collect($pedido->guias ?? [])->firstWhere('tiene_pdf', true);
    $accionPrincipal = $esRecogida ? 'entregar_bodega' : ($estadoEnvio === 'parcial' ? 'completar' : 'despachar');
@endphp

<div class="flex flex-wrap items-center justify-end gap-1.5" data-acciones-envio="{{ $pedido->id }}">
    {{-- Mensaje de WhatsApp al cliente: el MISMO componente que Despachos y Entregas; el servidor ofrece solo lo que corresponde. --}}
    @if (! in_array($pedido->status ?? null, ['canceled', 'closed', 'fraud'], true))
        @include('logistica::admin.mensajes.boton', ['pedidoId' => $pedido->id, 'numero' => $pedido->increment_id])
    @endif

    @if ($puedeDespachar && in_array($estadoEnvio, ['pendiente', 'parcial'], true))
        <button type="button" class="primary-button" data-logistica-despacho data-pedido="{{ $pedido->id }}" data-accion="{{ $accionPrincipal }}">
            @lang('logistica::app.despachos.acciones.'.$accionPrincipal)
        </button>
    @endif

    @if ($puedeVerEnvio && in_array($estadoEnvio, ['despachado', 'parcial'], true))
        <button type="button" class="secondary-button" data-logistica-despacho data-pedido="{{ $pedido->id }}" data-modo="ver" data-accion="ver_envio">
            @lang('logistica::app.despachos.acciones.ver_envio')
        </button>
    @endif

    @if ($conPdf)
        <a href="{{ route('admin.ventas_asesor.pedidos.guia', ['pedido' => $pedido->id, 'guia' => $conPdf['id'], 'modo' => 'ver']) }}" target="_blank" rel="noopener noreferrer"
           class="icon-printer inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 bg-white text-xl text-blue-600 transition-all hover:bg-blue-50 dark:border-gray-800 dark:bg-gray-900 dark:hover:bg-gray-950"
           data-accion="ver_guia" title="@lang('logistica::app.despachos.acciones.ver_guia')" aria-label="@lang('logistica::app.despachos.acciones.ver_guia') #{{ $pedido->increment_id }}"></a>

        <a href="{{ route('admin.ventas_asesor.pedidos.guia', ['pedido' => $pedido->id, 'guia' => $conPdf['id'], 'modo' => 'descargar']) }}"
           class="icon-down-stat inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 bg-white text-xl text-blue-600 transition-all hover:bg-blue-50 dark:border-gray-800 dark:bg-gray-900 dark:hover:bg-gray-950"
           data-accion="descargar_guia" title="@lang('logistica::app.despachos.acciones.descargar_guia')" aria-label="@lang('logistica::app.despachos.acciones.descargar_guia') #{{ $pedido->increment_id }}"></a>
    @endif
</div>
