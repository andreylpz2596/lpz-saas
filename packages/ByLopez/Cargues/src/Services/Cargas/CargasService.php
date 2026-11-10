<?php

namespace ByLopez\Cargues\Services\Cargas;

use ByLopez\Cargues\Exceptions\OperacionInvalidaException;
use ByLopez\Cargues\Jobs\EjecutarCarga;
use ByLopez\Cargues\Jobs\GenerarVistaPreviaCarga;
use ByLopez\Cargues\Jobs\LeerYAuditarCarga;
use ByLopez\Cargues\Models\Carga;
use ByLopez\Cargues\Models\Comparacion;
use ByLopez\Cargues\Models\Tipo;
use ByLopez\Cargues\Models\TipoVersion;
use ByLopez\Cargues\Services\Lectura\LectorCsv;
use ByLopez\Cargues\Services\Lectura\LectorFactory;
use ByLopez\Cargues\Services\Lectura\LimitesLectura;
use ByLopez\Cargues\Services\Lectura\OpcionesLectura;
use ByLopez\Cargues\Support\Esquema;
use ByLopez\Cargues\Support\Estados;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Throwable;

/**
 * "Nuevo cargue" (09 §2.1): crea la carga a partir de un archivo, prepara la lectura y la relacion de columnas y, con
 * "Validar", congela la configuracion y encola el procesamiento en segundo plano.
 *
 * Separa DOS clases de parametros:
 *  - configuracion administrativa validada (conexion, procedimiento, tabla, politica de errores, tipos, llaves,
 *    limites): vive en la version del tipo y NADA de lo que llegue al ejecutar puede cambiarla;
 *  - parametros permitidos al ejecutar (PARAMETROS_*): archivo, hoja, fila de encabezados, formato CSV, relacion de
 *    columnas y comparacion, y una accion de la lista del tipo. Cualquier otra clave se rechaza.
 */
class CargasService
{
    public const PARAMETROS_CREAR = ['tipo_id', 'archivo', 'accion'];

    public const PARAMETROS_LECTURA = ['hoja', 'fila_encabezado', 'delimitador', 'entrecomillado', 'codificacion'];

    public const PARAMETROS_EJECUTAR = ['confirmar_rechazadas'];

    public const PARAMETROS_VALIDAR = ['hoja', 'fila_encabezado', 'delimitador', 'entrecomillado', 'codificacion', 'relaciones', 'accion', 'guardar_comparacion', 'nombre_comparacion'];

    public function __construct(
        protected ArchivoService $archivos,
        protected LectorFactory $lectores,
        protected LectorCsv $csv,
        protected Encabezados $encabezados,
        protected Normalizador $normalizador,
        protected ProgresoService $progreso,
        protected ResumenService $resumen,
        protected EjecucionService $ejecucion,
    ) {}

    // ---------------------------------------------------------------------------------------------------------
    // Tipos
    // ---------------------------------------------------------------------------------------------------------

    /** Tipos activos con su version vigente (tarjetas del panel izquierdo). */
    public function tiposActivos(): array
    {
        return Tipo::where('activo', true)->whereNotNull('version_vigente_id')->orderBy('nombre')->get()
            ->map(function (Tipo $t) {
                $v = $t->versionVigente;

                if (! $v || $v->estado !== TipoVersion::ACTIVA) {
                    return null;
                }

                $lim = LimitesLectura::para($v->limites);

                return [
                    'id' => $t->id, 'nombre' => $t->nombre, 'descripcion' => $t->descripcion, 'activo' => true,
                    'version' => (int) $v->numero, 'procedimiento' => $v->procedimiento,
                    'acciones' => (array) $v->map['acciones'], 'accion' => $v->accion,
                    'acciones_etiquetas' => array_map(fn ($a) => (string) config('cargues.acciones_etiquetas.'.$a, $a), array_combine((array) $v->map['acciones'], (array) $v->map['acciones'])),
                    'politica_errores' => $v->politica_errores,
                    // Plantilla descargable del tipo (si tiene) y revision agrupada opcional (registros por tabla intermedia).
                    'plantillas' => array_values(array_filter(['xlsx', 'csv'], fn ($f) => $this->rutaPlantilla($v->map, $f) !== null)),
                    'agrupado' => $this->agrupador($v->map) !== null,
                    'formatos' => ['xlsx', 'csv', 'txt'],
                    'limites' => ['max_bytes' => $lim->maxBytes, 'max_celdas' => $lim->maxCeldas, 'max_filas' => $lim->maxFilas],
                    'campos' => array_map(fn ($c) => [
                        'nombre' => $c['nombre'], 'etiqueta' => $c['etiqueta'], 'tipo' => $c['tipo'],
                        'obligatorio_en' => array_values(array_filter((array) $v->map['acciones'], fn ($a) => Esquema::esObligatorio($c, $a))),
                        'obligatorio_en_alguna' => Esquema::esObligatorioEnAlguna($c),
                    ], (array) $v->map['campos']),
                ];
            })->filter()->values()->all();
    }

    /** Archivo de plantilla del tipo vigente (solo tipos activos; la ruta sale de la configuracion, nunca del map ni de la peticion). */
    public function plantilla(int $tipoId, string $formato): array
    {
        [$tipo, $v] = $this->tipoVigente($tipoId);
        $ruta = $this->rutaPlantilla($v->map, $formato);

        if ($ruta === null) {
            throw new OperacionInvalidaException('SIN_PLANTILLA', 'Este tipo no tiene plantilla en ese formato.');
        }

        return [$ruta, basename($ruta)];
    }

    /** Revision agrupada de una carga validada o ejecutada (solo si su tipo la registra). */
    public function agrupado(Carga $carga): array
    {
        if (! in_array($carga->estado, [Estados::VALIDADA, Estados::COMPLETADA, Estados::FALLIDA_EJECUCION], true)) {
            throw new OperacionInvalidaException('ESTADO_NO_EJECUTABLE', 'La revisión agrupada existe cuando la carga está validada o ejecutada.');
        }

        $agrupador = $this->agrupador((array) ($carga->config_congelada['map'] ?? []));

        if ($agrupador === null) {
            throw new OperacionInvalidaException('SIN_AGRUPADO', 'Este tipo no tiene revisión agrupada.');
        }

        return $agrupador->agrupar($carga);
    }

    protected function rutaPlantilla(array $map, string $formato): ?string
    {
        $relativa = config('cargues.plantillas.'.($map['tabla'] ?? '').'.'.$formato);
        $ruta = is_string($relativa) ? realpath(dirname(__DIR__, 3).'/'.$relativa) : false;

        return $ruta && is_file($ruta) ? $ruta : null;
    }

    protected function agrupador(array $map): ?\ByLopez\Cargues\Contracts\AgrupaResultado
    {
        $clase = config('cargues.resultado.agrupados.'.($map['tabla'] ?? ''));

        return $clase && is_subclass_of($clase, \ByLopez\Cargues\Contracts\AgrupaResultado::class) ? app($clase) : null;
    }

    /** @return array{0: Tipo, 1: TipoVersion} */
    protected function tipoVigente(int $tipoId): array
    {
        $tipo = Tipo::find($tipoId);
        $v = $tipo?->versionVigente;

        if (! $tipo || ! $tipo->activo || ! $v || $v->estado !== TipoVersion::ACTIVA) {
            throw new OperacionInvalidaException('TIPO_NO_ACTIVO', 'El tipo no existe o no está activo.');
        }

        return [$tipo, $v];
    }

    // ---------------------------------------------------------------------------------------------------------
    // Crear y leer
    // ---------------------------------------------------------------------------------------------------------

    public function crear($admin, array $datos, ?UploadedFile $archivo): Carga
    {
        $this->soloPermitidos(array_diff(array_keys($datos), ['archivo']), self::PARAMETROS_CREAR);

        if (! $archivo) {
            throw new OperacionInvalidaException('ARCHIVO_REQUERIDO', 'Seleccione un archivo.');
        }

        [$tipo, $v] = $this->tipoVigente((int) ($datos['tipo_id'] ?? 0));
        $accion = (string) ($datos['accion'] ?? $v->accion);

        if (! in_array($accion, (array) $v->map['acciones'], true)) {
            throw new OperacionInvalidaException('ACCION_NO_PERMITIDA', 'La acción no está entre las del tipo.');
        }

        $info = $this->archivos->guardar($archivo, LimitesLectura::para($v->limites));

        $lectura = ['hoja' => null, 'fila_encabezado' => 1, 'delimitador' => null, 'entrecomillado' => null, 'codificacion' => null];

        try {
            if ($info['formato'] === 'csv') {
                $s = $this->csv->detectar($this->archivos->rutaReal($info['ruta']));
                $lectura['delimitador'] = $s['delimitador'];
                $lectura['entrecomillado'] = '"';
                $lectura['codificacion'] = $s['codificacion'];
            }
        } catch (Throwable $e) {
            $this->archivos->eliminar($info['ruta']);

            throw $e;
        }

        $carga = Carga::create([
            'uuid' => (string) Str::uuid(), 'tipo_id' => $tipo->id, 'tipo_version_id' => $v->id,
            'admin_id' => $admin->id, 'admin_nombre' => $admin->name, 'estado' => Estados::RECIBIDA, 'accion' => $accion,
            'archivo_ruta' => $info['ruta'], 'archivo_nombre' => $info['nombre'], 'archivo_hash' => $info['hash'], 'archivo_bytes' => $info['bytes'],
            'formato' => $info['formato'], 'hoja' => $lectura['hoja'], 'fila_encabezado' => $lectura['fila_encabezado'],
            'delimitador' => $lectura['delimitador'], 'entrecomillado' => $lectura['entrecomillado'], 'codificacion' => $lectura['codificacion'],
            'filas_procesadas' => 0,
        ]);

        try {
            $this->prepararVistaPrevia($carga);
        } catch (OperacionInvalidaException $e) {
            if ($e->codigo === 'XLSX_EXCEDE_MEMORIA') {
                // Cabe en el worker pero no en este proceso: la vista previa la genera la cola.
                $this->encolarVistaPrevia($carga);

                return $carga->fresh();
            }

            // Archivo inaceptable: no queda nada (ni fila ni archivo).
            $this->archivos->eliminar($info['ruta']);
            $carga->delete();

            throw $e;
        }

        return $carga->fresh();
    }

    /** Cambia hoja / fila de encabezados / formato CSV y recalcula la vista previa. Invalida una validacion previa. */
    public function actualizarLectura(Carga $carga, array $params): Carga
    {
        $this->soloPermitidos(array_keys($params), self::PARAMETROS_LECTURA);
        $this->exigirEditable($carga);
        $this->aplicarLectura($carga, $params);

        try {
            $this->prepararVistaPrevia($carga->fresh());
        } catch (OperacionInvalidaException $e) {
            if ($e->codigo !== 'XLSX_EXCEDE_MEMORIA') {
                throw $e;
            }

            $this->encolarVistaPrevia($carga);
        }

        return $carga->fresh();
    }

    /** Calcula y guarda encabezados, ejemplos y sugerencias. Lo usan la peticion web y el job de vista previa. */
    public function prepararVistaPrevia(Carga $carga): void
    {
        $v = TipoVersion::findOrFail($carga->tipo_version_id);
        $limites = LimitesLectura::para($v->limites);
        $opciones = OpcionesLectura::desdeCarga($carga);
        $ruta = $this->archivos->rutaReal($carga->archivo_ruta);
        $lector = $this->lectores->para($carga->formato);

        $insp = $lector->inspeccionar($ruta, $opciones, $limites);

        // La hoja elegida por omision (primera visible) se guarda para que la lectura posterior sea la misma.
        if ($carga->formato === 'xlsx' && ! $carga->hoja) {
            $carga->hoja = $insp->hojaSeleccionada;
            $opciones = OpcionesLectura::desdeCarga($carga);
        }

        $prev = $lector->vistaPrevia($ruta, $opciones, $limites, 5);
        $meta = $this->encabezados->construir($prev['encabezados'], $prev['filas'], $this->normalizador);

        $carga->encabezados = $meta + [
            'inspeccion' => $insp->aArray(),
            'sugerencias' => $this->encabezados->sugerir($meta['columnas'], (array) $v->map['campos']),
            'firma' => $this->encabezados->firma($meta['columnas']),
        ];

        $carga->etapa = null;
        $carga->progreso_mensaje = null;
        $carga->save();
    }

    // ---------------------------------------------------------------------------------------------------------
    // Validar
    // ---------------------------------------------------------------------------------------------------------

    /** "Validar archivo": comprueba la relacion de columnas, congela la configuracion y encola el trabajo. */
    public function validar($admin, Carga $carga, array $params): Carga
    {
        $this->soloPermitidos(array_keys($params), self::PARAMETROS_VALIDAR);
        $this->exigirEditable($carga);

        [$tipo, $v] = $this->tipoVigente((int) $carga->tipo_id);

        if ($v->id !== (int) $carga->tipo_version_id) {
            throw new OperacionInvalidaException('VERSION_CAMBIADA', 'El tipo tiene una versión nueva. Cree la carga de nuevo para usarla.');
        }

        $lectura = array_intersect_key($params, array_flip(self::PARAMETROS_LECTURA));

        if ($lectura && $this->aplicarLectura($carga, $lectura)) {
            try {
                $this->prepararVistaPrevia($carga);
            } catch (OperacionInvalidaException $e) {
                if ($e->codigo === 'XLSX_EXCEDE_MEMORIA') {
                    throw new OperacionInvalidaException('VISTA_PREVIA_PENDIENTE', 'La vista previa del archivo aún se está preparando; espere y vuelva a validar.');
                }

                throw $e;
            }
        }

        if (empty($carga->encabezados['columnas'])) {
            throw new OperacionInvalidaException('VISTA_PREVIA_PENDIENTE', 'La vista previa del archivo aún no está lista.');
        }

        $accion = (string) ($params['accion'] ?? $carga->accion);

        if (! in_array($accion, (array) $v->map['acciones'], true)) {
            throw new OperacionInvalidaException('ACCION_NO_PERMITIDA', 'La acción no está entre las del tipo.');
        }

        $relaciones = $this->validarRelaciones($carga, $v, $accion, (array) ($params['relaciones'] ?? []));

        if (! empty($params['guardar_comparacion'])) {
            $nombre = trim((string) ($params['nombre_comparacion'] ?? ''));

            if ($nombre === '' || mb_strlen($nombre) > 191) {
                throw new OperacionInvalidaException('COMPARACION_NOMBRE', 'Dé un nombre a la relación que desea guardar (máximo 191 caracteres).');
            }

            $this->guardarComparacion($admin, $carga, $v, $nombre, $relaciones);
        }

        $congelada = [
            'tipo_version_id' => $v->id, 'tipo_nombre' => $tipo->nombre, 'conexion' => $v->conexion, 'procedimiento' => $v->procedimiento,
            'accion' => $accion, 'politica_errores' => $v->politica_errores, 'limites' => $v->limites,
            'map' => $v->map, 'estructura_huella' => $v->estructura?->estructura_huella, 'procedimiento_huella' => $v->procedimiento_huella,
        ];

        $carga->forceFill(['accion' => $accion, 'relaciones' => $relaciones, 'config_congelada' => $congelada])->save();

        return $this->encolar($carga, [Estados::RECIBIDA, Estados::VALIDADA, Estados::FALLIDA_LECTURA, Estados::FALLIDA_PREPARACION, Estados::FALLIDA_VALIDACION], false);
    }

    /** Relanza el procesamiento con la misma configuracion congelada (carga fallida o sin noticias del proceso). */
    public function reintentar(Carga $carga): Carga
    {
        if (empty($carga->config_congelada) || empty($carga->relaciones)) {
            throw new OperacionInvalidaException('SIN_CONFIGURACION', 'La carga aún no se ha validado: use «Validar archivo».');
        }

        return $this->encolar($carga, [Estados::FALLIDA_LECTURA, Estados::FALLIDA_PREPARACION, Estados::FALLIDA_VALIDACION], true);
    }

    public function cancelar($admin, Carga $carga): Carga
    {
        if ($carga->ejecucion_token || in_array($carga->estado, ['lista', 'ejecutando', 'interrumpida', 'consolidando', 'completada'], true)) {
            throw new OperacionInvalidaException('CARGA_NO_CANCELABLE', 'La carga ya inició o terminó su ejecución.');
        }

        // job_uuid a NULL: un job que siga corriendo detecta que ya no es el dueno y se detiene.
        $carga->forceFill([
            'estado' => Estados::CANCELADA, 'job_uuid' => null, 'etapa' => null,
            'mensaje' => 'Cancelada por '.$admin->name.'.', 'finalizada_en' => now(),
        ])->save();

        return $carga->fresh();
    }

    /**
     * CAS de encolado: solo UN proceso gana. Desde los estados permitidos, o desde un estado en curso cuyo progreso
     * lleva mas del umbral sin avanzar (el worker murio). Despues del CAS despacha el job.
     */
    protected function encolar(Carga $carga, array $desde, bool $permitirSinNoticias): Carga
    {
        $uuid = (string) Str::uuid();
        $umbral = now()->subSeconds((int) config('cargues.umbral_sin_noticias', 120));
        $anterior = $carga->only(['estado', 'etapa', 'job_uuid']);

        $n = Carga::where('id', $carga->id)->where(function ($q) use ($desde, $permitirSinNoticias, $umbral) {
            $q->whereIn('estado', $desde);

            if ($permitirSinNoticias) {
                $q->orWhere(function ($s) use ($umbral) {
                    $s->whereIn('estado', Estados::EN_CURSO)->where('progreso_actualizado_en', '<', $umbral);
                })->orWhere(function ($s) use ($umbral) {
                    $s->where('estado', Estados::EN_COLA)->where('encolada_en', '<', $umbral)->where(fn ($t) => $t->whereNull('progreso_actualizado_en')->orWhere('progreso_actualizado_en', '<', $umbral));
                })->orWhere(function ($s) use ($umbral) {
                    $s->where('estado', Estados::PREPARANDO)->where('etapa', 'en_cola_2')->where('progreso_actualizado_en', '<', $umbral);
                });
            }
        })->whereNull('ejecucion_token')->update([
            'estado' => Estados::EN_COLA, 'etapa' => 'cola', 'job_uuid' => $uuid, 'encolada_en' => now(), 'progreso_actualizado_en' => now(),
            'progreso_mensaje' => null, 'mensaje' => null, 'filas_procesadas' => 0, 'filas_total' => null, 'contadores' => null, 'resultado' => null,
            'finalizada_en' => null,
        ]);

        if ($n === 0) {
            throw new OperacionInvalidaException('CARGA_OCUPADA', 'La carga ya se está procesando o no admite esta acción en su estado actual ('.$carga->fresh()->estado.').');
        }

        try {
            LeerYAuditarCarga::dispatch($carga->id, $uuid);
        } catch (Throwable $e) {
            Carga::where('id', $carga->id)->where('job_uuid', $uuid)->update($anterior + ['mensaje' => 'No se pudo encolar el proceso.']);

            throw new OperacionInvalidaException('COLA_NO_DISPONIBLE', 'No se pudo encolar el proceso: '.$e->getMessage());
        }

        return $carga->fresh();
    }

    protected function encolarVistaPrevia(Carga $carga): void
    {
        $uuid = (string) Str::uuid();
        $carga->forceFill(['etapa' => 'vista_previa', 'job_uuid' => $uuid, 'encolada_en' => now(), 'progreso_actualizado_en' => now(), 'progreso_mensaje' => 'Preparando la vista previa del archivo', 'encabezados' => null])->save();
        GenerarVistaPreviaCarga::dispatch($carga->id, $uuid);
    }

    // ---------------------------------------------------------------------------------------------------------
    // Ejecutar (E2) y recuperar
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Puerta de la ejecucion. Aplica la politica de errores DEL TIPO (congelada al validar; nunca se elige aqui):
     *  - bloquear_todo con filas rechazadas: 409 aunque se llame directamente al endpoint;
     *  - procesar_validas con rechazadas: exige confirmar el numero EXACTO de rechazadas (422 si falta o no coincide).
     * Despues, CAS `validada | fallida_ejecucion → lista` (doble clic, dos pestanas: solo uno gana) y encola el job.
     */
    public function ejecutar($admin, Carga $carga, array $params): Carga
    {
        $this->soloPermitidos(array_keys($params), self::PARAMETROS_EJECUTAR);
        $carga->refresh();

        if (! in_array($carga->estado, [Estados::VALIDADA, Estados::FALLIDA_EJECUCION], true)) {
            throw new OperacionInvalidaException('ESTADO_NO_EJECUTABLE', 'Solo se puede ejecutar una carga validada (estado actual: '.$carga->estado.').');
        }

        $v = TipoVersion::find($carga->tipo_version_id);

        if (! $v || $v->estado !== TipoVersion::ACTIVA) {
            throw new OperacionInvalidaException('VERSION_CAMBIADA', 'La versión del tipo con la que se validó ya no está activa. Cree la carga de nuevo.');
        }

        $r = $this->resumen->calcular($carga);

        if (! $r['puede_ejecutar']) {
            throw new OperacionInvalidaException('EJECUCION_BLOQUEADA_POR_POLITICA', (string) $r['motivo_bloqueo'], ['rechazadas' => $r['filas_rechazadas'], 'politica' => $r['politica_errores']]);
        }

        if ($r['confirmar_rechazadas'] !== null) {
            $dado = $params['confirmar_rechazadas'] ?? null;

            if (! (is_int($dado) || (is_string($dado) && ctype_digit($dado))) || (int) $dado !== (int) $r['confirmar_rechazadas']) {
                throw new OperacionInvalidaException('CONFIRMACION_REQUERIDA', 'Confirme que continúa con '.$r['confirmar_rechazadas'].' fila(s) rechazada(s) escribiendo ese número.', ['rechazadas' => $r['confirmar_rechazadas']]);
            }
        }

        $uuid = (string) Str::uuid();
        $anterior = $carga->estado;

        $n = Carga::where('id', $carga->id)->where('estado', $anterior)->update([
            'estado' => Estados::LISTA, 'etapa' => 'cola_ejecucion', 'job_uuid' => $uuid, 'ejecucion_token' => bin2hex(random_bytes(32)),
            'encolada_en' => now(), 'progreso_actualizado_en' => now(), 'progreso_mensaje' => 'Ejecución en cola', 'mensaje' => null,
            'conexion_ejecutor' => null, 'bloqueo_nombre' => null, 'finalizada_en' => null,
        ]);

        if ($n === 0) {
            throw new OperacionInvalidaException('CARGA_OCUPADA', 'La carga ya se está ejecutando (otra pestaña o un doble clic).');
        }

        try {
            EjecutarCarga::dispatch($carga->id, $uuid);
        } catch (Throwable $e) {
            Carga::where('id', $carga->id)->where('job_uuid', $uuid)->where('estado', Estados::LISTA)->update(['estado' => $anterior, 'mensaje' => 'No se pudo encolar la ejecución.']);

            throw new OperacionInvalidaException('COLA_NO_DISPONIBLE', 'No se pudo encolar la ejecución: '.$e->getMessage());
        }

        return $carga->fresh();
    }

    /** Recuperacion explicita de una ejecucion interrumpida (ver EjecucionService::recuperar). */
    public function recuperar(Carga $carga): Carga
    {
        return $this->ejecucion->recuperar($carga);
    }

    // ---------------------------------------------------------------------------------------------------------
    // Relaciones y comparaciones
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Valida la relacion campo => indice de columna contra los campos del tipo y los encabezados REALES de la carga.
     *
     * @return array<string, int|null>
     */
    protected function validarRelaciones(Carga $carga, TipoVersion $v, string $accion, array $relaciones): array
    {
        $campos = array_column((array) $v->map['campos'], null, 'nombre');
        $ancho = count((array) ($carga->encabezados['columnas'] ?? []));
        $limpia = [];
        $usadas = [];

        foreach ($relaciones as $campo => $idx) {
            if (! isset($campos[$campo])) {
                throw new OperacionInvalidaException('RELACION_CAMPO_DESCONOCIDO', "El campo «{$campo}» no existe en el tipo.");
            }

            if ($idx === null || $idx === '') {
                $limpia[$campo] = null;

                continue;
            }

            if (! is_int($idx) && ! (is_string($idx) && ctype_digit($idx))) {
                throw new OperacionInvalidaException('RELACION_COLUMNA_INVALIDA', "La columna elegida para «{$campo}» no es válida.");
            }

            $idx = (int) $idx;

            if ($idx < 0 || $idx >= $ancho) {
                throw new OperacionInvalidaException('RELACION_COLUMNA_INVALIDA', "La columna elegida para «{$campo}» no existe en el archivo.");
            }

            if (isset($usadas[$idx])) {
                throw new OperacionInvalidaException('RELACION_COLUMNA_REPETIDA', "La columna {$carga->encabezados['columnas'][$idx]['letra']} está relacionada con «{$usadas[$idx]}» y con «{$campo}».");
            }

            $usadas[$idx] = $campo;
            $limpia[$campo] = $idx;
        }

        foreach ($campos as $nombre => $campo) {
            $limpia[$nombre] ??= null;

            if (Esquema::esObligatorio($campo, $accion) && $limpia[$nombre] === null) {
                throw new OperacionInvalidaException('RELACION_FALTA_OBLIGATORIO', "Falta relacionar el campo obligatorio «{$campo['etiqueta']}».");
            }
        }

        return $limpia;
    }

    protected function guardarComparacion($admin, Carga $carga, TipoVersion $v, string $nombre, array $relaciones): Comparacion
    {
        $cols = (array) $carga->encabezados['columnas'];

        return Comparacion::create([
            'tipo_id' => $v->tipo_id, 'tipo_version_id' => $v->id, 'nombre' => $nombre,
            'firma_encabezados' => (string) $carga->encabezados['firma'],
            'relaciones' => array_map(fn ($idx) => $idx === null ? null : ['indice' => $idx, 'texto' => $cols[$idx]['texto'] ?? ''], $relaciones),
            'predeterminada' => false, 'creada_por_admin_id' => $admin->id, 'creada_por_nombre' => $admin->name,
        ]);
    }

    /** Comparaciones guardadas del tipo; marca cuales tienen los mismos encabezados que el archivo actual. */
    public function comparaciones(Carga $carga): array
    {
        $firma = (string) ($carga->encabezados['firma'] ?? '');

        return Comparacion::where('tipo_id', $carga->tipo_id)->orderByDesc('id')->limit(50)->get()
            ->map(fn (Comparacion $c) => ['id' => $c->id, 'nombre' => $c->nombre, 'mismos_encabezados' => $c->firma_encabezados === $firma, 'creada_por' => $c->creada_por_nombre, 'fecha' => $c->created_at?->toDateString()])->all();
    }

    /**
     * Aplica una comparacion guardada al archivo actual y devuelve las DIFERENCIAS para mostrarlas antes de aceptarla:
     * por campo, la columna encontrada y si esta igual, cambio de posicion o ya no existe.
     */
    public function aplicarComparacion(Carga $carga, int $comparacionId): array
    {
        $c = Comparacion::where('tipo_id', $carga->tipo_id)->findOrFail($comparacionId);
        $cols = (array) ($carga->encabezados['columnas'] ?? []);
        $resultado = [];

        foreach ((array) $c->relaciones as $campo => $r) {
            if ($r === null) {
                $resultado[$campo] = ['indice' => null, 'estado' => 'sin_relacionar'];

                continue;
            }

            $mismoLugar = ($cols[$r['indice']]['texto'] ?? null) === $r['texto'];
            $candidatas = array_keys(array_filter($cols, fn ($col) => $col['texto'] === $r['texto']));

            if ($mismoLugar) {
                $resultado[$campo] = ['indice' => $r['indice'], 'estado' => 'igual'];
            } elseif (count($candidatas) === 1 && $r['texto'] !== '') {
                $resultado[$campo] = ['indice' => $candidatas[0], 'estado' => 'movida', 'antes' => $r['indice']];
            } else {
                $resultado[$campo] = ['indice' => null, 'estado' => 'no_existe', 'texto' => $r['texto']];
            }
        }

        return ['comparacion' => ['id' => $c->id, 'nombre' => $c->nombre], 'relaciones' => $resultado];
    }

    // ---------------------------------------------------------------------------------------------------------
    // Consulta
    // ---------------------------------------------------------------------------------------------------------

    public function detalle(Carga $carga): array
    {
        $carga->refresh();
        $terminada = in_array($carga->estado, [Estados::VALIDADA, Estados::COMPLETADA, Estados::FALLIDA_EJECUCION], true);
        // Version REAL del tipo con la que se creo y valida esta carga (no la vigente: el tipo puede tener una version mas nueva).
        $version = TipoVersion::find($carga->tipo_version_id, ['id', 'numero', 'procedimiento']);

        return [
            'id' => $carga->id, 'uuid' => $carga->uuid, 'tipo_id' => $carga->tipo_id, 'tipo_version_id' => $carga->tipo_version_id,
            'version' => $version ? ['numero' => (int) $version->numero, 'procedimiento' => $version->procedimiento] : null,
            'estado' => $carga->estado, 'accion' => $carga->accion, 'formato' => $carga->formato,
            'archivo' => ['nombre' => $carga->archivo_nombre, 'bytes' => $carga->archivo_bytes, 'hash' => $carga->archivo_hash],
            'lectura' => [
                'hoja' => $carga->hoja, 'fila_encabezado' => $carga->fila_encabezado, 'delimitador' => $carga->delimitador,
                'entrecomillado' => $carga->entrecomillado, 'codificacion' => $carga->codificacion,
            ],
            'encabezados' => $carga->encabezados, 'relaciones' => $carga->relaciones,
            'progreso' => $this->progreso->consultar($carga),
            'resumen' => $terminada ? $this->resumen->calcular($carga) : null,
            'mensaje' => $carga->mensaje, 'admin' => $carga->admin_nombre,
            'creada_en' => $carga->created_at?->toIso8601String(),
        ];
    }

    public function historial(int $limite = 50): array
    {
        return Carga::with(['tipo:id,nombre', 'version:id,numero'])->orderByDesc('id')->limit($limite)->get()->map(fn (Carga $c) => [
            'id' => $c->id, 'tipo' => $c->tipo?->nombre, 'version' => $c->version?->numero, 'archivo' => $c->archivo_nombre, 'estado' => $c->estado, 'admin' => $c->admin_nombre,
            'filas_total' => $c->filas_total, 'contadores' => $c->contadores, 'fecha' => $c->created_at?->format('Y-m-d H:i'),
        ])->all();
    }

    // ---------------------------------------------------------------------------------------------------------
    // Utilidades
    // ---------------------------------------------------------------------------------------------------------

    /** Un parametro que no esta en la lista se RECHAZA (no se ignora en silencio). */
    public function soloPermitidos(array $claves, array $permitidos): void
    {
        $extra = array_diff($claves, $permitidos);

        if ($extra) {
            throw new OperacionInvalidaException('PARAMETROS_DESCONOCIDOS', 'Parámetros no permitidos: '.implode(', ', $extra).'.', ['parametros' => array_values($extra)]);
        }
    }

    protected function exigirEditable(Carga $carga): void
    {
        $carga->refresh();

        if (! in_array($carga->estado, [Estados::RECIBIDA, Estados::VALIDADA, Estados::FALLIDA_LECTURA, Estados::FALLIDA_PREPARACION, Estados::FALLIDA_VALIDACION], true) || $carga->ejecucion_token) {
            throw new OperacionInvalidaException('CARGA_NO_EDITABLE', 'La carga ya se está procesando, fue cancelada o inició su ejecución.');
        }
    }

    protected function aplicarLectura(Carga $carga, array $p): bool
    {
        $cambios = [];

        if (array_key_exists('hoja', $p) && $carga->formato === 'xlsx') {
            $h = $p['hoja'];

            if ($h !== null && (! is_string($h) || mb_strlen($h) > 191)) {
                throw new OperacionInvalidaException('LECTURA_INVALIDA', 'Hoja inválida.');
            }

            $cambios['hoja'] = $h === '' ? null : $h;
        }

        if (array_key_exists('fila_encabezado', $p)) {
            $f = $p['fila_encabezado'];

            if (! (is_int($f) || (is_string($f) && ctype_digit($f))) || (int) $f < 1 || (int) $f > 1000) {
                throw new OperacionInvalidaException('LECTURA_INVALIDA', 'La fila de encabezados debe estar entre 1 y 1000.');
            }

            $cambios['fila_encabezado'] = (int) $f;
        }

        if ($carga->formato === 'csv') {
            foreach (['delimitador' => OpcionesLectura::DELIMITADORES, 'entrecomillado' => OpcionesLectura::ENTRECOMILLADOS, 'codificacion' => OpcionesLectura::CODIFICACIONES] as $k => $permitidos) {
                if (array_key_exists($k, $p)) {
                    $valor = $k === 'delimitador' && $p[$k] === '\t' ? "\t" : $p[$k];

                    if (! in_array($valor, $permitidos, true)) {
                        throw new OperacionInvalidaException('LECTURA_INVALIDA', "Valor no permitido para {$k}.");
                    }

                    $cambios[$k] = $valor;
                }
            }
        }

        // Solo cuenta lo que de verdad cambia: reenviar los mismos valores no invalida nada.
        $cambios = array_filter($cambios, fn ($valor, $k) => $carga->{$k} !== $valor, ARRAY_FILTER_USE_BOTH);

        if ($cambios) {
            $carga->forceFill($cambios + ['relaciones' => null, 'config_congelada' => null, 'contadores' => null])->save();

            if ($carga->estado !== Estados::RECIBIDA) {
                $carga->forceFill(['estado' => Estados::RECIBIDA, 'mensaje' => null])->save();
            }
        }

        return $cambios !== [];
    }
}
