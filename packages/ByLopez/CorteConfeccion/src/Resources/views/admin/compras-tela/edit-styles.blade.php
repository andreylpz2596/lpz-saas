@push('styles')
    <style>
        .compra-edicion {
            --ce-border: #e2e8f0;
            --ce-surface: #fff;
            --ce-muted: #f1f5f9;
            --ce-text: #172554;
            --ce-secondary: #64748b;
            color: var(--ce-text);
        }
        .dark .compra-edicion {
            --ce-border: #1f2937;
            --ce-surface: #111827;
            --ce-muted: #1f2937;
            --ce-text: #f1f5f9;
            --ce-secondary: #94a3b8;
        }
        .compra-edicion .ce-heading { display: flex; align-items: center; gap: 20px; margin-bottom: 14px; }
        .compra-edicion .ce-heading-title { flex: 1; min-width: 0; }
        .compra-edicion h1 { margin: 0; font-size: 24px; line-height: 1.2; font-weight: 700; }
        .compra-edicion .ce-back { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; }
        .compra-edicion .ce-back > span { display: grid; place-items: center; width: 28px; height: 28px; border: 1px solid var(--ce-border); border-radius: 50%; color: #2563eb; }
        .compra-edicion .ce-back > span::before { transform: rotate(180deg); }
        .compra-edicion .ce-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-top: 4px; font-size: 12px; color: var(--ce-secondary); overflow-wrap: anywhere; }
        .compra-edicion .ce-status { background: var(--ce-muted); border-radius: 20px; padding: 2px 10px; font-size: 11px; font-weight: 600; }
        .compra-edicion .ce-status-registered { background: #dcfce7; color: #15803d; }
        .compra-edicion .ce-card { border: 1px solid var(--ce-border); border-radius: 8px; background: var(--ce-surface); padding: 16px; }
        .compra-edicion .ce-section-heading { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; }
        .compra-edicion .ce-section-heading h2 { margin: 0; font-size: 15px; line-height: 1.3; font-weight: 700; }
        .compra-edicion .ce-section-heading p { margin-top: 2px; font-size: 12px; color: var(--ce-secondary); }
        .compra-edicion .ce-section-icon { display: grid; place-items: center; width: 30px; height: 30px; flex-shrink: 0; border-radius: 8px; background: #eff6ff; color: #2563eb; font-size: 21px; font-weight: 700; }
        .compra-edicion .ce-info-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px 18px; }
        .compra-edicion .ce-info-grid > div { margin-bottom: 0; min-width: 0; }
        .compra-edicion .ce-info-grid label, .compra-edicion .ce-summary-grid label { display: block; margin-bottom: 4px; font-size: 12px; font-weight: 600; }
        .compra-edicion .ce-info-grid input, .compra-edicion .ce-info-grid select, .compra-edicion .ce-info-grid textarea { width: 100%; border: 1px solid var(--ce-border); border-radius: 6px; padding: 7px 10px; font-size: 13px; line-height: 20px; background: var(--ce-surface); color: var(--ce-text); }
        .compra-edicion .ce-info-grid textarea { min-height: 56px; height: 56px; resize: vertical; }
        .compra-edicion .ce-observation { grid-column: 1 / -1; }
        .compra-edicion .ce-rollos-root { margin-top: 12px; }
        .compra-edicion .ce-rollos { padding: 14px; }
        .compra-edicion .ce-rollos-heading { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 12px; }
        .compra-edicion .ce-rollos-heading .ce-section-heading { margin-bottom: 0; }
        .compra-edicion .ce-group { border: 1px solid var(--ce-border); border-radius: 7px; }
        .compra-edicion .ce-group-heading { display: flex; align-items: center; width: 100%; gap: 12px; padding: 10px 12px; text-align: left; border-radius: 7px; }
        .compra-edicion .ce-group-heading:focus-visible { outline: 2px solid #2563eb; outline-offset: -2px; }
        .compra-edicion .ce-group-heading:hover { box-shadow: inset 0 0 0 1px var(--ce-border); }
        .compra-edicion .ce-group-title { flex: 1; min-width: 0; font-size: 14px; }
        .compra-edicion .ce-color-dot { width: 14px; height: 14px; flex-shrink: 0; border: 1px solid #00000018; border-radius: 50%; }
        .compra-edicion .ce-group-metrics { display: flex; align-items: center; justify-content: flex-end; flex-wrap: wrap; gap: 8px; font-size: 12px; }
        .compra-edicion .ce-chip { display: inline-flex; align-items: center; gap: 6px; padding: 5px 10px; border-radius: 20px; background: var(--ce-muted); white-space: nowrap; font-variant-numeric: tabular-nums; }
        .compra-edicion .ce-chip-money { font-weight: 700; }
        .compra-edicion .ce-group-body { border-top: 1px solid var(--ce-border); padding: 0 10px 6px; }
        .compra-edicion .ce-table { width: 100%; table-layout: fixed; border-collapse: collapse; font-size: 13px; text-align: left; }
        .compra-edicion .ce-table th { text-transform: none; font-size: 11px; color: var(--ce-secondary); font-weight: 600; padding: 7px 8px; }
        .compra-edicion .ce-table th:nth-child(1) { width: 36px; }
        .compra-edicion .ce-table th:nth-child(2) { width: 18%; }
        .compra-edicion .ce-table th:nth-child(3) { width: 18%; }
        .compra-edicion .ce-table th:nth-child(4) { width: 16%; text-align: left; }
        .compra-edicion .ce-table th:nth-child(6) { width: 60px; text-align: center; }
        .compra-edicion .ce-table td { padding: 4px 8px; vertical-align: middle; }
        .compra-edicion .ce-table input { min-width: 0; width: 100%; height: 32px; border: 1px solid var(--ce-border); border-radius: 5px; padding: 5px 8px; font-size: 13px; color: var(--ce-text); background: var(--ce-surface); }
        .compra-edicion .ce-table input[readonly] { background: var(--ce-muted); color: var(--ce-secondary); }
        .compra-edicion .ce-unit-input { display: flex; align-items: center; min-width: 0; border: 1px solid var(--ce-border); border-radius: 5px; background: var(--ce-surface); overflow: hidden; }
        .compra-edicion .ce-unit-input:focus-within { outline: 2px solid #93c5fd; outline-offset: 1px; }
        .compra-edicion .ce-unit-input input { min-width: 0; width: 100%; height: 32px; border: 0; border-radius: 0; padding: 5px 8px; background: transparent; color: var(--ce-text); font-size: 13px; outline: none; }
        .compra-edicion .ce-unit { display: flex; align-items: center; align-self: stretch; padding: 0 9px; background: var(--ce-muted); border-left: 1px solid var(--ce-border); font-size: 12px; color: var(--ce-secondary); }
        .compra-edicion .ce-currency { padding-left: 9px; color: var(--ce-secondary); font-size: 13px; }
        .compra-edicion .ce-subtotal { display: block; border-radius: 5px; padding: 7px 8px; background: var(--ce-muted); font-weight: 700; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .compra-edicion .ce-actions-cell { text-align: center; }
        .compra-edicion .ce-actions-cell button[aria-label] { min-height: 32px; min-width: 32px; padding: 6px; line-height: 20px; color: #2563eb; font-size: 20px; }
        .compra-edicion .ce-actions-cell > div > div:first-child { justify-content: center; }
        .compra-edicion .ce-required { color: #dc2626; }
        .compra-edicion .ce-summary { margin-top: 12px; padding: 14px 16px; }
        .compra-edicion .ce-summary .ce-section-heading { margin-bottom: 10px; }
        .compra-edicion .ce-summary-grid { display: grid; grid-template-columns: 1fr 1fr 1.2fr; gap: 20px; align-items: center; }
        .compra-edicion .ce-summary-grid > div { min-width: 0; margin-bottom: 0; }
        .compra-edicion .ce-total-panel { display: flex; flex-direction: column; padding: 12px 18px; border-radius: 8px; background: #eff6ff; color: #172554; }
        .compra-edicion .ce-total-panel span { font-size: 12px; }
        .compra-edicion .ce-total-panel strong { font-size: 24px; line-height: 1.3; font-variant-numeric: tabular-nums; }
        .compra-edicion .ce-footer { position: fixed; bottom: 0; z-index: 30; border-top: 1px solid var(--ce-border); background: var(--ce-surface); padding: 10px 14px; box-shadow: 0 -4px 12px #0000000a; }
        .compra-edicion .ce-footer-grid { display: grid; grid-template-columns: 1fr 1fr 1.2fr 1fr 1fr 1.2fr auto; gap: 12px; align-items: center; }
        .compra-edicion .ce-footer-grid > div { display: flex; flex-direction: column; min-width: 0; }
        .compra-edicion .ce-footer-grid strong { font-size: 13px; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .compra-edicion .ce-footer-grid span { font-size: 11px; color: var(--ce-secondary); }
        .compra-edicion .ce-footer-adjustment { display: block; min-width: 0; font-size: 11px; font-weight: 600; }
        .compra-edicion .ce-footer-adjustment .ce-unit-input { margin-top: 3px; }
        .compra-edicion .ce-footer-total { padding: 6px 10px; border-radius: 8px; background: #eff6ff; }
        .compra-edicion .ce-footer-total strong { font-size: 20px; color: #155dfb; }
        .compra-edicion .ce-footer-total span { color: #2563eb; font-weight: 600; }
        .compra-edicion .primary-button, .compra-edicion .secondary-button { min-height: 36px; font-size: 13px; white-space: nowrap; }
        .compra-edicion button:disabled { cursor: not-allowed; opacity: .6; }
        .dark .compra-edicion .ce-section-icon, .dark .compra-edicion .ce-total-panel, .dark .compra-edicion .ce-footer-total { background: #172554; color: #dbeafe; }
        .dark .compra-edicion .ce-footer-total strong { color: #93c5fd; }
        @media (max-width: 1199px) {
            .compra-edicion .ce-footer-grid { grid-template-columns: 1fr 1fr 1fr 1fr auto; gap: 10px; }
            .compra-edicion .ce-footer-adjustment { display: none; }
            .compra-edicion .ce-table th:nth-child(2), .compra-edicion .ce-table th:nth-child(3) { width: 22%; }
            .compra-edicion .ce-table th:nth-child(4) { width: 20%; }
            .compra-edicion .ce-info-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 900px) {
            .compra-edicion .ce-group-heading { flex-wrap: wrap; gap: 8px; }
            .compra-edicion .ce-group-title { flex-basis: 65%; }
            .compra-edicion .ce-group-metrics { flex-basis: 100%; justify-content: flex-start; padding-left: 26px; }
            .compra-edicion .ce-heading { gap: 12px; }
            .compra-edicion h1 { font-size: 21px; }
            .compra-edicion .ce-summary-grid { gap: 12px; }
            .compra-edicion .ce-total-panel { padding: 12px; }
            .compra-edicion .ce-total-panel strong { font-size: 21px; }
        }
        @media (max-width: 767px) {
            .compra-edicion .ce-heading { flex-wrap: wrap; }
            .compra-edicion .ce-heading-title { flex-basis: calc(100% - 85px); }
            .compra-edicion .ce-heading > button { margin-left: auto; }
            .compra-edicion .ce-rollos-heading { align-items: flex-start; flex-wrap: wrap; }
            .compra-edicion .ce-card { padding: 12px; }
            .compra-edicion .ce-table, .compra-edicion .ce-table tbody { display: block; }
            .compra-edicion .ce-table thead { display: none; }
            .compra-edicion .ce-table tr { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px 10px; padding: 10px 0; }
            .compra-edicion .ce-table td { display: block; min-width: 0; padding: 0; }
            .compra-edicion .ce-table td[data-label]::before { content: attr(data-label); display: block; font-size: 11px; line-height: 1.3; margin-bottom: 4px; color: var(--ce-secondary); }
            .compra-edicion .ce-row-number { grid-column: 1; grid-row: 1; font-weight: 600; }
            .compra-edicion .ce-table td.ce-row-number::before { display: inline; margin-right: 5px; }
            .compra-edicion .ce-actions-cell { grid-column: 2; grid-row: 1; text-align: right; }
            .compra-edicion .ce-actions-cell > div > div:first-child { justify-content: flex-end; }
            .compra-edicion .ce-weight-cell { grid-column: 1; grid-row: 2; }
            .compra-edicion .ce-price-cell { grid-column: 2; grid-row: 2; }
            .compra-edicion .ce-subtotal-cell { grid-column: 1 / -1; grid-row: 3; display: flex !important; align-items: center; justify-content: space-between; }
            .compra-edicion .ce-note-cell { grid-column: 1 / -1; grid-row: 4; }
            .compra-edicion .ce-table input, .compra-edicion .ce-unit-input input { height: 36px; }
            .compra-edicion .ce-summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .compra-edicion .ce-total-panel { grid-column: 1 / -1; }
            .compra-edicion .ce-footer-grid { grid-template-columns: 1fr auto; gap: 4px 10px; }
            .compra-edicion .ce-footer-grid > .ce-footer-subtotal, .compra-edicion .ce-footer-count > span, .compra-edicion .ce-footer-weight > span { display: none; }
            .compra-edicion .ce-footer-count { grid-column: 1; grid-row: 1; }
            .compra-edicion .ce-footer-weight { grid-column: 1; grid-row: 2; }
            .compra-edicion .ce-footer-weight strong { font-size: 11px; color: var(--ce-secondary); }
            .compra-edicion .ce-footer-total { grid-column: 2; grid-row: 1 / 3; align-items: flex-end; padding: 0; background: transparent; }
            .compra-edicion .ce-footer-total strong { font-size: 19px; }
            .compra-edicion .ce-footer-grid > button { grid-column: 1 / -1; grid-row: 3; min-height: 36px; margin-top: 4px; }
            .compra-edicion .ce-footer { padding: 8px 12px; padding-bottom: max(8px, env(safe-area-inset-bottom)); }
        }
        @media (max-width: 479px) {
            .compra-edicion .ce-info-grid { grid-template-columns: minmax(0, 1fr); }
            .compra-edicion .ce-group-metrics { gap: 5px; padding-left: 0; }
            .compra-edicion .ce-chip { padding: 4px 8px; font-size: 11px; }
            .compra-edicion .ce-section-heading p { font-size: 11px; }
        }
    </style>
@endpush
