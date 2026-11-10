# 03 · Auditoría de comisiones y cartera de asesores

Cobertura: inventario de servicios/tablas/rutas/restricciones + trabajo propio reciente (panel por pedido, avance, cuenta del asesor, lectura de comprobantes: docs 28/29 y pruebas Com06/Com07). Leyenda: [C] confirmado hoy · [D] declarado en docs/memoria · [S] sospecha por validar.

## 1. Piezas

Servicios (`Services/Comisiones`, 22): acuerdos, líneas, umbral mensual, elegibilidad, evaluación/reevaluación, liquidaciones/abonos/reversos, ajustes, incidencias, avance, cuenta, simulador, soportes (lectura de comprobantes), costos y utilidad. Tablas con **clave de idempotencia UNIQUE** [C]: `bvaum` (líneas de umbral), `bvacq` (liquidaciones), `bvacj` (ajustes), `bvacb` (abonos), `bvacm` (crédito de cliente), `bvaao` (ajustes de obligación). Bandeja de incidencias con `abierta_clave` UNIQUE; versiones de configuración con `UNIQUE(familia_clave, version…)` [C].

Seguridad de montos: abonos con `GET_LOCK` + `lockForUpdate` y huella de operación; `confirmar_misma_operacion` explícito para repetir referencia [C, `LiquidacionesComisionService`, `GET_LOCK=1` ocurrencia].

## 2. Controles pedidos y cómo se verifican

| Control | Evidencia | Estado |
|---|---|---|
| No causar dos veces una comisión | `UNIQUE idempotency_key` + reevaluación con deduplicación | [C] restricción; [D] pruebas Com* |
| No pagar sin requisitos (entrega validada + recaudo confirmado + umbral) | `ElegibilidadComisionService`; etapas que no se solapan; «desconocido = null, nunca $0» | [D] pruebas Com06/07 pasan (2026-11-02, secuencial) |
| Devolución | C5 corregido en dev: recibida reduce saldo real; reverso de abonos (`abono|reverso`) | [D] |
| Cambio de porcentaje no altera histórico | acuerdos con vigencias; líneas guardan snapshot de base | [S] **V-06**: probar cambio de acuerdo con líneas ya causadas |
| Privacidad | `view_own` / `view_all`; costos solo con `view_all`; comprobantes solo con permiso `pagar` (`ComisionesController::verSoporte` aborta 404 sin permiso) | [C] código; asignación real de `view_own` al rol «ASESOR VENTAS» **pendiente** |
| Venta atribuida a otro asesor | motor de atribución + reconciliador + incidencias (docs 07–10) | [D] |
| Carreras en pagos simultáneos | GET_LOCK + FOR UPDATE + UNIQUE; **solo probado en secuencia**, no con dos procesos reales | [S] **V-07** (P1 si falla) |

## 3. Hallazgos

| ID | Prioridad | Estado | Resumen |
|---|---|---|---|
| AUD-012 | P2 | [C] medición propia | Vista admin de comisiones ≈15 consultas por pedido (319 para 21 pedidos; 6,8 s en frío); sin paginación efectiva a gran escala |
| AUD-004 | P1 | [C] | La reevaluación depende de `schedule:run` (cron) y/o worker; sin ellos la bandeja no avanza |
| AUD-001 | P0 | [C] | Código D10 sin versionar interactúa con comisiones (`Elegibilidad`, `Umbral`, `Evaluar` modificados sin commit) |
| V-06, V-07 | — | [S] | Histórico ante cambio de porcentaje; concurrencia real de abonos |
| AUD-019 | P3 | [C] | Com03·COM03-1 falla igual que el 29-oct (falla preexistente documentada); decidir si se corrige la prueba o la regla |

## 4. Reglas de negocio a proteger (no cambiar)

Umbral mensual por asesor, porcentajes y vigencias aprobados, etapas no solapadas, avisos generales una sola vez, política de precio mínimo y bloqueo/fecha **apagados**, comisiones y C6 activos solo en demo. Pendientes D1–D12 son decisiones de Camilo (doc 15).

## 5. Cartera del cliente (relación con comisiones)

Los cobros de clientes y los abonos de comisión **no se mezclan** (probado en CO-x, doc 29). La comisión se habilita con recaudo confirmado; un pago «reportado» por transportadora no la habilita (doc 15 de Cargues).
