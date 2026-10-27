# 19 · Control de costos y rentabilidad

**Fecha:** 2026-10-08 · **Estado:** costo comisionable y Control de costos **IMPLEMENTADOS EN DESARROLLO** (sin commit);
rentabilidad real = **RADAR** (no implementar) · **Responsable funcional:** Camilo Gaitán

> Documento permanente del dominio de costos. Decisiones en `04_DECISIONES_PENDIENTES.md` §D-COST. Comisiones en los
> Docs. 16–18. Si este documento contradice el código, manda el código.
>
> **Actualización 2026-10-15:** el vocabulario de ganancia (utilidad bruta, contribución, utilidad del período, caja), las
> fuentes reales de cada costo, los estados del costo (confirmado / provisional / vencido / desconocido), el reparto de costos
> compartidos y el **precio mínimo** están en `22_PRECIO_MINIMO_RENTABILIDAD_Y_COMISIONES.md`. Cambió desde esta versión: la
> tabla de §3 ya no es exacta en «Pago al cortador» y «Confección»: CorteConfeccion tiene **tarifas por referencia y tela**
> con vigencias y la orden conserva su tarifa (Doc. CorteConfeccion 05), aunque el pago al prestador todavía no existe. El
> flete real por envío existe en las cuentas de transportadora (movimiento `servicio`).

---

## 1. Cuatro conceptos que NO se mezclan (D-COST-01)

| Concepto | Pregunta que responde | Fuente | Quién lo usa | Estado |
|---|---|---|---|---|
| **Costo comisionable** | ¿Con qué costo se calcula la comisión? | `cost` de Bagisto (variante), fijado por la empresa y **congelado al vender** | Motor de comisiones | **Oficial** (D-COST-02) |
| **Margen comisionable** | ¿Sobre qué se paga la comisión? | `max(0, precio neto unitario − costo comisionable congelado)` por unidad | Comisiones | Implementado (COM-02) |
| **Costo productivo** | ¿Cuánto cuesta producir la prenda? | CorteConfeccion (hoy **parcial: solo tela**) | Administración: comparar y alertar | Referencia (D-COST-07) |
| **Utilidad real de la empresa** | ¿Cuánto gana el negocio de verdad? | Futuro módulo de rentabilidad | Gerencia | **Radar** (§7) |
| *(además)* **Caja disponible** | ¿Cuánto dinero hay? | Futuro módulo financiero | Gerencia | Radar |

Reglas: margen comisionable ≠ margen productivo ≠ utilidad real ≠ caja. No se usa «margen neto» para la comisión.
La eficiencia productiva **no** sube la comisión (D-COST-03); un costo productivo mayor **alerta** pero no cambia
Bagisto (D-COST-04).

Ejemplo oficial: precio 8.500 · costo comisionable 5.000 · margen comisionable 3.500 · comisión 20 % = 700. Si producir
cuesta 4.700, la comisión sigue en 700 (los 300 son de la empresa). Si producir cuesta 5.200, se muestra
«Revisar costo» (+200) y Camilo decide.

---

## 2. Costo comisionable: cómo funciona

### 2.1 Bagisto (auditado)
- Atributo `cost` (id 12 en desarrollo; se resuelve siempre por `code`), tipo `price`, `value_per_channel = 0`,
  `value_per_locale = 0`, validación `decimal`. Vive en `product_attribute_values.float_value` (EAV), una fila por
  producto, `unique_id = "<product_id>|<attribute_id>"`.
- Solo las **variantes simples** tienen costo (300 de 303; ningún configurable). El costo del configurable padre nunca
  se usa.
- `ProductAttributeValueRepository::saveValues()` **sobrescribe** la fila: Bagisto **no guarda historial**. Un precio
  vacío se guarda como NULL. Un 0 se interpreta como «no cargado».

### 2.2 Snapshot al vender (COM-01b)
- `bylopez_va_costos_venta`: al crear el pedido, `fuente = cost_bagisto_comisionable` y **`autoridad_comision = true`**
  si el `cost` de la variante es > 0. Sin valor: `costo_unitario = NULL`, `fuente = sin_costo`, sin autoridad.
- Inmutable. Una actualización posterior del catálogo **no** cambia ventas pasadas (D-COST-06); la venta siguiente
  congela el costo nuevo. Probado (`CostosControlTest` COST-1).
- Fuente histórica `atributo_cost_bagisto_referencia` (sin autoridad) se conserva solo para leer filas antiguas; al
  aplicar D-COST-02 había **0** snapshots, así que no se convirtió nada.

### 2.3 Actualización masiva (D-COST-05)
Flujo obligatorio: **selección → vista previa → confirmación → actualización → auditoría**.

- Selección: casillas de la página o «todas las del filtro actual»; filtros por texto (SKU / producto), **familia de
  atributos de Bagisto** (la línea, sin listas fijas Hombre/Dama/Niño) y estado.
- Vista previa: variantes, SKU, producto, costo actual, nuevo, diferencia, cuántas cambian y cuántas ya tienen ese costo,
  rango de costos actuales e impacto («las ventas nuevas usarán el costo nuevo; las pasadas no cambian»). No escribe.
- Confirmación: exige el **token** de la vista previa (HMAC de ids + costo nuevo + costos actuales). Si alguien cambió
  un costo entretanto, se rechaza (`vista_previa_requerida`).
- Validación: costo nuevo decimal > 0, hasta 2 decimales; nunca NULL, 0 ni negativo; máximo 1.000 variantes por lote;
  solo productos `simple`.
- Escritura: repositorio EAV de Bagisto, solo la fila de `cost` (no reindexa: `cost` no participa en precios ni en
  `product_flat`), dentro de una transacción con bloqueo de las filas.
- Auditoría: `bylopez_va_costo_comisionable_cambios` (lote, producto, SKU y producto snapshot, costo anterior y nuevo,
  actor y su nombre, mecanismo `panel`, motivo, fecha). Solo de adición. **No** es fuente del costo de una venta.
- **Límite conocido:** editar el costo desde la ficha nativa del producto **no** deja traza en la bitácora (Bagisto no
  emite el valor anterior). Recomendación futura: usar solo «Control de costos» para cambiar costos, o un listener de
  `catalog.product.update.before/after` que registre la diferencia.

### 2.4 Permisos
- `ventas_asesor.costos` (ver Control de costos) y `ventas_asesor.costos.actualizar` (vista previa + confirmación).
  El servicio también valida. Solo administración; el rol ASESOR VENTAS **no** las tiene (probado: 401 y
  `sin_permiso`).

---

## 3. Auditoría de CorteConfeccion — costo productivo disponible HOY (solo lectura)

Datos de desarrollo al 2026-10-08: 10 órdenes de corte (5 con recepción en bodega), 28 rollos, 44 detalles de corte,
8 órdenes de confección, 2 talleres, 1 cortador.

| Componente | Existe | Fuente | Campo | Calidad | ¿Se puede usar hoy? | Falta |
|---|---|---|---|---|---|---|
| **Valor de la tela** | Sí | `bylopez_cc_rollos` | `valor_kilo`, `costo_total` | Buena; 1 de 28 rollos con `valor_kilo = 0` y `costo_total` > 0 (inconsistente) | **Sí** (rollos con `valor_kilo` > 0) | Corregir rollos sin valor por kilo |
| Compra de tela | Sí | `bylopez_cc_compras_tela` | `subtotal`, `descuento`, `impuesto`, `total_factura` | Buena | Indirecto (vía rollo) | — |
| **Kilos usados por orden** | Sí | `bylopez_cc_ordenes_corte` + pivote `orden_corte_rollos` | `peso_inicial_usado` (pivote), `peso_sobrante_total`, `merma_total` | Media: el pivote tiene `peso_consumido = 0` y `peso_sobrante = 0` en todas las filas; el consumo real solo vive a nivel de orden | **Sí**, a nivel de orden | Consumo por rollo |
| **Piezas producidas** | Sí | `ordenes_corte` / `ordenes_corte_detalles` | `total_piezas_cortadas`, `cantidad_real_cortada` | Buena | **Sí** | — |
| Variante producida | Sí | `ordenes_corte_detalles` | `product_id` = **simple de Bagisto** (`variant_id` sin uso, NULL en las 44 filas) | Buena | **Sí** | — |
| Rendimiento por talla | No | — | `peso_promedio_pieza` es promedio de **toda** la orden | Baja | No (se asume igual por pieza) | Peso por talla |
| **Pago al cortador** | **No** | `bylopez_cc_cortadores` sin campos monetarios | — | — | **No** | Tarifa/pago por pieza u orden |
| **Confección (satélite/taller)** | **No** | `ordenes_confeccion(_detalles)`, `talleres` sin campos monetarios | — | — | **No** | Pago por prenda al taller |
| **Transporte productivo** | **No** | — | — | — | **No** | Fletes de tela / taller |
| Otros directos (insumos, estampado, empaque) | **No** | — | — | — | **No** | — |
| Defectuosas / faltantes | Sí | `recepciones_corte_detalles`, `retorno_detalles` | `cantidad_defectuosa`, `cantidad_faltante` | Buena | No se usa hoy (el costo se reparte sobre piezas **cortadas**) | Política de merma de prendas |

### 3.1 Fórmula del costo productivo PARCIAL (solo tela)

```text
valor_kilo_orden = Σ(peso_inicial_usado × valor_kilo) / Σ peso_inicial_usado      (rollos de la orden)
kilos_usados     = Σ peso_inicial_usado − peso_sobrante_total                     (incluye la merma: tela pagada)
tela_por_pieza   = kilos_usados × valor_kilo_orden / total_piezas_cortadas
costo_variante   = promedio ponderado por cantidad_real_cortada de las órdenes válidas
```

Órdenes válidas: `recibido_bodega` o `cerrado`, piezas > 0 y **todos** sus rollos con `valor_kilo` > 0. Implementado
en `Services\Costos\CostoProductivoReferenciaService` (lee las tablas; no importa clases de CorteConfeccion; no escribe).

En desarrollo: **26 variantes** con costo de tela (≈ 881 por pieza en la orden más grande) frente a un costo
comisionable de 5.500 → estado **Parcial**. Ninguna en «Revisar».

### 3.2 Por qué se llama PARCIAL y nunca «real»
Falta corte, confección, transporte productivo y otros directos. El valor es una **cota inferior**: si aun así supera el
costo comisionable, la alerta es cierta; si no, no se concluye nada.

---

## 4. Pantalla «Control de costos» (Ventas Asesor › Control de costos)

- **Cabecera:** «Control de costos» — «Compara el costo comisionable de catálogo con el costo productivo y administra
  actualizaciones de forma segura.»
- **KPI:** variantes con costo (de N vendibles) · sin costo comisionable · producción > comisionable (clicable, filtra
  «Revisar») · actualizaciones recientes (cambios y lotes en 30 días).
- **Aviso** permanente de costo productivo parcial (o de ausencia de datos de CorteConfeccion).
- **Tabla (escritorio):** producto / variante (SKU) · familia · costo comisionable · costo productivo (con «Parcial: solo
  tela · N piezas») · diferencia $ y % · estado · última actualización (de la bitácora; «Sin cambios registrados» si
  no hay).
- **Tarjetas:** 2 columnas en tablet y 1 en móvil (SKU/producto, comisionable, productivo, diferencia, estado, casilla).
  Sin scroll horizontal.
- **Estados** (`EstadoControlCosto`): `sin_costo_comisionable` · `sin_datos_produccion` · **`revisar`** (productivo >
  comisionable) · `parcial` (no concluyente) · `ok` y `favorable` (solo con costo productivo **completo**; hoy no se
  alcanzan).
- **Tolerancia:** ninguna. Se alerta apenas productivo > comisionable. Un porcentaje de tolerancia sería configuración
  futura **decidida por Camilo** (no se inventa 5 % ni 10 %).
- Solo informa: **nunca** cambia el costo automáticamente.

---

## 5. Radar productivo (pendientes de CorteConfeccion, sin modificarlo)

Para que el costo productivo deje de ser parcial, CorteConfeccion debería registrar:

1. Pago por prenda al satélite / taller de confección.
2. Pago al cortador (por pieza o por orden).
3. Costo real de corte y de confección por orden.
4. Transporte productivo (tela al cortador, piezas al taller, retorno a bodega).
5. Otros directos: insumos, estampado, marquilla, empaque de producción.
6. Peso por talla (rendimiento) si se quiere un costo por talla y no promedio de orden.
7. Corregir rollos con `valor_kilo = 0` y consumo por rollo en el pivote.

No bloquea el costo comisionable. Cuando exista, `CostoProductivoReferenciaService` sumará componentes y marcará
`completo = true`; entonces aparecerán los estados `ok` y `favorable`.

---

## 6. Pruebas (dirigidas)

| Prueba | Cubre |
|---|---|
| `Com01SnapshotCostoTest` (4) | Captura con autoridad; variante real del configurable; catálogo editado no altera snapshot; sin costo = NULL/sin autoridad; idempotencia |
| `Com02UtilidadVentaTest` (2) | Margen comisionable autorizado; sin costo; utilidad negativa = 0 por unidad |
| `Com03ReglasComisionTest` (2) | Regla vigente; potencial sin `costo_no_autorizado`; vigencias |
| `CostosControlTest` (4) | Pedido anterior 5.000 / posterior 5.200 + bitácora; vista previa obligatoria y validaciones; comparación REVISAR/PARCIAL/SIN COSTO/SIN DATOS; permisos (admin ve; asesor 401 y `sin_permiso`) y render de la vista previa sin escribir |
| `AclYMenuConfiguracionTest` (11) | 36 claves, menú con Control de costos |

---

## 7. RADAR — Administración / Rentabilidad del negocio (NO implementar ahora)

**Objetivo futuro:** que Camilo vea la **ganancia real** después de ventas, costo de producción, comisiones, transporte,
devoluciones, seguros, empaques, plataformas, nómina, arriendo, servicios, merma y otros gastos.

| Capa | Fórmula conceptual | Fuente futura |
|---|---|---|
| Ventas | Σ ventas netas entregadas (sin IVA) | Pedidos + entrega |
| Costo de producto | Σ costo productivo (completo) | CorteConfeccion ampliado |
| **Margen comercial** | Ventas − costo de producto | Derivado |
| Comisiones | Σ comisiones causadas | Comisiones (COM-04+) |
| Gastos logísticos | Fletes, recaudo contra entrega, seguros, devoluciones | Logística + transportadoras |
| Devoluciones | Valor y costo de prendas devueltas (envío de retorno, recargos) | RMA / Logística |
| Gastos generales | Nómina, arriendo, servicios, plataformas | Módulo administrativo nuevo |
| **Utilidad real** | Margen comercial − comisiones − gastos logísticos − devoluciones − gastos generales | Derivado |
| **Caja** | Entradas verificadas − salidas pagadas | Cartera + egresos (nuevo) |

**Dashboard futuro (documentado, sin implementar).** KPI: ventas · costo de producto · margen comercial · comisiones ·
costo de producción · gastos logísticos · devoluciones · nómina · gastos generales · utilidad real · caja.
Alertas: producción > costo comercial · devoluciones elevadas · margen bajo · gastos crecientes · caja insuficiente.

Los costos de una devolución (envío de retorno, seguro, recargos) pertenecen a esta capa, **nunca** al margen
comisionable.

## 8. Actualización 2026-10-08 (tercera ronda): insumos nuevos para la rentabilidad futura

Sin cambios en el costo comisionable. Las dos piezas nuevas alimentarán el radar de rentabilidad (§7), **sin implementarlo**:
- **Retornos de transportadora** (`bylopez_lg_retornos`, LOG-01): unidades que no llegaron al cliente, con motivo y transportadora. Son la base futura de «devoluciones / retornos» y de los costos logísticos de retorno, que **nunca** entran al margen comisionable.
- **Clasificación comercial del pedido** (G-11): muestras, reposiciones y garantías quedan identificadas. En la rentabilidad real serán **costo comercial** (producto entregado sin venta), separado de las ventas normales.
