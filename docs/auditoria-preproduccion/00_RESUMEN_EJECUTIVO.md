# 00 · Resumen ejecutivo — Auditoría preproducción ByLopez (actualizado en la 2.ª etapa, 2026-11-09)

Fases 1–3 y validación de P0/P1. Sin cambios de código, datos, migraciones, commits ni push. `packages/Webkul/` sin cambios. No se ejecutó ninguna prueba.

## Alcance y límites
Auditoría estructural y de contratos, con lectura dirigida del código crítico, cruces automáticos y consultas **de solo lectura** a la base de dev. En la 2.ª etapa se validaron los hallazgos P0/P1 con evidencia (archivo y método en doc 12). Marcas: CONFIRMADO / POTENCIAL / DESCARTADO. «No apareció» ≠ «no existe»: las pruebas de concurrencia con dos procesos, IDOR con dos usuarios y suites bajo modo estricto **siguen sin ejecutarse**.

## Qué cambió con la validación

| Tema | 1.ª etapa | Resultado validado |
|---|---|---|
| Trabajo sin versionar | P0 | **Confirmado**, y peor de lo dicho: 456 archivos; un contrato cruza Logística↔Ventas; archivos compartidos con hunks de varias sesiones. Hay una estrategia segura (doc 13) |
| `strict=false` | P1 | **Baja a P2.** Daño real pero pequeño (1 texto cortado, 11 fechas `0000-00-00`, 0 montos saturados). Activarlo a ciegas puede romper el núcleo de Bagisto; primero hay que medir |
| Doble deuda 99↔física | P1 sospecha | **Descartado.** `UNIQUE(shipment_id)`, libro por envío, tope por pedido bajo bloqueo. Aparece en cambio algo distinto: 99 e Inter **no tienen cuenta corriente** (AUD-026) |
| Datos personales | P1 | **Confirmado**: 3 Excel + comprobantes reales en carpetas sin ignorar. No hay filtración previa en Git (historial limpio) |
| Rutas de Logística sin permiso | P2 | **No es vulnerabilidad** (falla cerrado + servicios revalidan); P3 funcional |
| Sin `throttle` | P2 | P3: todo exige sesión; el login ya limita |
| Cola | «sync» | **Corregido:** Cargues usa su cola `database` y necesita worker siempre |
| Roles | — | **Nuevo:** hay un rol «BODEGA» con permisos totales y un usuario (AUD-023) |

## Hallazgos (doc 12): 1 P0 · 3 P1 · 11 P2 · 12 P3

**Bloquean producción:** AUD-001 (versionado), AUD-004 (entorno, cron, worker), AUD-005 (motor y procedimientos almacenados). **Bloquea commit/push:** AUD-003 (datos personales).
**No bloquean:** AUD-002 (strict), AUD-006 (descartado), AUD-026/023 y el resto.

## Riesgos por área (resumen)
- **Financiero:** sin ruta de doble conteo encontrada en 99↔física; la ausencia de cuenta corriente para 99/Inter es una decisión pendiente; sin reconciliación periódica (AUD-021). `strict=false` es riesgo latente, no daño demostrado en dinero.
- **Inventario / comisiones:** sin hallazgo confirmado; quedan por probar sobreventa con dos procesos (V-01) y abonos simultáneos (V-07).
- **Seguridad:** autorización de operaciones críticas verificada en servicios; pendientes IDOR y XSS completos.
- **Despliegue:** MariaDB 10.4.28 en dev (se recomienda 10.11 LTS o MySQL 8.0/8.4 tras ensayo), cron y worker, php.ini (2 MB frente a cargues de 20 MB), cuenta MySQL restringida de Cargues, SP sin instalador (doc 11 §4).

## Lo que no conviene tocar
`ConfirmarVentaService`, fórmulas de comisión/rentabilidad, claves de idempotencia, SP v1/v2, `lang`, vistas monolíticas, políticas de precio mínimo (apagadas), `migrate:rollback`.

## Qué necesito de usted (doc 15)
Autorizar el respaldo y el orden de commits (C-01), mover los archivos sensibles, definir motor y servidor de producción, decidir si 99/Inter llevan cuenta corriente, y permitir la Fase 5 en una BD de pruebas exclusiva.

**Punto de control:** detenido. Sin cambios funcionales.
