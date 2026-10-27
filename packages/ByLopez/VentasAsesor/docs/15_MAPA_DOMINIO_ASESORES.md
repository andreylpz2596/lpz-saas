# 15 · Mapa del dominio de Asesores

**Fecha:** 2026-10-07 · **Fase:** COM-00 (auditoría, sin código) · **Responsable funcional:** Camilo Gaitán

> Documento permanente. Explica **quién es un asesor, cómo recibe una venta, qué puede ver y qué
> datos deja para comisiones**, con evidencia del código actual. Sirve para Claude, Codex y ChatGPT
> sin depender del chat. Si una afirmación de este documento contradice el código, **manda el
> código** y este documento debe corregirse.

Referencias: `[D-xx]` → `04_DECISIONES_PENDIENTES.md` · `[T-§n]` → `05_DISENO_TECNICO_CREACION_PEDIDOS_ASESOR.md` ·
`[M-§n]` → `02_MODELO_FUNCIONAL_VENTAS_ASESOR.md` · `[COM-§n]` → `16_AUDITORIA_COMISIONES.md`.

---

## 1. Resumen en 10 líneas

1. Un asesor **es un usuario de Bagisto** (`admins.id`). No existe una segunda identidad.
2. Su rol debe tener la clave ACL `ventas_asesor.operar_como_asesor` [D-06].
3. Además necesita un **perfil de asesor activo** en `bylopez_va_asesores`, 1:1 con el usuario,
   con `admin_id` único e inmutable [D-60, T-§43].
4. Sin perfil activo, quien vende como asesor **falla cerrado** (`asesor_sin_asociacion`).
5. Al confirmar una venta, la atribución guarda `advisor_id = admins.id` y un snapshot del nombre
   en `bylopez_va_order_attributions` (1 fila por pedido, `UNIQUE(order_id)`) [D-05].
6. El administrador `all` **nunca** recibe la venta automáticamente [D-27].
7. Toda reasignación deja una fila inmutable en `bylopez_va_advisor_assignment_logs`.
8. `view_own` compara `advisor_id` con el usuario en sesión; `view_all` ve todo [D-13].
9. Un perfil **nunca se borra**: se inactiva. La historia sigue apuntando al mismo `admins.id`.
10. Comisiones (futuro) debe leer `advisor_id` + snapshot de la atribución, **nunca** el perfil
    activo [COM-§11].

---

## 2. Identidad: tres piezas, una sola persona

| Pieza | Dónde vive | Qué aporta | Mutable | Evidencia |
|---|---|---|---|---|
| Usuario | `admins` (Bagisto) | Identidad, login, correo, `status`, `role_id` | Sí (Bagisto) | core |
| Marcador de asesor | Rol con `ventas_asesor.operar_como_asesor` | «Puede operar como asesor» | Sí (panel de roles) | `AutorizacionVentasAsesor::esAsesor()` |
| Perfil de asesor | `bylopez_va_asesores` | Nombre visible, teléfono, `activo` | Nombre/teléfono/activo sí; `admin_id` **no** | migración `2026_10_07_000014`, `GuardarAsesorService` |

**Identidad comercial = `admins.id`.** El perfil no tiene identidad propia para el negocio: su `id`
interno no se guarda en ningún pedido. Esto conserva intacto todo lo construido antes (atribución,
`view_own`, cartera, pagos).

> **Contradicción histórica resuelta.** D-06 (Fase 00B) decía «no se crea `bylopez_va_advisors`
> ni tabla de perfiles». D-60 (ASESOR-01) creó `bylopez_va_asesores` **sin** cambiar la identidad:
> el perfil complementa al usuario y no lo sustituye. D-06 sigue vigente en lo esencial (el asesor
> se identifica por ACL + `admins.id`); solo quedó superada la frase «sin tabla de perfiles».

### 2.1 Tabla `bylopez_va_asesores`

| Campo | Tipo | Regla |
|---|---|---|
| `id` | PK | Interno; no se usa como identidad comercial |
| `admin_id` | int unsigned, **UNIQUE**, FK `admins` RESTRICT | Inmutable: un usuario tiene como mucho un perfil en toda su vida |
| `nombre` | varchar(120) | Nombre visible comercial |
| `telefono` | varchar(30), null | Nunca se inventa |
| `activo` | bool, default true | Inactivar bloquea **nuevas** ventas; no toca la historia |
| `creado_por_admin_id` | null, sin FK | Auditoría |
| `created_at`, `updated_at` | | |

### 2.2 Servicios del dominio

| Servicio | Responsabilidad | Lee / escribe |
|---|---|---|
| `Support\AutorizacionVentasAsesor` | Fuente única de permisos y alcance (`esAsesor`, `esAsesorParaAtribucionAutomatica`, `puedeVerRegistroDe`, `puedeVerCarteraDe`) | Lee ACL |
| `Services\Asesores\AsesorActualService` | Asesor en sesión: `asesorDe()` (perfil **activo** o null), `requierePerfil()`, `operable()` | Lee |
| `Services\Asesores\GuardarAsesorService` | Alta, edición de nombre/teléfono, activar/inactivar; `AsesorInvalidoException` | Escribe perfil |
| `Services\Asesores\ConsultaAsesoresService` | Listado y ficha con métricas por lote | Lee |
| `Services\ResolverAtribucionService` | Decide propiedad comercial al crear (reglas A–D) | Lee |
| `Services\RegistrarAtribucionService` | Persiste la atribución con idempotencia | Escribe atribución |
| `Services\AsignacionAsesorService` | Reasignar / marcar empresa / dejar sin asignar, con `lockForUpdate` y motivo | Escribe atribución + log |
| `Services\HistorialAtribucionService` | Historial inicial e inmutable de cada cambio | Escribe log |
| `Support\Cartera\AlcanceDePagos` | Alcance de pagos por `advisor_id` | Lee |

---

## 3. Ciclo de vida del asesor

```text
Usuario Bagisto (activo)
  → rol con operar_como_asesor (p. ej. «ASESOR VENTAS»)
  → perfil en bylopez_va_asesores (activo = 1)
  → OPERABLE: crea borradores y confirma ventas; recibe la atribución automática
  → inactivar perfil: deja de operar (nuevas ventas rechazadas, también entre borrador y confirmación)
  → reactivar: exige que el usuario siga activo y elegible
```

| Situación | ¿Puede vender? | ¿Conserva ventas históricas? | ¿Aparece en listados? |
|---|---|---|---|
| Usuario + marcador + perfil activo | Sí | Sí | Sí |
| Usuario + marcador, **sin perfil** | **No** (`asesor_sin_asociacion`) | — | Como «elegible para asociar» |
| Perfil **inactivo** | **No** | **Sí** (la atribución no cambia) | Sí, con estado Inactivo |
| Usuario Bagisto desactivado (`admins.status = 0`) | No (no inicia sesión) | Sí | Sí (dato histórico) |
| Administrador `all` | Sí, sin perfil; la venta queda «sin asignar» salvo elección explícita | Sí | No como asesor, salvo que tenga perfil |

**Regla para comisiones:** la elegibilidad histórica **no** depende de `activo`. Ver §8 y
`17_DISENO_FUNCIONAL_COMISIONES.md` §8.

---

## 4. Atribución al vender

Tabla `bylopez_va_order_attributions` (1:1 con `orders`, sin columnas nuevas en el core):

| Campo | Significado | Inmutable |
|---|---|---|
| `order_id` | Pedido (UNIQUE) | Sí |
| `created_by_admin_id` + `created_by_name_snapshot` | Quién registró | Sí (RN-02) |
| `advisor_id` + `advisor_name_snapshot` | Asesor propietario (`admins.id`) | No: solo por reasignación auditada |
| `commercial_owner_type` | `advisor` · `company` · `unassigned` | No: por reasignación |
| `commercial_owner_source` | `auto_asesor`, `seleccion_explicita`, `reasignacion`, `sin_contexto`… | Cambia con la propiedad |
| `attribution_status` | `completa` · `sin_asesor` · `fallo_tecnico` | Cambia con la propiedad |
| `sales_channel` / `creation_source` | Canal comercial / mecanismo | Sí |
| `assigned_at` / `assigned_by_admin_id` | Última asignación | Cambia |

Semántica vigente (cierre 02B1B, Doc. 10 §9):

| Caso | Tipo | Estado | Comisión potencial |
|---|---|---|---|
| A. Venta de asesor | `advisor` | `completa` | Sí, según reglas futuras |
| B. Venta de empresa | `company` | `completa` | **No** (D-27) |
| C. No atribuida / histórico sin evidencia | `unassigned` | `sin_asesor` | **No** hasta resolver |

Historial: `bylopez_va_advisor_assignment_logs` (`tipo_operacion`, asesor y propiedad anterior/nuevo
con snapshots, `changed_by_admin_id`, `motivo`, `codigo_tecnico`). **Nunca** se borra ni edita. En
desarrollo hay 6 filas, todas `initial_assignment` (2026-10-07).

**Datos de desarrollo (2026-10-07):** 30 pedidos, 6 atribuciones (1 `advisor/completa` — la venta de
ASESOR-02 del usuario 12177 — y 5 `unassigned/sin_asesor`), 24 históricos sin fila de atribución
(reconciliación no persistida, Doc. 10).

---

## 5. Alcance y visibilidad por pantalla

| Pantalla | `view_own` | `view_all` | Clave de ruta |
|---|---|---|---|
| Inicio | Aviso si no hay perfil | Igual | `ventas_asesor` |
| Nueva venta | Sí (con perfil activo) | Sí | `pedidos.create` |
| Mis ventas / Pedidos | Solo `advisor_id` propio; `?asesor=` se ignora | Todo, con empresa / sin asignar rotulados | `pedidos.view_own|view_all` |
| Cartera / Pagos | Solo pedidos propios | Todo; Confirmar/Rechazar con `cartera.revisar` | `cartera.*` |
| Asesores | Siempre su propia ficha | Listado + cualquier ficha; alta/edición con `manage` | `asesores.*` |
| Comisiones | **No implementado** | **No implementado** | `comisiones.*` (claves reservadas desde la Fase 01) |

La autorización se aplica en el servidor (Bouncer + servicio), nunca solo ocultando botones.

---

## 6. ACL del dominio

| Grupo | Claves | Estado |
|---|---|---|
| Marcador | `ventas_asesor.operar_como_asesor` | Implementada |
| Pedidos | `create`, `view_own`, `view_all`, `assign`, `reassign` | `assign`/`reassign`: **sin ruta HTTP** (solo servicio) |
| Cartera | `view_own`, `view_all`, `registrar`, `abono_general`, `revisar`, `reversar` | Implementadas (`reversar` sin flujo, PAG-I) |
| Asesores | `view_own`, `view_all`, `manage` | Implementadas |
| **Comisiones** | `view_own`, `view_all`, `manage`, `approve`, `pay` | **Reservadas** (Fase 01), sin rutas |
| KPIs | `view_own`, `view_all` | Reservadas |
| Costos (2026-10-08) | `ventas_asesor.costos`, `ventas_asesor.costos.actualizar` | Implementadas; **solo administración** (el asesor nunca ve ni cambia costos; Doc. 19) |
| Tipo de operación (G-11, 2026-10-08) | `ventas_asesor.pedidos.tipo_operacion` | Implementada; elegir muestra / reposición / garantía en Nueva venta. El asesor no la tiene (solo venta normal) |
| Despacho V1 (2026-10-09) | `logistica.despachos`, `.despachos.despachar`, `logistica.guias`, `.guias.ver`, `.guias.archivo`, `.guias.corregir` (paquete Logística); la ruta `admin.ventas_asesor.pedidos.guia` la autoriza `ventas_asesor.pedidos.view_own` | Implementadas. BODEGA #4 las tiene (sin permisos comerciales); el Administrador, todas. El asesor lee la guía solo de **sus** pedidos (propiedad comercial validada en Logística), sin modificarla |
| Logística (LOG-01, 2026-10-08) | `logistica`, `logistica.entregas`, `.entregas.registrar`, `.entregas.anular`, `logistica.transportadoras`, `.transportadoras.manage` (paquete Logística) | Implementadas. BODEGA: registrar; ADMIN / SUPERVISIÓN: además anular y catálogo; asesor: ninguna. Las claves reservadas `ventas_asesor.logistica(.view/.manage)` quedan **sin uso** |
| Logística / Evidencias | `logistica.view|manage`, `evidencias.create|delete` | Reservadas |

**Rol `ASESOR VENTAS` (desarrollo, id 2, 13 permisos):** `ventas_asesor`, `operar_como_asesor`,
`pedidos`, `pedidos.create`, `pedidos.view_own`, `cartera`, `cartera.view_own`,
`cartera.registrar`, `cartera.abono_general`, `asesores`, `asesores.view_own`, `catalog`,
`catalog.products`. Ninguna clave `sales.*`. **Producción: no existe todavía** (rollout bloqueado,
ver `00_STATUS.md`). En producción el rol se resolverá por nombre/permisos, nunca asumiendo id 2.

---

## 7. Invariantes del dominio

| # | Invariante | Dónde se garantiza |
|---|---|---|
| I-A1 | Un usuario tiene como mucho un perfil; un perfil nunca cambia de usuario | UNIQUE `admin_id` + `GuardarAsesorService` |
| I-A2 | Un pedido tiene como mucho un asesor propietario | UNIQUE `order_id` en atribuciones (D-05) |
| I-A3 | Quien vende como asesor sin perfil activo no crea ventas | `BorradorVentaService`, `ConfirmarVentaService` |
| I-A4 | El `all` no recibe ventas automáticamente | `ResolverAtribucionService` (D-27) |
| I-A5 | Todo cambio de propiedad deja un log inmutable con motivo | `AsignacionAsesorService` + `HistorialAtribucionService` |
| I-A6 | Los históricos sin evidencia no se atribuyen | Reconciliador 02B1B |
| I-A7 | El perfil no se borra | Sin ruta ni servicio de borrado; FK RESTRICT |

---

## 8. Contrato para el dominio de comisiones

Comisiones **debe**:

- tomar el beneficiario de `bylopez_va_order_attributions.advisor_id` (y su snapshot) **en el
  momento de causar**, y congelarlo en su propia línea;
- funcionar con perfiles **inactivos** y usuarios desactivados: leer `Asesor` **sin** el scope
  `activos` y nunca usar `AsesorActualService::asesorDe()` (que devuelve null para inactivos);
- excluir `company` y `unassigned`;
- usar `view_own` con la misma regla (`advisor_id` = usuario en sesión).

Comisiones **no debe**: crear otra identidad de asesor, escribir en la atribución, ni depender de
nombres de rol.

---

## 9. Gaps del dominio (detectados en COM-00)

| # | Gap | Impacto | Propuesta |
|---|---|---|---|
| G-A1 | `AsignacionAsesorService::asignarAsesor()` valida solo `esAsesor()` (ACL), **no** el perfil activo. Tras D-60 se podría reasignar a un usuario sin perfil o inactivo | Ventas atribuidas a quien no puede operar; comisión a un asesor «fantasma» | Exigir perfil activo al **asignar** (no al leer historia). Corregir antes de exponer la reasignación por HTTP |
| G-A2 | La reasignación no tiene ruta ni pantalla | El admin no puede corregir atribuciones hoy | Fase 05 (asignación) |
| G-A3 | 24 pedidos históricos sin fila de atribución en desarrollo | Reportes por asesor incompletos (esperado) | Persistir reconciliación 02B1B con autorización; nunca inferir asesor |
| G-A4 | No hay regla para el caso «admin `all` con perfil de asesor» | Ambigüedad: ¿recibe automático? | Hoy no: D-27 manda (solo selección explícita). Documentado, sin cambio |
| G-A5 | Los montos de pedidos y cartera del módulo usan `grand_total` / `base_grand_total`, que **incluye envío** | Correcto para cartera (lo que debe el cliente); **incorrecto como base comisionable** (RN-19) | Comisiones calcula sobre `order_items`, nunca sobre `grand_total` (`16_AUDITORIA_COMISIONES.md` §5) |


## Antecedentes de envíos del cliente (2026-10-18)

`bylopez_va_cliente_envios_historicos` (un renglón por envío único: proveedor + transportadora + guía, con estado literal, fecha y procedencia) y `bylopez_va_estados_envio_clase` (equivalencias explícitas estado → entregado | devolucion | pendiente; lo demás es «desconocido»). Los escribe el cargue «Clientes históricos — 99 Envíos» (`packages/ByLopez/Cargues/docs/14_CLIENTES_HISTORICOS.md`); los lee `AntecedentesEnviosClienteService` para la tarjeta «Historial reportado por 99 Envíos» de la ficha del cliente (hook `bagisto.admin.customers.customers.view.card.orders.before`) y para el aviso de Nueva venta al identificar un cliente existente. Son HECHOS reportados con su procedencia: no calificación del cliente, ni entrega registrada/validada en Logística, ni pago. Sin doble conteo con Logística (un envío presente en ambos se cuenta una vez).

> **Ajuste 2026-10-19:** el antecedente conserva `transportadora_original` (texto del archivo). Una transportadora informada sin equivalencia usa la clave `PEND:<huella>` (no `''` = sin determinar): dos originales distintos con la misma guia son envios distintos. Ver `Cargues/docs/14` §15.
