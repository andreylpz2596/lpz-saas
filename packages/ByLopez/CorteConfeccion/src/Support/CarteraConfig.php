<?php

namespace ByLopez\CorteConfeccion\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Configuracion de la cartera. La fecha de arranque la define el negocio (decision D2); mientras no exista, la cartera no
 * esta iniciada: no se calculan cuentas ni se registran movimientos.
 */
class CarteraConfig
{
    public const FECHA_ARRANQUE = 'fecha_arranque';

    public static function fechaArranque(): ?Carbon
    {
        $valor = DB::table('bylopez_cc_cartera_config')->where('clave', self::FECHA_ARRANQUE)->value('valor');

        return $valor ? Carbon::parse($valor)->startOfDay() : null;
    }

    public static function establecerFechaArranque(string $fecha): Carbon
    {
        $carbon = Carbon::parse($fecha)->startOfDay();

        DB::table('bylopez_cc_cartera_config')->updateOrInsert(
            ['clave' => self::FECHA_ARRANQUE],
            ['valor' => $carbon->toDateString(), 'actualizado_por' => auth()->guard('admin')->id(), 'updated_at' => now(), 'created_at' => now()]
        );

        return $carbon;
    }
}
