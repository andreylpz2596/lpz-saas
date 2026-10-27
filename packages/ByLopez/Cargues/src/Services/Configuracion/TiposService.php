<?php

namespace ByLopez\Cargues\Services\Configuracion;

use ByLopez\Cargues\Exceptions\OperacionInvalidaException;
use ByLopez\Cargues\Models\Tipo;
use ByLopez\Cargues\Models\TipoVersion;
use ByLopez\Cargues\Support\Canonico;
use ByLopez\Cargues\Support\Estados;
use Illuminate\Support\Facades\DB;

/**
 * Configuracion administrativa del tipo (T1/T2). Alta y edicion SOLO en borrador; una version activa o retirada es
 * inmutable (un cambio crea una version nueva). La politica de errores es obligatoria para ACTIVAR y no tiene valor por
 * defecto: nunca se elige sola.
 */
class TiposService
{
    public const CLAVES = ['nombre', 'descripcion', 'conexion', 'procedimiento', 'accion', 'politica_errores', 'limites', 'map'];

    public const CLAVES_LIMITES = ['max_bytes', 'max_celdas', 'max_filas'];

    public function __construct(
        protected MapValidator $validador,
        protected ConexionTipo $conexiones,
        protected ProcedimientoService $procedimientos,
        protected ProteccionValidar $proteccion,
    ) {}

    public function crear($admin, array $datos): Tipo
    {
        $datos = $this->depurar($datos);

        if (blank($datos['nombre'] ?? null) || mb_strlen($datos['nombre']) > 191) {
            throw new OperacionInvalidaException('NOMBRE_INVALIDO', 'El nombre es obligatorio (máximo 191 caracteres).');
        }

        if (Tipo::where('nombre', $datos['nombre'])->exists()) {
            throw new OperacionInvalidaException('NOMBRE_REPETIDO', 'Ya existe un tipo con ese nombre.');
        }

        return DB::transaction(function () use ($admin, $datos) {
            $tipo = Tipo::create([
                'nombre' => $datos['nombre'], 'descripcion' => $datos['descripcion'] ?? null, 'activo' => false,
                'creado_por_admin_id' => $admin->id, 'creado_por_nombre' => $admin->name,
            ]);

            $this->nuevaVersion($admin, $tipo, 1, $datos);

            return $tipo->fresh();
        });
    }

    /** Guarda el borrador (lo crea a partir de la version vigente si no hay uno). */
    public function guardarBorrador($admin, Tipo $tipo, array $datos): TipoVersion
    {
        $datos = $this->depurar($datos);

        return DB::transaction(function () use ($admin, $tipo, $datos) {
            $tipo = Tipo::whereKey($tipo->id)->lockForUpdate()->firstOrFail();

            if (array_key_exists('nombre', $datos) || array_key_exists('descripcion', $datos)) {
                if (isset($datos['nombre'])) {
                    if (blank($datos['nombre']) || Tipo::where('nombre', $datos['nombre'])->where('id', '!=', $tipo->id)->exists()) {
                        throw new OperacionInvalidaException('NOMBRE_INVALIDO', 'Nombre vacío o repetido.');
                    }
                    $tipo->nombre = $datos['nombre'];
                }

                if (array_key_exists('descripcion', $datos)) {
                    $tipo->descripcion = $datos['descripcion'];
                }

                $tipo->save();
            }

            $borrador = $tipo->versiones()->where('estado', TipoVersion::BORRADOR)->first();

            if (! $borrador) {
                $base = $tipo->versiones()->first();
                $numero = ($base?->numero ?? 0) + 1;
                $herencia = $base ? [
                    'conexion' => $base->conexion, 'procedimiento' => $base->procedimiento, 'accion' => $base->accion,
                    'politica_errores' => $base->politica_errores, 'limites' => $base->limites, 'map' => $base->map,
                ] : [];

                return $this->nuevaVersion($admin, $tipo, $numero, $datos + $herencia);
            }

            return $this->actualizarBorrador($admin, $borrador, $datos);
        });
    }

    /**
     * Valida la configuracion SIN tocar la BD del tipo (la comprobacion contra la BD ocurre al activar).
     *
     * @return array<int, array{codigo:string, ruta:string, mensaje:string}>
     */
    public function validar(TipoVersion $v): array
    {
        $errores = [];

        if ($v->modo !== 'procedimiento') {
            return [['codigo' => 'MODO_NO_DISPONIBLE', 'ruta' => 'modo', 'mensaje' => 'Solo el modo procedimiento está disponible en la Fase 1.']];
        }

        $errores = array_merge($errores, $this->conexiones->errores((string) $v->conexion));

        if (! preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', (string) $v->procedimiento)) {
            $errores[] = ['codigo' => 'PROCEDIMIENTO_INVALIDO', 'ruta' => 'procedimiento', 'mensaje' => 'Nombre de procedimiento inválido (letras, números y _).'];
        }

        if ($v->tabla_destino !== null && $v->tabla_destino !== '') {
            $errores[] = ['codigo' => 'MODO_PROCEDIMIENTO_CON_TABLA_DESTINO', 'ruta' => 'tabla_destino', 'mensaje' => 'En modo procedimiento la tabla destino debe estar vacía (la intermedia es map.tabla).'];
        }

        $mapErrores = $this->validador->validar((array) $v->map, (string) $v->modo);
        $errores = array_merge($errores, $mapErrores);

        if ($mapErrores === [] && ! in_array($v->accion, (array) ($v->map['acciones'] ?? []), true)) {
            $errores[] = ['codigo' => 'ACCION_FUERA_DE_MAP', 'ruta' => 'accion', 'mensaje' => 'La acción debe estar en map.acciones.'];
        }

        if ($v->politica_errores !== null && ! in_array($v->politica_errores, Estados::POLITICAS, true)) {
            $errores[] = ['codigo' => 'POLITICA_INVALIDA', 'ruta' => 'politica_errores', 'mensaje' => 'Use bloquear_todo o procesar_validas.'];
        }

        foreach ((array) $v->limites as $k => $valor) {
            $max = config("cargues.limites.{$k}");

            if (! in_array($k, self::CLAVES_LIMITES, true) || ! is_int($valor) || $valor < 1 || $valor > (int) $max) {
                $errores[] = ['codigo' => 'LIMITES_INVALIDOS', 'ruta' => "limites.{$k}", 'mensaje' => 'Los límites del tipo solo pueden BAJAR el máximo global ('.implode(', ', self::CLAVES_LIMITES).').'];
            }
        }

        return $errores;
    }

    /** Confirmacion HUMANA de la revision del contrato, ligada a la huella del procedimiento revisado. */
    public function confirmarContrato($admin, TipoVersion $v): array
    {
        if (! $v->esBorrador()) {
            throw new OperacionInvalidaException('VERSION_NO_EDITABLE', 'Solo se confirma la revisión de un borrador.');
        }

        $errores = $this->validar($v);

        if ($errores !== []) {
            throw new OperacionInvalidaException('CONFIGURACION_INVALIDA', 'Corrija la configuración antes de confirmar la revisión.', $errores);
        }

        $c = $this->conexiones->conexion($v->conexion);
        $firma = $this->procedimientos->verificarFirma($c, $v->procedimiento);

        if ($firma !== []) {
            throw new OperacionInvalidaException('PROCEDIMIENTO_FIRMA', $firma[0]['mensaje'], $firma);
        }

        $congelado = $this->procedimientos->congelar($c, $v->procedimiento);

        if (! $congelado) {
            throw new OperacionInvalidaException('DEFINICION_NO_DISPONIBLE', 'No se pudo leer la definición del procedimiento (SHOW CREATE PROCEDURE).');
        }

        $map = $this->validador->normalizar((array) $v->map);
        $revision = $this->procedimientos->revisarContrato($congelado['definicion'], $map);
        $revision['hallazgos'] = array_merge($revision['hallazgos'], $this->proteccion->verificar($c, $v->conexion, $v->procedimiento, $map['tabla']));
        $revision['huella_procedimiento'] = $congelado['huella'];
        $hayErrores = (bool) array_filter($revision['hallazgos'], fn ($h) => $h['nivel'] === 'error');

        $v->update([
            'revision_contrato' => $revision,
            'revision_admin_id' => $hayErrores ? null : $admin->id,
            'revision_admin_nombre' => $hayErrores ? null : $admin->name,
            'revision_en' => $hayErrores ? null : now(),
            'revision_huella' => $hayErrores ? null : $congelado['huella'],
        ]);

        return $revision;
    }

    public function cambiarActivo(Tipo $tipo, bool $activo): Tipo
    {
        if ($activo && ! $tipo->version_vigente_id) {
            throw new OperacionInvalidaException('SIN_VERSION_ACTIVA', 'El tipo no tiene ninguna versión activa.');
        }

        $tipo->update(['activo' => $activo]);

        return $tipo->fresh();
    }

    protected function nuevaVersion($admin, Tipo $tipo, int $numero, array $datos): TipoVersion
    {
        $map = $this->mapComoArray($datos['map'] ?? []);

        return TipoVersion::create([
            'tipo_id' => $tipo->id, 'numero' => $numero, 'estado' => TipoVersion::BORRADOR,
            'conexion' => $datos['conexion'] ?? (string) (config('cargues.conexiones_autorizadas')[0] ?? ''),
            'modo' => 'procedimiento', 'tabla_destino' => null,
            'procedimiento' => $datos['procedimiento'] ?? null,
            'accion' => $datos['accion'] ?? '',
            'map' => $map, 'map_huella' => Canonico::huella($map),
            'politica_errores' => $this->politica($datos['politica_errores'] ?? null),
            'limites' => $datos['limites'] ?? null,
            'actor_admin_id' => $admin->id, 'actor_nombre' => $admin->name,
        ]);
    }

    protected function actualizarBorrador($admin, TipoVersion $v, array $datos): TipoVersion
    {
        if (! $v->esBorrador()) {
            throw new OperacionInvalidaException('VERSION_NO_EDITABLE', 'Una versión activa o retirada no se edita.');
        }

        $cambios = [];

        foreach (['conexion', 'procedimiento', 'accion', 'limites'] as $k) {
            if (array_key_exists($k, $datos)) {
                $cambios[$k] = $datos[$k];
            }
        }

        if (array_key_exists('politica_errores', $datos)) {
            $cambios['politica_errores'] = $this->politica($datos['politica_errores']);
        }

        if (array_key_exists('map', $datos)) {
            $cambios['map'] = $this->mapComoArray($datos['map']);
            $cambios['map_huella'] = Canonico::huella($cambios['map']);
        }

        // Cualquier cambio invalida la confirmacion de la revision del contrato.
        $cambios += ['revision_contrato' => null, 'revision_admin_id' => null, 'revision_admin_nombre' => null, 'revision_en' => null, 'revision_huella' => null];
        $cambios['actor_admin_id'] = $admin->id;
        $cambios['actor_nombre'] = $admin->name;

        $v->update($cambios);

        return $v->fresh();
    }

    /** Solo las claves conocidas: un parametro desconocido se rechaza, no se ignora en silencio. */
    protected function depurar(array $datos): array
    {
        $desconocidas = array_diff(array_keys($datos), self::CLAVES);

        if ($desconocidas) {
            throw new OperacionInvalidaException('PARAMETROS_DESCONOCIDOS', 'Parámetros no permitidos: '.implode(', ', $desconocidas).'.');
        }

        return $datos;
    }

    protected function politica(mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if (! in_array($valor, Estados::POLITICAS, true)) {
            throw new OperacionInvalidaException('POLITICA_INVALIDA', 'La política de errores debe ser bloquear_todo o procesar_validas.');
        }

        return $valor;
    }

    protected function mapComoArray(mixed $map): array
    {
        if (is_string($map)) {
            $decodificado = json_decode($map, true);

            if (! is_array($decodificado)) {
                throw new OperacionInvalidaException('MAP_JSON_INVALIDO', 'El map no es un JSON válido.');
            }

            $map = $decodificado;
        }

        return is_array($map) ? $map : [];
    }
}
