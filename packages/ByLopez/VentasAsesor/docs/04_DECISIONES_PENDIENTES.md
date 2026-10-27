# 04_DECISIONES_PENDIENTES.md

Decisiones de negocio del módulo `ByLopez\VentasAsesor`.

**Última actualización:** **2026-10-09:** **DESP-D-01…08 (Despacho V1) DECIDIDAS** por Camilo Gaitán e implementadas en desarrollo
(drawer «Despachar», guía y PDF, flete y recaudo). · **2026-10-08 (tercera ronda):** **LOG-D-01…04 y COM-D-15 DECIDIDAS** por Camilo Gaitán e
implementadas en desarrollo (LOG-01 y G-11). COM-04 queda a la espera de la validación manual. · **2026-10-08 (segunda ronda):** **D-COST-01…07 DECIDIDAS** (el `cost` de Bagisto es el costo
comisionable oficial; supera la precisión anterior de COM-D-02); nuevas decisiones abiertas **COM-D-15** (G-11) y
**LOG-D-01…04** (entrega), que bloquean COM-04. Sección «D-COST» antes de «COM-D». · **2026-10-08 (COM-01/02/03):** Camilo Gaitán **decidió COM-D-01 y COM-D-03 a COM-D-14**
(COM-D-10 = **quincenal**) y **precisó COM-D-02** (el `cost` de Bagisto se captura como referencia **sin autoridad de
comisión**; la fuente autoritativa sigue pendiente). Tabla de decisiones al inicio de la sección «COM-D». · **2026-10-07 (COM-00):** añadidas **COM-D-01 a COM-D-14** (comisiones; sección «COM-D» antes del
resumen) y ASESOR-02 validada (nota en D-60). · 2026-09-15 (Fase 02A) · **2026-09-26 (Fase 03, Camilo Gaitán):** decididas **D-18** y **D-19** (03B) y **D-35 a D-38** (cierre 03B; la nota de numeración está antes del resumen). D-38 queda bloqueada por la fuente de costo. **D-39** (03C) decidida e implementada. **D-40 a D-47** (03D): D-43 aprobada, D-42 y D-44 diferidas, resto pendiente.

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

> **Precisión (COM-00, 2026-10-07).** La frase «no se crea tabla de perfiles» quedó **superada por D-60**
> (`bylopez_va_asesores`, ASESOR-01). Lo esencial de D-06 sigue vigente: el asesor se identifica por ACL y su identidad
> comercial es `admins.id`. Ver `15_MAPA_DOMINIO_ASESORES.md` §2.

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

**Estado:** **decidida (2026-10-04, Camilo Gaitán): cada asesor ve solo lo suyo.** Implementada
en «Mis ventas / Pedidos» con la semántica existente de `AutorizacionVentasAsesor` (view_own =
`advisor_id` propio; view_all = todo; sin atribución válida y sin view_all = denegado). El
ranking agregado no se implementa todavía.

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

> **COM-00 (2026-10-07):** auditado. **No existe snapshot de costo (gap crítico)**; el atributo `cost` de Bagisto es actual,
> editable y sin historial. Continúa en **COM-D-02** (`16_AUDITORIA_COMISIONES.md` §4).
> **2026-10-08:** COM-D-02 precisada: desde COM-01 se captura `cost` como **referencia** (`autoridad_comision = false`); qué
> integra el costo autoritativo sigue **pendiente**.

---

## D-32 · Porcentaje inicial de comisión

**Estado:** pendiente. **Bloquea la Fase 08.** *(Añadida en Fase 01B.)*

**Propuesta registrada:** 20 % de la utilidad bruta positiva, solo sobre unidades
entregadas y no devueltas. No es política definitiva hasta validar márgenes reales.
Una venta de empresa (`company`) no genera comisión (D-27).

> **COM-00 (2026-10-07):** estado real verificado = **PROPUESTA** (no hay porcentaje en código ni configuración). Continúa en
> **COM-D-03**. **2026-10-08: DECIDIDA** por Camilo (COM-D-03): 20 % configurable con vigencia; regla #1 `COM-GLOBAL-INICIAL`
> creada en desarrollo.

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

## D-53 · Registro seguro de pagos: idempotencia y concurrencia — **DECIDIDA (arquitectura) · REVISADA por D-59 (PAG-A, 2026-10-05)**

**Estado:** ✅ **DECIDIDA** (2026-09-27, Camilo Gaitán: persistencia propia con UNIQUE real, no
solo `order_transactions.data`). Diseño en el Doc. 05 §35. **Sin migración ni código todavía.**

> **Revisión PAG-A (2026-10-05, D-59 / P-01).** El texto original de abajo **se conserva como historial**; lo
> que sigue vigente y lo sustituido es:
>
> - **Sustituido:** «registrar un pago = crear de inmediato la `order_transaction`». **Nueva regla:** registrar
>   ≠ crear Transaction. Registrar crea el ingreso ByLopez + sus aplicaciones (el saldo comercial baja de
>   inmediato) y el pago queda `sin_revisar`; la Transaction nativa nace **solo al confirmar**.
> - **Sustituido:** la tabla mínima `bylopez_va_registros_pago` (**nunca se creó**). Su clave de idempotencia
>   pasa a `bylopez_va_pagos` y su `order_transaction_id` a `bylopez_va_aplicaciones_pago`, que nace NULL y se
>   llena al confirmar (Doc. 05 §42.2).
> - **Sustituido:** «lo pagado = SUM de las transacciones». El saldo comercial pasa a una fórmula derivada
>   que suma las aplicaciones ByLopez activas y las Transactions ajenas a ByLopez, sin doble conteo (Doc. 05 §42.3).
> - **Vigente:** idempotencia por `(mecanismo, clave)` con huella (la huella ahora cubre cliente, monto, medio,
>   referencia, fecha y la distribución normalizada); una sola transacción de base de datos; recalcular bajo
>   bloqueo; composición de primitivas nativas al confirmar (`OrderTransactionRepository::create`,
>   `InvoiceRepository::updateState('paid')` y `OrderRepository::updateOrderStatus($order)` **sin estado**);
>   rechazo de pedidos con Refund; medios de cobro por código configurable; asesores fuera de
>   `sales.transactions` (ahora P-12).
> - **Vigente y ampliado:** el abono general multipedido de §36.4 (FIFO como **propuesta editable**, P-07; nunca
>   más que el dinero recibido, P-06) es **una sola operación atómica**.
> - **Orden de bloqueos actualizado:** antes «registro → pedido → factura»; ahora **pago → pedidos (id asc.) →
>   facturas (id asc.)** (Doc. 05 §42.5).

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

## 03G-B · Nueva venta rápida: decisiones de dominio — **APROBADAS (implementadas, sin commit)**

Camilo aprobó (2026-09-28): facturación de recogida = dirección REAL del cliente, sin dirección se pide una mínima real y no se usa la bodega (D-30); accesos rápidos por config (slug/nombre, sin ids); canales `tienda_fisica` y `whatsapp`, explícitos por venta; lote todo o nada sin parciales; solicitud ≠ carrito (D-39/I-13); tras editar líneas se reaplica el mismo método de entrega solo si sigue disponible (nunca otro; si no, revisión); pago resuelto una vez por operación sin caché; cliente inactivo/suspendido con borrador y confirmación bloqueada por 03F (P-09 intacta). Detalle: Doc. 05 §41.

## D-56 · Separación Pedido / Pago / Despacho — **APROBADA por Camilo Gaitán (arquitectura, 2026-10-05; sin estados propios todavía)**

**Decisión (Camilo Gaitán).** El estado del pedido, el estado del pago y el estado del despacho
son **tres ciclos distintos** y nunca se fusionan en un único estado. «Mis ventas / Pedidos» los
muestra en columnas separadas.

| Ciclo | Autoridad hoy | Cómo se lee hoy | Dominio futuro |
|---|---|---|---|
| **Pedido** | Bagisto `orders.status` | Directo: `pending`, `pending_payment`, `processing`, `completed`, `canceled`, `closed`, `fraud` (etiquetas del admin de Bagisto) | Sigue siendo Bagisto |
| **Pago** | Bagisto `invoices` + `order_transactions` | **Lectura derivada** `Support/Pedidos/EstadoPagoPedido` (Doc. 05 §34.6) | **Cartera** |
| **Despacho** | Bagisto `shipments` / `shipment_items` (reflejado en `order_items.qty_shipped`) | **Señal derivada** en `ConsultaPedidosAsesorService::estadoDespacho()` | **Logística** |

**Reglas.**

1. **Bagisto sigue siendo la autoridad del Order.** No se crea un segundo sistema de estados que
   compita con `orders.status`, pero tampoco se fuerza la operación comercial dentro de él.
   ByLopez podrá tener, en fases posteriores, estados operativos propios para lo que Bagisto no
   representa (diseñados con evidencia).
2. Cuando haya que cambiar el estado oficial de un pedido se hará **solo** con los mecanismos
   públicos de Bagisto (repositorios como `OrderRepository::updateOrderStatus`, `InvoiceRepository`,
   `ShipmentRepository`, `RefundRepository`, y sus eventos). **Nunca** modificando
   `packages/Webkul` ni con `UPDATE` directo sobre `orders.status`. No se implementa todavía.
3. **`EstadoPagoPedido` es una lectura derivada, no Cartera.** Sirve para el listado, el detalle,
   los KPIs y la información operativa. No registra, valida, aplica ni concilia pagos. Cartera
   (diseño previo: D-50, D-51, D-53, Doc. 05 §35–§36) deberá representar cuánto se debía, cuánto
   se pagó, qué pago fue validado, a qué pedido se aplicó y cuánto saldo queda; **solo después**
   se decide qué efecto tiene eso sobre el pedido.
4. **Prohibida la regla global «pedido no pagado = no se puede despachar».** La contra entrega
   (D-50) está pendiente de pago y es válido despacharla; la transferencia (D-48) pasa por
   «pendiente de validación → pago confirmado → saldo». Cada medio necesitará su propio flujo,
   que se diseñará en Cartera/Logística.
5. **Despacho hoy = solo la señal básica de Shipment.** No existen todavía «alistando»,
   «empacado», «guía generada», «entregado», «novedad» ni «devolución» (D-07, D-14, D-42, D-44;
   Fase 06). No se inventan.
6. Sin columnas, tablas ni estados nuevos en esta decisión.

**Derivación actual del despacho (auditada en Bagisto 2.4.9).** `qty_shipped` de cada línea
solo lo escribe `ShipmentRepository::create()` (suma la cantidad enviada) y
`OrderItemRepository::collectTotals()` (la recalcula como Σ `shipment_items.qty`), así que
refleja fielmente los Shipments registrados. Sobre las líneas padre del pedido:

| Señal | Condición |
|---|---|
| No aplica | Pedido `canceled`, `closed` o `fraud`, o sin unidades objetivo |
| **Sin despachar** | Pedido vigente con unidades objetivo (`qty_ordered − qty_canceled > 0`) y **ninguna unidad enviada** (Σ `qty_shipped` = 0) ⇔ ningún Shipment con unidades para ese pedido |
| Despacho parcial | 0 < enviadas < objetivo |
| Despachado | enviadas ≥ objetivo. Significa **«salió de bodega» (Shipment registrado), no «entregado»** |

Limitaciones conocidas: un producto no inventariable (virtual/descargable) nunca contaría como
enviado (el catálogo de ByLopez es físico); un Shipment no prueba entrega.

**KPI «Por despachar» (corregido y aprobado 2026-10-05).** Es un KPI **operacional basado en
unidades físicas pendientes de despacho, NO en `orders.status`**. Responde: «¿todavía existen
unidades físicas pendientes de despachar?». Cuenta los pedidos visibles (mismo alcance que el
listado: view_own = solo los propios; view_all = todos) cuya señal de despacho es **Sin despachar**
o **Despacho parcial**; excluye **Despachado** y **No aplica**. Usa la misma fuente que la columna
Despacho (`ConsultaPedidosAsesorService::agregadoDespacho()`: por pedido, líneas padre, objetivo =
Σ(`qty_ordered − qty_canceled`), enviadas = Σ `qty_shipped`): pedido no `canceled`/`closed`/`fraud`,
objetivo > 0 y enviadas < objetivo. Un pedido parcialmente despachado **sigue siendo trabajo
pendiente**.

Regla anterior (corregida): excluía además `orders.status = completed` y miraba línea a línea.
Hallazgo que motivó el cambio: en Bagisto 2.4.9 `TransactionController` marca el pedido `completed`
cuando el pago cubre la factura y existe **cualquier** Shipment, aunque sea parcial; ese pedido
desaparecía del KPI teniendo unidades por enviar. Prueba: `Fase05aMisVentasTest` («KPI por
despachar (D-56)…», casos 1–7 más `completed` con 5 de 10 unidades enviadas → cuenta).

**Caso operativo futuro: «Pagado + Sin despachar».** Venta que avanzó comercialmente pero
todavía no salió físicamente (riesgo: cliente esperando un pedido que nadie alistó). Con los
datos actuales **se puede detectar sin semántica nueva**: es el cruce de `EstadoPagoPedido` =
`pagado` y despacho = `sin_despachar`, ambos ya calculados por fila en el listado. En Bagisto
2.4.9 un pedido sin ningún Shipment no puede quedar `completed` (`isInCompletedState` exige lo
enviado y `TransactionController` deja `processing` si no hay Shipment), así que el caso aparece
como `processing` o `pending_payment`; con despacho parcial puede aparecer incluso como
`completed`. KPI/alerta «Pagados pendientes de despacho» (Pago = Pagado + Despacho = Sin despachar
/ Parcial) **no implementado**: Camilo lo decidirá al avanzar formalmente con Operación Asesor /
Cartera / Logística, incluido si «Revisar» (factura pagada sin transacción, D-48) y «Parcial»
(de pago) deben entrar.

**Deuda técnica explícita.** La regla de pago está duplicada con la misma semántica en
`ConfirmarVentaService::estadoPago()` (03F). No se unifica ahora para no reabrir 03F; al
reabrirla: test unitario de los 5 estados de `EstadoPagoPedido` y luego delegar en él.

## D-57 · Nueva venta: Entrega → Dirección → Pago sin doble decisión — **IMPLEMENTADA (2026-10-05, sin commit; pendiente de revisión manual de Camilo)**

**Contexto.** Auditoría de 03G-D (Doc. 05 §41.10): «Recogida en bodega» (opción de UI) y
«Recogida en tienda» (ShippingRate real del carrier `pickup` de `bagisto-eu/pickup`) eran la
**misma decisión** preguntada dos veces, y con destino bodega Bagisto también ofrecía
`flatrate` («Envío por pagar») y `free` («Envío gratuito»): estado híbrido posible.

**Decisiones aplicadas.**

1. **Recogida ⇔ bodega (bicondicional).** `PuntosRecogida::metodoPermitido()`: `pickup` solo con
   destino bodega real y, con destino bodega, solo `pickup`. Aplica a los rates ofrecidos, a la
   selección (422 `metodo_entrega_no_disponible`) y a la revalidación de 03F. Bagisto sigue
   recibiendo el `shipping_method` real (`pickup`).
2. **Sin doble decisión en la UI.** Una sola opción «Recogida en tienda» (título/descripción del
   carrier en `core_config`); al elegirla se aplica `pickup` y se muestra como información. No se
   pide dirección de envío del cliente; la facturación sigue D-30 (dirección real; nunca la bodega).
3. **Domicilio:** Dirección (CustomerAddress) → Método de envío (rates reales de Bagisto para ESA
   dirección) → Pago. El método va después de la dirección porque Bagisto calcula los rates para un
   destino.
4. **Predeterminada:** `default_address` de Bagisto. Preselección solo visual: predeterminada; si no
   hay y existe una sola dirección, esa; con varias sin predeterminada, elige el asesor.
5. **Agregar dirección en MODAL** (desde 2026-10-05; antes inline): guardada = RT-04 (`GuardarDireccionClienteComercialService`, cliente
   del borrador, propietario exigido, email opcional, primera = predeterminada); o puntual solo para
   esta venta (CartAddress). CustomerAddress → CartAddress → OrderAddress sin modificar silenciosamente
   la dirección del cliente.
6. **Pago** solo visible y Confirmar solo habilitado cuando la entrega aplicada al carrito coincide
   con la elegida y está completa.

**Resueltos el 2026-10-05 (aprobados por Camilo).**

- **Código postal en direcciones guardadas → IMPLEMENTADO (D-58; catálogo DIVIPOLA y autocomplete también implementados, ver D-58).** RT-04 deja de exigirlo fijo:
  `GuardarDireccionClienteComercialService` usa `core()->isPostCodeRequired()`
  (`customer.address.requirements.postcode`, la misma configuración del checkout de Bagisto). En
  `lpz_saas` vale **0** → opcional. La dirección puntual del borrador sigue opcional (D-46).
- **PDF de factura en desarrollo → APLICADO por configuración (sin código).** Fila `core_config`
  id 8, `emails.general.notifications.emails.general.notifications.new_invoice`, **global
  (`channel_code` NULL; el informe anterior decía canal `default`: corregido)**. Antes **1**, ahora
  **0** (2026-10-05 ~14:38). Deja de ejecutarse solo el correo de factura AL CLIENTE
  (`Webkul\Shop\Listeners\Invoice::afterCreated`, que construye `InvoicedNotification` con el PDF).
  **Sin tocar:** `sales.payment_methods.moneytransfer.generate_invoice` = 1,
  `moneytransfer.invoice_status` = paid, `cashondelivery.generate_invoice` = 1,
  `new_invoice_mail_to_admin` = 0. La Invoice se sigue creando (verificado: pedido 41 → factura
  `FAC-BYL-00000025-2025`, `paid`, `email_sent = 0`). Causas evitadas: logo por HTTP en `serve` y
  memoria de `ar-php` (128M). **Restaurar:** Configuración → Correos → Notificaciones → «Nueva
  factura» = Sí, o `UPDATE core_config SET value = '1' WHERE id = 8` + `php artisan
  optimize:clear`. Producción (no ahora): cola `database` + `after_commit = true` + worker y revisar
  `memory_limit`.

**Pendiente (configuración que debe corregir Camilo).**

- **Datos de la bodega en `lpz_saas`:** `inventory_sources` BODEGA1 tiene `state = MI` y
  `postcode = 48127` (parecen datos de ejemplo; MI no es un departamento de Colombia). Se copian tal
  cual al OrderAddress de las recogidas (confirmado en el pedido 41). Corregir desde Bagisto
  (Inventario → Fuentes) con la dirección real; no se corrigió inventando valores.
- **Pendiente de aclaración con Camilo: «NIF 1000».** Apareció en una instrucción dictada; no se
  implementó nada relacionado.

## D-58 · Geografía de Colombia (DIVIPOLA) y código postal — **APROBADO · IMPLEMENTADO (2026-10-05, sin commit; pendiente de prueba manual en celular)**

Camilo aprobó el diseño completo el 2026-10-05: catálogo local DANE/DIVIPOLA, equivalencia
estructurada municipio → departamento → `country_states`, autocomplete único con el formato
«Departamento — Municipio», Bogotá, D.C. como entidad propia (nunca Cundinamarca), y código postal
sin inventar ni inferir por municipio.

**Estado por parte.**

| Parte | Estado |
|---|---|
| Auditoría de Bagisto (state / city / postcode) y de la fuente DANE | AUDITADO |
| Catálogo local: 2 tablas, 2 migraciones, 2 modelos | **IMPLEMENTADO** (aplicado en `lpz_saas` y `lpz_saas_testing`) |
| Equivalencia DIVIPOLA → `country_states` y estado `BOG` (Bogotá, D.C.) | **IMPLEMENTADO** |
| Importador `ventas-asesor:geo-importar-divipola` (dry-run por defecto, idempotente, falla cerrado) | **IMPLEMENTADO** (catálogo real importado en `lpz_saas`) |
| Endpoint `GET admin/ventas-asesor/ubicaciones?q=` | **IMPLEMENTADO** |
| Autocomplete único en la modal «Agregar dirección» | **IMPLEMENTADO** (probado en backend y en HTML; la prueba con el celular real la hace Camilo) |
| Selección estructurada: el backend resuelve ciudad / estado / país por código | **IMPLEMENTADO** |
| Código postal según `core()->isPostCodeRequired()` | **IMPLEMENTADO** |
| Resolución automática del código postal | NO IMPLEMENTADA (no recomendada: ver más abajo) |
| Migración de direcciones históricas (backfill) | NO (decidido: las históricas se muestran con sus valores) |

**Auditoría de Bagisto 2.4.9.**

- `addresses.country` = ISO-2 (`CO`); `addresses.state` = **código** de `country_states` (`CUN`,
  `BOY`, …); `addresses.city` = texto libre (Bagisto no tiene catálogo de ciudades);
  `addresses.postcode` = texto validado con la regla `PostCode`.
- `core()->isPostCodeRequired()` = `(bool) getConfigData('customer.address.requirements.postcode')`,
  por canal, valor por defecto **1** (`Admin/Config/system.php`). Lo usan `Shop\…\AddressRequest` y
  `CartAddressRequest`; el formulario de direcciones del **admin** lo exige siempre. En `lpz_saas`:
  postcode = **0**, state = 1, country = 1.
- `country_states` CO traía **32 departamentos** con códigos de 3 letras (AMA … VID) y **no incluía
  Bogotá, D.C.** (DIVIPOLA 11, entidad aparte). `country_states.code` no tiene índice único.
- Datos reales de `lpz_saas` mezclados: `state` = `CUNDINAMARCA` (71), `CUN` (32), `Bogotá` (3),
  `MI` (2)…; postcode `1111` (65), `11111` (13), `111` (6)… (códigos forzados, no reales).

**Fuente oficial y esquema real (verificado al descargar, 2026-10-05).** DANE — DIVIPOLA, dataset
«DIVIPOLA- Códigos municipios» en datos.gov.co (`gdxc-w37w`; «Actualización a corte 30 diciembre
2024»; licencia CC BY-SA 4.0; descarga CSV
`https://www.datos.gov.co/api/views/gdxc-w37w/rows.csv?accessType=DOWNLOAD`). CSV UTF-8 sin BOM,
**1.122 filas**, 7 columnas: `Código Departamento` (2), `Nombre Departamento`, `Código Municipio`
(5), `Nombre Municipio`, `Tipo: Municipio / Isla / Área no municipalizada`, `longitud`, `Latitud`
(coordenadas con coma decimal). **33 departamentos**; tipos: Municipio 1.103 · Isla 1 · Área no
municipalizada 18. **Los nombres vienen en MAYÚSCULAS** («BOYACÁ», «PESCA»). El archivo importado
queda en `storage/app/geo/divipola.csv` (no versionado); SHA-256
`159b4b84595a11be5bf623fdf25c6fd5c9ac8c3ed898cf7928b2b3f321b9b492`.

**Tablas (migración `2026_10_05_000009_create_bylopez_geo_tables`).**

- `bylopez_geo_departamentos`: `codigo_divipola` CHAR(2) PK · `nombre_oficial` · `nombre_busqueda` ·
  `country_state_id` (FK RESTRICT a `country_states`, **UNIQUE**) · timestamps. 33 filas.
- `bylopez_geo_municipios`: `codigo_divipola` CHAR(5) PK · `nombre_oficial` · `nombre_busqueda`
  (índice) · `departamento_codigo` (FK RESTRICT) · `tipo` · `activo` · `fuente` · `version_fuente` ·
  `checksum_fuente` (SHA-256 del archivo importado) · timestamps. 1.122 filas, todas activas.
- Los códigos son **cadenas** (CHAR): se preserva el cero inicial (`05001`). Sin coordenadas: la
  fuente las trae pero hoy nada las usa (se pueden agregar después desde el mismo archivo).
- Modelos `GeoDepartamento` y `GeoMunicipio`. Nombres: `nombre_oficial` se guarda en formato de
  presentación («Boyacá», «Pesca», «Bogotá, D.C.», «Valle del Cauca») con una conversión
  determinista desde el MAYÚSCULAS de DANE (`Support\Geo\NombresGeograficos`); `nombre_busqueda` es
  minúsculas sin tildes ni signos («boyaca», «bogota d c»).

**Equivalencia DIVIPOLA → Bagisto (única, resuelta al importar).** `Support\Geo\EquivalenciaDepartamentos`
(tabla explícita de 33 pares, p. ej. 05→ANT, 15→BOY, 25→CUN, 68→SAN, 11→BOG) validada por el
importador contra `country_states`; queda en `bylopez_geo_departamentos.country_state_id`. En la
venta **nunca** se comparan nombres. Una advertencia informativa: el departamento 88 («Archipiélago
de San Andrés, Providencia y Santa Catalina») ↔ Bagisto `SAP` («San Andrés y Providencia»): la
equivalencia es por código y los nombres difieren.

**Bogotá, D.C. — resuelto.** DIVIPOLA 11 es entidad propia y **no** se mapea a Cundinamarca.
Migración de **datos** `2026_10_05_000010_agregar_estado_bogota_dc_a_country_states`: inserta en
`country_states` el estado `country_code = CO`, **`code = BOG`**, `default_name = Bogotá, D.C.`
(id 619 en `lpz_saas`). Convención verificada: 3 letras mayúsculas; sin ninguna colisión de `BOG`
en ningún país. Idempotente (no inserta si ya existe), no cambia ni borra ningún estado existente
(`BOY`, `CUN`, … y sus ids intactos) y no toca Webkul. `down()` solo lo elimina si nadie lo usa
(catálogo ni direcciones). Municipio `11001` → departamento «Bogotá, D.C.» → `BOG`.

**Importador.** `php artisan ventas-asesor:geo-importar-divipola --archivo=RUTA.csv [--persistir]
[--corte=2024-12-30]` (`--dry-run` y `--persistir` son excluyentes; sin `--archivo` el comando
indica la URL de descarga). Dry-run por defecto: calcula el mismo informe sin escribir. **Falla
cerrado** (no escribe nada, ni con `--persistir`) si: el encabezado cambió (la fuente cambió de
esquema), un código no tiene 2 / 5 dígitos o el municipio no pertenece a su departamento, hay
duplicados o nombres vacíos, un departamento DIVIPOLA no tiene equivalencia, o su estado de Bagisto
no existe / está duplicado. Idempotente (upsert por código; una 2.ª corrida: 0 altas, 0 cambios,
**ni un INSERT/UPDATE**); **no borra**: un municipio ausente de la fuente se **inactiva** y uno que
reaparece se reactiva; una sola transacción. Registra `fuente`, `version_fuente` (`--corte`) y
`checksum_fuente`. Resultado en `lpz_saas`: dry-run 33 + 1.122 altas sin escribir; persistir 33 +
1.122; segunda importación 0 / 0 / 0 y 1.122 «sin cambios».

**Endpoint de autocomplete.** `GET admin/ventas-asesor/ubicaciones?q=…`
(`admin.ventas_asesor.ubicaciones.buscar`, `UbicacionesController` + `BuscarUbicacionRequest` +
`Services\Geo\CatalogoUbicaciones`). ACL: la clave existente `ventas_asesor.pedidos.create` (la misma
de todas las rutas de Nueva venta; Bouncer real: sin sesión → login, sin la clave → 401). Mínimo 2
caracteres, **máximo 15** resultados, solo municipios **activos**; respuesta reducida
`[{municipio_codigo, municipio, departamento_codigo, departamento, etiqueta}]` con
**etiqueta = «Departamento — Municipio»**. Búsqueda en minúsculas y sin tildes: cada palabra
escrita debe coincidir con el **inicio de una palabra** del municipio o de su departamento
(`Boy Pe` → Boyacá — Pesca); primero las coincidencias por municipio y luego las que solo lo son por
departamento, luego alfabético. ~13 ms con el catálogo completo. Resultados reales en `lpz_saas`:
«Pes» → **Boyacá — Pesca** · «Tun» → Boyacá — Tunja, Boyacá — Tununguá, Cauca — Piendamó - Tunía ·
«Buc» → Santander — Bucaramanga, Norte de Santander — Bucarasica · «Chí» → **Cundinamarca — Chía**,
Antioquia — Chigorodó, … · «Bog» → **Bogotá, D.C. — Bogotá, D.C.** (jamás «Cundinamarca — Bogotá»)
· «Boy» → los municipios de Boyacá (el tope de 15 corta la lista alfabética: «Boyacá — Boyacá»,
«Boyacá — Puerto Boyacá», «Boyacá — Almeida», …; escribir «Boy Tun» afina).

**Selección estructurada (la UI no es autoridad).**

- La modal tiene **un solo input** «Ciudad / municipio» (debounce de 300 ms, sin librerías; la lista
  va en flujo, con scroll propio, para que el teclado del celular no la tape; flechas y Enter
  también funcionan). Tras elegir, muestra «Departamento: Boyacá» solo como información. **Se
  eliminó el select de departamento.** Escribir invalida la selección anterior.
- Guardar exige haber elegido de la lista («Selecciona una ciudad o municipio de la lista.»). La
  petición envía **`municipio_codigo`** (5 dígitos) y no ciudad ni departamento.
- Backend: `AgregarDireccionNuevaVentaRequest` y `EntregaNuevaVentaRequest` exigen `municipio_codigo`
  para direcciones de CO (422). `ValidadorDireccionComercial` (compartido por RT-04 y 03D) resuelve el
  código con `CatalogoUbicaciones::resolver()` y **ignora** `ciudad`, `departamento` y `pais`
  enviados: `country = CO`, `state = country_states.code`, `city = nombre_oficial`. Código
  inexistente, inactivo o mal formado → `datos_cliente_invalidos` (`errores.municipio_codigo`). Si
  llega el código de Pesca con ciudad «Medellín» y estado «ANT», se persiste Pesca / BOY.
- `DireccionComercial` gana el campo opcional `municipioCodigo` (+ `comoPredeterminada()`); sin él
  (llamadores programáticos y direcciones históricas) se usan los textos recibidos, como antes.

**CustomerAddress → CartAddress → OrderAddress** (sin cambios de arquitectura): guardada = RT-04
(`GuardarDireccionClienteComercialService`, ahora con la ubicación estructurada) y luego se copia al
CartAddress; sin guardar = solo CartAddress; Bagisto copia al OrderAddress al confirmar (probado:
`city = Pesca`, `state = BOY`, `country = CO`). Una CustomerAddress existente no se modifica al
usarla.

**Código postal (auditado; implementado solo lo seguro).** 6 dígitos (2 = departamento DANE, 2 =
zona postal, 2 = distrito postal), administrado por 4-72, ~3.681 códigos; **un municipio puede tener
varios** (p. ej. Bogotá por localidades; municipios pequeños a veces urbano + rural). Depende de la
**ubicación exacta**, no del municipio: una tabla `municipio → código` sería incorrecta y no se creó.
Automatizarlo exigiría los polígonos oficiales de zonas postales (código, municipio, vigencia,
geometría) + geocodificar la dirección y asignar por punto-en-polígono, o un servicio oficial de
4-72 por dirección; lo que exigirá cada transportadora está por verificar. **Implementado:**
`GuardarDireccionClienteComercialService` exige el código postal solo si
`core()->isPostCodeRequired()` lo exige (en `lpz_saas`: 0 → opcional); la dirección puntual del
borrador sigue opcional (D-46); nunca se inventa ni se infiere.

**Límites y pendientes.**

- Prueba en un navegador real / celular (teclado, scroll de la modal, lista): pendiente de Camilo.
- Direcciones históricas (`state` = `CUNDINAMARCA` / `Bogotá` / `MI`, ciudades en mayúsculas): sin
  backfill; se muestran con sus valores. Las nuevas usan el catálogo.
- La exigencia de `municipio_codigo` está en la frontera HTTP de Nueva venta; los llamadores de
  servicios (n8n, API futura) deberán pasar `municipioCodigo` para usar el catálogo.
- El catálogo se actualiza reimportando un CSV nuevo del DANE (dry-run primero).
- `Boy` lista 15 municipios alfabéticos; si Camilo prefiere ver primero las capitales o subir el
  tope, es un ajuste de ordenamiento sin cambios de modelo.
- `lpz_saas_testing` tiene las migraciones 000009 y 000010 aplicadas; los tests cargan su propio
  fixture con el importador real y no dependen del catálogo completo.
- BODEGA1 (`state = MI`, `postcode = 48127`) sigue con datos de ejemplo; **no** se tocó (D-57).

## D-59 · Pagos / Cartera: arquitectura PAG-A — **APROBADA (arquitectura; sin implementación ni migraciones)**

> **Riesgo de Sales nativo CERRADO (2026-10-07, decisión de Camilo):** el rol `#2 ASESOR VENTAS` ya no tiene ninguna clave `sales.*`
> (se retiraron las 12 restantes); conserva solo las 9 claves del módulo y `catalog`, `catalog.products`. El asesor trabaja
> exclusivamente desde Ventas Asesor; Ventas Asesor no depende de ACL nativas de Sales (verificado en código). 0 usuarios; Administrador
> `all` sin cambios. Detalle en Doc. 05 §42.24.
>
> **PAG-F CERRADA EN DESARROLLO (2026-10-07, sin commit) — decisiones de Camilo:** (1) **corte definitivo inicial `2026-10-06`**
> (opción A): pedido 45 dentro con sus pagos reales; pedido 46 dentro como **excepción de transición** (`requiere_revision`, factura
> `paid` sin evidencia; no se toca); 41/42/44 históricos; sin excepciones por id. (2) **Rol operativo inicial `#2 ASESOR VENTAS`**: se le
> agregaron `ventas_asesor`, `operar_como_asesor`, `pedidos`, `pedidos.create`, `pedidos.view_own`, `cartera`, `cartera.view_own`,
> `cartera.registrar` y `cartera.abono_general`; tras auditar rutas reales se le **retiraron** `sales.transactions` y
> `sales.transactions.view` (crean Transactions y pagan facturas: P-12), `sales.invoices.create` (factura manual nace `paid` y puede crear
> Transaction) y `sales.refunds.create` (Refund ≠ reverso); 0 usuarios asignados. (3) **Supervisión** por el rol Administrador `all`, sin
> rol Supervisor. (4) Nadie tiene `cartera.reversar` (PAG-I). (5) **Producción pendiente** de autorización tras la prueba manual de PAG-E.
> El riesgo de los permisos nativos de solo lectura (`sales.orders.view`, etc.) quedó **cerrado** el mismo día (ver nota anterior y
> Doc. 05 §42.24). Detalle en Doc. 05 §42.23.
>
> **PAG-F parcial (2026-10-07, sin commit).** Configuración objetivo de `moneytransfer` (§42.9) **aplicada en desarrollo** tras auditar
> Bagisto (sin contradicción con PAG-E: es la misma que ya usaba contra entrega). **Decisiones abiertas para Camilo:** (1) **fecha de corte
> definitiva**: opción A (recomendada) mantener `2026-10-06` (incluye el pedido 45 con sus pagos reales; el 46, transferencia creada con la
> configuración antigua, queda «a revisar»); opción B `2026-10-07 08:20:34` o posterior (cumple §42.8 literal, pero saca de Cartera al
> 45 y a sus pagos); (2) **rol de asesor**: «ASESOR VENTAS» no tiene usuarios ni claves del módulo y conserva `sales.transactions`
> (P-12); propuesta exacta de permisos en Doc. 05 §42.22; supervisión inicial con el rol «all»; (3) qué hacer con el pedido 46.
> Ningún corte ni rol se cambió. Detalle en Doc. 05 §42.22.
>
> **PAG-E implementada (2026-10-07, sin commit).** Tras auditar el código real de Bagisto 2.4.9: `order_transactions.invoice_id` es NOT NULL
> (sin factura utilizable **no se confirma** y no se crean facturas) y una Transaction pertenece a un solo pedido, así que un pago
> multipedido genera **una Transaction por aplicación**, enlazada en `aplicaciones.order_transaction_id`, sobre la factura abierta de menor
> id con capacidad para TODA la aplicación (nunca se parte). **Refinamiento de D-50/§42.7 (decisión a confirmar por Camilo):**
> `payment_method` lleva el método de pago DEL PEDIDO (el accesor `payment_title` de Bagisto revienta con códigos ajenos como
> `transferencia`) y el medio de cobro real va en `type` y `data`. `ConfirmarPagoService`: `sin_revisar → confirmado`, idempotente, locks
> pago→aplicaciones→pedidos→facturas, **no vuelve a bajar la deuda** (solo sin revisar → verificado); si la factura queda cubierta pasa a
> `paid` y el pedido recalcula su estado por cantidades. `RechazarPagoService`: solo `sin_revisar → rechazado`, motivo (categoría simple + detalle)
> obligatorio, anulación lógica de aplicaciones, la deuda vuelve por derivación; un confirmado no se rechaza (reverso = PAG-I). ACL:
> `cartera.revisar` + alcance (`view_own` exige que TODOS los pedidos del pago sean suyos). Entrada de menú «Cartera / Pagos». Detalle en
> Doc. 05 §42.21. Pendiente PAG-F: `moneytransfer`, fecha de corte definitiva y roles.
>
> **PAG-D implementada (2026-10-06, sin commit); PAG-C validada manualmente por Camilo** (dos abonos reales sobre
> `#BYL-00000045-CO`: transferencia + efectivo; saldo y «sin revisar» correctos). `RegistrarAbonoClienteService` (extiende, sin
> modificar, `RegistrarPagoService`): un Pago `sin_revisar` con N aplicaciones, distribución explícita (la propuesta FIFO es editable
> y solo una ayuda), suma exacta = monto, cada línea ≤ saldo real recalculado bajo bloqueo de los pedidos en orden de id ascendente,
> idempotencia por huella (cliente, medio, fecha, referencia, monto y distribución), rollback total, sin Transactions ni saldo a favor.
> ACL: `cartera.abono_general` + alcance `view_own|view_all` aplicado a **cada pedido** (decisión: poder ver al cliente no basta). La
> agrupadora `ventas_asesor.cartera` apunta ahora a `cartera.index` (`?cliente=ID`). Accesos contextuales (detalle del pedido y hook
> `bagisto.admin.customers.customers.view.card.orders.before`), sin menú global ni tocar Webkul. Detalle en Doc. 05 §42.20.
>
> **PAG-C implementada (2026-10-06, sin commit).** `RegistrarPagoService`: autoriza con `cartera.registrar` + alcance
> `view_own|view_all`; falla cerrado sin fecha de corte; idempotente (`mecanismo` + `idempotency_key` + huella: misma
> solicitud devuelve el pago existente, otra solicitud con la misma clave → 409); bloquea pedido (`FOR UPDATE`) tras reservar
> el pago y **recalcula el saldo real** con `CarteraConsultaService`; crea Pago `sin_revisar` + `AplicacionPago` + evento
> `registrado` en una sola transacción; el saldo baja de inmediato; **no** crea Transactions ni toca Invoice/Order; no
> sobreaplica ni crea saldo a favor. Estado de pago de Mis ventas: para pedidos en Cartera la fuente es Cartera (sin revisar
> ≠ verificado); fuera de Cartera rige D-56. **Bloqueo por diseño para pagos reales:** fecha de corte inactiva y tablas
> sin migrar en desarrollo (decisión y autorización de Camilo; no se inventó fecha). Ningún rol recibió `cartera.*`.
> Decisión abierta: «Nueva venta» es hoy de tercer nivel en el menú (no existe clave ACL hermana); pasarla a segundo nivel
> exige una clave ACL nueva. **Corrección 03G (2026-10-06):** «Ver pedido» daba 404 por una URL inexistente
> (`sales/orders/{id}`) y ahora abre el detalle del módulo; validación manual de Camilo con el pedido `#BYL-00000045-CO`.
> Detalle en Doc. 05 §42.17–§42.18.
>
> **PAG-B implementada (2026-10-06, sin commit).** Persistencia (3 tablas aditivas, solo en `lpz_saas_testing`), modelos
> `Pago`/`AplicacionPago`/`PagoEvento`, enums `EstadoPago`/`TipoEventoPago`, fuente única `Support\Cartera\MediosCobro`
> (`transferencia` con referencia requerida, `efectivo`, `contraentrega_recaudo`), ACL `ventas_asesor.cartera.*` (sin asignar a
> roles; P-12 sigue como despliegue), `FechaCorteCartera` **inactiva** (valor y almacenamiento pendientes de aprobación) y
> `CarteraConsultaService` de solo lectura con la fórmula de §42.3 (sin doble conteo vía `order_transaction_id`). Sin
> registrar/confirmar/rechazar, sin evidencias. Diferencias con §42.2 y detalle en Doc. 05 §42.16.

**Estado:** ✅ **APROBADA por Camilo Gaitán (2026-10-05).** Solo documentación: **sin** migraciones, modelos,
servicios, controladores, vistas, API, tests ni cambios de configuración. Diseño técnico completo en el
**Doc. 05 §42**. **Revisa y sustituye en parte D-53** (ver su nota de revisión). La auditoría de
Pagos/Cartera ya estaba hecha y no se repite.

**Base aceptada.** `Order` no representa el dinero cobrado. `Invoice paid` representa facturación y **no**
demuestra ingreso. `Transaction` es lo más cercano al dinero **verificado** nativo. Bagisto no ofrece pago
reportado sin revisar, aplicación multipedido, revisión, rechazo, reversión auditable, actor, evidencia,
saldo comercial, cartera de cliente ni idempotencia suficiente → **dominio propio ByLopez** que
complementa a Bagisto. Se mantiene D-56: **Pedido ≠ Pago ≠ Despacho**.

### Decisiones aprobadas (P-01 … P-12)

| ID | Decisión | Estado |
|---|---|---|
| **P-01** | **Registrar un pago ≠ crear una `order_transaction`.** Registrar = ingreso comercial ByLopez + aplicación a pedidos; el saldo comercial baja de inmediato; el pago nace `sin_revisar`. La Transaction nativa se crea **solo al confirmar** (evita una Transaction irreversible sobre dinero que resulte falso). Modifica D-53 | ✅ Aprobada |
| **P-02** | Modelo mínimo: `bylopez_va_pagos`, `bylopez_va_aplicaciones_pago`, `bylopez_va_pagos_eventos`; evidencias después (`bylopez_va_pago_evidencias`). **Sin migraciones en PAG-A** | ✅ Aprobada |
| **P-03** | **No** se guarda `customer.deuda` ni columnas equivalentes. Saldo del pedido y cartera del cliente son **derivados** y deben poder explicar de dónde sale cada peso | ✅ Aprobada |
| **P-04** | Estados `sin_revisar`, `confirmado`, `rechazado`, `reversado`. Transiciones: `sin_revisar → confirmado`, `sin_revisar → rechazado`, `confirmado → reversado`. Registrar + aplicar es atómico; no hay estado «registrado» | ✅ Aprobada (semántica estricta) |
| **P-05** | **Rechazar ≠ reversar.** Rechazar: solo desde `sin_revisar`, anula exactamente sus aplicaciones, **no** crea Transaction. Reversar: pago ya confirmado, operación excepcional y de mayor privilegio. **La Transaction negativa compensatoria propuesta por la auditoría NO se implementa ni se aprueba** hasta una microauditoría al inicio de PAG-I | ✅ Aprobada; mecánica de reversión **abierta** |
| **P-06** | **Sin sobreaplicación** (MVP): lo aplicado = lo registrado; si no hay deuda aplicable suficiente se rechaza con motivo claro. Sin wallet ni saldo a favor | ✅ Aprobada |
| **P-07** | **FIFO es propuesta, no regla.** El usuario autorizado puede cambiar la distribución; el backend revalida todo | ✅ Aprobada |
| **P-08** | ACL de cartera con claves equivalentes a `view_own`, `view_all`, `registrar`, `abono_general`, `revisar`, `reversar`; el **servicio siempre valida** permisos. Los nombres propuestos siguen la convención de `acl.php` y se conservaron tal cual | ✅ Aprobada; nombres propuestos en Doc. 05 §42.6 |
| **P-09** | **Sin ficha paralela de Customer.** Customer de Bagisto sigue siendo la autoridad. «Cartera del cliente» propia con ACL propia + tarjeta/enlace mediante hook limpio en la ficha nativa. Sin override de Webkul | ✅ Aprobada |
| **P-10** | Pedidos con Refund: no se les aplican pagos nuevos sin revisión; resultado `revisar`. Refund de mercancía ≠ reversión de un pago | ✅ Aprobada |
| **P-11** | No existe «saldo pendiente ⇒ prohibido despachar». Contraentrega: pendiente de pago → preparación → despacho → cobro → registro → revisión | ✅ Aprobada |
| **P-12** | Los asesores **no** usan el flujo nativo `sales.transactions` para registrar pagos; el dominio de pagos pasa por ByLopez. **No se cambian roles en PAG-A**; la revisión de permisos va en el checklist de despliegue | ✅ Aprobada como **política** (sin ejecutar) |

### Decisiones complementarias de PAG-A (C-1 … C-4)

| ID | Decisión | Estado |
|---|---|---|
| **C-1** | **Referencia por medio de cobro**, no universal y no dispersa en controladores. Transferencia: referencia **requerida** (preferida). Si aparecen casos legítimos sin referencia, se reporta antes de imponerla | ✅ Aprobada; política configurable (Doc. 05 §42.7) |
| **C-2** | **Medio de cobro ≠ método de pago del pedido.** Código `medio_cobro` desde **una fuente configurable y centralizada** (propuesta `ventasasesor.pagos.medios_cobro`), sin lista rígida repartida. Conceptualmente: `transferencia`, `efectivo`, `contraentrega_recaudo`. Sin integraciones con Nequi, Daviplata, bancos ni pasarelas | ✅ Aprobada; lista final por confirmar |
| **C-3** | **Histórico:** no crear pagos heredados, no reconciliar automáticamente, no convertir `Invoice paid` en pago. Cartera con **fecha de corte**. Mecanismo (almacenamiento en `core_config`, falla cerrado sin fecha, histórico sin saldo, incorporación puntual auditada) **propuesto** en Doc. 05 §42.8 | ✅ Principio aprobado · 🟡 mecanismo **propuesto, pendiente de aprobación** |
| **C-4** | **Configuración objetivo de `moneytransfer`:** `generate_invoice = 1`, `invoice_status = pending`, `order_status = pending_payment` (hoy: `paid` y `processing`). Diseñada, **no aplicada**; el cambio real va en PAG-F, con autorización y antes de fijar la fecha de corte | ✅ Diseño aprobado · ⏳ aplicación pendiente |

### Reglas centrales (resumen; detalle y fórmulas en Doc. 05 §42)

- **Saldo del pedido** = `total − aplicaciones ByLopez activas − Transactions nativas ajenas a ByLopez`. Sin doble
  conteo: la Transaction que nace al confirmar queda **vinculada** a su aplicación y se excluye del término
  «ajenas». **Confirmar no cambia el saldo; rechazar lo devuelve.**
- **Cartera del cliente** = suma de saldos de sus pedidos en alcance (no `canceled`/`closed`/`fraud`, sin Refund,
  posteriores a la fecha de corte), con detalle Total · Aplicado · Verificado · Sin revisar · Saldo.
- **Registrar ≠ confirmar:** todo pago nace `sin_revisar`; confirmar es una **segunda acción explícita** y puede
  hacerla otro usuario (supervisora, administrador u otro rol con permiso; nada de nombres o personas
  hardcodeados). Si una misma persona tiene ambos permisos puede hacer las dos, pero registrar jamás confirma.
- **Concurrencia e idempotencia** (diseñadas, no implementadas): orden de bloqueos `pago → pedidos (id asc.) →
  facturas (id asc.)`; el doble clic o el reintento no duplican; dos usuarios no sobreaplican; confirmar o
  rechazar dos veces no duplican efectos.
- **Evidencias:** bloque posterior. Comprobante ≠ dinero confirmado; no confirma solo.
- **Canales automatizados (futuro):** misma capa de servicios; la IA **no confirma dinero** y n8n **no escribe
  tablas directamente**; todo pago registrado por un canal nace `sin_revisar`.
- **Tres entradas de usuario:** (A) desde el pedido, (B) desde la cartera del cliente, (C) bandeja central
  Pagos/Cartera.

### Decisiones que permanecen abiertas

1. **Mecánica técnica de la reversión confirmada** (Transaction negativa vs. alternativas): microauditoría al inicio de PAG-I.
2. **Fecha de corte:** valor, almacenamiento (propuesto `core_config`) y mecanismo de incorporación puntual de históricos.
3. **Pedido sin factura o con varias facturas al confirmar** (la Transaction exige `invoice_id`): microauditoría puntual en PAG-E; relacionada con C-4.
4. **Visibilidad `view_own` de un pago multipedido** (abono con pedidos de varios asesores). Propuesta: se ve si lo registró el propio asesor o alguna aplicación recae en un pedido propio, ocultando los importes ajenos.
5. **Autoconfirmación:** hoy se permite a quien tiene ambos permisos (queda el actor en el evento); una política opcional de separación de funciones queda como mejora posible.
6. **Pedidos con Refund** en la cifra total de cartera: propuesta de mostrarlos aparte («por revisar»), fuera de la propuesta FIFO.
7. **Duplicados de referencia** (aviso, no bloqueo): por definir en PAG-C.
8. **Saldo a favor / sobreaplicación y conciliación:** PAG-H (hoy fuera de alcance).

### Hoja de ruta (propuesta; el orden definitivo lo fija Camilo)

**03G** validación manual de Camilo → **PAG-A** ✅ arquitectura → **PAG-B** modelo persistente + consulta de cartera → **PAG-C**
registrar pago desde el pedido + estado de pago de Mis ventas sobre el dominio nuevo → **PAG-D** cartera del cliente + abono
general → **PAG-E** bandeja central + confirmar/rechazar → **PAG-F** configuración objetivo `moneytransfer` + fecha de corte
+ roles + activación (**no activar en producción antes de PAG-E y PAG-F**) → **PAG-G** evidencias → **PAG-H** conciliación y
saldo a favor → **PAG-I** reversión (con microauditoría previa) → posterior: canales automatizados.

### Hechos de referencia (sin modificar)

- **Pedido 41 (`BYL-00000025-CO`):** `Invoice paid` + 0 Transactions; es evidencia del problema. No se modifica, no recibe Pago
  ni Transaction y no se usa para pruebas con escritura.
- **Configuración temporal de desarrollo:** `emails…new_invoice = 0` se mantiene; no se tocan `generate_invoice`, `invoice_status`
  ni los métodos de pago. El problema de PDF/correo sigue siendo deuda aparte.
- **BODEGA1** (`state = MI`, `postcode = 48127`, datos de ejemplo) sigue pendiente de que Camilo la corrija; no se mezcla con PAG.
- **03G** queda pendiente solo de la validación manual de Camilo y de su cierre documental.

## D-60 · Identidad del asesor: perfil propio asociado 1:1 al usuario de Bagisto (ASESOR-01) — **IMPLEMENTADA EN DESARROLLO (2026-10-07, sin commit)**

**Contexto.** La identidad comercial era el usuario de Bagisto (`admins.id`) con la clave ACL `ventas_asesor.operar_como_asesor`; la
atribución guarda ese id en `advisor_id`. No había nombre comercial, teléfono, estado activo/inactivo ni bloqueo para un usuario que vende
como asesor sin perfil.

**Decisión.**
- Perfil mínimo `bylopez_va_asesores` (`admin_id` **único e inmutable**, nombre, teléfono, activo). **No** se crea una segunda identidad:
  `advisor_id` sigue siendo el id del usuario y no se reescriben pedidos; como el perfil no cambia de usuario, la atribución histórica
  apunta siempre al mismo asesor. Nunca se borra un asesor: se inactiva.
- `AsesorActualService` es la fuente única «usuario en sesión → asesor». Quien vende como asesor (rol `custom` + `operar_como_asesor`)
  **sin perfil activo falla cerrado** (`asesor_sin_asociacion`); nunca se degrada a «sin asignar» ni a «empresa». El administrador `all`
  no requiere perfil (D-27 vigente).
- `view_own` no cambia (compara `advisor_id` con el usuario; equivalente al asesor por la inmutabilidad 1:1). Sin backfill de históricos.
- ACL nueva `ventas_asesor.asesores` (`view_own`, `view_all`, `manage`); el rol `#2 ASESOR VENTAS` recibe solo `asesores` +
  `asesores.view_own` (ve su ficha). Gestión por el Administrador `all`.
- Elegibilidad por ACL (`operar_como_asesor`), sin nombres de rol. La pantalla asocia usuarios; no crea usuarios ni contraseñas.

**Pendiente.** Asignar usuarios reales al rol y crear sus perfiles (Camilo); producción. Detalle en Doc. 05 §43.

> **ASESOR-02 (2026-10-07): VALIDADA MANUALMENTE por Camilo Gaitán en desarrollo.** Usuario de prueba 12177
> «Asesor Prueba» (rol `#2 ASESOR VENTAS`, perfil #1 activo, sin teléfono): creó una venta con `advisor_id` correcto, visible en
> Mis ventas, en su ficha y en sus KPI; cliente «atendido»; cartera propia; Sales nativo y revisión financiera bloqueados.
> Producción: rollout **bloqueado** (ver `00_STATUS.md`).

---

## D-COST · Costos: comisionable, productivo y utilidad real — **DECIDIDAS por Camilo Gaitán (2026-10-08, segunda ronda)**

> **Superan la precisión anterior de COM-D-02** (que dejaba el `cost` de Bagisto sin autoridad): el `cost` de Bagisto pasa a
> ser el **COSTO COMISIONABLE OFICIAL**. Detalle técnico en `19_CONTROL_COSTOS_Y_RENTABILIDAD.md`.

**Vocabulario oficial (no mezclar):**

| Término | Qué es | Fuente | Uso |
|---|---|---|---|
| **Costo comisionable** | Valor **fijado por la empresa** para calcular la comisión | Atributo `cost` de Bagisto de la variante vendida, congelado al vender | Comisión |
| **Margen comisionable** | `precio neto unitario − costo comisionable congelado`, llevado a `max(0, …)` por unidad | Calculado (COM-02) | Base de la comisión. **No** es «margen neto» ni utilidad contable |
| **Costo productivo** | Lo que cuesta producir la prenda (tela, corte, confección, transporte productivo…) | CorteConfeccion (hoy **parcial: solo tela**) | Referencia administrativa: comparar y alertar |
| **Utilidad real de la empresa** | Resultado tras devoluciones, transporte, nómina, empaques, plataformas, pérdidas, servicios, arriendo, comisiones… | Futuro módulo de rentabilidad | Fuera del cálculo de la comisión |

| Id | Decisión |
|---|---|
| **D-COST-01** | Se separan **costo comisionable**, **costo productivo real** y **utilidad real de la empresa**. El costo de la comisión **no** pretende representar la utilidad contable final; ese análisis pertenece a un futuro módulo administrativo/financiero |
| **D-COST-02** | El `cost` de Bagisto es el **COSTO COMISIONABLE OFICIAL**, fijado por la empresa. Ejemplo: precio 8.500 − costo 5.000 = margen comisionable 3.500 → comisión 20 % = 700 |
| **D-COST-03** | Si producir cuesta **menos** que el costo comisionable (4.700 vs 5.000), la comisión **no** aumenta: la eficiencia productiva (tela, producción, proveedores) es de la empresa |
| **D-COST-04** | Si el costo productivo promedio **supera** el costo comisionable (5.200 vs 5.000), **no** se cambia Bagisto automáticamente: el sistema **advierte** y Camilo decide |
| **D-COST-05** | Existe una herramienta administrativa de **actualización masiva** del costo Bagisto: selección → vista previa → confirmación → actualización → auditoría. **Nunca automática** |
| **D-COST-06** | Las ventas antiguas **no cambian**: el snapshot conserva el costo del momento de la venta; las nuevas ventas usan el costo nuevo. Nunca recalcular históricos con el costo actual |
| **D-COST-07** | CorteConfeccion es el **COSTO PRODUCTIVO DE REFERENCIA**, no la fuente de la comisión. El administrador compara costo comisionable vs costo productivo promedio |

**Implementado en desarrollo (2026-10-08, sin commit):** COM-01b (autoridad del costo comisionable), pantalla «Control de
costos» (comparación con el costo productivo parcial de CorteConfeccion y alertas), actualización masiva con vista previa
obligatoria y bitácora `bylopez_va_costo_comisionable_cambios`. Detalle en el Doc. 19.

### DESP-D · Despacho V1 — **DECIDIDAS por Camilo Gaitán (2026-10-09)** e implementadas en desarrollo

| Id | Decisión vigente | Implementación |
|---|---|---|
| **DESP-D-01** | BODEGA trabaja en **Logística › Despachos** (sin `view_all` de Mis ventas). El Administrador despacha desde el listado de **Pedidos**. Ambos reutilizan **exactamente el mismo componente/modal y los mismos servicios**. La bandeja de BODEGA es operativa: sin margen, costos, comisión, utilidad ni datos comerciales; sí el **valor a recaudar** | `admin/despachos/componente.blade.php`, `DespachoService`, `ContextoDespachoService` |
| **DESP-D-02** | Rol existente **BODEGA (#4)** con **ACL específica** de Logística; sin permisos comerciales; la autorización se valida **también en el backend** | `logistica.despachos`, `.despachos.despachar`, `logistica.guias(.ver/.archivo)`; rol #4 30 → 39 permisos incl. `logistica.guias.corregir` (solo desarrollo) |
| **DESP-D-03** | Flete **estructurado**: valor real + **quién lo paga** (cliente al recibir · ByLopez · incluido en la venta · otro), para que Finanzas pueda medir el gasto real | `valor_flete`, `flete_pagador`; si paga ByLopez el valor es obligatorio |
| **DESP-D-04** | Contraentrega: se registra el **recaudo esperado** («la transportadora debe cobrar $X»), **NO** un pago recibido en Cartera; queda preparado para conciliar recaudos | `recaudo_esperado`, `recaudo_estado = pendiente_liquidar` (reservado `liquidado`) |
| **DESP-D-05** | Recogida en bodega con **flujo propio** («Entregar en bodega»): sin transportadora, guía ni PDF; sin envío ficticio; entrega con las reglas existentes | Shipment real + entrega `recogida_bodega` |
| **DESP-D-06** | Despachos **parciales permitidos**. Por defecto **todas las unidades pendientes** («Productos a despachar: N unidades» + «Ajustar cantidades»); el pedido pasa a parcial y luego «Completar despacho» | Cantidades solo si se abre el ajuste |
| **DESP-D-07** | PDF máx. **5 MB**, solo PDF validado por contenido, almacenamiento **privado**, ver/descargar solo por endpoint autenticado y autorizado, versionado y reemplazo auditado | `bylopez_lg_guia_archivos` |
| **DESP-D-08** | El **asesor** ve transportadora, guía, tracking y PDF **solo de sus propios pedidos** (atribución comercial); no modifica, reemplaza ni borra | Ruta `admin.ventas_asesor.pedidos.guia` |
| **DESP-D-09** | **Corregir guía** (2026-10-09): una guía mal digitada se corrige con una acción explícita y auditada que **no anula el Shipment** (el despacho y el número de guía son conceptos distintos). Motivo obligatorio; historial con anterior/nuevo (número y transportadora), usuario y fecha; `shipments.track_number` / `carrier_code` se sincronizan con la guía vigente; no crea Shipment, no descuenta inventario, no cambia cantidades, no toca Cartera | `CorregirGuiaService`, `bylopez_lg_guia_correcciones` (migración 100005), clave `logistica.guias.corregir` |
| **DESP-D-10** | **BODEGA puede adjuntar y reemplazar el PDF** (con motivo obligatorio, versiones conservadas, privado, máx. 5 MB). No se modifica `php.ini` todavía; si PHP bloquea el archivo, el mensaje nombra el **límite vigente del servidor** | `logistica.guias.archivo`; `archivo_limite_servidor` |
| **DESP-D-11** | **PICAP** y cualquier otra transportadora nueva **no se programan**: se agregan por Logística › Transportadoras como las demás | Catálogo `bylopez_lg_transportadoras` |
| **DESP-D-19** | **Guía interna = `GI-000001`** (reemplaza la propuesta «BL»): consecutivo global solo entre transportadoras con guía interna, mínimo 6 dígitos, sin reinicio, inmutable, único, huecos permitidos, solo para guías internas (sin identificador para todos los envíos) | `Logistica/docs/MAPA_DOMINIO_LOGISTICA.md` §13 · `bylopez_lg_consecutivos` · `bylopez_lg_guias.consecutivo_interno` |
| **DESP-D-20** | **El modo de guía lo elige cada transportadora** (externa o interna, excluyente, independiente del tipo y del nombre) reutilizando `genera_guia_interna`; cambiarlo afecta solo despachos nuevos y cada guía conserva su origen. «Corregir guía» no cambia el número GI ni convierte el tipo de guía | `Transportadora`, `Guia`, `CorregirGuiaService` |
| **DESP-D-21** | **PDF / etiqueta de la guía interna: pendiente de definir su formato.** No bloquea el consecutivo ni se muestran botones de PDF para GI | — |
| **DESP-D-22** | **Cuenta corriente = libro de movimientos con importe firmado** (+ transportadora debe a ByLopez / − ByLopez debe), saldo derivado, sin saldo mutable; participa por la capacidad `liquida_cuenta_corriente`, nunca por nombre. Separada de Cartera: es custodia, no ingreso | `Logistica/docs/CUENTAS_TRANSPORTADORAS.md` §1–§4 |
| **DESP-D-23** | **El dinero nace de la ENTREGA:** recaudo con el envío completamente entregado (uno por envío), servicio con la primera unidad entregada y solo si lo paga ByLopez; por pagar sin servicio; despachar no genera nada; el «flete recibido por ByLopez» es el mismo servicio (no se duplica) | `ReglaCuentaEnvio` · §5 |
| **DESP-D-24** | **Sin proporciones inventadas:** entrega parcial con devolución y recaudo, pagador «otro» o sin costo → «Requiere revisión» y el Admin confirma el valor real; la devolución por sí sola no mueve dinero y el costo de devolución existe solo por registro explícito | §5, §8 |
| **DESP-D-25** | **Liquidación inmutable que suma 0:** pago + diferencia trasladada (cierre −D / apertura +D) con observación obligatoria; las correcciones posteriores son ajustes en la cuenta siguiente; LIQ-000001 con contador bloqueado y UNIQUE | `LiquidarCuentaService` · §7 |
| **DESP-D-26** | **Permisos:** BODEGA ve (`logistica.cuentas`); solo ADMIN liquida (`.liquidar`) y ajusta (`.ajustar`). **Pendiente:** adjunto privado del comprobante, indicador del cobro en Cartera, liquidación por rango y backfill histórico (comando listo, sin aplicar) | §9, §16 |
| **DESP-D-12** | **Cantidad a enviar** (2026-10-09): el drawer precarga la cantidad pendiente real por despachar (semántica de Bagisto `qty_to_ship`), nunca 0 si hay unidades y stock. Máximo = `min(pendiente, stock permitido por Bagisto)`; con stock insuficiente se despacha lo que haya como despacho parcial; con stock 0 la línea queda bloqueada con «Sin stock disponible». «Ajustar cantidades» queda para excepciones. El backend revalida siempre (sobre-despacho, concurrencia, manipulación del request) | `UnidadesDespachables::plan()/maximoEnBodega()`; `lineas[].pendientes/disponibles/maximo` |
| **DESP-D-13** | **Quién paga el flete** (2026-10-09): valor inicial siempre «Cliente, al recibir»; la operaria puede cambiarlo a ByLopez, Incluido en la venta u Otro **solo para ese despacho**. No se recuerda la última selección del pagador (sí transportadora y origen), para que una excepción no contamine el siguiente pedido | `PagadorFlete::porDefecto()`; el drawer ya no guarda `logistica.pagador` |
| **DESP-D-14** | **Stock del sistema insuficiente pero la prenda está en bodega** (2026-10-09, aprobada por Camilo): nunca se despacha sin stock (ni el Administrador). Flujo: stock insuficiente → BODEGA solicita autorización con conteo físico → Administrador revisa → regularización auditada → el drawer recalcula → despacho normal. Integrado en Logística › Despachos (pestaña «Incidencias de stock»), sin módulo nuevo | `bylopez_lg_incidencias_stock(_lineas)`, `IncidenciasStockService`, Doc. Logística §11.14 |
| **DESP-D-15** | **Una sola lógica de stock**: Logística reutiliza `InventarioMovimientoService` de CorteConfeccion mediante el contrato `AjustaInventario` / adaptador `AjusteViaCorteConfeccion`, sin modificar CorteConfeccion | Libro `bylopez_cc_inventario_movimientos` |
| **DESP-D-16** | **Solo incrementos** hacia el conteo físico para esta excepción; bajar stock es otro control de inventario. Si el stock cambió entre la solicitud y la resolución, el resultado es exactamente el conteo confirmado | `conteo_menor_al_sistema`, `sin_diferencia` |
| **DESP-D-17** | **Solo el Administrador regulariza o rechaza; BODEGA únicamente solicita.** El Administrador puede solicitar y resolver en una misma operación manteniendo la auditoría (solicitante y resolutor) | `logistica.stock.solicitar` / `.regularizar`; compuerta de CorteConfeccion |
| **DESP-D-18** | **Sin umbral numérico** por ahora. Toda regularización requiere conteo físico, motivo y confirmación expresa; si las prendas no existen físicamente se rechaza y el pedido sigue pendiente | `confirmacion_requerida`, `motivo_requerido` |

**DECISIÓN PENDIENTE para una fase posterior (no implementada):**
- **Anulación de un despacho (Shipment).** Tiene implicaciones de inventario (reingreso de stock), de estado del pedido y de operación (guía, PDF, recaudo esperado) distintas de corregir una guía. Decidir quién, cuándo y con qué consecuencias; y si el retorno de transportadora de LOG-01 debe reingresar inventario.

**Operativo (no es una decisión de negocio):** ampliar `upload_max_filesize` / `post_max_size` de PHP en cada entorno para admitir los 5 MB aprobados.

### LOG-D y COM-D-15 — **DECIDIDAS por Camilo Gaitán (2026-10-08, tercera ronda)** e implementadas en desarrollo

| Id | Decisión vigente | Implementación (desarrollo, sin commit) |
|---|---|---|
| **COM-D-15** (G-11) | Clasificación estructurada **POR PEDIDO**: `venta_normal` · `muestra` · `reposicion` · `garantia`. Nunca texto libre ni inferida de precio, cliente o comentario. Regla operativa: reposiciones y garantías normalmente a precio 0, pero el marcador es **obligatorio** (el precio 0 no demuestra el motivo). Solo `venta_normal` es comisionable. Clasificación por línea solo si aparecen pedidos mixtos reales (**no ahora**) | `bylopez_va_clasificaciones_pedido` (000018), listener `ClasificarOperacionPedido`, `TipoOperacionPedidoService`. Asesor: solo `venta_normal`; quien tenga `ventas_asesor.pedidos.tipo_operacion` (admin `all`): los cuatro. Históricos sin fila (no se rellenan). Que Bodega u otro rol cree reposiciones queda como **evolución futura** |
| **LOG-D-01** (= D-44) | Evento propio de ENTREGA en `ByLopez/Logistica`; VentasAsesor es consumidor de solo lectura. Por **shipment + unidades**. Nunca inferido de `orders.status`, Shipment, Invoice ni tracking | `bylopez_lg_entregas` / `_items`, `RegistrarEntregaService`, `ConsultaEntregaService::unidadesEntregadas()` |
| **LOG-D-02** (D-14 / COM-D-11) | Confirman **BODEGA** y **ADMIN / SUPERVISIÓN**; el asesor **no**. Evidencia **opcional** al inicio; siempre se auditan actor, fecha real, fecha de registro, shipment, pedido, transportadora, guía si existe y fuente | ACL `logistica.entregas.registrar` (Bodega y admin) y `.anular` (admin / supervisión); snapshots en el evento |
| **LOG-D-03** (D-07) | Sin tracking completo. Estados mínimos: `despachado` (= Shipment de Bagisto, **no se duplica**), `entregado` y `retornado_transportadora`. El dominio propio empieza en entrega y retorno | Entrega y retorno como eventos separados; estado del pedido derivado (sin columna mutable) |
| **LOG-D-04** (D-42) | Catálogo estructurado: BYLOPEZ, 99ENVIOS, INTER_RAPIDISIMO, DOMICILIARIO, RECOGIDA_BODEGA, OTRA. Auditar antes los valores reales; **no** convertir históricos; domiciliario = código genérico, sin módulo de personas | `bylopez_lg_transportadoras`; históricos con `carrier_code` NULL; despachos nuevos con código solo por coincidencia **exacta** con el catálogo |

**Pendientes derivados (no bloquean la prueba manual):**
- PICAP aparece en los datos reales y no está en el catálogo aprobado. Se registra como OTRA, o Camilo lo agrega desde «Transportadoras».
- El reingreso físico a inventario de un retorno de transportadora no se decidió (LOG-01 no toca inventario).
- Los pedidos creados fuera de Nueva venta (tienda, panel nativo) no tienen clasificación. Falta definir si requieren un flujo de clasificación auditado antes de COM-04.

<details><summary>Planteamiento original (2026-10-08, segunda ronda)</summary>

| Id | Pregunta | Opciones | Recomendación técnica | Impacto |
|---|---|---|---|---|
| **COM-D-15** (G-11) | ¿Cómo se marcan muestras, reposiciones y garantías? | A. clasificación por pedido · B. por línea · C. solo precio 0 · (D. texto libre, descartada) | A + C como regla operativa (Doc. 17 §15) | Sin esto COM-04 no puede excluirlas |
| **LOG-D-01** (= D-44) | ¿Se aprueba el evento propio de entrega (y su anulación auditada) por envío y por unidad? ¿En el paquete `ByLopez/Logistica`? | Sí en Logística · Sí en VentasAsesor · otra | Sí, en Logística (afecta también a la tienda; D-45) | Sin entrega no hay causación (D-01) |
| **LOG-D-02** (D-14 / COM-D-11) | ¿Qué rol y qué usuarios registran la entrega? ¿Se exige evidencia (D-09)? | Bodega · supervisor · ambos; con o sin evidencia | Bodega y supervisor, con referencia opcional al inicio | Confiabilidad del hecho generador |
| **LOG-D-03** (D-07) | ¿Hay estados intermedios (preparando, en tránsito, entrega fallida, retornado) o solo «entregado» al inicio? | Solo entregado + retornado · flujo completo | Empezar con `entregado` y `retornado_transportadora`; tránsito después | Alcance de la Fase 06 |
| **LOG-D-04** (D-42) | ¿Catálogo de transportadoras con código estable en `shipments.carrier_code` (BYLOPEZ, 99envíos, Inter Rapidísimo, domiciliario…)? ¿Cómo registrar a los domiciliarios (datos personales)? | Catálogo · texto libre | Catálogo; domiciliario como código genérico | Reportes logísticos y de devoluciones |

</details>

**Efecto sobre COM-D-02:** desde COM-01b, un snapshot con `cost` válido (> 0) nace `fuente = cost_bagisto_comisionable`,
`autoridad_comision = true`. Sin valor: `NULL` / `sin_costo` / `autoridad_comision = false`. Los snapshots previos con la
fuente de referencia (si existieran) **no** se convierten: al aplicar el cambio había **0** snapshots en desarrollo.

---

## COM-D · Decisiones de Comisiones (COM-00, 2026-10-07) — **DECIDIDAS por Camilo Gaitán el 2026-10-08** (COM-D-02 con precisión)

> **2026-10-15 — APROBADAS por Camilo Gaitán (A-01…A-07, detalle en `22_PRECIO_MINIMO_RENTABILIDAD_Y_COMISIONES.md` §0):**
> A-01 % de comisión **por asesor** con vigencias e historial, sin valores quemados (**reemplaza COM-D-03 y COM-D-06**) ·
> A-02 % vigente **al confirmar la venta** · A-03 cálculo visible por referencia y total del pedido · A-04 habilitar con
> **todo el pedido validado + cobro confirmado + dinero recibido por ByLopez** · A-05 **abonos** de comisión y saldo pendiente ·
> A-06 **no** vender con precios incorrectos o sin la ganancia mínima definida · A-07 $5.000 / $1.500 son solo ejemplos.
> Preguntas abiertas para aprobar la regla de precio mínimo: Doc. 22 §11 (P1–P12).
>
> **2026-10-19 — APROBADAS por Camilo Gaitán:** base **A** (comisión = % × (precio neto − costo); costos directos fuera de la base,
> descontados después en la contribución) · ganancia mínima **después** de comisión y costos variables, por línea, sin excepción ·
> **umbral mensual por asesor** (primeras N unidades del mes sin comisión, desde la N+1 sí, no retroactivo, reinicia cada mes en
> America/Bogota, N configurable con vigencia, sin carga masiva de 1.000). Pendientes D1–D12 (D10–D12 nuevas: devoluciones/reembolsos, costo provisional, recaudo compensado) en `23_IMPLEMENTACION_RENTABILIDAD_COMISIONES_UMBRAL.md` §7.
>
> **2026-10-14:** la auditoría E2E (`21_AUDITORIA_FLUJO_COMISIONES_E2E.md` §9) pide **reconfirmar** estas decisiones (P2) y
> abre **P1, P3–P13** (recaudo contra entrega, habilitación por envío, fecha de la regla, ventas creadas por administrador,
> flete de ByLopez, quincena de imputación, pedidos sin clasificar, costo 0, devoluciones sin RMA, pago al asesor, fecha de
> inicio, cobro de un solo actor). Las preguntas viven solo en el Doc. 21.

### Decisiones de Camilo (2026-10-08) — vigentes; mandan sobre las recomendaciones de abajo

| Id | Decisión | Diferencia con la recomendación técnica |
|---|---|---|
| COM-D-01 | Base = `precio_neto_unitario − costo_unitario_congelado`; sin IVA, sin envío; **nunca** `orders.grand_total` | Igual (A) |
| **COM-D-02** | **PRECISIÓN:** el atributo `cost` de Bagisto **NO es costo autoritativo** (mutable, sin historial, incompleto, no probado como costo real). Desde ya se **captura** su valor como referencia: `fuente = atributo_cost_bagisto_referencia`, **`autoridad_comision = false`**. Sin valor: `costo_unitario = NULL`, `fuente = sin_costo` (nunca 0). COM-02 puede calcular utilidad **diagnóstica**, marcada como no liquidable. **Prohibido causar o pagar comisión con un costo no autorizado.** La fuente autoritativa queda **pendiente** de una decisión posterior | Más estricta: se captura pero sin autoridad |
| COM-D-03 | 20 % de la utilidad positiva, **configurable y con vigencia**; nunca `0.20` en el código | Igual |
| COM-D-04 | Se causa solo cuando coinciden **entrega confirmada** y **pedido totalmente pagado/verificado** | Igual (F) |
| COM-D-05 | Pago parcial **no** causa comisión proporcional; hasta pago completo verificado = no causada | Igual |
| COM-D-06 | Regla inicial **global**, preparada para vigencia y alcances futuros; muestras, reposiciones y garantías **no comisionables** | Igual |
| COM-D-07 | `max(0, utilidad)` **por unidad**; sin netear pérdidas entre productos ni pedidos | Igual |
| COM-D-08 | Refund sin devolución: **no** descontar automáticamente; queda para revisión futura | Igual |
| COM-D-09 | Beneficiario = asesor vigente en la atribución **al causar**; después de causar, corrección = ajuste auditado; tras liquidación pagada, la línea original no se reescribe | Igual |
| COM-D-10 | Liquidación inicial **QUINCENAL**; el diseño debe permitir cambiar la frecuencia sin alterar comisiones históricas | **Distinta** (se recomendaba mensual) |
| COM-D-11 | El asesor **no** confirma su propia entrega; el evento vendrá de Bodega / supervisión / logística confiable | Igual |
| COM-D-12 | El asesor **no** ve costo, utilidad ni margen; **sí** ve venta, unidades, comisión y estado | Igual |
| COM-D-13 | No comisionar históricos reconstruidos; solo pedidos posteriores a la activación oficial del snapshot / corte de comisiones | Igual |
| COM-D-14 | Ajuste negativo sin liquidaciones futuras: saldo a cargo **trazable**; con liquidaciones futuras puede compensarse según regla futura; el ajuste **nunca** se borra | Igual, con compensación futura permitida |

**Aplicación (2026-10-08, desarrollo, sin commit):** COM-01 (snapshot de costo), COM-02 (utilidad) y COM-03 (reglas con
vigencia + comisión potencial diagnóstica) implementadas; detalle en `16_AUDITORIA_COMISIONES.md` §11,
`17_DISENO_FUNCIONAL_COMISIONES.md` §13 y `18_ROADMAP_COMISIONES.md`. **No existe comisión causada ni pagable.**

### Planteamiento original (COM-00, 2026-10-07) — se conserva como historia

**Origen:** auditoría COM-00 (`16_AUDITORIA_COMISIONES.md`) y diseño (`17_DISENO_FUNCIONAL_COMISIONES.md`). Roadmap en
`18_ROADMAP_COMISIONES.md`. Las COM-D **no reabren** D-01, D-02, D-05 ni D-27 (decididas); concretan lo abierto y absorben
D-03, D-04, D-10, D-14, D-15, D-24, D-31 y D-32, que siguen existiendo con su historia y ahora remiten aquí. Las recomendaciones
son **técnicas**, no aprobaciones. Ejemplo de referencia: precio 6.900, costo 5.500, 20 %.

### COM-D-01 · Base de la utilidad comisionable
**Opciones:** A. `precio neto − costo congelado` · B. A − costos variables comerciales (recaudo COD, pasarela, empaque) ·
C1. % sobre venta neta (sin costo) · C2. valor fijo por prenda.
**Recomendación:** A (es la dirección ya documentada; auditable; el descuento ya reduce la comisión). B solo si existe un costo
variable claro y medible por unidad. Precio neto = `order_items.base_price` − descuento unitario, **sin IVA y sin envío**.
**Impacto:** define el motor de utilidad (COM-02). Con A: 6.900 − 5.500 = 1.400 de utilidad por unidad.

### COM-D-02 · Fuente del costo que se congela (absorbe D-31)
**Hecho:** no existe snapshot de costo (**gap crítico**); el único costo es el atributo `cost` de Bagisto (solo variantes simples,
valores de referencia 3.900–5.500, editable y sin historial). CorteConfeccion solo tiene costo de tela por rollo.
**Opciones:** 1. Congelar `cost` de Bagisto etiquetado como fuente provisional · 2. Esperar un costo de producción real
(tela + corte + confección…) antes de congelar nada · 3. Tabla propia de costos por variante con vigencias, mantenida por la empresa.
**Recomendación:** 1 de inmediato (COM-01), etiquetado; migrar a 3 cuando Camilo defina qué integra el costo. Esperar (2) pierde para
siempre el costo de cada venta mientras tanto.
**Impacto:** sin esta decisión no hay COM-01 y cada venta nueva queda sin costo verificable.

### COM-D-03 · ¿Se aprueba el 20 %? (absorbe D-32)
**Estado real:** **PROPUESTA** (dirección aceptada; «no es política definitiva hasta validar márgenes reales»).
**Opciones:** aprobar 20 % · otro porcentaje · aprobar 20 % solo como regla inicial con vigencia y revisión tras N meses.
**Recomendación:** la tercera (la regla con vigencia permite cambiarlo sin tocar comisiones pasadas).
**Impacto:** 20 % = 280 por unidad a 6.900 (4,1 % del precio) y 600 a 8.500 (7,1 %).

### COM-D-04 · Condición de pago para causar (ambigüedad «conciliadas»)
**Hecho:** D-01 (entrega) está decidida. `ACTUALIZACION` §7 añade «conciliadas», sin definir.
**Opciones:** E. Solo entrega (D-01 literal) · F. Entrega **y** pago verificado del pedido (Cartera, sin `sin_revisar`).
**Recomendación:** F: no se paga comisión sobre dinero no cobrado (contra entrega y crédito). Estado intermedio `pendiente_pago`.
**Impacto:** con E la empresa asume el riesgo de cartera en la comisión; con F la comisión espera al recaudo.

### COM-D-05 · Pago parcial
**Caso:** venta 500.000 · utilidad 100.000 · comisión 20.000 · pagado 250.000.
**Opciones:** 0 hasta pago total (20.000 al completar) · proporcional (10.000 hoy) · total (20.000 hoy) · umbral (p. ej. ≥ 90 %).
**Recomendación:** 0 hasta pago total verificado (simple; sin imputar pagos de pedido a unidades; sin ajustes por cobro).
**Impacto:** el proporcional exige reglas de imputación (¿envío primero?) y ajustes ante pagos rechazados o reversados.

### COM-D-06 · Alcance de reglas y exclusiones (absorbe D-03)
**Opciones:** regla global · por asesor · por producto/categoría · combinación con prioridad. Exclusiones: muestras, reposiciones,
garantías (ya excluidas por D-01), productos marcados no comisionables.
**Recomendación:** una regla global con vigencia; el modelo admite alcance y prioridad para no migrar después.
**Impacto:** estructura de `bylopez_va_comision_reglas` (COM-03).

### COM-D-07 · Unidad con utilidad negativa
**Opciones:** 0 por unidad, sin compensar (dirección documentada) · netear dentro del pedido · netear dentro del periodo.
**Recomendación:** 0 por unidad (el asesor no paga pérdidas; una venta con pérdida no se premia).
**Impacto:** con neteo, una prenda vendida bajo costo reduce la comisión de las demás del mismo pedido.

### COM-D-08 · Reembolso sin devolución (D-24)
**Opciones:** descontar siempre · nunca · solo si es atribuible al asesor · caso a caso con supervisor.
**Recomendación:** no descontar automáticamente; marcar incidencia para revisión (`comisiones.manage`).
**Impacto:** alcance de los ajustes negativos (COM-08).

### COM-D-09 · Reasignación y comisión (absorbe D-04)
**Opciones:** R1 asesor vigente al causar · R2 asesor original · R3 reasignación con tipo (corrección vs traspaso).
**Recomendación:** R1 con bloqueos: libre antes de causar; tras causar solo con `comisiones.manage` (anula y recausa); **bloqueada**
tras liquidación pagada.
**Impacto:** quién cobra cuando un admin corrige la atribución.

### COM-D-10 · Frecuencia de liquidación (absorbe D-15)
**Opciones:** semanal · quincenal · mensual · manual.
**Recomendación:** mensual por fecha de entrega, con entregas tardías en el periodo en que se registran.
**Impacto:** carga administrativa y frecuencia de ajustes.

### COM-D-11 · Quién confirma la entrega (absorbe D-14)
**Opciones:** solo bodega · bodega y supervisor · también el asesor (con auditoría).
**Recomendación:** bodega o supervisor; el asesor no, porque la entrega causa su propia comisión (conflicto de interés).
**Impacto:** confiabilidad del hecho generador (Fase 06 / COM-04).

### COM-D-12 · ¿El asesor ve costo y utilidad? (absorbe D-10)
**Opciones:** solo venta y comisión · también utilidad y % · nada monetario.
**Recomendación:** venta, comisión y estado; sin costo ni utilidad (deducirían márgenes de la empresa).
**Impacto:** columnas de «Mis comisiones» (COM-05).

### COM-D-13 · Desde cuándo se comisiona
**Hecho:** ningún pedido actual tiene costo congelado; los históricos no lo tendrán nunca.
**Opciones:** solo pedidos con snapshot (desde COM-01) · también anteriores usando el costo actual etiquetado como «reconstruido» ·
fecha de inicio explícita decidida por Camilo.
**Recomendación:** solo pedidos confirmados después de activar COM-01 en producción; anteriores sin comisión automática.
**Impacto:** evita comisionar con costos reconstruidos no verificables.

### COM-D-14 · Ajuste negativo sin liquidaciones futuras
**Caso:** el asesor se retiró y después se devuelve una prenda ya pagada.
**Opciones:** saldo a cargo informado y gestionado fuera del sistema · descontar de otros pagos al asesor · condonar por debajo de un monto.
**Recomendación:** registrar el saldo a cargo y dejar visible; la política de cobro la define Camilo.
**Impacto:** trazabilidad de D-02 cuando no hay «siguiente liquidación».

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
| D-13 | Visibilidad entre asesores | 05 | Solo lo propio | **decidida 2026-10-04** |
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
| D-53 | Registro seguro de pagos: idempotencia y concurrencia | 03E / cobros | Tabla mínima con UNIQUE(mecanismo, clave), bloqueo pedido → factura, primitivas nativas (Doc. 05 §35–§36.4) | ✅ **DECIDIDA 2026-09-27** (arquitectura; sin migración; abono general FIFO/manual documentado como requisito futuro, Doc. 05 §36.4) · 🔁 **REVISADA por D-59 (PAG-A, 2026-10-05)**: registrar ≠ crear Transaction; tabla `registros_pago` sustituida por `pagos` + `aplicaciones_pago` + `pagos_eventos` |
| D-54 | Protección concurrente de inventario | 03F | Cerco en `checkout.order.save.before`: `FOR UPDATE` de `products` en orden ascendente y revalidación con la semántica de Bagisto; no reserva (Doc. 05 §38.3, §39.3) | ✅ **APROBADA e IMPLEMENTADA 2026-09-27**; redactada de nuevo: serialización y revalidación con la semántica nativa, efectiva donde Bagisto reserva; en configurables, **limitación conocida aceptada** (sin corregir) |
| D-55 | Confirmación idempotente | 03F | `confirmation_token` + `confirmacion_iniciada_en` (000008), lease configurable, fencing en el cerco y enlace condicionado (Doc. 05 §38.6, §39.2) | ✅ **APROBADA e IMPLEMENTADA 2026-09-27** (000008 solo en `lpz_saas_testing`) |
| D-56 | Separación Pedido / Pago / Despacho | 05, Cartera, 06 | Tres ciclos: Order (Bagisto), pago derivado de Invoice/Transaction (futuro Cartera), despacho derivado de Shipment (futuro Logística); sin bloqueo global de despacho por falta de pago; KPI «Por despachar» = unidades físicas pendientes (sin despachar + parcial), no `orders.status` | ✅ **APROBADA 2026-10-05** (arquitectura; sin estados ni tablas nuevas) |
| D-57 | Nueva venta: Entrega → Dirección → Pago | 03G-D | Recogida ⇔ bodega (bicondicional); una sola opción de recogida; domicilio = dirección → rates reales → pago; alta de dirección por RT-04; Pago/Confirmar solo con entrega aplicada | ✅ **IMPLEMENTADA 2026-10-05** (sin commit); modal de dirección; postcode según Bagisto (D-58); notificación «Nueva factura» desactivada en desarrollo; primer Order real 41 |
| D-58 | Geografía CO (DIVIPOLA) y código postal | 03G-D, Logística | Catálogo local DIVIPOLA + equivalencia única con `country_states` + autocomplete «Departamento — Municipio»; postcode por `isPostCodeRequired()`, sin tabla municipio→código | ✅ **APROBADA e IMPLEMENTADA 2026-10-05** (sin commit): catálogo DIVIPOLA (33 + 1.122), importador, endpoint, autocomplete único, Bogotá, D.C. = `BOG`, postcode dinámico; pendiente la prueba manual en celular |
| D-59 | Pagos / Cartera: arquitectura PAG-A (PAG-B a PAG-E implementadas 2026-10-06/07; PAG-F cerrada en desarrollo, producción pendiente) | Pagos / Cartera (PAG-B…I) | Registrar ≠ crear Transaction: pago `sin_revisar` aplicado a pedidos (saldo baja de inmediato); Transaction nativa solo al confirmar; saldo y cartera derivados (sin deuda mutable); rechazar ≠ reversar; sin sobreaplicación; FIFO propuesta editable; ACL `ventas_asesor.cartera.*`; medio de cobro ≠ método del pedido; fecha de corte, sin backfill; configuración objetivo de `moneytransfer` (Doc. 05 §42) | ✅ **APROBADA 2026-10-05** (P-01…P-12 y C-1…C-4; arquitectura sin implementar). Abiertas: mecánica de reversión (PAG-I), fecha de corte, pedido sin/multi factura |
| D-60 | Identidad del asesor (ASESOR-01) | Ventas Asesor / Asesores | Perfil `bylopez_va_asesores` 1:1 e inmutable con el usuario de Bagisto (`advisor_id` sigue siendo `admins.id`); `AsesorActualService` única fuente; asesor sin perfil activo falla cerrado (`asesor_sin_asociacion`); `all` sin perfil (D-27); ACL `ventas_asesor.asesores` (view_own/view_all/manage) | ✅ **IMPLEMENTADA EN DESARROLLO 2026-10-07** (sin commit; producción pendiente) · **ASESOR-02 VALIDADA manualmente** por Camilo (2026-10-07) |
| COM-D-01 | Base de la utilidad comisionable | COM-02 | A: precio neto − costo congelado (sin IVA ni envío) | ✅ **DECIDIDA 2026-10-08** · aplicada en COM-02 |
| COM-D-02 | Fuente del costo que se congela (D-31) | COM-01 | `cost` de Bagisto etiquetado como provisional; tabla propia con vigencias después | ◐ **PRECISADA 2026-10-08**: se captura `cost` como referencia con `autoridad_comision = false`; la fuente **autoritativa** sigue pendiente |
| COM-D-03 | ¿20 %? (D-32) | COM-03 | Regla inicial con vigencia y revisión | ✅ **DECIDIDA 2026-10-08**: 20 % configurable con vigencia (regla #1 en desarrollo) |
| COM-D-04 | Condición de pago además de la entrega (D-01) | COM-04 | Entrega + pago verificado (F) | ✅ **DECIDIDA 2026-10-08** (F) |
| COM-D-05 | Pago parcial | COM-04 | 0 hasta pago total verificado | ✅ **DECIDIDA 2026-10-08** |
| COM-D-06 | Alcance de reglas y exclusiones (D-03) | COM-03 | Global con vigencia; modelo preparado para alcance | ✅ **DECIDIDA 2026-10-08** |
| COM-D-07 | Unidad con utilidad negativa | COM-02 | 0 por unidad, sin neteo | ✅ **DECIDIDA 2026-10-08** · aplicada en COM-02 |
| COM-D-08 | Reembolso sin devolución (D-24) | COM-08 | No automático; incidencia | ✅ **DECIDIDA 2026-10-08** |
| COM-D-09 | Reasignación y comisión (D-04) | COM-04/08 | Asesor vigente al causar + bloqueos por estado | ✅ **DECIDIDA 2026-10-08** |
| COM-D-10 | Frecuencia de liquidación (D-15) | COM-07 | Mensual por fecha de entrega | ✅ **DECIDIDA 2026-10-08: QUINCENAL** (frecuencia cambiable sin alterar historia) |
| COM-D-11 | Quién confirma la entrega (D-14) | COM-04 | Bodega/supervisor, no el asesor | ✅ **DECIDIDA 2026-10-08** |
| COM-D-12 | ¿Asesor ve costo y utilidad? (D-10) | COM-05 | No | ✅ **DECIDIDA 2026-10-08**: no ve costo/utilidad/margen; sí venta, unidades, comisión y estado |
| COM-D-13 | Desde cuándo se comisiona | COM-10 | Solo pedidos con snapshot (desde COM-01 en producción) | ✅ **DECIDIDA 2026-10-08** |
| COM-D-14 | Ajuste negativo sin liquidaciones futuras | COM-08 | Saldo a cargo visible; política de Camilo | ✅ **DECIDIDA 2026-10-08**: saldo a cargo trazable; compensable en liquidaciones futuras; nunca se borra |

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
