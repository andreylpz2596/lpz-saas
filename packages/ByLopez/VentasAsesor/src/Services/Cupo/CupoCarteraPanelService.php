<?php

namespace ByLopez\VentasAsesor\Services\Cupo;

use ByLopez\VentasAsesor\DataObjects\CupoCartera;
use ByLopez\VentasAsesor\Enums\AlcanceVisibilidad;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use Illuminate\Support\Facades\DB;
use Webkul\User\Contracts\Admin as AdminContract;

/**
 * Panel COMPACTO de cupos dentro de Cartera (Doc. 35): cuantos clientes con cupo estan cerca del limite, agotados, excedidos o bloqueados,
 * cuanto dinero esta expuesto y los clientes que mas conviene mirar. Solo lectura y SOLO de los clientes con cupo configurado (un cliente
 * sin politica no tiene limite que vigilar; su deuda ya esta en Cartera). Las cifras salen de CupoCarteraClienteService por LOTE: una
 * llamada a Cartera para todos, sin consultas por cliente.
 *
 * Alcance: la hoja `cartera.cupo.ver` (con la regla de `all` de D10) Y alcance de Cartera «todos»; con «propios» nada (la exposicion
 * de un cliente es de todos sus pedidos, no solo de los que atiende un asesor).
 */
class CupoCarteraPanelService
{
    public function __construct(
        protected AutorizacionVentasAsesor $autorizacion,
        protected CupoCarteraClienteService $cupo,
    ) {}

    public function puedeVer(?AdminContract $actor): bool
    {
        return $actor !== null
            && $this->autorizacion->puedeCupo($actor, AutorizacionVentasAsesor::PERMISO_CUPO_VER)
            && $this->autorizacion->alcanceDeCartera($actor) === AlcanceVisibilidad::TODOS;
    }

    /**
     * @return ?array{
     *     bloqueo_activo: bool, con_cupo: int, activos: int, cerca: int, agotados: int, excedidos: int, bloqueados: int,
     *     autorizado_total: float, exposicion_total: float, disponible_total: float, exceso_total: float,
     *     alertas: list<array<string, mixed>>, mayores_saldos: list<array<string, mixed>>
     * }
     */
    public function resumen(?AdminContract $actor, int $limite = 6): ?array
    {
        if (! $this->puedeVer($actor)) {
            return null;
        }

        $ids = DB::table('bylopez_va_clientes_cupo_cartera')->pluck('customer_id')->map(fn ($id) => (int) $id)->all();
        $cupos = $this->cupo->deClientes($ids);
        $cuenta = fn (string $estado) => count(array_filter($cupos, fn (CupoCartera $c) => $c->estado === $estado));

        $nombres = $ids === [] ? collect() : DB::table('customers')->whereIn('id', $ids)->get(['id', 'first_name', 'last_name'])
            ->keyBy('id')->map(fn ($c) => trim($c->first_name.' '.$c->last_name));

        $fila = fn (CupoCartera $c) => [
            'customer_id' => $c->customerId, 'nombre' => $nombres->get($c->customerId) ?: ('#'.$c->customerId), 'estado' => $c->estado,
            'autorizado' => $c->autorizado, 'exposicion' => $c->exposicion, 'disponible' => $c->disponible, 'exceso' => $c->exceso,
            'utilizacion_pct' => $c->utilizacionPct, 'bloqueado' => $c->bloqueado(),
        ];

        // Prioridad de atencion: bloqueado y excedido primero (mayor exceso), luego agotado, luego cerca (mayor utilizacion).
        $prioridad = [CupoCartera::BLOQUEADO => 0, CupoCartera::EXCEDIDO => 1, CupoCartera::AGOTADO => 2, CupoCartera::CERCA => 3];
        $enAlerta = array_filter($cupos, fn (CupoCartera $c) => isset($prioridad[$c->estado]));
        uasort($enAlerta, fn (CupoCartera $a, CupoCartera $b) => [$prioridad[$a->estado], -$a->exceso, -($a->utilizacionPct ?? 0)] <=> [$prioridad[$b->estado], -$b->exceso, -($b->utilizacionPct ?? 0)]);

        $porSaldo = $cupos;
        uasort($porSaldo, fn (CupoCartera $a, CupoCartera $b) => $b->exposicion <=> $a->exposicion);

        return [
            'bloqueo_activo' => $this->cupo->bloqueoActivo(),
            'con_cupo' => count($cupos),
            'activos' => $cuenta(CupoCartera::ACTIVO),
            'cerca' => $cuenta(CupoCartera::CERCA),
            'agotados' => $cuenta(CupoCartera::AGOTADO),
            'excedidos' => $cuenta(CupoCartera::EXCEDIDO),
            'bloqueados' => $cuenta(CupoCartera::BLOQUEADO),
            'autorizado_total' => round(array_sum(array_map(fn (CupoCartera $c) => $c->autorizado, $cupos)), 2),
            'exposicion_total' => round(array_sum(array_map(fn (CupoCartera $c) => $c->exposicion, $cupos)), 2),
            'disponible_total' => round(array_sum(array_map(fn (CupoCartera $c) => $c->disponible, $cupos)), 2),
            'exceso_total' => round(array_sum(array_map(fn (CupoCartera $c) => $c->exceso, $cupos)), 2),
            'alertas' => array_values(array_map($fila, array_slice($enAlerta, 0, $limite))),
            'mayores_saldos' => array_values(array_map($fila, array_slice(array_filter($porSaldo, fn (CupoCartera $c) => $c->exposicion > 0), 0, $limite))),
        ];
    }
}
