<?php

namespace ByLopez\Cargues\Services\Cargas;

use ByLopez\Cargues\Exceptions\OperacionInvalidaException;
use ByLopez\Cargues\Models\Carga;
use ByLopez\Cargues\Models\CargaDato;
use ByLopez\Cargues\Models\CargaIncidencia;
use ByLopez\Cargues\Models\TipoVersion;
use ByLopez\Cargues\Services\Configuracion\ConexionTipo;
use ByLopez\Cargues\Services\Configuracion\ProcedimientoService;
use ByLopez\Cargues\Services\Configuracion\ProteccionValidar;
use ByLopez\Cargues\Support\Estados;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use Throwable;

/**
 * P8 — `CALL sp(carga, accion, 'VALIDAR', ...)` (07 §8). VALIDAR no puede modificar negocio. Un CHECKSUM por si solo NO
 * basta, asi que son varias barreras, y ninguna se presenta como prueba total:
 *   1. revision estatica del procedimiento al activar + confirmacion humana ligada a su huella (ProcedimientoService);
 *   2. la huella del procedimiento debe coincidir con la congelada (si cambio, no se ejecuta);
 *   3. el CALL va dentro de una transaccion: si cambian las tablas de negocio DECLARADAS (CHECKSUM antes/despues) o los
 *      datos de entrada de la intermedia, o marca un estado distinto de «rechazada», se hace ROLLBACK y la carga falla;
 *   4. sesion dedicada con bloqueo con nombre (no se solapa con otra validacion ni con la ejecucion de E2);
 *   0. y ANTES de todo, la barrera preventiva (docs/12): la sesion es la de la CUENTA RESTRINGIDA y sus privilegios
 *      efectivos, el SQL SECURITY del procedimiento y las rutinas ejecutables se leen del servidor (ProteccionValidar).
 *      Con ella, una escritura en negocio (declarada o no, con o sin COMMIT interno) la niega el servidor.
 */
class ValidarProcedimientoService
{
    public function __construct(
        protected ConexionTipo $conexiones,
        protected ProcedimientoService $procedimientos,
        protected LlenadoIntermediaService $llenado,
        protected ProgresoService $progreso,
        protected ProteccionValidar $proteccion,
        protected SesionProcedimiento $sesion,
    ) {}

    /** @return array{resultado:string, mensaje:?string, rechazadas:int} */
    public function validar(Carga $carga, string $jobUuid): array
    {
        $cfg = (array) $carga->config_congelada;
        $map = (array) $cfg['map'];
        $tabla = $map['tabla'];
        $conexion = (string) $cfg['conexion'];
        $c = $this->conexiones->conexion($conexion);

        // 2. Huella del procedimiento congelada.
        $version = TipoVersion::findOrFail($carga->tipo_version_id);
        $actual = $this->procedimientos->congelar($c, $version->procedimiento);

        if (! $actual || $actual['huella'] !== $version->procedimiento_huella) {
            throw new OperacionInvalidaException('PROCEDIMIENTO_CAMBIO', 'El procedimiento cambió (definición o sql_mode) desde que se activó el tipo. Revíselo y reactive una versión del tipo.');
        }

        $this->progreso->avanzar($carga, $jobUuid, 'procedimiento', 0, null, 'Ejecutando el procedimiento en modo VALIDAR', true);

        $esquema = $this->conexiones->esquema($c);
        $bloqueo = $this->sesion->nombreBloqueo($esquema, $carga->id);
        $cuenta = $this->proteccion->cuenta($conexion);
        $pdo = $this->conexiones->pdoDedicada($conexion, null, $cuenta, false);
        $tomado = false;

        // 0. Barrera PREVIA: la sesion que ejecuta VALIDAR es la de la cuenta restringida y sus privilegios EFECTIVOS se leen
        // del servidor ahora mismo (puede haber cambiado desde la activacion).
        $bloqueos = array_values(array_filter(
            $this->proteccion->verificar($c, $conexion, $version->procedimiento, $tabla, $cuenta ? $pdo : null, $cuenta !== null),
            fn ($h) => $h['nivel'] === 'error',
        ));

        if ($bloqueos) {
            throw new OperacionInvalidaException('VALIDAR_SIN_PROTECCION', 'VALIDAR no se ejecutó: '.$bloqueos[0]['mensaje'], $bloqueos);
        }

        if ($cuenta) {
            $pdo->exec('USE `'.str_replace('`', '', $esquema).'`');
        }

        try {
            // 4. Bloqueo con nombre, sin esperar.
            if (! $this->sesion->tomarBloqueo($pdo, $bloqueo)) {
                throw new OperacionInvalidaException('CARGA_OCUPADA', 'Otro proceso está usando esta carga en el servidor del tipo.');
            }

            $tomado = true;
            $this->sesion->topeDeTiempo($pdo);

            $antesNegocio = $this->checksums($pdo, (array) ($map['tablas_negocio'] ?? []));
            $antesEntrada = $this->sesion->huellaEntrada($pdo, $map, $carga->id);

            if ($antesEntrada !== $carga->huella_entrada) {
                throw new OperacionInvalidaException('ENTRADA_ALTERADA', 'Los datos de entrada de la intermedia no coinciden con los preparados: alguien los modificó antes de validar.');
            }

            $pdo->beginTransaction();

            try {
                $out = $this->sesion->llamar($pdo, $version->procedimiento, $carga, 'VALIDAR');

                $this->sesion->exigirBloqueo($pdo, $bloqueo);

                // 3. Barreras sobre lo que VALIDAR dejo hecho (aun sin confirmar).
                $despuesNegocio = $this->checksums($pdo, (array) ($map['tablas_negocio'] ?? []));

                if ($despuesNegocio !== $antesNegocio) {
                    throw new OperacionInvalidaException('VALIDAR_MODIFICO_NEGOCIO', 'VALIDAR modificó tablas de negocio ('.implode(', ', array_keys(array_diff_assoc($despuesNegocio, $antesNegocio))).'): se deshizo todo y la carga queda en error. Corrija el procedimiento.');
                }

                if ($this->sesion->huellaEntrada($pdo, $map, $carga->id) !== $antesEntrada) {
                    throw new OperacionInvalidaException('VALIDAR_MODIFICO_ENTRADA', 'VALIDAR modificó los datos de entrada de la intermedia: se deshizo todo y la carga queda en error.');
                }

                $estados = $pdo->query("SELECT DISTINCT cg_estado FROM `{$tabla}` WHERE cg_carga_id = ".(int) $carga->id.' AND cg_estado IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);

                if (array_diff($estados, [Estados::FILA_RECHAZADA]) !== []) {
                    throw new OperacionInvalidaException('VALIDAR_ESTADO_INVALIDO', 'VALIDAR solo puede marcar filas como «rechazada»; marcó: '.implode(', ', $estados).'.');
                }

                if (! in_array($out['resultado'], ['OK', 'PARCIAL', 'ERROR'], true)) {
                    throw new OperacionInvalidaException('PROCEDIMIENTO_RESULTADO_INVALIDO', 'El procedimiento devolvió un resultado fuera del contrato (OK, PARCIAL o ERROR).');
                }

                if ($out['resultado'] === 'ERROR') {
                    throw new OperacionInvalidaException('PROCEDIMIENTO_ERROR', 'El procedimiento respondió ERROR: '.($out['mensaje'] ?: 'sin mensaje').'.');
                }

                $rechazadas = $pdo->query("SELECT cg_carga_dato_id, cg_fila_origen, cg_codigo, cg_mensaje FROM `{$tabla}` WHERE cg_carga_id = ".(int) $carga->id." AND cg_estado = 'rechazada' ORDER BY cg_fila_origen")->fetchAll(PDO::FETCH_ASSOC);

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                if ($e instanceof PDOException) {
                    throw new OperacionInvalidaException('PROCEDIMIENTO_FALLO', 'El procedimiento falló en VALIDAR: '.$e->getMessage());
                }

                throw $e;
            }
        } finally {
            if ($tomado) {
                $this->sesion->liberarBloqueo($pdo, $bloqueo);
            }

            $pdo = null; // cierra la sesion: el servidor libera el bloqueo y revierte lo no confirmado
        }

        $this->consolidar($carga, $rechazadas);

        return ['resultado' => $out['resultado'], 'mensaje' => $out['mensaje'], 'rechazadas' => count($rechazadas)];
    }

    /** Refleja en T6/T7 las filas que el procedimiento marco como rechazadas (idempotente). */
    protected function consolidar(Carga $carga, array $rechazadas): void
    {
        foreach (array_chunk($rechazadas, 500) as $lote) {
            DB::transaction(function () use ($carga, $lote) {
                $inc = [];

                foreach ($lote as $r) {
                    $inc[] = [
                        'carga_id' => $carga->id, 'dato_id' => (int) $r['cg_carga_dato_id'], 'fila_origen' => (int) $r['cg_fila_origen'],
                        'etapa' => Estados::ETAPA_VALIDACION_PROCEDIMIENTO, 'campo' => '',
                        'codigo' => mb_substr((string) ($r['cg_codigo'] ?: 'RECHAZADA_POR_PROCEDIMIENTO'), 0, 60),
                        'motivo' => mb_substr((string) ($r['cg_mensaje'] ?: 'El procedimiento rechazó la fila sin indicar el motivo.'), 0, 500),
                        'severidad' => 'error', 'created_at' => now(),
                    ];
                }

                CargaDato::where('carga_id', $carga->id)->whereIn('id', array_column($lote, 'cg_carga_dato_id'))
                    ->update(['estado_final' => Estados::FILA_RECHAZADA, 'detalle' => 'RECHAZADA_POR_PROCEDIMIENTO']);
                CargaIncidencia::registrar($inc);
            });
        }
    }

    /** @return array<string, string> tabla => checksum */
    protected function checksums(PDO $pdo, array $tablas): array
    {
        $r = [];

        foreach ($tablas as $t) {
            if (! preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $t)) {
                throw new OperacionInvalidaException('TABLA_NEGOCIO_INVALIDA', "Nombre de tabla de negocio inválido: {$t}.");
            }

            $fila = $pdo->query("CHECKSUM TABLE `{$t}`")->fetch(PDO::FETCH_ASSOC);

            if (! $fila || $fila['Checksum'] === null) {
                throw new OperacionInvalidaException('TABLA_NEGOCIO_INEXISTENTE', "La tabla de negocio declarada «{$t}» no existe en la conexión del tipo.");
            }

            $r[$t] = (string) $fila['Checksum'];
        }

        return $r;
    }
}
