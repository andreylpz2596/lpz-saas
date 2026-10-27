<?php

namespace ByLopez\Logistica\Models;

use ByLopez\Logistica\Enums\OrigenArchivoGuia;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use Illuminate\Database\Eloquent\Model;

/**
 * Version del PDF de una guia. Se escribe una vez; la unica modificacion permitida es pasar de vigente a
 * reemplazada (`vigente = NULL` y `reemplazado_en`) cuando entra una version nueva. Nunca se borra ni se sobrescribe.
 */
class GuiaArchivo extends Model
{
    private const AL_REEMPLAZAR = ['vigente', 'reemplazado_en', 'updated_at'];

    protected $table = 'bylopez_lg_guia_archivos';

    protected $fillable = [
        'guia_id', 'version', 'vigente', 'origen_archivo', 'disco', 'ruta', 'nombre_original', 'mime', 'tamano_bytes',
        'sha256', 'subido_por_admin_id', 'subido_por_name_snapshot', 'motivo_reemplazo', 'reemplazado_en',
    ];

    protected $casts = [
        'guia_id' => 'integer',
        'version' => 'integer',
        'vigente' => 'integer',
        'origen_archivo' => OrigenArchivoGuia::class,
        'tamano_bytes' => 'integer',
        'subido_por_admin_id' => 'integer',
        'reemplazado_en' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $archivo) {
            $esReemplazo = $archivo->getOriginal('vigente') === 1
                && $archivo->vigente === null
                && array_diff(array_keys($archivo->getDirty()), self::AL_REEMPLAZAR) === [];

            if (! $esReemplazo) {
                throw new Invalida(Invalida::REGISTRO_INMUTABLE, ['archivo' => $archivo->getKey()]);
            }
        });

        static::deleting(fn (self $archivo) => throw new Invalida(Invalida::REGISTRO_INMUTABLE, ['archivo' => $archivo->getKey()]));
    }

    public function guia()
    {
        return $this->belongsTo(Guia::class, 'guia_id');
    }
}
