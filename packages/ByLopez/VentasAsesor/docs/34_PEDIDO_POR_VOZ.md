# 34 · Pedido por voz en Nueva venta

Estado: **prototipo completo en develop y en pruebas.alpz.com.co** (jornada 2026-11-26). **Producción no se tocó**: espera la
aprobación de Camilo. La interpretación es **código y reglas propias** (sin LLM, embeddings, búsqueda vectorial ni APIs de IA).
La imagen pegada queda para una fase 2.

En pruebas se instaló con la rama **`integracion/pruebas-20261126`** = develop (dictado) + `responsive/iphone-2026-11` (ajustes
de iPhone de otra sesión que ya estaban instalados en pruebas), para no quitarlos. Quien vuelva a publicar
`responsive/iphone-2026-11` en pruebas debe partir de esa rama o integrar develop, o el dictado desaparece de pruebas.

## 1. Voz a texto e interpretación: dos cosas distintas

| Parte | Quién la hace | Qué hace | IA |
|---|---|---|---|
| Voz → texto | El **navegador** (Web Speech API: `webkitSpeechRecognition`, `es-CO`) o el micrófono del teclado del celular | Convierte el audio en texto dentro del cuadro editable | **No es nuestra y no se afirma que sea «sin IA».** Según MDN, en navegadores como Chrome el reconocimiento usa un motor en el servidor del fabricante (el audio sale del equipo). Safari en iPhone usa el dictado de Apple. ByLopez no controla ese motor. |
| Texto → líneas | **ByLopez** (`InterpreteDictado` + `ResolutorDictado`) | Reglas propias y el catálogo real con el código único | No: código y reglas. Sin servicios externos. |

La asesora puede **escribir** el pedido en el mismo cuadro: el micrófono es opcional y el flujo funciona igual sin él.

## 2. Cómo funciona

1. La asesora elige el cliente en Nueva venta y abre **«Dictar pedido»**.
2. Opcional: **«Si no digo el tipo»** (Preguntar · Dama · Caballero · Niño · Crop top). Solo completa lo que no se dijo; un tipo
   dicho siempre manda.
3. Dicta (🎤 Hablar) o escribe, **en el orden que quiera** («dos rojas M de dama», «roja dos dama M», «dama M roja dos» son lo mismo).
   La cantidad al final es una recomendación, no una condición.
4. **Interpretar**: el servidor lee el texto y valida cada línea contra el catálogo. **No escribe en la venta.**
5. Revisión (escritorio: columnas **Estado · Tipo · Color · Talla · Cantidad**; móvil: tarjetas). Cada línea muestra:
   - el **estado en texto**: Lista · Revisar · Elige una opción · Falta un dato · No existe · Sin stock suficiente · Corrigiendo ·
     No se pudo agregar · Omitida;
   - el **fragmento original** que se dijo («Dijo: «dos rojas M de dama»»);
   - de dónde salió el tipo: dicho, de la prenda anterior, por la talla de niño, por «Si no digo el tipo», o asumido por catálogo;
   - el **efecto en la venta**: «ya hay 3 + dictadas 2 = quedarán 5» (varias líneas del mismo SKU se suman: 3 + 2 + 1 = 6).
6. Lo dudoso trae **opciones concretas** («¿«68» es talla 6-8 o 68 unidades?» → botones «Talla 6-8» / «68 unidades»). Al elegir,
   se conserva lo válido y el foco va al campo que siga faltando; nada se completa solo.
7. **Corregir** abre selectores con el **catálogo real** (tipo, color y talla dependientes: Crop top M, XXL cuando existan). Al
   cambiar un atributo se revalidan los demás y la variante anterior deja de valer (no queda un SKU oculto). Una respuesta vieja
   de validación no pisa una corrección más nueva. **Omitir / Volver a incluir** por línea.
8. **Agregar** queda bloqueado (con el motivo escrito) si hay líneas por revisar, si todas están omitidas, si se está
   corrigiendo o si el micrófono sigue escuchando. Nunca se agrega solo al terminar de hablar.
9. **Agregar N líneas (M u.) a la venta** guarda **todo el dictado en una operación** (§5). La pantalla muestra lo que dice el
   servidor: «110D-DZ-M: había 2 + 3 = quedan 5». Después, el lápiz del producto en el resumen cambia cantidades sin tocar otros.

## 3. Qué entiende (InterpreteDictado)

| Pieza | Acepta |
|---|---|
| Color | Nombre del catálogo sin importar tildes, plural o femenino; compuestos completos (Azul Rey, Verde Botella, Azul Bebe Oscuro); «de» interno. Palabra de varios colores = **ambigua** (opciones). Alias en `ventasasesor.dictado.alias_colores`. Nunca se quita una palabra para convertirlo en otro color. |
| Tipo | dama/mujer/señora → D · caballero/hombre/señor → H · niño/niña/infantil → N · crop top / top → Crop top (siempre dama). Rojo/roja/rojas no deciden el tipo. |
| Talla | S, M, L, XL, XXL sin decir «talla» y sin importar mayúsculas; «ese/eme/ele» = S/M/L; «equis ele» = XL; «doble equis ele», «2XL», «XXL» = XXL (**nunca baja a XL**). Niño 2-4, 6-8, 10-12, 14-16 («6-8», «6 a 8», «seis ocho», «6.8», «talla 6» = 6-8); alias pegados 24/68/1012/1416 solo con contexto de niño. |
| Cantidad | Cifras o palabras («quince», «doscientas», «diez y seis», «mil»), con «unidades», «cantidad», «x», «por». Entero de 1 a 9999; 0, negativos, decimales (2.5 / 2,5, nunca truncados) y fuera de límite → se pregunta. |
| «una/un/uno» | Cantidad 1 delante de una prenda, talla, tipo o color («una XL dama roja», «una ESE…», «un caballero negro…»). «una referencia» no es cantidad. Con otra cantidad → contradicción. No se confunde con talla Única. |
| SKU | El código completo se busca **exacto** («124H-DZ-M 20», «124h dz m 20», «cinco 124H-DZ-M»). Incompleto («124H-DZ 5») pide la talla con las que existen. Con forma de código pero inexistente, de otra tela o con talla inválida → se marca. Seguido de un atributo que no coincide («124H-DZ-M 5 dama») → se pregunta. Los códigos de color sueltos no se aceptan (se confundirían con cantidades). |

**Cómo arma las líneas (dos pasos):**
1. Las **piezas seguras** arman las líneas; una pieza que la línea ya tenía la cierra. Los números sueltos se guardan.
2. Cada línea reparte sus números entre la talla que falte y la cantidad. Se acepta solo la **única lectura completa**; con dos se
   pregunta con opciones. Un número nunca vale por dos campos ni pasa a otra línea (salvo la cantidad dicha justo antes del
   producto siguiente, si es la única lectura). El último número es una pista, no una regla: «dos rojas niño talla 10» = 2 de 10-12.

**Reglas de la jornada 2026-11-26:**
- **Orden libre** sin contaminación: «una» delante de OTRO color abre la prenda siguiente («…tres negras L, una blanca S» ya no
  deja «Negro L × 1» aceptado).
- **Número suelto con niño** («rojo 10 niño», «rojo 2 niño»): se pregunta talla o cantidad y luego se completa lo que falte. Con
  otro número es la única lectura («rojo niño 10 5» = 10-12 × 5); dos lecturas completas («rojo niño 6 10») se preguntan; un par
  que forma rango («6 y 8») no se parte; un alias o un par ganan al número suelto («roja 68 niño 10» = 6-8 × 10). Si «Si no digo
  el tipo» es de adulto, un número suelto no se toma como talla de niño.
- **Correcciones**: «no», «mejor», «perdón», «digo», «o sea» cambian lo ÚLTIMO dicho con la siguiente pieza del mismo tipo
  («negro dama M diez, no, mejor ocho» = 8; «kaki, no, terracota»). «no negro, blanco» = blanco. Si no queda claro qué se corrige
  («…M 2, no» o «M 2 no blanco caballero…») se pregunta; no se cambia en silencio la prenda anterior.
- **Repeticiones**: la misma pieza dicha dos veces seguidas («negro negro», «M M») no crea productos ni contradicciones. Dos
  solicitudes completas iguales sí son dos líneas. Números iguales seguidos («68 68») se confirman (puede ser eco del dictado).
- **Tipo con alcance definido**: el tipo dicho vale para las prendas siguientes del mismo dictado hasta que se diga otro (marcado
  «de la prenda anterior»). Dos tipos distintos en una prenda se preguntan. Un tipo entre dos prendas completas: es de la
  siguiente si hubo pausa antes; de la anterior si la pausa fue después o si la siguiente ya dice el suyo; sin pausas, la
  anterior queda en duda (se pregunta) y la siguiente lo toma. Decir un tipo quita el crop top heredado.
- **Crop top**: la talla Única que se asume es blanda (una talla dicha después la reemplaza) y la decide el catálogo; la Única
  heredada de otra prenda también queda asumida. «Crop top» después de una prenda de niño o caballero es otra prenda.
- **Nada se pierde en silencio**: las palabras que no se usan (y no son de apoyo como «quiero», «dame», «por favor») se muestran
  («No usé: «camel»»). La línea guarda el fragmento original que se dijo.

## 4. Validación contra el catálogo (ResolutorDictado)

`número del color` + `D/H/N` + `-DZ-`/`-TP-` + `talla` → SKU exacto de la variante (Doc. 33). Un SKU dicho completo se busca tal
cual. La disponibilidad (`stock`, `gestiona_stock`) orienta; **nunca** se usa para adivinar la variante.

| Estado | Cuándo |
|---|---|
| `ok` | Una variante exacta. |
| `aviso` | Resuelta con algo visible: tipo asumido porque el color y la talla solo existen en una línea; crop top con una sola talla; palabras no usadas. |
| `error` + `interpretacion` | Falta o duda un dato. Trae `opciones` (talla/cantidad), `candidatos` (color), `opciones_talla` (tallas que existen para ese color y tipo) u `opciones_tipo`. |
| `error` + `catalogo` | La combinación o la referencia no existe, o el producto no está activo. |

Crop top sin talla dicha: si el color tiene M y Única → se pregunta («El crop top Negro existe en M y Única: elige la talla»); con
una sola → se toma con aviso. «Si no digo el tipo» acepta Crop top también en el servidor (`tipo_defecto` D, H, N o TP) y no
resuelve una duda de tipo.

## 5. Guardado seguro

`POST nueva-venta/dictado/agregar` → `NuevaVentaService::agregarDictado`:
- Cada línea se **resuelve otra vez** con el catálogo con sus atributos visibles; no se confía en un SKU o id de la pantalla.
- **Todo el dictado en una transacción**: o se agrega completo o no se agrega nada (no solo por producto). Si una variante falla
  (stock, producto, precio, permiso), se informa cuál y por qué, y la línea queda marcada.
- Se envían cantidades **a sumar** y el servidor suma sobre lo que **hay** en la venta, leído con el borrador y el carrito
  bloqueados y con lectura bloqueante. El bloqueo es la primera lectura de la transacción: con REPEATABLE READ, una foto tomada
  antes de esperar perdía la suma de un dictado simultáneo (la prueba DIC03-2 lo demostró: 3 + 2 + 4 quedaba en 7).
- **Idempotente**: cada «Agregar» lleva un identificador de operación; se registra en la misma transacción en
  `bylopez_va_dictado_operaciones` (UNIQUE admin + operación). Un reintento, una respuesta perdida o un doble clic simultáneo
  devuelven lo que ya se aplicó, sin sumar otra vez. Otra operación sí suma (solicitud intencional).
- Pantalla: un segundo clic mientras guarda no envía otra petición. Sin respuesta (red caída o error 5xx) → «No sabemos si se
  guardó» con **Reintentar** (misma operación) y la edición bloqueada. Una respuesta clara de error = no se guardó nada.
- Migración aditiva: `2026_11_26_000001_create_bylopez_va_dictado_operaciones_table` (solo su tabla).

## 6. Micrófono

Estados: inactivo → escuchando → (detenido · editado · sin voz · denegado · interrumpido). Cada inicio es una sesión; los
eventos de una sesión vieja o que llegan después de terminar se ignoran. El texto se arma por **índice de resultado**: un final
repetido del mismo índice no se duplica y dos frases iguales dichas a propósito (índices distintos) se conservan. Si la asesora
escribe mientras escucha, el micrófono se detiene y su texto no se reemplaza. Corregir una línea detiene la escucha. Sin soporte
o con permiso denegado se explica y se sigue escribiendo.

## 7. Piezas

| Pieza | Archivo |
|---|---|
| Reglas | `src/Support/Dictado/InterpreteDictado.php` (sin base de datos) |
| Catálogo | `src/Services/Venta/Dictado/ResolutorDictado.php` (solo lectura) |
| Guardado | `NuevaVentaService::agregarDictado` + tabla `bylopez_va_dictado_operaciones` |
| Rutas (permiso `ventas_asesor.pedidos.create`) | `POST nueva-venta/dictado/interpretar`, `…/corregir`, `…/agregar` |
| Pantalla | Panel «Dictar pedido» en `nueva-venta/index.blade.php` (clases propias `nv-dic-*`, claro y oscuro; en móvil la barra de «Agregar» queda fija mientras el panel está a la vista porque el contenedor de Bagisto impide el sticky) |
| Configuración | `ventasasesor.dictado.activo` y `alias_colores` |

## 8. Pruebas

| Tipo | Archivo | Qué prueba |
|---|---|---|
| Texto (desarrollo) | `tests/Unit/InterpreteDictadoRevisionTest.php`, `InterpreteDictadoTest.php`, `InterpreteDictadoMatrizTest.php` | Reglas y casos del brief |
| Texto (contrato) | `InterpreteDictadoMatrizComunTest.php` + `Datos/dictado_matriz_comun.php` | 100 frases, 111 líneas: cero aceptadas mal y las 111 correctas |
| Texto (evaluación ciega) | `InterpreteDictadoEvaluacionCiegaTest.php` + `Datos/dictado_evaluacion_ciega.php` | 80 frases **sintéticas** escritas antes de implementar (commit bc25bd5496) |
| Propiedades | `InterpreteDictadoPropiedadesTest.php` | Mayúsculas/espacios, 24 órdenes por prenda, otra prenda no cambia las anteriores, línea válida completa, ningún dato se pierde, determinismo |
| HTTP + catálogo | `tests/Feature/Dictado01Test.php`, `Dictado04ResolutorTest.php` | Resolutor, rutas, permisos |
| HTTP + guardado | `tests/Feature/Dictado02GuardadoTest.php` | Todo o nada, suma sobre lo actual, idempotencia, revalidación, permisos, interpretar no escribe |
| Concurrencia real | `tests/Concurrencia/DictadoConcurrenteTest.php` + `proceso_dictado.php` | Procesos PHP simultáneos: doble clic con la misma operación y dos dictados a la vez |
| Navegador | `tests/Demo/dictado/navegador_*.cjs` (demo local aislada, autenticación real) | Flujo completo, robustez de red, micrófono con eventos simulados, móvil 390 px, claro y oscuro |

Demo local: `tests/Demo/dictado/preparar_demo_dictado.php` sobre una copia de la base limpia de preproducción (sin clientes); la
clave de la asesora de prueba se escribe fuera del repositorio.

**Expectativas cambiadas por una regla aprobada en el brief del 2026-11-26** (cada una comentada en su prueba; no se cambió
ninguna para que pasara):

| Prueba | Antes | Ahora | Regla |
|---|---|---|---|
| `InterpreteDictadoRevisionTest` «ningún número pendiente…» y «números sobrantes…»; matriz «rojo niño 10» | «10» era cantidad y faltaba la talla | Se pregunta talla 10-12 o 10 unidades | «niño rojo 68», «rojo 10 niño» y «rojo 2 niño» → preguntar |
| `Dictado01Test` DIC01-10 «crop top azul oscuro 2» (catálogo con M y Única) | Aviso con 907D-TP-U | Error con opciones M / Única | «Crop top con M y U no asume Única» |

**Resultados con el código final (2026-11-26):**

| Grupo | Resultado |
|---|---|
| A orden libre + B casos del brief (texto, esperado copiado del brief) | 38 líneas: 37 exactas, 0 falsos positivos, 1 sin confirmar («208 blancas») |
| Unitarias del dictado (incluye C propiedades) | 134 pasan, 3 omitidas (las expectativas revisadas, con cobertura activa en otro archivo); salida 0 |
| Matriz común | 111/111 líneas, 0 aceptadas mal |
| F evaluación ciega | 1.ª corrida: 93/95 líneas, **1 falso positivo** (E41 «tres crop top lila M» → Única). Corregido: 94/94; E41 ya no es ciega |
| HTTP + catálogo | DIC01 12/12 · DIC04 4/4 |
| Guardado y concurrencia | DIC02 8/8 · DIC03 2/2 (procesos PHP simultáneos) |
| E regresión | ACL y menú 12 · seguridad de Nueva venta 6 · pantalla 4 · API 7 · Nueva venta 39: todas pasan, salida 0 |
| D navegador (demo local, autenticación real) | flujo 26/26 · robustez de red 10/10 · micrófono simulado + móvil 390 px 18/18 · humo 17/17 |

## 9. Evaluación de herramientas de código abierto (corregida el 2026-11-26)

Se comparó **A** (intérprete propio) con **B** (el mismo intérprete con el reconocimiento de piezas hecho por **phplrt 4.2.4**,
parser **generado** con `phplrt compile`). Mismos colores, alias, conversión de números (la propia, no ICU), catálogo, entradas y
expectativas.

- Las dos aceptaciones incorrectas del prototipo del 2026-11-25 («talla M talla L 3» y «niño 68 68») venían de **mi capa
  semántica de integración**, no de phplrt ni de ICU. ICU solo causó aclaraciones de más («doscientas» → 2, «diez y seis» → 10).
- B quedó **idéntico a A en 207/207 frases** (matriz común, evaluación ciega y frases de la jornada) después de corregir un defecto
  propio de integración: la gramática unía «2 y 3» en una pieza y el adaptador usaba solo el primer número, así que el «3» se
  perdía y B aceptaba «Negro M × 2» (falso positivo). El riesgo es estructural: si la segmentación de la gramática no coincide
  exactamente con las reglas en PHP, se pierden datos en silencio.
- Complejidad: B reemplaza `pieza()` (105 líneas) por adaptador (128) + gramática (127) + parser generado (1.091); el resto del
  intérprete (reglas de contexto, asignación, correcciones, ~1.700 líneas) sigue igual de necesario. Los colores salen del
  catálogo y no caben en una gramática compilada.
- Rendimiento: B ≈ 1,4 veces más lento (0,76 frente a 0,56 ms por frase; 65 frente a 49 ms con 3.000 caracteres).
- Dependencias: en producción `phplrt/runtime`; en desarrollo el compilador (trae laminas-code, symfony/console y twig) y
  regenerar el parser en cada cambio de gramática. phplrt 4.x corre en PHP 8.3; la rama 5 pide 8.4.
- nearley (JavaScript, sin publicar desde 2023) y Fuse.js (solo sugerencias en el navegador) no se reconsideraron.

**Decisión: conservar y mejorar el intérprete propio.** phplrt iguala la corrección pero no reduce la complejidad, agrega un
paso de compilación y una dependencia, y abre una clase de errores nueva. La matriz común, la evaluación ciega y las propiedades
quedan como contrato para cualquier cambio de reglas.

## 10. Límites reales

- **Audio real y iPhone físico no se probaron en esta jornada**: el micrófono se probó con eventos simulados; Chrome con ancho
  móvil no equivale a Safari en iPhone.
- En Android, el modo continuo de Chrome puede repetir frases en resultados nuevos (otro índice): no se puede distinguir de una
  repetición intencional, así que se ve como dos líneas y la asesora la omite en la revisión.
- Las frases de evaluación son sintéticas (no hay frases reales de asesoras todavía); hacen falta 30-50 reales como grupo de control.
- La intención de «208 … blancas» sigue sin confirmar (se lee como cantidad 208; no se presenta como acierto comprobado).
- El cruce matriz/dictado al mismo tiempo sobre la misma talla: la matriz fija cantidades finales (la última escritura gana), el
  dictado suma; es el comportamiento esperado de cada una.
- Pendiente de Camilo: aprobar producción (migración + rutas + pantalla) y frases reales.
