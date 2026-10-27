<?php

namespace ByLopez\Cargues\Services\Cargas;

use ByLopez\Cargues\Exceptions\OperacionInvalidaException;
use ByLopez\Cargues\Models\Carga;
use ByLopez\Cargues\Models\TipoVersion;
use ByLopez\Cargues\Services\Configuracion\ConexionTipo;
use ByLopez\Cargues\Services\Configuracion\ProcedimientoService;
use ByLopez\Cargues\Support\Estados;
use ByLopez\Cargues\Support\ExtensionesTipo;
use PDO;
use Throwable;

/**
 * EJECUTAR (07 §8, 09 §2.2), modelo transaccional `motor`: en una sesion DEDICADA de la conexion del tipo,
 *   bloqueo con nombre → huella del procedimiento y de la entrada → CAS `lista → ejecutando` → BEGIN → CALL 'EJECUTAR'
 *   → comprobaciones del contrato → COMMIT (o ROLLBACK) → consolidacion → `completada`.
 *
 * Contrato que se exige ANTES de confirmar (si falla: ROLLBACK, no queda ningun efecto ni marca):
 *   - toda fila elegible (`cg_estado IS NULL` al empezar) queda marcada con un estado de MARCAS_EJECUCION;
 *   - los datos de entrada no cambian y las marcas previas (rechazadas en la validacion) quedan identicas;
 *   - el resultado es OK o PARCIAL (ERROR o SIGNAL deshacen todo);
 *   - la sesion conserva el bloqueo.
 *
 * Certeza del resultado: si la ejecucion falla y el ROLLBACK se confirma, la carga queda `fallida_ejecucion` (no se
 * aplico nada; se puede ejecutar de nuevo). Si se pierde la conexion, el resultado es INCIERTO: la carga queda
 * `ejecutando` y solo la recuperacion (`recuperar`) la clasifica cuando el servidor confirma que la sesion termino.
 * El modelo `procedimiento` (commits por lotes) no esta habilitado en esta entrega.
 *
 * TIPOS CON EXTENSION (docs/14): si el map nombra una extension, el procedimiento solo PLANEA (marca las rechazadas y deja
 * `cg_detalle` en las demas, sin marca final) y, confirmado el COMMIT, la extension APLICA por los servicios de Laravel, una fila
 * por transaccion, marcandolas. Es reanudable: un fallo a medias deja `fallida_ejecucion` con lo ya aplicado marcado, y volver a
 * ejecutar solo procesa las filas pendientes. El bloqueo con nombre se conserva durante la aplicacion.
 */
class EjecucionService
{
    public function __construct(
        protected ConexionTipo $conexiones,
        protected ProcedimientoService $procedimientos,
        protected SesionProcedimiento $sesion,
        protected ConsolidacionService $consolidacion,
        protected ResumenService $resumen,
        protected ExtensionesTipo $extensiones,
    ) {}

    /** Punto de entrada del job: solo actua si sigue siendo el dueno de una carga `lista`. */
    public function correr(int $cargaId, string $jobUuid): void
    {
        $carga = Carga::where('id', $cargaId)->where('job_uuid', $jobUuid)->where('estado', Estados::LISTA)->first();

        if (! $carga) {
            return;
        }

        try {
            $this->ejecutar($carga, $jobUuid);
        } catch (OperacionInvalidaException $e) {
            if ($e->codigo !== 'JOB_SUPERADO') {
                $this->registrarFallo($carga->id, $jobUuid, $e, (bool) ($e->detalles['revertida'] ?? false));
            }
        } catch (Throwable $e) {
            report($e);
            $incierto = $this->sesion->esConexionPerdida($e);
            $this->registrarFallo($carga->id, $jobUuid, new OperacionInvalidaException(
                $incierto ? 'CONEXION_PERDIDA' : 'ERROR_INESPERADO',
                $incierto ? 'Se perdió la conexión con el servidor durante la ejecución: el resultado es incierto. Use «Recuperar».' : 'Error inesperado ('.class_basename($e).'): '.mb_substr($e->getMessage(), 0, 300),
            ), false);
        }
    }

    public function ejecutar(Carga $carga, string $jobUuid): void
    {
        $cfg = (array) $carga->config_congelada;
        $map = (array) $cfg['map'];
        $tabla = (string) $map['tabla'];
        $conexion = (string) $cfg['conexion'];

        if (($map['transaccion'] ?? null) !== 'motor') {
            throw new OperacionInvalidaException('MODELO_NO_HABILITADO', 'Esta entrega solo ejecuta procedimientos del modelo transaccional «motor» (todo o nada).');
        }

        $c = $this->conexiones->conexion($conexion);
        $version = TipoVersion::findOrFail($carga->tipo_version_id);
        $actual = $this->procedimientos->congelar($c, $version->procedimiento);

        if (! $actual || $actual['huella'] !== $version->procedimiento_huella) {
            throw new OperacionInvalidaException('PROCEDIMIENTO_CAMBIO', 'El procedimiento cambió (definición o sql_mode) desde que se activó el tipo. No se ejecutó nada.');
        }

        $bloqueo = $this->sesion->nombreBloqueo($this->conexiones->esquema($c), $carga->id);
        $pdo = $this->conexiones->pdoDedicada($conexion);
        $tomado = false;
        $extension = $this->extensiones->de($map);

        try {
            if (! $this->sesion->tomarBloqueo($pdo, $bloqueo)) {
                throw new OperacionInvalidaException('CARGA_OCUPADA', 'Otro proceso está usando esta carga en el servidor del tipo. No se ejecutó nada.');
            }

            $tomado = true;
            $this->sesion->topeDeTiempo($pdo);

            if ($this->sesion->huellaEntrada($pdo, $map, $carga->id) !== $carga->huella_entrada) {
                throw new OperacionInvalidaException('ENTRADA_ALTERADA', 'Los datos de entrada de la intermedia no coinciden con los validados. No se ejecutó nada.');
            }

            $elegibles = (int) $pdo->query("SELECT COUNT(*) FROM `{$tabla}` WHERE cg_carga_id = ".(int) $carga->id.' AND cg_estado IS NULL')->fetchColumn();

            if ($elegibles === 0) {
                throw new OperacionInvalidaException('SIN_FILAS_ELEGIBLES', 'No hay filas válidas pendientes de ejecutar.');
            }

            $previas = $this->marcasPrevias($pdo, $tabla, $carga->id);
            $conexionId = (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();

            // CAS: solo una ejecucion pasa a `ejecutando`. Lo que se guarda aqui es lo que usa la recuperacion.
            $n = Carga::where('id', $carga->id)->where('job_uuid', $jobUuid)->where('estado', Estados::LISTA)->update([
                'estado' => Estados::EJECUTANDO, 'etapa' => 'ejecutar_procedimiento', 'conexion_ejecutor' => $conexionId, 'bloqueo_nombre' => $bloqueo,
                'filas_total' => $elegibles, 'filas_procesadas' => 0, 'progreso_actualizado_en' => now(), 'progreso_mensaje' => 'Ejecutando el procedimiento',
                'resultado' => json_encode(['ejecucion' => ['elegibles' => $elegibles, 'conexion' => $conexionId]], JSON_UNESCAPED_UNICODE),
            ]);

            if ($n === 0) {
                throw new OperacionInvalidaException('JOB_SUPERADO', 'Otro proceso tomó la carga.');
            }

            $pdo->beginTransaction();

            try {
                $out = $this->sesion->llamar($pdo, $version->procedimiento, $carga, 'EJECUTAR');
                $this->sesion->exigirBloqueo($pdo, $bloqueo);
                $this->exigirContrato($pdo, $map, $carga, $previas, $out, $extension !== null);
                $pdo->commit();
            } catch (Throwable $e) {
                $this->deshacer($pdo, $e);
            }

            // Extension: el plan ya esta confirmado; ahora se APLICA por los servicios de Laravel (con el bloqueo tomado).
            if ($extension !== null) {
                $this->aplicarExtension($extension, $carga, $map, $jobUuid, $pdo, $tabla, $elegibles);
            }
        } finally {
            if ($tomado) {
                $this->sesion->liberarBloqueo($pdo, $bloqueo);
            }

            $pdo = null; // cierra la sesion: el servidor libera el bloqueo
        }

        // Confirmado en el servidor del tipo: desde aqui un fallo NO deshace nada, la consolidacion se repite.
        Carga::where('id', $carga->id)->where('job_uuid', $jobUuid)->where('estado', Estados::EJECUTANDO)->update([
            'estado' => Estados::CONSOLIDANDO, 'etapa' => 'consolidando', 'progreso_actualizado_en' => now(), 'progreso_mensaje' => 'Registrando el resultado',
            'resultado' => json_encode(['ejecucion' => ['elegibles' => $elegibles ?? null, 'procedimiento' => $out]], JSON_UNESCAPED_UNICODE),
        ]);

        $this->completar($carga->fresh(), $jobUuid);
    }

    /**
     * Aplica el plan con la extension del tipo. Un fallo aqui NO deshace lo ya aplicado (cada fila fue una transaccion con su
     * marca): la carga queda `fallida_ejecucion` y volver a ejecutar solo procesa las filas pendientes.
     */
    protected function aplicarExtension(\ByLopez\Cargues\Contracts\ExtensionTipo $extension, Carga $carga, array $map, string $jobUuid, PDO $pdo, string $tabla, int $elegibles): void
    {
        Carga::where('id', $carga->id)->where('job_uuid', $jobUuid)->where('estado', Estados::EJECUTANDO)->update([
            'etapa' => 'aplicar_cambios', 'progreso_actualizado_en' => now(), 'progreso_mensaje' => 'Aplicando los cambios fila por fila',
        ]);

        $progreso = function (int $hechas, int $total) use ($carga, $jobUuid) {
            Carga::where('id', $carga->id)->where('job_uuid', $jobUuid)->where('estado', Estados::EJECUTANDO)->update(['filas_procesadas' => $hechas, 'filas_total' => $total, 'progreso_actualizado_en' => now()]);
        };

        try {
            $extension->aplicar($carga->fresh(), $map, $progreso);
        } catch (Throwable $e) {
            report($e);
            $pend = (int) $pdo->query("SELECT COUNT(*) FROM `{$tabla}` WHERE cg_carga_id = ".(int) $carga->id.' AND cg_estado IS NULL')->fetchColumn();

            throw new OperacionInvalidaException('APLICACION_INTERRUMPIDA', 'La aplicación se interrumpió ('.class_basename($e).': '.mb_substr($e->getMessage(), 0, 250).'). Las filas ya aplicadas quedaron marcadas; faltan '.$pend.' de '.$elegibles.'. Puede ejecutar de nuevo: solo se procesan las pendientes.', ['revertida' => true]);
        }

        $pend = (int) $pdo->query("SELECT COUNT(*) FROM `{$tabla}` WHERE cg_carga_id = ".(int) $carga->id.' AND cg_estado IS NULL')->fetchColumn();

        if ($pend > 0) {
            throw new OperacionInvalidaException('APLICACION_INCOMPLETA', "La extensión terminó sin marcar {$pend} fila(s). Puede ejecutar de nuevo: solo se procesan las pendientes.", ['revertida' => true]);
        }
    }

    /** Consolida y cierra la carga (idempotente). Con `$jobUuid` null lo hace la recuperacion. */
    public function completar(Carga $carga, ?string $jobUuid): Carga
    {
        $r = $this->consolidacion->consolidar($carga);
        $contadores = $this->resumen->calcular($carga->fresh());
        $q = Carga::where('id', $carga->id)->where('estado', Estados::CONSOLIDANDO);

        if ($jobUuid !== null) {
            $q->where('job_uuid', $jobUuid);
        }

        $resultado = (array) ($carga->resultado ?? []);
        $resultado['consolidacion'] = $r;

        $q->update([
            'estado' => Estados::COMPLETADA, 'etapa' => 'finalizado', 'contadores' => json_encode($contadores, JSON_UNESCAPED_UNICODE),
            'filas_total' => $contadores['filas_total'], 'filas_procesadas' => $contadores['filas_total'], 'mensaje' => null,
            'resultado' => json_encode($resultado, JSON_UNESCAPED_UNICODE),
            'progreso_mensaje' => 'Ejecución terminada', 'progreso_actualizado_en' => now(), 'finalizada_en' => now(),
        ]);

        return $carga->fresh();
    }

    /**
     * Recuperacion (07 §8.4 punto 4): NUNCA clasifica mientras la sesion ejecutora siga viva en el servidor.
     *  - `consolidando`: los efectos ya estan confirmados → consolida y completa;
     *  - `ejecutando`: con el bloqueo libre y la conexion terminada, decide por las marcas: todas las elegibles marcadas
     *    = se confirmo → consolida; ninguna = se revirtio → `fallida_ejecucion` (se puede ejecutar de nuevo);
     *  - `lista` sin proceso vivo: no llego a ejecutar → `fallida_ejecucion`.
     */
    public function recuperar(Carga $carga): Carga
    {
        $carga->refresh();

        if ($carga->estado === Estados::CONSOLIDANDO) {
            return $this->completar($carga, null);
        }

        if ($carga->estado === Estados::LISTA) {
            $umbral = now()->subSeconds((int) config('cargues.umbral_sin_noticias', 120));

            if ($carga->progreso_actualizado_en && $carga->progreso_actualizado_en->gt($umbral)) {
                throw new OperacionInvalidaException('EJECUCION_EN_CURSO', 'La ejecución está en cola o empezando; espere.');
            }

            $this->marcar($carga->id, Estados::LISTA, Estados::FALLIDA_EJECUCION, '[NO_INICIADA] La ejecución no llegó a empezar: no se aplicó nada. Puede ejecutar de nuevo.');

            return $carga->fresh();
        }

        if ($carga->estado !== Estados::EJECUTANDO) {
            throw new OperacionInvalidaException('NADA_QUE_RECUPERAR', 'La carga no está en ejecución (estado: '.$carga->estado.').');
        }

        $cfg = (array) $carga->config_congelada;
        $c = $this->conexiones->conexion((string) $cfg['conexion']);

        if ($carga->bloqueo_nombre && $c->selectOne('SELECT IS_USED_LOCK(?) AS u', [$carga->bloqueo_nombre])->u !== null) {
            throw new OperacionInvalidaException('EJECUCION_EN_CURSO', 'El procedimiento sigue corriendo en el servidor (conexión '.$c->selectOne('SELECT IS_USED_LOCK(?) AS u', [$carga->bloqueo_nombre])->u.'). No se clasifica nada mientras tanto.');
        }

        if ($carga->conexion_ejecutor && (int) $c->selectOne('SELECT COUNT(*) AS n FROM information_schema.PROCESSLIST WHERE ID = ?', [$carga->conexion_ejecutor])->n > 0) {
            throw new OperacionInvalidaException('EJECUCION_EN_CURSO', 'La sesión ejecutora '.$carga->conexion_ejecutor.' sigue abierta en el servidor. No se clasifica nada mientras tanto.');
        }

        $elegibles = (int) ($carga->resultado['ejecucion']['elegibles'] ?? -1);
        $nulas = (int) $c->table((string) $cfg['map']['tabla'])->where('cg_carga_id', $carga->id)->whereNull('cg_estado')->count();

        if ($nulas === 0) {
            Carga::where('id', $carga->id)->where('estado', Estados::EJECUTANDO)->update(['estado' => Estados::CONSOLIDANDO, 'etapa' => 'consolidando', 'progreso_actualizado_en' => now()]);

            return $this->completar($carga->fresh(), null);
        }

        if ($nulas === $elegibles) {
            $this->marcar($carga->id, Estados::EJECUTANDO, Estados::FALLIDA_EJECUCION, '[REVERTIDA] La ejecución no llegó a confirmarse: el servidor la deshizo y no se aplicó nada. Puede ejecutar de nuevo.');

            return $carga->fresh();
        }

        if ($this->extensiones->de((array) $cfg['map']) !== null) {
            $this->marcar($carga->id, Estados::EJECUTANDO, Estados::FALLIDA_EJECUCION, "[PARCIAL] La aplicación se interrumpió: quedan {$nulas} de {$elegibles} filas pendientes y las demás ya están aplicadas y marcadas. Puede ejecutar de nuevo: solo se procesan las pendientes.");

            return $carga->fresh();
        }

        throw new OperacionInvalidaException('RESULTADO_INCIERTO', "Quedan {$nulas} de {$elegibles} filas sin marca: el resultado no encaja con el modelo «motor». Revise la intermedia antes de continuar.");
    }

    // ---------------------------------------------------------------------------------------------------------

    /** @param  array<int, string>  $previas  cg_id => estado|codigo de las filas ya marcadas antes del CALL */
    protected function exigirContrato(PDO $pdo, array $map, Carga $carga, array $previas, array $out, bool $conExtension = false): void
    {
        $tabla = (string) $map['tabla'];

        // Sin extension, toda fila elegible queda marcada. Con extension, las no rechazadas quedan PLANEADAS (cg_detalle) y las marca la aplicacion.
        $sinMarca = (int) $pdo->query("SELECT COUNT(*) FROM `{$tabla}` WHERE cg_carga_id = ".(int) $carga->id.' AND cg_estado IS NULL'.($conExtension ? ' AND cg_detalle IS NULL' : ''))->fetchColumn();

        if ($sinMarca > 0) {
            throw new OperacionInvalidaException('MARCAS_INCOMPLETAS', "El procedimiento dejó {$sinMarca} fila(s) ".($conExtension ? 'sin plan ni marca' : 'sin marcar').': no cumple el contrato. Se deshizo todo.');
        }

        $estados = $pdo->query("SELECT DISTINCT cg_estado FROM `{$tabla}` WHERE cg_carga_id = ".(int) $carga->id.($conExtension ? ' AND cg_estado IS NOT NULL' : ''))->fetchAll(PDO::FETCH_COLUMN);

        if (array_diff($estados, Estados::MARCAS_EJECUCION) !== []) {
            throw new OperacionInvalidaException('ESTADO_INVALIDO', 'El procedimiento marcó estados fuera del contrato: '.implode(', ', array_diff($estados, Estados::MARCAS_EJECUCION)).'. Se deshizo todo.');
        }

        if ($this->sesion->huellaEntrada($pdo, $map, $carga->id) !== $carga->huella_entrada) {
            throw new OperacionInvalidaException('EJECUTAR_MODIFICO_ENTRADA', 'El procedimiento modificó los datos de entrada de la intermedia. Se deshizo todo.');
        }

        if ($previas !== [] && $this->marcasDe($pdo, $tabla, $carga->id, array_keys($previas)) !== $previas) {
            throw new OperacionInvalidaException('MARCAS_PREVIAS_ALTERADAS', 'El procedimiento cambió marcas de filas ya rechazadas en la validación. Se deshizo todo.');
        }

        if (! in_array($out['resultado'], ['OK', 'PARCIAL'], true)) {
            throw new OperacionInvalidaException($out['resultado'] === 'ERROR' ? 'PROCEDIMIENTO_ERROR' : 'PROCEDIMIENTO_RESULTADO_INVALIDO', 'El procedimiento respondió '.($out['resultado'] ?: 'sin resultado').($out['mensaje'] ? ': '.$out['mensaje'] : '').'. Se deshizo todo.');
        }
    }

    /** ROLLBACK y relanza con la certeza correcta: revertida si el servidor lo confirmo, incierta si se perdio la conexion. */
    protected function deshacer(PDO $pdo, Throwable $e): never
    {
        try {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable $r) {
            throw $this->sesion->esConexionPerdida($r) || $this->sesion->esConexionPerdida($e) ? $e : $r;
        }

        if ($this->sesion->esConexionPerdida($e)) {
            throw $e;
        }

        $base = $e instanceof OperacionInvalidaException ? $e : new OperacionInvalidaException('PROCEDIMIENTO_FALLO', 'El procedimiento falló al ejecutar: '.mb_substr($e->getMessage(), 0, 400).'. Se deshizo todo.');

        throw new OperacionInvalidaException($base->codigo, $base->getMessage(), $base->detalles + ['revertida' => true]);
    }

    /** @return array<int, string> */
    protected function marcasPrevias(PDO $pdo, string $tabla, int $cargaId): array
    {
        $r = [];

        foreach ($pdo->query("SELECT cg_id, CONCAT_WS('|', cg_estado, IFNULL(cg_codigo, '~')) AS m FROM `{$tabla}` WHERE cg_carga_id = {$cargaId} AND cg_estado IS NOT NULL ORDER BY cg_id") as $f) {
            $r[(int) $f['cg_id']] = (string) $f['m'];
        }

        return $r;
    }

    /** @return array<int, string> */
    protected function marcasDe(PDO $pdo, string $tabla, int $cargaId, array $ids): array
    {
        $r = [];

        foreach (array_chunk($ids, 1000) as $lote) {
            foreach ($pdo->query("SELECT cg_id, CONCAT_WS('|', cg_estado, IFNULL(cg_codigo, '~')) AS m FROM `{$tabla}` WHERE cg_carga_id = {$cargaId} AND cg_id IN (".implode(',', array_map('intval', $lote)).') ORDER BY cg_id') as $f) {
                $r[(int) $f['cg_id']] = (string) $f['m'];
            }
        }

        ksort($r);

        return $r;
    }

    /** Estado final de un fallo segun lo que se SABE: nada aplicado → `fallida_ejecucion`; incierto → sigue en ejecucion. */
    protected function registrarFallo(int $cargaId, string $jobUuid, OperacionInvalidaException $e, bool $revertida): void
    {
        $c = Carga::where('id', $cargaId)->where('job_uuid', $jobUuid)->first();

        if (! $c) {
            return;
        }

        $error = json_encode(['error' => ['codigo' => $e->codigo, 'detalles' => $e->detalles]] + (array) ($c->resultado ?? []), JSON_UNESCAPED_UNICODE);

        if ($c->estado === Estados::LISTA || ($c->estado === Estados::EJECUTANDO && $revertida)) {
            Carga::where('id', $cargaId)->where('job_uuid', $jobUuid)->where('estado', $c->estado)->update([
                'estado' => Estados::FALLIDA_EJECUCION, 'mensaje' => '['.$e->codigo.'] '.$e->getMessage(), 'resultado' => $error,
                'progreso_mensaje' => null, 'finalizada_en' => now(),
            ]);

            return;
        }

        // Incierto (conexion perdida) o despues del COMMIT: no se reclasifica; la recuperacion decide.
        Carga::where('id', $cargaId)->where('job_uuid', $jobUuid)->whereIn('estado', [Estados::EJECUTANDO, Estados::CONSOLIDANDO])->update([
            'mensaje' => '['.$e->codigo.'] '.$e->getMessage().' Use «Recuperar».', 'resultado' => $error, 'progreso_actualizado_en' => now(),
        ]);
    }

    protected function marcar(int $cargaId, string $desde, string $hacia, string $mensaje): void
    {
        Carga::where('id', $cargaId)->where('estado', $desde)->update(['estado' => $hacia, 'mensaje' => $mensaje, 'progreso_mensaje' => null, 'finalizada_en' => now()]);
    }
}
