<?php

namespace ByLopez\Logistica\Services\Cuentas;

use ByLopez\Logistica\Models\Guia;
use ByLopez\Logistica\Models\MovimientoCuenta;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * C6 — de donde nace el RECAUDO de una guia: del COBRO REAL (guia C6) o del valor esperado al entregar (guia legada).
 *
 * Regla inequivoca de transicion:
 *   1. Una guia con algun movimiento `recaudo` de origen `envio` o `revision` es LEGADA para siempre (nada historico se reinterpreta).
 *   2. Si no, es C6 solo cuando hay fecha de corte configurada (`logistica.cuentas.recaudo_desde_cobro_desde`) y la guia se creo en
 *      esa fecha o despues. Sin fecha todo sigue como antes de C6.
 * Despues de nacer, el ORIGEN del movimiento (`cobro` vs `envio`) es lo que distingue un recaudo C6 de uno legado.
 */
class ModoRecaudo
{
    public static function desde(): ?CarbonImmutable
    {
        $fecha = config('logistica.cuentas.recaudo_desde_cobro_desde');

        return empty($fecha) ? null : CarbonImmutable::parse((string) $fecha)->startOfDay();
    }

    public function esC6(Guia $guia): bool
    {
        return isset($this->guiasC6(new Collection([$guia]))[(int) $guia->id]);
    }

    /**
     * Una consulta para todo el lote.
     *
     * @param  iterable<Guia>  $guias
     * @return array<int, true> ids de las guias C6
     */
    public function guiasC6(iterable $guias): array
    {
        $desde = self::desde();

        if ($desde === null) {
            return [];
        }

        $candidatas = [];

        foreach ($guias as $g) {
            if ($g->created_at !== null && CarbonImmutable::parse($g->created_at)->greaterThanOrEqualTo($desde)) {
                $candidatas[(int) $g->id] = true;
            }
        }

        if ($candidatas === []) {
            return [];
        }

        $legadas = DB::table('bylopez_lg_cuenta_movimientos')->whereIn('guia_id', array_keys($candidatas))->where('concepto', 'recaudo')
            ->whereIn('origen_tipo', [MovimientoCuenta::ORIGEN_ENVIO, MovimientoCuenta::ORIGEN_REVISION])->distinct()->pluck('guia_id');

        foreach ($legadas as $id) {
            unset($candidatas[(int) $id]);
        }

        return $candidatas;
    }
}
