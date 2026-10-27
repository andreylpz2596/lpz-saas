{{-- Estilos de Cargues (prefijo cg-), sin build: mismo patron que Logistica. Colores del mockup aprobado; modo oscuro con `.dark`. --}}
<style>
    .cg-page { margin: -16px; min-height: calc(100vh - 64px); padding: 16px; background: #f8fafc; }
    .dark .cg-page { background: #030712; }
    .cg-panel { border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; box-shadow: 0 1px 2px rgba(15, 23, 42, .04); }
    .dark .cg-panel { border-color: #1f2937; background: #111827; }
    .cg-title { font-size: 28px; font-weight: 700; line-height: 1.2; color: #0f172a; }
    .dark .cg-title { color: #fff; }
    .cg-sub { font-size: 14px; color: #64748b; }
    .dark .cg-sub { color: #9ca3af; }
    .cg-caption { font-size: 12px; font-weight: 600; color: #475569; }
    .dark .cg-caption { color: #d1d5db; }

    /* Pestañas */
    .cg-tabs { display: flex; gap: 8px; border-bottom: 1px solid #e2e8f0; }
    .dark .cg-tabs { border-color: #1f2937; }
    .cg-tab { padding: 10px 14px; font-size: 14px; font-weight: 600; color: #475569; border-bottom: 2px solid transparent; background: none; cursor: pointer; margin-bottom: -1px; }
    .cg-tab:hover { color: #1d4ed8; }
    .cg-tab[aria-selected="true"] { color: #1d4ed8; border-bottom-color: #2563eb; }
    .dark .cg-tab { color: #9ca3af; } .dark .cg-tab[aria-selected="true"], .dark .cg-tab:hover { color: #93c5fd; border-bottom-color: #3b82f6; }

    /* Distribución: tarjetas a la izquierda (~1/3), asistente a la derecha (~2/3) */
    .cg-layout { display: grid; grid-template-columns: minmax(260px, 1fr) minmax(0, 2.1fr); gap: 16px; align-items: start; }
    @media (max-width: 1023px) { .cg-layout { grid-template-columns: minmax(0, 1fr); } }

    /* Tarjetas de tipo */
    .cg-card { display: flex; align-items: center; gap: 12px; width: 100%; padding: 14px; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; text-align: left; cursor: pointer; }
    .cg-card:hover { border-color: #93c5fd; }
    .cg-card[aria-checked="true"] { border-color: #2563eb; background: #eff6ff; box-shadow: 0 0 0 1px #2563eb inset; }
    .cg-card:focus-visible { outline: 2px solid #2563eb; outline-offset: 2px; }
    .dark .cg-card { background: #111827; border-color: #374151; } .dark .cg-card[aria-checked="true"] { background: #172554; border-color: #3b82f6; }
    .cg-card-icon { flex: 0 0 auto; display: grid; place-items: center; width: 40px; height: 40px; color: #1e293b; font-size: 28px; }
    .dark .cg-card-icon { color: #e5e7eb; }
    .cg-card-name { font-weight: 700; color: #0f172a; } .dark .cg-card-name { color: #fff; }
    .cg-card-desc { font-size: 13px; color: #64748b; } .dark .cg-card-desc { color: #9ca3af; }

    .cg-pill { display: inline-flex; align-items: center; gap: 4px; padding: 2px 9px; border: 1px solid; border-radius: 999px; font-size: 11px; font-weight: 700; line-height: 1.4; white-space: nowrap; }
    .cg-pill--verde { background: #dcfce7; border-color: #86efac; color: #166534; }
    .cg-pill--amarillo { background: #fef3c7; border-color: #fcd34d; color: #92400e; }
    .cg-pill--rojo { background: #ffe4e6; border-color: #fda4af; color: #9f1239; }
    .cg-pill--gris { background: #f1f5f9; border-color: #cbd5e1; color: #475569; }
    .cg-pill--azul { background: #dbeafe; border-color: #93c5fd; color: #1e40af; }
    .dark .cg-pill--verde { background: #052e16; border-color: #166534; color: #86efac; } .dark .cg-pill--amarillo { background: #451a03; border-color: #92400e; color: #fcd34d; }
    .dark .cg-pill--rojo { background: #4c0519; border-color: #9f1239; color: #fda4af; } .dark .cg-pill--gris { background: #1f2937; border-color: #4b5563; color: #d1d5db; } .dark .cg-pill--azul { background: #172554; border-color: #1e40af; color: #bfdbfe; }

    /* Avisos */
    .cg-alert { display: flex; gap: 12px; padding: 12px 14px; border: 1px solid; border-radius: 10px; font-size: 13px; }
    .cg-alert--info { background: #eff6ff; border-color: #bfdbfe; color: #1e3a8a; }
    .cg-alert--aviso { background: #fffbeb; border-color: #fde68a; color: #78350f; }
    .cg-alert--error { background: #fff1f2; border-color: #fecdd3; color: #881337; }
    .cg-alert--ok { background: #f0fdf4; border-color: #bbf7d0; color: #14532d; }
    .dark .cg-alert--info { background: #172554; border-color: #1e3a8a; color: #dbeafe; } .dark .cg-alert--aviso { background: #451a03; border-color: #78350f; color: #fef3c7; }
    .dark .cg-alert--error { background: #4c0519; border-color: #881337; color: #ffe4e6; } .dark .cg-alert--ok { background: #052e16; border-color: #14532d; color: #dcfce7; }
    .cg-alert-title { font-weight: 700; }

    /* Pasos */
    .cg-stepper { display: flex; align-items: center; gap: 10px; padding: 16px 20px; border-bottom: 1px solid #e2e8f0; overflow-x: auto; }
    .dark .cg-stepper { border-color: #1f2937; }
    .cg-step { display: flex; align-items: center; gap: 8px; font-size: 14px; color: #64748b; white-space: nowrap; }
    .cg-step-dot { display: grid; place-items: center; width: 30px; height: 30px; border: 2px solid #cbd5e1; border-radius: 999px; font-size: 13px; font-weight: 700; color: #64748b; background: #f8fafc; }
    .cg-step--hecho .cg-step-dot { background: #2563eb; border-color: #2563eb; color: #fff; }
    .cg-step--activo .cg-step-dot { background: #2563eb; border-color: #2563eb; color: #fff; box-shadow: 0 0 0 4px #dbeafe; }
    .cg-step--hecho, .cg-step--activo { color: #1d4ed8; font-weight: 600; }
    .cg-step-line { flex: 1 1 24px; min-width: 24px; height: 2px; background: #cbd5e1; }
    .cg-step-line--hecho { background: #2563eb; }
    .dark .cg-step-dot { background: #1f2937; border-color: #4b5563; color: #9ca3af; } .dark .cg-step--hecho, .dark .cg-step--activo { color: #93c5fd; }
    .dark .cg-step--hecho .cg-step-dot, .dark .cg-step--activo .cg-step-dot { background: #2563eb; border-color: #2563eb; color: #fff; }

    /* Archivo y controles */
    .cg-file { display: flex; align-items: center; gap: 14px; padding: 14px; border: 1px solid #e2e8f0; border-radius: 10px; }
    .dark .cg-file { border-color: #374151; }
    .cg-file-icon { display: grid; place-items: center; width: 38px; height: 38px; border-radius: 8px; background: #16a34a; color: #fff; font-weight: 800; font-size: 14px; }
    .cg-file-icon--csv { background: #0f766e; }
    .cg-drop { display: grid; place-items: center; gap: 6px; padding: 36px 16px; border: 2px dashed #cbd5e1; border-radius: 12px; text-align: center; color: #475569; cursor: pointer; background: #f8fafc; }
    .cg-drop:hover, .cg-drop--sobre { border-color: #2563eb; background: #eff6ff; }
    .dark .cg-drop { background: #0b1220; border-color: #374151; color: #9ca3af; }
    .cg-field label { display: block; margin-bottom: 4px; font-size: 13px; color: #334155; } .dark .cg-field label { color: #d1d5db; }
    .cg-input { width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; padding: 9px 10px; font-size: 14px; color: #1e293b; background: #fff; }
    .cg-input:focus { outline: 2px solid #2563eb; outline-offset: 0; border-color: #2563eb; }
    .cg-input:disabled { background: #f1f5f9; color: #94a3b8; cursor: not-allowed; }
    .dark .cg-input { background: #111827; border-color: #4b5563; color: #e5e7eb; } .dark .cg-input:disabled { background: #1f2937; }
    .cg-grid2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
    @media (max-width: 639px) { .cg-grid2 { grid-template-columns: minmax(0, 1fr); } }

    /* Tabla de relaciones */
    .cg-table-wrap { border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; }
    .dark .cg-table-wrap { border-color: #374151; }
    .cg-table { width: 100%; border-collapse: collapse; font-size: 14px; }
    .cg-table th { padding: 10px 12px; text-align: left; font-size: 12px; font-weight: 600; color: #475569; background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
    .cg-table td { padding: 8px 12px; vertical-align: middle; border-top: 1px solid #f1f5f9; }
    .dark .cg-table th { background: #0b1220; color: #d1d5db; border-color: #374151; } .dark .cg-table td { border-color: #1f2937; }
    .cg-req { color: #dc2626; font-weight: 700; margin-left: 2px; }
    .cg-check { display: inline-grid; place-items: center; width: 24px; height: 24px; border: 2px solid #2563eb; border-radius: 999px; color: #2563eb; font-size: 13px; font-weight: 800; }
    .cg-check--no { border-color: #cbd5e1; color: #cbd5e1; }
    .cg-ejemplo { color: #334155; max-width: 240px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; } .dark .cg-ejemplo { color: #d1d5db; }
    .cg-ejemplo--vacio { color: #94a3b8; font-style: italic; }
    @media (max-width: 767px) {
        .cg-table thead { display: none; }
        .cg-table tr { display: grid; gap: 4px; padding: 10px 12px; border-top: 1px solid #f1f5f9; }
        .cg-table td { padding: 2px 0; border: 0; }
    }

    /* Pie del asistente */
    .cg-foot { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; padding: 14px 16px; border-radius: 10px; background: #f1f5f9; font-size: 13px; color: #475569; }
    .dark .cg-foot { background: #1f2937; color: #d1d5db; }
    .cg-link { display: inline-flex; align-items: center; gap: 6px; color: #1d4ed8; text-decoration: underline; font-size: 13px; background: none; cursor: pointer; }
    .dark .cg-link { color: #93c5fd; }
    .cg-btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 9px 16px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; font-size: 14px; font-weight: 600; color: #1e293b; cursor: pointer; }
    .cg-btn:hover { background: #f1f5f9; } .cg-btn:disabled { opacity: .5; cursor: not-allowed; }
    .cg-btn--primario { background: #2563eb; border-color: #2563eb; color: #fff; } .cg-btn--primario:hover { background: #1d4ed8; }
    .dark .cg-btn { background: #111827; border-color: #4b5563; color: #e5e7eb; } .dark .cg-btn--primario { background: #2563eb; border-color: #2563eb; color: #fff; }

    /* Progreso y resumen */
    .cg-bar { height: 10px; border-radius: 999px; background: #e2e8f0; overflow: hidden; }
    .dark .cg-bar { background: #374151; }
    .cg-bar > div { height: 100%; background: #2563eb; transition: width .4s ease; }
    .cg-kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; }
    @media (max-width: 767px) { .cg-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    .cg-kpi { padding: 12px 14px; border: 1px solid #e2e8f0; border-radius: 10px; }
    .dark .cg-kpi { border-color: #374151; }
    .cg-kpi-n { font-size: 24px; font-weight: 800; color: #0f172a; } .dark .cg-kpi-n { color: #fff; }
    .cg-kpi-l { font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: #64748b; }
    .cg-spin { display: inline-block; width: 16px; height: 16px; border: 2px solid #bfdbfe; border-top-color: #2563eb; border-radius: 999px; animation: cg-giro 0.9s linear infinite; }
    @keyframes cg-giro { to { transform: rotate(360deg); } }
    .cg-mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12px; }
    .cg-scroll-x { overflow-x: auto; }
    .cg-vacio { padding: 28px; text-align: center; color: #64748b; font-size: 14px; }
    .cg-list-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 10px 12px; border-top: 1px solid #f1f5f9; font-size: 13px; }
    .dark .cg-list-row { border-color: #1f2937; }
</style>
