# 00 · Integración y publicación de la versión de preproducción

Revisión del 2026-11-16. Reúne el estado del repositorio, lo integrado, lo probado y lo pendiente antes de la fase de servidor. Este documento no contiene secretos, IP ni datos personales. Si cita un archivo con datos sensibles, solo dice dónde está.

## 1. Repositorio
| Dato | Valor |
|---|---|
| Remoto | `origin` → `github.com/andreylpz2596/lpz-saas` (**público**) |
| Rama de trabajo | `upgrade/bagisto-2.4.9`, que **no existía en `origin`**. Publicarla crea una rama nueva y no sobrescribe ninguna |
| Base | Bagisto v2.4.9 (`upstream`). 1.351 commits sin publicar: 75 propios de ByLopez y el resto, historia pública de Bagisto |
| Otras sesiones | worktree `lpz-saas-qa` en `qa/integral-preproduccion` (14084b55a5), contenido en esta rama. Sus documentos QA se quedan en esa carpeta |
| Sin publicar a propósito | `docs/despliegue/` (guía del VPS con datos del servidor; queda sin seguimiento) |
| Commit publicado | ver el informe de entrega y `git ls-remote origin upgrade/bagisto-2.4.9`. Un documento no puede contener el hash de su propio commit |

## 2. Funcionalidades integradas
Están todas en la misma rama, con su código, sus migraciones y sus documentos. Hay 293 migraciones en total, todas aplicadas en la base limpia r3, y 787 rutas.

| Módulo | Paquete | Rutas | Documentación |
|---|---|---|---|
| Corte y Confección (órdenes, consolidado, prestadores, cartera de cortadores y satélites) | `packages/ByLopez/CorteConfeccion` | 108 | `packages/ByLopez/CorteConfeccion/docs` |
| Ventas Asesor (Nueva venta, Cartera, crédito, comisiones, rentabilidad, cotizaciones) | `packages/ByLopez/VentasAsesor` | 71 | `packages/ByLopez/VentasAsesor/docs` |
| Logística (despachos, guías, recaudo, cuentas de transportadoras) | `packages/ByLopez/Logistica` | 46 | `packages/ByLopez/Logistica/docs` |
| Cargues (clientes históricos, envíos, inventario, productos) | `packages/ByLopez/Cargues` | 30 | `packages/ByLopez/Cargues/docs` |
| Lectura local de comprobantes (Tesseract) | Corte y Confección / Ventas Asesor | — | `docs/LECTURA_COMPROBANTES_PAGOS.md` |
| Base limpia y numeración nativa con sufijo `-2026` | — | — | `docs/limpieza-preproduccion/` |

`packages/Webkul/` es idéntico a la etiqueta v2.4.9, igual que los compilados de Bagisto. **No se modificó.**

## 3. Revisión de seguridad del historial que se publicará
Se revisaron los 75 commits propios en todas sus versiones, no solo el estado final.

| Revisión | Resultado |
|---|---|
| `.env`, claves, contraseñas, tokens, llaves privadas | Ninguno real. Solo valores de ejemplo y contraseñas ficticias de pruebas |
| Respaldos SQL o exportaciones | Ninguno (`*.sql` está ignorado; la exportación r3 está fuera del repositorio) |
| Comprobantes, soportes, guías, capturas | Ninguno. Los ejemplos de lectura de comprobantes son ficticios |
| IP o datos del servidor | Ninguno en lo publicable (`docs/despliegue/` queda fuera) |
| **Datos personales** | **3 hallazgos en 3 commits** (seis archivos de documentación y pruebas de Ventas Asesor): el nombre completo de una cuenta administrativa real, y el nombre, el apellido y una dirección parcial de una clienta real, usados como datos de prueba. Siguen en el estado final de varios archivos y en el historial |

Para quitarlos del historial hay que reescribir esos commits. La solución está preparada en §7, pero **no se publica sin autorización**.

## 4. Pruebas ejecutadas (bases exclusivas de prueba)
Todas corrieron sobre `lpz_saas_testing` (el `TestCase` raíz bloquea cualquier otra base) o sobre una instancia MariaDB aislada. Ninguna escribió en `lpz_saas` ni en la base limpia.

| Suite | Resultado |
|---|---|
| Corte y Confección | 572 correctas, 1 omitida (3.357 aserciones) |
| Ventas Asesor | ver el informe de entrega |
| Logística | 163/163 |
| Cargues (instancia aislada) | 182/182 (5.853 aserciones) |
| Instalación desde un clon nuevo | instalación correcta, 0 migraciones pendientes, vistas compiladas, recorrido autenticado de 23/23 pantallas |

**Cargues necesita su instancia aislada.** Debe ser un MariaDB en otro puerto (en esta revisión, el 3401), con su datadir dentro de una carpeta temporal y sin las bases `lpz_saas` ni `bagisto`. Pasos:
1. `mysql_install_db --datadir=<tmp>`, arrancar `mysqld --port=3401` y crear `lpz_saas_testing` y `cg_tipo_test`.
2. Correr `migrate` y el `DatabaseSeeder` del Installer.
3. Ejecutar con `CARGUES_PRUEBAS_AISLADAS=1` y `CARGUES_TEST_TIPO_DB=cg_tipo_test`.

Sin esas variables, las 182 fallan a propósito.

### Correcciones de esta revisión
No cambian reglas de negocio ni quitan funcionalidad.
1. **`tests/TestCase.php`**: `tearDown` vuelve a poner `set_time_limit(0)`. Tres servicios llaman a `set_time_limit`; en Pest el límite seguía vivo y cortaba las suites largas con «Maximum execution time» en una prueba que no tenía nada que ver.
2. **Tres pruebas de Ventas Asesor desfasadas** (`AccesoInicioTest`, `CierreAuditado02A2Test`):
   - El menú ya no admite una hoja sin su padre, porque la hoja `operar_como_asesor` también es la entrada «Inicio», y el árbol de roles marca los ancestros.
   - El esquema tiene más llaves foráneas desde ASESOR-01, pero la regla verificada (solo la ficha de asesor apunta a `admins`, con RESTRICT) se mantiene.
3. **Comentarios en Ventas Asesor**: tres comentarios citaban el nombre de un rol, y una prueba de arquitectura prohíbe nombres de rol en `src/`. No hay cambio de código.
4. **`.env.example`**: `APP_LOCALE=es`, `APP_CURRENCY=COP` y `APP_TIMEZONE=America/Bogota`. Con `en`/`USD`, una instalación nueva sobre la base limpia daba error 500 al entrar al panel.

## 5. Numeración y concurrencia
Se conserva la numeración nativa de Bagisto con el sufijo de factura `-2026`, sin generador propio. Detalle en `02_CONCURRENCIA_NUMERACION.md`.
- **Pedidos:** sin riesgo de número repetido, porque hay índice único y reintento.
- **Facturas:** **riesgo real de número duplicado**, demostrado con una sonda dirigida. **No está resuelto.** Las opciones quedan para que Camilo decida.

## 6. Base de datos compatible
- La exportación vigente es `lpz_saas_preproduccion_limpia_20261116_r3.sql` (SHA-256 `881666228fa6f9d819d733ef71e683726d60f1c44bfe8554cdf248e90fd37671`).
- Esta revisión **no cambió la estructura**: ninguna corrección agrega migraciones. Por eso r3 sigue siendo la compatible y no hay exportación nueva.
- `lpz_saas` queda intacta.
- Instalación: `01_INSTALACION.md`.

## 7. Saneamiento de datos personales (preparado, no publicado)
- Se preparó una rama local saneada con `git filter-branch --index-filter`.
- Solo reescribe los commits propios (`HEAD --not --remotes=upstream`) y reemplaza los nombres y la dirección por datos ficticios en los seis archivos afectados.
- Los commits de Bagisto conservan sus hashes. Los 75 propios cambian de hash.
- La rama original y el worktree QA no se tocan.
- Publicar la rama saneada en lugar de la original requiere la autorización de Camilo, porque cambia los hashes de commits que ya usa la rama QA local.

## 8. Pendientes reales
1. Autorizar la publicación de la rama saneada (§7).
2. Decidir sobre la duplicación de números de factura (§5).
3. Configurar roles y permisos, incluido BODEGA. No se tocaron.
4. Volver a subir el logo y el favicon del panel (`01_INSTALACION.md` §8).
5. Registrar el catálogo PDF de Brainstream: no está registrado en la base limpia.
6. Eliminar las bases auxiliares de desarrollo y pruebas, cuando se autorice.
7. Publicar `docs/despliegue/` sin datos del servidor, o mantenerla privada.
8. Llevar `spa.traineddata` (tessdata) al servidor si se usa la lectura local de comprobantes.
9. La tabla `mysql.db` está dañada en el XAMPP de desarrollo. No se reparó, por instrucción, y no afecta al servidor nuevo.

No se desplegó nada ni se modificó el servidor.
