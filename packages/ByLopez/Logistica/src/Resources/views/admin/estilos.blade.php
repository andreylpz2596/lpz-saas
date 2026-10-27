{{-- Estilos de Logistica (prefijo lg-): mismo lenguaje visual que Ventas Asesor, sin depender de ese paquete. --}}
<style>
    .lg-page { margin: -16px; min-height: calc(100vh - 64px); background: #f8fafc; padding: 16px; }
    .dark .lg-page { background: #030712; }
    .lg-panel { border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; box-shadow: 0 1px 2px rgba(15, 23, 42, .04); }
    .dark .lg-panel { border-color: #1f2937; background: #111827; }
    .lg-kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
    .lg-kpi { padding: 14px 16px; }
    .lg-kpi-label { font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: #64748b; }
    .lg-kpi-value { margin-top: 4px; font-size: 22px; font-weight: 800; color: #0f172a; }
    .dark .lg-kpi-value { color: #fff; }
    .lg-pill { display: inline-flex; align-items: center; padding: 2px 9px; border: 1px solid; border-radius: 999px; font-size: 11px; font-weight: 700; line-height: 1.3; }
    .lg-pill--verde { background: #dcfce7; border-color: #86efac; color: #166534; }
    .lg-pill--amarillo { background: #fef3c7; border-color: #fcd34d; color: #92400e; }
    .lg-pill--rojo { background: #ffe4e6; border-color: #fda4af; color: #9f1239; }
    .lg-pill--gris { background: #f1f5f9; border-color: #cbd5e1; color: #475569; }
    .lg-pill--azul { background: #e0e7ff; border-color: #a5b4fc; color: #3730a3; }
    .lg-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .lg-table th, .lg-table td { padding: 12px 10px; text-align: left; vertical-align: middle; }
    .lg-table th { color: #475569; font-size: 11px; font-weight: 700; text-transform: uppercase; background: #f8fafc; }
    .lg-table tbody tr { border-top: 1px solid #f1f5f9; }
    .dark .lg-table th { color: #d1d5db; background: #030712; }
    .dark .lg-table tbody tr { border-color: #1f2937; }
    .lg-num { text-align: right !important; font-variant-numeric: tabular-nums; }
    .lg-sub { font-size: 12px; color: #64748b; }
    .lg-caption { font-size: 10px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: #94a3b8; }
    .lg-input { width: 100%; border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px 10px; font-size: 13px; color: #374151; background: #fff; }
    .dark .lg-input { border-color: #1f2937; background: #111827; color: #d1d5db; }
    .lg-btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 7px 12px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; font-size: 12px; font-weight: 700; color: #1e293b; cursor: pointer; text-decoration: none; }
    .lg-btn:hover { background: #f1f5f9; }
    .lg-btn--ok { border-color: #16a34a; background: #16a34a; color: #fff; }
    .lg-btn--ok:hover { background: #15803d; }
    .lg-btn--aviso { border-color: #d97706; color: #92400e; }
    .lg-btn--no { border-color: #e11d48; color: #be123c; }
    .dark .lg-btn { background: #111827; border-color: #374151; color: #e5e7eb; }
    .dark .lg-btn--ok { background: #16a34a; border-color: #16a34a; color: #fff; }
    .lg-filters { display: grid; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr) auto; gap: 10px; align-items: end; }
    .lg-kpis--3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .lg-filters--4 { display: grid; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr) minmax(0, 1fr) auto; gap: 10px; align-items: end; }
    .lg-clamp { max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .lg-desktop { display: block; }
    .lg-mobile { display: none; }
    .lg-grid2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
    @media (max-width: 1023px) {
        .lg-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .lg-desktop { display: none; }
        .lg-mobile { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        .lg-filters { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); }
        .lg-filters > :first-child { grid-column: span 2 / span 2; }
    }
    @media (max-width: 1023px) {
        .lg-filters--4 { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); }
        .lg-filters--4 > :first-child { grid-column: span 2 / span 2; }
    }
    @media (max-width: 767px) {
        .lg-kpis--3 { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }
        .lg-kpis--3 .lg-kpi { padding: 10px; }
        .lg-kpis--3 .lg-kpi-label { font-size: 9px; }
        .lg-filters--4 { grid-template-columns: minmax(0, 1fr); }
        .lg-filters--4 > :first-child { grid-column: auto; }
        .lg-page { margin: -12px; padding: 12px; }
        .lg-mobile, .lg-grid2 { grid-template-columns: minmax(0, 1fr); }
        .lg-filters { grid-template-columns: minmax(0, 1fr); }
        .lg-filters > :first-child { grid-column: auto; }
    }
    /* Entregas: estados, tipo de producto y cifras compactas (no dependen del Tailwind compilado). */
    .lg-estados { display: grid; gap: 4px; }
    .lg-estado { display: flex; align-items: center; gap: 6px; }
    .lg-estado-tit { min-width: 64px; font-size: 10px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: #94a3b8; }
    .lg-pill--punto::before { content: ""; width: 6px; height: 6px; margin-right: 5px; border-radius: 50%; background: currentColor; }
    .lg-pill--lila { background: #f3e8ff; border-color: #d8b4fe; color: #6b21a8; }
    .lg-tipo { display: inline-flex; align-items: center; gap: 5px; font-weight: 600; color: #1f2937; }
    .lg-tipo [class^="icon-"] { font-size: 16px; color: #64748b; }
    .dark .lg-tipo { color: #f3f4f6; }
    .lg-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 6px; text-align: center; }
    .lg-stat b { display: block; font-size: 15px; font-variant-numeric: tabular-nums; color: #0f172a; }
    .lg-stat span { display: block; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; color: #94a3b8; }
    .lg-stat--pend b { color: #b45309; }
    .lg-stat--cero b { color: #94a3b8; }
    .dark .lg-stat b { color: #f9fafb; }
    .lg-stats--grande .lg-stat { padding: 10px 6px; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; }
    .lg-stats--grande .lg-stat b { font-size: 22px; }
    .dark .lg-stats--grande .lg-stat { border-color: #1f2937; background: #111827; }
    .lg-acciones { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 6px; }
    .lg-nota-fina { margin: 0; font-size: 12px; color: #64748b; }
    .lg-seccion { margin: 20px 0 8px; font-size: 12px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: #475569; }
    .dark .lg-seccion { color: #9ca3af; }
    .lg-info { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px 16px; padding: 14px; }
    .lg-info dt { font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: #94a3b8; }
    .lg-info dd { margin: 2px 0 0; font-size: 13px; color: #1f2937; overflow-wrap: anywhere; }
    .dark .lg-info dd { color: #f3f4f6; }
    .lg-info .lg-span2 { grid-column: span 2 / span 2; }
    .lg-prods { display: grid; gap: 10px; }
    .lg-prod-cab { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 2px 12px; }
    .lg-tallas { display: grid; gap: 4px; margin-top: 8px; }
    .lg-talla { display: grid; grid-template-columns: 56px repeat(4, minmax(0, 1fr)); gap: 6px; align-items: center; padding: 5px 8px; border-radius: 8px; background: #f8fafc; font-size: 13px; font-variant-numeric: tabular-nums; }
    .lg-talla b { font-weight: 700; }
    .lg-talla small { display: block; font-size: 9px; font-weight: 700; text-transform: uppercase; color: #94a3b8; }
    .lg-talla--pend { background: #fffbeb; }
    .dark .lg-talla { background: #030712; }
    .dark .lg-talla--pend { background: #451a03; }
    @media (max-width: 767px) {
        .lg-info { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .lg-stats--grande .lg-stat b { font-size: 18px; }
    }
    /* Drawer unico de Entregas (lge-): no depende del Tailwind compilado del admin. */
    .lge-titulo { margin: 0; font-size: 18px; font-weight: 700; color: #1f2937; }
    .lge-sub { margin: 0; font-size: 13px; color: #64748b; }
    .dark .lge-titulo { color: #fff; }
    .lge-pila { display: grid; gap: 14px; padding: 4px 0; }
    .lge-estados { display: flex; flex-wrap: wrap; gap: 6px; }
    .lge-sec { display: flex; align-items: center; gap: 6px; margin: 0 0 6px; font-size: 11px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: #475569; }
    .lge-sec [class^="icon-"] { font-size: 18px; color: #64748b; }
    .dark .lge-sec { color: #9ca3af; }
    .lge-nota { margin: 0; font-size: 12px; color: #64748b; }
    .lge-nota-reingreso { margin-top: 6px; font-weight: 600; }
    .lge-error { margin: 0; font-size: 12px; font-weight: 600; color: #be123c; }
    .lge-error--caja { padding: 8px 10px; border-radius: 8px; background: #ffe4e6; }
    .dark .lge-error { color: #fda4af; }
    .dark .lge-error--caja { background: #4c0519; }
    .lge-invalido { border-color: #e11d48 !important; box-shadow: 0 0 0 1px #e11d48; }
    .lge-label { display: block; margin-bottom: 4px; font-size: 12px; font-weight: 600; color: #1f2937; }
    .dark .lge-label { color: #fff; }
    .lge-grid2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
    .lge-alinear-fin { display: flex; align-items: flex-end; }
    .lge-banner { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border: 1px solid; border-radius: 10px; }
    .lge-banner [class^="icon-"] { font-size: 20px; flex-shrink: 0; }
    .lge-banner-cuerpo { flex: 1; min-width: 0; }
    .lge-banner-tit { margin: 0; font-size: 13px; font-weight: 700; }
    .lge-banner--aviso { background: #fffbeb; border-color: #fcd34d; color: #78350f; }
    .lge-banner--rojo { background: #fff1f2; border-color: #fda4af; color: #9f1239; }
    .dark .lge-banner--aviso { background: #451a03; border-color: #b45309; color: #fef3c7; }
    .dark .lge-banner--rojo { background: #4c0519; border-color: #be123c; color: #fecdd3; }
    .lge-banner .lge-nota { color: inherit; opacity: .85; }
    .lge-desglose { margin: 0; padding: 8px 10px; border-radius: 8px; background: rgba(255, 255, 255, .6); font-size: 13px; }
    .dark .lge-desglose { background: rgba(0, 0, 0, .25); }
    .lge-desglose > div { display: flex; justify-content: space-between; gap: 12px; padding: 2px 0; }
    .lge-desglose dd { margin: 0; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .lge-desglose-linea { padding-left: 12px !important; font-size: 12px; opacity: .8; }
    .lge-desglose-linea dt { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .lge-desglose-total { margin-top: 4px; padding-top: 6px !important; border-top: 1px solid currentColor; font-weight: 700; }
    .lge-prod { padding: 12px; }
    .lge-prod-nombre { margin: 0; font-weight: 700; color: #1f2937; }
    .dark .lge-prod-nombre { color: #fff; }
    .lge-fila-titulo { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 4px 12px; }
    .lge-enlace { padding: 0; border: 0; background: none; font-size: 13px; font-weight: 600; color: #2563eb; cursor: pointer; }
    .lge-enlace:hover { text-decoration: underline; }
    .lge-enlace--rojo { color: #dc2626; margin-top: 4px; }
    .lge-acciones { display: flex; flex-wrap: wrap; gap: 10px; }
    .lge-lineas { display: grid; gap: 6px; margin-top: 8px; }
    .lge-linea { display: grid; grid-template-columns: 1fr 64px 96px; gap: 8px; align-items: center; padding: 4px 8px; border-radius: 8px; background: #f8fafc; }
    .lge-linea-talla { font-size: 14px; }
    .lge-linea-pend small, .lge-calc small, .lge-recep-campos label small { display: block; font-size: 9px; font-weight: 700; text-transform: uppercase; color: #94a3b8; }
    .lge-linea-pend { text-align: center; font-variant-numeric: tabular-nums; }
    .dark .lge-linea { background: #030712; }
    .lge-cant { min-height: 40px; text-align: right; font-size: 15px; font-weight: 700; }
    .lge-recep { display: grid; gap: 8px; margin-top: 8px; }
    .lge-recep-linea { padding: 8px; border-radius: 8px; background: #f8fafc; display: grid; gap: 6px; }
    .lge-recep-dif { background: #fffbeb; }
    .dark .lge-recep-linea { background: #030712; }
    .dark .lge-recep-dif { background: #451a03; }
    .lge-recep-cab { display: flex; justify-content: space-between; align-items: baseline; }
    .lge-recep-campos { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px; align-items: end; }
    .lge-calc { text-align: center; font-variant-numeric: tabular-nums; }
    .lge-calc b { display: block; padding-bottom: 8px; font-size: 16px; }
    .lge-resumen { padding: 12px; border: 1px solid #e2e8f0; border-radius: 10px; display: grid; gap: 4px; }
    .lge-resumen--dif { border-color: #fcd34d; background: #fffbeb; }
    .dark .lge-resumen { border-color: #1f2937; }
    .dark .lge-resumen--dif { background: #451a03; border-color: #b45309; }
    .lge-resumen-fila { display: flex; justify-content: space-between; font-size: 14px; font-variant-numeric: tabular-nums; }
    .lge-resumen-ok b { color: #166534; }
    .lge-resumen-dif b { color: #b45309; }
    .lge-hist { list-style: none; margin: 0; padding: 0 0 0 14px; border-left: 2px solid #e2e8f0; display: grid; gap: 10px; }
    .dark .lge-hist { border-color: #1f2937; }
    .lge-hist-item { position: relative; }
    .lge-hist-item::before { content: ""; position: absolute; left: -20px; top: 5px; width: 10px; height: 10px; border-radius: 50%; background: #94a3b8; }
    .lge-hist-entrega::before { background: #16a34a; }
    .lge-hist-devolucion::before { background: #dc2626; }
    .lge-hist-recepcion::before { background: #d97706; }
    .lge-hist-anulado { opacity: .6; }
    .lge-hist-cab { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 8px; }
    .lge-hist-cab b { font-size: 13px; }
    .lge-detalle { margin: 2px 0 0; font-size: 12px; color: #334155; overflow-wrap: anywhere; }
    .dark .lge-detalle { color: #cbd5e1; }
    .lge-anular { display: grid; gap: 6px; margin-top: 6px; }
    .lge-pie { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 8px; padding: 10px 16px; border-top: 1px solid #e5e7eb; }
    .dark .lge-pie { border-color: #1f2937; }
    .lge-principal { min-height: 40px; font-size: 13px; }
    .lg-kpis--5 { grid-template-columns: repeat(5, minmax(0, 1fr)); }
    .lg-kpis--6 { grid-template-columns: repeat(6, minmax(0, 1fr)); }
    @media (max-width: 1023px) { .lg-kpis--5, .lg-kpis--6 { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 524px) {
        .lge-grid2 { grid-template-columns: minmax(0, 1fr); }
        .lge-recep-campos { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .lge-pie { padding: 10px 12px; }
        .lge-pie .lg-btn { flex: 1 1 auto; }
        .lge-banner { flex-wrap: wrap; }
    }
    /* Cuentas de transportadoras (lgc-): saldo en palabras, nunca solo por color. */
    .lgc-nombre { font-weight: 700; color: #0f172a; }
    .dark .lgc-nombre { color: #fff; }
    .lgc-monto { font-size: 15px; font-weight: 800; font-variant-numeric: tabular-nums; color: #0f172a; white-space: nowrap; }
    .dark .lgc-monto { color: #f9fafb; }
    .lgc-monto--grande { font-size: 24px; }
    .lgc-card { padding: 16px; display: grid; gap: 6px; }
    .lgc-hero { display: grid; gap: 2px; padding: 16px; border: 1px solid #e2e8f0; border-left-width: 5px; border-radius: 12px; background: #fff; }
    .lgc-hero--debe_entregar { border-left-color: #d97706; }
    .lgc-hero--bylopez_paga { border-left-color: #4f46e5; }
    .lgc-hero--al_dia { border-left-color: #16a34a; }
    .dark .lgc-hero { border-color: #1f2937; background: #111827; }
    .lgc-hero-monto { margin: 0; font-size: 34px; line-height: 1.1; font-weight: 800; font-variant-numeric: tabular-nums; color: #0f172a; }
    .dark .lgc-hero-monto { color: #fff; }
    .lgc-hero-texto { margin: 0; font-size: 14px; font-weight: 700; color: #334155; }
    .dark .lgc-hero-texto { color: #e5e7eb; }
    .lgc-tabs { display: flex; gap: 4px; border-bottom: 1px solid #e2e8f0; }
    .dark .lgc-tabs { border-color: #1f2937; }
    .lgc-tab { padding: 8px 12px; border: 0; border-bottom: 2px solid transparent; background: none; font-size: 13px; font-weight: 700; color: #64748b; cursor: pointer; }
    .lgc-tab--activa { border-bottom-color: #2563eb; color: #1d4ed8; }
    .lgc-filtros { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)) auto; gap: 8px; align-items: end; }
    .lgc-grupo { border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; }
    .dark .lgc-grupo { border-color: #1f2937; }
    .lgc-grupo-cab { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 2px 12px; padding: 8px 12px; background: #f8fafc; font-size: 12px; }
    .dark .lgc-grupo-cab { background: #030712; }
    .lgc-mov { display: grid; grid-template-columns: 74px minmax(0, 1.6fr) minmax(0, 1fr) minmax(0, 1fr) 96px; gap: 8px; align-items: center; padding: 8px 12px; border-top: 1px solid #f1f5f9; font-size: 13px; }
    .dark .lgc-mov { border-color: #1f2937; }
    .lgc-mov--cab { border-top: 0; font-size: 10px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: #94a3b8; }
    .lgc-mov-concepto b { display: block; font-weight: 700; color: #1f2937; }
    .dark .lgc-mov-concepto b { color: #f3f4f6; }
    .lgc-mas { color: #92400e; }
    .lgc-menos { color: #3730a3; }
    .dark .lgc-mas { color: #fcd34d; }
    .dark .lgc-menos { color: #a5b4fc; }
    .lgc-resumen { display: grid; gap: 6px; padding: 14px 16px; }
    .lgc-resumen-fila { display: flex; justify-content: space-between; gap: 12px; font-size: 14px; font-variant-numeric: tabular-nums; }
    .lgc-resumen-total { padding-top: 8px; border-top: 1px dashed #cbd5e1; font-weight: 800; font-size: 15px; text-transform: uppercase; }
    .lgc-hist { padding: 12px 14px; display: grid; gap: 6px; }
    .lgc-hist-cab { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 4px 12px; }
    .lgc-cifras { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 6px 12px; font-size: 12px; }
    .lgc-cifras b { display: block; font-size: 14px; font-variant-numeric: tabular-nums; color: #0f172a; }
    .dark .lgc-cifras b { color: #f9fafb; }
    @media (max-width: 767px) {
        .lgc-filtros { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); }
        .lgc-mov { grid-template-columns: minmax(0, 1fr) auto; gap: 2px 10px; }
        .lgc-mov--cab { display: none; }
        .lgc-mov-fecha, .lgc-mov-estado, .lgc-mov-efecto { font-size: 11px; color: #64748b; }
        .lgc-mov-concepto { grid-column: 1; grid-row: 1; }
        .lgc-mov-valor { grid-column: 2; grid-row: 1; text-align: right; }
        .lgc-mov-fecha { grid-column: 1; grid-row: 2; }
        .lgc-mov-estado { grid-column: 2; grid-row: 2; text-align: right; }
        .lgc-mov-efecto { grid-column: 1 / span 2; grid-row: 3; }
        .lgc-hero-monto { font-size: 28px; }
        .lgc-cifras { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .lge-pie .lg-btn { min-height: 42px; }
    }
    /* El modal de confirmacion de Bagisto (open-confirm-modal) comparte z-index con los drawers y, al abrirse desde un drawer, queda a medias
       debajo de el (se cortaba el boton «Confirmar»). En las pantallas de Logistica se sube por encima: solo presentacion. */
    div.fixed.inset-0.z-\[10002\] { z-index: 10010; }

    /* Indicadores compactables (movil): el checkbox guarda el estado sin JavaScript. Compacto = solo los que piden accion (--atencion). */
    .lg-kpis-wrap { position: relative; }
    .lg-kpis-toggle { position: absolute; width: 1px; height: 1px; opacity: 0; }
    .lg-kpis-btn { display: none; }
    .lg-kpis-btn-ocultar { display: none; }
    @media (max-width: 767px) {
        .lg-kpis-btn { display: inline-flex; min-height: 44px; margin-bottom: 8px; cursor: pointer; }
        .lg-kpis-toggle:checked ~ .lg-kpis .lg-kpi:not(.lg-kpi--atencion) { display: none; }
        .lg-kpis-toggle:not(:checked) ~ .lg-kpis-btn .lg-kpis-btn-ver { display: none; }
        .lg-kpis-toggle:not(:checked) ~ .lg-kpis-btn .lg-kpis-btn-ocultar { display: inline; }
        .lg-kpis-toggle:focus-visible ~ .lg-kpis-btn { outline: 2px solid #2563eb; outline-offset: 2px; }
    }

    /* Foco visible por teclado en las pantallas de Logistica. */
    .lg-page :is(a, button, input, select, textarea, summary):focus-visible,
    .lge-pila :is(a, button, input, select, textarea, summary):focus-visible,
    .lge-pie :is(a, button):focus-visible { outline: 2px solid #2563eb; outline-offset: 2px; }
    .dark .lg-page :is(a, button, input, select, textarea, summary):focus-visible,
    .dark .lge-pila :is(a, button, input, select, textarea, summary):focus-visible,
    .dark .lge-pie :is(a, button):focus-visible { outline-color: #93c5fd; }
</style>
