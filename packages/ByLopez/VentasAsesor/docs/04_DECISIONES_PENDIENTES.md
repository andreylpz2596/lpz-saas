# 04_DECISIONES_PENDIENTES.md

Decisiones de negocio del módulo `ByLopez\VentasAsesor`.

**Última actualización:** 2026-09-15 (Fase 02A)

**Estado global:** 6 decididas (D-01, D-02, D-05, D-06, **D-16**, **D-27**) ·
**27 pendientes** · 1 retirada (D-23). La Fase 01 añadió **D-27**. La Fase 01B
**cerró D-16**, **reformuló y cerró D-27**, añadió las decisiones **D-28 a D-34** y
los requisitos técnicos **RT-03** y **RT-04** (evidencia en
`06B_AUDITORIA_CLIENTES_PRECIOS_Y_PROPIEDAD_COMERCIAL.md`, citado como `[B-§n]`).

**La Fase 02A no decidió nada nuevo.** Implementó en el dominio las decisiones ya
tomadas: D-05 (un solo asesor propietario, por `UNIQUE(order_id)`), D-06 (asesor por
permiso ACL, con snapshot de nombre) y D-27 (propiedad comercial y reglas de
asignación). Detalle en `07_FASE_02A_MODELO_Y_MOTOR_ATRIBUCION.md`, citado como
`[C-§n]`.

**Reclasificación de la Fase 00C1.** La Fase 00C registró cuatro decisiones
nuevas; dos estaban mal clasificadas:

- **D-23 se retira**: evitar un reembolso duplicado no es una elección del
  negocio. Pasa a ser el requisito técnico **RT-01** (`[R-§26.1]`).
- **D-24 se reformula**: la parte técnica (distinguir el origen de un reembolso)
  pasa a ser el requisito técnico **RT-02** (`[R-§26.2]`); queda como decisión de
  negocio solo la política de si un reembolso sin devolución descuenta comisión.
- **D-25 y D-26 se conservan** como decisiones de negocio legítimas.

Los requisitos técnicos RT-01 y RT-02 **siguen bloqueando las Fases 07, 08 y
09**, pero no son preguntas para el propietario del negocio.
Las recomendaciones de las decisiones pendientes son **técnicas**, no
aprobaciones. Ninguna fase puede iniciarse asumiendo una recomendación como
decidida.

Referencias `[A-§n]` → `01_AUDITORIA_CORE_VENTAS_BAGISTO.md`;
`[M-§n]` → `02_MODELO_FUNCIONAL_VENTAS_ASESOR.md`;
`[T-§n]` → `05_DISENO_TECNICO_CREACION_PEDIDOS_ASESOR.md`;
`[R-§n]` → `05A_AUDITORIA_RMA_Y_MAPA_CORE_RELACIONADO.md`.

---

## D-01 · Evento que genera comisión — **DECIDIDA**

**Estado:** ✅ **DECIDIDA** (Fase 00B).

**Decisión.** La comisión se causa únicamente por **prendas efectivamente
entregadas al cliente y no devueltas**.

**No** generan comisión:

- pedido creado
- pedido facturado
- pedido despachado
- envío en tránsito
- entrega fallida
- retorno de transportadora
- unidad cancelada
- unidad devuelta
- reposición gratuita
- garantía
- muestra
- producto marcado como no comisionable

**Fecha causante:** la fecha confirmada de entrega de cada envío o unidad
entregada, **no** la fecha de creación del pedido.

**Implicación técnica.** El dato "unidad entregada" **no existe en el core**
[A-§8.2]. Exige el evento de entrega por `shipment_item` de la Fase 06
[M-§8.3]. Si ese estado no se captura con disciplina, la comisión no es
calculable. **Desbloquea la Fase 08.**

---

## D-02 · Devolución después de pagar la comisión — **DECIDIDA**

**Estado:** ✅ **DECIDIDA** (Fase 00B).

**Decisión.** Una liquidación pagada es **inmutable**. Si una prenda se devuelve
después de haberse pagado su comisión, se crea un **ajuste negativo trazable**
en la siguiente liquidación del asesor.

El ajuste debe referenciar: pedido · ítem original · envío o evento de entrega ·
liquidación original · cantidad · valor descontado · motivo · usuario o
integración que registró la devolución · fecha.

**Nunca** se recalcula ni se reescribe una liquidación pagada.

**Implicación técnica.** Exige el snapshot por línea de [M-§8.5] y la
restricción de unicidad de [M-§8.7]. **Desbloquea la Fase 09** en lo que respecta
a este punto.

---

## D-03 · Alcance de las reglas y exclusiones

**Estado:** pendiente. **Bloquea la Fase 08.**

**Impacto.** Define la estructura de `bylopez_va_commission_rules` y qué prendas
entran en el cálculo.

**Preguntas concretas.** ¿La tarifa es igual para todos los asesores? ¿Varía por
producto o categoría? ¿Los productos gratuitos, los cambios por garantía, las
reposiciones y las muestras generan comisión? ¿Los descuentos reducen la base?

**Alternativas.** Regla global única · por asesor · por producto o categoría ·
combinación con prioridad.

**Recomendación técnica.** Soportar el modelo con vigencia y prioridad
[M-§8.4] aunque hoy solo se use una regla global: el costo adicional es bajo y
evita una migración futura. Marcar explícitamente los productos no comisionables.

---

## D-04 · ¿Se puede reasignar el asesor después de facturar o liquidar?

**Estado:** pendiente. **Bloquea la Fase 09.**

**Impacto.** Consistencia entre atribución y comisiones ya pagadas.

**Alternativas.** Reasignación libre siempre con historial · libre hasta
facturar, después solo rol superior · **bloqueada una vez la venta entró en una
liquidación pagada**.

**Recomendación técnica.** La tercera. Reasignar una venta ya liquidada
obligaría a recalcular dinero pagado a dos personas distintas, lo que contradice
D-02.

---

## D-05 · Cantidad de asesores por pedido — **DECIDIDA**

**Estado:** ✅ **DECIDIDA** (Fase 00B).

**Decisión.** Cada pedido tiene **un solo asesor comercial propietario**
(`orders` 1:1 `advisor_id`). **No** se implementa reparto porcentual ni
múltiples asesores por pedido.

El creador administrativo **puede ser distinto** del asesor propietario:
`created_by_admin_id != advisor_id` es un caso válido y esperado.

Si en el futuro aparece un caso real y recurrente de colaboración comercial,
podrá añadirse de forma **aditiva**. No se diseña ahora.

> **Precisión (Fase 01), sin cambiar la decisión.** «`orders` 1:1 `advisor_id`» es
> una relación **conceptual**. No se agregará `advisor_id` a la tabla core
> `orders`: la atribución vivirá en una tabla propia relacionada uno a uno con
> `orders` (Fase 02).

**Implicación técnica.** Simplifica atribución, KPIs y comisiones. **Desbloquea
la Fase 02** en lo que respecta a este punto. Elimina el caso límite CL-04.

---

## D-06 · Identificación del asesor — **DECIDIDA**

**Estado:** ✅ **DECIDIDA** (Fase 00B).

**Decisión.** El asesor se identifica mediante un **permiso ACL propio del
módulo**, no por el nombre editable del rol ni por una tabla adicional de
perfiles:

```
ventas_asesor.operar_como_asesor
```

El rol `ASESOR VENTAS` podrá recibir ese permiso desde el panel de Bagisto.

**No** se crea `bylopez_va_advisors`.

La atribución sigue apuntando a `advisor_id → admins.id`, y se guarda
`advisor_name_snapshot` para conservar el nombre histórico si el administrador
cambia de nombre o queda inactivo.

**Implicación técnica.** Desacopla el módulo de nombres de rol editables sin
introducir una tabla de perfiles. **Desbloquea las Fases 01 y 02** en lo que
respecta a este punto.

**Precondición operativa (no se corrige en fase documental):** el administrador
ASESOR_PRUEBA apunta a `role_id = 3`, que no existe; con un rol nulo,
`Bouncer::hasPermission()` y `Admin::hasPermission()` producen un error fatal de
PHP [A-§18.3]. **Precisión (Fase 01):** en rutas administrativas con sesión el
middleware de Bagisto responde **401** antes de llegar al módulo (verificado por
prueba); el **500** corresponde al inicio de sesión (inferido de alta confianza).
La implementación de D-06 en la Fase 01 **no depende** de corregir este dato.

---

## D-07 · ¿Existe un estado logístico previo al despacho?

**Estado:** pendiente. **Bloquea la Fase 06.**

**Impacto.** Define el estado inicial de la máquina de estados [M-§7.3].

**Contexto real.** En Bagisto el envío se crea **cuando ya se despacha**, porque
es entonces cuando descuenta inventario físico [A-§7]. Un estado `preparando`
solo tiene sentido si el negocio registra la preparación **antes** de crear el
envío en Bagisto.

**Recomendación técnica.** Estado inicial `despachado`. Añadir `preparando`
después, solo si bodega realmente lo necesita.

---

## D-08 · Canal y usuario técnico de los pedidos automatizados

**Estado:** pendiente. **Bloquea la Fase 12.**

**Contexto real.** Hoy **no existe** ninguna integración con n8n ni WhatsApp, ni
una API REST de creación de pedidos [A-§3.4]. Un pedido automatizado no tendría
usuario administrador autenticado.

**Preguntas.** ¿La automatización creará pedidos realmente? ¿Con qué usuario
técnico? ¿El asesor se asigna en el momento o después? ¿Qué identificador
externo se usará como clave de idempotencia?

**Recomendación técnica.** `creation_source = n8n`, `sales_channel` según el
origen real de la conversación, `external_reference` con el id de ejecución
(obligatorio, [T-§17]) y `advisor_id = null` hasta la asignación manual.

---

## D-09 · Evidencias: formatos, tamaño y retención

**Estado:** pendiente. **Bloquea la Fase 11.**

**Preguntas.** ¿Qué formatos se aceptan? ¿Tamaño máximo por archivo y por
pedido? ¿Cuánto tiempo se conservan? ¿Quién puede eliminarlas? ¿Hay obligación
legal de conservar comprobantes de entrega?

**Recomendación técnica.** `jpg/jpeg/png/webp/pdf`, máximo 5 MB por archivo,
almacenamiento privado, borrado lógico y retención mínima de 12 meses hasta que
el negocio defina otra cosa. Las capturas de WhatsApp contienen datos personales
de clientes: nunca en disco público.

---

## D-10 · ¿El asesor ve costos y márgenes?

**Estado:** pendiente. **Bloquea la Fase 10.**

**Impacto.** Confidencialidad. Bagisto expone precio y totales; el costo de
producción vive en el módulo de Corte y Confección.

**Alternativas.** El asesor ve solo precio de venta y su comisión · ve también
margen · no ve valores monetarios, solo prendas.

**Recomendación técnica.** Precio de venta y comisión propia; sin costos, sin
márgenes y sin comisiones de terceros.

---

## D-11 · Metas de los asesores

**Estado:** pendiente. **Bloquea la Fase 10.**

**Preguntas.** ¿La meta es en prendas, pedidos o dinero? ¿Es mensual? ¿Por
asesor o global? ¿Afecta la comisión mediante bonos o es solo informativa?

**Recomendación técnica.** Meta mensual por asesor, en prendas entregadas
(coherente con D-01), **informativa** en una primera etapa. Ligarla a bonos
multiplica la complejidad de la Fase 09.

---

## D-12 · Tratamiento del histórico de pedidos existente

**Estado:** pendiente. **Bloquea las Fases 05 y 13.**

**Contexto real.** Existen 24 pedidos previos sin ninguna atribución posible
[A-§10].

**Alternativas.** Dejarlos permanentemente sin asesor · asignarlos manualmente
uno a uno · asignarlos masivamente a un asesor por defecto · **marcar una fecha
de corte** y medir solo desde la puesta en marcha.

**Recomendación técnica.** Fecha de corte, combinada con asignación manual para
casos puntuales. La asignación masiva por defecto falsearía KPIs y comisiones.

---

## D-13 · Visibilidad entre asesores

**Estado:** pendiente. **Bloquea la Fase 05.**

**Alternativas.** Cada asesor ve solo lo suyo · ve el ranking agregado sin
detalle ajeno · ve todo.

**Recomendación técnica.** Cada asesor ve solo sus ventas. El ranking, si se
desea, se muestra agregado y sin datos de clientes de terceros.

---

## D-14 · ¿Quién confirma la entrega y actualiza el estado logístico?

**Estado:** pendiente. **Bloquea la Fase 06.**

**Impacto.** Matriz de permisos [M-§13] y **confiabilidad del dato que causa la
comisión**, ya que D-01 quedó decidida por entrega.

**Alternativas.** Solo bodega · bodega y supervisor · también el asesor que
recibe la confirmación del cliente por WhatsApp.

**Recomendación técnica.** Bodega registra despacho, guía y estado. La
confirmación de entrega puede permitirse al asesor **solo** si queda auditada
por autor, fecha y origen.

> **Advertencia explícita:** con D-01 decidida, permitir que el beneficiario de
> la comisión marque "entregado" es un **conflicto de interés**. Debe decidirse
> conscientemente, no por omisión.

---

## D-15 · Periodo de corte de las comisiones

**Estado:** pendiente. **Bloquea la Fase 09.**

**Preguntas.** ¿Mensual, quincenal o semanal? ¿Cuántos días después del cierre
se paga? ¿Qué pasa con las entregas confirmadas con retraso, después de cerrado
el periodo?

**Recomendación técnica.** Periodo mensual, con pertenencia definida por la
**fecha confirmada de entrega** (D-01), y una ventana de gracia para eventos
registrados tarde; los que caigan fuera entran en el periodo siguiente como
línea normal, no como ajuste.

---

## D-16 · Momento en que una conversación se convierte en pedido — **DECIDIDA**

**Estado:** ✅ **DECIDIDA en la Fase 01B** (2026-09-13). Ya no bloquea ninguna fase.

**Decisión aprobada.**

> Durante la conversación existe un carrito oficial o borrador de Bagisto. El pedido
> se crea únicamente cuando el cliente confirma productos, variantes, cantidades,
> dirección, envío y total.
>
> El pago contraentrega no significa que el pedido esté pagado.

**Consecuencias.**

- Mientras se negocia **no existe pedido** ni reserva de inventario
  (`OrderRepository::create()` es lo que reserva [A-§6]).
- El carrito administrativo de Bagisto ya nace **inactivo** (`is_active = 0`,
  `Admin\CartController::store()`), así que no interfiere con el carrito del cliente
  en la tienda [B-§4].
- Antes de confirmar, el carrito debe estar recalculado con el grupo del **cliente del
  carrito** (RT-03). Hoy no lo está [B-§4].
- `cashondelivery` crea el pedido en estado pendiente de pago; ninguna métrica puede
  tratar «valor pedido» como dinero cobrado [M-§14.3].
- La necesidad de una tabla de contexto durable ligada a `cart_id` se evalúa en la
  Fase 02 con esta decisión ya resuelta [M-§10.5, T-§11].

*Texto anterior de la decisión, conservado como historial:*

**Pregunta.** ¿Se crea el pedido al recibir los datos, al confirmar el cliente o
al confirmar la disponibilidad?

**Impacto.** `OrderRepository::create()` **reserva inventario** en
`product_ordered_inventories` [A-§6]. Crear pedidos durante la negociación
bloquearía stock real y contaminaría los KPIs con ventas que nunca se cierran.
También determina si hace falta una tabla de contexto durable ligada a `cart_id`
[M-§10.5, T-§11].

**Recomendación técnica.** Usar carrito o borrador durante la negociación y
crear el pedido **solo cuando el cliente confirme**.

> **Reevaluación del alcance del bloqueo (Fase 01A) — propuesta, no aprobada.**
>
> Hay que separar cuatro cosas que D-16 mezclaba:
>
> | Asunto | ¿Depende de D-16? | Evidencia |
> |---|---|---|
> | Atribuir un pedido **ya creado** (storefront o panel oficial) | **No** | El listener sobre `checkout.order.save.after` actúa cuando el pedido ya existe, sea cual sea el momento comercial en que se decidió crearlo [M-§10.4, filas «Storefront» y «Panel oficial»] |
> | Contexto previo ligado a carrito o borrador | **Sí** | La tabla de contexto durable solo se justifica si D-16 adopta borradores de larga duración [M-§10.5, T-§11] |
> | Momento comercial en que el cliente confirma | **Sí**: es la pregunta misma | — |
> | Creación definitiva del pedido desde el servicio propio | **Sí** | El servicio conoce el contexto antes de crear [M-§10.2 M-3]; cuándo invocarlo depende de D-16 |
>
> **Conclusión:** D-16 **no debería bloquear por completo la Fase 02**. Se propone
> dividirla en una **Fase 02A — atribución de pedidos ya creados**, sin dependencia
> de D-16 pero **sí de D-27**, y dejar el contexto previo, el borrador y el momento
> de confirmación en la **Fase 03**, que sí depende de D-16.
>
> Esta división **no se ha aplicado al roadmap**: queda como propuesta pendiente
> de aprobación. *(Nota Fase 01B: con D-16 decidida la división deja de ser necesaria
> para desbloquear la Fase 02; puede conservarse como orden interno de trabajo.)*

---

## D-17 · Identidad y duplicados de clientes

**Estado:** pendiente. **Bloquea las Fases 03 y 04.**

**Pregunta.** ¿Cómo se busca un cliente existente y qué se hace si no tiene
correo?

**Impacto.** El core busca por correo. En venta por WhatsApp muchos clientes no
tienen correo, y ese es el origen clásico de duplicados [T-§15].

**Alternativas de criterio.** Teléfono normalizado (E.164) · correo · documento
· dirección.

**Recomendación técnica.** Teléfono normalizado como criterio primario, con
correo y documento como secundarios, y confirmación explícita del asesor antes
de crear un cliente nuevo.

> **No inventar correos falsos automáticamente.** Contamina la base, rompe las
> notificaciones e imposibilita la deduplicación posterior.

> **Actualización Fase 01B.** La regla de datos del cliente de WhatsApp quedó definida
> (nombre y teléfono obligatorios, correo opcional, documento opcional, dirección
> obligatoria con despacho). La parte técnica «qué hacer si no tiene correo» pasa a
> **D-28**; la política de teléfonos compartidos, a **D-29**; los datos mínimos por
> modalidad de entrega, a **D-30**. Hallazgos verificados que afectan a esta
> recomendación [B-§2, B-§3]:
>
> - `customers.email` **ya admite NULL** y el índice único es `(email, channel_id)`;
>   la obligatoriedad viene de validaciones, no del esquema.
> - `customers.phone` **ya tiene índice ÚNICO** (`customers_phone_unique`) y compara
>   texto: `3001112233` y `+573001112233` son valores distintos para la base.
> - La búsqueda de clientes del panel (`CustomerController::search`) **no busca por
>   teléfono**.

---

## D-18 · Modificación de precios y descuentos

**Estado:** pendiente. **Bloquea las Fases 03 y 04.**

**Pregunta.** ¿Puede el asesor cambiar precios, aplicar descuentos o seleccionar
grupos de clientes?

**Recomendación técnica.** Usar los precios oficiales calculados por Bagisto y
permitir excepciones **únicamente** con un permiso específico y con registro de
auditoría (quién, cuánto, por qué). Un descuento libre sin auditoría afecta
directamente a la base comisionable.

> **Actualización Fase 01B.** Quedan incluidas en esta decisión la política de
> **precios negociados por debajo del margen mínimo** y la de **descuentos
> excepcionales y su autorización** (`ACTUALIZACION_MODELO_COMERCIAL_POS_Y_COMISIONES.md`
> §11). Con la comisión sobre utilidad (D-31, D-32), un descuento reduce la comisión
> por sí mismo; no se aplica una segunda penalización por volumen sin regla expresa.
> Precondición técnica: el «precio oficial» solo es fiable cuando se calcula con el
> grupo del cliente del carrito (**RT-03**).

---

## D-19 · Venta sin inventario suficiente

**Estado:** pendiente. **Bloquea la Fase 03.**

**Pregunta.** ¿Se permiten pedidos pendientes o backorders, o solo stock
disponible?

**Impacto.** Determina si el fallo de `Cart::isItemsHaveSufficientQuantity()` es
un error duro que impide crear el pedido o una advertencia [T-§13].

**Recomendación técnica inicial.** Impedir la sobreventa, salvo un flujo
explícito autorizado. Con D-01 decidida por entrega, un pedido que no se puede
despachar tampoco generaría comisión, pero sí bloquearía inventario y falsearía
los KPIs de "vendido".

---

## D-20 · Cambios posteriores a la confirmación

**Estado:** pendiente. **Bloquea la Fase 04.**

**Contexto.** Bagisto **no** permite editar libremente un pedido confirmado. No
debe tratarse como si lo permitiera.

**Pregunta.** ¿Qué ocurre si el cliente cambia talla, color o cantidad después
de confirmar?

**Alternativas.** Cancelar y recrear · cancelación parcial y nuevo pedido ·
flujo controlado de modificación antes del despacho.

**Recomendación técnica.** Cancelación parcial más pedido nuevo, que es lo único
que el core soporta sin corromper totales e inventario.

> **No se implementará edición directa de `order_items`** bajo ninguna
> alternativa.

---

## D-21 · Envío y cobro del transporte

**Estado:** pendiente. **Bloquea las Fases 04 y 08.**

**Preguntas.** ¿Qué transportadoras? ¿Tarifa Bogotá y tarifa nacional? ¿Pago
contraentrega? ¿Envío pagado por el cliente o incluido? ¿Tarifa manual o
calculada? **¿El transporte forma parte del valor comisionable?**

**Recomendación técnica.** Tarifas calculadas por Bagisto y **transporte fuera
de la base comisionable** (RN-19): el asesor vende prendas, no fletes. Reportar
el ingreso por transporte como métrica separada [M-§14.3].

---

## D-22 · Definición de los KPIs monetarios

**Estado:** pendiente. **Bloquea la Fase 10.**

**Pregunta.** ¿El dashboard debe mostrar valor pedido, facturado, entregado o
efectivamente recaudado?

**Contexto.** En contraentrega, "valor pedido" no es dinero cobrado. Un pedido
despachado y retornado tiene valor pedido y **cero ingreso**.

**Recomendación técnica.** Mostrar las métricas **separadas** [M-§14.3] y **no
llamar "venta" a valores todavía no entregados**. La cifra principal del
dashboard debería ser el valor entregado, coherente con D-01.

---

## D-23 · RETIRADA — reclasificada como requisito técnico

**Estado:** ❌ **RETIRADA de las decisiones pendientes** (Fase 00C1).

La Fase 00C registró como «decisión de negocio» el tratamiento del riesgo de
reembolso duplicado. **Fue un error de clasificación.** Que una acción repetida
produzca un segundo reembolso no es una opción que el negocio pueda elegir: es un
defecto. Plantearlo como decisión sugería que «aceptar reembolsos duplicados»
fuera una postura válida.

Se ha convertido en el **requisito técnico obligatorio RT-01 · Idempotencia de la
devolución**, documentado en
`05A_AUDITORIA_RMA_Y_MAPA_CORE_RELACIONADO.md` §26.1.

**Enunciado de RT-01:** una acción de devolución repetida debe ser idempotente o
rechazada explícitamente; en ningún caso puede producir un segundo reembolso, un
segundo descuento de comisión ni un segundo movimiento de inventario. Como
`packages/Webkul/` no se modifica, el requisito se satisface en la capa de
`VentasAsesor`: la conciliación debe detectar y neutralizar el duplicado aunque
el core lo cree.

**Sigue siendo precondición bloqueante de las Fases 07, 08 y 09**, pero como
requisito técnico, no como decisión pendiente del propietario del negocio.

---

## D-24 · ¿Un reembolso sin RMA descuenta comisión?

**Estado:** pendiente. **Bloquea la Fase 09.** *(Reformulada en Fase 00C1.)*

> **Reclasificación (Fase 00C1).** La formulación original —«cómo se atribuye un
> reembolso a un RMA»— mezclaba dos cosas. La parte técnica **no requiere
> decisión del negocio**: que el sistema sepa distinguir el origen de un
> reembolso es una capacidad obligatoria. Esa parte pasó a ser el requisito
> técnico **RT-02 · Trazabilidad del reembolso** (`05A_…md` §26.2), que exige
> distinguir cinco casos: reembolso por RMA, reembolso administrativo
> independiente, reembolso repetido, reembolso parcial y varios reembolsos
> válidos sobre cantidades distintas.
>
> Lo que queda aquí es la única parte que **sí** es política comercial.

**Pregunta.** Cuando Contabilidad o un administrador emite un reembolso **sin que
exista una devolución del cliente** —corrección de precio, error de cobro, gesto
comercial— ¿debe descontarse de la comisión del asesor?

**Contexto real.** No existe FK entre `rma` y `refunds` (H-RMA-10), así que el
módulo tendrá que construir la correlación. Pero incluso resuelta la correlación,
qué hacer con un reembolso ajeno a una devolución es una decisión de negocio.

**Opciones reales.**

1. Descontar siempre, sea cual sea el origen.
2. No descontar nunca si no hay devolución del cliente.
3. Descontar solo cuando el motivo se atribuya al asesor.
4. Decidir caso por caso, con aprobación del supervisor.

**Recomendación técnica.** La 2 combinada con la 4: no descontar de forma
automática y marcarlo como incidencia para revisión. Descontar al asesor por un
error administrativo ajeno a él erosionaría la confianza en el módulo.

**Impacto.** Determina el alcance de los ajustes negativos de D-02.

---

## D-25 · Habilitación de RMA

**Estado:** pendiente. **Bloquea la Fase 07.** *(Añadida en Fase 00C.)*

**Contexto real.** **H-RMA-14**: RMA está **inoperante**, no solo sin usar.
`rma_statuses`, `rma_reasons`, `rma_reason_resolutions` y `rma_rules` están
**vacías**, y `order_items.rma_return_period` es `NULL` en las **174** filas, lo
que hace que `Helper::getOrderItems()` devuelva colección vacía para todos los
pedidos. **[DATOS]**

**Pregunta.** ¿Se pondrá RMA en funcionamiento?

**Opciones reales.**

1. Habilitarlo por completo: sembrar estados, motivos y reglas, y fijar el
   periodo de devolución.
2. Dejarlo inactivo y gestionar las devoluciones solo en `VentasAsesor`.
3. Habilitarlo solo para uso interno del administrador, sin exponerlo al
   cliente.

**Recomendación técnica.** La 3 como paso intermedio. Habilitarlo de cara al
cliente expone sin mitigación el hallazgo **S-1** (evidencias en disco público)
y **H-RMA-7** (ausencia de control de transiciones).

**Impacto.** Si no se habilita, la Fase 07 se reduce a conciliar reembolsos
manuales y el ajuste de comisión pierde su disparador natural.

---

## D-26 · Reposición y garantía

**Estado:** pendiente. **Bloquea la Fase 08.** *(Añadida en Fase 00C.)*

**Contexto real.** `DefaultRMAResolution` solo contempla `return` y
`cancel_items`. **No existe reposición** en el core. Se relaciona con D-03
(productos no comisionables) y con la exclusión de reposiciones y garantías
decidida en D-01.

**Pregunta.** Cuando se cambia una prenda defectuosa por otra sin devolver
dinero, ¿qué se registra?

**Opciones reales.**

1. Pedido nuevo de importe cero, marcado como no comisionable.
2. RMA con `cancel_items` más pedido nuevo.
3. Concepto propio dentro de `VentasAsesor`.
4. No registrarlo en el sistema.

**Recomendación técnica.** La 1: conserva el movimiento real de inventario y es
la única que no inventa un flujo paralelo al del core.

**Impacto.** Afecta al inventario y a la base comisionable.

---

## D-27 · Propiedad comercial cuando vende un administrador — **DECIDIDA**

**Estado:** ✅ **REFORMULADA Y DECIDIDA en la Fase 01B** (2026-09-13). Ya no bloquea la
Fase 02. *(Añadida en Fase 01 como «¿Un administrador con acceso total cuenta como
asesor?».)*

**Decisión aprobada.** Sustituye la exclusión absoluta que proponía la recomendación
anterior.

| Quién registra | Asignación automática | Qué puede elegir |
|---|---|---|
| Asesor normal (rol `custom` con `ventas_asesor.operar_como_asesor`) | **Se asigna a sí mismo** | Asignar a otro asesor solo con permiso de asignación |
| Administrador con `permission_type = all` | **Nunca** recibe la venta automáticamente | **Venta de empresa**, **asignarme como asesor** o **asignar otro asesor** |
| Otros roles | Nunca | Según permiso de asignación |
| Automatización (n8n, API) | Nunca por el usuario técnico | Asesor explícito en el contexto o venta sin asignar |

- **No** se crea un segundo usuario para que el administrador venda: el mismo
  `admins.id` puede ser creador y asesor cuando se elige expresamente.
- Tener `permission_type = all` **no** implica ser asesor ni recibir comisión.
- Se separan tres conceptos (**no implementados todavía**):

```text
created_by_admin_id      = quien registró el pedido (inmutable, RN-02)
advisor_id               = asesor propietario, nullable
commercial_owner_type    = advisor | company | unassigned
```

- `company` = venta directa de ByLopez: sin asesor, **sin comisión**, cuenta en las
  métricas de ventas de empresa; el administrador sigue figurando como creador.
- Un cliente puede tener una **preferencia predeterminada** (p. ej. `company` para
  clientes fijos como Rosario), visible y modificable al crear la venta. El pedido
  guarda **su propia decisión histórica**: cambiar la preferencia del cliente no altera
  pedidos anteriores.
- Detalle del modelo, casos y métricas: [B-§7].

**Implementación.** Ninguna en la Fase 01B. `AutorizacionVentasAsesor::esAsesor()`
sigue devolviendo `true` para `all`, lo cual es **compatible** con la decisión: indica
que el administrador **puede** ser elegido como asesor, no que se le asigne. La regla
«nunca automática para `all`» pertenece al resolvedor de atribución de la Fase 02.

> **Implementada en la Fase 02A** (`07_…md` §3). `ResolverAtribucionService` aplica las
> cuatro reglas y `AutorizacionVentasAsesor::esAsesorParaAtribucionAutomatica()` exige
> `permission_type = custom` más el permiso explícito; `esAsesor()` no cambió. Las tres
> opciones del administrador total están modeladas en `SeleccionPropiedadComercial`.
> **Falta** exponerlas en una interfaz y guardar la preferencia comercial del cliente.

*Texto anterior, conservado como historial:*

**Contexto real.** D-06 identifica al asesor por el permiso
`ventas_asesor.operar_como_asesor`. En Bagisto, un rol con
`permission_type = all` **tiene todos los permisos**, incluido ese:
`bouncer()->hasPermission()` devuelve `true` para cualquier clave. La Fase 01
respeta ese comportamiento oficial, así que hoy el rol `Administrador` (id 1, dos
usuarios) **se reconoce como asesor** y con alcance de visibilidad total.
Verificado por prueba automatizada (`06_FASE_01_BASE_PAQUETE_ACL.md`).

**Por qué importa.** El modelo propone que, al crear un pedido desde el panel, el
asesor por defecto sea el creador si tiene el permiso de asesor [M-§10.4]. Sin
una regla adicional, **toda venta registrada por un administrador total quedaría
atribuida a ese administrador** y entraría en sus KPIs y comisiones.

**Pregunta.** ¿Un administrador con `permission_type = all` debe tratarse como
asesor para la atribución automática y las comisiones?

**Opciones reales.**

1. Sí, siempre: se respeta la semántica de Bagisto sin excepciones.
2. No para la **atribución automática**: el pedido creado por un administrador
   total queda `sin_asesor` hasta asignarlo; sí puede operar la pantalla.
3. Solo si su rol además lista explícitamente el permiso marcador, lo que exige
   pasar a `permission_type = custom`.

**Recomendación técnica.** La 2:

> Un administrador con `permission_type = all` puede administrar el módulo, pero
> no debe ser asesor para atribución automática. Sus pedidos quedan sin asesor
> hasta que se seleccione uno explícitamente.

No altera la autorización de Bagisto, evita atribuir ventas a la gerencia por
accidente y conserva la trazabilidad del creador en `created_by_admin_id`.

**Estado de implementación (Fase 01A).** No implementada: la excepción pertenece al
resolvedor de atribución de la Fase 02. Hoy `AutorizacionVentasAsesor::esAsesor()`
sigue la semántica oficial de Bagisto y devuelve `true` para `all`.

**Impacto.** Define la regla de asesor por defecto de la Fase 02.

---

## D-28 · Estrategia técnica para clientes sin correo

**Estado:** pendiente. **Bloquea las Fases 03 y 04.** *(Añadida en Fase 01B.)*

**Contexto verificado [B-§2].** El esquema ya admite `customers.email = NULL`; lo
exigen las validaciones del panel (alta, edición y dirección del carrito). Crear un
cliente sin correo por `CustomerRepository` funciona, pero en `lpz_saas_testing` se
demostró que:

1. la notificación de pedido lanza `TypeError` si `orders.customer_email` es nulo, y
   ese error **no** lo captura el `catch (\Exception)` de los listeners del core;
2. `CustomerRepository::syncNewRegisteredCustomerInformation()` **reasigna a ese
   cliente todos los pedidos con correo nulo** si llegara a ejecutarse para él.

**Alternativas comparadas [B-§2.4].** (1) cliente Bagisto con correo nulo creado por
el servicio propio; (2) contacto comercial propio vinculado a un cliente Bagisto;
(3) cliente invitado; (4) correo de la dirección de facturación, que es lo que hace
el panel.

**Recomendación técnica.** Combinar **(1) + (2)**, condicionada a **RT-04**: cliente
Bagisto real (conserva grupo, precios e historial) con correo nulo cuando no existe,
más una ficha de contacto propia con teléfono original y normalizado. El invitado
(3) se descarta porque pierde grupo de precios e historial. Los correos sintéticos
siguen prohibidos.

---

## D-29 · Teléfonos compartidos y cambio de titular

**Estado:** pendiente. **Bloquea la Fase 04.** *(Añadida en Fase 01B.)*

**Pregunta.** Cuando un teléfono normalizado coincide con un cliente existente, ¿qué
puede hacer el asesor y qué justificación se exige? ¿Cómo se registra que un número
cambió de titular?

**Hecho que condiciona la respuesta.** `customers.phone` tiene índice ÚNICO en el core:
dos clientes no pueden compartir ese campo [B-§3]. Un número compartido solo cabe en
una tabla propia no única.

**Recomendación técnica.** Proceso «normalizar → buscar → mostrar → usar, corregir o
crear con confirmación y motivo» [B-§3.4]; historial de titularidad en la ficha de
contacto propia; nunca índice único absoluto sobre el teléfono normalizado.

---

## D-30 · Datos mínimos del cliente por modalidad de entrega

**Estado:** pendiente para recogida y POS. *(Añadida en Fase 01B.)*

**Aprobado para venta por asesor con despacho:** nombre obligatorio, teléfono
obligatorio, correo opcional, documento opcional inicialmente, dirección obligatoria.

**Pendiente.** Recogida en tienda, venta POS con entrega inmediata y exigencias
fiscales que obliguen a capturar documento.

---

> **Nota de la Fase 02A1.** El módulo **no** contiene ninguna decisión de comisión: se
> retiraron `is_excluded`, `exclusion_reason` y los métodos `esComisionable()` que
> afirmaban que toda venta de asesor lo era. Que una venta pertenezca a un asesor es
> condición necesaria, no suficiente: falta utilidad positiva, costo congelado, entrega,
> devoluciones y reglas vigentes. **D-03, D-31 y D-32 siguen pendientes.**

---

## D-31 · Costo unitario comisionable

**Estado:** pendiente. **Bloquea la Fase 08.** *(Añadida en Fase 01B.)*

**Pregunta.** Qué integra el costo unitario congelado: tela, corte, confección,
transporte interno, empaque, estampado, costos de recaudo u otros.

**Por qué importa.** La comisión propuesta es un porcentaje de la utilidad bruta
positiva por unidad entregada y no devuelta; sin costo definido no hay utilidad.

---

## D-32 · Porcentaje inicial de comisión

**Estado:** pendiente. **Bloquea la Fase 08.** *(Añadida en Fase 01B.)*

**Propuesta registrada:** 20 % de la utilidad bruta positiva, solo sobre unidades
entregadas y no devueltas. No es política definitiva hasta validar márgenes reales.
Una venta de empresa (`company`) no genera comisión (D-27).

---

## D-33 · Comisión en ventas POS

**Estado:** pendiente. **Bloquea la futura fase POS.** *(Añadida en Fase 01B.)*

**Pregunta.** Si una venta presencial comisiona, a quién (cajero o asesor comercial,
que pueden ser personas distintas) y con qué evento de entrega.

---

## D-34 · Alcance operativo y fiscal del POS

**Estado:** pendiente. **Bloquea la futura fase POS.** *(Añadida en Fase 01B.)*

**Pregunta.** Sucursal y fuente de inventario; caja, apertura, cierre y arqueo; medios
de pago combinados; comprobante y obligaciones fiscales; entrega inmediata, envío o
recogida; cancelaciones y cambios; operación sin conexión; numeración e idempotencia.

---

## Resumen

| # | Decisión | Fase bloqueada | Recomendación técnica | Estado |
|---|---|---|---|---|
| D-01 | Evento que causa comisión | 08 | Prenda entregada y no devuelta | ✅ **DECIDIDA** |
| D-02 | Devolución tras el pago | 09 | Ajuste negativo; liquidación inmutable | ✅ **DECIDIDA** |
| D-03 | Alcance y exclusiones de reglas | 08 | Vigencia y prioridad; marcar no comisionables | pendiente |
| D-04 | Reasignación tras liquidar | 09 | Bloquear tras liquidación pagada | pendiente |
| D-05 | Asesores por pedido | 02 | Un solo propietario | ✅ **DECIDIDA** |
| D-06 | Identificación del asesor | 01, 02 | Permiso ACL propio | ✅ **DECIDIDA** |
| D-07 | Estado previo al despacho | 06 | Iniciar en `despachado` | pendiente |
| D-08 | Canal y usuario de automatización | 12 | Canal + referencia externa obligatoria | pendiente |
| D-09 | Evidencias | 11 | Privado, 5 MB, 12 meses | pendiente |
| D-10 | Visibilidad de costos | 10 | Sin costos ni márgenes | pendiente |
| D-11 | Metas | 10 | Mensual, en prendas, informativa | pendiente |
| D-12 | Histórico existente | 05, 13 | Fecha de corte + casos puntuales | pendiente |
| D-13 | Visibilidad entre asesores | 05 | Solo lo propio | pendiente |
| D-14 | Quién confirma la entrega | 06 | Bodega; asesor solo si queda auditado | pendiente |
| D-15 | Periodo de corte | 09 | Mensual por fecha de entrega | pendiente |
| D-16 | Cuándo nace el pedido | — | Carrito/borrador; pedido al confirmar | ✅ **DECIDIDA** (01B) |
| D-17 | Identidad del cliente | 03, 04 | Teléfono normalizado; nunca correos falsos | pendiente |
| D-18 | Precios y descuentos | 03, 04 | Precio oficial; excepción con permiso y auditoría | pendiente |
| D-19 | Sobreventa | 03 | Impedirla salvo flujo autorizado | pendiente |
| D-20 | Cambios tras confirmar | 04 | Cancelación parcial + pedido nuevo | pendiente |
| D-21 | Envío y transporte | 04, 08 | Tarifa calculada; transporte fuera de la base | pendiente |
| D-22 | KPIs monetarios | 10 | Métricas separadas; cifra principal = entregado | pendiente |

| ~~D-23~~ | ~~Riesgo de reembolso duplicado~~ → **RT-01** | 07, 08, 09 | Requisito técnico, no decisión | ❌ **RETIRADA** |
| D-24 | ¿Un reembolso **sin RMA** descuenta comisión? | 09 | No automáticamente; marcar como incidencia | pendiente |
| D-25 | Habilitación de RMA | 07 | Solo uso interno como paso intermedio | pendiente |
| D-26 | Reposición y garantía | 08 | Pedido nuevo de importe cero, no comisionable | pendiente |
| D-27 | Propiedad comercial cuando vende un administrador | — | Nunca automática para `all`; elige empresa, él mismo u otro asesor | ✅ **DECIDIDA** (01B) |
| D-28 | Clientes sin correo | 03, 04 | Cliente Bagisto con correo nulo + contacto propio, sujeto a RT-04 | pendiente |
| D-29 | Teléfono compartido y cambio de titular | 04 | Confirmación con motivo; sin índice único absoluto | pendiente |
| D-30 | Datos mínimos por modalidad de entrega | 04, POS | Aprobado para despacho; pendiente recogida y POS | pendiente (parcial) |
| D-31 | Costo unitario comisionable | 08 | Definir componentes antes de calcular utilidad | pendiente |
| D-32 | Porcentaje inicial de comisión | 08 | 20 % de la utilidad bruta positiva (propuesta) | pendiente |
| D-33 | Comisión en ventas POS | POS | — | pendiente |
| D-34 | Alcance operativo y fiscal del POS | POS | — | pendiente |

### Requisitos técnicos obligatorios (no son decisiones de negocio)

| # | Requisito | Fase bloqueada | Detalle |
|---|---|---|---|
| **RT-01** | Idempotencia de la devolución: una acción repetida no puede producir un segundo reembolso, descuento ni movimiento de inventario | 07, 08, 09 | `[R-§26.1]` |
| **RT-02** | Trazabilidad del reembolso: el módulo debe distinguir reembolso por RMA, administrativo, repetido, parcial y múltiple válido | 07, 08, 09 | `[R-§26.2]` |
| **RT-03** | Precio por grupo del **cliente del carrito**: los precios, descuentos y totales no pueden depender del cliente autenticado en la sesión; al cambiar de cliente se recalculan todos los ítems y se bloquea la confirmación ante inconsistencia | 03, 04 | `[B-§4, B-§5]` |
| **RT-04** | Compatibilidad de clientes sin correo: ningún pedido con `customer_email` nulo puede llegar a una notificación que construya `Address(null)`, y ningún cliente sin correo puede disparar la sincronización de pedidos por correo | 03, 04 (si D-28 adopta correo nulo) | `[B-§2]` |

**Estado tras la Fase 01B.** Fases 01, 01A y 01B completadas. D-16 y D-27 están
decididas, por lo que **la Fase 02 ya no tiene decisiones bloqueantes**; no se ha
iniciado. RT-03 no bloquea la Fase 02 (atribución) pero sí las Fases 03 y 04.
