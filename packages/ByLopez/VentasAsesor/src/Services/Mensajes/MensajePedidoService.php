<?php

namespace ByLopez\VentasAsesor\Services\Mensajes;

use ByLopez\Logistica\Contracts\MensajesPedidoCliente;
use ByLopez\Logistica\Exceptions\MensajePedidoNoDisponibleException as NoDisponible;
use ByLopez\Logistica\Support\AutorizacionLogistica;
use ByLopez\VentasAsesor\Enums\AlcanceVisibilidad;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use ByLopez\VentasAsesor\Support\Mensajes\AjustesMensajes;
use Illuminate\Support\Facades\DB;
use Webkul\User\Contracts\Admin;

/**
 * UNICO punto que decide QUE mensajes al cliente corresponden a un pedido y los genera (Pedidos, Despachos y Entregas solo lo
 * consumen; un futuro endpoint para n8n tambien). Solo lectura: generar un mensaje nunca cambia un estado, un stock ni una cuenta.
 *
 * Quien puede (siempre en servidor):
 *   - VENTAS: alcance de Pedidos (`view_own` solo sus pedidos por atribucion; `view_all` todos). Ve el total del pedido; abonos y
 *     saldo solo con alcance de Cartera sobre ESE pedido.
 *   - LOGISTICA: `logistica.despachos` o `logistica.entregas` (BODEGA). Mensajes operativos SIN ningun dinero ni resumen comercial
 *     (BODEGA no ve el valor del pedido).
 *   Sin ninguna de las dos: sin_permiso. Ocultar un boton no autoriza nada.
 *
 * Que mensajes (por el estado REAL del pedido; una opcion que no corresponde no se ofrece ni se genera):
 *   resumen · listo_domicilio · listo_recoger · despacho_completo | despacho_parcial | despacho_final (uno POR despacho) · entregado.
 */
class MensajePedidoService implements MensajesPedidoCliente
{
    private const ESTADOS_SIN_MENSAJE = ['canceled', 'closed', 'fraud'];

    public function __construct(
        protected LectorPedidoParaMensaje $lector,
        protected AutorizacionVentasAsesor $ventas,
        protected AutorizacionLogistica $logistica,
        protected AjustesMensajes $ajustes,
    ) {}

    public function opciones(int $orderId, Admin $actor): array
    {
        [$datos, $comercial] = $this->preparar($orderId, $actor);

        return array_map(fn (array $o) => ['clave' => $o['clave'], 'tipo' => $o['tipo'], 'etiqueta' => $o['etiqueta']], $this->disponibles($datos, $comercial));
    }

    public function generar(int $orderId, string $clave, Admin $actor): array
    {
        [$datos, $comercial] = $this->preparar($orderId, $actor);
        $opcion = collect($this->disponibles($datos, $comercial))->firstWhere('clave', $clave) ?? throw new NoDisponible(NoDisponible::MENSAJE_NO_DISPONIBLE);

        $texto = (new PlantillasMensajePedido($this->ajustes->marca(), $this->ajustes->usarEmojis(), $this->ajustes->fraseFinal()))
            ->armar($opcion['tipo'], $datos, $opcion['envio'] ?? null);

        return [
            'clave' => $opcion['clave'],
            'tipo' => $opcion['tipo'],
            'etiqueta' => $opcion['etiqueta'],
            'texto' => $texto,
            'telefono' => $datos['cliente']['telefono'],
            'pedido' => ['id' => $datos['pedido']['id'], 'numero' => $datos['pedido']['numero']],
        ];
    }

    /**
     * Autoriza y lee. Devuelve [datos, esComercial]: `esComercial` = el actor entra por Ventas (ve el valor del pedido y el resumen).
     *
     * @return array{0: array<string, mixed>, 1: bool}
     */
    protected function preparar(int $orderId, Admin $actor): array
    {
        $porVentas = $this->ventas->alcanceDeVisibilidad($actor) !== AlcanceVisibilidad::NINGUNO;
        $porLogistica = $this->logistica->tienePermiso($actor, AutorizacionLogistica::PERMISO_DESPACHOS)
            || $this->logistica->tienePermiso($actor, AutorizacionLogistica::PERMISO_ENTREGAS);

        // Sin ningun camino de acceso no se revela ni siquiera si el pedido existe.
        if (! $porVentas && ! $porLogistica) {
            throw new NoDisponible(NoDisponible::SIN_PERMISO);
        }

        if (! DB::table('orders')->where('id', $orderId)->exists()) {
            throw new NoDisponible(NoDisponible::PEDIDO_INEXISTENTE);
        }

        $asesor = DB::table('bylopez_va_order_attributions')->where('order_id', $orderId)->value('advisor_id');
        $asesor = $asesor === null ? null : (int) $asesor;

        // Un asesor solo ve lo suyo; sin alcance de ventas sobre ESTE pedido, queda la puerta de Logistica (sin dinero).
        $comercial = $porVentas && $this->ventas->puedeVerRegistroDe($actor, $asesor);

        if (! $comercial && ! $porLogistica) {
            throw new NoDisponible(NoDisponible::SIN_PERMISO);
        }

        $datos = $this->lector->leer($orderId, $comercial, $comercial && $this->ventas->puedeVerCarteraDe($actor, $asesor))
            ?? throw new NoDisponible(NoDisponible::PEDIDO_INEXISTENTE);

        return [$datos, $comercial];
    }

    /**
     * @param  array<string, mixed>  $d
     * @return list<array{clave: string, tipo: string, etiqueta: string, envio?: array<string, mixed>}>
     */
    protected function disponibles(array $d, bool $comercial): array
    {
        $p = $d['pedido'];

        if (in_array($p['estado'], self::ESTADOS_SIN_MENSAJE, true) || $d['objetivo'] <= 0) {
            return [];
        }

        $opciones = [];
        $etiqueta = fn (string $tipo) => (string) trans('ventasasesor::app.mensajes.etiquetas.'.$tipo);

        // El resumen lleva el valor del pedido: es comercial y BODEGA no lo ve.
        if ($comercial) {
            $opciones[] = ['clave' => 'resumen', 'tipo' => PlantillasMensajePedido::RESUMEN, 'etiqueta' => $etiqueta('resumen')];
        }

        if ($d['despachadas'] === 0 && $p['es_recogida']) {
            $opciones[] = ['clave' => 'listo-recoger', 'tipo' => PlantillasMensajePedido::LISTO_RECOGER, 'etiqueta' => $etiqueta('listo_recoger')];
        }

        if ($d['despachadas'] === 0 && $p['es_domicilio']) {
            $opciones[] = ['clave' => 'listo-domicilio', 'tipo' => PlantillasMensajePedido::LISTO_DOMICILIO, 'etiqueta' => $etiqueta('listo_domicilio')];
        }

        $n = count($d['envios']);

        foreach ($d['envios'] as $e) {
            // Recogida en bodega: la mercancia se entrega en mano, no hay «despacho» con guia que avisar.
            if ($p['es_recogida'] || $e['es_recogida']) {
                continue;
            }

            // Por DESPACHO: solo sus prendas y SU guia (nunca se mezclan guias ni se presenta una como cubriendo lo de otra).
            $tipo = match (true) {
                $e['pendientes_total_despues'] > 0 => PlantillasMensajePedido::DESPACHO_PARCIAL,
                $e['orden'] > 1 => PlantillasMensajePedido::DESPACHO_FINAL,
                default => PlantillasMensajePedido::DESPACHO_COMPLETO,
            };

            $opciones[] = [
                'clave' => 'despacho-'.$e['id'],
                'tipo' => $tipo,
                'etiqueta' => $n > 1
                    ? (string) trans('ventasasesor::app.mensajes.etiquetas.despacho_n', ['n' => $e['orden'], 'total' => $n]).' · '.($e['guia']['transportadora'] ?? $etiqueta($tipo))
                    : $etiqueta($tipo),
                'envio' => $e,
            ];
        }

        // Entregado solo con TODOS los envios con entrega validada vigente y nada pendiente de despachar.
        if ($n > 0 && $d['despachadas'] >= $d['objetivo'] && collect($d['envios'])->every(fn ($e) => $e['validado'])) {
            $opciones[] = ['clave' => 'entregado', 'tipo' => PlantillasMensajePedido::ENTREGADO, 'etiqueta' => $etiqueta('entregado')];
        }

        return $opciones;
    }
}
