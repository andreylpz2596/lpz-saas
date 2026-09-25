# 09 — Fase 02B1A: listener, contexto e incidencias de atribución

**Estado:** COMPLETADA
**Fecha:** 2026-09-20
**Depende de:** Fase 02A2 (motor de atribución auditado) y Fase 02B0 (auditoría del enganche real, corregida).
**Continúa en:** Fase 02B1B — comando de reconciliación (pendiente de aprobación, **no iniciada**).

Esta fase conecta por primera vez los pedidos reales de Bagisto con el motor de
atribución comercial. No agrega interfaz, comandos, rutas ni backfill.

---

## 1. Qué se implementó

| Pieza | Archivo | Responsabilidad |
|---|---|---|
| Contexto acotado a la petición | `src/Support/ContextoComercialDePeticion.php` | Transporta el `ContextoComercial` entre quien crea el pedido y el listener, indexado por `cart_id`. |
| Lista única de rutas | `src/Support/RutasDeCreacionDePedido.php` | Fuente única de las 9 rutas que crean pedidos. La consumen el código y las pruebas. |
| Clasificador de origen | `src/Support/ClasificadorOrigenPedido.php` | Adaptador de infraestructura: única pieza que mira la petición HTTP y el guard administrativo. |
| Listener | `src/Listeners/AtribuirPedidoCreado.php` | Oyente síncrono de `checkout.order.save.after`. |
| Registro del evento | `src/Providers/EventServiceProvider.php` | Patrón real del core (`Webkul\Notification`, `Webkul\Payment`): proveedor de eventos propio. |
| Estado y código de incidencia | `src/Enums/EstadoIncidencia.php`, `src/Enums/CodigoIncidencia.php` | Tipos explícitos; nunca mensajes humanos como código. |
| Modelo de incidencia | `src/Models/IncidenciaAtribucion.php` | Cola operativa, con sus scopes de consulta. |
| Servicio de incidencias | `src/Services/IncidenciasAtribucionService.php` | Único escritor autorizado de la cola. Detectar y resolver; nada más. |
| Cuarta migración | `src/Database/Migrations/2026_09_20_000004_create_bylopez_va_attribution_incidents_table.php` | Tabla `bylopez_va_attribution_incidents`. Aditiva: las tres anteriores no se tocaron. |
| Excepción de uso indebido | `src/Exceptions/ContextoDePeticionInvalidoException.php` | Clave que no corresponde al carrito, o reemplazo incompatible. |

**No se implementó, por alcance explícito:** comando de reconciliación, backfill,
DataGrid o bandeja, rutas o controladores propios, interfaz de asignación,
`CrearPedidoComercialService`, clientes sin correo, corrección de precios,
comisiones, jobs o listeners en cola, integración con n8n, cambios en pedidos
históricos.

---

## 2. Arquitectura final

```text
  Creación del pedido                    Motor de atribución (dominio puro)
  ───────────────────                    ─────────────────────────────────
  OrderRepository::create()
      │  DB::beginTransaction()
      │
      ├─ checkout.order.save.before
      │
      ├─ (crea el pedido)
      │
      └─ checkout.order.save.after ──►  AtribuirPedidoCreado (listener síncrono)
                                            │
                                            ├─1─ ContextoComercialDePeticion  (scoped)
                                            │      contexto explícito del cart_id
                                            │
                                            ├─2─ ClasificadorOrigenPedido     (scoped)
                                            │      infraestructura: request + guard admin
                                            │
                                            ├─3─ ResolverAtribucionService    ← dominio
                                            │
                                            ├─4─ RegistrarAtribucionService   ← dominio
                                            │      (único motor autorizado)
                                            │
                                            └─5─ IncidenciasAtribucionService ← solo en error
      finally { DB::commit(); }
```

La separación importante es la línea entre las dos columnas: `ContextoComercial`,
`ResolverAtribucionService` y `RegistrarAtribucionService` **no usan `request()`,
`auth()` ni sesión**. Reciben datos ya decididos. Por eso las reglas comerciales
se prueban sin simular una petición y no cambian de resultado según quién tenga
sesión abierta.

---

## 3. Lista única de rutas

`RutasDeCreacionDePedido` es la única fuente, verificada en la auditoría 02B0
(doc. 08 §16.2). La comparación es siempre por **nombre exacto**.

| Ruta | Clase | Contexto que produce |
|---|---|---|
| `admin.sales.orders.store` | Administrativa | `admin_bagisto` + canal `desconocido` |
| `shop.checkout.onepage.orders.store` | Tienda | empresa / `ecommerce` / `storefront` |
| `stripe.payment.success` | Pasarela | ídem |
| `payu.success` | Pasarela | ídem |
| `razorpay.payment.success` | Pasarela | ídem |
| `paypal.standard.success` | Pasarela | ídem |
| `paypal.smart-button.capture-order` | Pasarela | ídem |
| `phonepe.callback` | Pasarela | ídem |
| `phonepe.webhook` | Pasarela (servidor a servidor) | ídem |

### Por qué no se compara por prefijo

`starts_with('admin.')` abarcaría cualquier pantalla futura del panel que acabe
creando un pedido por otro camino, y `starts_with('shop.')` incluye rutas que no
crean pedidos. Un `default => storefront` es peor todavía: convierte cualquier
flujo desconocido en una venta de la empresa **por descarte**, sin que nadie se
entere. El nombre exacto, además, sobrevive a cambios de `APP_ADMIN_URL`.

### Precedencia

```text
1. Contexto explícito registrado para ese cart_id
2. Ruta administrativa exacta
3. Ruta exacta de tienda o pasarela
4. No determinable  →  fallo técnico  listener.origen_no_determinado
```

### Reglas administrativas

- Administrador `custom` con el permiso marcador exacto: se asigna la venta a sí
  mismo (`auto_asesor`).
- Administrador `all`: queda `unassigned`. Tener todos los permisos no lo
  convierte en asesor.
- **Ruta administrativa sin administrador autenticado:** estado inválido →
  `fallo_tecnico` con código `listener.admin_sin_autenticar`. **Nunca degrada a
  storefront**, que regalaría la venta a la empresa inventando evidencia.
- El canal queda `desconocido`. Afirmar WhatsApp sin evidencia sería inventar un
  dato que después alguien usaría para decidir comisiones.

### Reglas de tienda

Las ocho rutas producen exactamente `owner=company`, `advisor=null`,
`channel=ecommerce`, `source=storefront`, `status=completa`.

**Un guard administrativo activo no cambia nada.** El panel y la tienda comparten
la sesión de Laravel (doc. 08 §7.3): que exista `login_admin_*` no prueba que el
pedido lo haya creado un administrador.

---

## 4. Pedido sin `cart_id`

Decisión tomada y justificada, como pedía el encargo:

| Rama | Comportamiento | Razón |
|---|---|---|
| Ruta de tienda **sin** `cart_id` | `fallo_tecnico` con código `listener.cart_id_ausente` | El `cart_id` es la única correlación que permite verificar y reconciliar la atribución después (M-4). Los 24 pedidos reales auditados lo tienen (doc. 08 §7.2): su ausencia es una anomalía, no un caso normal. Se prefiere una incidencia visible a una atribución que nadie podría volver a comprobar. |
| Ruta administrativa **sin** `cart_id` | Se atribuye igual, con `cart_id = null` | Aquí la evidencia es el **administrador autenticado en una ruta que solo alcanza el panel**, no la correlación con el carrito. No se acepta ningún creador sin esa evidencia verificable. Se pierde la trazabilidad al carrito, y eso queda como limitación conocida. |

La idempotencia no depende del `cart_id`: se apoya en `UNIQUE(order_id)` de la
atribución. Lo que se pierde sin `cart_id` es la capacidad de **reconstruir** la
atribución más tarde a partir del carrito, que es justo lo que necesitará la Fase
02B1B.

**Nunca** se infiere el contexto por coincidencia temporal ni por el último
carrito de la sesión.

---

## 5. Ciclo de vida del contexto comercial

`ContextoComercialDePeticion` se registra como **`scoped`**, nunca `singleton`,
propiedad estática, sesión ni variable global. El proyecto tiene
`laravel/octane ^2.3` instalado: con `singleton`, la instancia sobreviviría entre
peticiones y el contexto de un cliente podría atribuir el pedido del siguiente.

```php
$contextoDePeticion->durante($cartId, $contexto, function () {
    return $this->orderRepository->create($datos);   // con todos sus reintentos
});
```

`durante()`:

1. registra el contexto bajo la clave `cart_id`;
2. ejecuta toda la operación;
3. lo mantiene disponible durante **todos** los reintentos internos;
4. lo retira en `finally`;
5. lo retira también si la operación lanza;
6. restaura el contexto anterior si había anidamiento válido.

### Por qué la clave es `cart_id` y no `order_id`

Medido en la Fase 02B0 (doc. 08 §13): cuando el listener lanza,
`OrderRepository::createOrderIfNotThenRetry()` hace rollback y reintenta, y **cada
intento crea un pedido con un id distinto** (se observaron los ids 306/307/308).
El carrito, en cambio, es el mismo en los tres intentos. Atar el contexto al
primer `order_id` temporal lo perdería en el segundo intento.

### Quién limpia

El **dueño del contexto** —quien llamó a `durante()`—, en su `finally`. El
listener **solo consulta y nunca consume ni limpia**: si consumiera el contexto en
la primera lectura, el segundo intento del repositorio se quedaría sin él y la
venta pasaría de "del asesor" a "sin asignar" en silencio.

En esta fase **nadie registra todavía un contexto explícito en producción**: el
creador previsto es `CrearPedidoComercialService`, que corresponde a la Fase 03.
El mecanismo queda construido y probado; hoy todos los pedidos reales se clasifican
por ruta.

---

## 6. Tabla de incidencias

`bylopez_va_attribution_incidents`, cuarta migración, aditiva.

```text
id, order_id, order_attribution_id (nullable), codigo, campos_conflicto (JSON nullable),
estado (open|resolved), attempt_count, first_detected_at, last_detected_at,
resolved_at, resolved_by_admin_id, resolved_by_name_snapshot, timestamps
```

| Garantía | Cómo |
|---|---|
| Idempotencia | `UNIQUE (order_id, codigo)` |
| Consulta operativa | Índices en `estado`, `codigo`, `last_detected_at` y `(estado, last_detected_at)` |
| No destruir la incidencia | `order_id` y `order_attribution_id` con FK **RESTRICT** |
| Actor histórico | `resolved_by_admin_id` **sin FK**, con snapshot de nombre |
| Contador válido | `attempt_count >= 1` en el dominio **y** con `CHECK` real en MariaDB 10.4 |
| Reversión acotada | `down()` elimina únicamente esta tabla |

### Qué NO se guarda

Valores recibidos o esperados, contexto serializado, request, payload, datos del
cliente, teléfono, dirección, tokens, mensajes de excepción, trazas y notas
libres. `campos_conflicto` contiene **solo nombres de campo** de una lista
permitida (`ContextoComercial::CAMPOS_COMPARABLES` + `codigo_tecnico`),
normalizados, únicos y ordenados.

Un nombre de campo dice qué revisar. Su valor sería una fuga de datos en una tabla
que nadie vuelve a depurar.

### Cola operativa ≠ historial inmutable

| `bylopez_va_advisor_assignment_logs` | `bylopez_va_attribution_incidents` |
|---|---|
| Historial de **propiedad comercial** | Cola **operativa** de revisión |
| Solo de adición; el modelo rechaza `save()` sobre una fila existente, `update` y `delete` | Mutable a propósito: se reabre, se cuenta y se resuelve |
| Fuente de verdad para auditoría y comisiones futuras | No decide nada comercial |

### Servicio

Dos operaciones con reglas, **no** un repositorio genérico que exponga
create/update/delete sin reglas:

- `registrar()`: primera detección crea con `attempt_count = 1`; repetición
  incrementa **atómicamente** (`attempt_count + 1` evaluado en el motor, no en
  PHP) y actualiza `last_detected_at`; bajo concurrencia, el perdedor del índice
  único se comporta como una repetición más (`lockForUpdate()` para releer, por la
  misma razón que en `RegistrarAtribucionService`); una incidencia **resuelta que
  reaparece vuelve a `open`** y se limpian los datos de resolución.
- `resolver()`: idempotente; registra actor y snapshot; marca fecha. **No cambia
  la atribución.** Implementada **sin exponer por HTTP**: no hay ruta ni
  controlador. Una reasignación comercial sigue pasando por
  `AsignacionAsesorService`, que exige permiso, motivo e historial.

El servicio nunca modifica la atribución, nunca asigna asesor y nunca resuelve
conflictos por su cuenta.

---

## 7. Listener

Registrado con el patrón real del core: `EventServiceProvider` propio, registrado
desde `VentasAsesorServiceProvider::register()`. Laravel descarta el registro
repetido de un mismo proveedor, así que queda **exactamente una vez** (probado).

Flujo:

1. obtener `order_id` y `cart_id` del modelo `Order` recibido;
2. consultar el contexto explícito **sin consumirlo**;
3. si no existe, clasificar por ruta exacta;
4. construir el `ContextoComercial`;
5. ejecutar `RegistrarAtribucionService` (o `registrarFalloTecnico()`, que es el
   camino autorizado cuando el contexto es de fallo técnico);
6. no modificar el pedido ni el carrito;
7. no eliminar el contexto;
8. no relanzar errores propios del módulo.

Prohibido para la atribución: `updateOrCreate`, `firstOrCreate` e `INSERT`
directo. Cualquiera de los tres convierte un conflicto real en una sobrescritura
silenciosa. El único motor autorizado es `RegistrarAtribucionService`.

---

## 8. Taxonomía de errores

Capturas ordenadas de lo específico a lo general.

| Excepción | Qué hace | Qué NO hace |
|---|---|---|
| `AtribucionEnConflictoException` | Conserva la atribución intacta; crea o actualiza incidencia `atribucion_conflicto` con **solo nombres de campo** | No registra `fallo_tecnico` (la atribución es válida, solo discutida); no relanza |
| `HistorialIncoherenteException` | Incidencia `historial_incoherente` | No modifica atribución ni historial; no relanza |
| `AtribucionInvalidaException`, `ContextoDePeticionInvalidoException` | Intenta `fallo_tecnico` (`listener.contexto_invalido`) y crea incidencia `contexto_invalido`, ambos protegidos | No adivina una atribución; no relanza |
| `QueryException`, `PDOException` | **Solo log seguro** | **No intenta `fallo_tecnico` ni incidencia por la misma conexión que acaba de fallar**; no relanza |
| `Throwable` | Intenta `listener_error` mediante operación protegida; si eso también falla, solo log | Nunca entra en bucle de manejo de errores; no relanza |

Campos permitidos en el log: `order_id`, `cart_id`, código estable, **clase** de la
excepción, `attribution_id` cuando exista, `incident_id` cuando exista. Nunca el
mensaje ni la traza: arrastran SQL con valores, correos y teléfonos hacia un
archivo que suele compartirse entero.

### Límite real de `catch (Throwable)`

Atrapar la excepción evita que **este listener** tumbe la venta. **No resucita una
transacción que la base de datos ya marcó como inválida.** Si la conexión está
rota o la transacción exterior fue abortada, lo único que queda es el log; afirmar
que el `catch` "salva el pedido" en ese escenario sería falso (doc. 08 §16.5).

---

## 9. Transacción y reintentos

Comprobado con pruebas, sin tocar `OrderRepository` ni ningún archivo de
`packages/Webkul/`:

- el listener corre **síncrono** dentro de la transacción del pedido;
- la atribución se escribe dentro de esa misma transacción;
- si algo provoca rollback, **desaparecen también atribución e incidencia**;
- en el siguiente intento se reutiliza el contexto del **mismo `cart_id`**;
- el listener no conserva referencias al primer `order_id`;
- al terminar la operación, el dueño del contexto lo limpia;
- un scope nuevo de aplicación no hereda nada del anterior.

Encolar el listener no era una alternativa: el job correría fuera de la
transacción, cuando la petición HTTP —y con ella el contexto— ya no existe.

---

## 10. Pruebas

**Suite del módulo: 198 casos en verde, 973 aserciones** (antes de esta fase: 153 /
678). Solo sobre `lpz_saas_testing`.

| # | Prueba | Archivo |
|---|---|---|
| 1 | Contexto registrado como `scoped` | `Feature/ContextoScopedEnContenedorTest.php` |
| 2 | Consultarlo no lo consume | `Unit/ContextoComercialDePeticionTest.php` |
| 3 | Carritos distintos no se pisan | ídem |
| 4 | `durante()` limpia tras éxito | ídem |
| 5 | `durante()` limpia tras excepción | ídem |
| 6 | Los reintentos leen el mismo contexto | ídem |
| 7 | Un scope nuevo no hereda contexto | `Feature/ContextoScopedEnContenedorTest.php` |
| 8 | Contexto explícito tiene prioridad sobre la ruta | `Feature/ClasificadorOrigenPedidoTest.php` |
| 9 | Ruta administrativa exacta | ídem |
| 10 | Administrador asesor `custom` se autoasigna | ídem |
| 11 | Administrador `all` no se autoasigna | ídem |
| 12 | Ruta administrativa sin administrador | ídem |
| 13 | Ruta exacta de checkout | ídem |
| 14 | Las ocho rutas de tienda y pasarela | ídem |
| 15 | Guard admin activo no cambia la propiedad | ídem |
| 16 | Ruta desconocida produce `fallo_tecnico` | ídem |
| 17 | Ausencia de ruta produce `fallo_tecnico` | ídem |
| 18 | Ausencia de `cart_id` sin inferencias | ídem |
| 19 | Evento repetido no duplica atribución ni historial | `Feature/ListenerAtribucionPedidoTest.php` |
| 20 | Conflicto conserva atribución y crea incidencia | ídem |
| 21 | Conflicto repetido incrementa sin duplicar | `Feature/IncidenciasAtribucionTest.php` |
| 22 | Campos de conflicto normalizados | ídem |
| 23 | Resolver no modifica la atribución | ídem |
| 24 | Incidencia que reaparece vuelve a `open` | ídem |
| 25 | Historial incoherente crea incidencia | `Feature/ListenerAtribucionPedidoTest.php` |
| 26 | Error de dominio no aborta el listener | ídem |
| 27 | Error inesperado no aborta el listener | ídem |
| 28 | Fallo al registrar la incidencia tampoco se propaga | ídem |
| 29 | Error de base no intenta otra escritura por la misma conexión | ídem |
| 30 | Rollback revierte atribución e incidencia | ídem |
| 31 | Tras un reintento, una atribución y un historial inicial | ídem |
| 32 | Dos ejecuciones concurrentes reales | `Feature/CarreraAtribucionListenerTest.php` |
| 33 | Listener registrado exactamente una vez | `Feature/ListenerAtribucionPedidoTest.php` |
| 34 | Ninguna operación modifica tablas del core | ídem |
| 35 | Todas las pruebas usan `lpz_saas_testing` | ídem |

La prueba 32 **no fabrica un ganador sin historial**: la segunda conexión ejecuta
el mismo `RegistrarAtribucionService` y confirma de verdad; la ejecución perdedora
choca contra el índice único y recupera al ganador. Por eso el historial inicial
existe y es exactamente uno.

Dos pruebas de fases anteriores se **ampliaron** (no se relajaron) porque esta
fase agrega estructura de forma aditiva:

- `CierreAuditado02A2Test` §12: ahora verifica también `bvaai_order_fk` y
  `bvaai_attribution_fk` en RESTRICT; el conteo de claves foráneas pasa de 2 a 4.
- `IntegridadAtribucionTest` §22: el conteo de migraciones del módulo pasa de 3 a 4.

---

## 11. Mutaciones

Aplicadas temporalmente y restauradas; restauración verificada por hash MD5 del
archivo (y del DDL, en la cuarta).

| # | Mutación | Pruebas que fallaron | Restauración |
|---|---|---|---|
| 1 | Ruta desconocida cae como storefront | 16, 17 | MD5 idéntico |
| 2 | El contexto se consume en la primera lectura | 2, 3, 6, 8, 31 y «registrar dos veces… anidamiento» | MD5 idéntico |
| 3 | El listener relanza el conflicto | 20, 30 | MD5 idéntico |
| 4 | Se retira la unicidad de incidencias | «la tabla de incidencias existe con su unicidad…» | MD5 del archivo idéntico; DDL idéntico salvo el contador `AUTO_INCREMENT` |
| 5 | `RegistrarAtribucionService` sustituido por `updateOrCreate` | 19, 20, 26, 27, 28, 29, 30, 31 | MD5 idéntico |

**Nota honesta sobre la mutación 4.** El primer intento fue quitar el índice
directamente en la base y **no llegó a aplicarse**: MariaDB lo rechazó con el error
1553 porque la clave foránea `bvaai_order_fk` necesita ese índice como prefijo. Se
repitió mutando la migración y reconstruyendo la tabla, y entonces sí falló la
prueba de esquema. También queda registrado el límite de esta mutación: la
idempotencia **secuencial** sigue pasando sin el índice, porque el servicio lee
antes de insertar; lo que el índice garantiza es el caso **concurrente**, y es
exactamente la razón por la que existe.

Tras restaurar las cinco, la suite volvió a **198 en verde** y no quedó ningún
archivo mutado (búsqueda de `MUTACION` en el módulo: 0 coincidencias).

---

## 12. Estado de las bases de datos

**Antes de migrar** se verificó: `APP_ENV=testing`, `DB_DATABASE=lpz_saas_testing`,
`SELECT DATABASE() = lpz_saas_testing`, host `127.0.0.1`, guard de `Tests\TestCase`
activo, y se tomó instantánea de `lpz_saas` **antes de cualquier prueba**.

- **`lpz_saas_testing`:** migración aplicada. Tabla creada con su unicidad, sus
  cuatro índices, dos claves foráneas RESTRICT y el `CHECK` de `attempt_count`.
- **`lpz_saas` (desarrollo):** **no** contiene `bylopez_va_attribution_incidents`.
  No se ejecutó ninguna escritura del módulo contra ella.

> **Aclarado 2026-09-25:** las cuatro migraciones del módulo están aplicadas también en `lpz_saas` (batch 21, 2026-09-22, compartido con migraciones de CorteConfeccion: un `migrate:rollback` de ese batch quitaría ambas). El registro de esa aplicación no está en ningún documento de fase. Ver `00_STATUS.md` y Doc. 10.

Cambios externos observados en `lpz_saas` entre el cierre de la 02B0 y el inicio de
esta fase, con evidencia temporal y **ajenos a este módulo**: migración
`2026_09_20_000001_add_idempotency_key_to_bylopez_cc_inventario_movimientos`
(batch 20, paquete `CorteConfeccion`) y un cambio de checksum en `roles`. No se
atribuyen a la Fase 02B1A y no se tocaron.

---

## 13. Riesgos y pendientes para la Fase 02B1B

| # | Riesgo | Detalle |
|---|---|---|
| R-1 | Pedidos anteriores a esta fase sin atribución | El listener solo actúa sobre pedidos nuevos. Los históricos siguen sin fila. Corresponde al comando de reconciliación, con `--dry-run`. |
| R-2 | `fallo_tecnico` sin camino de recuperación automático | Un pedido marcado `listener.origen_no_determinado` o `listener.cart_id_ausente` queda esperando decisión humana. Reconciliación y bandeja (Fase 05) son quienes lo resuelven. |
| R-3 | Incidencias sin bandeja | La cola es durable y consultable, pero **no hay interfaz**. Hoy se revisa por consulta directa. |
| R-4 | Contexto explícito sin productor | `durante()` está construido y probado, pero nadie lo usa en producción hasta `CrearPedidoComercialService` (Fase 03). Mientras tanto, todo pedido administrativo se clasifica por ruta. |
| R-5 | Atribución administrativa sin `cart_id` | Se acepta a propósito (§4) y pierde correlación con el carrito; la reconciliación no podrá re-derivarla desde el carrito. |
| R-6 | Concurrencia de incidencias no cubierta por prueba | El índice único la garantiza en la base y el servicio tiene la rama de recuperación, pero no hay una prueba de carrera equivalente a la 32 para la cola de incidencias. |
| R-7 | Reintentos del repositorio y correo del core | Un rollback del pedido revierte las tablas propias, pero **no** deshace efectos externos de otros oyentes (correo ya encolado). Fuera del alcance del módulo. |

---

## 14. Confirmaciones

- `packages/Webkul/` **sin cambios**; `packages/Webkul/RMA/` **sin cambios**;
  `packages/ByLopez/CorteConfeccion/` no tocado por esta fase.
- `config/concord.php`, `CLAUDE.md` y `.env.testing` sin modificar.
- Sin escrituras en `lpz_saas`.
- Sin `commit`, `push`, `merge`, `rebase`, `cherry-pick`, `reset`, `checkout` ni
  `stash`.
- Sin comando, sin interfaz y sin backfill.
