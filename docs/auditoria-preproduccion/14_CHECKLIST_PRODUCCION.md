# 14 · Checklist de producción y criterios de validación (Fases 5–6, diseñados, no ejecutados)

## 1. Requisitos previos
- [ ] AUD-001 cerrado: release etiquetado, `git status` limpio, 0 migraciones/SP fuera de Git.
- [ ] Motor definido (mismo que el probado) y ensayo de restauración realizado.
- [ ] PHP 8.3, extensiones de `composer.json`, OPcache, `upload_max_filesize`/`post_max_size` ≥ límites de app, `max_execution_time` acorde.
- [ ] `.env` de producción: `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` propio, `APP_URL` https, cookies seguras, `CC_COMPROBANTES_ACTIVO=false`, sin `ANTHROPIC_API_KEY`.
- [ ] Cron `schedule:run` cada minuto y worker supervisado (cola `cargues` y default).
- [ ] 2FA para administradores; rol Cargues (`CARGUES_ROL_ADMIN_ID`) correcto.

## 2. Copia de seguridad verificable
- [ ] Volcado completo + `storage/app` (privados), con suma de verificación.
- [ ] Restaurado en un entorno de ensayo y comparado (conteos por tabla `bylopez_*`, Σ de libros: pagos, comisiones, cuentas, cartera de prestadores, stock).

## 3. Aplicación
1. Modo mantenimiento. 2. `composer install --no-dev -o`. 3. `npm ci && npm run build` (admin; CSS propio). 4. `php artisan migrate --pretend` revisado → `migrate --force`. 5. Instalar SP de Cargues. 6. `config:cache route:cache event:cache view:cache`. 7. Salir de mantenimiento.

## 4. Comprobaciones de salud y humo (en producción, con datos de prueba marcados)
- [ ] Login admin con 2FA; menús por rol (asesor ve solo lo suyo).
- [ ] **Venta mínima:** cliente → producto/color/talla → precio por grupo → entrega → pago → confirmar → un solo pedido; doble clic no duplica.
- [ ] **Inventario:** stock antes/después del despacho; despacho repetido no descuenta dos veces.
- [ ] **Cartera:** registrar pago, confirmar, saldo del pedido; rechazo no aplica.
- [ ] **Comisiones:** pedido entregado+recaudado causa una vez; abono parcial; reverso.
- [ ] **Logística:** guía con PDF privado (acceso del asesor propietario sí; otro asesor no).
- [ ] Cargues: solo un cargue sintético de VALIDAR (sin EJECUTAR sobre datos reales).
- [ ] `cargues:diagnostico` y `schedule:list` muestran worker y cron vivos.

## 5. Monitoreo posterior
Logs (`laravel.log` rotado, nivel `warning`), alertas de cola fallida (`failed_jobs`), espacio en disco, bandeja de incidencias de comisiones, reporte diario de reconciliación (AUD-021), tiempos de la vista de comisiones, errores 401/403 inesperados.

## 6. Rollback
Aplicable si el humo falla: mantenimiento → restaurar volcado → desplegar etiqueta anterior → cache clear. **No usar** `migrate:rollback` (los `down()` borran tablas). Conservar los archivos subidos tras el despliegue.

## 7. Criterios de «listo para producción»
1. AUD-001 cerrado. 2. Modo SQL decidido con evidencia (suites de Bagisto y ByLopez medidas bajo modo estricto de sesión; `''`→`null` corregido; 11 fechas `0000-00-00` normalizadas con autorización). 3. Pruebas de concurrencia de dos procesos pasan (abonos, ventas con stock, recepciones, despachos del mismo pedido). 4. IDOR con dos usuarios sin fugas. 5. Procedimientos de Cargues instalados y verificados; cron y worker `cargues` vivos; roles `all` revisados. 6. Restauración de backup demostrada. 7. Checklist §4 completo. 8. Decisiones pendientes que bloquean (doc 15) respondidas. 9. Webkul sin cambios (`git status packages/Webkul` vacío).

## 8. Suites propuestas (a ejecutar solo en BD exclusiva `lpz_saas_testing`, secuenciales, **no** en dev)
VentasAsesor: Com*, Pag*, Fase*, Concurrencia; Logística y CorteConfeccion: sus carpetas `tests`; Cargues: 20 archivos. Registrar resultado por suite y causa de cada falla antes de comparar con la línea base del 02/11 (`storage/logs/regresion/reg_resumen_20261102.txt`).
