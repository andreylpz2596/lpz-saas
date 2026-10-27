# 14 · Cargue «Clientes históricos — 99 Envíos» (2026-10-18)

Tipo parametrizable que toma la **Cartera completa de 99 Envíos** (`docs/Cartera_Completa_2026-09-12.xlsx`) para **crear o completar clientes** (teléfono, dirección y ubicación) y registrar sus **antecedentes de envíos reportados por el proveedor**, para incorporar la base histórica sin recrearla a mano. Este documento: plan por fases, análisis del archivo, reglas, estructura, uso, instalación posterior en producción, pruebas y límites. **Sin datos personales** (los archivos reales no se copian al repositorio).

> **CAMBIO DE FUENTE (2026-10-19):** este tipo ya **no** usa «Cartera completa» sino **«Envíos completos»** (§14). Las secciones §2, §3, §10 y §12 describen la fase anterior (Cartera) y se conservan como historia y evidencia; Cartera pasa a ser solo de **conciliación financiera** (`15_CARTERA_CONCILIACION.md`). Procedimiento vigente: `sp_cg_clientes_historicos_v2`.

Estado: **IMPLEMENTADO en dev** y **PROBADO** con datos sintéticos (instancia aislada) y con una muestra de 9 filas reales en desarrollo (§10). **NO desplegado en producción. NO se importó el archivo completo.**

Convención: [APROBADO] lo dicho por Camilo; [DECIDIDO] decisión mía documentada y reversible; [PENDIENTE] falta un dato o una decisión.

---

## 1. Plan por fases (todo ejecutado en esta sesión salvo lo marcado)

| Fase | Contenido | Estado |
|---|---|---|
| F0 | Analizar el archivo y el modelo de clientes del proyecto | Hecho (§2, §3) |
| F1 | Modelo de datos de antecedentes y equivalencias de estado (VentasAsesor) | Hecho (§6) |
| F2 | Servicios: registro/lectura de antecedentes con reglas explícitas | Hecho (§6) |
| F3 | Extensión genérica del motor (derivados + aplicación por servicios de Laravel) | Hecho (§5) |
| F4 | SP versionado, map y extensión del tipo | Hecho (§4, §5) |
| F5 | Interfaz: ficha del cliente y aviso al identificar un cliente existente | Hecho (§7) |
| F6 | Pruebas: suite del tipo + 2 pruebas acotadas en dev + prueba visual aislada | Hecho (§10) |
| F7 | MD y pasos de instalación en producción | Hecho (§9); el despliegue **no** se hace aún |

## 2. El archivo real (estructura y agregados)

- `docs/Cartera_Completa_2026-09-12.xlsx`: 1 hoja **`Data`**, encabezados en la fila 1, **65 filas de datos** × **12 columnas**: `Cód. Sucursal`, `Nº Guía`, `Valor Comercial`, `Flete`, `Consignado`, `Fecha Consig.`, `Estado Envío`, `Estado Pago`, `Destinatario`, `Dirección`, `Teléfono`, `Ciudad`.
- **Tiene:** nombre completo en un solo campo, teléfono (10 dígitos, texto, los 65), dirección, ciudad con el formato `CIUDAD/NOMBRE OFICIAL` (51 ciudades distintas), guía (65 únicas) y estado del envío (`Entregada` 56 · `Devolución ratificada` 8 · `Devolucion Regional` 1).
- **NO tiene:** documento de identidad, correo, departamento, ni **transportadora** (solo el proveedor 99 Envíos y la sucursal 668099), ni la fecha del estado (`Fecha Consig.` es la fecha de la **consignación**, un dato de pago: no se usa como fecha de estado).
- **Calidad:** 0 nombres de una sola palabra · **4 teléfonos repetidos** (máx. 3 filas) y **1 teléfono con dos nombres distintos** · 3 direcciones con `,` o `/`.
- **Ubicación contra el catálogo DIVIPOLA de dev:** 57 ciudades se resuelven a un municipio único, **7 son ambiguas** (mismo nombre en varios departamentos; el archivo no trae departamento) y 1 no se encuentra.
- **Estado de pago ≠ entrega:** `Estado Pago` (`pagado` / `Seguro Antidevoluciones`) y estar en Cartera **no** se usan para decidir entregas. Solo sirvió de **evidencia** para clasificar `Devolución ratificada` y `Devolucion Regional` como devolución (las 9 filas con «Seguro Antidevoluciones» son exactamente esas dos clases).

## 3. Cruce con Envíos completos (transportadora efectiva + guía)

- El archivo de Envíos que está en el repositorio (`docs/Envios_Completos_2026-10-03.xlsx`) **no contiene ninguna de las 65 guías de Cartera** (0 de 65, por guía exacta); las 65 están en **otra** exportación, la «P», que **no está en el repositorio** (§12.1 explica la aparente contradicción con el 65/65 del doc 13 §9, con hashes). Por tanto **desde el repositorio no se puede** complementar la transportadora ni comprobar estados.
- **Consecuencia [DECIDIDO]:** la transportadora es un campo **opcional** del tipo. Sin ella, el envío se guarda con transportadora **«sin determinar»** y se completa si un cargue posterior la trae sin ambigüedad. Si se informa, se resuelve por la equivalencia explícita de Logística (`bylopez_lg_proveedor_transportadoras`, igualdad exacta por código **o** nombre); sin equivalencia → la fila **no** se rechaza: el envío se guarda con la **transportadora original conservada** y pendiente (§15).
- **Conflictos:** una guía asociada a otro cliente → se rechaza (no se reasigna); misma guía con varias transportadoras y sin transportadora en la fila → se rechaza como ambigua.
- **[PENDIENTE]** cuando esté la exportación «P» (o cualquier Envíos que contenga estas guías), cargar «Envíos completos» o este tipo con la columna de transportadora para completar la transportadora efectiva.

## 4. Identidad y duplicados [DECIDIDO]

El archivo no trae documento ni correo, así que la **identidad fuerte es el teléfono** (como en Nueva venta: un teléfono normalizado = un cliente). El nombre **solo corrobora**; nunca une personas por sí mismo.

| Situación | Resultado |
|---|---|
| Teléfono no normalizable (E.164, reglas del proyecto) | **Rechazada** `TELEFONO_NO_VALIDO` |
| Nombre de una sola palabra (no se inventa apellido) | **Rechazada** `NOMBRE_INCOMPLETO` |
| El teléfono figura en varias fichas de cliente | **Rechazada** `TELEFONO_EN_VARIAS_FICHAS` |
| Cliente existente con ese teléfono y **nombre compatible** (todas las palabras del nombre más corto están en el más largo, sin tildes ni mayúsculas) | **Se completa** (dirección, antecedente) |
| Cliente existente con ese teléfono y nombre **distinto** (posible teléfono compartido) | **Rechazada** `CLIENTE_NOMBRE_DISTINTO` |
| Mismo teléfono con **nombres distintos dentro del archivo** | **Rechazadas todas** `TELEFONO_COMPARTIDO_EN_ARCHIVO` |
| Mismo teléfono y mismo nombre en varias filas (varias guías) | Una persona: la primera crea, las demás completan |
| No existe cliente con ese teléfono | **Se crea** |

- **Nombre y apellido** (`customers` los exige separados): convención documentada, **no verificada** — 2 palabras → 1+1 · 3 → 1 nombre + 2 apellidos · 4 o más → 2 nombres + resto. El nombre completo original queda en la auditoría del cargue (T6) y no se vuelve a copiar.
- **No se inventan:** correo (queda `NULL`), documento, contraseña (el cliente no tiene cuenta), ciudad ni departamento. Grupo del cliente: `cargues.clientes_historicos.grupo_codigo` (por defecto `general`; env `CARGUES_CLIENTES_HISTORICOS_GRUPO`); si el grupo no existe, **solo la operación de crear** queda bloqueada con un motivo (completar sigue funcionando).
- **Completar sin sobrescribir:** a un cliente existente solo se le **agrega** lo que falta (una dirección nueva, un antecedente); nunca se cambia su nombre, teléfono ni direcciones existentes.
- **Repetir no duplica:** la dirección se compara normalizada (texto + municipio); el envío es único por proveedor + transportadora + guía.

## 5. Arquitectura y frontera motor / tipo / servicios de Laravel

**Por qué hay una extensión.** Crear un cliente en este proyecto no es un `INSERT`: `CrearClienteComercialService` (VentasAsesor) normaliza el teléfono a E.164, mantiene el índice `bylopez_va_customer_phones` (único por número) y `customers.phone`, crea el cliente **sin cuenta** (`password` NULL, sin verificación), valida el grupo, usa el canal por defecto y dispara `customer.create.before/after`; `GuardarDireccionClienteComercialService` valida y resuelve ciudad/departamento/país **desde el catálogo DIVIPOLA** (`country_states` de Bagisto) y dispara `customer.addresses.*`. Replicarlo en SQL duplicaría reglas y las desincronizaría. **Se reutilizan los servicios**; no se omite ninguna regla.

**Motor genérico (opcional, sin cambiar los tipos existentes):**
- `Contracts/ExtensionTipo`: `derivar()` y `aplicar()`. Registro por nombre en `cargues.extensiones` (el map solo la **nombra**: nunca una clase escrita en el map).
- Map: `extension` y **`derivados`** (columnas extra de la intermedia que calcula la extensión; no se relacionan ni aparecen en el mapeo). `Esquema::columnas()` une `campos` + `derivados` para DDL, verificación, huella de entrada y llenado.
- `derivar` corre al **preparar** la carga (solo **lee** la BD): teléfono E.164 y estado, nombre/apellido, clave de nombre, cliente existente y compatibilidad, municipio DIVIPOLA (solo si es inequívoco) y transportadora efectiva.
- **VALIDAR** = el SP clasifica y marca rechazadas; **no escribe** clientes, teléfonos, direcciones ni antecedentes (probado: conteos idénticos antes/después; y la cuenta restringida solo puede actualizar la intermedia).
- **EJECUTAR**, en el mismo núcleo E2: bloqueo con nombre → CAS → `BEGIN` → `CALL … 'EJECUTAR'` (planifica: `cg_detalle` = `crear` | `completar`, sin marca final) → contrato (toda fila no rechazada con plan) → `COMMIT` → **`aplicar`**: **una transacción por fila** (cliente → dirección → antecedente → **marca de la fila en la misma transacción**). Un rechazo (identidad ambigua, guía de otro cliente…) **revierte esa fila entera**.
- **Reanudable e idempotente:** un fallo a medias deja `fallida_ejecucion` (`APLICACION_INTERRUMPIDA`/`[PARCIAL]` en la recuperación) con lo ya aplicado marcado; «Ejecutar de nuevo» procesa solo las filas pendientes. Mientras tanto el bloqueo con nombre se conserva.
- **Requisito de la frontera:** la intermedia debe estar en la **misma base** que la aplicación (así la marca y los efectos son una sola transacción). Si no, el tipo se niega con `EXTENSION_CONEXION_DISTINTA`.
- **Procedimiento** `sp_cg_clientes_historicos_v1` (`src/Database/Procedures/`): reglas R1–R7 de §4, `SQL SECURITY INVOKER`, escribe **solo** la intermedia (`tablas_negocio: []`; la revisión estática del contrato lo confirma). Map `…v1.map.json`: acción `importar_historico`, 9 campos relacionables y 11 derivados, llave primaria = número de guía (una guía repetida en el archivo rechaza todas sus copias).
- **No genera** pedidos, entregas operativas, pagos, abonos ni comisiones.

**Campos a relacionar** (el mapeo sugiere los de Cartera por sinónimos): `Destinatario`, `Teléfono`, `Dirección`, `Ciudad`, `Nº Guía`, `Estado Envío`. Opcionales y **sin relacionar** para Cartera: `Departamento`, `Transportadora`, `Fecha del estado`.

## 6. Datos nuevos (VentasAsesor; migración `2026_10_18_000001`)

- `bylopez_va_cliente_envios_historicos`: **un renglón por envío único** (`proveedor` + `transportadora_clave` ('' = sin determinar) + `numero_guia` exacto), con último estado literal, `fecha_estado` si existe, y procedencia (`primer_carga_id`, `carga_id`, `fila_origen`, `reportes`). FK a `customers` con **RESTRICT**.
- `bylopez_va_estados_envio_clase`: equivalencias **explícitas** estado → `entregado | devolucion | pendiente` con su **evidencia**. Sembradas solo las respaldadas por archivos reales: `Entregada`; `Devolución ratificada` y `Devolucion Regional` (devolución); `Admitida`, `Creado`, `Transito nacional`, `Reparto`, `Intento de entrega`, `Reclame en oficina` (en curso). **Todo lo demás es «desconocido»** (p. ej. «Confirmado por Cliente», los mensajes de WhatsApp): nunca se clasifica por parecido. La clase se calcula **al leer**: corregir una equivalencia corrige todos los conteos.
- **Reglas de actualización del mismo envío en varios archivos** (`AntecedentesEnviosClienteService::registrar`): el envío es de quien lo registró primero (otro cliente → conflicto, no se reasigna) · con fecha en ambos gana la **más reciente** (la anterior no retrocede; misma fecha y otro estado → conflicto) · un reporte **sin fecha comprobada** (con o sin fecha en el existente) **nunca** reemplaza un estado distinto: queda como «omitida» `SIN_FECHA_NO_REEMPLAZA` para revisión (§12.5) · un reporte con fecha sí reemplaza a uno sin fecha · el mismo estado no se suma · la transportadora desconocida se **completa** cuando llega.
- **Sin doble conteo:** los conteos son por **envíos únicos**; un envío que ya está en Logística (guía de un pedido del cliente con estado reportado) y también en el histórico se cuenta **una vez**.

## 7. Interfaz

- **Ficha del cliente** (`Clientes › Ver`): tarjeta **«Historial reportado por 99 Envíos — Entregados: N | Devoluciones: M»** (+ «En curso» y «Estado sin clasificar» si los hay), con el aviso «hechos reportados con su procedencia; no es una calificación, ni una entrega registrada o validada en Logística, ni un pago» y, desplegable, la evidencia por envío: proveedor, transportadora (o «sin determinar»), guía, estado + clase, fecha y **procedencia** (cargue y fila, o «Logística»). Se inyecta por el hook nativo de Bagisto (sin tocar Webkul), con el permiso del módulo. Sin antecedentes la tarjeta no aparece. **No hay calificación bueno/malo.**
- **Nueva venta:** al identificar un cliente existente por teléfono o correo (el momento previo a crear un duplicado) se muestra «Historial reportado por 99 Envíos — Entregados | Devoluciones» y un enlace «Ver ficha».
- **Búsqueda normal de clientes:** el cliente importado es un `customers` normal: aparece en el listado de Bagisto (por teléfono o nombre) y en la búsqueda de Nueva venta.
- **Cargues:** el tipo aparece como «Clientes históricos — 99 Envíos» con su versión; los derivados no aparecen en el mapeo; el Resultado por fila muestra creado / completado / sin cambio / rechazada con su motivo, y observaciones (p. ej. `UBICACION_AMBIGUA`) como subconjunto.

## 8. Ubicación [DECIDIDO]

Dirección, ciudad y departamento se guardan **solo** con un municipio DIVIPOLA inequívoco; el backend resuelve ciudad/departamento/país desde el catálogo (como Nueva venta). `CIUDAD/NOMBRE OFICIAL`: se prueba primero el oficial; si el archivo trae departamento, desambigua. Ambigua / no encontrada / sin ciudad → el cliente y el antecedente **sí** se guardan, la dirección **no**, y la fila queda con la observación `UBICACION_AMBIGUA` | `UBICACION_NO_ENCONTRADA` | `UBICACION_SIN_CIUDAD` (visible en el resultado). El código postal sigue la configuración de Bagisto (no obligatorio aquí); si se exigiera, la dirección se omite con `DIRECCION_NO_CREADA`.

## 9. Uso e instalación posterior en producción (NO ejecutado)

**Uso en dev:** `http://127.0.0.1:8000/admin/cargues` → «Nuevo cargue» → tarjeta **«Clientes históricos — 99 Envíos»** → subir `Cartera_Completa_…xlsx` (hoja `Data`) → relacionar (`Destinatario`, `Teléfono`, `Dirección`, `Ciudad`, `Nº Guía`, `Estado Envío`; se sugieren solos) → **Validar** (revisar rechazadas y observaciones; descargar «No cargadas») → **Ejecutar** (política «procesar las válidas»: se escribe el número de rechazadas) → Resultado. Clientes: `Clientes` (búsqueda por teléfono o nombre) → **Ver** (apartado de antecedentes).

**Instalar en producción (cuando Camilo lo decida; cada paso es reversible salvo la importación):**
1. Desplegar el código del paquete y **ejecutar las migraciones** (`2026_10_18_000001` crea las 2 tablas aditivas con las equivalencias sembradas). Ver que existan el catálogo DIVIPOLA (`bylopez_geo_*`, con `country_states` de Colombia) y el grupo de clientes `general` (o fijar `CARGUES_CLIENTES_HISTORICOS_GRUPO`).
2. En Logística, configurar la **equivalencia del proveedor** (`bylopez_lg_proveedor_transportadoras`) si la exportación trae transportadora.
3. Instalar el procedimiento: ejecutar `src/Database/Procedures/sp_cg_clientes_historicos_v1.sql` en la base de la aplicación.
4. Cargues › Configurar tipos › **Crear** «Clientes históricos — 99 Envíos» con ese procedimiento, acción `importar_historico`, política «procesar las válidas» y el contenido de `sp_cg_clientes_historicos_v1.map.json` → **confirmar el contrato** → **activar** (se crea la intermedia y se conceden SELECT/UPDATE e EXECUTE a la cuenta restringida: `php artisan cargues:proteccion-validar --aplicar`; ver la nota de `procs_priv` en doc 13 §11).
5. Reiniciar el worker de la cola `cargues` (`-d memory_limit=512M`).
6. **Ensayar con una muestra** (VALIDAR primero) antes de la carga completa; revisar rechazadas (teléfonos compartidos, ubicaciones ambiguas).
7. Antes de importar a gran escala: comprobar que la notificación al administrador por **cliente nuevo** (`emails.general.notifications…customer_registration_confirmation_mail_to_admin`) esté apagada o aceptada; el alta dispara el evento nativo `customer.create.after` por cada cliente.

## 10. Pruebas

**Suite aislada** (instancia MariaDB aparte, datos sintéticos): `ClientesHistoricosTest` **5/5** (activación con contrato sin errores y el SP escribe solo la intermedia; VALIDAR no escribe y rechaza por motivo; EJECUTAR crea, completa y deja antecedentes con conteos por envíos únicos; repetir no duplica; reglas de actualización del antecedente; aviso al identificar un cliente existente). Regresión del motor tras el cambio: Ejecución 9, Envíos completos 13, Configuración 25, Flujo 1, Auditoría y validación 18, Protección de VALIDAR 11, Lectura 21, Acceso y HTTP 15, Unit 28 — todas verdes; VentasAsesor `Rt04b1bClienteComercialTest` 28 verde.

**Dos pruebas acotadas en dev** (muestra de **9 filas reales** de Cartera copiadas a un archivo fuera del repositorio: 2 entregadas con ciudad única, una pareja con el mismo teléfono y nombre, una ciudad ambigua, una devolución ratificada, una regional y una pareja con teléfono compartido y nombres distintos; flujo real subir → relacionar → validar → ejecutar con el worker real):

| | Prueba 1 (cargue 7) | Prueba 2 — repetición (cargue 8) |
|---|---|---|
| Validar | 9 filas: 7 válidas, 2 rechazadas (`TELEFONO_COMPARTIDO_EN_ARCHIVO`); **0 cambios** en negocio | igual; 0 cambios |
| Ejecutar | 7 aplicadas (**6 clientes creados**, 1 completado), 2 rechazadas, 1 con observación (`UBICACION_AMBIGUA`) | **0 aplicadas, 7 sin cambio**, 2 rechazadas |
| Clientes / teléfonos indexados | 30→36 / 25→31 | 36→36 / 31→31 |
| Direcciones / antecedentes | 28→33 / 0→7 | 33→33 / 7→7 |
| Pedidos, entregas, pagos, comisiones | sin cambios (CHECKSUM idéntico) | sin cambios |

Ficha: los 6 clientes muestran su apartado con los conteos correctos por envío único (3 con «Entregados 1», 1 con «Entregados 2» —dos guías— y 2 con «Devoluciones 1»). El listado nativo de clientes los encuentra por teléfono y por nombre.

**Visual** (Chrome sin ventana, instancia aislada, datos sintéticos): ver §11.

## 11. Pendientes y límites

- **No se importó el archivo completo.** Los 6 clientes de la prueba 1 **permanecen en dev** como evidencia (ids 32–37). Para borrarlos hay que quitar antes sus antecedentes, direcciones e índice de teléfonos.
- **Transportadora efectiva** no disponible en Cartera (§3).
- **Teléfonos compartidos y 7 ciudades ambiguas** de Cartera requieren decisión humana (descargar «No cargadas» / revisar observaciones): el tipo no adivina.
- La convención nombre/apellido es una **aproximación** (§4); si Camilo prefiere otra, es una constante en `Nombres::partir`.
- El nombre del cliente existente se compara con una regla **estricta** (§15: solo mayúsculas, tildes y espacios); un existente con cualquier otro nombre (mismo teléfono) se rechaza para revisión.
- Los clientes importados **no** tienen correo ni documento: Bagisto no los exige; si se necesitan, se completarán desde otra fuente.
- La interfaz de Nueva venta solo se verificó por prueba de servicio y por la estructura de la vista; **no se abrió** esa pantalla en navegador (requiere un asesor y un borrador).
- La zona horaria de las fechas del archivo sigue pendiente (doc 13 §11).

## 12. Cierre de puntos antes de importar toda la Cartera (2026-10-19)

Comprobaciones dirigidas; **no** se importaron las 65 filas ni se ejecutó el cargue 6. Sin archivos externos: solo lo que está en el repositorio, en la BD y en las copias de referencia que quedaron en el scratchpad de sesiones anteriores.

### 12.1 La contradicción 65/65 ↔ 0/65 (archivos exactos)

Son **archivos distintos** que comparten el nombre base «Envios_Completos_2026-10-03» y el mismo reporte de 99 Envíos:

| Id | Archivo | SHA-256 (16) | Hoja · filas con guía | Fechas de envío | Guías de Cartera que contiene |
|---|---|---|---|---|---|
| **P** | `Envios_Completos_2026-10-03 (1).xlsx` (de «Descargas», analizado en una sesión anterior, doc 10 §0; copia de referencia en el scratchpad) | `b1669ad84b0873e6` | 152 | 2026-08-12 → 2026-09-30 | **65 de 65** (mismo estado y mismo valor comercial en las 65) |
| **A** | `Envios_Completos_2026-10-03.xlsx` (el primero que subió Camilo; cargue 1) | `355cf577f6cd3302` | 76 | 2026-09-14 → 2026-10-02 | **0** |
| **D** | `docs/Envios_Completos_2026-10-03.xlsx` (cargue 6) | `fb07fe4d84c7…` | `Envíos Completos` · 76 guías en 78 filas de hoja | 2026-09-14 → 2026-10-02 | **0** |
| C | `Cartera_Completa_2026-09-12.xlsx` | `53e0bdd7a7b34ace…` (16 449 bytes) | `Data` · 65 | — | — |

- **D = A + 2 filas sin guía** (una vacía y una de totales con fórmula): mismas 76 guías y **0 de 2 128 celdas distintas**. Además hay una versión de 25 columnas (`fd9b305664ce…`, cargues 4 y 5) y la de 1 fila de prueba (`e388f5fb6514…`, cargues 2 y 3).
- La Cartera es del 2026-09-12; **P** cubre hasta 2026-09-30 y las 65 guías son de agosto-septiembre, anteriores a la ventana de **A/D** (desde 2026-09-14). Por eso 0/65 contra el archivo del repositorio y 65/65 contra P: **ninguna** de las dos cifras estaba mal; el doc 13 §9 dice «las 65 guías están en la exportación P» y el doc 14 §3 (primera versión) comparó contra D.
- **Estado en la BD de dev:** 0 guías de Cartera en las intermedias de Envíos ni en `bylopez_lg_guias`; las 7 guías ya importadas existen solo como antecedentes (`bylopez_va_cliente_envios_historicos`).
- **[PENDIENTE]** P no está en el repositorio, así que no se puede completar la transportadora desde aquí. En P, las 65 guías traen `transportadora_nombre` (equivalencia ya configurada `99envios:1 → INTER_RAPIDISIMO`); **decisión de Camilo**: o aporta un archivo con esa columna para relacionarla en este tipo, o se importan sin transportadora («sin determinar»).

### 12.2 Llave del antecedente cuando falta la transportadora

- **Llave:** `UNIQUE (proveedor, transportadora_clave, numero_guia)`; `transportadora_clave = ''` significa «sin determinar: el archivo no la trae» (un `NULL` permitiría duplicados); desde §15, una transportadora informada **sin equivalencia** usa la clave `PEND:<huella>` y conserva el texto en `transportadora_original`. `numero_guia` es exacto (colación binaria).
- **Completar después** (`AntecedentesEnviosClienteService::registrar`): si llega la misma guía con transportadora y existe el renglón `''` **del mismo cliente**, es **el mismo envío**: se actualiza la clave de `''` a la transportadora; no se inserta otro renglón y **`reportes` no sube**.
- **No se une a otro cliente:** si la guía `''` es de otro cliente → `GUIA_DE_OTRO_CLIENTE` (conflicto; la fila se rechaza para revisión y nada cambia).
- **Otra transportadora** con la misma guía es **otro envío** (otra clave), nunca una unión; con dos transportadoras ya registradas, una fila sin transportadora es ambigua (`GUIA_AMBIGUA_TRANSPORTADORA`).
- **Conteos:** por envío único; completar la transportadora no cambia `total`, `entregados` ni `devoluciones`.
- **Prueba** (`ClientesHistoricosTest`, 2 pruebas «P2»): servicio y flujo completo por Cargues (Cartera sin transportadora → archivo con transportadora relacionada a mano): 1 renglón antes y después, clave `INTER_RAPIDISIMO`, mismos conteos de clientes, teléfonos, direcciones y envíos, resultado `aplicada · cliente_completado`.

### 12.3 Nombre completo original e identidad ambigua

- El **nombre completo original** se conserva en `bylopez_va_cliente_envios_historicos.nombre_reportado` (migración `2026_10_19_000001`; en dev se completó para los 7 antecedentes ya importados desde la intermedia del cargue 7), además de la auditoría original del cargue (T6). La ficha muestra «Nombre completo reportado por el proveedor» con la advertencia «el nombre y apellido de la ficha se separaron automáticamente: son una propuesta, no una identidad comprobada».
- **Teléfono compartido o identidad ambigua = para revisión, sin fusionar:** `TELEFONO_COMPARTIDO_EN_ARCHIVO` (mismo teléfono con nombres distintos en el archivo: se rechazan **todas** sus filas), `CLIENTE_NOMBRE_DISTINTO` (el teléfono ya es de otro cliente con otro nombre) y `TELEFONO_EN_VARIAS_FICHAS` aparecen en «No cargadas» con la fila original. Probado: no se crea nadie, no se toca al cliente existente y no se le agregan antecedentes.

### 12.4 Ubicaciones ambiguas = «Dirección pendiente de completar» (no rechazo)

- La fila se **aplica** (cliente y antecedente guardados) con la observación `UBICACION_AMBIGUA | UBICACION_NO_ENCONTRADA | UBICACION_SIN_CIUDAD` y el texto «Dirección pendiente de completar. Texto original: «dirección — ciudad». Motivo: …».
- Persistencia: `bylopez_va_cliente_direcciones_pendientes` (texto original de dirección, ciudad y departamento, motivo, cargue y fila; única por cliente + dirección + ciudad: repetir el archivo no duplica). Se retira sola cuando el cliente recibe una dirección equivalente.
- Visible en la **ficha del cliente** («Dirección pendiente de completar» con el texto original), en la búsqueda de **Nueva venta** y en el **Resultado por fila** de Cargues (complemento del tipo).
- Salida descargable **«Pendientes por completar»** (CSV/XLSX, `descargas/pendientes.{csv,xlsx}`): filas **aplicadas** (o sin cambio) con observación, con la fila de origen, el motivo y las columnas originales. **Separada** de «No cargadas» (solo rechazadas) y del Resultado completo; la pantalla dice «esas filas SÍ se aplicaron (no son rechazadas)». Probado: la pendiente sale en «Pendientes» y no en «No cargadas».

### 12.5 Qué fecha sustenta el estado

- **Cartera no trae fecha de estado.** `Fecha Consig.` es la fecha de **consignación** (un dato de pago): no está relacionada con ningún campo del tipo (el campo opcional `Fecha del estado` no tiene sinónimos) y **nunca** se usa como fecha de entrega. Verificado en dev: los 7 antecedentes importados tienen `fecha_estado` = NULL y la relación del cargue 7 deja `fecha_estado` sin columna.
- **Regla de actualización endurecida:** un reporte **sin fecha comprobada no sobrescribe** un estado distinto, ya sea que el existente tenga fecha o no (antes, entre dos sin fecha ganaba el último cargue: se cambió porque un archivo procesado después puede ser más viejo). Resultado: fila `omitida_antigua` con detalle `SIN_FECHA_NO_REEMPLAZA` (y observación visible); un reporte **con** fecha sí reemplaza a uno sin fecha; uno anterior no retrocede; misma fecha y otro estado → conflicto.
- Consecuencia práctica: re-importar una Cartera más nueva sin fechas **no cambiará** estados existentes; para actualizar hace falta un archivo con fecha del estado (p. ej. la columna `fecha_actualizacion` de Envíos completos, relacionándola a «Fecha del estado»).

### 12.6 Efectos del evento de creación (sin correos ni notificaciones)

- El alta dispara `customer.create.after`, que en este proyecto tiene **un solo listener** (`Webkul\Admin\Listeners\Customer@afterCreated`): encola un correo **al administrador** solo si `emails.general.notifications…customer_registration_confirmation_mail_to_admin` está activo. No hay listeners de `customer.create.before` ni de `customer.addresses.*`. En dev ese ajuste vale **0**, `MAIL_MAILER=log`, y tras la prueba 1 no hay jobs ni `failed_jobs` ni menciones de envío en el log.
- **Garantía en el código:** si el aviso está activo, **crear** clientes se bloquea (`CREAR_CLIENTE_BLOQUEADO_NOTIFICACION`; completar sigue funcionando) salvo `cargues.clientes_historicos.permitir_aviso_admin`. No se envía ni se encola nada desde un cargue. Probado con `Mail::fake()`: con el aviso apagado, 0 correos enviados o encolados y 0 notificaciones; con el aviso activo, el alta se bloquea y no se crea el cliente.

### 12.7 Verificación en navegador (instancia aislada, datos sintéticos)

Ver §13 (resultado de la prueba de Chrome para buscar, abrir la ficha y seleccionar en Nueva venta).

## 13. Verificación en navegador del punto 6 (2026-10-19)

Chrome sin ventana contra la **instancia aislada** con 2 clientes sintéticos (uno con dirección guardada y 3 envíos; otro con ciudad ambigua → dirección pendiente). **No** se abrió el navegador contra los datos reales de dev (los 6 clientes importados de la prueba 1 se comprobaron por HTTP interno, sin capturas, para no exponer datos personales).

| Paso | Resultado |
|---|---|
| **Buscar** en `Clientes` (listado nativo) por nombre | Aparece el cliente importado, con «1 Dirección(es)» (el de dirección pendiente muestra «0 Dirección(es)»). **El buscador rápido del listado no busca por teléfono** (comportamiento nativo de Bagisto: lo hace el filtro «Número de contacto», comprobado por HTTP) |
| **Ficha** del cliente (`Clientes › Ver`) | Nombre completo reportado + aviso de propuesta, **teléfonos** (principal, con el original si difiere), **dirección** guardada en la sección nativa, o **«Dirección pendiente de completar»** con el texto original, motivo y cargue/fila, y **antecedentes** (`Entregados: 2 \| Devoluciones: 1`; el otro cliente `Estado sin clasificar: 1`) |
| **Nueva venta** (`Ventas Asesor › Nueva venta`) buscando por teléfono | La búsqueda devuelve al cliente con su teléfono, «Historial reportado por 99 Envíos — Entregados: N \| Devoluciones: M», enlace «Ver ficha» y, si corresponde, **«Dirección pendiente de completar»**; al pulsar «Seleccionar» se abre la venta con el cliente (nombre, teléfono y grupo) |
| Errores de consola/HTTP | ninguno en ficha y Nueva venta |

Límites: tras **seleccionar** al cliente, la pantalla de venta muestra su teléfono y grupo, pero **no repite** los antecedentes ni la dirección pendiente (se ven en el resultado de la búsqueda y en la ficha); la selección creó un borrador de venta en la base aislada (no en dev).

## 14. Cambio de fuente: «Envíos completos» (2026-10-19)

### 14.1 Exportaciones disponibles (por hoja, columnas, fechas y hash)

El nombre del archivo **no** garantiza el contenido: varios se llaman «Envios_Completos_2026-10-03». Todas tienen una hoja `Envíos Completos` y, salvo indicación, 28 columnas (primer bloque `codigo_sucursal … estado_del_envio … aplica_contrapago … seguro` y un segundo bloque de columnas gemelas). Las fechas son las de `fecha_envio` / `fecha_actualizacion`.

| Id | Archivo | SHA-256 (12) | Filas con guía | Fechas de envío | Fecha del estado | Transportadora (código) | Teléfonos de 10 dígitos |
|---|---|---|---|---|---|---|---|
| **P** | `Envios_Completos_2026-10-03 (1).xlsx` (Descargas; copia de referencia en el scratchpad) | `b1669ad84b08` | **152** | 2026-08-12 → 2026-09-30 | 2026-08-13 → 2026-10-02 | `1`: 150 · `4`: 2 | 152 |
| A | `Envios_Completos_2026-10-03.xlsx` (cargue 1) | `355cf577f6cd` | 76 | 2026-09-14 → 2026-10-02 | 2026-09-15 → 2026-10-03 | `1`: 74 · `4`: 2 | 76 |
| D | `docs/Envios_Completos_2026-10-03.xlsx` (repositorio; = A + fila vacía + fila de totales) | `fb07fe4d84c7` | 76 (78 filas de hoja) | igual que A | igual que A | igual que A | 76 |
| A-csv | `Envios_Completos_2026-10-03.csv` | `e083a5c41d52` | 76 | igual que A | igual que A | igual que A | 76 |
| H1 | `Envios_Completos_2026-08-29.xlsx` | `dd2ef8546dff` | 47 | 2026-08-12 → 2026-08-29 | 2026-08-13 → 2026-08-29 | `1`: 47 | 47 |
| — | `ec_250617` (copia de referencia; origen en Descargas, no documentado en doc 10) | `17132629b636` | 137 | **2025**-02-11 → 2025-06-11 | 2025-02-14 → 2025-06-14 | **sin columna** (25 columnas) | 129 (8 inválidos) |
| — | `ec_250706` (ídem) | `acf1d43dbaa7` | 99 | 2025-06-11 → 2025-07-05 | 2025-06-11 → 2025-07-05 | **sin columna** (25 columnas) | 99 |

- **Cobertura:** H1 ⊂ P (47/47); A ∩ P = 64 guías; **A ∪ P = 164** (A aporta 12 guías que P no tiene); los dos archivos de 2025 no comparten ninguna guía con P ni con A. En total hay **400 guías distintas** entre P, A y los dos de 2025.
- **La más completa: P** (152 guías, 28 columnas **con** transportadora, contiene H1 y 64 de las 76 de A). No es *toda* la historia: A (12 guías nuevas) y los de 2025 (236 guías sin transportadora) la complementan; ninguno la sustituye. Orden sugerido: P → A → los de 2025 (el orden no altera el resultado porque los estados tienen fecha).
- **Género:** ninguna exportación trae una columna de género.
- **Archivo validado para tu prueba manual:** **carga 18** = P completo (hash `b1669ad84b0873e6`, 152 filas), **validado y sin ejecutar** (§14.6).

### 14.2 Qué hace el tipo con «Envíos completos» (versión 2 del procedimiento)

Mismo motor y misma extensión (no se rehízo el módulo). Cambios: fuente y mapeo, y reglas de nombre/transportadora/género.

- **Campos relacionables** (se sugieren solos; llevan el nombre exacto de las columnas del primer bloque para no chocar con las gemelas): `nombre_destinatario` → nombre completo; `telefono_destinatario`; `direccion_destinatario`; `ciudad_destino`; `numero_de_guia`; `transportadora` (código **o** nombre de 99 Envíos); `estado_del_envio`; `fecha_actualizacion` → **fecha del estado**; opcional `departamento` (no viene). `fecha_envio` **no** se usa como fecha de estado.
- **Clientes:** se crean los que no existen (teléfono principal indexado, sin correo, sin contraseña, **sin género**); a los existentes se les **completa solo lo que falta** (dirección nueva, antecedente). Identidad = teléfono (§4); el nombre solo corrobora.
- ~~**Variantes del mismo nombre** (subconjunto de palabras) son una persona~~ — **REEMPLAZADO por §15**: dos nombres con el mismo teléfono solo son la misma persona si son idénticos tras normalizar mayúsculas, tildes y espacios; palabras añadidas u omitidas → todas las filas a revisión (`TELEFONO_COMPARTIDO_EN_ARCHIVO` / `CLIENTE_NOMBRE_DISTINTO`), sin crear ni fusionar.
- **Ubicación:** `ciudad_destino` contra el catálogo DIVIPOLA; ambigua / no encontrada / sin ciudad → **dirección pendiente de completar** con el texto original (§12.4). En P: 140 ciudades únicas, **11 ambiguas, 1 no encontrada**.
- **Antecedentes:** por **transportadora efectiva + guía** (equivalencia `99envios` código/nombre → catálogo), con estado, **fecha del estado**, procedencia (primer y último cargue, fila) y nombre completo original; sin duplicar conteos (§6, §12.2). **No se exige un pedido** ni una guía en Logística para estas guías históricas (comprobado: ni se crea ni se consulta).
- **Transportadora sin equivalencia** (p. ej. el código `4`, coordinadora, que no está en el catálogo): **ya no se rechaza**; la fila se aplica con el envío bajo la clave `PEND:<huella del texto original>`, conserva el texto original (`transportadora_original`) y lleva la observación `TRANSPORTADORA_PENDIENTE` (sale en «Pendientes por completar»); si luego se configura la equivalencia y se repite, el **mismo** envío completa su transportadora (§15). Ya **no** se agrupa bajo «sin determinar».

### 14.3 Nombres (propuesta editable) y género

- **Se conserva el nombre completo original** (`bylopez_va_cliente_envios_historicos.nombre_reportado` + auditoría T6 + Resultado por fila).
- La separación en nombre y apellidos es una **propuesta** (convención en `Nombres::partir`), mostrada en la ficha con el aviso «no es una identificación segura» y **editable** con el «Editar» nativo del cliente.
- **Una sola palabra ya no se rechaza.** Bagisto exige `last_name` y el servicio de alta no admite un apellido vacío, así que el cliente se crea con el apellido **«(pendiente)»** (marcador explícito, no un apellido inventado) y se registra en `bylopez_va_cliente_datos_pendientes` (migración `2026_10_20_000001`) con el texto original; la ficha lo muestra como «Datos pendientes de completar» y **deja de mostrarlo solo** cuando el apellido deja de ser el marcador. La fila queda `aplicada` con la observación `APELLIDO_PENDIENTE`. En P no hay nombres de una palabra; está probado con datos sintéticos.
- **Género:** nunca se deduce ni se guarda por fórmulas sobre nombres; `customers.gender` queda `NULL` (probado: 14 clientes antiguos con género, 0 de los importados).

### 14.4 Datos ya importados de las pruebas con Cartera

Se **conservan** los 6 clientes y 7 antecedentes de la prueba 1 (cargue 7, procedencia Cartera). Importar «Envíos completos» los **complementa** sin duplicar ni borrar evidencia: los 7 antecedentes pasaron de «sin fecha y sin transportadora» a fecha de estado + `INTER_RAPIDISIMO`, **`primer_carga_id` sigue siendo 7** (procedencia original), el último cargue es el nuevo y `reportes` = 2; la ficha muestra «primer reporte: cargue #7». Clientes, teléfonos y direcciones no se duplicaron.

### 14.5 Dos comprobaciones acotadas en dev (muestra de 15 filas reales de P)

Muestra = 7 guías ya importadas desde Cartera + 4 clientes nuevos de ciudad única + 1 ciudad ambigua + 1 transportadora `4` + 2 filas con un mismo teléfono (copia exacta de las filas en un archivo fuera del repositorio). Flujo real subir → relacionar (8 campos sugeridos solos) → validar → ejecutar con el worker real.

| | Creación y complementación (cargue 13, regla anterior de nombres) | Repetición (cargue 17, ya con la regla definitiva) |
|---|---|---|
| Validar | 12 válidas, 3 rechazadas; negocio sin cambios | 14 válidas, 1 rechazada; negocio sin cambios |
| Ejecutar | 12 aplicadas (4 creados, 8 completados), 3 observaciones (ubicación ×2, transportadora ×1) | **0 aplicadas, 14 sin cambio**, 1 rechazada |
| Cambios | +4 clientes, +4 teléfonos, +3 direcciones, +5 antecedentes, +2 direcciones pendientes; pedidos, entregas, pagos y comisiones sin cambios | **0 en todas las tablas** |

(**Superado por §15:** la regla de «variantes» de esta frase ya no existe. Entre ambas se afinó la regla de variantes de nombre: el cargue 16 aplicó las 2 filas del teléfono repetido —1 cliente nuevo con 2 envíos— y el 17 confirmó que la repetición no cambia nada.)

### 14.6 Archivo completo validado (sin ejecutar)

**Carga 18**: P completo (152 filas, hash `b1669ad84b0873e6`), **validada**, versión 5 del tipo, plan: **149 válidas y 3 rechazadas** (`TELEFONO_COMPARTIDO_EN_ARCHIVO` ×2, `CLIENTE_NOMBRE_DISTINTO` ×1). Si se ejecuta: ≈ **117 clientes nuevos**, 17 filas sobre clientes ya existentes, 12 direcciones pendientes (11 ambiguas + 1 no encontrada), 2 envíos con transportadora pendiente y 14 guías que ya existían como antecedente (sin cambio o complemento). Las cifras son el **plan** de la validación (la ejecución vuelve a comprobar). **No se ejecutó.** El cargue 6 (Envíos completos → Logística) tampoco se ejecutó.

### 14.7 Pasos para probar manualmente

1. `http://127.0.0.1:8000/admin/cargues` → pestaña **Historial** → abrir la **carga 18** (validada, P completo). Revisar el resumen, el detalle por fila y «No cargadas». *(Si se prefiere subir el archivo de nuevo: «Nuevo cargue» → tarjeta «Clientes históricos — 99 Envíos» → `Envios_Completos_2026-10-03 (1).xlsx`; se sugieren 8 campos solos.)*
2. Pulsar **Ejecutar** y escribir **3** (rechazadas). Al terminar: ver el Resultado (aplicadas / sin cambio / observaciones) y descargar **«Pendientes por completar»** (direcciones ambiguas y transportadora pendiente) y **«No cargadas»** (3).
3. **Clientes** → buscar un cliente nuevo por nombre (el buscador rápido no busca por teléfono; usar el filtro «Número de contacto») → **Ver**: teléfonos, direcciones, «Dirección pendiente de completar», nombre completo reportado, antecedentes y su procedencia.
4. **Ventas Asesor › Nueva venta**: buscar por teléfono y comprobar el historial y los pendientes; **Seleccionar**.
5. **Repetir** el mismo archivo (otro cargue): debe dar todo «sin cambio» y no crear nada.
6. Antes de ejecutar a escala: el aviso por correo al administrador por cliente nuevo debe estar apagado (si no, crear se bloquea, §12.6).

Pendientes de esta fuente: cargar A (12 guías nuevas) y los de 2025 (sin transportadora); decidir la equivalencia de la transportadora `4` (coordinadora) y qué hacer con los 3 rechazados; revisar las 11 ubicaciones ambiguas y la no encontrada.

## 15. Ajuste posterior: asociación estricta de personas y transportadora original (2026-10-19)

Cambios respecto al reporte anterior (§14). Sin producción, Webkul, commit ni push; **no se ejecutaron las cargas 18 ni 6**.

### 15.1 Asociación de personas: solo mayúsculas, tildes y espacios [IMPLEMENTADO · PROBADO]
- `Nombres::compatibles` ya **no** compara por subconjunto de palabras: dos nombres son «el mismo» solo si son **idénticos tras normalizar mayúsculas, tildes y espacios** (`«  JUAN   PÉREZ »` = `«juan perez»`). No se reordena, no se quitan palabras ni signos: `Juan Pérez` ≠ `Juan Carlos Pérez` ≠ `Pérez Juan`.
- `Nombres::clave` (el derivado `nombre_clave`) dejó de ordenar palabras y de quitar signos.
- Efecto: mismo teléfono con nombres no idénticos dentro del archivo → todas las filas `TELEFONO_COMPARTIDO_EN_ARCHIVO` (a revisión, sin crear ni completar); teléfono de un cliente existente con nombre no idéntico → `CLIENTE_NOMBRE_DISTINTO`. El SP no cambió (R6 usa `telefono_nombres_ok`, que ahora se calcula con la regla estricta; la regla vive en la extensión).
- El apellido marcador «(pendiente)» no cuenta como parte del nombre al comparar con un cliente existente (`Luis (pendiente)` se compara como `Luis`); si alguien edita el apellido, el nombre de la ficha ya no coincide con un archivo que traiga solo `Luis`, y la fila queda para revisión.
- Prueba: `Nombres · SOLO se normalizan mayúsculas, tildes y espacios…` (7 casos: normalizado idéntico, palabra añadida con teléfono compartido, palabra añadida contra existente, otro orden, idéntico tras normalizar contra existente).

### 15.2 Revisión de las asociaciones hechas con la regla anterior (dev, solo lectura) [COMPROBADO]
Sobre los 14 antecedentes y los 12 clientes con antecedentes que hay en dev:
- **1 cliente** (id 42, cargue 16) tiene una asociación que la regla estricta **no** habría hecho: dos filas del mismo teléfono con nombres de 4 y 3 palabras (una incluida en la otra). Resultado de la regla anterior: 1 cliente con **2 antecedentes** (guías `…483026` y `…442754`), **2 direcciones** y 1 teléfono. Es una variante probable pero **no comprobada**.
- Los otros **11** clientes con antecedentes tienen todos sus nombres reportados idénticos al de la ficha tras normalizar (comparación estricta de cada antecedente con su ficha y de los reportados entre sí).
- **No se borró ni reasignó nada.** Decisión pendiente de Camilo: dejar el cliente 42 como está, o separar manualmente la guía `…442754` (hoy el sistema no tiene separación automática).
- Script de auditoría fuera del repo (sin nombres en la salida); la consulta se puede repetir.

### 15.3 Transportadora original sin equivalencia [IMPLEMENTADO · PROBADO]
- Migración aditiva `2026_10_20_000002` agrega `bylopez_va_cliente_envios_historicos.transportadora_original` (texto tal como llegó, ≤120).
- Una transportadora **informada sin equivalencia (o ambigua)** ya no se guarda como `''` («sin determinar»): la clave es `PEND:` + huella (12 hex) del texto normalizado, y el texto original queda en `transportadora_original`. Dos transportadoras sin equivalencia **distintas** con la misma guía son **dos envíos distintos**; el mismo texto (mayúsculas/espacios aparte) no duplica.
- `''` queda solo para «el archivo no trae transportadora». Sin transportadora y con varias registradas para la guía → `GUIA_AMBIGUA_TRANSPORTADORA` (no se elige).
- Cuando llega la equivalencia, la efectiva completa **solo** el envío pendiente cuyo texto original coincide (o, si no hay, el que estaba sin determinar); un envío ya resuelto no se cambia. El original se conserva siempre y se completa si faltaba (también en los envíos de Cartera: gana `transportadora_original = '1'`).
- Visible en la ficha («`«4» (sin equivalencia)`») y en el resultado por fila.
- Prueba: `Transportadoras sin equivalencia DISTINTAS con la misma guía…` y el test de TRANSPORTADORA_PENDIENTE (conserva el original, repetir no cambia, configurar la equivalencia completa el mismo envío).
- **Limitación conocida:** en la ficha, el antecedente con clave `PEND:` se une al envío de Logística por guía sola (igual que `''`), para no contar dos veces; si hubiera otra transportadora con la misma guía en Logística se vería como el mismo envío.

### 15.4 Revalidación de la carga 18 (archivo P completo, `b1669ad84b0873e6`) [COMPROBADO en dev, sin ejecutar]
VALIDAR no escribe negocio (clientes 41, teléfonos 36, direcciones 38, antecedentes 14, direcciones pendientes 3, pedidos 59: iguales antes y después).

| | Filas | Clientes únicos (teléfono) |
|---|---|---|
| Archivo | 152 | — |
| Válidas | **138** | **126** (114 nuevos + 12 sobre fichas existentes); 8 teléfonos traen 2+ envíos (12 filas extra) |
| Rechazadas | **14** | **7** teléfonos |
| · `TELEFONO_COMPARTIDO_EN_ARCHIVO` | 12 | 5 |
| · `CLIENTE_NOMBRE_DISTINTO` | 2 | 2 |
| Guías únicas válidas | 138 | 12 ya existen como antecedente |
| Ubicación (válidas) | 126 ok · 11 ambiguas · 1 no encontrada | — |
| Transportadora (válidas) | 136 con equivalencia · 2 sin equivalencia (código `4`, original conservado) | — |

Antes (regla de subconjunto): 149 válidas y 3 rechazadas. Las 11 filas que pasaron de válidas a rechazadas eran variantes de nombre que la regla estricta ya no une. **Siguen sin ejecutarse la carga 18 ni la 6.** Revisión manual de los 7 teléfonos: descarga «No cargadas».
