<?php

namespace ByLopez\Logistica\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Catalogo parametrizable de la clasificacion de unidades defectuosas: `motivo` (que tiene la prenda) y `origen` (donde creemos
 * que ocurrio; nunca es una acusacion, el predeterminado es «por_determinar»). El codigo es estable; nombre, orden, activo y
 * `requiere_detalle` se pueden ajustar sin migrar.
 */
class CatalogoDefecto extends Model
{
    public const MOTIVO = 'motivo';

    public const ORIGEN = 'origen';

    public const ORIGEN_PREDETERMINADO = 'por_determinar';

    /** Motivos COMERCIALES de devolucion (por que el cliente no recibio). Tipo propio: nunca es un diagnostico de la prenda. */
    public const DEVOLUCION = 'devolucion';

    protected $table = 'bylopez_lg_catalogo_defectos';

    protected $fillable = ['tipo', 'codigo', 'nombre', 'requiere_detalle', 'activo', 'orden'];

    protected $casts = ['requiere_detalle' => 'boolean', 'activo' => 'boolean', 'orden' => 'integer'];

    /** @return list<array{codigo: string, nombre: string, requiere_detalle: bool}> activos de un tipo, en su orden */
    public static function activos(string $tipo): array
    {
        return static::query()->where('tipo', $tipo)->where('activo', true)->orderBy('orden')->orderBy('id')->get()
            ->map(fn (self $c) => ['codigo' => $c->codigo, 'nombre' => $c->nombre, 'requiere_detalle' => $c->requiere_detalle])->all();
    }
}
