<?php

namespace ByLopez\Cargues\Services\Configuracion;

use ByLopez\Cargues\Exceptions\OperacionInvalidaException;
use ByLopez\Cargues\Models\Carga;
use ByLopez\Cargues\Models\Estructura;
use ByLopez\Cargues\Models\Tipo;
use ByLopez\Cargues\Models\TipoVersion;
use ByLopez\Cargues\Support\Canonico;
use ByLopez\Cargues\Support\Estados;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Activacion de una version (09 §2.1): valida, verifica el procedimiento (firma + revision de contrato confirmada),
 * congela su definicion y crea o reutiliza la intermedia. Un cambio estructural sigue 07 §5.4: sin ventana sin tabla
 * (crear `__nueva`, un solo RENAME multiple, registro en T3) y con reconciliacion ante una caida del proceso.
 */
class ActivarTipoService
{
    /** Estados de carga que bloquean un cambio estructural (las ya ejecutadas o cerradas no). */
    public const CARGAS_ABIERTAS = [
        Estados::RECIBIDA, Estados::EN_COLA, Estados::LEYENDO, Estados::PREPARANDO, Estados::VALIDANDO, Estados::VALIDADA,
        'lista', 'ejecutando', 'interrumpida', 'consolidando',
    ];

    public function __construct(
        protected TiposService $tipos,
        protected MapValidator $validador,
        protected ConexionTipo $conexiones,
        protected IntermediaService $intermedia,
        protected ProcedimientoService $procedimientos,
        protected ProteccionValidar $proteccion,
    ) {}

    public function activar($admin, TipoVersion $v): TipoVersion
    {
        if (! $v->esBorrador()) {
            throw new OperacionInvalidaException('VERSION_NO_EDITABLE', 'Solo se activa un borrador.');
        }

        $errores = $this->tipos->validar($v);

        if ($v->politica_errores === null) {
            // F1-C2b: nunca se elige sola. Se guarda como borrador y se pide elegirla.
            $errores[] = ['codigo' => 'POLITICA_ERRORES_OBLIGATORIA', 'ruta' => 'politica_errores', 'mensaje' => 'Elija la política de errores (bloquear todo o procesar las válidas) para activar el tipo.'];
        }

        if ($errores !== []) {
            throw new OperacionInvalidaException('CONFIGURACION_INVALIDA', $errores[0]['mensaje'], $errores);
        }

        $tipo = $v->tipo;
        $c = $this->conexiones->conexion($v->conexion);
        $map = $this->validador->normalizar((array) $v->map);

        $firma = $this->procedimientos->verificarFirma($c, $v->procedimiento);

        if ($firma !== []) {
            throw new OperacionInvalidaException($firma[0]['codigo'], $firma[0]['mensaje'], $firma);
        }

        $congelado = $this->procedimientos->congelar($c, $v->procedimiento);

        if (! $congelado) {
            throw new OperacionInvalidaException('DEFINICION_NO_DISPONIBLE', 'No se pudo leer la definición del procedimiento.');
        }

        $revision = $this->procedimientos->revisarContrato($congelado['definicion'], $map);
        // Proteccion PREVIA de VALIDAR (cuenta restringida, SQL SECURITY, rutinas ejecutables, triggers): se evalua en vivo.
        $revision['hallazgos'] = array_merge($revision['hallazgos'], $this->proteccion->verificar($c, $v->conexion, $v->procedimiento, $map['tabla']));
        $errorContrato = array_values(array_filter($revision['hallazgos'], fn ($h) => $h['nivel'] === 'error'));

        if ($errorContrato) {
            throw new OperacionInvalidaException('CONTRATO_PROCEDIMIENTO', $errorContrato[0]['mensaje'], $errorContrato);
        }

        if ($v->revision_huella !== $congelado['huella']) {
            throw new OperacionInvalidaException('REVISION_PENDIENTE', 'Confirme la revisión del contrato del procedimiento actual (cambió o aún no se ha confirmado).');
        }

        $estructura = $this->prepararEstructura($admin, $tipo, $v, $c, $map);

        $activada = DB::transaction(function () use ($tipo, $v, $map, $congelado, $revision, $estructura) {
            TipoVersion::where('tipo_id', $tipo->id)->where('estado', TipoVersion::ACTIVA)->where('id', '!=', $v->id)
                ->update(['estado' => TipoVersion::RETIRADA]);

            $v->update([
                'estado' => TipoVersion::ACTIVA,
                'map' => $map, 'map_huella' => Canonico::huella($map),
                'estructura_id' => $estructura->id,
                'procedimiento_definicion' => $congelado['definicion'],
                'procedimiento_huella' => $congelado['huella'],
                'revision_contrato' => $revision + ['huella_procedimiento' => $congelado['huella']],
                'activada_en' => now(),
            ]);

            $tipo->update(['version_vigente_id' => $v->id, 'activo' => true]);

            return $v->fresh();
        });

        // La intermedia ya existe: se conceden a la cuenta restringida SOLO sus permisos minimos (SELECT/UPDATE sobre la
        // intermedia, EXECUTE sobre el procedimiento, SELECT sobre las tablas declaradas). Mejor esfuerzo: si la conexion no
        // puede conceder, VALIDAR avisara con la lista exacta de lo que falta.
        if (config('cargues.validar.conceder_al_activar', true)) {
            try {
                $this->proteccion->conceder($c, $v->conexion, $v->procedimiento, $map['tabla'], (array) ($map['tablas_negocio'] ?? []));
            } catch (Throwable) {
                // VALIDAR informara lo que falte
            }
        }

        return $activada;
    }

    /** Cancela las cargas abiertas NO ejecutadas de un tipo (paso previo explicito a un cambio estructural). */
    public function cancelarCargasAbiertas($admin, Tipo $tipo): int
    {
        return Carga::where('tipo_id', $tipo->id)
            ->whereIn('estado', [Estados::RECIBIDA, Estados::EN_COLA, Estados::LEYENDO, Estados::PREPARANDO, Estados::VALIDANDO, Estados::VALIDADA])
            ->update(['estado' => Estados::CANCELADA, 'mensaje' => 'Cancelada por '.$admin->name.' antes de un cambio estructural.', 'job_uuid' => null, 'finalizada_en' => now()]);
    }

    // ---------------------------------------------------------------------------------------------------------
    // Estructura
    // ---------------------------------------------------------------------------------------------------------

    protected function prepararEstructura($admin, Tipo $tipo, TipoVersion $v, Connection $c, array $map): Estructura
    {
        $tabla = $map['tabla'];
        $huella = $this->intermedia->huella($map);

        $ajena = Estructura::where('conexion', $v->conexion)->where('nombre_tabla', $tabla)->where('tipo_id', '!=', $tipo->id)->exists();

        if ($ajena) {
            throw new OperacionInvalidaException('MAP_TABLA_DE_OTRO_TIPO', "La tabla «{$tabla}» pertenece a otro tipo.");
        }

        // Una caida anterior pudo dejar un cambio a medias: se reconcilia ANTES de seguir.
        foreach (Estructura::where('conexion', $v->conexion)->where('nombre_tabla', $tabla)->where('estado', Estructura::EN_CAMBIO)->get() as $pendiente) {
            $this->reconciliar($c, $pendiente);
        }

        $vigente = Estructura::where('conexion', $v->conexion)->where('nombre_tabla', $tabla)->where('estado', Estructura::ACTIVA)->first();

        if (! $vigente) {
            return $this->crearPrimera($tipo, $v, $c, $map, $huella);
        }

        if ($vigente->estructura_huella === $huella) {
            $dif = $this->intermedia->verificar($c, $map);

            if ($dif !== []) {
                throw new OperacionInvalidaException('ESTRUCTURA_ALTERADA', 'La intermedia registrada no coincide con su definición: '.$dif[0], $dif);
            }

            return $vigente; // misma huella: se reutiliza y T3 no cambia (F1-C3)
        }

        return $this->cambiarEstructura($tipo, $v, $c, $map, $huella, $vigente);
    }

    protected function crearPrimera(Tipo $tipo, TipoVersion $v, Connection $c, array $map, string $huella): Estructura
    {
        $tabla = $map['tabla'];

        if ($this->intermedia->existe($c, $tabla)) {
            throw new OperacionInvalidaException('MAP_TABLA_EXISTENTE_NO_REGISTRADA', "Ya existe una tabla «{$tabla}» que el módulo no creó.");
        }

        // Intencion registrada ANTES de la DDL: si el proceso cae, la reconciliacion lo ve.
        $fila = Estructura::create([
            'tipo_id' => $tipo->id, 'conexion' => $v->conexion, 'nombre_tabla' => $tabla, 'estructura_huella' => $huella,
            'estado' => Estructura::EN_CAMBIO, 'version_pendiente_id' => $v->id,
        ]);

        try {
            $ddl = $this->intermedia->crear($c, $map);
        } catch (Throwable $e) {
            $fila->delete(); // CREATE TABLE es atomico: si falla, no quedo nada

            throw new OperacionInvalidaException('DDL_FALLIDA', 'No se pudo crear la intermedia: '.$e->getMessage());
        }

        $fila->update(['estado' => Estructura::ACTIVA, 'ddl_auditoria' => $ddl, 'version_pendiente_id' => null]);

        return $fila;
    }

    protected function cambiarEstructura(Tipo $tipo, TipoVersion $v, Connection $c, array $map, string $huella, Estructura $vigente): Estructura
    {
        $tabla = $map['tabla'];

        // 1. Cargas abiertas resueltas (F1-C4): se bloquea, sin tocar nada.
        $versiones = TipoVersion::where('estructura_id', $vigente->id)->pluck('id');
        $abiertas = Carga::whereIn('tipo_version_id', $versiones)->whereIn('estado', self::CARGAS_ABIERTAS)->pluck('id')->all();

        if ($abiertas) {
            throw new OperacionInvalidaException('CARGAS_ABIERTAS', 'Hay cargas abiertas de la estructura vigente ('.count($abiertas).'). Resuélvalas o cancélelas antes de cambiar la estructura.', ['cargas' => $abiertas]);
        }

        $nueva = $tabla.'__nueva';

        // Restos de un intento anterior (T3 no los registra): se descartan.
        if ($this->intermedia->existe($c, $nueva)) {
            $c->statement("DROP TABLE `{$nueva}`");
        }

        $n = Estructura::where('conexion', $v->conexion)->where('nombre_tabla', $tabla)->where('estado', Estructura::ARCHIVADA)->count() + 1;

        while ($this->intermedia->existe($c, $tabla.'__a'.$n)) {
            $n++;
        }

        $archivo = $tabla.'__a'.$n;

        // 2. Intencion en T3 y creacion de `__nueva`. Si falla, nada cambio.
        $fila = Estructura::create([
            'tipo_id' => $tipo->id, 'conexion' => $v->conexion, 'nombre_tabla' => $tabla, 'estructura_huella' => $huella,
            'estado' => Estructura::EN_CAMBIO, 'nombre_nueva' => $nueva, 'archivada_como' => $archivo, 'version_pendiente_id' => $v->id,
        ]);

        try {
            $ddl = $this->intermedia->crear($c, $map, $nueva);
        } catch (Throwable $e) {
            if ($this->intermedia->existe($c, $nueva)) {
                $c->statement("DROP TABLE `{$nueva}`");
            }

            $fila->delete();

            throw new OperacionInvalidaException('DDL_FALLIDA', 'No se pudo crear la nueva estructura (no se cambió nada): '.$e->getMessage());
        }

        // 3. Un solo RENAME multiple: ninguna ventana sin tabla.
        try {
            $c->statement("RENAME TABLE `{$tabla}` TO `{$archivo}`, `{$nueva}` TO `{$tabla}`");
        } catch (Throwable $e) {
            $this->reconciliar($c, $fila->fresh());

            throw new OperacionInvalidaException('RENAME_FALLIDO', 'No se pudo intercambiar la estructura: '.$e->getMessage());
        }

        // 4. T3 en una transaccion de la conexion principal.
        DB::transaction(function () use ($vigente, $fila, $archivo, $ddl) {
            $vigente->update(['estado' => Estructura::ARCHIVADA, 'archivada_como' => $archivo]);
            $fila->update(['estado' => Estructura::ACTIVA, 'nombre_nueva' => null, 'archivada_como' => null, 'ddl_auditoria' => $ddl, 'version_pendiente_id' => null]);
        });

        return $fila->fresh();
    }

    /**
     * Reconciliacion de un cambio a medias (07 §5.4), por comparacion de T3 con information_schema:
     *  - vigente + archivo y sin `__nueva`: el RENAME se aplico, se completa T3;
     *  - vigente sin archivo: no se aplico, se descarta `__nueva` y la version sigue en borrador;
     *  - primera creacion sin tabla: se descarta el registro;
     *  - cualquier otro estado: INTERVENCION MANUAL (no se adivina) y las ejecuciones del tipo quedan bloqueadas.
     */
    public function reconciliar(Connection $c, Estructura $fila): string
    {
        $tabla = $fila->nombre_tabla;
        $existeVigente = $this->intermedia->existe($c, $tabla);
        $nueva = $fila->nombre_nueva;
        $archivo = $fila->archivada_como;

        // Primera creacion (sin archivo ni `__nueva`).
        if (! $nueva && ! $archivo) {
            if (! $existeVigente) {
                $fila->delete();

                return 'descartada';
            }

            $fila->update(['estado' => Estructura::ACTIVA, 'version_pendiente_id' => null]);

            return 'completada';
        }

        $existeArchivo = $archivo && $this->intermedia->existe($c, $archivo);
        $existeNueva = $nueva && $this->intermedia->existe($c, $nueva);

        if ($existeVigente && $existeArchivo && ! $existeNueva) {
            DB::transaction(function () use ($fila, $archivo, $tabla) {
                Estructura::where('conexion', $fila->conexion)->where('nombre_tabla', $tabla)->where('estado', Estructura::ACTIVA)->where('id', '!=', $fila->id)
                    ->update(['estado' => Estructura::ARCHIVADA, 'archivada_como' => $archivo]);
                $fila->update(['estado' => Estructura::ACTIVA, 'nombre_nueva' => null, 'archivada_como' => null, 'version_pendiente_id' => null]);
            });

            return 'completada';
        }

        if ($existeVigente && ! $existeArchivo) {
            if ($existeNueva) {
                $c->statement("DROP TABLE `{$nueva}`");
            }

            $fila->delete();

            return 'descartada';
        }

        throw new OperacionInvalidaException('ESTRUCTURA_INTERVENCION_MANUAL', "La estructura de «{$tabla}» quedó en un estado que no se puede resolver sola (sin tabla vigente, o archivo y nueva a la vez). Se requiere intervención manual; las ejecuciones del tipo quedan bloqueadas.");
    }

    /** ¿La intermedia esta bloqueada por un cambio estructural sin resolver? (E2 y P7 lo consultan). */
    public function bloqueada(string $conexion, string $tabla): bool
    {
        return Estructura::where('conexion', $conexion)->where('nombre_tabla', $tabla)->where('estado', Estructura::EN_CAMBIO)->exists();
    }
}
