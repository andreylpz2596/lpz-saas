<?php

namespace ByLopez\VentasAsesor\Models;

use ByLopez\VentasAsesor\Contracts\LogAsignacionAsesor as LogAsignacionAsesorContract;
use ByLopez\VentasAsesor\Enums\CanalVenta;
use ByLopez\VentasAsesor\Enums\MecanismoCreacion;
use ByLopez\VentasAsesor\Enums\OrigenPropiedadComercial;
use ByLopez\VentasAsesor\Enums\TipoOperacionAtribucion;
use ByLopez\VentasAsesor\Enums\TipoPropiedadComercial;
use ByLopez\VentasAsesor\Exceptions\HistorialInmutableException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fila de historial de propiedad comercial: registra TODA decision de propiedad,
 * incluida la atribucion inicial, no solo los cambios entre asesores.
 *
 * Inmutable a nivel de MODELO y SERVICIO (Fases 02A1 y 02A2): rechaza `save()`,
 * `update()` y `delete()` sobre filas existentes, y solo acepta inserciones hechas
 * por HistorialAtribucionService.
 *
 * Alcance honesto de la garantia: son eventos de Eloquent. Una consulta masiva por
 * Query Builder o SQL directo los salta. No hay disparadores de base de datos en
 * esta fase, asi que la regla del modulo es: el historial solo se escribe por el
 * servicio, nunca por consultas masivas.
 *
 * La tabla conserva su nombre, `bylopez_va_advisor_assignment_logs`, porque
 * renombrarla obligaria a reescribir la migracion ya aplicada sin ganar nada.
 */
class LogAsignacionAsesor extends Model implements LogAsignacionAsesorContract
{
    /** Solo el servicio autorizado puede abrir esta puerta, y por una sola insercion. */
    protected static bool $insercionAutorizada = false;

    protected $table = 'bylopez_va_advisor_assignment_logs';

    protected $fillable = [
        'order_attribution_id',
        'order_id',
        'tipo_operacion',
        'advisor_id_anterior',
        'advisor_anterior_snapshot',
        'advisor_id_nuevo',
        'advisor_nuevo_snapshot',
        'tipo_propiedad_anterior',
        'tipo_propiedad_nuevo',
        'origen_propiedad',
        'sales_channel',
        'creation_source',
        'changed_by_admin_id',
        'changed_by_name_snapshot',
        'codigo_tecnico',
        'motivo',
    ];

    protected $casts = [
        'order_attribution_id' => 'integer',
        'order_id' => 'integer',
        'advisor_id_anterior' => 'integer',
        'advisor_id_nuevo' => 'integer',
        'changed_by_admin_id' => 'integer',
        'tipo_operacion' => TipoOperacionAtribucion::class,
        'tipo_propiedad_anterior' => TipoPropiedadComercial::class,
        'tipo_propiedad_nuevo' => TipoPropiedadComercial::class,
        'origen_propiedad' => OrigenPropiedadComercial::class,
        'sales_channel' => CanalVenta::class,
        'creation_source' => MecanismoCreacion::class,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $log) {
            if (! static::$insercionAutorizada) {
                throw new HistorialInmutableException(
                    'El historial de propiedad comercial solo puede escribirse desde HistorialAtribucionService.'
                );
            }
        });

        /*
         * `save()` sobre una fila existente y SIN cambios no dispara `updating`:
         * Model::save() corta antes por isDirty(). Por eso el guardia vive en
         * `saving`, que si se dispara siempre.
         */
        static::saving(function (self $log) {
            if ($log->exists) {
                throw new HistorialInmutableException(
                    "El historial {$log->id} es inmutable: no puede volver a guardarse."
                );
            }
        });

        static::updating(function (self $log) {
            throw new HistorialInmutableException(
                "El historial {$log->id} es inmutable: no puede actualizarse."
            );
        });

        static::deleting(function (self $log) {
            throw new HistorialInmutableException(
                "El historial {$log->id} es inmutable: no puede eliminarse."
            );
        });
    }

    /**
     * Unica via de insercion. La reservamos al servicio para que ningun otro
     * punto del modulo pueda fabricar auditoria.
     *
     * @param  array<string, mixed>  $atributos
     */
    public static function escribirDesdeServicio(array $atributos): self
    {
        static::$insercionAutorizada = true;

        try {
            return static::query()->create($atributos);
        } finally {
            static::$insercionAutorizada = false;
        }
    }

    public function atribucion(): BelongsTo
    {
        return $this->belongsTo(AtribucionPedido::class, 'order_attribution_id');
    }

    public function esAsignacionInicial(): bool
    {
        return $this->tipo_operacion === TipoOperacionAtribucion::ASIGNACION_INICIAL;
    }
}
