# 03 — Antecedentes de arquitectura (NO IMPLEMENTAR)

> **Este documento es histórico.** Contenía las revisiones 1 y 2 del modelo, que **contradicen** el requisito aprobado. **Ninguna instrucción de este archivo debe implementarse.** La especificación vigente está en `07_PLAN_CONSOLIDADO.md`.

## 1. Qué decía y qué lo reemplaza

| Propuesta retirada | Por qué se retira | Vigente en `07` |
|---|---|---|
| Aplicador PHP **programado por cada destino** en modo tabla, registrado en lista blanca de código | Obliga a programar por cada tipo nuevo; contradice el motor parametrizable | Motor genérico de acciones sobre la estructura real de la tabla (§6) |
| Staging **tipado y fijo por tipo**, provisionado por migración o rutina revisada | Contradice la creación dinámica obligatoria | Intermedia definida en `map.tabla` y creada por el motor (§5) |
| Staging generado al publicar con nombre `stg_<slug>_v<n>` elegido por el motor | El nombre lo define `map.tabla` | §5.4 |
| `campos` declarados en el JSON también en modo tabla | Los campos del modo tabla salen de la estructura real | §3.2, §6.1 |
| Cinco claves de permiso por acción | Aprobado: solo administrador | §0 |
| Tabla `carga_filas`/`carga_errores`, `mapeos` | Renombradas | T6 `carga_datos`, T7 `carga_incidencias`, T4 `comparaciones` (§3.1) |
| Llave de negocio solo como índice no único | Limitaba la definición de llaves primarias y únicas | §5.5 |
| Estado `revertida` para todas las filas tras un rollback | Algunas filas pueden no haberse procesado | §9 |

## 2. Razonamiento que se conserva

Se resume aquí porque sustenta decisiones de `07`; no añade instrucciones.

- **Clases de operación frente a reprocesos:** (A) fijar un valor o hacer upsert converge al reejecutar; (B) sumar o insertar movimientos duplica; (C) los efectos externos no se pueden revertir. El contrato de `07` §8 y la responsabilidad del procedimiento sobre reprocesos [APROBADO] parten de esta distinción.
- **Mecanismos de idempotencia:** el cambio de estado atómico evita disparos simultáneos; las marcas por fila solo valen si se escriben en la **misma transacción** que el efecto; el hash del archivo solo sirve para avisar de una recarga.
- **`JSON_TABLE` no existe en MariaDB 10.4**: el procedimiento no puede leer el JSON de auditoría de forma eficiente. De ahí la intermedia tipada.
- **Alternativas descartadas:** reutilizar `Webkul\DataTransfer` (ver `02`); un motor ETL con SQL configurable (riesgo de seguridad); idempotencia solo por hash del archivo.
