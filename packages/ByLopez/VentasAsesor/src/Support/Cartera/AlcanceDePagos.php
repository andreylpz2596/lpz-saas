<?php

namespace ByLopez\VentasAsesor\Support\Cartera;

use ByLopez\VentasAsesor\Enums\AlcanceVisibilidad;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Webkul\User\Contracts\Admin as AdminContract;

/**
 * UNICA regla de alcance de un PAGO (PAG-E): lo ven y lo revisan quienes tienen `cartera.view_all`, o `cartera.view_own`
 * cuando TODOS los pedidos de sus aplicaciones (tambien las anuladas) estan atribuidos al actor. Revisar un pago afecta
 * a todos sus pedidos, por eso un solo pedido ajeno lo deja fuera de alcance. La usan la bandeja y la revision.
 */
class AlcanceDePagos
{
    public function __construct(protected AutorizacionVentasAsesor $autorizacion) {}

    public function alcance(AdminContract $actor): AlcanceVisibilidad
    {
        return $this->autorizacion->alcanceDeCartera($actor);
    }

    /** Acota una consulta sobre `bylopez_va_pagos as p` al alcance del actor. */
    public function restringir(Builder $consulta, AdminContract $actor): Builder
    {
        return match ($this->alcance($actor)) {
            AlcanceVisibilidad::TODOS => $consulta,
            AlcanceVisibilidad::PROPIOS => $consulta->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('bylopez_va_aplicaciones_pago as ax')
                ->leftJoin('bylopez_va_order_attributions as atx', fn ($j) => $j->on('atx.order_id', '=', 'ax.order_id')->where('atx.advisor_id', (int) $actor->id))
                ->whereColumn('ax.pago_id', 'p.id')->whereNull('atx.id')),
            AlcanceVisibilidad::NINGUNO => $consulta->whereRaw('1 = 0'),
        };
    }

    public function incluye(AdminContract $actor, int $pagoId): bool
    {
        return $this->restringir(DB::table('bylopez_va_pagos as p')->where('p.id', $pagoId), $actor)->exists();
    }
}
