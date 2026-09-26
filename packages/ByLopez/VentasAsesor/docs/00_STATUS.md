# 00_STATUS.md — Módulo Ventas Asesor

Estado consolidado de las fases del módulo `ByLopez\VentasAsesor`.

**Última actualización:** 2026-09-26 (datos reales de RT-04 preparados en `lpz_saas`)

**Resumen rápido:** 02B1B = CERRADO · RT-03 = CERRADO · **RT-04A = CERRADO** · **RT-04B0 = CERRADO** ·
**RT-04B1a = CERRADO** · **RT-04B1b = CERRADO** ·
Fase 03 = NO INICIADA. **RT-04 cerrado y con datos reales preparados** (migración 000005 + índice
telefónico en `lpz_saas`, 2026-09-26, Doc. 13 §37.11): listo para que la Fase 03 pueda empezar
cuando se autorice.

> **El paquete está registrado y carga.** PSR-4 en el `composer.json` raíz, provider en
> `bootstrap/providers.php` y dos testsuites en `phpunit.xml`. Expone una sola pantalla,
> `GET admin/ventas-asesor`, protegida por ACL propia.
>
> **Desde la Fase 02A tiene tablas propias y dominio.** Cuatro migraciones
> (`bylopez_va_order_attributions`, `bylopez_va_advisor_assignment_logs` y, desde la
> Fase 02B1A, `bylopez_va_attribution_incidents`), modelos, repositorios y servicios de
> atribución comercial. **Aplicadas en `lpz_saas_testing` y también en `lpz_saas` (desarrollo, batch 21, con 0 filas; verificado el 2026-09-25).**
> *(RT-04B1a, 2026-09-26: quinta migración, `bylopez_va_customer_phones`, aplicada **solo** en
> `lpz_saas_testing`; en `lpz_saas` no existe.)*
>
> **Desde la Fase 02B1A el motor está conectado a la creación real de pedidos.** Un
> listener síncrono sobre `checkout.order.save.after` atribuye cada pedido nuevo,
> clasificando el origen por lista exacta de rutas y registrando incidencias durables
> cuando algo no cuadra. **Sigue sin haber comando de reconciliación, interfaz, rutas
> propias ni backfill.** *(Actualizado en la 02B1B: ya existe el comando `ventas-asesor:reconciliar-atribuciones`, con dry-run por defecto; sigue sin interfaz ni backfill masivo.)*
>
> **Las pruebas solo pueden ejecutarse sobre `lpz_saas_testing`.** `Tests\TestCase` bloquea
> cualquier otra base antes de abrir transacciones. Detalle en
> `06A_CIERRE_FASE_01_Y_ENTORNO_PRUEBAS.md`.

---

## Leyenda de estados

| Estado | Significado |
|---|---|
| COMPLETADA | Ejecutada y verificada |
| EN CORRECCIÓN | Implementada, pero con un cierre técnico pendiente |
| PARCIAL / BLOQUEADA | Avanzada, pero con una condición externa que impide cerrarla |
| APROBADA | Autorizada por el negocio, aún no ejecutada |
| PROPUESTA | Definida técnicamente, pendiente de aprobación |
| BLOQUEADA | Depende de una decisión de negocio pendiente |

---

## Tabla de fases

| Fase | Nombre | Estado | Alcance | Dependencias | Decisiones que la bloquean | Evidencia | Actualización |
|---|---|---|---|---|---|---|---|
| 00 | AUDITORIA_CORE_VENTAS | **COMPLETADA** | Auditoría del dominio de ventas de Bagisto. Sin código. | — | — | Doc. 01 §1–15 | 2026-09-08 |
| 00B | CORRECCION_Y_CIERRE_MODELO | **COMPLETADA** | Corrección arquitectónica y diseño técnico de creación de pedidos. Sin código. | 00 | — | Doc. 01 §16–20, Doc. 02–05 | 2026-09-09 |
| 00C | AUDITORIA_RMA_Y_MAPA_CORE | **COMPLETADA** | Auditoría profunda de RMA y mapa del core. Sin código. | 00B | — | Doc. 05A Parte I | 2026-09-12 |
| 00C1 | CIERRE_COBERTURA_RMA | **COMPLETADA** | Cobertura 64/64 de RMA, origen de catálogos, reclasificación D-23/D-24. Sin código. | 00C | — | Doc. 05A Parte II | 2026-09-12 |
| 01 | BASE_PAQUETE_ACL_Y_PREVENCION_ACCESO | **COMPLETADA** | Paquete registrado, ACL de 23 claves, menú de un nodo, pantalla inicial, servicio central de autorización. Cerrada tras la Fase 01A. | 00B | — | Doc. 06 y 06A | 2026-09-13 |
| 01A | CIERRE_TECNICO_Y_ENTORNO_PRUEBAS | **COMPLETADA** | Registro auditado; autorización cerrada (solo `all` y `custom`); base `lpz_saas_testing` migrada y sembrada sin RMA (autorización expresa, opción A); guard global. **72/72 casos propios en verde** en el entorno aislado; regresión 169/174, con los 5 fallos clasificados y ajenos al módulo; `lpz_saas` idéntica. | 01 | — | Doc. 06A | 2026-09-13 |
| 01B | AUDITORIA_CLIENTES_PRECIOS_Y_PROPIEDAD_COMERCIAL | **COMPLETADA** | Auditoría y documentación, sin código. Correo obligatorio explicado y alternativas comparadas; teléfono y duplicados; **precio heredado reproducido con causa identificada** (grupo tomado de la sesión, no del carrito); D-16 y D-27 cerradas; propiedad comercial y POS documentados. `lpz_saas` sin cambios. | 01 | — | Doc. 06B | 2026-09-13 |
| 02A | MODELO_Y_MOTOR_INTERNO_ATRIBUCION | **COMPLETADA, CORREGIDA Y AUDITADA** | Tablas propias `bylopez_va_order_attributions` y `bylopez_va_advisor_assignment_logs`; enums, objetos de valor, modelos, repositorio y servicios de dominio; reglas A–E probadas. **Sin listener, rutas, interfaz ni backfill.** Corregida por la Fase 02A1. | 01B | — | Doc. 07 y 07A | 2026-09-15 |
| 02A1 | CORRECCION_INTEGRIDAD_IDEMPOTENCIA_HISTORIAL | **COMPLETADA** | Cascadas a RESTRICT; ids de administrador sin FK con snapshot; idempotencia que compara contexto y lanza conflicto; historial inicial obligatorio e inmutable; reasignación con `lockForUpdate()`; retiro de lógica de comisiones; camino válido para `fallo_tecnico`. 145 casos propios en verde (643 aserciones) y 6 mutaciones demostradas. | 02A | — | Doc. 07A | 2026-09-15 |
| 02A2 | CIERRE_AUDITADO_ANTES_DE_INTEGRAR | **COMPLETADA** | Carrera concurrente probada de verdad; `fallo_tecnico` sin notas libres y con su código dentro de la idempotencia; `down()` que trata datos reales; historial que también rechaza `save()`; reglas de conflicto corregidas para la 02B. 153 casos propios en verde (678 aserciones). | 02A1 | — | Doc. 07A §19 | 2026-09-19 |
| 02B0 | AUDITORIA_CONEXION_PEDIDOS | **COMPLETADA Y CORREGIDA** | Auditoría del enganche real, sin código. Evento elegido con evidencia (`checkout.order.save.after`, `OrderRepository.php:86`); frontera transaccional **medida** (nivel 1 dentro del listener); comportamiento ante fallo medido (3 reintentos, venta perdida); 9 flujos mapeados; contexto por petición recomendado y **tabla durable ligada al carrito descartada**. Corregida tras revisión externa: política de rutas cerrada, contexto que el listener no consume, **tabla de incidencias aprobada** y límites reales de `catch (Throwable)`. | 02A2 | — | Doc. 08 | 2026-09-20 |
| 02B1A | LISTENER_CONTEXTO_E_INCIDENCIAS | **COMPLETADA** | Conexión runtime entre pedidos reales y el motor de atribución. Contexto `scoped` por `cart_id` con `durante()`; clasificación **cerrada** por las 9 rutas exactas; listener síncrono registrado una sola vez; cuarta migración `bylopez_va_attribution_incidents` con servicio idempotente; taxonomía de errores por categoría. **198 casos propios en verde (973 aserciones)** y 5 mutaciones demostradas. **Sin comando, interfaz ni backfill.** | 02B0 | — | Doc. 09 | 2026-09-20 |
| 02B1B | RECONCILIACION_SEGURA_DE_ATRIBUCIONES | **COMPLETADA** | Comando `ventas-asesor:reconciliar-atribuciones` (dry-run por defecto, `--persistir`, `--order`, `--limite`). Solo lee evidencia persistida; **no infiere origen, asesor ni creador**. Sin evidencia registra `sin_asesor` con origen desconocido; nunca sobrescribe ni fuerza. Dry-run sobre `lpz_saas`: 24 pedidos, 24 sin evidencia, 0 escrituras. **Persistencia no ejecutada en desarrollo.** 213 casos propios en verde (1052 aserciones). | 02B1A | — | Doc. 10 | 2026-09-25 |
| RT-03 | PRECIO_POR_GRUPO_DEL_CLIENTE_DEL_CARRITO | **CERRADO** | RT-03A reproduce el defecto; RT-03B: `ContextoPrecioComercial` (scoped, explícito), `CustomerRepositoryConContextoComercial` por binding y adaptador temporal `RouteMatched` con 11 rutas exactas del panel (incluido `reorder` con contexto de creación). Fail closed ante contexto inconsistente. | 02B1B | — | Doc. 11 | 2026-09-25 |
| RT-04A | AUDITORIA_CLIENTE_COMERCIAL_SIN_EMAIL | **CERRADO** (auditado) | Solo análisis y 14 pruebas de caracterización: email NULL admitido en BD pero exigido en admin y en direcciones de carrito; notificaciones con email NULL lanzan TypeError no capturado; teléfono UNIQUE global sin normalización ni búsqueda; grupo opcional al crear (invitado). Sin cambios de comportamiento. | RT-03 | — (decisiones tomadas: D-RT04-01…10, Doc. 12 §14) | Doc. 12 | 2026-09-25 |
| RT-04B0 | DISENO_TECNICO_CLIENTE_COMERCIAL | **CERRADO** (diseño; parcialmente superado por RT-04B1a, Doc. 13 §36.20) | Diseño sin código: teléfono normalizado en tabla propia `bylopez_va_customer_phones` (INDEX, no UNIQUE global); `customers.phone UNIQUE` intacto (política U1/U2); guard de notificaciones por **binding de subclases de listeners del Shop**; alta comercial con `customer.create.*` y sin `customer.registration.*`; grupo validado en alta y resolución; servicios de búsqueda/alta/resolución y contrato para Fase 03. | RT-04A | P-01…P-15 (Doc. 13 §33) | Doc. 13 | 2026-09-25 |
| RT-04B1a | INFRAESTRUCTURA_CLIENTE_COMERCIAL | **CERRADO** (aprobado 2026-09-26) | Auditoría real del teléfono (matriz de consumidores). **D-RT04-PHONE-01**: un número normalizado → un Customer; principal en `customers.phone` y secundarios en `bylopez_va_customer_phones` (UNIQUE del normalizado, principal 1/NULL). Normalizador E.164 sin dependencias. Indexador histórico con dry-run. `ValidadorGrupoComercial`. Guard de correos al cliente por binding de 5 listeners del Shop (extension point probado antes). Sin listeners de Customer, sin procedencia ni ACL (aplazadas). Migración aplicada **solo** en testing. **Revisión final** (Doc. 13 §36.23): restricción 1/NULL verificada con SQL directo; `ESTRUCTURA_RECONOCIDA` sustituye a `VALIDO` (normalizar ≠ validar); reembolso verificado con `RefundRepository` real; 26 fallos de base del core clasificados por causa (iguales con y sin B1a); `email_sent` no prueba entrega. **381 casos propios en verde (1595 aserciones).** | RT-04B0 | revisión del usuario | Doc. 13 §36 | 2026-09-26 |
| RT-04B1b | CLIENTE_COMERCIAL_OPERATIVO | **CERRADO** (aprobado 2026-09-26) | Servicios `BuscarClienteComercialService` (teléfono → email del canal → candidatos por nombre; conflicto teléfono A/email B; transición de fichas históricas sin indexar), `CrearClienteComercialService` (Customer real sin cuenta, email NULL, grupo validado, sin duplicados, transacción con el principal, `customer.create.*` sin `registration.*`), `TelefonosClienteService` (principal ↔ `customers.phone` transaccional, secundarios, promoción; el principal no se elimina), `ResolverClienteComercialService` (solo lectura) y `GuardarDireccionClienteComercialService` (contrato del core, email NULL, teléfono de contacto explícito). ACL y procedencia aplazadas con evidencia. **Revisión final** (Doc. 13 §37.10): resolución A–F probada y de solo lectura (email de A con teléfono nuevo = EXISTENTE A, sin agregar el teléfono); P-03, P-06, P-08 y P-10 cerradas para RT-04; P-09 aplazada (status e is_suspended del core solo actúan en la tienda). 427 casos propios en verde (1758 aserciones); CorteConfeccion 384/2100; core 225/26 igual al baseline (no repetido en la revisión final: ningún cambio afecta al core). | RT-04B1a | revisión del usuario | Doc. 13 §37 | 2026-09-26 |
| 03 | MOTOR_CREACION_PEDIDO_ASESOR | BLOQUEADA — **NO INICIADA** | Servicio común sobre el flujo oficial de Bagisto; nombre recomendado `CrearPedidoComercialService`. Consumirá el contrato de RT-04 (Doc. 13 §25). | 02B | D-17, D-18, D-19, D-28 + req. téc. RT-03 (cerrado), RT-04 (en diseño) | — | 2026-09-25 |
| 04 | INTERFAZ_CREACION_PEDIDO_ASESOR | BLOQUEADA | Pantalla propia de venta adaptada a WhatsApp. | 03 | D-17, D-18, D-20, D-21, D-28, D-29, D-30 + req. téc. RT-03 | — | 2026-09-13 |
| 05 | LISTADO_DETALLE_ASIGNACION_Y_BACKFILL | BLOQUEADA | DataGrid propio con aislamiento, detalle, bandeja sin asignar, backfill. | 02B | D-12, D-13 | — | 2026-09-15 |
| 06 | SEGUIMIENTO_LOGISTICO_POR_ENVIO | BLOQUEADA | Estado logístico por envío y evento de entrega por `shipment_item`. | 05 | D-07, D-14 | — | 2026-09-09 |
| 07 | DEVOLUCIONES_RMA_Y_CONCILIACION | BLOQUEADA | Conciliación RMA ↔ reembolso ↔ retorno logístico, sin tablas propias. Empieza por prueba funcional de RMA. | 06 | D-25 + req. téc. RT-01, RT-02 | — | 2026-09-12 |
| 08 | REGLAS_Y_EVENTOS_DE_COMISION | BLOQUEADA | Reglas con vigencia y prioridad; unidades elegibles por entrega. | 06, 07 | D-03, D-21, D-26 + req. téc. RT-01, RT-02 | — | 2026-09-12 |
| 09 | LIQUIDACIONES_Y_AJUSTES | BLOQUEADA | Liquidación con snapshot por línea, aprobación, pago y ajustes negativos. | 08 | D-04, D-15, D-24 + req. téc. RT-01, RT-02 | — | 2026-09-12 |
| 10 | KPIS_Y_DASHBOARD | BLOQUEADA | Dashboard de asesor y supervisor; metas. | 05, 06, 07, 09 | D-10, D-11, D-22 | — | 2026-09-09 |
| 11 | EVIDENCIAS_PRIVADAS | BLOQUEADA | Carga, categorización y descarga autorizada de evidencias. | 05 | D-09 | — | 2026-09-09 |
| 12 | API_N8N_WHATSAPP | BLOQUEADA | API que reutiliza el mismo servicio de creación, con idempotencia. | 03, 04 | D-08, D-16, D-17 | — | 2026-09-09 |
| 13 | IMPORTACION_HISTORICA_EXCEL | BLOQUEADA | Carga masiva idempotente. | 12 | D-12 | — | 2026-09-09 |
| 14 | PRUEBAS_INTEGRALES_Y_DESPLIEGUE | PROPUESTA | Consolidación, regresión, despliegue y reversión. | 01–13 | — | — | 2026-09-09 |
| POS | POS_TIENDA_FISICA (futura, sin número) | PROPUESTA | Interfaz de venta presencial sobre el mismo servicio común; `tienda_fisica` / `pos`. | 03, 04 y cadena logística/comisiones | D-30, D-33, D-34 + req. téc. RT-03 | Doc. 03 | 2026-09-13 |

---

## Estado de las decisiones

**Decididas (6):** D-01, D-02, D-05, D-06 (implementada en la Fase 01), **D-16** y **D-27**
(ambas cerradas en la Fase 01B).

**Pendientes (27):** D-03, D-04, D-07, D-08, D-09, D-10, D-11, D-12, D-13, D-14, D-15, D-17,
D-18, D-19, D-20, D-21, D-22, D-24, D-25, D-26, **D-28, D-29, D-30, D-31, D-32, D-33, D-34**.

- **D-16 (decidida):** carrito oficial o borrador durante la conversación; pedido solo
  cuando el cliente confirma. Contraentrega no es pagado.
- **D-27 (decidida, reformulada):** asesor normal se asigna a sí mismo; un administrador
  `all` nunca recibe la venta automáticamente y elige venta de empresa, asignarse o asignar
  otro asesor. Se separan `created_by_admin_id`, `advisor_id` y `commercial_owner_type`
  (sin implementar).

**Retirada (1):** D-23 (pasó a requisito técnico RT-01).

**Requisitos técnicos obligatorios:** RT-01 y RT-02 (bloquean 07, 08 y 09); **RT-03** precio
por grupo del cliente del carrito y **RT-04** clientes sin correo compatibles (bloquean 03 y 04).

**Autorización operativa registrada (no es una decisión de negocio):** sembrar los datos base
de Bagisto **solo** en `lpz_saas_testing` y **sin** seeders de RMA (opción A, Fase 01A).

Detalle en `04_DECISIONES_PENDIENTES.md`.

---

## Bloqueos activos

- **Fase 02B1A:** cerrada. El listener ya atribuye los pedidos nuevos (Doc. 09).
- **Fase 02B1B:** cerrada (Doc. 10). Queda pendiente, por decisión humana, **ejecutar el modo persistente sobre `lpz_saas`** (24 pedidos históricos sin atribución).
- **Fase 02B (completa):** completada técnicamente (02B0, 02B1A, 02B1B).
- **Fase 02 (completa):** completada técnicamente; falta solo la decisión de persistir la reconciliación en desarrollo.
- **Fases 03 y 04:** RT-03 (precio heredado, defecto del core reproducido) y D-28 (clientes sin
  correo, con RT-04), además de sus decisiones previas. *(Actualizado 2026-09-25: RT-03 está
  cerrado; D-28 queda decidida en su núcleo por D-RT04-01/02; RT-04 sigue abierto: diseño
  pendiente de revisión y B1 sin iniciar.)* *(Actualizado 2026-09-26: RT-04B1a y RT-04B1b
  cerrados; la Fase 03 sigue sin iniciar y queda pendiente aplicar la migración 000005 y el
  indexador en `lpz_saas`, con autorización.)*

## Decisiones de RT-04 (cliente comercial) — hacia dónde va la arquitectura

Decididas el 2026-09-25 (Doc. 12 §14); diseño técnico en el Doc. 13; **nada implementado aún**.

- **Identidad:** el `Customer` de Bagisto es el cliente comercial; `customers.id` es la identidad; sin
  `ContactoComercial` paralelo (D-RT04-01).
- **Email:** opcional en el flujo comercial y siempre `NULL`, nunca `""` ni sintético (D-RT04-02);
  la tienda sigue exigiéndolo (D-RT04-03); sin email no se intenta correo al cliente, mediante
  un binding de subclases de los listeners del Shop (D-RT04-06).
- **Grupo:** obligatorio, explícito y válido; el invitado no es fallback; sin grupo por defecto
  decidido (D-RT04-04). RT-03 no cambia.
- **Teléfono:** llave principal de **búsqueda**, no de identidad (D-RT04-07); original +
  normalizado E.164 (D-RT04-08). *Actualizado 2026-09-26:* **D-RT04-PHONE-01** sustituye a
  D-RT04-09. Un número normalizado pertenece a **un solo** Customer (UNIQUE). El **principal**
  vive en `customers.phone`, que el core usa en la ficha, el perfil, la exportación y el
  importador. `bylopez_va_customer_phones` guarda su reflejo normalizado y los secundarios. Sin
  fusión automática.
- **Notificaciones (implementado en B1a):** sin destinatario válido no se intenta el correo al
  cliente; queda registrado y se emite `NotificacionClienteOmitida`. Con email válido el
  comportamiento es exactamente el del core.
- **Dirección comercial:** admite email NULL sin tocar `CartAddressRequest` (D-RT04-05).
- **Webkul:** 0 cambios (D-RT04-10).

No hay bloqueos de entorno.

---

## Mapa de documentos

Orden de lectura recomendado para un tema:

- **Cliente comercial:** 06B → 12 → 13.
- **Precios:** 06B §4 → 11.
- **Atribución:** 07 → 07A → 08 → 09 → 10.

Los documentos históricos conservan su evidencia original. Cuando una decisión posterior los
supera, llevan una nota «Superado por…» en el punto afectado.

| Documento | Contenido |
|---|---|
| `00_STATUS.md` | Este archivo |
| `01_AUDITORIA_CORE_VENTAS_BAGISTO.md` | Auditoría del core (20 secciones) |
| `02_MODELO_FUNCIONAL_VENTAS_ASESOR.md` | Modelo funcional |
| `03_ROADMAP_IMPLEMENTACION.md` | Fases 01–14 |
| `04_DECISIONES_PENDIENTES.md` | D-01 a D-34, RT-01 a RT-04 |
| `05_DISENO_TECNICO_CREACION_PEDIDOS_ASESOR.md` | Diseño del motor y la interfaz de creación de pedidos |
| `05A_AUDITORIA_RMA_Y_MAPA_CORE_RELACIONADO.md` | Auditoría de RMA y mapa del core |
| `06_FASE_01_BASE_PAQUETE_ACL.md` | Fase 01, con las correcciones de la Fase 01A anotadas |
| `06A_CIERRE_FASE_01_Y_ENTORNO_PRUEBAS.md` | Fase 01A: autorización cerrada, registro, suites, entorno aislado, guard, siembra autorizada, resultados y criterios de salida |
| `ACTUALIZACION_MODELO_COMERCIAL_POS_Y_COMISIONES.md` | Requerimientos comerciales posteriores a la Fase 01 (entrada de la 01B), con notas de auditoría |
| `06B_AUDITORIA_CLIENTES_PRECIOS_Y_PROPIEDAD_COMERCIAL.md` | Fase 01B: correo obligatorio, teléfono y duplicados, precio heredado reproducido, D-16, D-27, propiedad comercial, comisión y POS |
| `07_FASE_02A_MODELO_Y_MOTOR_ATRIBUCION.md` | Fase 02A: modelo de datos de atribución, reglas A–E, servicios de dominio, pruebas y regresión, con la lista de lo corregido después |
| `07A_CORRECCIONES_INTEGRIDAD_FASE_02A.md` | Fases 02A1 y 02A2: integridad referencial, idempotencia con conflicto, historial inicial e inmutable, concurrencia, retiro de comisiones y `fallo_tecnico` |
| `08_FASE_02B0_AUDITORIA_CONEXION_PEDIDOS.md` | Fase 02B0: evento, frontera transaccional medida, flujos reales, transporte de contexto, fallos y conflictos, reconciliación y límite de la bandeja |
| `09_FASE_02B1A_LISTENER_CONTEXTO_INCIDENCIAS.md` | Fase 02B1A: contexto `scoped`, clasificación cerrada por rutas, listener síncrono, tabla y servicio de incidencias, taxonomía de errores, 35 pruebas, 5 mutaciones y riesgos para la 02B1B |
| `10_FASE_02B1B_RECONCILIACION_ATRIBUCIONES.md` | Fase 02B1B: evidencia histórica disponible, algoritmo, garantías contra inferencias, dry-run, pruebas y resultado sobre los 24 pedidos; semántica A/B/C y regla de UI futura (§9–§11) |
| `11_RT03_PRECIO_POR_GRUPO_CLIENTE_CARRITO.md` | RT-03 (cerrado): defecto, causa raíz, estrategia C, adaptador del panel, fail closed, T4 como requisito futuro |
| `12_RT04_CLIENTE_COMERCIAL_SIN_EMAIL.md` | RT-04A (auditado): email, teléfono, grupo, notificaciones, snapshots, alternativas y UX actual; **§14 decisiones D-RT04-01…10** |
| `13_RT04B_DISENO_CLIENTE_COMERCIAL.md` | **§36: RT-04B1a implementado** (matriz real del teléfono, D-RT04-PHONE-01, tabla, normalizador, indexador, grupo, guard de notificaciones, desviaciones y deuda). §1–35: RT-04B0 (diseñado): identidad, email NULL, teléfono normalizado y UNIQUE, búsqueda, duplicados, grupo, direcciones, notificaciones (extension point), eventos, servicios, clases, migraciones, contratos Fase 03/POS/API/WhatsApp/IA, riesgos, decisiones P-01…P-15, plan B1 y pruebas |

---

## Precondiciones operativas registradas (fuera del alcance de estas fases)

1. El administrador id 52 sigue con `role_id = 3`, que no existe: 401 en rutas con sesión
   (verificado) y 500 al iniciar sesión (inferido). Acción manual: asignarle un rol válido o
   desactivarlo.
2. El rol `ASESOR VENTAS` conserva 18 permisos amplios del core que el módulo no necesita.
3. `FILESYSTEM_DISK=public` en desarrollo: exposición **latente** de evidencias de RMA si se
   ejecuta `storage:link`.
4. S-16 — XSS almacenado en el panel de RMA, corregido en upstream por `286204d0cf` y presente en
   este árbol.
5. Catálogos de RMA vacíos en desarrollo por actualización incompleta.
6. Este árbol está 94 commits por detrás de `upstream/2.4`.
7. **(Resuelto en la Fase 01A)** Existe `lpz_saas_testing` y el guard impide ejecutar pruebas
   sobre otra base. Los consumos de `AUTO_INCREMENT` ya producidos en desarrollo **no se
   revierten**; entre el cierre de la Fase 01 y el inicio de la 01A hubo ejecuciones de pruebas
   sobre desarrollo **ajenas a estas fases** (`admins` 11194 → 12114).
8. **(Resuelto en la Fase 01A)** Los 4 fallos de `Admin/Settings/UsersTest` tienen causa
   demostrada: tres por el locale `es` y uno por el nombre del rol sembrado en español. Con ellos,
   la regresión aislada da 5 fallos ajenos al módulo (`06A_…md` §11.1).
9. **(Fase 01A)** Cambios ajenos posteriores a la Fase 01, no atribuidos a este módulo: archivos
   de CorteConfeccion (incluida la migración
   `2026_09_13_000001_add_retorno_fields_to_bylopez_cc_ordenes_confeccion`) y el archivo
   `packages/ByLopez/VentasAsesor.rar`.
10. **(Fase 01A)** `lpz_saas_testing` contiene datos base persistentes y el administrador de
    ejemplo de Bagisto. Es solo local. Si cambia el esquema, migrar y, si hace falta, volver a
    sembrar con la misma exclusión de RMA.
11. **(Fase 01B)** **Precios del panel de Bagisto hoy:** la creación administrativa de pedidos
    usa el grupo del cliente autenticado en la sesión (o `guest`), no el del cliente del
    pedido. En desarrollo, 69 de 95 ítems de carritos con cliente tienen precio de invitado.
    Mitigación operativa temporal, no solución: no usar «Iniciar sesión como cliente» mientras
    se crean pedidos y revisar el precio antes de confirmar.
12. **(Fase 01B)** Cambios ajenos detectados antes de la fase: migraciones de CorteConfeccion
    `2026_09_13_000002` y `000003` **aplicadas en desarrollo** (tablas 163 → 165) y `tests/Pest.php`
    con el enlace de `CorteConfeccionTestCase`.
13. **(Fase 01B)** En testing, el alta de cliente del panel termina en 500 por un `TypeError` al
    enviar correo (compatible con remitente no configurado en `.env.testing`). No afecta a las
    conclusiones; conviene configurar un remitente de prueba antes de fases con correos.
14. **(Actualizado en la Fase 02A1)** Los 33 fallos de CorteConfeccion por `TallerService`
    **ya no aparecen**: su responsable avanzó ese trabajo. Hoy quedan **3 fallos suyos**: dos por
    su nodo de menú `corte_confeccion.inventario_movimientos`, que no está en su propio `acl.php`
    y cambia el conteo de hijos esperado, y uno por el estado de retorno en RET-01
    (`07A_…md` §14). Este módulo tiene prohibido modificar ese paquete.
15. **(Fase 02A; corregido el 2026-09-25)** Las tablas del módulo se crearon primero solo en
    `lpz_saas_testing`. **Hoy también existen en `lpz_saas`** (las 4 migraciones, batch 21, con
    0 filas), y el listener de la 02B1A ya está activo allí.
16. **(Fase 02A1)** Al aplicar la migración correctiva en testing, `migrate` aplicó también una
    migración **ajena que estaba pendiente**, `2026_09_15_000001_create_bylopez_cc_inventario_movimientos_table`
    de CorteConfeccion. No se modificó ese paquete ni se tocó desarrollo, pero su tabla quedó
    creada en `lpz_saas_testing` (`07A_…md` §11).
17. **(Fase 02A1)** Borrar un pedido con atribución ahora **falla** por clave foránea RESTRICT. Es
    deliberado: protege la trazabilidad comercial. Si algún flujo del core necesitara borrar
    pedidos, habrá que decidir una excepción explícita.
18. **(Fase 02A2)** La inmutabilidad del historial es de **modelo y servicio**, no de base de
    datos: una consulta masiva o SQL directo saltaría los eventos de Eloquent. El módulo no debe
    usarlas sobre el historial (`07A_…md` §19.6).
19. **(Fase 02A2)** Entre el cierre de la 02A1 y esta fase, `lpz_saas` cambió por trabajo ajeno:
    165 → 166 tablas (nueva tabla de inventario de CorteConfeccion), datos suyos y un
    administrador y un rol nuevos. Esta fase no escribió en desarrollo y el módulo sigue sin
    ninguna tabla allí.
20. **(Fase 02B0)** **Un `Throwable` de un listener de `checkout.order.save.after` revierte y
    reintenta la creación del pedido hasta 3 veces y, si persiste, la venta se pierde** (medido,
    `08_…md` §3). Cualquier listener futuro del módulo debe capturar `Throwable` y no relanzar.
21. **(Fase 02B0)** El guard `admin` **no** distingue panel de tienda: comparten sesión. El
    discriminador de origen es el nombre de la ruta (`08_…md` §5.2).
22. **(Fase 02B0)** `laravel/octane` está instalado: cualquier contexto por petición debe
    registrarse como `scoped`, nunca `singleton`.
23. **(Fase 02B0, corrección)** El origen de un pedido se clasifica con **lista exacta de
    rutas**; una ruta desconocida o ausente **no** es tienda: es origen no determinable
    (`08_…md` §16.1 y §16.2).
24. **(Fase 02B0, corrección)** Un conflicto detectado por el listener no es huérfano ni
    `fallo_tecnico`: por eso se aprobó la tabla de incidencias
    `bylopez_va_attribution_incidents` para la 02B1 (`08_…md` §16.4).
25. **(Fase 02B0, corrección)** `catch (Throwable)` **no** garantiza conservar la venta si
    falla la conexión o la transacción compartida (`08_…md` §16.5).
26. **(Fase 02B1A)** La cola `bylopez_va_attribution_incidents` es **operativa y mutable**; el
    historial de propiedad comercial sigue siendo inmutable. No deben confundirse
    (`09_…md` §6).
27. **(Fase 02B1A)** Un pedido de tienda **sin `cart_id`** queda en `fallo_tecnico`
    (`listener.cart_id_ausente`); uno administrativo sin `cart_id` **sí** se atribuye, porque la
    evidencia es el administrador autenticado (`09_…md` §4).
28. **(Fase 02B1A)** Entre el cierre de la 02B0 y esta fase, `lpz_saas` cambió por trabajo ajeno:
    migración `2026_09_20_000001_add_idempotency_key_to_bylopez_cc_inventario_movimientos`
    (batch 20, CorteConfeccion) y checksum de `roles`. Esta fase no escribió en desarrollo.
29. **(RT-04B1a)** La migración `2026_09_26_000005_create_bylopez_va_customer_phones_table` está
    aplicada **solo en `lpz_saas_testing`** (batch 12). En `lpz_saas` no existe, y el indexador
    (`ventas-asesor:indexar-telefonos`) no se ejecutó allí. Aplicarla exige autorización y `--path`.
    *(Actualizado 2026-09-26: con autorización expresa, la 000005 se aplicó en `lpz_saas` (batch 25,
    solo con `--path`, con respaldo previo local fuera del repo) y el índice se persistió: 10
    teléfonos principales, sin anomalías. Doc. 13 §37.11.)*
30. **(RT-04B1a)** `.env.testing` no define `MAIL_FROM_ADDRESS`: los listeners de correo de
    **Admin** reciben un correo de administrador NULL y lanzan `TypeError`. Esto causa, de forma
    demostrada, **14 de los 26** fallos de base del core. Los demás se deben a moneda/fábricas
    (3), locale `es` (4), `PHP_EOL` de Windows (3) y 2 no determinados (Doc. 13 §36.23).
    Cualquier entorno sin remitente ni correo de admin haría fallar la creación de pedidos.
    Desarrollo sí lo tiene configurado.

## Semántica de propiedad comercial (vigente, cierre 02B1B)

| Caso | Propiedad | Estado | Significado |
|---|---|---|---|
| A | `advisor` | `completa` | Asesor conocido |
| B | `company` | `completa` | Venta **conocida** de empresa (p. ej. storefront); nunca `sin_asesor` |
| C | `unassigned` | `sin_asesor` | Venta **no atribuida / no resuelta**: pendiente de asignar, histórico sin evidencia u origen no resuelto. **No es venta de empresa.** |

El histórico sin evidencia es C con `creation_source = desconocido` y `commercial_owner_source = sin_contexto`, sin asesor ni creador. `fallo_tecnico` queda solo para errores del listener. **Regla para UI futura:** no rotular como «Sin asesor» a secas un pedido `unassigned + desconocido + sin_contexto`; distinguir empresa conocida, asesor, sin asignar, histórico/origen desconocido y fallo técnico (Doc. 10 §9–§10).

---

## Regla permanente del módulo

`packages/Webkul/` no se modifica en ninguna fase. La extensión se realiza por eventos,
`belongsTo` hacia proxies de Concord, `mergeConfigFrom` de ACL y menú, y tablas propias con
prefijo `bylopez_va_`. No se sustituyen modelos del core salvo que una fase futura demuestre que
es indispensable. **Ninguna prueba se ejecuta sobre la base de desarrollo.**
