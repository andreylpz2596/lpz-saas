<?php

namespace ByLopez\VentasAsesor\Services\Autoservicio;

use ByLopez\VentasAsesor\DataObjects\ConfirmacionVenta;
use ByLopez\VentasAsesor\Services\Pedidos\ConsultaPedidosAsesorService;
use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\Customer;

/**
 * Doc. 33 P5 — «Mis pedidos» del cliente de autoservicio: SOLO los pedidos de su cliente vinculado (orders.customer_id), con
 * la informacion comercial que le corresponde. Reutiliza la lectura del modulo (estado de pago por recaudos CONFIRMADOS,
 * despacho, guias) pero nunca expone propietario/asesor, comisiones, costos, cartera interna, custodia ni archivos.
 */
class PedidosClienteService
{
    public const POR_PAGINA = 15;

    protected const ESTADOS = [
        'pending' => 'Recibido', 'pending_payment' => 'Recibido', 'processing' => 'En preparación', 'completed' => 'Completado',
        'canceled' => 'Cancelado', 'closed' => 'Cerrado', 'fraud' => 'En revisión',
    ];

    public function __construct(protected ConsultaPedidosAsesorService $consulta) {}

    /** @return array{data: list<array<string, mixed>>, pagina: int, total: int, por_pagina: int} */
    public function listar(Customer $cliente, int $pagina = 1): array
    {
        $consulta = DB::table('orders')->where('customer_id', $cliente->id);
        $total = (clone $consulta)->count();
        $ids = $consulta->orderByDesc('id')->forPage(max(1, $pagina), self::POR_PAGINA)->pluck('id')->all();

        return [
            'data' => array_values(array_filter(array_map(fn ($id) => $this->resumen((int) $id, false), $ids))),
            'pagina' => max(1, $pagina),
            'total' => $total,
            'por_pagina' => self::POR_PAGINA,
        ];
    }

    /** Un pedido del cliente; uno ajeno o inexistente devuelve null (el mismo 404, sin distinguirlos). */
    public function detalle(Customer $cliente, int $orderId): ?array
    {
        $propio = DB::table('orders')->where('id', $orderId)->where('customer_id', $cliente->id)->exists();

        return $propio ? $this->resumen($orderId, true) : null;
    }

    protected function resumen(int $orderId, bool $conLineas): ?array
    {
        $d = $this->consulta->detalle($orderId);

        if (! $d) {
            return null;
        }

        $p = $d['pedido'];

        $resumen = [
            'id' => (int) $p->id,
            'numero' => (string) $p->increment_id,
            'fecha' => (string) $p->created_at,
            'estado' => self::ESTADOS[(string) $p->status] ?? 'En proceso',
            'total' => (float) $p->grand_total,
            'moneda' => (string) $p->order_currency_code,
            'estado_despacho' => $this->etiquetaDespacho((string) $d['estado_despacho']),
            'estado_pago' => $this->etiquetaPago((string) $d['estado_pago']),
            'unidades' => (int) collect($d['items'])->sum('qty_ordered'),
            'guias' => collect($d['guias'])->map(fn ($g) => [
                'transportadora' => $g['transportadora'] ?? null,
                'numero_guia' => $g['numero_guia'] ?? null,
            ])->values()->all(),
        ];

        if ($conLineas) {
            $resumen['entrega'] = str_contains((string) $p->shipping_method, 'pickup') ? 'Recogida en tienda' : 'Envío';
            $resumen['prendas'] = collect($d['items'])->map(function ($l) {
                $atributos = (array) (json_decode((string) $l->additional, true)['attributes'] ?? []);

                return [
                    'nombre' => (string) $l->name,
                    'color' => (string) ($atributos['color']['option_label'] ?? ''),
                    'talla' => (string) collect($atributos)->except('color')->pluck('option_label')->filter()->implode(' · '),
                    'cantidad' => (int) $l->qty_ordered,
                    'precio' => (float) $l->price,
                    'total' => (float) $l->total,
                ];
            })->values()->all();
        }

        return $resumen;
    }

    protected function etiquetaPago(string $estado): string
    {
        return match ($estado) {
            ConfirmacionVenta::PAGO_PAGADO => 'Pagado',
            ConfirmacionVenta::PAGO_PARCIAL => 'Abonado',
            default => 'Pendiente',
        };
    }

    protected function etiquetaDespacho(string $estado): string
    {
        return match ($estado) {
            'despachado' => 'Despachado',
            'parcial' => 'Despacho parcial',
            'pendiente' => 'Pendiente de despacho',
            default => '—',
        };
    }
}
