# 10 · Seguridad y permisos (actualizado en la 2.ª etapa, 2026-11-09)

Método: lectura de código y consultas de solo lectura; sin pruebas de intrusión ni ejecución de flujos. **No se copiaron secretos ni datos personales**; solo rutas, nombres de archivo y naturaleza. Leyenda: **CONFIRMADO** (verificado con evidencia) · **DESCARTADO** · **NO PROBADO** (requiere prueba en Fase 5).

## 1. Veredicto sobre cada punto pedido

| Punto | Veredicto | Evidencia |
|---|---|---|
| Excel con datos personales | **CONFIRMADO (exposición potencial, sin filtración)** | 3 `.xlsx` en `docs/` con exportaciones reales de 99 Envíos (destinatario, dirección, teléfono — doc 15 de Cargues §1) + 9 capturas y un JSON de **comprobantes reales** en `CorteConfeccion/docs/confeccion/evidencia_2026-10-29/lectura_local_2026-11-02/` (su `LEEME.md`: «no subir a git»). Están **sin versionar y sin ignorar**: un `git add docs` o `git add -A` los incluiría. `git log --all` no registra que se hayan versionado jamás; el remoto `origin` es GitHub |
| Archivos sensibles rastreados por Git | **NO hay datos personales ni secretos rastreados** | `git ls-files`: solo `.env.example`, `.env.testing.example`, `docker/production/mysql-init.sql` (upstream), `VentasAsesor/tests/Demo/demo.env` (verificado: solo nombres de variables de demo, sin credenciales), `.xlsx/.csv` de ejemplo de Bagisto, `.sql` de procedimientos. Historial de `.env`: solo commits de 2022 del proyecto upstream |
| Rutas de Logística sin permiso (`admin.logistica.cuentas.propuesta`, `.cobro`) | **CONFIRMADAS, pero NO son una vulnerabilidad** | (a) El Bouncer de Bagisto **falla cerrado**: un rol personalizado recibe 401 en rutas sin clave ACL (`Bouncer::checkIfAuthorized`); solo `permission_type=all` pasa. (b) Los servicios revalidan: `ConsultaCuentasService::propuesta()` → `exigirVer($actor)`; `CobroReportadoService::registrar()` → `PERMISO_CUENTAS_LIQUIDAR` (y `CobrarEntregaService` → `PERMISO_VALIDAR`). (c) Ningún rol de dev tiene `logistica.cuentas.liquidar`; solo el administrador usa hoy esa pantalla. Es un defecto **funcional** (cuando se asigne el permiso a un rol personalizado, esas 2 acciones darán 401); prioridad P3 |
| Endpoints sin `throttle` | **CONFIRMADO, riesgo bajo** | 0 usos de `throttle` en ByLopez. Pero las 255 rutas exigen sesión de administrador (0 sin autenticar) y el login de Bagisto sí limita (`throttle:5,1` en `Admin/Routes/auth-routes.php`). Un abuso requeriría una cuenta válida; las operaciones de dinero son idempotentes y topadas por saldo. Prioridad P3 |
| Autorización real de operaciones críticas | **CONFIRMADA en backend** (lectura) | Servicios con comprobación propia, además del ACL de ruta: `RegistrarPagoService`, `ConfirmarPagoService`, `RechazarPagoService`, `RegistrarAbonoClienteService`, `LiquidacionesComisionService` (15 comprobaciones), `AplicarCredito/ReembolsarCredito/AjusteObligacion`, `ActualizarCostoComisionableService`, `DespachoService`, `ValidarEntregaService`, `RecibirDevolucionService`, `LiquidarCuentaService`, `AjusteCuentaService`, `CorregirGuiaService`, `CobrarEntregaService`, `RegistrarEventoEnvio` (base de entrega/retorno). `RegistrarEntregaService` hereda la comprobación de `RegistrarEventoEnvio` (`PERMISO_REGISTRAR`) |

## 2. Hallazgos nuevos de esta etapa (datos reales de dev)

| ID | Prioridad | Estado | Resumen |
|---|---|---|---|
| AUD-023 | **P2** | CONFIRMADO en dev; **verificar producción** | Existen **3 usuarios con rol `all`**: 2 en «Administrador» y **1 en un rol llamado «BODEGA» (id 12071) con `permission_type=all`**, además del rol «BODEGA» personalizado (id 4). Un usuario de bodega con todos los permisos puede registrar pagos, liquidar cuentas y ver costos. También hay 4 roles QA `all` sin usuarios y roles «CLIENTE FRECUENTE» con acceso al admin |
| AUD-028 | P3 | CONFIRMADO | Hay un segundo `bash.exe.stackdump` en `VentasAsesor/tests/Demo/visual/` (además del de la raíz) |

## 3. Otros controles (de la 1.ª etapa, sin cambios)

- Subidas validadas por contenido (PDF de guías por firma + `finfo`; comprobantes de comisión por extensión + MIME real + tamaño; pagos `mimes` + 10 MB; cartera de prestadores por MIME real en servicio) y guardadas fuera de `public`; respuestas con `nosniff` y `no-store`. Los discos `local` y `private` tienen `serve=true` (AUD-017).
- SQL crudo: sin interpolación de entrada del usuario en los `whereRaw/selectRaw` hallados (variables calculadas por el servicio; V-15 pendiente de confirmar tipos).
- XSS: 66 `{!! !!}`; muestreo con escapado `e()` o contenido construido en servidor (V-16 pendiente).
- CSRF: `@csrf` y cabecera `X-CSRF-TOKEN`; ningún `withoutMiddleware(VerifyCsrfToken)` en ByLopez.
- `$guarded=[]` solo en 7 modelos de Cargues (AUD-020). Logs sin datos sensibles (búsqueda de `Log::` con `request->all()`/contraseñas: 0).
- Lector externo de comprobantes (`LectorClaudeVision`): apagado (`CC_COMPROBANTES_ACTIVO=false`, lector por defecto local) (AUD-010).
- **NO PROBADO:** IDOR con dos usuarios reales (V-04); XSS completo (V-16).

## 4. Resumen de prioridades de seguridad (revisadas)

| ID | Antes | Ahora | Bloquea producción |
|---|---|---|---|
| AUD-003 datos personales sin cobertura | P1 | **P1** (confirmado) | Bloquea **commit/push** de esas rutas; no bloquea el servidor |
| AUD-007 sin throttle | P2 | **P3** | No |
| AUD-008 2 rutas sin ACL | P2 | **P3** (funcional, no vulnerabilidad) | No |
| AUD-023 rol BODEGA `all` | — | **P2** | No, pero verificar antes de abrir usuarios |
