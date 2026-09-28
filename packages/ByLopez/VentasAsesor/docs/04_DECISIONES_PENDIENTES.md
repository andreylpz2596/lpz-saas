# 04_DECISIONES_PENDIENTES.md

Decisiones de negocio del módulo `ByLopez\VentasAsesor`.

**Última actualización:** 2026-09-15 (Fase 02A) · **2026-09-26 (Fase 03, Camilo Gaitán):** decididas **D-18** y **D-19** (03B) y **D-35 a D-38** (cierre 03B; la nota de numeración está antes del resumen). D-38 queda bloqueada por la fuente de costo. **D-39** (03C) decidida e implementada. **D-40 a D-47** (03D): D-43 aprobada, D-42 y D-44 diferidas, resto pendiente.

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

> **Actualización 2026-09-25 — SUPERADO EN SU NÚCLEO POR D-RT04-01/02 (Doc. 12 §14) y el Doc. 13.**
> Se decidió el cliente Bagisto con correo `NULL` creado por un servicio propio (1). La «ficha de
> contacto propia» (2) **no** se crea como entidad paralela: se sustituye por un **índice de
> teléfonos** sin nombre, email ni grupo (`bylopez_va_customer_phones`, Doc. 13 §9). RT-04 queda
> resuelto en diseño (guard de notificaciones por binding, Doc. 13 §15.3) y pendiente de
> implementación (RT-04B1).

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

> **Actualización 2026-09-25 — D-RT04-09 (Doc. 12 §14) confirma el índice no único.** La
> confirmación con motivo al crear un cliente con un teléfono ya indexado está diseñada en el Doc. 13
> §12 (P-04, P-05). El historial de titularidad sigue **pendiente**: no hay «ficha de contacto»
> (D-RT04-01), así que se registrará en la procedencia del alta o en una fase posterior.
>
> **Actualización 2026-09-26 — SUPERADO POR D-RT04-PHONE-01 (Doc. 13 §36.1):** un teléfono
> normalizado pertenece a **un solo** Customer (índice UNIQUE). Ya no existe «crear con motivo»
> para un teléfono de otro cliente: se localiza al titular.

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

> **Nota de numeración (cierre 03B, 2026-09-26; identificadores D-35 a D-38 CONFIRMADOS como definitivos por Camilo Gaitán, no se renumeran).** Camilo Gaitán presentó estas cuatro decisiones
> como «D-20 a D-23». Esos identificadores ya existían: D-20 (cambios tras confirmar), D-21 (envío
> y transporte) y D-22 (KPIs monetarios) siguen pendientes y los citan el roadmap, `00_STATUS` y
> el Doc. 05; D-23 está retirada (RT-01). Para no romper la trazabilidad se registran como
> **D-35 a D-38**. Equivalencia: D-20 de la instrucción → **D-35**, D-21 → **D-36**, D-22 → **D-37**
> y D-23 → **D-38**.

## D-35 · Días laborales del borrador — **DECIDIDA**

**Estado:** ✅ **DECIDIDA** (2026-09-26, Camilo Gaitán).

**Decisión.** Son laborales de **lunes a sábado**; el domingo no lo es. El borrador queda asignado
a su propietario **1 día hábil**: viernes 10:00 → sábado 10:00; sábado 10:00 → lunes 10:00. Los
festivos colombianos **no** se codifican: la lista es configurable y empieza vacía.

**Implementación.** `ventasasesor.calendario_laboral.dias_laborales = [1..6]` con
`CalendarioLaboralConfigurable` (Doc. 05 §23.7, §24.1).

## D-36 · Disponibilidad para entrega inmediata — **DECIDIDA**

**Estado:** ✅ **DECIDIDA** (2026-09-26, Camilo Gaitán).

**Decisión.** Para entrega inmediata **solo** cuenta el stock vendible real de Bagisto:
`disponible_inmediato = stock_vendible`. **Nunca** se suman prendas en corte, en confección,
pendientes de recibir, planeadas ni pedidos especiales. Si piden 100 y hay 30 vendibles, 50 en
confección y 20 por fabricar, la entrega inmediata es **30**, no 80 ni 100.

**Implementación.** Invariante **I-12** en `DesgloseAbastecimiento` (Doc. 05 §24.2).

## D-37 · Producción y fabricación como información comercial — **DECIDIDA**

**Estado:** ✅ **DECIDIDA** (2026-09-26, Camilo Gaitán).

**Decisión.** «En producción», «en confección» y «por fabricar» son información para conversar
con el cliente sobre la espera. **No** son stock vendible, **no** habilitan entrega inmediata,
**no** modifican el índice de inventario y **no** crean reservas. Sin una fuente fiable de
CorteConfeccion, la producción es **desconocida (NULL), nunca 0**. El sistema no promete fechas
sin una fuente fiable para calcularlas.

## D-38 · Descuento limitado por rentabilidad — **DECIDIDA en principio; bloqueada por datos**

**Estado:** ✅ principio **DECIDIDO** (2026-09-26, Camilo Gaitán). La implementación está
**bloqueada** hasta definir las fuentes de abajo.

**Decisión.** Un descuento **nunca** puede dejar la venta por debajo del margen mínimo de
rentabilidad aprobado:

```text
precio de Bagisto → costo relevante → margen mínimo → precio mínimo → descuento máximo
```

No hay porcentaje máximo global (ni 5, ni 10, ni 20 %): el máximo varía según producto, costo,
precio y grupo.

**Falta, antes de implementar:**

1. una fuente **autoritativa** de `costo_base_para_precio_minimo`. No se usa el atributo `cost`
   de Bagisto sin demostrar que es esa fuente, ni costos copiados de documentación;
2. una política de `margen_minimo_requerido`: global, por categoría o por producto, y quién la
   aprueba.

**No confundir** el margen mínimo para autorizar precio con la utilidad final de la empresa, que
incluye nómina, arriendo, plataformas, devoluciones, empaques, comisiones y desperdicios. Tampoco
con **D-31** (costo unitario comisionable), aunque podrían compartir fuente de costo: el diseño
debe permitirlo sin mezclarlos.

**Mecanismo técnico** (auditoría de CartRule en Doc. 05 §24.3): el descuento se aplicará con el
motor de reglas de carrito de Bagisto. Nunca `custom_price` (D-18) ni un cambio de grupo.

---

## D-39 · Cantidad solicitada por encima del stock vendible — **DECIDIDA**

**Estado:** ✅ **DECIDIDA** (2026-09-26, Camilo Gaitán) e implementada (Doc. 05 §26).

**Decisión.** La cantidad que pide el cliente se persiste **fuera** del Cart. **Solicitud
comercial** = lo que el cliente quiere; **Cart** = lo que Bagisto puede procesar hoy. Ninguna
de las dos reserva stock. Crear la solicitud **no** agrega automáticamente lo disponible: llevar
unidades al carrito es una acción explícita. Al operar, el carrito nunca supera lo solicitado
(invariante **I-13**) ni el **stock vendible actual** (si lo supera, se rechaza informando lo
disponible; no se recorta ni se agrega solo). El CASCADE al borrar una variante está aprobado
**solo para solicitudes de borrador**, no para pedidos (cierre 03C, Doc. 05 §27). Tabla `bylopez_va_borrador_solicitudes`, con una solicitud por variante y borrador.

*Texto original de la pregunta:*

**Hecho verificado.** Sin backorders, Bagisto rechaza entera cualquier línea que supere el stock
vendible, ya sea al agregar (sobre el total consolidado) o al cambiar la cantidad. Un Cart
**no puede** guardar «pidió 100, hay 30» (Doc. 05 §25.8). Hoy 03C responde `stock_insuficiente`
con el desglose y no guarda la cantidad pedida.

**Preguntas:**

1. ¿Se persiste la cantidad solicitada aparte del Cart?
   - **Recomendado:** una tabla propia mínima, `bylopez_va_borrador_solicitudes`, con borrador,
     producto, variante y `cantidad_solicitada`, UNIQUE(borrador, variante).
   - **Descartados:** backorders (configuración global) y `cart_items.additional` (Bagisto lo
     reemplaza en cada consolidación).
2. Al pedir más que el stock, ¿el sistema pone automáticamente lo disponible en el carrito y
   registra el faltante, o rechaza y deja decidir al asesor?

**Límites que se mantienen:** el faltante nunca cuenta como entrega inmediata (D-36, I-12) y la
producción sigue siendo informativa y null mientras no exista el contrato con CorteConfeccion
(D-37). No se crea ninguna migración hasta que Camilo decida.

## D-40 a D-45 · Entrega y logística (auditoría 03D) — **PENDIENTES**

**Estado:** ⏳ **PENDIENTES** (2026-09-26). Evidencia en el Doc. 05 §28. Ninguna está
implementada.

> **Actualización (diseño 03D, 2026-09-26, Doc. 05 §29):**
> - **D-43 APROBADA** por Camilo Gaitán: `Shipment.id` es la referencia interna, sin `dispatch_reference`; `track_number` solo guarda guías reales y, sin guía, queda en NULL.
> - **D-42 y D-44 DIFERIDAS** a la fase de logística. No bloquean 03D, 03E ni 03F. Se aprueba la necesidad de evitar el texto libre en la transportadora.
> - **D-40, D-41 y D-45:** recomendación en el Doc. 05 §29.2, §29.4 y §29.8, pendiente de aprobación.
> - **Nuevas:** D-46 y D-47.
>
> **Implementación 03D (2026-09-26, Doc. 05 §30):**
> - **D-40 APROBADA e implementada:** carriers `recogida_bodega`, `domicilio_local` y `envio_nacional` (paquete `ByLopez/Logistica`), inactivos por defecto; el envío gratis es CartRule.
> - **D-41 decidida (opción B) e implementada:** en la recogida, la dirección de envío es la bodega real (`inventory_sources` activa) con los datos de quien recibe.
> - **D-45 APROBADA e implementada:** paquete `ByLopez/Logistica`.
> - **D-46 implementada:** código postal opcional en el borrador (sigue obligatorio en las direcciones guardadas de RT-04). Cliente sin dirección real → sin entrega.
> - **D-47 parcial:** tarifa del domicilio configurable en el panel, sin valor (sin tarifa no se ofrece); envío nacional con tarifa 0 «por pagar». Pendiente: el valor del domicilio y si se limita a Bogotá.

## D-46 · Facturación mínima — **PENDIENTE**

- **Pregunta 1:** ¿el código postal es obligatorio (como exige hoy RT-04 en las direcciones guardadas) u opcional (la base de datos lo admite NULL y no hay tasas de impuesto)?
- **Pregunta 2:** si el cliente no da ninguna dirección, ¿no hay pedido? Bagisto exige siempre la dirección de facturación y no se inventan datos.

## D-47 · Tarifas de entrega — **PENDIENTE**

- **Pregunta 1:** política y valor del domicilio local (fija configurable, 0 «por pagar al domiciliario», gratis con CartRule o, más adelante, por zonas). No se usa un valor manual por venta.
- **Pregunta 2:** ¿el domicilio local se limita a Bogotá? ¿Con qué criterio de ciudad o departamento?
- **Pregunta 3:** ¿el envío nacional será siempre «flete por pagar» (0) o a veces se cobrará en el pedido?

- **D-40 · Métodos de entrega.**
  - **Pregunta:** ¿qué catálogo de métodos de entrega se usa (por ejemplo recogida en bodega,
    domicilio local de Bogotá, envío nacional)?
  - **Contexto:** hoy `flatrate` («Envío por pagar», tarifa 0) y `free` («Envío gratuito»)
    mezclan método de entrega con política de cobro del flete.
  - **Recomendación:** métodos como carriers y tarifas de Bagisto; la política de cobro del flete,
    como tarifa o descripción del método.
- **D-41 · Dirección en la recogida.**
  - **Contexto:** Bagisto exige dirección de envío y tarifa si hay productos con stock, y siempre
    dirección de facturación.
  - **Pregunta:** en la recogida, ¿la dirección de envío es la de la **bodega** (el lugar real de
    entrega) o la del cliente? ¿Qué dirección de facturación se exige a quien no tiene?
- **D-42 · Catálogo de transportadoras.**
  - **Pregunta:** ¿se aprueba un catálogo con código estable (Inter Rapidísimo, Servientrega,
    99envíos, domiciliarios, BYLOPEZ) que escriba `shipments.carrier_code` (columna nativa sin
    usar) y `carrier_title`, en lugar del texto libre actual?
  - **Pregunta abierta:** ¿cómo se registran las personas domiciliarias? Son datos personales.
- **D-43 · Referencia interna del despacho.**
  - **Propuesta:** el número del Shipment de Bagisto, sin campo nuevo; `track_number` solo para
    guías externas reales y se abandona la práctica de números falsos.
- **D-44 · Guía posterior y estados de entrega.**
  - **Contexto:** Bagisto no permite editar un shipment ni tiene estados de entrega.
  - **Pregunta:** ¿se aprueba, para la fase de logística, una extensión mínima para añadir o
    corregir la guía con auditoría y registrar «en tránsito», «entregado» e «incidencia»?
- **D-45 · Ubicación y división.**
  - **Pregunta 1:** ¿dónde viven los carriers ByLopez? En VentasAsesor, o en un paquete ByLopez de
    logística, porque también afectan a la tienda.
  - **Pregunta 2:** confirmar la división propuesta:
    - 03D: dirección y método de entrega sobre el borrador;
    - 03E: pago;
    - 03F: confirmación del pedido;
    - una fase de logística aparte, porque opera sobre el pedido, no sobre el borrador.

## D-48 a D-51 · Pago (auditoría 03E) — **PENDIENTES**

**Estado:** ⏳ **PENDIENTES** (2026-09-27). Evidencia en el Doc. 05 §32. Ninguna está
implementada.

- **D-48 · Estado inicial de la transferencia (CRÍTICA).**
  - **Hecho:** hoy `lpz_saas` configura `moneytransfer` con `invoice_status = paid` y
    `order_status = processing`, así que Bagisto crea la factura **pagada** al crear el pedido,
    sin verificar el dinero (las 10 transferencias históricas lo confirman).
  - **Opción nativa:** `invoice_status = pending` y `order_status = pending_payment`, y
    confirmar con una transacción del panel.
  - **Coste:** es un cambio de configuración global (también afecta a la tienda) y en producción,
    así que requiere autorización.
- **D-49 · Métodos permitidos en el canal asesor.**
  - **Recomendación:** métodos ofrecidos = disponibles según Bagisto ∩ lista configurable de
    VentasAsesor, inicialmente `cashondelivery` y `moneytransfer`.
  - Sin un motor propio ni una lista cerrada en la lógica.
- **D-50 · Cobro de contra entrega y medios de cobro.**
  - **Pregunta 1:** ¿el cobro se registra con transacciones nativas? Evitan la inconsistencia del
    cambio masivo de estado: hoy hay 3 pedidos `pending_payment` con factura `paid`.
  - **Pregunta 2:** ¿efectivo y datáfono se registran como medio de la transacción, o como
    métodos de Bagisto?
- **D-51 · Comprobante de transferencia.** Diferido: Bagisto no tiene adjuntos de pago; se
  diseñará cuando se aborde la verificación de pagos.

> **Evidencia definitiva 03E (2026-09-27, Doc. 05 §34).** No se cierra ninguna: decide Camilo Gaitán.
>
> - **D-48: CERRABLE.** El código confirma que factura pendiente → `pending_payment` → transacción nativa → factura `paid` y pedido `processing`/`completed` es coherente. La configuración es por canal (con un solo canal, global), así que decisión y despliegue van separados.
> - **D-49: CERRABLE.** Métodos ofrecidos = lista de VentasAsesor ∩ disponibles según Bagisto. Mecanismo: configuración de VentasAsesor.
> - **D-50: CERRABLE en arquitectura.** El dinero se registra solo con `order_transactions` asociadas a la factura, y `payment_method` guarda el medio real con códigos de una lista configurable. La implementación depende de la *decisión nueva propuesta 1* (idempotencia): la vía nativa no protege concurrencia ni reintentos.
> - **D-51: CERRABLE como principio.** Comprobante ≠ pago confirmado; no hay adjuntos nativos, así que el mecanismo de archivo queda diferido.
>
> **Decisiones nuevas propuestas (sin numerar):** (1) dónde persistir la clave de idempotencia de pagos; (2) política de saldo ante reembolsos, cancelaciones y facturas `paid` sin transacción; (3) distribución de un pago global del cliente.
>
> **Cierre arquitectónico (2026-09-27, Doc. 05 §35).** Camilo Gaitán **aprueba** D-48, D-49, D-50 y D-51.
>
> - **D-48:** el despliegue en producción (configuración de `moneytransfer`) sigue necesitando una autorización aparte.
> - **La propuesta (1)** pasa a ser **D-53, decidida**.
> - **Las propuestas (2) y (3)** siguen diferidas: el servicio rechaza pedidos con reembolso y facturas ya `paid` (se ven como `revisar`) y no reparte pagos globales.

## D-52 · Principio de venta rápida de bodega — **DECIDIDA (principio transversal)**

**Estado:** ✅ **DECIDIDA** (2026-09-27, Camilo Gaitán) como requisito funcional que deben
respetar 03E, 03F, la interfaz Nueva Venta y la futura cartera. **No autoriza implementar**
nada todavía.

**Motivación.** En bodega llegan varios clientes seguidos. Registrar un pedido hoy es lento, así
que se atiende primero y se registra después, y se acumulan pedidos sin registrar. Resultado:
pérdida de trazabilidad, inventario desactualizado, cartera incompleta y dudas sobre cuánto debe
cada cliente. **La velocidad de registro es un requisito funcional**, no una preferencia visual.

**Principio.** Registrar una venta presencial de bodega debe ser **rápido, intuitivo, continuo y
con pocas interacciones**, sin recorrer las pantallas administrativas de Bagisto. **Más rápido,
no menos control.**

**Arquitectura.** Interfaz simple → servicios comerciales ByLopez → Bagisto (Customer, grupo,
Cart, precios, inventario, envío, pago, Order, factura y transacciones). La interfaz **no**
contiene reglas de precio, stock, variantes, grupos, envío o pago, ni crea el Order.

**Caso de referencia.** Un cliente existente pide 6 camisetas Caballero Negro M y 6 L, recoge en
bodega y paga por transferencia. El flujo debe ser: Nueva Venta → buscar cliente → seleccionar →
cantidades → recogida → transferencia → confirmar (→ registrar pago si ya pagó). No debe ser un
recorrido por clientes, carrito, direcciones, envío, pago, pedido y factura.

**Requisitos que quedan registrados (Doc. 05 §33):**

- una pantalla principal;
- búsqueda rápida de cliente (RT-04);
- alta de cliente sin salir de la venta (email opcional, sin cuenta de tienda artificial);
- matriz compacta de variantes;
- stock visible (el carrito nunca supera el stock vendible);
- precio de RT-03;
- borradores reanudables (03B);
- entrega con los métodos de Bagisto (03D);
- pago elegido entre los permitidos (03E);
- **confirmar y registrar el pago como dos hechos distintos**;
- pago pendiente que aparece solo en la cartera;
- saldo pendiente informativo (no bloquea la venta);
- confirmación breve con «Nueva venta», «Registrar pago» y «Ver pedido»;
- operación con teclado, diseño adaptable (PC, tablet y celular) y baja latencia;
- concurrencia y trazabilidad intactas;
- los mismos servicios para asesor, voz, WhatsApp, n8n e IA.

**Límites.**

- Sin precios fijos en código ni `custom_price`.
- La UI no inventa ids, SKU, stock ni precio.
- Sin un segundo sistema de borradores.
- Sin marcar un pedido como pagado con una casilla: el dinero se registra como un hecho
  económico.
- Sin estado técnico «moroso»: la mora requerirá vencimiento y una política explícita.
- **Un solo servicio de dominio para «Registrar pago»**, desde el pedido, la cartera o la ficha
  del cliente.

**Precisión (2026-09-27, Camilo Gaitán, Doc. 05 §33.8).** Se separan dos tiempos:

- **Tiempo comercial:** lo que tarda el cliente en decidir. Sin SLA; no es lentitud del sistema.
- **Tiempo operativo:** interacciones que añade la aplicación para registrar lo ya decidido.
  **Se optimiza y se mide.**

Objetivo: registrar una decisión ya tomada debe ser extremadamente rápido. Esto se concreta en
una matriz de tallas por producto y color, sin repetir la búsqueda por cada variante; métricas
por escenario (tiempo operativo, clics, búsquedas repetidas, cambios de página, modales,
requests, latencia, errores); y los escenarios E-01 a E-10. **Sin SLA en segundos** hasta medir
la interfaz real. Velocidad = hacer automáticamente lo que el sistema ya conoce, nunca omitir
controles.

**Precisión: navegación POS de productos (2026-09-27, Doc. 05 §33.9).**

- **Modelo híbrido:** accesos rápidos → color → matriz de variantes reales, más buscador,
  recientes, frecuentes y escáner futuro. Todos convergen en `CatalogoVentaService` y en las
  primitivas de 03C.
- **Evidencia:** la línea comercial («CABALLERO/DAMA/NIÑO») sale de las **categorías** de
  Bagisto, no de la familia de atributos, del nombre ni de un atributo de género (no existe). El
  color es la opción real del atributo `color`, con swatch; hoy cada configurable es línea × color.
  Las tallas son los ejes reales de cada configurable.
- **Hallazgo:** la búsqueda actual no encuentra «caballero» ni «dama» (los nombres dicen «Hombre»
  o «Mujer») ni el SKU de una variante.
- **Pendientes:** qué categorías son accesos rápidos (configuración, sin nombres en el código) y si
  se crea un atributo de código de barras.
- **Límites:** sin segundo catálogo, sin stock ni precio duplicados y sin implementar hasta cerrar
  03E y 03F.

## D-53 · Registro seguro de pagos: idempotencia y concurrencia — **DECIDIDA (arquitectura)**

**Estado:** ✅ **DECIDIDA** (2026-09-27, Camilo Gaitán: persistencia propia con UNIQUE real, no
solo `order_transactions.data`). Diseño en el Doc. 05 §35. **Sin migración ni código todavía.**

**Decisión:**

- **Tabla mínima `bylopez_va_registros_pago`:** `mecanismo`, `idempotency_key`,
  `huella_solicitud`, `order_transaction_id` (UNIQUE, FK RESTRICT), `actor_admin_id` y
  timestamps, con UNIQUE(`mecanismo`, `idempotency_key`).
- **Qué no guarda:** saldo, monto, medio, pedido, factura ni ledger.
- **Reintentos:** la misma clave con la misma huella devuelve la operación existente; la misma
  clave con otra huella da conflicto; una clave distinta es otro pago legítimo, lo que permite los
  **abonos parciales sobre UN mismo pedido o factura** (pago 1 de 150.000 y pago 2 de 250.000).
  Esto es distinto del **abono general** del cliente sobre varios pedidos (Doc. 05 §36.4): ese
  reparto es una sola operación comercial atómica, con sus propias garantías de idempotencia y
  bloqueo por definir en su implementación futura; no se resuelve con esta clave por pedido.
- **Una sola transacción de base de datos, en este orden:**
  1. reservar la clave;
  2. bloquear pedido → factura;
  3. recalcular lo pagado bajo bloqueo;
  4. validar;
  5. crear la transacción nativa;
  6. si se completa el total: `InvoiceRepository::updateState('paid')` y
     `OrderRepository::updateOrderStatus($order)` **sin estado**, que calcula por cantidades (no
     se copia la regla «algún envío = completed» del controlador).

## Propuestas pendientes de 03F (sin numerar) — **PENDIENTES DE DECISIÓN**

**Estado:** ⏳ propuestas (2026-09-27, auditoría 03F, Doc. 05 §37.12). No se numeran: las decide Camilo Gaitán.

1. **Garantía anti-sobreventa en la confirmación:** bloqueos con nombre por variante (sin tocar Webkul), o aceptar el riesgo nativo de Bagisto (no valida stock bajo bloqueo; la reserva es un leer-sumar-escribir sin bloqueo).
2. **Cambio de precio o tarifa entre la vista y la confirmación:** `total_esperado` con conflicto `totales_cambiaron` (recomendado), o confirmar siempre con el total recalculado.
3. **Transferencia antes de desplegar D-48:** desplegar D-48 primero, o allowlist solo con `cashondelivery` mientras tanto.
4. **Umbral para tomar un `confirmando` viejo**, y si la toma es automática o explícita.
5. **Carrito tras confirmar:** borrarlo como el panel (recomendado) o desactivarlo.
6. **Operación:** cola asíncrona real para correos y reindexado.

**Contradicción detectada:** el listener de atribución **no revierte** el pedido cuando falla (decisión de 02B1A, vigente); lo marca `fallo_tecnico`. 03F la respeta salvo que Camilo decida cambiarla.

**Decisiones de Camilo Gaitán (2026-09-27, cierre técnico 03F; registradas sin numerar):**

- **Atribución:** se mantiene 02B1A (un fallo no revierte el pedido: `fallo_tecnico` + incidencia).
- **Propuesta 2, APROBADA:** `total_esperado`. Si el total recalculado por Bagisto (precio, grupo, descuentos, impuestos, tarifa o cualquier componente) no coincide, no se confirma: `totales_cambiaron` con el total nuevo.
- **Propuesta 3, decidida:** 03F se desarrolla y prueba; la transferencia **no** se usa en producción en Nueva Venta hasta desplegar D-48.
- **Propuesta 5, APROBADA:** el carrito se borra tras confirmar (borrador `confirmado`, `order_id`, `cart_id` en NULL por SET NULL).
- **Propuesta 6:** fuera de 03F (deuda independiente de operación).

**Propuestas técnicas A y B del cierre 03F (Doc. 05 §38):** aprobadas por Camilo Gaitán el
2026-09-27 como **D-54** (A, stock) y **D-55** (B, recuperación). Implementadas en 03F (Doc. 05 §39).

## D-54 · Protección concurrente de inventario — **APROBADA (implementada en 03F)**

**Estado:** ✅ **APROBADA** (2026-09-27, Camilo Gaitán). Implementada en 03F (Doc. 05 §39.3).

**Decisión:** «cerco de inventario» en `checkout.order.save.before`, para los pedidos creados
mediante `OrderRepository` (tienda, panel y VentasAsesor):

- identifica los productos con gestión de stock del pedido, los ordena por `product_id`
  ascendente y bloquea sus filas `products` con `FOR UPDATE`;
- revalida la disponibilidad con la semántica real de Bagisto (Σ stock de las fuentes activas del
  canal − reservado del canal; `back_orders` donde Bagisto lo admite) y, si no alcanza, **falla
  cerrado**;
- **no** reserva inventario por sí mismo ni descuenta stock físico: Bagisto sigue creando la
  reserva nativa.

**Descripción corregida (Camilo Gaitán, 2026-09-27):** serialización y revalidación de
inventario durante la creación de pedidos. **Mantiene la semántica nativa de Bagisto** y su
protección es efectiva **donde Bagisto genera reservas**: productos simples y variantes vendidas
como línea simple. **No** introduce reservas adicionales para configurables y **no** promete
«sin sobreventa» para las variantes vendidas mediante un configurable. Se mantiene tal cual
(no se retira ni se quitan todavía los bloqueos de los configurables).

**Riesgos residuales conocidos (fuera de 03F):** Shipment concurrente, ajustes directos de
inventario y operaciones externas que no pasen por `OrderRepository`.

**Limitación conocida (aceptada, ver abajo):** en las variantes de configurables la reserva
nativa es 0, así que el cerco no evita una venta doble ni concurrente ni en secuencia.

## D-55 · Confirmación idempotente — **APROBADA (implementada en 03F)**

**Estado:** ✅ **APROBADA** (2026-09-27, Camilo Gaitán). Implementada en 03F (Doc. 05 §39.2).

**Decisión:**

- el borrador recibe `confirmation_token` (identifica **un** intento de confirmación) y
  `confirmacion_iniciada_en` (inicio y lease del intento), con la migración 000008;
- `updated_at` **no** se usa como lease; la duración del lease es **configurable** (parámetro
  técnico, no regla de negocio);
- el tiempo solo decide cuándo otro intento puede intentar recuperar; la seguridad la da el token
  como **fencing token**: un intento con un token que ya no es el vigente no puede crear ni
  enlazar un pedido;
- `external_reference` conserva su semántica (identidad de creación u origen del borrador). La
  identidad idempotente del pedido sigue siendo `borrador_id`.

## Reserva nativa de variantes de configurables — **DECIDIDA: LIMITACIÓN CONOCIDA (no se corrige)**

**Estado:** ✅ **DECIDIDA** (2026-09-27, Camilo Gaitán; sin numerar). Auditoría quirúrgica en el
Doc. 05 §39.4 y §40.1.

**Decisión:** se acepta el comportamiento nativo de Bagisto 2.4.9 y **no se corrige**:

- el hijo llega al pedido con `qty_ordered` 0 y su reserva nativa queda en 0;
- el stock vendible no baja por el pedido; el físico baja con el Shipment.

**Prohibido:** modificar Webkul, parchear `OrderItemResource` o `manageInventory`, escribir en
`product_ordered_inventories`, crear una reserva o un stock comprometido propios, o restar
cantidades al stock Bagisto. **Bagisto sigue siendo la autoridad de inventario.** El hueco
entre pedido y Shipment se cubre solo con la señal informativa «confirmados sin despachar»
(ver abajo).

**Registro original del hallazgo** (se conserva). Las opciones 1 y 2 quedan **descartadas** por
esta decisión.

**Hecho verificado (código y `lpz_saas`, solo SELECT):**

- `Configurable::prepareForCart` no da cantidad a la fila hija, así que la columna vale 0 por
  defecto;
- `OrderItemResource` calcula entonces `qty_ordered` del hijo = 0 × cantidad del padre = 0;
- `OrderItemRepository::manageInventory` reserva esa cantidad, **0**.

En producción, todos los hijos salvo uno tienen cantidad 0 en `cart_items` y `order_items`, y 88
de las 93 filas de `product_ordered_inventories` están en 0.

**Consecuencia:** para las variantes de configurables (todo el catálogo línea × color), un pedido
no descuenta nada del vendible hasta el Shipment. Dos pedidos de 30 sobre 50 unidades se
confirman los dos, incluso **en secuencia**, por la tienda, el panel o VentasAsesor. El cerco
D-54 serializa, pero no puede ver una reserva que no existe. **Es preexistente, no lo causa 03F.**

**Opciones (ninguna implementada):**

1. corregirlo en Bagisto (Webkul), prohibido para este módulo;
2. sustituir la reserva nativa desde VentasAsesor, por ejemplo con un `OrderItemRepository`
   enlazado en el contenedor que use la cantidad del padre. Cambia la reserva global y contradice
   el «no reserva por sí mismo» de D-54: exige decisión;
3. aceptar el riesgo hasta una fase de inventario.

**Exige además** revisar cancelación y Shipment, que hoy restan de una reserva que vale 0.

**Corrección de la auditoría:** las «88 filas en 0» no bastan como prueba, porque el Shipment
también devuelve la reserva a 0. La prueba es `order_items` (todo hijo de configurable tiene
`qty_ordered` 0) y la traza controlada del Doc. 05 §40.1.

## Señal comercial de ventas simultáneas — **DECIDIDA (implementada, sin commit)**

**Estado:** ✅ **DECIDIDA** (2026-09-27, Camilo Gaitán; sin numerar). Implementada:
`VentasSimultaneasService` (Doc. 05 §40).

**Decisión:**

- es una herramienta **informativa** para los asesores: no es una reserva, no modifica stock, no
  bloquea, no participa en la confirmación y no crea tablas (todo se calcula a partir de datos
  existentes);
- métricas por variante:
  - `stockBagisto` (intacto);
  - «en esta venta»;
  - «en otras ventas sin confirmar»: borradores `activo` y `confirmando`;
  - «confirmados sin despachar»: `qty_to_ship` nativo, que cubre el hueco de los configurables;
  - el detalle por asesor;
- **nunca** existe un «disponible» propio: 50 − 30 no se publica como 20. La advertencia solo
  compara;
- **nombres visibles:** los asesores del módulo ven el nombre del asesor de otra venta y la
  cantidad, nada más (ni cliente, teléfono, dirección, precio ni total). Ver el nombre **no**
  da permiso para abrir, editar, reclamar ni confirmar el borrador ajeno;
- **antigüedad:** **no** se excluyen borradores por un umbral. Se informa la última actividad
  (el carrito o sus líneas; no `borradores.updated_at`) y la interfaz futura podrá atenuar los
  antiguos;
- **alcance:** solo Nueva Venta y asesores. No se expone a la tienda, a clientes, a WhatsApp, a
  n8n ni a la IA sin una política explícita;
- **tiempo real:** sin WebSockets. Carga al seleccionar, refresco tras editar y polling por lotes
  cada 20–30 s mientras la pantalla esté visible (cuando exista la interfaz).

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
| D-18 | Precios y descuentos | 03, 04 | Precio oficial; excepción con permiso y auditoría | ✅ **DECIDIDA 2026-09-26** (Camilo Gaitán): sin precios manuales, nunca `custom_price`; descuento autorizado = fase aparte (Doc. 05 §23.1, §23.10) |
| D-19 | Sobreventa | 03 | Impedirla salvo flujo autorizado | ✅ **DECIDIDA 2026-09-26** (Camilo Gaitán): compromiso disponible / en producción / pedido especial, nunca stock falso (Doc. 05 §23.1, §23.4) |
| D-20 | Cambios tras confirmar | 04 | Cancelación parcial + pedido nuevo | pendiente |
| D-21 | Envío y transporte | 04, 08 | Tarifa calculada; transporte fuera de la base | pendiente |
| D-22 | KPIs monetarios | 10 | Métricas separadas; cifra principal = entregado | pendiente |

| ~~D-23~~ | ~~Riesgo de reembolso duplicado~~ → **RT-01** | 07, 08, 09 | Requisito técnico, no decisión | ❌ **RETIRADA** |
| D-24 | ¿Un reembolso **sin RMA** descuenta comisión? | 09 | No automáticamente; marcar como incidencia | pendiente |
| D-25 | Habilitación de RMA | 07 | Solo uso interno como paso intermedio | pendiente |
| D-26 | Reposición y garantía | 08 | Pedido nuevo de importe cero, no comisionable | pendiente |
| D-27 | Propiedad comercial cuando vende un administrador | — | Nunca automática para `all`; elige empresa, él mismo u otro asesor | ✅ **DECIDIDA** (01B) |
| D-28 | Clientes sin correo | 03, 04 | Cliente Bagisto con correo nulo + contacto propio, sujeto a RT-04 | pendiente → **núcleo decidido 2026-09-25 por D-RT04-01/02** (índice de teléfonos en lugar de ficha de contacto; Doc. 12 §14, Doc. 13) |
| D-29 | Teléfono compartido y cambio de titular | 04 | Confirmación con motivo; sin índice único absoluto | pendiente |
| D-30 | Datos mínimos por modalidad de entrega | 04, POS | Aprobado para despacho; pendiente recogida y POS | pendiente (parcial) |
| D-31 | Costo unitario comisionable | 08 | Definir componentes antes de calcular utilidad | pendiente |
| D-32 | Porcentaje inicial de comisión | 08 | 20 % de la utilidad bruta positiva (propuesta) | pendiente |
| D-33 | Comisión en ventas POS | POS | — | pendiente |
| D-34 | Alcance operativo y fiscal del POS | POS | — | pendiente |
| D-35 | Días laborales del borrador | 03 | — | ✅ **DECIDIDA** (2026-09-26): lunes a sábado; 1 día hábil |
| D-36 | Disponibilidad para entrega inmediata | 03 | — | ✅ **DECIDIDA** (2026-09-26): solo stock vendible de Bagisto (I-12) |
| D-37 | Producción como información comercial | 03 | — | ✅ **DECIDIDA** (2026-09-26): informativa, nunca stock; desconocida = NULL |
| D-38 | Descuento limitado por rentabilidad | 03 | Reglas de carrito de Bagisto (Doc. 05 §24.3) | ✅ principio decidido (2026-09-26); **bloqueada** por fuente de costo y política de margen |
| D-39 | Cantidad solicitada por encima del stock vendible | 03C | Solicitud comercial fuera del Cart (Doc. 05 §26) | ✅ **DECIDIDA 2026-09-26** (Camilo Gaitán): tabla propia, sin parcial automático, I-13 |
| D-40 | Métodos de entrega | 03D | Carriers y tarifas de Bagisto (Doc. 05 §28–§30) | ✅ **APROBADA y CERRADA** (03D; 3 carriers inactivos por defecto; envío gratis = CartRule) |
| D-41 | Dirección en la recogida en bodega | 03D | Bodega real (`inventory_sources`) + quien recibe | ✅ **APROBADA y CERRADA**: opción B (Doc. 05 §30.2) |
| D-42 | Catálogo de transportadoras | Logística | `carrier_code` nativo más catálogo (Doc. 05 §28.5) | ⏸ **diferida** a logística (evitar texto libre: aprobado) |
| D-43 | Referencia interna del despacho | Logística | Número del Shipment, sin campo nuevo | ✅ **APROBADA 2026-09-26**: `Shipment.id`; `track_number` solo para guías reales, sin guía NULL |
| D-44 | Guía posterior y estados de entrega | Logística | Extensión mínima auditada | ⏸ **diferida** a logística |
| D-45 | Ubicación de los carriers y división de subfases | 03D | Paquete `ByLopez/Logistica`; 03D, 03E, 03F y logística | ✅ **APROBADA** (paquete creado en 03D) |
| D-46 | Facturación mínima | 03D | Datos reales; código postal opcional en el borrador | ✅ **implementada** (Doc. 05 §30.3); abierto: relajar también RT-04 |
| D-47 | Tarifas de entrega | 03D | Domicilio configurable; nacional 0 «por pagar» | ◐ **parcial**: falta el valor del domicilio y la restricción a Bogotá |
| D-48 | Estado inicial de la transferencia | 03E | Hoy se crea pagada; opción nativa: pendiente + transacción (Doc. 05 §32.4) | ✅ **DECIDIDA 2026-09-27** (arquitectura: pendiente hasta que el dinero sea real); **despliegue en producción pendiente de autorización** |
| D-49 | Métodos permitidos en el canal asesor | 03E | Disponibles ∩ lista configurable (Doc. 05 §32.8, §34.12) | ✅ **DECIDIDA 2026-09-27** |
| D-50 | Cobro de contra entrega y medios de cobro | 03E / cobros | Transacción nativa + medio con códigos configurables (Doc. 05 §34.9) | ✅ **DECIDIDA 2026-09-27** (con D-53) |
| D-51 | Comprobante de transferencia | Cobros | Comprobante ≠ pago; sin soporte nativo | ✅ **DECIDIDA 2026-09-27** como principio; mecanismo de archivo diferido |
| D-52 | Principio de venta rápida de bodega | 03E, 03F, UI, cartera | Interfaz simple sobre servicios sobre Bagisto; confirmar ≠ registrar pago; tiempo comercial ≠ tiempo operativo (E-01 a E-10); navegación POS sobre categorías y atributos reales (P-01 a P-07) (Doc. 05 §33) | ✅ **DECIDIDA 2026-09-27** (requisito transversal; sin implementar) |
| D-53 | Registro seguro de pagos: idempotencia y concurrencia | 03E / cobros | Tabla mínima con UNIQUE(mecanismo, clave), bloqueo pedido → factura, primitivas nativas (Doc. 05 §35–§36.4) | ✅ **DECIDIDA 2026-09-27** (arquitectura; sin migración; abono general FIFO/manual documentado como requisito futuro, Doc. 05 §36.4) |
| D-54 | Protección concurrente de inventario | 03F | Cerco en `checkout.order.save.before`: `FOR UPDATE` de `products` en orden ascendente y revalidación con la semántica de Bagisto; no reserva (Doc. 05 §38.3, §39.3) | ✅ **APROBADA e IMPLEMENTADA 2026-09-27**; redactada de nuevo: serialización y revalidación con la semántica nativa, efectiva donde Bagisto reserva; en configurables, **limitación conocida aceptada** (sin corregir) |
| D-55 | Confirmación idempotente | 03F | `confirmation_token` + `confirmacion_iniciada_en` (000008), lease configurable, fencing en el cerco y enlace condicionado (Doc. 05 §38.6, §39.2) | ✅ **APROBADA e IMPLEMENTADA 2026-09-27** (000008 solo en `lpz_saas_testing`) |

### Requisitos técnicos obligatorios (no son decisiones de negocio)

| # | Requisito | Fase bloqueada | Detalle |
|---|---|---|---|
| **RT-01** | Idempotencia de la devolución: una acción repetida no puede producir un segundo reembolso, descuento ni movimiento de inventario | 07, 08, 09 | `[R-§26.1]` |
| **RT-02** | Trazabilidad del reembolso: el módulo debe distinguir reembolso por RMA, administrativo, repetido, parcial y múltiple válido | 07, 08, 09 | `[R-§26.2]` |
| **RT-03** | Precio por grupo del **cliente del carrito**: los precios, descuentos y totales no pueden depender del cliente autenticado en la sesión; al cambiar de cliente se recalculan todos los ítems y se bloquea la confirmación ante inconsistencia | 03, 04 | `[B-§4, B-§5]` |
| **RT-04** | Compatibilidad de clientes sin correo: ningún pedido con `customer_email` nulo puede llegar a una notificación que construya `Address(null)`, y ningún cliente sin correo puede disparar la sincronización de pedidos por correo | 03, 04 (si D-28 adopta correo nulo) — **lo adoptó** (D-RT04-02); RT-04A auditado, RT-04B0 diseñado (Doc. 12, 13) | `[B-§2]` |

**Estado tras la Fase 01B.** Fases 01, 01A y 01B completadas. D-16 y D-27 están
decididas, por lo que **la Fase 02 ya no tiene decisiones bloqueantes**; no se ha
iniciado. RT-03 no bloquea la Fase 02 (atribución) pero sí las Fases 03 y 04.
