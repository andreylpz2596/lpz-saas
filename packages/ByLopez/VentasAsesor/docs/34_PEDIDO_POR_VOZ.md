# 34 · Pedido por voz en Nueva venta

Estado: **implementado en develop y en pruebas** (2026-11-24), **sin servicios externos**. Decisión de Camilo: nada de API;
todo con reglas propias. La imagen pegada queda para una fase 2.

## 1. Cómo funciona

1. La asesora busca o crea el cliente en Nueva venta, como hoy.
2. Abre **«Dictar pedido»**, toca **🎤 Hablar** y dicta. El navegador pasa la voz a texto en un cuadro **editable**. Si el navegador
   no reconoce voz, se usa el micrófono del teclado del celular sobre el mismo cuadro.
3. **«Interpretar»** envía el texto al servidor. `InterpreteDictado` lo lee con reglas y `ResolutorDictado` valida cada línea contra
   el catálogo con el código único.
4. Se ve una lista con un estado por línea:
   - ✅ variante exacta;
   - ⚠️ resuelta asumiendo el género, porque el color y la talla solo existen en una línea;
   - ❌ con el motivo.
5. Cada línea se puede **corregir** con selectores (color, tipo Dama/Caballero/Niño/Crop top, talla del tipo, cantidad) que se
   validan otra vez contra el catálogo (`POST nueva-venta/dictado/corregir`), u **omitir**. Las líneas ❌ abren los selectores
   solas. Mientras quede una ❌ sin corregir ni omitir, no se puede agregar.
6. Después de agregar, la cantidad de cualquier talla se cambia con el lápiz ✏️ del producto en el resumen (modal solo de ese
   producto).
7. **«Agregar N líneas a la venta»** usa el mismo «aplicar tallas» de la matriz: un lote por producto, y la cantidad final es lo que ya había más lo dictado. Precio, stock y permisos son los de siempre. Si aún no hay venta, la primera prenda la crea, igual que con la matriz.

## 2. Qué entiende (InterpreteDictado)

| Pieza | Acepta |
|---|---|
| Color | Nombre exacto del catálogo (`bylopez_va_sku_colores` + etiqueta de la opción), sin importar tildes, en femenino o plural («roja», «negras»), con o sin «de» interno («palo rosa»). Una palabra que solo está en un color lo identifica («turquesa» → Azul Turquesa, «vino» → Vino Tinto). Una palabra de varios colores queda **ambigua** y se pide elegir («azul», «verde», «beige»). Alias en `ventasasesor.dictado.alias_colores` («rosa» → Rosado / Palo de Rosa). |
| Género | dama, mujer, señora, femenino → D · caballero, hombre, señor, masculino → H · niño, niña, infantil → N |
| Talla | S, M, L, XL, XXL **sin decir «talla»** y sin importar mayúsculas; «ese/eme/ele» = S/M/L (también sin «talla»); «equis ele» = XL; «doble equis ele», «2XL», «XXL» = XXL (**nunca baja a XL**; si la variante no existe se conserva XXL y se explica); niño 2-4, 6-8, 10-12, 14-16 («6-8», «6 a 8», «seis ocho», «6.8», «talla 6» = 6-8, siempre mostrada como 6-8); «única». |
| Cantidad | Cifras o palabras («quince», «treinta y dos», «doscientas», «diez y seis», «mil»), con o sin «unidades»/«cantidad»/«x»/«por». Entero de 1 a 9999; 0, negativos y decimales se rechazan. |
| «una» | «una camiseta…», «una XL…», «una ESE…», «una equis ele…», «una 2XL…», «una roja…» = **1 unidad**. «una referencia» **no** es cantidad. «una» + otra cantidad = contradicción. |
| Top | «top», «crop top» → línea TP, Dama, talla única (con aviso) salvo que se diga M. |
| SKU | El código completo: «124H-DZ-M 20», «124h dz m 20», «110N-DZ-6 10», «106D-TP-U 4». Cada SKU es una línea propia. Un código de color inexistente no inventa nada. |

**Cómo arma las líneas (dos pasos):**
1. Las piezas seguras (color, tipo, talla en letra, «talla …», rango, «N unidades», SKU) arman las líneas. Una pieza que la línea
   ya tenía la cierra; la siguiente hereda el color y el género no repetidos: «caballero negro M 10, L 5, XL 3 y blanco M 4» da 4
   líneas. Los números sueltos se guardan y **no** cierran líneas.
2. Cada línea reparte sus números sueltos entre la talla que falte y la cantidad. Solo se acepta una lectura que use todos los
   números; con dos lecturas se pregunta con **opciones** («¿68 es talla 6-8 o 68 unidades?»); un número nunca viaja a otra línea
   (salvo una cantidad dicha justo antes del producto siguiente, si es la única lectura posible).

**Decisiones de Camilo (2026-11-24):** A «una camiseta» = 1 · B «talla 6» = 6-8 · C «niño rojo 68» pregunta · D XXL nunca se
degrada · E un tipo dicho nunca se reemplaza; el tipo elegido en pantalla solo completa lo no dicho (`tipo_defecto`).

**Se pregunta, nunca se elige en silencio:** color ambiguo; falta talla o cantidad; número que puede ser talla o cantidad;
números sobrantes; cantidad inválida; dos cantidades («una camiseta … 5», «2 unidades 3 unidades»); dos tallas para el mismo
producto sin cantidad entre ellas («talla M talla L 3»).

## 3. Validación contra el catálogo (ResolutorDictado)

`número del color` + `D/H/N` + `-DZ-` o `-TP-` + `talla` → SKU exacto de la variante (Doc. 33). El ejemplo de Camilo da
110N-DZ-2, 110N-DZ-6 y 108D-DZ-S.

Mensajes:
- «Azul Rey Dama no tiene talla XL.»
- «No hay Rojo en Caballero.»
- «¿Cuál color? Azul Oscuro, Azul Rey…»
- «Di si es dama o caballero…»
- «Falta la talla / la cantidad.»
- «El producto … no está activo.»

## 4. Piezas

| Pieza | Archivo |
|---|---|
| Reglas | `src/Support/Dictado/InterpreteDictado.php` (sin base de datos) |
| Validación | `src/Services/Venta/Dictado/ResolutorDictado.php` (solo lectura) |
| Ruta | `POST nueva-venta/dictado/interpretar` (`NuevaVentaController::interpretarDictado`), con el permiso de Nueva venta (`ventas_asesor.pedidos.create`) |
| Pantalla | Panel «Dictar pedido» en `nueva-venta/index.blade.php` (Web Speech API, `es-CO`); no aparece en autoservicio |
| Configuración | `ventasasesor.dictado.activo` (`VENTASASESOR_DICTADO_ACTIVO`, activo por defecto) y `alias_colores` |
| Pruebas | `tests/Unit/InterpreteDictadoTest.php` (reglas) y `tests/Feature/Dictado01Test.php` (catálogo, ruta, permiso, pantalla) |

## 5. Límites conocidos

- La voz depende del navegador:
  - Chrome y Edge en computador y Android funcionan bien.
  - En iPhone (Safari), el dictado de Siri debe estar activo.
  - Con internet lento, el reconocimiento se corta: se puede seguir con el teclado.
- Solo productos del patrón del código único (camisetas Piel Durazno y Crop Top). Lo demás se agrega con la matriz.
- El selector de talla ofrece las tallas del tipo (S–XL, 2-4…14-16, Única), no solo las que existen en ese color: si no existe,
  «Validar» lo dice.
- Fase 2, sin hacer: pegar una imagen o captura del pedido. Sin API haría falta reconocimiento de texto local (Tesseract, como en los comprobantes), con menos exactitud en fotos.

## 6. Evaluación de herramientas de código abierto (2026-11-25)

Pregunta: ¿conviene cambiar las reglas propias por un analizador? Siempre sin LLM, embeddings ni IA externa.

| Herramienta | Licencia / estado | PHP 8.3 | Veredicto |
|---|---|---|---|
| phplrt 4.2.4 | MIT, activa (release 2026-10-16) | Sí la 4.x (la rama 5 pide 8.4) | Prototipo construido. **No se adopta** (ver abajo) |
| nearley 2.20.1 | MIT, sin publicar desde 2023-03 | No aplica (JavaScript) | Descartada: movería la regla al navegador o exigiría Node; sin mantenimiento |
| ICU (`intl`) SPELLOUT | Incluida en PHP; ICU 72 local, 74 en pruebas | Sí | **No se adopta**: «doscientas» → 2, «diez y seis» → 10, «una» → 1 (convertiría «una referencia» en cantidad) |
| Fuse.js 7.5.0 | Apache-2.0, activa, sin dependencias | No aplica (navegador) | Útil **solo** para sugerir nombres en el corrector, nunca para elegir. Aplazada hasta la interfaz con catálogo dinámico |

**Comparación con la misma matriz** (100 frases y 111 líneas esperadas, fijadas antes de correr). Grupos:
- 30 de la matriz del 24-11.
- 13 reglas obligatorias.
- 40 nuevas, redactadas para la evaluación (no son transcripciones de asesoras).
- 17 adversas, que buscan aceptar algo incorrecto.

| Métrica | Intérprete actual (ordenado) | Prototipo phplrt + ICU |
|---|---|---|
| Líneas correctas | 109 / 111 | 100 / 111 |
| Interpretaciones incorrectas aceptadas | **0** | **2** («talla M talla L 3», «niño 68 68») |
| Aclaraciones de más | 1 | 9 |
| Tiempo medio por frase | ~0,15 ms | ~4 ms (con el parser generado en proceso) |

- **Antes de ordenarlo**, el actual tenía 2 aceptaciones incorrectas y 6 aclaraciones de más. Se corrigieron así:
  - «una + talla» = 1.
  - Dos tallas y dos cantidades se preguntan.
  - «diez y seis» y «mil» se leen bien.
- **Las 2 diferencias que quedan** («rojo 10 niño», «rojo niño 2 3») son un conflicto de expectativas, no un error. Las adversas suponían que un «10» o «2» suelto con niño es talla, mientras que la matriz del 24-11 (aprobada antes) lo trata como cantidad y pide la talla. El sistema pregunta en ambos casos: es seguro. Decide Camilo.

**Por qué no se adopta phplrt:**
- La gramática solo resuelve el troceo en piezas, que ya es lo fácil.
- Todo lo difícil sigue en PHP igual que hoy: repartir los números, heredar entre líneas, detectar contradicciones y ofrecer opciones.
- Su elección ordenada (PEG) toma la primera lectura en silencio, lo contrario de «las ambigüedades deben dar opciones».
- Exige generar y commitear la clase del parser, más dependencias de desarrollo nuevas (laminas-code, symfony/console, twig).
- Añadir una regla no fue más fácil: tocó el token, la gramática y la semántica.

**Recomendación:** conservar el intérprete propio, ya ordenado. Mantener la matriz común
(`tests/Unit/InterpreteDictado*Test.php`) como contrato, y añadir frases reales de asesoras como grupo de control antes de
cada cambio de reglas.

## 7. Pendiente

Interfaz:
- Columnas Tipo | Color | Talla | Cantidad.
- Botones para elegir entre las opciones.
- Selectores con el catálogo real (`catalogo`) y «Si no digo el tipo» (`tipo_defecto`).
- «Ya hay 3 + dictadas 2 = quedarán 5».
- Disponibilidad y stock sin rebajar en silencio.

Micrófono:
- No borrar la edición manual.

Guardado:
- Reintento y doble clic seguros.

Pruebas:
- Pruebas de navegador con un usuario autorizado de pruebas.
- Audio real en iPhone y Android.
