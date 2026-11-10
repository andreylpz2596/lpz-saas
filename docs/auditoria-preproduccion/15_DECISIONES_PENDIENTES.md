# 15 · Decisiones pendientes

## A. Para aprobar esta auditoría
1. ¿Aprueba el plan del doc 13 y en qué orden (propuesta: C-01 → C-02 → C-03…)?
2. **C-01** requiere saber quién versiona qué: la rama contiene trabajo de varias sesiones (D10, Cargues v3/productos, Cartera de prestadores, Consolidado, Cotizaciones). ¿Se commitea por objetivo con confirmación de cada responsable?
3. ¿Se pueden mover fuera del repositorio `docs/*.xlsx` (datos personales), los `.rar` y `grep.exe.stackdump`?

## B. Técnicas de producción
4. Motor y versión de base de datos del hosting (¿MariaDB 10.4 actual o MySQL/MariaDB más reciente?).
5. Servidor: ¿se usará la imagen Docker del repositorio, XAMPP/Windows u otro? (cron, worker, Tesseract/Poppler dependen de ello).
6. ¿Se activa `strict=true` antes de publicar (recomendado) y se acepta corregir lo que aparezca?
7. ¿La lectura de comprobantes debe quedar solo local (Tesseract) o desactivada en producción? ¿Se elimina el driver externo?
8. Política de copia de seguridad (frecuencia, retención, ubicación).

## C. De negocio (ya abiertas en el proyecto; esta auditoría no las cambia)
9. D1–D12 de comisiones/dinero (D3, D4, D5, D6, D10, D12 siguen pendientes según memoria); políticas de precio mínimo (bloqueo/fecha) permanecen **apagadas**.
10. Asignar `view_own` al rol real «ASESOR VENTAS» y las claves D10 a roles reales.
11. 99 Envíos: ¿se modela como intermediario con «transportadora efectiva»? ¿Qué se compara en la conciliación de Cartera y qué se hace con guías «sin vínculo»? (doc 15 de Cargues §5).
12. Catálogo de transportadoras: ¿se agregan Coordinadora, Servientrega, Envía o se mantienen bajo «Otra»?
13. Catálogo/variantes V2: 13 colisiones familia+color+talla; N1 (tarifas y pagos a talleres) y H11.
14. Com03·COM03-1: ¿se ajusta la regla o la prueba?

## D. Validaciones que requieren permiso para ejecutarse
15. Pruebas de dos procesos reales (C-08) y suites con `strict` (C-03) usan la BD `lpz_saas_testing` y escriben datos: ¿se autoriza su ejecución en la Fase 5, con la base reservada a esta tarea?

## E. Agregadas en la 2.ª etapa
16. **99 Envíos e Inter Rapidísimo sin cuenta corriente (AUD-026):** ¿se les activa la capacidad de cuenta y quién es el deudor por envío (99 o la transportadora física)? Activarla no duplica deuda (el libro es por envío), pero antes conviene un campo «transportadora efectiva» (AUD-027).
17. **Archivos con datos reales:** ¿se mueven fuera del repo los 3 `.xlsx` de `docs/` y las evidencias de `lectura_local_2026-11-02`, y se anonimizan para versionarlas?
18. **Fechas `0000-00-00`** (5 órdenes de corte y 4 rollos): ¿se autoriza normalizarlas a `NULL` con un script revisado? (cambia datos históricos).
19. **Rol «BODEGA» con permisos `all` (id 12071)** y roles QA `all`: ¿se revocan en dev y se verifica producción?
20. **Propiedad del trabajo sin versionar:** ¿quién confirma cada línea (D10, Cotizaciones, Com08/avance, Cargues v3/camisetas, Reasignación, Cartera de prestadores, Comprobantes, Consolidado) y se aprueba el orden de commits del doc 13?
21. **Motor de producción:** MariaDB 10.11 LTS o MySQL 8.0/8.4 (confirmar contra la documentación de Laravel 12/Bagisto 2.4) y ensayo de migración desde 10.4.28.
22. **Reevaluación de comisiones:** ¿se queda en la petición (`sync`) o se saca a un worker (`VENTASASESOR_COMISIONES_COLA_CONEXION`)?
