# 18 · Roadmap de Comisiones (COM)

**Fecha:** 2026-10-07 · **Estado:** PROPUESTA · **Responsable funcional:** Camilo Gaitán

> **Actualización 2026-10-19:** R1–R4 **implementados en desarrollo** (sin commit, sin prueba funcional) con la base A y el
> umbral mensual por asesor aprobados: ver `23_IMPLEMENTACION_RENTABILIDAD_COMISIONES_UMBRAL.md`. Bloqueo de rentabilidad y
> habilitación de comisiones quedan desactivados hasta aprobar valores y fecha de inicio (D1–D9 del Doc. 23).
>
> **Actualización 2026-10-15:** el plan vigente es **R1–R4** de `22_PRECIO_MINIMO_RENTABILIDAD_Y_COMISIONES.md` §12
> (base de costos y políticas → protección del precio en la venta → comisiones con salvaguardas → cuenta del asesor, abonos y
> KPIs). Reemplaza a C1–C4. No se inicia hasta que Camilo responda las preguntas P1–P12 del Doc. 22.
>
> **Actualización 2026-10-14 (auditoría E2E):** las fases **COM-04 a COM-10** se reagrupan en **cuatro fases C1–C4**
> (habilitación · liquidación y pago · ajustes y correcciones · KPIs) en `21_AUDITORIA_FLUJO_COMISIONES_E2E.md` §8, que
> manda sobre la tabla de abajo para esas fases. El hito de entrega ya no es el evento de LOG-01 sino la **validación por
> envío** (`bylopez_lg_validaciones_entrega`). **C1 no se inicia** hasta que Camilo responda P1–P5 (Doc. 21 §9).

> **Actualización 2026-10-08 (tercera ronda):** LOG-D-01…04 y COM-D-15 decididas. **LOG-01** (entrega canónica, en el
> paquete Logística) y **G-11** (clasificación comercial por pedido) **IMPLEMENTADAS EN DESARROLLO**. **Punto de parada:**
> prueba manual de Camilo. COM-04 queda **desbloqueada técnicamente, pero NO INICIADA** hasta esa validación.
>
> **Actualización 2026-10-08 (segunda ronda):** D-COST-01…07 decididas. **COM-01b** (autoridad del costo comisionable) y
> **COSTOS** (Control de costos, comparación productiva parcial, actualización masiva con vista previa y bitácora)
> **COMPLETADAS EN DESARROLLO**. Auditoría de Logística hecha: **no existe fuente de entrega**. **Punto de parada:** antes
> de implementar la entrega (LOG-D-01…04) y G-11 (COM-D-15). COM-04 sigue **BLOQUEADA**.
>
> **Actualización 2026-10-08:** COM-D decididas (COM-D-02 precisada). **COM-01, COM-02 y COM-03 COMPLETADAS EN
> DESARROLLO** (sin commit). **Punto de parada: fin de COM-03.** Siguiente bloqueo real: COM-04 necesita el evento de
> entrega (Logística) y una fuente de costo con autoridad.

> Roadmap derivado de la auditoría (`16_AUDITORIA_COMISIONES.md`) y del diseño
> (`17_DISENO_FUNCIONAL_COMISIONES.md`). Las fases COM **sustituyen y detallan** las fases 08
> (reglas y eventos de comisión), 09 (liquidaciones y ajustes) y la parte de comisiones de la 10 del
> `03_ROADMAP_IMPLEMENTACION.md`. Ninguna fase se inicia sin autorización de Camilo.

## 1. Dependencias externas que condicionan el orden

| Dependencia | Estado | Afecta a |
|---|---|---|
| Evento de **entrega** por `shipment_item` (Fase 06 Logística; D-07, D-14) | No iniciada | COM-04 en adelante (causación, D-01) |
| RMA / reembolsos trazables (Fase 07; D-25, RT-01, RT-02) | Bloqueada | COM-08 (ajustes) |
| Reversión de pagos confirmados (PAG-I) | Propuesta | COM-08 |
| Rollout de VentasAsesor a producción | **Bloqueado** (Git/infra, ver `00_STATUS.md`) | COM-10 |

## 2. Fases

| Fase | Objetivo | Dependencias | Entregable | Prueba | Riesgo |
|---|---|---|---|---|---|
| **COM-00** Auditoría y diseño | Fuentes de verdad, gaps, diseño, decisiones | — | Docs. 15, 16, 17, 18; COM-D-01…14 | Revisión de Camilo | — · **COMPLETADA 2026-10-07** |
| **COM-01** Snapshot de costo | Congelar el costo unitario de cada ítem al confirmar el pedido, con su fuente | COM-D-02 (fuente; recomendación provisional: `cost` de Bagisto etiquetado); **no** depende de Logística | Tabla `bylopez_va_costos_venta`; captura en la confirmación (listener `checkout.order.save.after` del módulo); comando de diagnóstico para pedidos sin snapshot (sin backfill inventado) | Confirmar venta → 1 snapshot por ítem padre con costo de la variante; editar `cost` después → snapshot intacto; sin costo → NULL visible; idempotente ante reintento | Capturar el costo equivocado del configurable (padre vs variante); enganche fuera de la transacción del pedido · **COMPLETADA EN DESARROLLO 2026-10-08**: captura de **referencia** con `autoridad_comision = false`; `Com01SnapshotCostoTest` 4/4 |
| **COM-02** Motor de utilidad (derivado) | Calcular precio neto, costo congelado y utilidad por unidad, sin persistir | COM-01, COM-D-01, COM-D-07 | Servicio de solo lectura `UtilidadVentaService` | Casos con descuento, configurable, sin costo, utilidad negativa | Usar `grand_total` o costo actual por error · **COMPLETADA EN DESARROLLO 2026-10-08**: utilidad diagnóstica vs autorizada (hoy null); `Com02UtilidadVentaTest` 2/2 |
| **COM-03** Reglas de comisión | Porcentaje y base con vigencia | COM-D-03, COM-D-06 (D-03) | Tabla `bylopez_va_comision_reglas` + pantalla mínima (`manage`) | Cambio de regla no altera líneas previas; sin solapes | Editar una regla ya usada · **COMPLETADA EN DESARROLLO 2026-10-08** sin pantalla (alta por comando auditado); regla #1 = 20 %; comisión potencial **diagnóstica** (nunca causada); `Com03ReglasComisionTest` 2/2 |
| **COM-01b** Costo comisionable oficial | `cost` de Bagisto con autoridad (D-COST-02) | D-COST | Fuente `cost_bagisto_comisionable` | Captura con autoridad; sin costo sin autoridad | — · **COMPLETADA EN DESARROLLO 2026-10-08** |
| **COSTOS** Control de costos | Comparar costo comisionable vs productivo y actualizar masivamente con trazabilidad | D-COST-04/05/07 | Pantalla, `CostoProductivoReferenciaService`, `ActualizarCostoComisionableService`, bitácora (000017), ACL `ventas_asesor.costos(.actualizar)` | `CostosControlTest` 4/4 | Costo productivo solo parcial (tela) · **COMPLETADA EN DESARROLLO 2026-10-08** (Doc. 19) |
| **LOG-01** Entrega canónica | Evento `entrega_confirmada` por envío y unidad, idempotente e inmutable (Doc. 17 §14) | LOG-D-01…04 | Tablas de entrega (paquete Logística), registro por Bodega/supervisión | Idempotencia, parciales, anulación auditada, asesor sin permiso | **IMPLEMENTADA EN DESARROLLO 2026-10-08** · `Log01EntregaCanonicaTest` 9/9 · pendiente de prueba manual |
| **DESPACHO V1** Guía, flete, recaudo y PDF | Despachar con guía desde Pedidos / Despachos; recogida en bodega; PDF versionado | LOG-01 | `bylopez_lg_guias` + `bylopez_lg_guia_archivos`, drawer único, ACL `logistica.despachos/guias` | `Log02DespachoTest` 11/11 | Corrección de guías mal digitadas · **IMPLEMENTADA EN DESARROLLO 2026-10-09** · pendiente de prueba manual. No cambia COM-04: la entrega sigue siendo el evento de LOG-01 (despachar ≠ entregar) |
| **G-11** Clasificación comercial | Marcar venta normal / muestra / reposición / garantía por pedido (COM-D-15) | COM-D-15 | `bylopez_va_clasificaciones_pedido` (000018), selector en Nueva venta, `TipoOperacionPedidoService` | `G11TipoOperacionPedidoTest` 4/4 | Pedidos de otros canales sin clasificar · **IMPLEMENTADA EN DESARROLLO 2026-10-08** · pendiente de prueba manual |
| **COM-04** Causación | Crear líneas `causada` al entregar **y** con pago total verificado (COM-D-04/05) | LOG-01 ✅ (dev), G-11 ✅ (dev), COM-02, COM-03, costo con autoridad ✅ · **NO INICIADA: espera la validación manual de Camilo** | Tablas `comision_lineas` y `comision_eventos`; proceso idempotente; potencial y pendiente_pago derivados | Entrega parcial, reintento, doble ejecución, pedido de empresa, asesor inactivo | Doble comisión; causar sin entrega real |
| **COM-05** UI asesor | «Mis comisiones» (solo lectura) | COM-04 (potencial puede adelantarse tras COM-02), COM-D-12 | Pantalla `view_own` + ACL en el rol ASESOR VENTAS | Aislamiento `view_own`; sin botones de escritura | Mostrar costo/utilidad contra D-10 |
| **COM-06** UI admin | «Comisiones» con filtros y detalle | COM-04 | Pantalla `view_all` | Filtros por asesor/periodo/estado; métricas por lote sin N+1 | Rendimiento |
| **COM-07** Liquidaciones | Borrador → cerrada → pagada | COM-04, COM-D-10 | Tabla `comision_liquidaciones`; flujo con `approve` y `pay` | Pagada inmutable; una línea en una sola liquidación; anular borrador libera líneas | Pagar dos veces una línea |
| **COM-08** Ajustes y reversos | Anulación y ajuste negativo por devolución, fraude, reasignación, reversión | Fase 07, PAG-I, COM-07, COM-D-08, COM-D-09, COM-D-14 | Ajustes trazables a la línea de origen | RMA + reembolso del mismo hecho = un solo ajuste; ajuste tras pago entra al periodo siguiente | Doble ajuste |
| **COM-09** Reportes / API | Reportes operativos y financieros; API de solo lectura | COM-07 | Reportes de `17` §11; endpoints read-only (D-08) | Totales cuadran con líneas congeladas | Exponer datos financieros a asesores |
| **COM-10** Rollout | Activar en producción | COM-01…COM-08, rollout de VentasAsesor, backup | Plan de despliegue, fecha de inicio de comisiones, rol actualizado | Smoke manual de Camilo | Comisionar pedidos anteriores al inicio |

**Prioridad recomendada:** COM-01 primero (cada venta sin snapshot pierde su costo para siempre),
luego COM-02/COM-03. COM-04 en adelante esperan la entrega de Logística.

## 3. Decisiones COM-D

> **2026-10-08: DECIDIDAS por Camilo Gaitán**, igual que la recomendación salvo **COM-D-10 = QUINCENAL** y la
> **precisión de COM-D-02** (captura de `cost` como referencia sin autoridad; fuente autoritativa pendiente). La tabla
> de abajo conserva el planteamiento original. Decisiones vigentes en `04_DECISIONES_PENDIENTES.md` §COM-D.

**Pendiente real para avanzar a COM-04:** (1) ~~evento de entrega confiable~~ **LOG-01 implementada en desarrollo**;
(2) ~~fuente de costo autoritativa~~ **resuelta por D-COST-02**; (3) ~~marcador de muestras / reposiciones / garantías~~
**G-11 implementada en desarrollo**; (4) **prueba manual de Camilo** de LOG-01 y G-11.

Detalle completo en `04_DECISIONES_PENDIENTES.md` §COM-D.

| Id | Pregunta | Recomendación técnica | Bloquea |
|---|---|---|---|
| COM-D-01 | ¿Base de la utilidad? (A: P − C · B: P − C − V · C1: % de venta · C2: fijo por prenda) | A | COM-02 |
| COM-D-02 | ¿Fuente del costo que se congela? | Provisional: atributo `cost` de Bagisto etiquetado; definir integración (D-31) | COM-01 |
| COM-D-03 | ¿Se aprueba el 20 %? | Aprobar como regla inicial con vigencia, revisable | COM-03 |
| COM-D-04 | ¿Además de la entrega (D-01), se exige pago verificado? | Sí (opción F) | COM-04 |
| COM-D-05 | ¿Pago parcial? | 0 hasta pago total verificado | COM-04 |
| COM-D-06 | ¿Regla global o por asesor/producto?; ¿exclusiones? | Global, modelo preparado para alcance; muestras/reposiciones no comisionan | COM-03 |
| COM-D-07 | ¿Unidad con pérdida = 0 o netea con otras? | 0 por unidad, sin neteo | COM-02 |
| COM-D-08 | ¿Reembolso sin devolución descuenta comisión? (D-24) | No automático; incidencia para revisión | COM-08 |
| COM-D-09 | ¿Quién conserva la comisión al reasignar? | Asesor vigente al causar + bloqueos por estado | COM-04/08 |
| COM-D-10 | ¿Frecuencia de liquidación? (D-15) | Mensual, por fecha de entrega | COM-07 |
| COM-D-11 | ¿Quién confirma la entrega? (D-14) | Bodega/supervisor; el asesor no | COM-04 |
| COM-D-12 | ¿El asesor ve costo y utilidad? (D-10) | No: ve venta, comisión y estado | COM-05 |
| COM-D-13 | ¿Desde qué fecha se comisiona? | Desde COM-01 en producción; antes, sin comisión | COM-10 |
| COM-D-14 | ¿Ajuste negativo a asesor sin liquidaciones futuras? | Saldo a cargo informado; cobro según política de Camilo | COM-08 |
