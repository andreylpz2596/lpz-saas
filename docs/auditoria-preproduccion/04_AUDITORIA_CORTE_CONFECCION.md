# 04 · Auditoría Corte y Confección

Cobertura: inventario estructural, restricciones de BD, rutas/ACL, documentación (`docs/confeccion/*`) y memoria del proyecto. **No** se re-ejecutaron pruebas ni se leyó el código de cada servicio. Leyenda: [C] confirmado hoy · [D] declarado · [S] por validar.

## 1. Alcance real del módulo

314 archivos PHP, 72 vistas, 46 migraciones, 108 rutas (todas con clave ACL), 15 controladores. Flujo implementado: compra de tela → rollos → orden de corte → entrega/consumo → resultado real → orden de confección → distribución a talleres → retorno/recepción → movimiento de inventario; además reasignación de prendas, cartera de cortadores/satélites, consolidado con prioridades y stock mínimo, notificaciones y lectura de comprobantes.

## 2. Integridad (lo que protege la base)

- `inventario_movimientos.idempotency_key` UNIQUE (migración 2026_09_20) [C] → un mismo movimiento de stock no se registra dos veces.
- `retornos`: UNIQUE por orden/línea (migración 2026_09_13_000002) [C].
- Cartera de prestadores: `huella_activa` UNIQUE (operación duplicada) [C]; movimientos + aplicaciones, cuenta derivada [D].
- 46 `lockForUpdate` y 36 transacciones en el paquete [C, conteo].
- Doble recepción, doble ingreso de stock, cantidades negativas, sobreproducción y pagos por prendas no recibidas: diseño con restricciones y bloqueos; **no re-probados hoy**. Métodos en §4.

## 3. Hallazgos

| ID | Prioridad | Estado | Resumen |
|---|---|---|---|
| AUD-001 | **P0** | [C] | **89 archivos nuevos y 41 modificados sin versionar**, entre ellos 8 migraciones (reasignaciones, cartera, prioridades, stock mínimo), modelos, controladores y vistas. Producción no podría reconstruirse desde Git |
| AUD-010 | P2 | [C] | `LectorClaudeVision` (API externa) presente; apagado por defecto. Debe quedar documentado y bloqueado en producción |
| AUD-009 | P2 | [C] | Comprobantes: request valida solo `file`; el servicio valida MIME real (`getMimeType`) y 10 MB; disco `local`. PHP dev `upload_max_filesize=2M` contradice el límite de 10 MB |
| AUD-014 | P3 | [C] | `CarteraController:276` sin `mimes`/`max` a nivel de request (se compensa en servicio) |
| AUD-016 | P3 | [C] | Vistas `ordenes-corte/partials/card-solicitud` y `components/record-search` sin referencias en código (solo en docs): categoría B |
| AUD-018 | P3 | [C] | Archivos gigantes: `ordenes-confeccion/show` (1693 l.), `create` (1463), `proveedores-tela/show` (1178), `ResolvedorVarianteCorteService` (1076) |
| V-08 | — | [S] | Catálogo/variantes: 13 colisiones familia+color+talla (memoria) con decisión V2 pendiente; verificar que no existan en datos reales antes de publicar |
| V-09 | — | [S] | N1 (tarifas/pagos a talleres) y H11 abiertos según memoria; confirmar estado funcional |

## 4. Validaciones propuestas (Fase 5)

1. Recepción repetida (mismo `idempotency_key` y distinto) → un solo movimiento, stock correcto.
2. Dos usuarios recibiendo la misma orden a la vez (dos procesos).
3. Σ recibido + defectuoso + no confeccionable ≤ enviado, por variante.
4. Consumo de rollos: descuento único tras reasignación o anulación.
5. Pago/cuenta de prestador solo por prendas recibidas; reversión simétrica.
6. Reconciliar `Σ ingresos de stock por producción` contra `Σ recepciones` (consulta de lectura).

## 5. No tocar

Reasignación de prendas, cuenta derivada de cartera, comprobantes desactivados por defecto, idempotencia de movimientos.
