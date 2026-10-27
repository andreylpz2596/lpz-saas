<?php

namespace ByLopez\Cargues\Models;

use Illuminate\Database\Eloquent\Model;

class CargaIncidencia extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'bylopez_cg_carga_incidencias';

    protected $guarded = [];

    /**
     * Registra incidencias de forma IDEMPOTENTE (repetir una etapa no duplica) sin esconder errores: se usa upsert y
     * nunca INSERT IGNORE, que truncaria o descartaria datos en silencio.
     */
    public static function registrar(array $filas): void
    {
        if ($filas !== []) {
            static::upsert($filas, ['carga_id', 'fila_origen', 'etapa', 'codigo', 'campo'], ['dato_id', 'motivo', 'severidad']);
        }
    }
}
