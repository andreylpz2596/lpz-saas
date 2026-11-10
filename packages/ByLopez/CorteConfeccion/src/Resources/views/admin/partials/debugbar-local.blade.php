{{--
    Solo en desarrollo (APP_DEBUG) y solo en las pantallas del modulo que lo incluyen: la barra de Laravel Debugbar se
    pinta con z-index 10000000000 y tapaba el pie de las modales (x-admin::modal usa z-[10002]) y los botones finales en
    celular. Aqui queda por debajo de modales y cajones, y la pagina deja espacio para su barra minimizada. No toca el
    layout de Bagisto ni la configuracion de Debugbar; en produccion (sin Debugbar) no se emite nada.
--}}
@if (config('app.debug'))
    @push('styles')
        <style>
            div.phpdebugbar { z-index: 9000 !important; }
            /* En celular la barra minimizada ocupa dos lineas (~97 px medidos en WebKit/iPhone 13). */
            body:has(div.phpdebugbar) .cc-pagina { padding-bottom: 7rem; }
        </style>
    @endpush
@endif
