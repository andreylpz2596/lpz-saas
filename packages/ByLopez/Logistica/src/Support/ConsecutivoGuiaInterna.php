<?php

namespace ByLopez\Logistica\Support;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Consecutivo GLOBAL de la guia interna ByLopez: GI-000001, GI-000002… (minimo seis digitos, crece sin tope). Compartido solo
 * entre transportadoras configuradas con guia interna; sin reinicio anual ni por transportadora.
 *
 * Concurrencia: la fila del contador se bloquea con FOR UPDATE dentro de la transaccion del despacho y el siguiente numero es
 * max(contador, mayor consecutivo ya guardado) + 1. La unicidad la garantiza ademas el UNIQUE de
 * `bylopez_lg_guias.consecutivo_interno`. Los huecos son aceptables; un numero asignado jamas se reutiliza (si la transaccion
 * se revierte, el numero nunca llego a existir).
 */
class ConsecutivoGuiaInterna
{
    public const CLAVE = 'guia_interna';

    public const PREFIJO = 'GI-';

    public const DIGITOS_MINIMOS = 6;

    /**
     * Debe llamarse DENTRO de la transaccion que crea la guia.
     *
     * @return array{0: int, 1: string} [consecutivo, numero GI]
     */
    public function siguiente(): array
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('El consecutivo de guia interna se asigna dentro de la transaccion del despacho.');
        }

        DB::table('bylopez_lg_consecutivos')->insertOrIgnore(['clave' => self::CLAVE, 'ultimo' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $fila = DB::table('bylopez_lg_consecutivos')->where('clave', self::CLAVE)->lockForUpdate()->first();
        $mayorGuardado = (int) DB::table('bylopez_lg_guias')->max('consecutivo_interno');
        $siguiente = max((int) $fila->ultimo, $mayorGuardado) + 1;

        DB::table('bylopez_lg_consecutivos')->where('clave', self::CLAVE)->update(['ultimo' => $siguiente, 'updated_at' => now()]);

        return [$siguiente, self::formatear($siguiente)];
    }

    public static function formatear(int $consecutivo): string
    {
        return self::PREFIJO.str_pad((string) $consecutivo, self::DIGITOS_MINIMOS, '0', STR_PAD_LEFT);
    }

    /** Los numeros GI-… los asigna SOLO el sistema: una guia externa no puede escribirlos. */
    public static function esReservado(?string $numero): bool
    {
        return (bool) preg_match('/^GI-\d+$/i', trim((string) $numero));
    }
}
