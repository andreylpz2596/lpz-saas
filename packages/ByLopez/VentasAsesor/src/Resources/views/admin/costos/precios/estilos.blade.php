{{-- Doc. 32: estilos propios de «Precios por grupo» (el build de Tailwind del panel no escanea ByLopez: clases .pg-* aqui). --}}
<style>
    .pg-grid { display: grid; gap: 10px; grid-template-columns: repeat(12, minmax(0, 1fr)); align-items: end; }
    .pg-c2 { grid-column: span 2 / span 2; } .pg-c3 { grid-column: span 3 / span 3; } .pg-c4 { grid-column: span 4 / span 4; }
    .pg-c6 { grid-column: span 6 / span 6; } .pg-c12 { grid-column: span 12 / span 12; }
    @media (max-width: 1023px) { .pg-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } .pg-grid > * { grid-column: span 1 / span 1; } .pg-grid > .pg-ancho { grid-column: span 2 / span 2; } }
    @media (max-width: 479px) { .pg-grid { grid-template-columns: minmax(0, 1fr); } .pg-grid > *, .pg-grid > .pg-ancho { grid-column: span 1 / span 1; } }

    .pg-label { display: block; margin-bottom: 6px; font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: #64748b; }
    .dark .pg-label { color: #9ca3af; }
    .pg-input { width: 100%; min-height: 40px; border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px 10px; font-size: 14px; color: #1f2937; background: #fff; }
    .dark .pg-input { border-color: #1f2937; background: #111827; color: #e5e7eb; }
    .pg-input:disabled { opacity: .55; }
    .pg-check { display: inline-flex; align-items: center; gap: 8px; min-height: 40px; font-size: 14px; color: #334155; cursor: pointer; }
    .dark .pg-check { color: #d1d5db; }
    .pg-check input { width: 18px; height: 18px; }

    .pg-barra { position: sticky; top: 62px; z-index: 5; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px 16px; padding: 10px 14px; }
    .pg-grupo { display: inline-flex; align-items: center; gap: 8px; font-size: 15px; font-weight: 800; color: #0f172a; }
    .dark .pg-grupo { color: #fff; }
    .pg-grupo small { font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: #64748b; }
    .pg-seleccion { font-size: 13px; color: #334155; }
    .dark .pg-seleccion { color: #d1d5db; }
    .pg-seleccion strong { font-size: 15px; }

    .pg-ref { margin-top: 10px; }
    .pg-ref > summary { list-style: none; display: grid; grid-template-columns: auto minmax(0, 1fr) auto auto; gap: 10px 14px; align-items: center; padding: 12px 14px; cursor: pointer; }
    .pg-ref > summary::-webkit-details-marker { display: none; }
    .pg-ref > summary .pg-flecha { transition: transform .15s ease; color: #64748b; }
    .pg-ref[open] > summary .pg-flecha { transform: rotate(90deg); }
    .pg-ref-nombre { font-size: 15px; font-weight: 800; color: #0f172a; }
    .dark .pg-ref-nombre { color: #fff; }
    .pg-chips { display: flex; flex-wrap: wrap; gap: 4px 6px; margin-top: 4px; }
    .pg-chip { display: inline-block; padding: 1px 8px; border-radius: 999px; background: #f1f5f9; font-size: 11px; font-weight: 600; color: #475569; }
    .dark .pg-chip { background: #1f2937; color: #d1d5db; }
    .pg-resumen { text-align: right; }
    @media (max-width: 767px) {
        .pg-ref > summary { grid-template-columns: auto minmax(0, 1fr) auto; }
        .pg-resumen { grid-column: 2 / span 2; text-align: left; }
    }

    .pg-tabla { width: 100%; border-collapse: collapse; font-size: 13px; }
    .pg-tabla th { padding: 8px 10px; text-align: left; font-size: 11px; font-weight: 700; letter-spacing: .02em; text-transform: uppercase; color: #475569; background: #f8fafc; }
    .dark .pg-tabla th { color: #d1d5db; background: #030712; }
    .pg-tabla td { padding: 10px; vertical-align: top; border-top: 1px solid #f1f5f9; color: #1f2937; }
    .dark .pg-tabla td { border-color: #1f2937; color: #e5e7eb; }
    .pg-tabla .pg-num { text-align: right; white-space: nowrap; }
    .pg-tabla tr.pg-editor td { background: #f8fafc; }
    .dark .pg-tabla tr.pg-editor td { background: #030712; }
    @media (max-width: 767px) {
        .pg-tabla thead { display: none; }
        .pg-tabla, .pg-tabla tbody, .pg-tabla tr, .pg-tabla td { display: block; width: 100%; }
        .pg-tabla tr { border-top: 1px solid #e2e8f0; padding: 8px 0; }
        .dark .pg-tabla tr { border-color: #1f2937; }
        .pg-tabla td { border: 0; padding: 4px 12px; }
        .pg-tabla td[data-etiqueta]::before { content: attr(data-etiqueta); display: block; font-size: 10px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: #94a3b8; }
        .pg-tabla .pg-num { text-align: left; }
        /* Fila de talla compacta: casilla a la izquierda y los datos en dos columnas. */
        .pg-tabla tr[data-variante] { display: grid; grid-template-columns: 32px repeat(2, minmax(0, 1fr)); column-gap: 4px; }
        .pg-tabla tr[data-variante] > td:has(> [data-sel-var]) { grid-row: span 4; padding-top: 8px; }
        .pg-tabla tr[data-variante] > td { grid-column: auto; }
        .pg-tabla tr[data-variante] > td:last-child { grid-column: 2 / -1; }
    }

    .pg-escalones { margin: 0; padding: 0; list-style: none; display: grid; gap: 2px; }
    .pg-escalones li { white-space: nowrap; }
    .pg-tachado { text-decoration: line-through; opacity: .7; }
    .pg-muted { color: #64748b; }
    .dark .pg-muted { color: #9ca3af; }
    .pg-fuerte { font-weight: 800; color: #0f172a; }
    .dark .pg-fuerte { color: #fff; }
    .pg-rojo { color: #be123c; } .dark .pg-rojo { color: #fda4af; }
    .pg-verde { color: #15803d; } .dark .pg-verde { color: #86efac; }

    .pg-editor-caja { display: grid; gap: 12px; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); }
    @media (max-width: 1023px) { .pg-editor-caja { grid-template-columns: minmax(0, 1fr); } }
    .pg-form-linea { display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-end; }
    .pg-form-linea > label { flex: 1 1 110px; }
    .pg-form-linea .pg-input { min-height: 44px; }
    .pg-form-linea .va-btn, .pg-form-linea .primary-button { min-height: 44px; }

    .pg-kpis { display: grid; gap: 10px; grid-template-columns: repeat(5, minmax(0, 1fr)); }
    @media (max-width: 1023px) { .pg-kpis { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
    @media (max-width: 479px) { .pg-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } }

    .pg-aviso { padding: 10px 12px; border-radius: 8px; border: 1px solid #fcd34d; background: #fffbeb; color: #92400e; font-size: 13px; line-height: 1.5; }
    .dark .pg-aviso { border-color: #92400e; background: #451a03; color: #fcd34d; }
    .pg-aviso--rojo { border-color: #fda4af; background: #fff1f2; color: #9f1239; }
    .dark .pg-aviso--rojo { border-color: #9f1239; background: #4c0519; color: #fda4af; }
    .pg-ayuda { font-size: 13px; line-height: 1.55; color: #334155; }
    .dark .pg-ayuda { color: #d1d5db; }
    .pg-ayuda ul { margin: 6px 0 0 18px; list-style: disc; }

    .pg-acciones-barra { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
    .pg-acciones-barra .va-btn { min-height: 40px; }
    .pg-oculto { display: none !important; }
    .pg-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
    .pg-sticky-pie { position: sticky; bottom: 0; z-index: 5; display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 10px; padding: 12px 14px; }
    @media (max-width: 479px) { .pg-sticky-pie > * { flex: 1 1 100%; justify-content: center; } }
</style>
