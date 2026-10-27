# 12 — Protección preventiva de VALIDAR (privilegios efectivos)

**Fecha:** 2026-10-14 · **Estado:** [IMPLEMENTADO] en el paquete y [V] probado en la instancia aislada (MariaDB 10.4.28); activa en desarrollo para el tipo sintético «Prueba E1». **No verificada en MySQL 8 ni en producción.** Los tipos reales **no están autorizados**; esta protección es requisito previo para habilitar cualquier procedimiento real.

Marcas: **[V]** comprobado con una prueba ejecutada · **[IMPLEMENTADO]** existe en el código · **[PROPUESTO]** decisión técnica sin aprobación expresa · **[PENDIENTE]**.

## 1. El problema: «una cuenta con SELECT» no limita a un procedimiento

`VALIDAR` no debe modificar el negocio. Detectarlo **después** (CHECKSUM, huella de entrada, `ROLLBACK`) no basta: un `COMMIT` interno no se deshace y una escritura en una tabla no declarada no se ve. La barrera correcta es **preventiva**: que el servidor niegue la escritura.

La idea ingenua —conectar `VALIDAR` con una cuenta que solo tenga `SELECT`— **falla** por cómo MariaDB/MySQL asigna privilegios a las rutinas:

| Característica de la rutina | Con qué privilegios corre su cuerpo |
|---|---|
| `SQL SECURITY DEFINER` (**el valor por defecto**) | Los de su **DEFINER** (p. ej. `root@localhost`), **no** los de quien la llama |
| `SQL SECURITY INVOKER` | Los de la cuenta que ejecuta el `CALL` |
| Triggers | Los de su definidor, sin pasar por los de la cuenta |
| Acciones en cascada de llaves foráneas | No comprueban privilegios de la tabla afectada |
| Otra rutina (`CALL` o función) que la cuenta pueda ejecutar | Según su propio `SQL SECURITY` |

**[V] demostrado** (`ProteccionValidarTest`, «LA PREGUNTA»): una cuenta que solo tiene `SELECT` y `EXECUTE` sobre un procedimiento `DEFINER` creado por `root` **modificó una tabla de negocio** al llamarlo. Por eso se evalúan los **privilegios efectivos**, no la cuenta configurada.

## 2. Diseño (todo se lee del servidor, no de lo configurado)

| # | Barrera | Qué comprueba | Cuándo |
|---|---|---|---|
| 1 | **Cuenta restringida** | `VALIDAR` abre una sesión PDO dedicada con una cuenta de BD **distinta** de la de la conexión del tipo (credenciales en `.env`: `CARGUES_VALIDAR_DB_USERNAME` / `_PASSWORD`; nunca en la configuración del tipo) | Cada `VALIDAR` |
| 2 | **Privilegios efectivos de esa sesión** | `SHOW GRANTS` de la sesión que va a ejecutar. Solo se admite `USAGE`, `SELECT`, `SHOW VIEW`; `UPDATE` **solo** sobre la intermedia del tipo; `EXECUTE` **solo** sobre rutinas con nombre. Cualquier otro (`INSERT`, `DELETE`, `FILE`, `ALL PRIVILEGES`, `GRANT OPTION`, `EXECUTE` de esquema `db.*`, `CREATE TEMPORARY TABLES`, roles, `PROXY`, privilegios dinámicos de MySQL 8…) **bloquea** | Cada `VALIDAR` y al activar |
| 3 | **`SQL SECURITY`** | El procedimiento debe ser `INVOKER`; un `DEFINER` solo se admite si el definidor **es la propia cuenta restringida** | Cada `VALIDAR` y al activar |
| 4 | **Rutinas ejecutables** | Cada rutina que la cuenta puede ejecutar (por sus `GRANT EXECUTE`, que enumeran todo lo invocable, incluidas las funciones) se somete a la regla 3. Un `CALL` o una función `DEFINER` ajena es una vía de escalada. Esto cubre también el SQL dinámico (`PREPARE`), que la revisión estática no puede leer | Cada `VALIDAR` y al activar |
| 5 | **Intermedia sin triggers ni llaves foráneas** | Escapan a los privilegios de la cuenta | Cada `VALIDAR` y al activar |
| 6 | **Cuenta ≠ cuenta del tipo** y misma base | Una cuenta idéntica a la del tipo no restringe nada | Cada `VALIDAR` y al activar |
| 7 | **Permisos mínimos presentes** | `UPDATE` sobre la intermedia y `EXECUTE` sobre el procedimiento; si falta algo, `VALIDAR` no corre y dice exactamente qué `GRANT` falta | Cada `VALIDAR` |

Si algo falla, la carga termina `fallida_validacion` con código **`VALIDAR_SIN_PROTECCION`** y el motivo; **el procedimiento nunca se llama**. En activación, los mismos hallazgos aparecen como errores del contrato (`PROCEDIMIENTO_DEFINER`, `VALIDAR_SIN_CUENTA_RESTRINGIDA`, `INTERMEDIA_CON_TRIGGER`…) y **impiden activar**.

Las barreras posteriores **se conservan** (huella del procedimiento, CHECKSUM de `tablas_negocio`, huella de entrada, solo marcar `rechazada`, `ROLLBACK`, bloqueo con nombre): son la segunda línea, no la primera.

### Permisos que concede el módulo a la cuenta (mínimos, por tipo)

```
GRANT SELECT, UPDATE ON `<base>`.`<intermedia>` TO '<cuenta>'@'<host>';
GRANT EXECUTE ON PROCEDURE `<base>`.`<procedimiento>` TO '<cuenta>'@'<host>';
GRANT SELECT ON `<base>`.`<tabla de map.tablas_negocio>` TO '<cuenta>'@'<host>';   -- una por cada tabla declarada
```

- **Al activar**, el módulo intenta concederlos (mejor esfuerzo; necesita `GRANT OPTION` en la conexión del tipo; `CARGUES_VALIDAR_CONCEDER_AL_ACTIVAR`). Si no puede, no falla: `VALIDAR` avisará qué falta.
- **A mano o por comando:** `php artisan cargues:proteccion-validar` (muestra y evalúa) y `php artisan cargues:proteccion-validar --aplicar` (concede). Sale con código 1 si algún tipo activo no cumple.
- Efecto buscado: **solo puede leer lo que el tipo declaró** (`tablas_negocio`); si el procedimiento lee otra tabla, falla con «denegado» y hay que declararla. Esto además hace que el CHECKSUM cubra todo lo que el procedimiento toca.
- La cuenta **debe existir** y tener contraseña; crearla es una tarea del administrador de la BD:
  `CREATE USER '<cuenta>'@'<host>' IDENTIFIED BY '<clave>';` (sin ningún otro privilegio). Si no hay privilegios sobre la base, la sesión de verificación igualmente se evalúa (conecta sin base y la selecciona después).

## 3. Qué se cierra y qué NO

| Riesgo | Antes | Ahora |
|---|---|---|
| `INSERT/UPDATE/DELETE` en tabla de negocio **declarada** | Se detectaba al final y se deshacía | **Lo niega el servidor** [V] |
| Escritura en tabla **no declarada** (incluido SQL dinámico) | **No se detectaba** | **Lo niega el servidor** [V] |
| `COMMIT` interno que confirmaba la escritura | Se detectaba pero **no se deshacía** | **El servidor no deja empezar** la escritura [V] |
| DDL, `LOAD DATA`, `INTO OUTFILE` | Revisión estática (heurística) | Sin privilegios (`CREATE`, `DROP`, `FILE`…) |
| Procedimiento `SQL SECURITY DEFINER` | No se miraba | **Bloquea** activar y validar [V] |
| Permisos de la cuenta cambiados después de activar | — | **Bloquea antes del `CALL`** [V] |
| Procedimiento recreado como `DEFINER` tras activar | — | Lo detecta la huella (`PROCEDIMIENTO_CAMBIO`) [V] |

**Cuenta compartida (ajuste 2026-10-15):** la cuenta es UNA por conexión; con varios tipos activos tiene `UPDATE` sobre la intermedia de cada uno. Se admite solo sobre intermedias **registradas** (T3, estructura activa) de esa conexión [V]. Riesgo residual: un procedimiento con SQL dinámico podría marcar filas de la intermedia de otro tipo; los datos de entrada de ese tipo siguen vigilados por su huella.

**Dev (2026-10-15):** `mysql.procs_priv` está corrupta en el MariaDB de XAMPP: los `GRANT EXECUTE` quedan solo en memoria y se pierden al reiniciar. La protección lo detecta («faltan permisos»). Solución: `REPAIR TABLE mysql.procs_priv;` y `php artisan cargues:proteccion-validar --aplicar` (no aplicada por Claude).

**Límites que siguen vigentes (no se presentan como resueltos):**

1. **Lecturas.** La cuenta puede leer las tablas declaradas (datos personales incluidos). Es inherente a validar contra el negocio.
2. **No prueba que el procedimiento haga lo correcto.** Impide que *escriba* durante `VALIDAR`, no que *decida mal* qué rechazar. La revisión humana ligada a la huella sigue siendo necesaria.
3. **EJECUTAR (E2) no queda protegido por esto.** Con `INVOKER`, el mismo cuerpo corre en `EJECUTAR` con los privilegios de la conexión del tipo (los que escriben). Lo que impide que `VALIDAR` escriba es la **cuenta**, no el texto del procedimiento. Un procedimiento que en `VALIDAR` parece inocuo y en `EJECUTAR` hace lo que no debe solo lo frena la revisión y el diseño de E2.
4. **Procedimientos con tablas temporales** necesitan `CREATE TEMPORARY TABLES`, que hoy **se considera no admitido** [PENDIENTE decidir].
5. **Denegación de servicio.** Un procedimiento puede consumir tiempo/CPU; solo lo acota `max_statement_time` (MariaDB).
6. **Solo MariaDB 10.4.28 probado.** En MySQL 8 `SHOW GRANTS` puede traer privilegios dinámicos y roles: se tratan como violación (más estricto, no más laxo), sin haberse probado.
7. **Producción:** la cuenta, su host, `GRANT OPTION` para el concesor y si `SHOW GRANTS`/`information_schema` son legibles **sin verificar** (R5).

## 4. Configuración

| Variable `.env` | Valor | Nota |
|---|---|---|
| `CARGUES_VALIDAR_DB_USERNAME` / `CARGUES_VALIDAR_DB_PASSWORD` | cuenta restringida de la conexión `mysql` | Otras conexiones: `config('cargues.validar.cuentas.<conexión>')` |
| `CARGUES_VALIDAR_EXIGIR_CUENTA` | `true` (por defecto) | Con `true`, sin cuenta **no se activa ni se valida**. **No ponerlo en `false` con tipos reales** |
| `CARGUES_VALIDAR_CONCEDER_AL_ACTIVAR` | `true` | Concede los permisos mínimos al activar |

En desarrollo se creó `cargues_validar@127.0.0.1` (sin permisos salvo los de «Prueba E1») y sus credenciales están en el `.env` local (no versionado).

## 5. Pruebas ejecutadas (instancia aislada, `ProteccionValidarTest`)

| Prueba | Resultado |
|---|---|
| `analizarGrants`: acepta el mínimo; rechaza `ALL PRIVILEGES`, `INSERT`, `UPDATE` ajeno o por columna, `FILE`, `GRANT OPTION`, `EXECUTE db.*`, `EXECUTE` de otra base, roles, `UPDATE` sobre otra intermedia | [V] |
| Sin cuenta configurada: no se confirma la revisión ni se activa | [V] |
| **«Una cuenta con SELECT no limita a un DEFINER»**: demostración (la tabla cambia) y bloqueo `PROCEDIMIENTO_DEFINER` | [V] |
| `INVOKER` + cuenta: activa, concede lo mínimo, `VALIDAR` correcto, negocio intacto, `SHOW GRANTS` sin escritura | [V] |
| Escritura declarada, **no declarada (SQL dinámico)**, inserción y borrado: el servidor los niega, la carga falla `PROCEDIMIENTO_FALLO`, CHECKSUM igual | [V] |
| `COMMIT` interno (modelo `procedimiento`): antes quedaba confirmado; ahora el servidor lo niega y no queda nada | [V] |
| Permiso `INSERT` agregado después de activar: `VALIDAR_SIN_PROTECCION` antes del `CALL` | [V] |
| Función `DEFINER` ejecutable, trigger en la intermedia, cuenta = cuenta del tipo: bloquean | [V] |
| Sin conceder al activar: `VALIDAR` lista los `GRANT` que faltan; el comando los muestra (código 1) y con `--aplicar` los concede (código 0) y entonces funciona | [V] |
| Procedimiento recreado como `DEFINER` tras activar: `PROCEDIMIENTO_CAMBIO` | [V] |

En desarrollo (sin crear cargas): la cuenta abre sesión, ejecuta `sp_cg_prueba_e1` en `VALIDAR` (carga 0) y el servidor **niega** `UPDATE`/`SELECT` sobre otras tablas, `DELETE` sobre `bylopez_cg_cargas` e `INSERT` en la intermedia.
