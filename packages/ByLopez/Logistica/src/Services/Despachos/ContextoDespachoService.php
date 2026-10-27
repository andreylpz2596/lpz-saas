<?php

namespace ByLopez\Logistica\Services\Despachos;

use ByLopez\Logistica\Contracts\SugiereRecaudo;
use ByLopez\Logistica\Enums\OrigenGuia;
use ByLopez\Logistica\Enums\PagadorFlete;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Services\Entregas\ConsultaEntregaService;
use ByLopez\Logistica\Services\Guias\ConsultaGuiaService;
use ByLopez\Logistica\Services\Stock\IncidenciasStockService;
use ByLopez\Logistica\Services\Transportadoras\TransportadorasService;
use ByLopez\Logistica\Support\AutorizacionLogistica;
use ByLopez\Logistica\Support\FuentePanel;
use ByLopez\Logistica\Support\PuntosRecogida;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Webkul\Sales\Models\OrderItem;
use Webkul\Sales\Models\OrderProxy;
use Webkul\User\Contracts\Admin;

/**
 * Todo lo que el modal «Despachar» necesita de UN pedido, en una sola lectura: resumen compacto del cliente y el
 * destino, unidades pendientes, transportadoras, sugerencias y el modo que corresponde segun el estado del pedido.
 *
 * Es informacion OPERATIVA. No incluye margen, costos, comision, utilidad ni el valor comercial del pedido: de lo
 * monetario solo el valor a RECAUDAR (contra entrega). El flete se muestra unicamente a quien puede despachar.
 */
class ContextoDespachoService
{
    public function __construct(
        protected AutorizacionLogistica $autorizacion,
        protected UnidadesDespachables $unidades,
        protected TransportadorasService $transportadoras,
        protected ConsultaGuiaService $guias,
        protected ConsultaEntregaService $entregas,
        protected SugiereRecaudo $recaudo,
        protected IncidenciasStockService $stock,
        protected CondicionDespacho $condicion,
        protected RecaudoPorGuia $recaudoPorGuia,
    ) {}

    /** @return array<string, mixed> */
    public function paraPedido(int $orderId, ?Admin $actor): array
    {
        $puedeDespachar = $this->autorizacion->tienePermiso($actor, AutorizacionLogistica::PERMISO_DESPACHAR);

        if (! $puedeDespachar && ! $this->autorizacion->tienePermiso($actor, AutorizacionLogistica::PERMISO_DESPACHOS)) {
            throw new Invalida(Invalida::SIN_PERMISO);
        }

        $pedido = OrderProxy::modelClass()::query()->find($orderId) ?? throw new Invalida(Invalida::PEDIDO_INEXISTENTE);

        $direccion = DB::table('addresses')->where('order_id', $orderId)->where('address_type', 'order_shipping')->first();
        $esRecogida = $pedido->shipping_method === PuntosRecogida::METODO;
        $metodoPago = (string) DB::table('order_payment')->where('order_id', $orderId)->value('method');

        $pendientes = $this->unidades->pendientes($pedido);
        $totalPendiente = (int) $pendientes->sum(fn (OrderItem $l) => $l->qty_to_ship);
        $despachables = $pedido->canShip() && $totalPendiente > 0 && $direccion !== null;
        $resumenEntrega = $this->entregas->resumenPedido($orderId);

        $modo = match (true) {
            ! $despachables || ! $puedeDespachar => 'ver',
            $esRecogida => 'entregar_bodega',
            $resumenEntrega['despachadas'] > 0 => 'completar',
            default => 'despachar',
        };

        $recaudoSugerido = $modo === 'ver' ? 0.0 : $this->recaudo->recaudoSugerido($orderId);
        $envio = $this->condicion->contexto($pedido);
        $recaudoGuia = $modo === 'ver' ? null : $this->recaudoGuia($pedido, $pendientes);

        // Pendiente / disponible / maximo por linea, con la MISMA regla que valida el servidor al despachar.
        $plan = $this->unidades->plan($pedido);

        return [
            'pedido' => [
                'id' => (int) $pedido->id,
                'numero' => $pedido->increment_id,
                'estado' => $pedido->status,
                'cliente' => trim($pedido->customer_first_name.' '.$pedido->customer_last_name),
                'telefono' => $direccion?->phone,
                'ciudad' => $direccion ? $this->ciudad($direccion) : null,
                'direccion' => $direccion ? trim(str_replace(["\r\n", "\n"], ', ', (string) $direccion->address)) : null,
                'codigo_postal' => $direccion?->postcode,
                'tipo_entrega' => $pedido->shipping_title ?: $pedido->shipping_method,
                'es_recogida' => $esRecogida,
                'tipo_pago' => $this->tituloPago($metodoPago),
                'es_contraentrega' => $metodoPago === 'cashondelivery',
            ],
            'modo' => $modo,
            'unidades' => [
                'pendientes_total' => $totalPendiente,
                'despachadas' => $resumenEntrega['despachadas'],
                'entregadas' => $resumenEntrega['entregadas'],
                // `maximo` = min(pendiente, stock permitido por Bagisto): es la cantidad que la interfaz precarga y no deja superar.
                'lineas' => $pendientes->map(fn (OrderItem $l) => [
                    'id' => (int) $l->id,
                    'producto_id' => (int) $l->product_id,
                    'sku' => $l->sku,
                    'nombre' => $l->name,
                    'talla' => $this->talla($l),
                    'pendientes' => $plan['lineas'][$l->id]['pendientes'],
                    'disponibles' => $plan['lineas'][$l->id]['disponibles'],
                    'maximo' => $plan['lineas'][$l->id]['maximo'],
                ])->values()->all(),
            ],
            'bodega' => $plan['bodega']?->name,
            'recaudo_sugerido' => $recaudoSugerido,
            // C1: base para proponer el recaudo de ESTA guia segun las unidades elegidas (el servidor lo recalcula al despachar).
            'recaudo_guia' => $recaudoGuia,
            'moneda' => $pedido->base_currency_code,
            // Limite REAL de subida del servidor (puede ser menor que el maximo logico de 5 MB): para el mensaje del drawer.
            'limite_subida' => ['archivo' => (string) ini_get('upload_max_filesize'), 'cuerpo' => (string) ini_get('post_max_size')],
            'transportadoras' => $this->transportadoras->activas()
                ->reject(fn ($t) => $t->codigo === FuentePanel::TRANSPORTADORA_RECOGIDA)
                ->map(fn ($t) => [
                    'codigo' => $t->codigo,
                    'nombre' => $t->nombre,
                    'tipo' => $t->tipo?->value,
                    // Capacidades (independientes del tipo): el drawer decide QUE mostrar por ellas, nunca por el nombre.
                    'liquida_cuenta_corriente' => (bool) $t->liquida_cuenta_corriente,
                    'genera_guia_interna' => (bool) $t->genera_guia_interna,
                ])->values()->all(),
            'sugerencias' => [
                // Domicilio local: el elegido por el asesor en la venta o, si no, el ultimo domiciliario usado en la zona.
                'transportadora' => $envio['domiciliario_sugerido'],
                // F1: el pagador se DEDUCE de la condicion de envio (null = metodo desconocido: se elige con motivo). No se
                // recuerda de despachos anteriores.
                'flete_pagador' => $envio['pagador_deducido'],
            ],
            // F1: condicion de envio, zona automatica, zonas, tarifas vigentes y pago sin confirmar (bloqueo).
            'envio' => $envio,
            'origenes' => OrigenGuia::elegibles(),
            'pagadores' => array_column(PagadorFlete::cases(), 'value'),
            'guias' => array_map(
                fn (array $g) => $g + ['versiones' => $this->guias->versiones($g['id']), 'correcciones' => $this->guias->correcciones($g['id'])],
                $this->guias->deOrdenes([$orderId], true)[$orderId] ?? [],
            ),
            'stock' => $this->stock($pedido->id, $despachables, $pendientes, $plan, $actor),
            'permisos' => [
                'despachar' => $puedeDespachar,
                'archivo' => $this->autorizacion->tienePermiso($actor, AutorizacionLogistica::PERMISO_GUIAS_ARCHIVO),
                'corregir' => $this->autorizacion->tienePermiso($actor, AutorizacionLogistica::PERMISO_GUIAS_CORREGIR),
                'ver_guia' => $this->autorizacion->tienePermiso($actor, AutorizacionLogistica::PERMISO_GUIAS_VER),
            ],
        ];
    }

    /**
     * C1: lo necesario para que el drawer proponga el recaudo de la guia (contra entrega): saldo exigible, lo ya comprometido por
     * otras guias, flete (solo si es la primera guia) y el valor historico de una unidad de cada linea pendiente.
     *
     * @param  Collection<int, OrderItem>  $pendientes
     * @return array<string, mixed>
     */
    private function recaudoGuia($pedido, $pendientes): array
    {
        $c = $this->recaudoPorGuia->contextoPedido($pedido);
        $unitarios = $c['contra_entrega'] ? $this->recaudoPorGuia->valoresUnitarios($pendientes->keys()->map(fn ($id) => (int) $id)->all()) : [];

        return [
            'contra_entrega' => $c['contra_entrega'],
            'exigible' => $c['exigible'],
            'comprometido' => $c['comprometido'],
            'disponible' => $c['disponible'],
            'hay_previas' => $c['hay_previas'],
            'flete' => $c['hay_previas'] ? 0.0 : $c['flete_pedido'],
            'unitarios' => (object) $unitarios,
        ];
    }

    /**
     * Faltante de stock del pedido (lineas con menos stock del sistema que lo pendiente) y estado de su incidencia. No
     * cambia la regla de cantidades: solo informa y habilita pedir/dar la regularizacion segun el permiso.
     *
     * @param  Collection<int, OrderItem>  $pendientes
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function stock(int $orderId, bool $despachables, $pendientes, array $plan, ?Admin $actor): array
    {
        $faltantes = $pendientes
            ->filter(fn (OrderItem $l) => $plan['lineas'][$l->id]['disponibles'] < $plan['lineas'][$l->id]['pendientes'])
            ->map(fn (OrderItem $l) => [
                'id' => (int) $l->id,
                'sku' => $l->sku,
                'nombre' => $l->name,
                'necesaria' => $plan['lineas'][$l->id]['pendientes'],
                'stock_sistema' => $plan['lineas'][$l->id]['disponibles'],
            ])->values()->all();

        $resumen = $this->stock->resumenParaPedido($orderId);
        $puedeSolicitar = $this->autorizacion->tienePermiso($actor, AutorizacionLogistica::PERMISO_STOCK_SOLICITAR);
        $puedeRegularizar = $this->autorizacion->tienePermiso($actor, AutorizacionLogistica::PERMISO_STOCK_REGULARIZAR);
        $hayFaltante = $despachables && $faltantes !== [];

        return [
            'hay_faltante' => $hayFaltante,
            'lineas' => $hayFaltante ? $faltantes : [],
            'abierta' => $resumen['abierta'],
            'ultima' => $resumen['ultima'],
            // Con una solicitud abierta BODEGA espera; el Administrador puede resolverla (o regularizar directo).
            'puede_solicitar' => $hayFaltante && $puedeSolicitar && ! $puedeRegularizar && $resumen['abierta'] === null,
            'puede_regularizar' => $hayFaltante && $puedeRegularizar,
        ];
    }

    /** Talla (o cualquier opcion que no sea el color) de la linea, solo para MOSTRAR el resumen; vacio si no hay. */
    private function talla(OrderItem $linea): string
    {
        $atributos = (array) (((array) $linea->additional)['attributes'] ?? []);

        return collect($atributos)->except('color')->pluck('option_label')->filter()->implode(' · ');
    }

    private function ciudad(object $direccion): string
    {
        $departamento = DB::table('country_states')
            ->where('country_code', $direccion->country)->where('code', $direccion->state)->value('default_name');

        return trim($direccion->city.', '.($departamento ?? $direccion->state), ', ');
    }

    private function tituloPago(string $metodo): ?string
    {
        if ($metodo === '') {
            return null;
        }

        return core()->getConfigData('sales.payment_methods.'.$metodo.'.title') ?: $metodo;
    }
}
