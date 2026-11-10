# 02 · Auditoría Ventas Asesor

Cobertura: **estructural y de contratos** (rutas, ACL, servicios, restricciones de BD, documentación y pruebas existentes) más lectura dirigida de `ConfirmarVentaService`, `PedidosAsesorController`, `ConsultaPedidosAsesorService`, `RegistrarPagoService`. **No** se leyó línea a línea el código (434 archivos PHP) ni se ejecutaron pruebas. Lo no verificado figura como «por validar» (V-xx) con método propuesto.

Leyenda: [C] confirmado en esta auditoría · [D] declarado en documentación/memoria del proyecto, no re-verificado hoy · [S] sospecha por validar.

## 1. Flujo Cliente → Pedido: qué existe

| Etapa | Evidencia | Estado |
|---|---|---|
| Cliente (buscar/crear, direcciones, sin email) | rutas `nueva-venta/clientes*`, servicios `Clientes/*` (9), docs 12/13 | [C] implementado |
| Grupo comercial habitual y **temporal por venta** | rutas `grupo`, `grupo/restaurar`, `grupo/historial`; doc 20 (GRP-01): «nunca modifica al cliente» | [C] rutas; [D] semántica |
| Productos/colores/tallas/matriz | `lineas/{clave}/colores`, `productos/{producto}/matriz`, `matrices`; `CatalogoVentaService`, `LineasBorradorService` | [C] |
| Carrito comercial y precio | `CarritoComercial`, doc 11 (RT03 precio por grupo) | [D] |
| Entrega / domiciliario / pago del borrador | rutas `entrega`, `domiciliario`, `pago`; `EntregaBorradorService`, `PagoBorradorService` | [C] |
| Confirmación → pedido Bagisto | `ConfirmarVentaService` (ver §2) | [C] diseño leído |
| Cotizaciones (foto versionada) | `CotizacionService`, migración `2026_11_04_000001` **sin versionar** | [C] existe; sin commit |
| Rentabilidad/precio mínimo | `GET {borrador}/rentabilidad`, `Rentabilidad/*`; doc 22 | [D] advertencia al asesor sin costos |

## 2. Confirmación: idempotencia y concurrencia

Hallado en el código [C]:
- **Un borrador → un pedido** (D-55): paso A en transacción corta con `FOR UPDATE` del borrador y token de confirmación; paso B **sin transacción exterior** porque `OrderRepository::create()` hace `DB::commit()` en su `finally` (comentado como invariante «protegido por una prueba»); paso C borra el carrito tras el commit.
- Un intento viejo no reactiva ni pisa a otro (UPDATE condicionado por token).
- Unicidad: `bylopez_va_borradores_venta (mecanismo_creacion, external_reference…)` y `bylopez_va_pagos (mecanismo, idempotency_key)`.
- `VentasSimultaneasService` existe para aislar ventas simultáneas de distintos asesores (contexto en `ContextoComercialDePeticion`).
- No reserva ni descuenta stock físico al confirmar (D-53: fuera del alcance de 03F); el stock se afecta en el despacho (Logística, con bloqueos).

Riesgos / sospechas:
- [S] **V-01 Sobreventa**: al no reservar stock en la venta, dos asesores pueden confirmar pedidos que superan el stock; existe un «cerco de inventario» en `save.before` (D-54) que debe validarse con prueba de dos procesos reales (hoy solo hay pruebas secuenciales + `GET_LOCK`, según memoria del proyecto). Prioridad provisional P2; sube a P1 si el cerco no cubre configurables (hay antecedente: «reserva 0 de configurables», memoria 03F).
- [S] **V-02** `ConfirmarVentaService` depende de que nadie envuelva la llamada en `DB::transaction`; hoy lo protege una prueba, no una restricción. Revisar llamadores nuevos (cotizaciones, API, voz) antes de publicar.

## 3. Pagos y cartera del cliente

- Pagos con comprobante: `RegistrarPagoService` (disco `private`, `mimes:jpg,jpeg,png,webp,pdf`, 10 MB), idempotencia `(mecanismo, idempotency_key)` [C].
- Confirmación/rechazo con permiso `cartera.revisar`; abono general multipedido; D10 (crédito, reembolso, reverso, ajustes, flete) con claves ACL separadas y servicios que revalidan alcance «todos» [C]. D10 está **sin versionar** y con ACL sin asignar a roles reales [D].
- [S] **V-03** Verificar que ningún flujo marque pagado un pedido sin `confirmado` (estado de pago derivado). Método: prueba de propiedades sobre `CarteraConsultaService` con pagos parciales/rechazados/reversados.

## 4. Autorización y alcance (IDOR)

- `PedidosAsesorController`: `abort_if(alcance NINGUNO, 403)`; listado acotado en SQL por `visibles()` («ningún filtro del request puede ampliarlo»); detalle `?pedido=ID` exige `puedeVerRegistroDe($admin, advisor_id)` [C].
- Guía PDF del pedido: la autorización la hace Logística por propiedad comercial del pedido [C].
- Nueva venta: acciones comprueban `propietario_admin_id === actor->id` [C parcial: se vio el patrón en `enCurso`; las demás acciones se delegan a servicios que «exigen ser propietario» según su doc].
- [S] **V-04** Probar con dos usuarios reales: asesor A intenta `GET/POST nueva-venta/{borrador de B}/...`, `pedidos?pedido=ID de B`, `cotizaciones/{id}/pdf` de B, `pedidos/{p}/guias/{g}/ver` de B. Esperado 403/404 en todos.

## 5. Precios

- Precio por grupo con recálculo del carrito (doc 11); grupo temporal por venta con historial; precio mínimo/utilidad (doc 22, política **apagada** en dev por decisión); precios históricos del pedido son los de Bagisto (no se reescriben).
- [S] **V-05** Consistencia catálogo ↔ resumen ↔ pedido: la matriz y el resumen usan el mismo cálculo (`CarritoComercial`)? Método: pruebas de contraste con 3 grupos × 3 escalas de cantidad × cambio de grupo a mitad de venta.

## 6. Hallazgos de esta área

| ID | Prioridad | Estado | Resumen |
|---|---|---|---|
| AUD-001 | P0 (despliegue) | [C] | D10, cotizaciones y otros cambios sin versionar |
| AUD-007 | P3 (revisado) | [C] | Sin `throttle` en rutas de venta/pago (todas autenticadas) |
| V-01…V-05 | por definir | [S] | Validaciones propuestas arriba |

## 7. Qué NO tocar

`ConfirmarVentaService` (diseño de dos fases), estados de borrador, claves de idempotencia, `AutorizacionVentasAsesor`.
