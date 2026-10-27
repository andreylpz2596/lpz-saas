# 17 · Diseño funcional de Comisiones (COM-00)

**Fecha:** 2026-10-07 · **Estado:** PROPUESTA (diseño, sin código ni migraciones) ·
**Responsable funcional:** Camilo Gaitán

> **Actualización 2026-10-08:** Camilo decidió COM-D-01 y COM-D-03…14 y precisó COM-D-02 (Doc. 04, sección COM-D). Las
> recomendaciones de este documento que coinciden con esas decisiones pasan a ser **reglas aprobadas**; la única diferencia
> es la liquidación **quincenal** (COM-D-10). COM-01/02/03 están implementadas en desarrollo: ver **§13**. COM-04 en
> adelante sigue siendo diseño.

> Este documento **no decide** lo que está abierto: presenta alternativas con su recomendación
> técnica. Lo decidido se marca como tal con su identificador. Evidencia en
> `16_AUDITORIA_COMISIONES.md` (`[COM-§n]`); decisiones abiertas en `04_DECISIONES_PENDIENTES.md`
> (COM-D-01 a COM-D-14); fases en `18_ROADMAP_COMISIONES.md`.

## 0. Restricciones ya decididas (no se reabren aquí)

| Id | Regla |
|---|---|
| D-01 | La comisión se causa solo por **prendas entregadas y no devueltas**; fecha causante = fecha confirmada de entrega. No causan: pedido creado, facturado, despachado, en tránsito, entrega fallida, retorno, cancelación, devolución, reposición, garantía, muestra, producto no comisionable |
| D-02 | Liquidación pagada **inmutable**; devolución posterior = **ajuste negativo** trazable en la siguiente liquidación |
| D-05 | Un solo asesor propietario por pedido |
| D-27 | Venta de empresa (`company`) **no** comisiona; el `all` comisiona solo si se asignó expresamente |
| D-13 | Cada asesor ve solo lo suyo |
| RN-18/19 | Moneda base; el transporte no es base comisionable salvo decisión expresa |

---

## 1. Utilidad comisionable (Bloque 5)

Notación por unidad: `P` = precio neto unitario (`base_price` − descuento unitario, sin IVA ni envío,
[COM-§5]); `C` = costo unitario **congelado**; `V` = costos variables comerciales imputables a la unidad.

| Alt. | Fórmula por unidad | Ventajas | Riesgos | Datos necesarios | Impacto en margen | Auditoría |
|---|---|---|---|---|---|---|
| **A** | `U = P − C` | Simple; es la dirección documentada (`ACTUALIZACION` §7); un descuento ya reduce la comisión sin doble penalización | Ignora costos de venta (recaudo, empaque); si `C` es solo referencia, la utilidad es aproximada | Precio (existe), costo congelado (**falta**, COM-01) | Comisión = % × margen bruto; ej. 6.900/5.500 → 280 | Alta: 2 números congelados |
| **B** | `U = P − C − V` (V = comisión de recaudo COD, pasarela, empaque, flete no cobrado) | Paga sobre la utilidad que realmente queda | `V` no existe hoy por unidad; muchos costos son por pedido o por guía y hay que repartirlos; discusiones con asesores | Todo lo de A + tarifas de recaudo y empaque por pedido + regla de reparto | Menor comisión; más justa con contra entrega | Media: depende del reparto |
| **C1** | `Comisión = % × P` (porcentaje sobre venta neta) | No necesita costo; trivial de auditar | Paga igual una venta con margen 0 que una con margen alto; incentiva vender barato | Solo precio | Erosiona margen en precios bajos | Alta |
| **C2** | `Comisión = valor fijo por prenda` (por escala de precio o grupo) | Muy simple para el asesor | No sigue el margen; hay que mantener la tabla de escalas | Tabla de valores por escala | Depende de la tabla | Alta |

- **Utilidad negativa:** la dirección documentada es `max(0, U)` **por unidad** (una unidad con pérdida
  paga 0 y **no** resta de otras). Alternativa: netear por pedido. → **COM-D-07**.
- **No se incluyen gastos generales** (nómina, arriendo, plataformas): D-38 ya separó margen mínimo de
  utilidad final de la empresa; incluirlos exigiría una regla de prorrateo sin sustento.
- **Recomendación técnica:** **A** con `C` congelado y etiquetado; dejar `V` como extensión futura
  (B) solo si Camilo identifica un costo variable claro y medible por unidad. → **COM-D-01**.

---

## 2. Momento de causación (Bloque 7)

> **D-01 ya está DECIDIDA: entrega (opción E).** La tabla compara opciones para dejar explícitas sus
> consecuencias y para resolver la única ambigüedad real: **si además se exige pago verificado**
> («conciliadas», [COM-§7 C-1]).

| Opción | Ventajas | Riesgos | Devoluciones | Contra entrega | Cartera | Facilidad |
|---|---|---|---|---|---|---|
| A. Al crear el pedido | Inmediata | Paga ventas que se cancelan, no se despachan o no se cobran; contradice D-01 | Muchos ajustes | Paga antes de cobrar | Ignora saldo | Fácil |
| B. Pago total verificado | Dinero real | Ignora si la prenda llegó; contradice D-01 si se usa sola | Ajustes si devuelven tras pagar | Llega tarde (recaudo) | Alineado | Media |
| C. Proporcional al pago verificado | Refleja el avance del cobro | Pago por pedido vs unidad → requiere imputación; reversos de pago generan ajustes | Ajustes | Igual que B | Alineado | Difícil |
| D. Al despacho | Dato existente (`shipments`) | Retornos y entregas fallidas causarían comisión; contradice D-01 | Muchos ajustes | Paga antes de cobrar | Ignora saldo | Fácil |
| **E. Al entregado (D-01)** | Coherente con la prenda real; excluye retornos | Requiere evento de entrega (no existe); sin condición de pago, paga ventas a crédito no cobradas | Ajuste solo por devolución real | Al entregar, el recaudo aún no llegó a ByLopez | Ignora saldo | Media (depende de Logística) |
| **F. Combinación E + pago verificado** | Prenda entregada **y** dinero verificado: no paga lo no cobrado | Dos condiciones; la comisión «espera» al pago | Igual que E | Espera la confirmación del recaudo | Usa `verificado` de Cartera | Media |

**Recomendación técnica:** **F** = D-01 + «pedido sin saldo pendiente de verificar». Estado intermedio
`pendiente_pago` (entregada pero sin pago verificado completo). → **COM-D-04**.

---

## 3. Pagos parciales (Bloque 8)

**Caso:** venta 500.000 · utilidad potencial 100.000 · comisión potencial (20 %) = **20.000** · el cliente
paga 250.000 (verificados) · todo entregado.

| Opción | Comisión causada hoy | Si luego paga el resto | Si nunca paga el resto | Complejidad |
|---|---|---|---|---|
| **0 hasta pago total** | 0 (20.000 en `pendiente_pago`) | Se causan los 20.000 completos | 0; la empresa no pierde comisión | Baja |
| **Proporcional** | `20.000 × 250.000 / 500.000` = **10.000** | +10.000 al completar | Quedan 10.000 pagados sobre una venta medio cobrada; no hay ajuste (no hubo devolución) | Alta: imputar el pago a unidades; ¿el envío se cubre primero?; un pago rechazado o reversado (PAG-I) obliga a ajustar |
| **Total** | 20.000 | — | 20.000 pagados con 250.000 sin cobrar; requiere política de recuperación | Baja, pero traslada el riesgo de cartera a la empresa |
| Otra: **umbral** (p. ej. ≥ 90 % pagado) | 0 o 20.000 según umbral | — | Igual que «0» si no llega al umbral | Baja; el umbral es arbitrario |

Consideraciones:
- Los pagos `sin_revisar` **no** cuentan (no es dinero verificado).
- El factor proporcional usaría `verificado / base_grand_total`, que **incluye envío**: habría que
  definir si el envío se cubre primero.
- **Recomendación técnica:** **0 hasta pago total verificado** (coherente con F; simple y sin ajustes
  por cobro). → **COM-D-05**.

---

## 4. Devoluciones, cancelaciones y reversos (Bloque 9)

| Evento | Antes de causar | Después de causada (no liquidada) | Después de pagada |
|---|---|---|---|
| Cancelación (total o de unidades) | No hay comisión; la unidad sale del potencial | No aplica: no se puede cancelar una unidad entregada (Bagisto cancela solo lo no facturado/no enviado) | No aplica |
| Refund parcial **con devolución** (RMA) | Unidad sale del potencial | Línea **anulada** por la cantidad devuelta | **Ajuste negativo** en la siguiente liquidación (D-02) |
| Refund total con devolución | Igual | Todas las líneas del pedido anuladas | Ajustes negativos por todas las unidades pagadas |
| Refund **sin** devolución (corrección de precio, gesto comercial) | Potencial recalculado con el nuevo precio neto | **Abierto (D-24)** | **Abierto (D-24)**; recomendación: no descontar automáticamente, marcar incidencia |
| Pago rechazado (PAG-E) | Sigue sin causar (el pago no era verificado) | Con F: no puede ocurrir (solo se causa con pago verificado) | No aplica |
| Pago **reversado** futuro (PAG-I) | Sigue sin causar | Con F: la línea vuelve a `pendiente_pago`… o se anula | **Ajuste negativo**; mecánica de PAG-I aún no aprobada |
| Pedido fraude | Fuera de comisión | Anular líneas | Ajuste negativo |
| Pedido cerrado (`closed` = reembolsado por completo) | Fuera | Anular líneas | Ajustes negativos |
| Retorno de transportadora / entrega fallida | Nunca se causa (D-01) | — | — |
| Reposición / garantía | No comisiona (D-01); registro pendiente (D-26) | — | — |

Decisiones que faltan: **D-24** (refund sin devolución), **D-25** (habilitar RMA), **D-26**
(reposición), RT-01/RT-02 (idempotencia y origen), mecánica de PAG-I, **COM-D-08** y **COM-D-14**
(ajuste a asesor sin liquidaciones futuras).

---

## 5. Reasignación de asesor (Bloque 10)

**Evidencia:** existe `AsignacionAsesorService` (lock, motivo, log inmutable con anterior/nuevo y
snapshots); sin ruta HTTP; 6 logs `initial_assignment`; D-04 pendiente (recomendación: bloquear tras
liquidación pagada). Gap G-A1: no exige perfil activo.

Caso: A vende → admin reasigna a B.

| Opción | ¿Quién cobra? | Ventajas | Riesgos |
|---|---|---|---|
| R1. **Asesor vigente al causar** | Quien sea `advisor_id` cuando la unidad se causa | Simple: la reasignación es la corrección oficial; una sola fuente | Si se reasigna por razones operativas (p. ej. A sale de la empresa), A pierde la comisión de ventas que hizo |
| R2. Asesor original de la venta | A siempre (`initial_assignment`) | Protege a quien cerró la venta | La reasignación pierde sentido económico; hay que distinguir «corrección de error» de «traspaso» |
| R3. Reasignación con **tipo**: `correccion` (cambia beneficiario) vs `traspaso_operativo` (no cambia) | Según tipo elegido con motivo | Expresa ambas realidades | Más complejo; requiere UI y disciplina |

Bloqueos por estado (recomendación técnica, alineada con D-04):
- Antes de causar: reasignación libre con motivo y log (cambia el beneficiario futuro).
- Causada, no liquidada: permitida solo con `comisiones.manage`: anula las líneas y las recausa al
  nuevo asesor, con trazabilidad.
- Incluida en una liquidación pagada: **bloqueada**.

Histórico que debe quedar: el log de atribución (ya existe) + `advisor_id` y snapshot congelados en
cada línea de comisión. **Recomendación técnica: R1 con bloqueos por estado.** → **COM-D-09**.

---

## 6. Asesor inactivo (Bloque 11)

Regla propuesta (técnica, sin decisión de negocio nueva salvo COM-D-14):

1. Inactivar el perfil **no** altera atribuciones, líneas de comisión ni liquidaciones.
2. Las unidades vendidas por un asesor **antes** de inactivarse se causan normalmente cuando se
   entregan, aunque el asesor ya esté inactivo.
3. El cálculo toma `advisor_id` de la atribución y su snapshot; **nunca** usa
   `AsesorActualService::asesorDe()` ni el scope `activos`.
4. Un asesor inactivo puede tener una liquidación abierta y recibir su pago final.
5. Si después de su última liquidación llega un ajuste negativo, queda como **saldo a cargo del
   asesor** pendiente; qué hacer con él es **COM-D-14**.
6. La pantalla de comisiones muestra al asesor inactivo con su estado, sin ocultar su historia.

---

## 7. Entidades futuras (Bloque 12) — solo diseño

**Recomendación: modelo mínimo de 5 tablas** (sin tabla de detalle de liquidación: la línea de
comisión apunta a su liquidación; una línea pertenece como mucho a una liquidación).

### 7.1 `bylopez_va_costos_venta` — snapshot de costo (COM-01)
- **Propósito:** congelar el costo unitario de cada ítem al confirmar el pedido.
- **Claves:** `id`; `order_item_id` **UNIQUE** (fila padre); `order_id`; `variante_order_item_id`
  (hija, null para simples); `product_id` de la variante.
- **Snapshots:** `sku_variante`, `costo_unitario` (moneda base), `fuente` (enum, p. ej.
  `atributo_cost_bagisto`), `fuente_referencia` (attribute_id / versión), `capturado_en`.
- **Estados:** ninguno (inmutable). Una corrección = fila de corrección separada con motivo, nunca
  UPDATE.
- **Invariantes:** un snapshot por ítem; nunca se edita; costo ≥ 0; sin costo disponible → fila con
  `costo_unitario` NULL y `fuente = sin_costo` (visible, nunca 0 inventado).

### 7.2 `bylopez_va_comision_reglas` — reglas con vigencia (COM-03)
- **Propósito:** porcentaje y base vigentes.
- **Claves:** `id`; `vigente_desde`, `vigente_hasta` (null = abierta); `alcance` (`global` hoy;
  asesor/producto/categoría preparados para D-03); `prioridad`.
- **Campos:** `tipo` (`porcentaje_utilidad` hoy), `valor`, `base` (`precio_neto_menos_costo`),
  `utilidad_negativa` (`cero_por_unidad`), `creada_por_admin_id`.
- **Invariantes:** no se solapan dos reglas del mismo alcance y prioridad; una regla usada en alguna
  línea **no se edita** (se cierra y se crea otra).

### 7.3 `bylopez_va_comision_lineas` — línea causada o ajuste (COM-04 / COM-08)
- **Propósito:** unidad(es) comisionadas por un evento de entrega, o un ajuste.
- **Claves:** `id`; `tipo` (`causacion` | `ajuste`); `advisor_id`; `order_id`; `order_item_id`;
  `shipment_item_id`; `evento_entrega_id` (de Logística); `linea_origen_id` (para ajustes);
  `liquidacion_id` (null hasta liquidar).
- **Snapshots:** `advisor_name_snapshot`, `sku_variante`, `cantidad`, `precio_neto_unitario`,
  `descuento_unitario`, `costo_unitario`, `fuente_costo`, `utilidad_unitaria`, `porcentaje`,
  `comision_unitaria`, `comision_total`, `regla_id` + `regla_snapshot` (JSON), `fecha_causante`,
  `sales_channel`, `motivo` (ajustes).
- **Estados:** `causada` → `anulada` (solo si no está en liquidación pagada); los ajustes nacen
  `causada` con monto negativo.
- **Invariantes:** UNIQUE `(evento_entrega_id, shipment_item_id, tipo)` para causación;
  `SUM(cantidad causada por shipment_item) ≤ cantidad entregada`; un ajuste referencia su línea de
  origen y no supera su monto; línea con liquidación pagada = inmutable.

### 7.4 `bylopez_va_comision_eventos` — bitácora inmutable
- **Propósito:** auditoría de causación, anulación, ajuste, inclusión en liquidación y pago (mismo
  patrón que `bylopez_va_pagos_eventos`).
- **Campos:** `linea_id` o `liquidacion_id`, `tipo`, `actor_admin_id`, `mecanismo`, `motivo`,
  `datos` (JSON), `created_at`. Sin `updated_at`; rechaza `save()` sobre existentes.

### 7.5 `bylopez_va_comision_liquidaciones` — liquidación por asesor y periodo (COM-07)
- **Propósito:** agrupar líneas causadas de un asesor para revisar y pagar.
- **Claves:** `id`; `advisor_id`; `periodo_desde`, `periodo_hasta`; UNIQUE `(advisor_id, periodo_desde,
  periodo_hasta)` mientras no esté anulada.
- **Snapshots:** `advisor_name_snapshot`, totales (`total_causado`, `total_ajustes`, `total_a_pagar`),
  `cerrada_por/en`, `pagada_por/en`, `medio_pago`, `referencia_pago`.
- **Estados:** `borrador` → `cerrada` → `pagada`; `anulada` solo desde `borrador` o `cerrada`.
- **Invariantes:** pagada = inmutable (D-02); al cerrar se congelan totales; una línea en una sola
  liquidación.

**Dependencia externa:** la tabla de **eventos de entrega** pertenece a Logística (Fase 06). Comisiones
solo la lee.

**Alternativa más simple descartada:** calcular todo «al vuelo» sin líneas persistidas. Falla con D-02
(no hay nada inmutable que liquidar) y con cambios de reglas o costos.

---

## 8. Estados (Bloque 13)

Se separan **dos ejes** que nunca se mezclan en un solo campo:

### 8.1 Estado de cálculo (por unidad/línea)

| Estado | Persistido | Significado | Sale por |
|---|---|---|---|
| `potencial` | **No** (derivado) | Pedido de asesor confirmado, unidades no entregadas aún | Entrega · cancelación |
| `pendiente_pago` | **No** (derivado) | Entregada, pero sin pago verificado completo (si COM-D-04 = F) | Pago verificado · devolución |
| `causada` | Sí | Línea creada con snapshot completo | Liquidación · anulación |
| `anulada` | Sí | Revertida antes de pagarse (devolución, fraude, reasignación) | — |
| `ajuste` (tipo de línea) | Sí | Línea negativa por un hecho posterior a una liquidación pagada | Siguiente liquidación |

### 8.2 Estado de pago al asesor (por liquidación)

| Estado | Significado |
|---|---|
| `sin_liquidar` (derivado: línea causada sin `liquidacion_id`) | Disponible para el próximo periodo |
| `borrador` | Liquidación armada, editable (incluir/excluir líneas con motivo) |
| `cerrada` | Revisada y aprobada; totales congelados |
| `pagada` | Pago al asesor registrado; inmutable |
| `anulada` | Liquidación descartada antes de pagarse; sus líneas vuelven a `sin_liquidar` |

---

## 9. Liquidación (Bloque 14)

```text
Elegir periodo y asesor(es)
  → seleccionar líneas `causada` con fecha_causante dentro del periodo y sin liquidación
  → sumar ajustes pendientes del asesor
  → BORRADOR: revisar (incluir / excluir con motivo)
  → CERRAR (comisiones.approve): congela totales y líneas
  → PAGAR (comisiones.pay): medio, referencia y fecha; inmutable
```

| Frecuencia | Ventajas | Riesgos |
|---|---|---|
| Semanal | Asesor ve su dinero rápido | Más trabajo administrativo; más ajustes entre periodos |
| Quincenal | Equilibrio; habitual en nómina | — |
| Mensual | Menos trabajo; recomendación previa de D-15 | Espera larga; más devoluciones caen antes de pagar (menos ajustes) |
| Manual (por demanda) | Flexible al inicio | Sin disciplina, periodos irregulares |

**No se elige frecuencia** (D-15 / **COM-D-10**). Entregas confirmadas tarde entran al periodo en que
se registran, como línea normal (recomendación de D-15).

> **2026-10-08 — COM-D-10 DECIDIDA: QUINCENAL.** La frecuencia debe poder cambiar sin alterar comisiones históricas: la
> liquidación guarda su propio `periodo_desde` / `periodo_hasta` (no se deriva de una configuración) y las líneas ya
> liquidadas nunca se recalculan.

---

## 10. UX futura (Bloque 15) — mock textual

Estilo: el mismo de Clientes, Talleres, Asesores, Mis ventas y Cartera (cabecera con icono y
subtítulo, KPI clicables, filtros GET, tabla compacta y tarjetas en móvil).

### 10.1 Vista asesor — «Mis comisiones» (`comisiones.view_own`)

```text
┌ 💰 Mis comisiones ─────────────────────── Periodo: [Octubre 2026 ▾] ┐
│ KPI: Ventas entregadas $ · Utilidad* $ · Causada $ · Pendiente $ · Pagada $ │
│ Filtros: [Estado ▾] [Buscar pedido o cliente]                              │
├────────────────────────────────────────────────────────────────────────────┤
│ Pedido      Cliente    Venta     Utilidad*  %*   Comisión  Estado          Fecha │
│ #BYL-0047   Rosario    82.800    16.800     20%  3.360     Causada         12/10 │
│ #BYL-0049   Carol      44.400     —          —   2.280     Potencial       —     │
│ #BYL-0051   Luis       27.600     —          —   1.120     Pendiente pago  15/10 │
└────────────────────────────────────────────────────────────────────────────┘
 * 2026-10-08 — COM-D-12 DECIDIDA: el asesor NO ve costo, utilidad ni margen. Las columnas «Utilidad» y «%»
   y el KPI «Utilidad» se ELIMINAN de esta vista; quedan Venta, Unidades, Comisión y Estado.
 Montos ilustrativos: costo 5.500, precios 6.900/7.400 y 20 % (propuesta, no aprobada).
 Detalle: unidades, entrega, línea y liquidación; sin botones de edición.
```

- «Potencial» siempre con aviso: «No es un valor a pagar hasta que se entregue (y se pague)».
- Sin acciones: el asesor solo consulta.

### 10.2 Vista admin — «Comisiones» (`comisiones.view_all`)

```text
┌ 💰 Comisiones ───────────────────────────────────────── [Nueva liquidación] ┐
│ KPI: Causado sin liquidar · En liquidación · Pagado en el periodo · Ajustes pendientes │
│ Filtros: [Asesor ▾] [Periodo ▾] [Estado ▾]                                   │
├──────────────────────────────────────────────────────────────────────────────┤
│ Asesor        Unidades  Venta     Utilidad  Comisión  Ajustes  A pagar  Estado   │
│ Asesor Prueba 18        124.200   25.200    5.040     0        5.040    Borrador │
│   [Ver detalle] [Revisar] [Liquidar]                                          │
└──────────────────────────────────────────────────────────────────────────────┘
Pestañas: Líneas · Liquidaciones · Ajustes · Reglas (manage)
```

---

## 11. Reportes (Bloque 16)

| Reporte | Tipo | Contenido |
|---|---|---|
| Por asesor | Operativo | Unidades potenciales, entregadas, causadas; pendiente de pago |
| Por periodo | Financiero | Causado, liquidado, pagado, ajustes |
| Por pedido | Operativo | Unidades, entregas, estado de cada línea |
| Por producto / variante | Financiero | Unidades, precio neto medio, costo congelado, utilidad, comisión |
| Por margen | Financiero | Distribución de utilidad unitaria; unidades con utilidad ≤ 0 |
| Por comisión | Financiero | Líneas por estado; anulaciones y motivos |
| Por liquidación | Financiero | Cabecera, líneas, ajustes, pago y referencia |

**Operativo** (asesor y supervisor): unidades y estados, sin costos. **Financiero** (solo admin con
`view_all`): costos, utilidad, totales y pagos. Fuente: líneas congeladas; nunca recalcular con datos
actuales.

---

## 12. Seguridad y ACL (Bloque 17)

Se reutilizan las claves **ya reservadas** en `Config/acl.php` desde la Fase 01 (no se crean nuevas):

| Clave | Uso propuesto | Rol inicial |
|---|---|---|
| `ventas_asesor.comisiones` | Agrupador / menú | Asesor y admin |
| `ventas_asesor.comisiones.view_own` | «Mis comisiones» (`advisor_id` = sesión) | ASESOR VENTAS |
| `ventas_asesor.comisiones.view_all` | Todas las comisiones y reportes financieros | Admin `all` |
| `ventas_asesor.comisiones.manage` | Reglas, anulaciones, recausar tras reasignación, ajustes manuales con motivo | Admin `all` |
| `ventas_asesor.comisiones.approve` | Revisar y **cerrar** liquidaciones | Admin `all` |
| `ventas_asesor.comisiones.pay` | Registrar el **pago** de una liquidación | Admin `all` |

El asesor **nunca** edita: costo, utilidad, porcentaje, comisión calculada, estado de línea o
liquidación. Toda escritura se autoriza en el servidor; ocultar botones no es seguridad. Separar
`approve` y `pay` permite, más adelante, que dos personas distintas cierren y paguen.

---

## 13. Implementado en desarrollo (2026-10-08, sin commit) — COM-01, COM-02, COM-03

> **Punto de parada:** termina en COM-03. **No existe** causación, evento de entrega, líneas de comisión, liquidaciones,
> pagos de comisión, ajustes ni pantallas de comisiones. COM-04 depende de Logística (entrega).

### 13.1 COM-01 — Snapshot de costo (§7.1 implementada)

- **Tabla `bylopez_va_costos_venta`** (migración `2026_10_08_000015`): `order_id` (FK RESTRICT), `order_item_id` (fila
  padre, **UNIQUE**, FK RESTRICT), `variante_order_item_id`, `product_id`, `variant_product_id` (sin FK: el snapshot
  sobrevive al borrado del producto), `sku_snapshot`, `tipo_producto`, `costo_unitario` decimal(12,4) **NULL o > 0**
  (CHECK), `moneda` (moneda base del pedido), `fuente`, `fuente_referencia`, `autoridad_comision` (default **false**),
  `capturado_en`, timestamps.
- **Modelo `CostoVenta`**: inmutable (rechaza update/save/delete de filas existentes; `CostoVentaInmutableException`).
- **Enum `FuenteCostoVenta`**: `atributo_cost_bagisto_referencia` | `sin_costo`; `otorgaAutoridadComision()` = false
  para ambas (COM-D-02).
- **`Services\Comisiones\CapturarCostoVentaService::capturarPedido(orderId)`**: por lote (lecturas constantes por
  pedido), idempotente (lectura previa + UNIQUE; reintento = no-op), nunca lanza por falta de costo.
  - Simple: costo del propio producto. Configurable: costo de la **variante** (hija `product_id`/`sku`, o
    `additional.selected_configurable_option`); el costo del padre nunca se usa. Una sola fila por unidad vendida.
  - `cost` del canal del pedido si existiera; si no, el global.
  - Sin costo: `NULL` + `sin_costo` con motivo en `fuente_referencia`: `cost_ausente`, `cost_no_positivo` (un 0 en un
    atributo de precio de Bagisto es «no cargado»), `variante_no_resuelta`, `atributo_cost_inexistente`,
    `tipo_no_soportado:<tipo>`.
- **Listener `CapturarCostoAlCrearPedido`** en `checkout.order.save.after` (dentro de la transacción del pedido, con el
  Order y todas las OrderItems ya creados). Escribe en un **savepoint**; **nunca relanza** (un fallo deja el pedido sin
  snapshot y un log `ventas_asesor.costo_venta.no_capturado` con campos acotados).
- **Diagnóstico** `php artisan ventas-asesor:diagnostico-costos` (solo lectura): pedidos/líneas con snapshot, líneas sin
  costo, con autoridad, por fuente, motivos sin costo y pedidos creados desde la activación **sin** snapshot.
- **Históricos:** sin backfill (COM-D-13).

### 13.2 COM-02 — Motor de utilidad (solo lectura)

- **`Services\Comisiones\UtilidadVentaService`** (`dePedido`, `dePedidos` por lote, 2 consultas):

```text
precio_neto_unitario = base_price − base_discount_amount / qty_ordered     (línea padre; moneda base; sin IVA ni envío)
cantidad             = qty_ordered − qty_canceled
utilidad_unitaria    = max(0, precio_neto_unitario − costo_unitario_congelado)        (por unidad, COM-D-07)
utilidad_total       = utilidad_unitaria × cantidad
```

- DTO `LineaUtilidad`: pedido, ítem, SKU, cantidad, precio neto unitario, costo snapshot, fuente, autoridad, utilidad
  unitaria y total **diagnósticas**, utilidad unitaria y total **autorizadas** (solo con autoridad; hoy null),
  `margenNegativo`, estado. DTO `UtilidadPedido`: líneas + `utilidadDiagnostica()`, `utilidadAutorizada()` (null salvo
  que **todas** las líneas estén autorizadas), `lineasSinCosto()`.
- Enum `EstadoUtilidadLinea`: `diagnostica` · `autorizada` · `sin_costo` · `sin_snapshot` · `sin_cantidad`. Sin costo o
  sin snapshot, la utilidad es **null** (nunca «utilidad = precio») y la línea queda fuera de los totales.
- No usa `grand_total` ni el `cost` actual. Reembolsos y devoluciones no se descuentan aquí (COM-08). No se expone al
  asesor.

### 13.3 COM-03 — Reglas con vigencia + comisión potencial diagnóstica

- **Tabla `bylopez_va_comision_reglas`** (migración `2026_10_08_000016`): `codigo` UNIQUE, `nombre`, `tipo`
  (`porcentaje_utilidad`), `base` (`utilidad_positiva`), `porcentaje` en puntos (CHECK 0 < p ≤ 100), `alcance`
  (`global`), `prioridad`, `vigente_desde`, `vigente_hasta` (NULL = abierta; CHECK hasta > desde), `activo`,
  `creado_por_admin_id`, `mecanismo`, `motivo`, timestamps.
- **Modelo `ReglaComision`**: los campos de cálculo (`codigo`, `tipo`, `base`, `porcentaje`, `alcance`, `prioridad`,
  `vigente_desde`) **nunca cambian**; `vigente_hasta` se fija una sola vez; `activo = false` solo para cancelar una regla
  que todavía no rige; no se borra (`ReglaComisionInvalidaException` con códigos estables).
- **`ReglasComisionService`**: `vigenteEn(fecha)` y `programar()` (no retroactiva; rechaza solapes con reglas futuras;
  cierra la abierta anterior **sin** cambiar su porcentaje). Ningún servicio tiene un porcentaje por defecto.
- **Regla inicial** creada en desarrollo con `php artisan ventas-asesor:comisiones-programar-regla --persistir`:
  #1 `COM-GLOBAL-INICIAL`, 20 %, desde 2026-10-08 07:08:14, abierta, `mecanismo = cli`, motivo «COM-D-03 aprobada por
  Camilo Gaitán 2026-10-08…».
- **`ComisionPotencialService`** → DTO `ComisionPotencialDiagnostica`:
  `monto_diagnostico = utilidad_diagnostica × porcentaje de la regla vigente`. Solo ventas `advisor`. Los campos
  `causada`, `pagable` y `liquidable` son **siempre false** por construcción y `esDiagnostica = true`. Motivos de no
  pagable: `entrega_no_implementada` y `pago_no_evaluado` (siempre), `costo_no_autorizado`, `lineas_sin_costo`,
  `pedido_sin_snapshot`, `propiedad_no_asesor`, `sin_regla_vigente`. La regla usada es la vigente **ahora**; la
  comisión real usará la vigente en la fecha de entrega (D-01).

### 13.3b Actualización D-COST (2026-10-08, segunda ronda)

- **COM-01b:** `fuente = cost_bagisto_comisionable` y `autoridad_comision = true` cuando el `cost` de la variante es > 0
  (D-COST-02). La utilidad de COM-02 se documenta como **MARGEN COMISIONABLE** (el código conserva el nombre
  «utilidad»; nunca es utilidad real de la empresa). Con costo válido, la línea queda `autorizada` y la comisión
  potencial deja de llevar `costo_no_autorizado`; sigue **potencial** (faltan entrega y pago total verificado).
- Control de costos, comparación productiva y actualización masiva: Doc. 19.

### 13.4 Lo que COM-04 debe respetar de COM-01…03

1. Causar solo con `autoridad_comision = true` en **todas** las líneas causadas (desde D-COST-02: todo costo comisionable > 0).
2. Tomar la regla vigente en la **fecha causante** (entrega) y congelar `regla_id` + snapshot en la línea.
3. Leer utilidad desde `UtilidadVentaService`, nunca recalcular con el catálogo.
4. Resolver antes el marcador de muestras / reposiciones / garantías (G-11).

---

## 14. Entrega canónica — DISEÑO (Fase 06; no implementada)

> Auditoría en el Doc. 16 §12.2: hoy no existe ninguna fuente de entrega. Este diseño no se implementa hasta que Camilo
> responda LOG-D-01…04 (Doc. 04).

### 14.1 Estados separados (nunca inferidos unos de otros)

| Ciclo | Fuente | Ejemplo | Lo que NO significa |
|---|---|---|---|
| Pedido | `orders.status` (Bagisto) | `completed` | No es entrega |
| Pago | Cartera (D-59) | saldo verificado = 0 | No es entrega |
| Despacho | `shipments` (Bagisto) | Shipment creado | No es entrega |
| Transportadora | Catálogo futuro (D-42) → `shipments.carrier_code` | `99envios` | — |
| Guía | `shipments.track_number` (D-43) | número real | No es entrega |
| **Entrega** | **Evento propio** (§14.2) | `entregado` | — |
| Devolución | Evento propio distinto (RMA / Logística) | `devuelto` | No borra la entrega |

### 14.2 Evento canónico `entrega_confirmada` (propuesta)

Tabla propia (propuesta: paquete `ByLopez/Logistica`, D-45; VentasAsesor solo la lee):

- `bylopez_lg_entregas`: `id`, `order_id`, `shipment_id`, `fecha_entrega` (la fecha real informada, no la de registro),
  `fuente` (`bodega_manual` · `supervisor_manual` · `recogida_en_bodega` · `integracion_transportadora`), `actor_admin_id`
  + nombre snapshot, `carrier_code` y `track_number` **snapshot**, `evidencia_referencia` (D-09), `estado`
  (`confirmada` · `anulada_por_correccion`), `idempotency_key` UNIQUE, timestamps.
- `bylopez_lg_entrega_items`: `entrega_id`, `shipment_item_id`, `order_item_id`, `cantidad` (entregas parciales), con
  la invariante `Σ cantidad entregada por shipment_item ≤ shipment_items.qty`.
- **Idempotente** (clave única por origen + referencia), **auditable** (actor, fuente, fecha) e **inmutable**: no se
  edita; una corrección es un evento de anulación con motivo y un evento nuevo.
- Una **devolución** posterior es OTRO evento (no modifica la entrega): ajuste de comisión si ya estaba pagada (D-02).
- Recogida en bodega: la entrega ocurre cuando el cliente retira; si el Shipment se crea en ese momento, despacho y
  entrega coinciden en fecha pero siguen siendo dos registros.

### 14.3 Quién confirma (COM-D-11)
El asesor **nunca**. Bodega, supervisión o una integración confiable, con la hoja ACL reservada
`ventas_asesor.logistica.manage` (o la que defina el paquete Logística). Pendiente LOG-D-02: qué rol/usuarios.

### 14.4 Devoluciones (solo radar)
- Comisión **no causada**: la unidad devuelta nunca se causa.
- Comisión **pagada**: ajuste negativo en la siguiente liquidación (D-02, COM-D-14).
- Costos de la devolución (envío de retorno, seguro, recargos) → rentabilidad real (Doc. 19 §7), **nunca** al margen
  comisionable.

---

## 15. G-11 — muestras, reposiciones y garantías: opciones (decisión COM-D-15)

| Opción | Cómo | Ventajas | Riesgos |
|---|---|---|---|
| **A. Clasificación comercial del pedido** (tabla propia 1:1 con `orders`, como la atribución) | Valor `venta_normal` · `muestra` · `reposicion` · `garantia`, elegido al crear el pedido (Nueva venta) y auditado | Estructurado, auditable, sin tocar Bagisto; excluye el pedido completo de comisión | No sirve si un mismo pedido mezcla una reposición con prendas vendidas |
| B. Clasificación por línea | Lo mismo por `order_item` | Admite pedidos mixtos | Más UI y más errores operativos |
| C. Solo precio 0 (D-26) | Reposición/garantía/muestra siempre como pedido de importe 0 | Sin dato nuevo: `max(0, 0 − costo) = 0` | Una muestra o garantía con cobro parcial comisionaría; depende de disciplina |
| D. Texto libre / comentario | — | — | No auditable: **descartada** |

**Recomendación técnica:** A (pedido) + C como regla operativa (reposiciones y garantías a 0); B solo si existen
pedidos mixtos reales. No se crea una orden paralela: el pedido de Bagisto sigue siendo la fuente.

## 16. Entrega canónica: IMPLEMENTADA en desarrollo (LOG-01, 2026-10-08)

El diseño de §14 se implementó en `ByLopez/Logistica` con las decisiones LOG-D-01…04. Referencia técnica completa: `packages/ByLopez/Logistica/docs/MAPA_DOMINIO_LOGISTICA.md`.

**Diferencias con la propuesta de §14:**
- El estado se llama `confirmado` / `anulado`, y la anulación vive en el mismo registro (motivo, quién y cuándo).
- Hay retorno de transportadora (`bylopez_lg_retornos`) como evento hermano.
- La transportadora se guarda como snapshot del código del catálogo. Si el despacho no tiene código, el operador elige una del catálogo al registrar la entrega.

**Contrato para COM-04 (solo lectura):**
- Unidades entregadas por `order_item_id` (línea padre) con `fecha_entrega` real: `ConsultaEntregaService::unidadesEntregadas($orderIds)`.
- Una entrega anulada deja de contar.
- COM-04 deberá definir qué hacer con una línea **ya causada** cuya entrega se anula después (ajuste, D-02 / COM-08). Hoy no hay líneas causadas.

## 17. Clasificación comercial del pedido: IMPLEMENTADA en desarrollo (G-11, COM-D-15)

- **Tabla** `bylopez_va_clasificaciones_pedido`: `order_id` UNIQUE (FK RESTRICT), `tipo_operacion` (CHECK), `origen = nueva_venta`, `borrador_id`, actor y nombre snapshot. Inmutable.
- **Escritura:** `ConfirmarVentaService::confirmar(..., $tipoOperacion)` valida el tipo **antes** de tomar el borrador.
  - Sin valor = `venta_normal`.
  - Tipo desconocido: `tipo_operacion_invalido`.
  - Muestra, reposición o garantía sin `ventas_asesor.pedidos.tipo_operacion`: `tipo_operacion_sin_permiso` (HTTP 403).
  - El tipo viaja en `ConfirmacionEnCurso` hasta el listener `ClasificarOperacionPedido` (segundo de `checkout.order.save.after`, tras el enlace con el borrador), que escribe la fila en la transacción del pedido. Si falla, el pedido se revierte.
- **UI:** selector discreto «Tipo de operación» sobre el botón Confirmar de Nueva venta, visible solo con permiso.
  - Valor por defecto: Venta normal.
  - Muestra un aviso si es reposición o garantía con total > 0, y una nota en las muestras.
  - La vista nativa del pedido muestra el tipo no normal y la misma advertencia (hook `title.after`).
- **Regla de comisión preparada** (no causa nada): `TipoOperacionPedidoService::esComisionable($orderId)` es true solo para `venta_normal`. Sin clasificación devuelve false.
- **No se impone precio 0** desde el código; solo se advierte.
