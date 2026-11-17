# 31 · Período de comisión por asesora (día de ingreso)

Fecha: 2026-11-16 · Rama: `revision/bagisto-2.4.9-saneada` · Estado: implementado y probado en `lpz_saas_testing`; **las reglas marcadas (P) esperan aprobación del responsable**.

## Requerimiento

Hoy el umbral, el avance, las líneas (`mes_comercial`), el panel y el simulador usan el mes calendario (del 1 al último día). Ahora cada asesora tiene un **día de ingreso** (su fecha de inicio de labores), editable en *Ventas Asesor › Asesores › Editar*. Su período va del día de ingreso de un mes al día anterior del mes siguiente:

- Karen ingresó el 14 → períodos del 14 al 13.
- Zuly ingresó el 1 → mes calendario.
- Wendy ingresó el 18 → períodos del 18 al 17.

## Reglas

| Tema | Regla |
|---|---|
| Sin día de ingreso | Día 1 = mes calendario. Es lo que ya está guardado: nada cambia para quien no tiene fecha. |
| Clave del período | Mes (`Y-m`) en que **empieza** el período. Karen, venta del 5-dic → período 14-nov a 13-dic → clave `2026-11`. Es la misma columna `mes_comercial` y la misma clave de `bylopez_va_comision_meses` y del libro de umbral: no hay cambio de esquema en las tablas de comisiones. |
| Días 29, 30 y 31 (P) | Si el mes no tiene ese día, el período empieza el **último día** de ese mes. Día 31: 31-ene a 27-feb, 28-feb a 30-mar, 31-mar a 29-abr… En año bisiesto, febrero empieza el 29. |
| Zona horaria | El instante de confirmación se lleva a America/Bogota antes de decidir el período (igual que antes). |
| Etiqueta | Mes calendario: «noviembre 2026». Otro período: «14 nov 2026 – 13 dic 2026». Los filtros siguen siendo un selector de mes, con el rótulo «Período (mes en que inicia)», y debajo se muestra el período exacto de la asesora. |
| Cambio de día **sin** comisiones calculadas | La asesora no tiene ninguna línea ni ningún mes de umbral: el día rige **desde siempre** (no hay nada guardado que cambie de período). Es el caso de producción hoy, porque las comisiones están apagadas. |
| Cambio de día **con** comisiones (P) | No se reescribe ningún período vivido. El período **en curso** se alarga hasta el primer inicio del día nuevo que no sea anterior a su fin natural, y desde ahí rige el día nuevo. Ejemplos con el cambio el 20-nov: de 1 a 14 → noviembre va del 1-nov al 13-dic y el día 14 rige desde el 14-dic; de 14 a 1 → el período 14-nov se alarga hasta el 31-dic, diciembre no abre período propio y el 1-ene rige el mes calendario. |
| Cambio pendiente | Un cambio que todavía no rige se **reemplaza** con el siguiente cambio (no se apilan). Si se vuelve al día vigente, el pendiente se cancela. |
| Por qué alargar y no recortar | Si el período en curso se recortara, dos períodos podrían empezar el mismo mes y compartir clave, y se mezclaría su umbral. |
| Reevaluación | Cuando un período en curso se alarga, se solicita la reevaluación de los pedidos de ese período, porque puede entrar un acuerdo programado dentro del tramo nuevo. Con la marca de «cambio de umbral en el mes» funciona igual que con los acuerdos. |
| Liquidación | Sigue con período libre (filtra por `habilitada_en`, sin cambios). Solo cambia la **sugerencia**: el formulario propone el último período **ya terminado** de la asesora. |
| Panel de rentabilidad (empresa) | Sigue siendo por **mes calendario** de la confirmación. La comisión del mes se toma de las líneas confirmadas en ese mes, y cada una se estima con el reparto de **su** período (asesora + clave). Para quien no tiene día de ingreso el resultado es idéntico al anterior. |
| Permisos | Editar la fecha usa el permiso existente `ventas_asesor.asesores.manage`. No se tocan roles ni permisos. |
| Validación | La fecha debe ser real (AAAA-MM-DD), del año 2000 en adelante y no posterior a hoy. Si se deja vacía, la asesora queda sin día (mes calendario). |

## Impacto

**Migración** `2026_11_16_000001_create_bylopez_va_comision_ciclos_table` (aditiva, sin backfill):

- `bylopez_va_asesores.fecha_ingreso` (fecha, puede quedar vacía);
- tabla `bylopez_va_comision_ciclos`: historial de días por asesora (`asesor_admin_id` = admins.id, `dia_inicio`, `vigente_desde` vacío = desde siempre, `fecha_ingreso`, `origen` retroactivo/transicion, autor).

**Código nuevo:**

- `Support/Comisiones/CalendarioComision.php`: cálculo puro de períodos, claves, rangos y sugerencia;
- `Support/Comisiones/PeriodoComision.php`: un período y su etiqueta;
- `Services/Comisiones/CicloComisionService.php`: guarda el cambio con la regla retroactivo/transición.

**Código adaptado:**

- `MesComercial` gana `calendario()` y `periodo()`; `de()` y `rango()` quedan como el mes calendario.
- Ya calculan la clave con el calendario de la asesora:
  - `LineasComisionService` (crea `mes_comercial`);
  - `EvaluarComisionesService` (meses implicados);
  - `UmbralMensualService::umbralDelMes` (rango para los acuerdos);
  - `AcuerdosComisionService` (mes a reevaluar);
  - `SimuladorProximaVentaService`;
  - `AvanceAsesorService` (agrega `periodo`; el comparativo usa el período en curso de cada asesora);
  - `ConsultaComisionesService` (detalle del pedido).
- `PanelMensualService`: la comisión se toma por mes calendario de la confirmación.
- Controladores:
  - `ComisionesController`: la clave por defecto es la de la asesora elegida o la propia; agrega la sugerencia de liquidación;
  - `AsesoresController`: recibe la fecha y muestra el mensaje de transición;
  - `PedidosAsesorController`.
- `GuardarAsesorService` y `ConsultaAsesoresService`, más el modelo `Asesor`.
- Vistas (ficha, modal de edición, avance, comparativo, Mis comisiones, liquidaciones, detalle) y textos en es/en.

**Pruebas:**

- `tests/Unit/CalendarioComisionTest.php`: 16 casos. Cubre los ejemplos de Karen, Zuly y Wendy, los días 29/30/31, el año bisiesto, la zona horaria, las transiciones en los dos sentidos y una propiedad sobre 1.100 días (contiguos, sin huecos, claves crecientes, `rango(clave)` coherente).
- `tests/Feature/Com09PeriodoAsesoraTest.php`: 5 casos. Cubre el cambio retroactivo vía HTTP y la ficha, la validación, la transición con comisiones (pendiente reemplazado o cancelado) y ventas reales: 4 + 4 unidades a ambos lados del día 14 con umbral 5 dan 0 comisionables por período (por mes calendario serían 3). También el simulador, el comparativo, el panel, la sugerencia de liquidación y la regresión de una asesora sin día.

## Despliegue

```bash
# como el usuario del sitio (alpz-bylopez); la consola usa PHP 8.4 por defecto: usar siempre php8.3
cd ~/htdocs/bylopez.alpz.com.co
git status --short                                  # debe salir vacío (sin cambios locales en el servidor)
php8.3 artisan down
git fetch origin && git checkout revision/bagisto-2.4.9-saneada && git pull --ff-only
php8.3 /usr/local/bin/composer install --no-dev --optimize-autoloader   # el lock no cambió; verificación rápida
php8.3 artisan migrate:status --pending            # debe listar SOLO 2026_11_16_000001_create_bylopez_va_comision_ciclos_table
php8.3 artisan migrate --force
php8.3 artisan optimize:clear && php8.3 artisan optimize
php8.3 artisan queue:restart
php8.3 artisan up
```

Nunca `migrate:fresh`, `db:wipe` ni seeders. Antes de migrar, saca un respaldo de la base. La migración es reversible (`down` borra la tabla y la columna), pero en producción se corrige hacia adelante.

Después de desplegar, carga la fecha de ingreso de cada asesora en *Asesores › Editar*. Como las comisiones están apagadas y no hay líneas, el día rige desde siempre.
