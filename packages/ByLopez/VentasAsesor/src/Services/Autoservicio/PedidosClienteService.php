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
    public function listar(Customer $cliente, int $pagina = 1, ?string $buscar = null): array
    {
        $consulta = DB::table('orders')->where('customer_id', $cliente->id);

        // Solo por numero de pedido (el cliente ve unos pocos; nada de busquedas por otros campos).
        if ($buscar !== null && trim($buscar) !== '') {
            $consulta->where('increment_id', 'like', '%'.str_replace(['%', '_'], '', trim($buscar)).'%');
        }

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
            'tipo_entrega' => str_contains((string) $p->shipping_method, 'pickup') ? 'Recogida en tienda' : 'Envío a domicilio',
            'guias' => collect($d['guias'])->map(fn ($g) => [
                'transportadora' => $g['transportadora'] ?? null,
                'numero_guia' => $g['numero_guia'] ?? null,
            ])->values()->all(),
        ];

        if ($conLineas) {
            $esRecogida = str_contains((string) $p->shipping_method, 'pickup');
            $resumen['entrega'] = $resumen['tipo_entrega'];
            $resumen['subtotal'] = (float) $p->sub_total;
            $resumen['envio'] = (float) $p->shipping_amount;
            $resumen['metodo_envio'] = $esRecogida ? null : $this->sinRepetidos((string) $p->shipping_title);

            // Solo SU direccion de entrega (la de la recogida es la tienda y no se muestra como destino).
            $destino = $esRecogida ? null : DB::table('addresses')->where('order_id', $orderId)->where('address_type', 'order_shipping')->first(['address', 'city', 'state']);
            $resumen['destino'] = $destino ? trim(implode(', ', array_filter([preg_replace('/\s*\R\s*/', ', ', (string) $destino->address), $destino->city]))) : null;
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

    /** «Envio nacional - Envio nacional» → «Envio nacional». */
    protected function sinRepetidos(string $titulo): ?string
    {
        $partes = collect(preg_split('/\s+-\s+/', trim($titulo)) ?: [])->map(fn ($x) => trim($x))->filter()->unique(fn ($x) => mb_strtolower($x))->values();

        return $partes->isEmpty() ? null : $partes->implode(' - ');
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
