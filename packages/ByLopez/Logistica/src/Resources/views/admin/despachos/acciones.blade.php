{{--
    Acciones CONTEXTUALES de un pedido en la bandeja (sin llenar la fila de botones). Variables: $p (fila de
    ConsultaDespachoService), $puedeDespachar, $puedeVerGuia.

      sin despachar  → «Despachar»   (recogida: «Entregar en bodega»)
      parcial        → «Completar despacho» (recogida: «Entregar en bodega») + «Ver envio»
      despachado     → «Ver envio»  + «Ver guia» / «Descargar guia» solo si hay PDF
--}}
@php
    $conPdf = collect($p->guias)->firstWhere('tiene_pdf', true);
    $pendiente = $p->estado_despacho !== 'despachado';
    $etiquetaPrincipal = $p->es_recogida ? 'entregar_bodega' : ($p->estado_despacho === 'parcial' ? 'completar' : 'despachar');
@endphp

<div class="flex flex-wrap items-center justify-end gap-2" data-acciones="{{ $p->id }}">
    @if ($pendiente && $puedeDespachar)
        <button type="button" class="primary-button" data-logistica-despacho data-pedido="{{ $p->id }}" data-accion="{{ $etiquetaPrincipal }}">
            @lang('logistica::app.despachos.acciones.'.$etiquetaPrincipal)
        </button>
    @endif

    @if (! $pendiente || $p->estado_despacho === 'parcial')
        <button type="button" class="secondary-button" data-logistica-despacho data-pedido="{{ $p->id }}" data-modo="ver" data-accion="ver_envio">
            @lang('logistica::app.despachos.acciones.ver_envio')
        </button>
    @endif

    @if ($conPdf && $puedeVerGuia)
        <a href="{{ route('admin.logistica.guias.archivo', ['guia' => $conPdf['id'], 'modo' => 'ver']) }}" target="_blank" rel="noopener noreferrer"
           class="icon-printer inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 bg-white text-xl text-blue-600 hover:bg-blue-50 dark:border-gray-800 dark:bg-gray-900"
           data-accion="ver_guia" title="@lang('logistica::app.despachos.acciones.ver_guia')" aria-label="@lang('logistica::app.despachos.acciones.ver_guia') #{{ $p->increment_id }}"></a>

        <a href="{{ route('admin.logistica.guias.archivo', ['guia' => $conPdf['id'], 'modo' => 'descargar']) }}"
           class="icon-down-stat inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 bg-white text-xl text-blue-600 hover:bg-blue-50 dark:border-gray-800 dark:bg-gray-900"
           data-accion="descargar_guia" title="@lang('logistica::app.despachos.acciones.descargar_guia')" aria-label="@lang('logistica::app.despachos.acciones.descargar_guia') #{{ $p->increment_id }}"></a>
    @endif
</div>
