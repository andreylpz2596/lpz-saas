<?php

/*
 * Configuracion del modulo Cargues (E1). TODOS los valores son configurables; los limites por tipo (map `limites`)
 * solo pueden BAJAR estos maximos, nunca subirlos. Valores conservadores [PROPUESTO, 09 §5].
 */
return [
    /*
     | Rol que puede usar el modulo (id de `roles`). No basta `permission_type = all`: varios roles lo tienen.
     */
    'rol_administrador_id' => (int) env('CARGUES_ROL_ADMIN_ID', 1),

    /*
     | Conexiones de `config/database.php` autorizadas para los tipos. Solo MySQL/MariaDB tienen motor habilitado.
     */
    'conexiones_autorizadas' => array_values(array_filter(array_map('trim', explode(',', (string) env('CARGUES_CONEXIONES', 'mysql'))))),

    /*
     | Disco PRIVADO de archivos subidos (nunca `public`).
     */
    'disco' => env('CARGUES_DISCO', 'local'),
    'directorio' => 'cargues',

    'limites' => [
        'max_bytes' => (int) env('CARGUES_MAX_BYTES', 20 * 1024 * 1024),
        'max_celdas' => (int) env('CARGUES_MAX_CELDAS', 300000),
        'max_filas' => (int) env('CARGUES_MAX_FILAS', 200000),
        'max_columnas' => (int) env('CARGUES_MAX_COLUMNAS', 150),
        'max_longitud_celda' => 32767,
        // XLSX: se limita lo REAL que se descomprime, no lo que declara el ZIP ni `<dimension>`.
        'xlsx_max_bytes_hoja' => (int) env('CARGUES_XLSX_MAX_BYTES_HOJA', 40 * 1024 * 1024),
        'xlsx_max_bytes_total' => (int) env('CARGUES_XLSX_MAX_BYTES_TOTAL', 60 * 1024 * 1024),
        'xlsx_max_entradas' => 64,
        'xlsx_max_ratio' => 200,
        // Memoria: PhpSpreadsheet ~0,63 KB por celda (peor caso medido, 10 §5) + base de Laravel (42 MB medidos).
        'bytes_por_celda_xlsx' => 700,
        'memoria_base_mb' => 60,
        'memoria_maxima_fraccion' => 0.5,
    ],

    /*
     | Complementos OPCIONALES del paso «Resultado» por tabla intermedia del tipo (solo LEEN lo persistido). Sin complemento el
     | resultado por fila sigue funcionando con lo generico: fila, estado, valores leidos e incidencias.
     */
    'resultado' => [
        'complementos' => [
            'bylopez_cgi_envios_completos' => \ByLopez\Cargues\Services\Cargas\Complementos\EnviosCompletosResultado::class,
            'bylopez_cgi_clientes_historicos' => \ByLopez\Cargues\Services\Cargas\Complementos\ClientesHistoricosResultado::class,
        ],
    ],

    /*
     | Extensiones de tipo (codigo de la aplicacion; el map solo las nombra). Ver Contracts/ExtensionTipo y docs/14.
     */
    'extensiones' => [
        'clientes_historicos' => \ByLopez\Cargues\Extensiones\ClientesHistoricos\ClientesHistoricosExtension::class,
    ],

    /*
     | Clientes historicos: grupo de cliente con el que se CREAN los clientes importados (codigo de customer_groups; no hay un grupo
     | por defecto implicito: si no existe, la operacion de crear queda bloqueada con un motivo claro).
     */
    'clientes_historicos' => [
        'grupo_codigo' => env('CARGUES_CLIENTES_HISTORICOS_GRUPO', 'general'),
        'proveedor' => '99envios',
        // false = si el aviso al administrador por cliente nuevo esta activo, crear clientes se BLOQUEA (no se envian correos desde un cargue).
        'permitir_aviso_admin' => (bool) env('CARGUES_CLIENTES_HISTORICOS_PERMITIR_AVISO', false),
    ],

    /*
     | Proteccion PREVENTIVA de VALIDAR (07 §8.5): cuenta de BD restringida, por conexion del tipo. Las credenciales
     | viven en el .env, nunca en la configuracion del tipo. Sin cuenta, y con `exigir_cuenta_restringida`, no se activa
     | ni se valida un tipo de procedimiento. Ver docs/12_PROTECCION_VALIDAR.md.
     */
    'validar' => [
        'exigir_cuenta_restringida' => (bool) env('CARGUES_VALIDAR_EXIGIR_CUENTA', true),
        'conceder_al_activar' => (bool) env('CARGUES_VALIDAR_CONCEDER_AL_ACTIVAR', true),
        'cuentas' => [
            'mysql' => ['usuario' => env('CARGUES_VALIDAR_DB_USERNAME'), 'clave' => env('CARGUES_VALIDAR_DB_PASSWORD')],
        ],
    ],

    'lotes' => [
        'auditoria_bytes' => 512 * 1024,
        'auditoria_filas' => 500,
        'normalizacion_filas' => 500,
        'intermedia_filas' => 500,
        'progreso_cada_filas' => 500,
        'progreso_cada_segundos' => 2,
    ],

    /*
     | Cola DEDICADA. Define una conexion de cola propia llamada `cargues`; NO cambia `queue.default` ni las demas
     | conexiones, asi que ningun otro modulo se ve afectado. `retry_after` debe ser MAYOR que `timeout`.
     */
    'cola' => [
        'conexion' => env('CARGUES_QUEUE_CONNECTION', 'cargues'),
        'nombre' => 'cargues',
        'timeout' => (int) env('CARGUES_JOB_TIMEOUT', 900),
        'retry_after' => (int) env('CARGUES_QUEUE_RETRY_AFTER', 1500),
        'tries' => 1,
    ],

    /*
     | Ejecutor de procedimientos (VALIDAR en E1; EJECUTAR en E2). `wait_timeout` de SESION, en segundos.
     */
    'ejecutor' => [
        'wait_timeout' => (int) env('CARGUES_WAIT_TIMEOUT', 300),
    ],

    /*
     | Segundos sin avance de progreso a partir de los cuales la UI muestra "sin noticias del proceso".
     */
    'umbral_sin_noticias' => (int) env('CARGUES_UMBRAL_SIN_NOTICIAS', 120),

    'output' => [
        'xlsx_max_filas' => 20000,
    ],
];
