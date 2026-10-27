{{-- Estilos de «Cartera del cliente»: mismo lenguaje visual que «Mis ventas» (pills de estado, paneles, tabla compacta). --}}
<style>
    .va-orders-page { margin: -16px; min-height: calc(100vh - 64px); background: #f8fafc; padding: 16px; }
    .dark .va-orders-page { background: #030712; }

    .va-panel { border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04); }
    .dark .va-panel { border-color: #1f2937; background: #111827; }

    .va-pill { display: inline-flex; align-items: center; gap: 5px; max-width: 100%; padding: 3px 10px 3px 8px; border: 1px solid; border-radius: 999px; font-size: 12px; font-weight: 700; line-height: 1.2; }
    .va-pill svg { flex: none; width: 13px; height: 13px; fill: none; stroke: currentColor; stroke-width: 2.4; stroke-linecap: round; stroke-linejoin: round; }
    .va-pill--sm { padding: 1px 8px 1px 6px; font-size: 11px; font-weight: 600; }
    .va-pill--sm svg { width: 11px; height: 11px; }
    .va-pill--verde { background: #dcfce7; border-color: #86efac; color: #166534; }
    .va-pill--amarillo { background: #fef3c7; border-color: #fcd34d; color: #92400e; }
    .va-pill--cian { background: #cffafe; border-color: #67e8f9; color: #155e75; }
    .va-pill--indigo { background: #e0e7ff; border-color: #a5b4fc; color: #3730a3; }
    .va-pill--rojo { background: #ffe4e6; border-color: #fda4af; color: #9f1239; }
    .va-pill--gris { background: #f1f5f9; border-color: #cbd5e1; color: #475569; }
    .dark .va-pill--verde { background: #052e16; border-color: #166534; color: #86efac; }
    .dark .va-pill--amarillo { background: #451a03; border-color: #92400e; color: #fcd34d; }
    .dark .va-pill--cian { background: #083344; border-color: #155e75; color: #67e8f9; }
    .dark .va-pill--indigo { background: #1e1b4b; border-color: #4338ca; color: #a5b4fc; }
    .dark .va-pill--rojo { background: #4c0519; border-color: #9f1239; color: #fda4af; }
    .dark .va-pill--gris { background: #1f2937; border-color: #374151; color: #d1d5db; }

    .va-kpi-box { padding: 14px 16px; }
    .va-kpi-box .va-kpi-label { font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: #64748b; }
    .va-kpi-box .va-kpi-value { margin-top: 4px; font-size: 22px; font-weight: 800; line-height: 1.2; color: #0f172a; }
    .dark .va-kpi-box .va-kpi-value { color: #fff; }
    .va-kpi-box--saldo { border-color: #fcd34d; background: #fffbeb; }
    .dark .va-kpi-box--saldo { border-color: #92400e; background: #451a03; }

    .va-orders-table { width: 100%; table-layout: fixed; border-collapse: collapse; }
    .va-orders-table th, .va-orders-table td { padding: 14px 10px; vertical-align: middle; }
    .va-orders-table th { color: #475569; font-size: 11px; font-weight: 700; letter-spacing: .02em; text-transform: uppercase; }
    .va-orders-table tbody tr { border-top: 1px solid #f1f5f9; transition: background-color .16s ease; }
    .va-orders-table tbody tr:hover { background: rgba(239, 246, 255, .62); }
    .dark .va-orders-table th { color: #d1d5db; }
    .dark .va-orders-table tbody tr { border-color: #1f2937; }
    .dark .va-orders-table tbody tr:hover { background: #030712; }

    .va-order-id { font-size: 15px; font-weight: 800; line-height: 1.2; }
    .va-sub { margin-top: 2px; font-size: 12px; color: #64748b; }
    .va-money { font-size: 14px; font-weight: 600; }
    .va-money--strong { font-size: 15px; font-weight: 800; }
    .va-stack { display: flex; flex-direction: column; align-items: flex-start; gap: 4px; }
    .va-caption { font-size: 10px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: #94a3b8; }
    .dark .va-sub { color: #9ca3af; }

    .va-desktop-table { display: block; }
    .va-mobile-orders { display: none; }

    @media (max-width: 1023px) {
        .va-desktop-table { display: none; }
        .va-mobile-orders { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }

    @media (max-width: 767px) {
        .va-orders-page { margin: -12px; padding: 12px; }
        .va-mobile-orders { grid-template-columns: minmax(0, 1fr); }
    }

    /* Bandeja Cartera / Pagos: KPI clicables, filtros y botones de accion */
    .va-kpi { position: relative; display: block; cursor: pointer; text-decoration: none; transition: box-shadow .16s ease, border-color .16s ease, background-color .16s ease; }
    .va-kpi:hover { box-shadow: 0 2px 8px rgba(15, 23, 42, .08); }
    .va-kpi:focus-visible { outline: 2px solid #2563eb; outline-offset: 2px; }
    .va-kpi.is-active { border-color: #2563eb; box-shadow: 0 0 0 2px rgba(37, 99, 235, .25); background: #eff6ff; }
    .dark .va-kpi.is-active { background: #1e3a8a; }
    .va-kpi-check { display: none; position: absolute; top: 10px; right: 10px; width: 20px; height: 20px; align-items: center; justify-content: center; border-radius: 999px; color: #fff; background: #2563eb; }
    .va-kpi-check svg { width: 12px; height: 12px; fill: none; stroke: currentColor; stroke-width: 3; stroke-linecap: round; stroke-linejoin: round; }
    .va-kpi.is-active .va-kpi-check { display: inline-flex; }

    .va-filters-layout { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 10px; align-items: end; }
    .va-filter-search { grid-column: span 4 / span 4; }
    .va-filter-field { grid-column: span 2 / span 2; }
    .va-filter-actions { grid-column: span 2 / span 2; display: flex; justify-content: flex-end; gap: 10px; }
    @media (max-width: 1023px) {
        .va-filters-layout { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .va-filter-search, .va-filter-field, .va-filter-actions { grid-column: span 1 / span 1; }
        .va-filter-search { grid-column: span 2 / span 2; }
        .va-filter-actions { grid-column: span 2 / span 2; }
    }
    @media (max-width: 479px) {
        .va-filters-layout { display: block; }
        .va-filters-layout > * { margin-top: 10px; }
    }

    .va-acciones { display: flex; flex-wrap: wrap; gap: 6px; }
    .va-btn { display: inline-flex; align-items: center; justify-content: center; padding: 6px 12px; border-radius: 8px; border: 1px solid #cbd5e1; background: #fff; font-size: 12px; font-weight: 700; color: #1e293b; cursor: pointer; line-height: 1.2; }
    .va-btn:hover { background: #f1f5f9; }
    .va-btn--ok { border-color: #16a34a; background: #16a34a; color: #fff; }
    .va-btn--ok:hover { background: #15803d; }
    .va-btn--no { border-color: #e11d48; background: #fff; color: #be123c; }
    .va-btn--no:hover { background: #fff1f2; }
    .dark .va-btn { background: #111827; border-color: #374151; color: #e5e7eb; }
    .dark .va-btn--ok { background: #16a34a; border-color: #16a34a; color: #fff; }
    .dark .va-btn--no { background: #111827; border-color: #e11d48; color: #fda4af; }
    @media (max-width: 1023px) { .va-acciones .va-btn { flex: 1 1 30%; padding: 9px 10px; } }

    /* Modal de abono: lineas de distribucion */
    .va-linea { display: grid; grid-template-columns: minmax(0, 1fr) 150px; gap: 10px; align-items: center; padding: 8px 0; border-top: 1px solid #f1f5f9; }
    .dark .va-linea { border-color: #1f2937; }
    @media (max-width: 480px) { .va-linea { grid-template-columns: minmax(0, 1fr) 120px; } }

    /* Modal de revision de pagos: datos del pago como pares «etiqueta / valor». */
    .va-pago-detalle { display: grid; gap: 6px; margin: 0; padding: 10px 12px; border-radius: 8px; background: #f1f5f9; }
    .va-pago-detalle > div { display: flex; justify-content: space-between; gap: 12px; }
    .va-pago-detalle dt { color: #475569; }
    .va-pago-detalle dd { margin: 0; font-weight: 700; text-align: right; color: #0f172a; word-break: break-word; }
    .dark .va-pago-detalle { background: #030712; }
    .dark .va-pago-detalle dt { color: #9ca3af; }
    .dark .va-pago-detalle dd { color: #f9fafb; }

    /* Encabezado de pantalla con una accion (p. ej. «Reevaluar pendientes»): en pantallas estrechas la accion pasa DEBAJO del titulo, completa. */
    .va-encab { display: flex; flex-wrap: wrap; align-items: flex-start; gap: 12px 16px; }
    .va-encab__texto { flex: 1 1 14rem; min-width: 0; }
    .va-encab__accion { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; }
    @media (max-width: 524px) {
        .va-encab__accion { width: 100%; align-items: flex-start; }
        .va-encab__accion .secondary-button { min-height: 44px; }
    }
    .va-tabs { display: flex; gap: 4px; overflow-x: auto; white-space: nowrap; }

    /* Foco visible por teclado en toda la pantalla (el build de Tailwind del admin no trae las utilidades focus-visible de ByLopez). */
    .va-orders-page :is(a, button, input, select, textarea, summary):focus-visible { outline: 2px solid #2563eb; outline-offset: 2px; }
    .dark .va-orders-page :is(a, button, input, select, textarea, summary):focus-visible { outline-color: #93c5fd; }

    /* Dos cifras que son etapas del MISMO importe (estimada → habilitada): se leen juntas, nunca como una suma. */
    .va-etapas { display: flex; flex-wrap: wrap; gap: 6px 20px; font-size: 14px; }
    .va-etapas__nota { flex-basis: 100%; font-size: 12px; color: #64748b; }
    .dark .va-etapas__nota { color: #9ca3af; }

    /* Filtros secundarios plegables (movil): el checkbox guarda el estado sin JavaScript. Plegar solo OCULTA los campos (siguen en el
       formulario con su valor): no borra ni cambia ningun filtro. La insignia dice cuantos filtros secundarios hay activos. */
    .va-filtros-check { position: absolute; width: 1px; height: 1px; opacity: 0; }
    .va-filtros-btn { display: none; }
    .va-filtros-btn-menos { display: none; }
    .va-filtros-insignia { display: inline-block; min-width: 20px; padding: 0 6px; border-radius: 999px; background: #2563eb; color: #fff; font-size: 11px; font-weight: 700; line-height: 20px; text-align: center; }
    @media (max-width: 767px) {
        .va-filtros-btn { display: inline-flex; align-items: center; gap: 8px; min-height: 44px; margin-bottom: 10px; cursor: pointer; }
        .va-filtros-check:not(:checked) ~ .va-filters-layout .va-filter-sec { display: none; }
        .va-filtros-check:checked ~ .va-filtros-btn .va-filtros-btn-mas { display: none; }
        .va-filtros-check:checked ~ .va-filtros-btn .va-filtros-btn-menos { display: inline; }
        .va-filtros-check:focus-visible ~ .va-filtros-btn { outline: 2px solid #2563eb; outline-offset: 2px; }
    }
</style>
