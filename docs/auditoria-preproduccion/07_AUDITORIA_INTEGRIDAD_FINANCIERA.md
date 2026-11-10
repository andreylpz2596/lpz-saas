# 07 · Integridad financiera transversal

Cobertura: mapa de fuentes de dinero, restricciones de BD y documentación; sin cálculos sobre datos reales ni cambios de fórmulas. Leyenda [C]/[D]/[S].

## 1. Mapa de libros (quién es fuente de verdad)

| Dinero | Libro / tabla | Se confirma por | Protección [C] |
|---|---|---|---|
| Cobro de clientes | `bylopez_va_pagos` (+ aplicaciones a pedidos) | revisión de Cartera (`confirmado`) | UNIQUE `(mecanismo, idempotency_key)` |
| Crédito del cliente (D10) | `bylopez_va_creditos_cliente_movimientos` (+ `ajustes_obligacion`) | servicios con alcance «todos» | UNIQUE idempotencia; ledger derivado; **sin versionar** |
| Recaudo de transportadoras | libro firmado de Logística (`cuentas_transportadora`) | liquidación `LIQ` inmutable | UNIQUE idempotencia; Σ=0 por liquidación [D] |
| Comisiones | líneas + liquidaciones + abonos/reversos | `pagar` + comprobante opcional | UNIQUE idempotencia; GET_LOCK |
| Cartera de cortadores/satélites | movimientos + aplicaciones (cuenta **derivada**) | administración | `huella_activa` UNIQUE [D] |
| Costos | `bylopez_va_costos_venta` (captura por venta), `costo_comisionable_cambios` (historial), `costos_directos` | `CapturarCostoVentaService` | histórico no se sobrescribe [D] |
| Fletes / pagador del flete | Logística + D10 flete | reglas D5/D6 (aprobadas, sin versionar) | [D] |

Todas las monedas se guardan como `decimal` (94 columnas `decimal`, **0** `float/double`) [C].

## 2. Búsqueda de inconsistencias pedidas

| Riesgo | Resultado |
|---|---|
| Pagos duplicados | Cubierto por UNIQUE de idempotencia en cada libro [C]; reintento con clave nueva depende de la huella de operación (solo comisiones y cartera de prestadores la usan) → [S] **V-12**: pagos de clientes con misma referencia bancaria y distinta clave |
| Comisiones dobles | UNIQUE + deduplicación [C/D] |
| Gastos contados dos veces | Costos de fabricación vs. costos directos de venta: separados por diseño (doc 22/23) [D]; [S] **V-13** conciliar `Σ costos capturados` con rentabilidad panel |
| Recaudos sin confirmación | El reporte del proveedor no confirma [D]; recaudo nace del cobro (C6) [D] |
| Movimientos sin auditoría | Cada libro conserva usuario/fecha/idempotencia; `forceFill` de Logística acotado a correcciones auditadas [C] |
| Diferencias pedidos ↔ cartera | No existe una **consulta de reconciliación global** que las detecte periódicamente → AUD-021 (P2): recomendable un reporte de solo lectura |
| Costos históricos sobrescritos | `ActualizarCostoComisionableService` actualiza masivamente con vista previa y confirmación; el histórico por pedido queda capturado [D] |
| Estados incompatibles | Hay ~110 restricciones `CHECK`/`ADD CONSTRAINT` en las migraciones ByLopez (coincidencias por texto) que protegen combinaciones de columnas; los estados cruzados entre tablas dependen de la aplicación; [S] V-14: consulta de estados imposibles (pedido `entregado` sin guía, pago confirmado sin pedido) |

## 3. Riesgo estructural transversal (AUD-002) — validado en la 2.ª etapa

**Configuración (confirmada):** `config/database.php` líneas 58 y 78: `'strict' => false` (mysql y mariadb); la sesión corre con `sql_mode=NO_ENGINE_SUBSTITUTION`. Es el valor por defecto de Bagisto. Además `bootstrap/app.php` (línea 34) **quita `ConvertEmptyStringsToNull`** (comentario de Bagisto: «first handle all nullable cases, then remove this line»): los campos vacíos de formularios llegan a los servicios como `''`, no como `null`. Combinado con el modo no estricto, `''` en una columna `date` se guarda como `0000-00-00`, en un número como `0`, y un texto largo se corta sin error.

**Evidencia en datos reales de dev (consultas de solo lectura; 121 tablas, 1.827 columnas `bylopez_*`):**

| Prueba | Resultado |
|---|---|
| Fechas `0000-00-00` | **11 valores en 9 filas** (5 de órdenes de corte y 4 de rollos): `bylopez_cc_ordenes_corte` ids 169, 311, 1624, 2754, 2762 (`fecha_entrega` en las 5 y `fecha_solicitud` en 2754 y 2762; columnas `date NULL`) y 4 en `bylopez_cc_orden_corte_rollos.entregado_en` (ids 176, 318, 1621, 2737). Son residuos de agosto-septiembre (ya hoy `OrdenCorteService` escribe `null`) |
| Texto truncado | **1 fila confirmada**: `bylopez_cg_carga_incidencias.motivo` mide exactamente 500 caracteres y termina a mitad de frase («…Producto creado INACTIVO (acción «»); 1 fila más al límite en `bylopez_cgi_productos_camisetas.cg_mensaje` (500). Los demás campos «al límite» son hashes/UUID/códigos de longitud fija (falsos positivos) |
| Montos saturados | **0** (ninguna columna `decimal` en su máximo) |
| Enum/ints en límite o con valor vacío | **0** |
| Columnas `NOT NULL` sin valor por defecto | **612** de 1.827 (varchar 241, int 159, bigint 95, decimal 39…). Con modo estricto, un `INSERT` que no las informe falla («Field doesn't have a default value»); con modo actual se rellena con `''`/`0` |

**Qué podría fallar al activar el modo estricto (análisis por mecanismo; no ejecutado):**
1. **Inserciones/actualizaciones con `''` en `date`, `int`, `decimal`** desde formularios (por la quita de `ConvertEmptyStringsToNull`). Candidatos: los `FormRequest` con campos `nullable|date`/numéricos que luego se pasan sin normalizar a `create()` (p. ej. `StoreOrdenConfeccionRequest.fecha_entrega`, `DistribuirConfeccionRequest.asignaciones.*.fecha_entrega`; los servicios actuales usan `?? null`, que **no** convierte `''` en `null`). Alto en CorteConfeccion y formularios de configuración.
2. **Código del núcleo Bagisto** (no modificable): fue escrito y probado con `strict=false`; carga de productos, atributos y checkout podrían enviar `''`/valores sin default. Es el riesgo principal y **solo se mide ejecutando sus suites**.
3. **Datos existentes**: las 5+4 filas con `0000-00-00` no se rompen al leerse; **sí** fallarían en un `UPDATE` que reescriba esas columnas, en `ALTER TABLE`, o en un volcado/restauración con `NO_ZERO_DATE` (riesgo directo en el plan de backup/restauración y en migraciones nuevas).
4. **Cargues**: los SP insertan en tablas intermedias con textos de hasta 500 caracteres; con modo estricto un mensaje largo abortaría la fila en vez de truncarse.
5. **Pruebas**: las suites ByLopez se escribieron con modo no estricto; habrá fallos nuevos por datos de fixture incompletos.

**Qué NO está demostrado:** que haya habido pérdida de dinero o de inventario por el modo no estricto. El único daño comprobado es un texto de diagnóstico cortado y 11 fechas inválidas en datos históricos.

**Prioridad revisada: P2 (de P1). No bloquea producción.** Recomendación revisada: *no* activarlo a ciegas. (1) Medir: ejecutar las suites de Bagisto Admin/Shop y ByLopez en la BD de pruebas **con un modo estricto de sesión** definido solo para esa corrida (sin tocar `config/database.php` del proyecto) y listar fallos; (2) corregir en ByLopez los puntos de `''`→`null` y fijar `maxlength`/validación en los campos de 500 caracteres; (3) normalizar las 11 fechas `0000-00-00` a `NULL` con un script **aprobado** (cambia datos históricos: requiere autorización explícita); (4) decidir con la evidencia si se usa `strict=true` o se mantiene `NO_ZERO_DATE`/`ERROR_FOR_DIVISION_BY_ZERO` mínimos. Impacto de la corrección: medio (cualquier fallo nuevo se vería como error 500 en vez de dato alterado). Prueba necesaria: suites completas bajo modo estricto + restauración de un volcado en el modo de producción.

## 4. Finanzas y Rentabilidad

Panel mensual de rentabilidad (`PanelMensualService`): `gastos_generales` y `resultado_operativo` figuran «sin fuente / no disponible» por diseño [C, vista]; no deben presentarse como cifras finales. Fórmulas **no** se cambian.
