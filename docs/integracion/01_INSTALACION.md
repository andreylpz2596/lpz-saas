# 01 · Instalación desde el repositorio con la base limpia

Pasos para la fase de servidor. Esta guía no contiene IP, dominios ni credenciales. La guía específica del VPS es otro documento, todavía sin publicar.

## 1. Qué se instala y con qué base

| Pieza | Valor |
|---|---|
| Código | rama `upgrade/bagisto-2.4.9` de `github.com/andreylpz2596/lpz-saas`, en el commit publicado que indica `00_INTEGRACION_Y_PUBLICACION.md` §1 |
| Base compatible | exportación privada `lpz_saas_preproduccion_limpia_20261116_r3.sql` (SHA-256 `881666228fa6f9d819d733ef71e683726d60f1c44bfe8554cdf248e90fd37671`). **No está en Git**; se entrega aparte con su `.sha256` |
| Compatibilidad | las 293 migraciones del código están aplicadas en esa base y no hay pendientes. Se comprobó con `migrate:status` sobre la base y sobre su restauración |
| Estado de la base | catálogo y configuración completos; clientes, operaciones e inventario vacíos o en cero; una sola cuenta administrativa; numeración nativa de Bagisto con sufijo de factura `-2026` (`docs/limpieza-preproduccion/00_ENTREGA.md`) |

## 2. Requisitos del servidor
- **PHP 8.3** (`composer.json`: `>=8.3 <8.5`). Extensiones: `calendar`, `curl`, `intl`, `mbstring`, `openssl`, `pdo_mysql`, `tokenizer`, además de las habituales de Laravel/Bagisto (`gd`, `zip`, `bcmath`, `fileinfo`, `xml`).
- **Composer 2**. Node no hace falta para instalar: los CSS y JS compilados se versionan (ver §6).
- **MariaDB**: en desarrollo se usa la 10.4; la guía del VPS propone la 10.11. Restaurar con `mysql --comments` (sin `--comments` se pierden los comentarios de los procedimientos de Cargues y estos rechazan las cargas).
- **Lectura local de comprobantes** (opcional pero integrada): `tesseract-ocr` con el idioma español y `poppler-utils` si se leerán PDF. En Debian/Ubuntu: `apt install tesseract-ocr tesseract-ocr-spa poppler-utils`. Detalle en `docs/LECTURA_COMPROBANTES_PAGOS.md`. Sin Tesseract, el registro manual sigue funcionando.
- Clonar en Windows exige `git config --global core.longpaths true`: hay rutas de migraciones de Bagisto de más de 260 caracteres. En Linux no aplica.

## 3. Código
```bash
git clone --branch upgrade/bagisto-2.4.9 https://github.com/andreylpz2596/lpz-saas.git <carpeta>
cd <carpeta>
git checkout <commit publicado>          # el de 00_INTEGRACION_Y_PUBLICACION.md §1
composer install --no-dev --optimize-autoloader
cp .env.example .env                       # completar (ver §4); NO correr key:generate sobre una base con datos cifrados
php artisan storage:link
```

## 4. `.env` mínimo
- `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, `APP_KEY` (nueva para una instalación nueva; ver la guía del VPS para producción).
- `APP_LOCALE=es`, `APP_CURRENCY=COP` y `APP_TIMEZONE=America/Bogota`, que ya vienen en `.env.example`. La base limpia solo tiene el idioma `es` y la moneda `COP`. Con `en` o `USD`, el ingreso al panel da error 500 (`Attempt to read property "direction" on null`); se comprobó en una copia nueva del repositorio.
- `DB_*` con un usuario propio de la aplicación (no root).
- `QUEUE_CONNECTION=database` (o la que se use) y `CACHE_STORE`/`SESSION_DRIVER` según el servidor.
- `DEBUGBAR_ENABLED=false`.
- Cargues: `CARGUES_VALIDAR_DB_USERNAME` / `CARGUES_VALIDAR_DB_PASSWORD`, la cuenta restringida que valida los procedimientos (`php artisan cargues:proteccion-validar`). Con `CARGUES_VALIDAR_EXIGIR_CUENTA=true` (por defecto), Cargues no valida sin ella.
- Comprobantes: `CC_COMPROBANTES_LECTOR=tesseract` (por defecto), y `CC_TESSDATA` si `spa.traineddata` no está en `storage/app/tessdata`. El servicio externo queda apagado (`CC_COMPROBANTES_ACTIVO=false`).
- Fechas de activación que ya están en la base de desarrollo, como `VENTASASESOR_COMISIONES_FECHA_INICIO` y `LOGISTICA_RECAUDO_DESDE_COBRO`: definirlas a propósito, con la fecha de arranque real.

## 5. Base de datos
1. Verificar la exportación: `sha256sum -c lpz_saas_preproduccion_limpia_20261116_r3.sql.sha256`.
2. Crear la base vacía y restaurar cambiando el nombre en las líneas `CREATE DATABASE` y `USE`: `mysql --comments --default-character-set=utf8mb4 < archivo`.
3. Comprobar:
   - 271 tablas y 8 procedimientos `sp_cg_*`.
   - `orders` = 0, `invoices` = 0, `customers` = 0 y `SUM(qty)` de `product_inventories` = 0.
   - `admins` = 1.
4. Correr `php artisan migrate:status`: debe dar 0 pendientes. Si el código publicado trae migraciones nuevas, aplicarlas con `php artisan migrate --force`, nunca con `migrate:fresh` ni `db:wipe`.
5. La cuenta de la aplicación debe poder **leer la definición de los procedimientos**, ya sea porque es su DEFINER o porque tiene `SELECT` sobre `mysql.proc`. Si no, Cargues rechaza toda carga con `PROCEDIMIENTO_CAMBIO`. Los procedimientos vienen con DEFINER `root@localhost`: revisarlo al restaurar.

## 6. Estilos y JavaScript
No hay que compilar nada al instalar:
- Los compilados de Bagisto (`public/themes/*/default/build`) son los oficiales de la v2.4.9: 0 diferencias con la etiqueta.
- La hoja de ByLopez (`public/themes/admin/default/build-bylopez/corteconfeccion.css`) está versionada y se reproduce idéntica con `cd packages/ByLopez/CorteConfeccion && npm install && npm run build`. Solo hay que recompilarla si cambian las vistas de ese paquete.

## 7. Procesos
- `php artisan optimize:clear` y después `php artisan optimize`.
- Worker de Cargues: `php artisan queue:work cargues --queue=cargues --timeout=900` (`retry_after` 1500 debe seguir siendo mayor que `timeout`).
- Worker de la cola por defecto (comisiones y correos): `php artisan queue:work`.
- Cron cada minuto: `php artisan schedule:run`. Ventas Asesor programa `ventas-asesor:comisiones:evaluar --bandeja` cada 5 minutos.

## 8. Archivos
- **Llevar:** las carpetas «Incluir» de `docs/limpieza-preproduccion/01_MATRIZ.md` (imágenes de catálogo, canal y configuración) y `storage/app/tessdata` si se usa la ruta por defecto. Las imágenes no están en Git. Sin `storage/app/public/product`, `category`, `channel` y `configuration`, el catálogo se ve sin fotos.
- **Logo y favicon del panel:** `core_config` apunta a dos archivos de `storage/app/public/configuration/` que **no existen ni siquiera en desarrollo** y devuelven 403. Hay que volver a subirlos desde Configuración › General › Diseño, o dejar el valor vacío. Es un dato de configuración y queda pendiente para Camilo.
- **No llevar:** archivos de cargues, soportes de cartera, guías ni comprobantes de desarrollo, ni las fotos de cuentas eliminadas.

## 9. Verificación después de instalar
1. Acceder con la cuenta conservada. Su contraseña la conoce Camilo; no está en ningún documento.
2. Abrir:
   - el panel;
   - Ventas Asesor › Nueva venta;
   - Corte y Confección;
   - Logística › Despachos;
   - Cargues;
   - Configuración › Ventas › Facturas, que debe mostrar el sufijo `-2026` y el generador vacío.
3. Antes de operar, configurar roles y permisos. Eso lo hace Camilo; esta fase no los cambió.
4. Orden de carga: usuarios y fichas → clientes (Cargues «Clientes históricos — 99 Envíos») → inventario (Cargues «Inventario — Actualizar cantidades»).
