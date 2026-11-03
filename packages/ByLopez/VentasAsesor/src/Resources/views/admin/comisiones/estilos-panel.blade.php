{{--
    Estilos propios del panel de comisiones (avance, lista por pedido, «Ver cálculo», cuenta). El build de Tailwind del admin no escanea
    ByLopez: todo lo nuevo usa clases `va-*` con modo oscuro explícito. Se incluye una sola vez por página.
--}}
<style>
    .va-avance { padding: 16px; }
    .va-avance__cab { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 4px 12px; }
    .va-avance__titulo { margin: 0; font-size: 16px; font-weight: 700; color: #0f172a; }
    .dark .va-avance__titulo { color: #f9fafb; }
    .va-avance__mes { font-size: 12px; color: #64748b; }
    .dark .va-avance__mes { color: #9ca3af; }
    .va-avance__mensaje { margin: 10px 0 0; font-size: 18px; font-weight: 700; line-height: 1.3; color: #0f172a; }
    .dark .va-avance__mensaje { color: #f9fafb; }
    .va-avance__detalle { margin: 2px 0 0; font-size: 14px; color: #475569; }
    .dark .va-avance__detalle { color: #cbd5e1; }
    .va-barra { height: 10px; margin-top: 12px; overflow: hidden; border-radius: 999px; background: #e2e8f0; }
    .dark .va-barra { background: #1f2937; }
    .va-barra__relleno { height: 100%; border-radius: 999px; background: #2563eb; }
    .va-barra__relleno--ok { background: #059669; }
    .va-avance__cifras { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 10px 16px; margin: 14px 0 0; }
    .va-avance__cifras dt { font-size: 11px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: #64748b; }
    .dark .va-avance__cifras dt { color: #9ca3af; }
    .va-avance__cifras dd { margin: 2px 0 0; font-size: 18px; font-weight: 700; color: #0f172a; }
    .dark .va-avance__cifras dd { color: #f9fafb; }
    .va-avance__nota { margin: 10px 0 0; font-size: 12px; line-height: 1.5; color: #64748b; }
    .dark .va-avance__nota { color: #9ca3af; }
    .va-avance__pie { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px 16px; margin-top: 12px; }

    /* Avisos generales de la cuenta: se dicen UNA vez arriba, no junto a cada pedido. */
    .va-aviso { padding: 10px 14px; border: 1px solid #fcd34d; border-radius: 10px; background: #fffbeb; color: #92400e; font-size: 13px; line-height: 1.5; }
    .dark .va-aviso { border-color: #78350f; background: #1c1308; color: #fcd34d; }
    .va-aviso ul { margin: 4px 0 0; padding-left: 18px; }
    .va-aviso--info { border-color: #bfdbfe; background: #eff6ff; color: #1e40af; }
    .dark .va-aviso--info { border-color: #1e3a8a; background: #0b1730; color: #bfdbfe; }

    /* Lista de pedidos: una fila por pedido; en pantallas estrechas pasa a tarjeta. */
    .va-ped { padding: 12px 16px; }
    .va-ped__fila { display: grid; grid-template-columns: minmax(110px, 1.1fr) minmax(120px, 1fr) 90px 100px 130px minmax(150px, 1.4fr); align-items: center; gap: 8px 14px; }
    .va-ped__fila > * { min-width: 0; }
    .va-ped__rot { display: none; font-size: 11px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: #64748b; }
    .dark .va-ped__rot { color: #9ca3af; }
    .dark .va-ped, .dark .va-asesora, .dark .va-panel { color: #e5e7eb; }
    .dark .va-panel .va-kpi-label { color: #9ca3af; }
    .va-ped__num { font-weight: 700; }
    .va-ped__monto { font-weight: 700; text-align: right; }
    .va-ped__motivos { margin: 8px 0 0; padding: 0; list-style: none; font-size: 13px; color: #92400e; }
    .dark .va-ped__motivos { color: #fcd34d; }
    .va-ped__motivos li + li { margin-top: 2px; }
    @media (max-width: 860px) {
        .va-ped__fila { grid-template-columns: 1fr 1fr; }
        .va-ped__rot { display: block; }
        .va-ped__monto { text-align: left; }
    }

    /* «Ver cálculo»: desplegable por pedido (cerrado de entrada). */
    .va-calculo { margin-top: 10px; }
    .va-calculo > summary { display: inline-flex; align-items: center; min-height: 36px; cursor: pointer; font-size: 13px; font-weight: 600; color: #2563eb; }
    .dark .va-calculo > summary { color: #93c5fd; }
    .va-calculo__tabla { width: 100%; margin-top: 8px; border-collapse: collapse; font-size: 13px; }
    .va-calculo__tabla th { padding: 6px 8px; text-align: right; font-size: 11px; font-weight: 600; letter-spacing: .03em; text-transform: uppercase; color: #64748b; white-space: nowrap; }
    .va-calculo__tabla th:first-child, .va-calculo__tabla td:first-child { text-align: left; }
    .dark .va-calculo__tabla th { color: #9ca3af; }
    .va-calculo__tabla td { padding: 6px 8px; border-top: 1px solid #f1f5f9; text-align: right; vertical-align: top; }
    .dark .va-calculo__tabla td { border-color: #1f2937; }
    .va-calculo__scroll { overflow-x: auto; }
    .va-calculo__formula { margin: 8px 0 0; font-size: 12px; color: #64748b; }
    .dark .va-calculo__formula { color: #9ca3af; }
    .va-calculo__linea-motivos { margin: 2px 0 0; padding: 0; list-style: none; font-size: 12px; color: #92400e; text-align: left; }
    .dark .va-calculo__linea-motivos { color: #fcd34d; }

    /* Filtros compactos: en escritorio una sola fila; en movil el boton pliega el formulario (sin JavaScript). */
    .va-fcompacto { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 10px 12px; }
    .va-fcompacto__campo { flex: 0 1 auto; min-width: 140px; }
    .va-fcompacto__campo--ancho { flex: 1 1 220px; }
    .va-fcompacto label { display: block; margin-bottom: 4px; font-size: 11px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: #64748b; }
    .dark .va-fcompacto label { color: #9ca3af; }
    .va-fcompacto input, .va-fcompacto select { width: 100%; min-height: 40px; padding: 6px 10px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; color: #334155; background: #fff; }
    .dark .va-fcompacto input, .dark .va-fcompacto select { border-color: #1f2937; color: #d1d5db; background: #111827; }
    .va-fcheck { position: absolute; width: 1px; height: 1px; opacity: 0; }
    .va-fbtn { display: none; }
    @media (max-width: 767px) {
        .va-fbtn { display: inline-flex; align-items: center; gap: 8px; min-height: 44px; cursor: pointer; font-weight: 600; font-size: 14px; }
        .va-fcheck:not(:checked) ~ .va-fcompacto { display: none; }
        .va-fcheck:focus-visible ~ .va-fbtn { outline: 2px solid #2563eb; outline-offset: 2px; }
        .va-fcompacto__campo, .va-fcompacto__campo--ancho { flex: 1 1 100%; }
        .va-fcompacto { margin-top: 8px; }
        .va-fcompacto input, .va-fcompacto select { min-height: 44px; }
    }

    /* Resumen por asesora (administracion): tarjetas comparables. */
    .va-asesora { padding: 14px 16px; }
    .va-asesora__cifras { display: grid; grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); gap: 8px 14px; margin: 10px 0 0; }
    .va-asesora__cifras dt { font-size: 11px; font-weight: 600; letter-spacing: .03em; text-transform: uppercase; color: #64748b; }
    .dark .va-asesora__cifras dt { color: #9ca3af; }
    .va-asesora__cifras dd { margin: 1px 0 0; font-size: 16px; font-weight: 700; }
</style>
