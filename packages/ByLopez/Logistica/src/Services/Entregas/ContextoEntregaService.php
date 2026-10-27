<?php

namespace ByLopez\Logistica\Services\Entregas;

use ByLopez\Logistica\Contracts\GestionaCobroPedido;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Models\CatalogoDefecto;
use ByLopez\Logistica\Services\Transportadoras\TransportadorasService;
use ByLopez\Logistica\Support\AutorizacionLogistica;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\User\Contracts\Admin;

/**
 * Contexto JSON del drawer de Entregas (un solo drawer: ver / entregar / devolver / recibir). Solo ARMA lectura sobre
 * `ConsultaEntregaService::envio()`: no hay reglas de negocio nuevas aqui. Los permisos que se informan son los que los
 * servicios vuelven a exigir al guardar; ocultar un boton no autoriza nada.
 */
class ContextoEntregaService
{
    public function __construct(
        protected ConsultaEntregaService $consulta,
        protected AutorizacionLogistica $autorizacion,
        protected TransportadorasService $transportadoras,
        protected GestionaCobroPedido $cobro,
        protected ReporteProveedorService $reportes,
    ) {}

    /** @return array<string, mixed> */
    public function paraEnvio(int $shipmentId, ?Admin $actor): array
    {
        if (! $this->autorizacion->tienePermiso($actor, AutorizacionLogistica::PERMISO_ENTREGAS)) {
            throw new Invalida(Invalida::SIN_PERMISO);
        }

        $d = $this->consulta->envio($shipmentId) ?? throw new Invalida(Invalida::ENVIO_INEXISTENTE);
        $envio = $d['envio'];
        $r = $d['resumen'];
        $fecha = fn ($v) => $v ? core()->formatDate($v, 'd M Y') : null;
        $fechaHora = fn ($v) => $v ? core()->formatDate($v, 'd M Y H:i') : null;

        $devoluciones = array_map(function (array $x) use ($fecha) {
            $x['fecha_texto'] = $fecha($x['fecha']);

            if ($x['recepcion']) {
                $x['recepcion']['fecha_texto'] = $fecha($x['recepcion']['fecha']);
            }

            return $x;
        }, $d['devoluciones']);

        $historial = array_map(function (array $h) use ($fechaHora, $fecha) {
            $h['cuando_texto'] = $fechaHora($h['cuando']);
            $h['fecha_real_texto'] = isset($h['fecha_real']) ? $fecha($h['fecha_real']) : null;

            return $h;
        }, $d['historial']);

        $puedeRegistrar = $this->autorizacion->tienePermiso($actor, AutorizacionLogistica::PERMISO_REGISTRAR);

        return [
            'envio' => [
                'id' => (int) $envio->id,
                'pedido_id' => (int) $envio->order_id,
                'pedido' => $envio->increment_id,
                'cliente' => $envio->cliente,
                'telefono' => $d['contacto']['telefono'],
                'destino' => $d['contacto']['destino'],
                'direccion' => $d['contacto']['direccion'],
                'transportadora' => [
                    'codigo' => $envio->carrier_code,
                    'nombre' => $this->transportadoras->nombreDe($envio->carrier_code),
                    'titulo' => $envio->carrier_title,
                ],
                'guia' => $d['guia']->numero_guia ?? ($envio->track_number ?: null),
                'tracking' => $d['guia']->tracking ?? null,
                'metodo' => $envio->shipping_title ?: $envio->shipping_method,
                'bodega' => $envio->inventory_source_name,
                'despachado' => Carbon::parse($envio->created_at)->toDateString(),
                'despachado_texto' => $fecha($envio->created_at),
                'es_recogida' => $envio->shipping_method === 'pickup',
            ],
            'resumen' => ['despachadas' => $r['despachadas'], 'entregadas' => $r['entregadas'], 'devueltas' => $r['retornadas'], 'pendientes' => $r['pendientes']],
            'estado_entrega' => $d['estado_entrega'],
            'validacion' => $d['validacion'],
            'estado_devolucion' => $d['estado_devolucion'],
            'validada' => $d['validacion_registro'] ? [
                'id' => (int) $d['validacion_registro']->id, 'actor' => $d['validacion_registro']->actor_name_snapshot,
                'cuando_texto' => $fechaHora($d['validacion_registro']->created_at), 'observacion' => $d['validacion_registro']->observacion,
            ] : null,
            'lista_para_validar' => $d['lista_para_validar'],
            'cierre' => $this->cierre($d, $actor),
            'tipo_producto' => $d['tipo_producto'],
            'productos' => array_map(fn (array $p) => $p + ['filas' => array_map(fn (array $f) => [
                'shipment_item_id' => $f['shipment_item_id'], 'sku' => $f['sku'] ?? null, 'talla' => $f['talla'],
                'despachadas' => $f['despachadas'], 'entregadas' => $f['entregadas'], 'devueltas' => $f['retornadas'], 'pendientes' => $f['pendientes'],
            ], $p['filas'])], $d['productos']),
            'devoluciones' => $devoluciones,
            // C5: clasificacion de defectuosas al recibir (catalogo parametrizable: motivo del defecto y origen probable).
            'catalogo_defectos' => ['motivos' => CatalogoDefecto::activos(CatalogoDefecto::MOTIVO), 'origenes' => CatalogoDefecto::activos(CatalogoDefecto::ORIGEN)],
            // Motivos COMERCIALES para registrar una devolucion (otro tipo del catalogo; vacio si la base no tiene la migracion).
            'motivos_devolucion' => CatalogoDefecto::activos(CatalogoDefecto::DEVOLUCION),
            'historial' => $historial,
            'reporte_proveedor' => $this->reporteProveedor((int) $envio->id, $fechaHora),
            'cita_recogida' => $d['cita_recogida'] ? ['fecha' => $fecha($d['cita_recogida']->pickup_date), 'desde' => substr((string) $d['cita_recogida']->start_time, 0, 5), 'hasta' => substr((string) $d['cita_recogida']->end_time, 0, 5)] : null,
            'permisos' => [
                'registrar' => $puedeRegistrar,
                'anular' => $this->autorizacion->tienePermiso($actor, AutorizacionLogistica::PERMISO_ANULAR),
                'recibir' => $this->autorizacion->tienePermiso($actor, AutorizacionLogistica::PERMISO_RECIBIR_DEVOLUCION),
                'validar' => $this->autorizacion->tienePermiso($actor, AutorizacionLogistica::PERMISO_VALIDAR),
            ],
            'form' => [
                'hoy' => now()->toDateString(),
                'fecha_min' => Carbon::parse($envio->created_at)->toDateString(),
                'requiere_transportadora' => ! $envio->carrier_code,
                'transportadoras' => $envio->carrier_code || ! $puedeRegistrar ? [] : $this->transportadoras->activas()->map(fn ($t) => ['codigo' => $t->codigo, 'nombre' => $t->nombre])->values()->all(),
            ],
        ];
    }

    /**
     * Estado reportado por el proveedor intermediario (99 Envios) para la guia del envio: SOLO informativo. No es la entrega
     * registrada ni validada por ByLopez ni un pago. null si no hay reporte aplicado.
     *
     * @return array<string, mixed>|null
     */
    private function reporteProveedor(int $shipmentId, \Closure $fechaHora): ?array
    {
        $r = $this->reportes->detalleDeEnvio($shipmentId);

        if ($r === null) {
            return null;
        }

        $r['transportadora_nombre'] = $this->transportadoras->nombreDe($r['transportadora_codigo']);
        $r['fecha_estado_texto'] = $fechaHora($r['fecha_estado']);
        $r['fecha_envio_texto'] = $fechaHora($r['fecha_envio']);
        $r['ultimo_cruce_texto'] = $fechaHora($r['ultimo_cruce']);
        $r['historial'] = array_map(function (array $h) use ($fechaHora) {
            $h['fecha_estado_texto'] = $fechaHora($h['fecha_estado_proveedor']);
            $h['cruce_texto'] = $fechaHora($h['created_at']);

            return $h;
        }, $r['historial']);

        return $r;
    }

    /**
     * Cierre del pedido tal como esta HOY en Bagisto/Cartera (solo lectura): estado del pedido y de su factura, y el cobro
     * disponible. El pedido se completa y la factura pasa a pagada unicamente cuando Cartera confirma el dinero.
     *
     * @return array<string, mixed>
     */
    private function cierre(array $d, ?Admin $actor): array
    {
        $orderId = (int) $d['envio']->order_id;
        $facturas = DB::table('invoices')->where('order_id', $orderId)->pluck('state')->all();
        $puede = $this->autorizacion->tienePermiso($actor, AutorizacionLogistica::PERMISO_VALIDAR);
        $saldo = $puede ? $this->cobro->saldo($actor, $orderId) : null;
        $estado = (string) $d['envio']->estado_pedido;

        $motivo = match (true) {
            ! $puede => 'sin_permiso',
            $d['resumen']['entregadas'] <= 0 => 'sin_entregas',
            $saldo === null => 'sin_cartera',
            ! $saldo['en_cartera'] => 'fuera_cartera',
            $saldo['requiere_revision'] => 'requiere_revision',
            ($saldo['saldo'] ?? 0) <= 0 => 'sin_saldo',
            default => null,
        };

        return [
            'pedido_estado' => $estado,
            'factura_estado' => $facturas === [] ? null : (in_array('pending', $facturas, true) ? 'pending' : $facturas[0]),
            'cerrado' => in_array($estado, ['completed', 'closed'], true),
            'cobro' => [
                'disponible' => $motivo === null,
                'motivo' => $motivo,
                'total' => $saldo['total'] ?? null,
                'saldo' => $saldo['saldo'] ?? null,
                'sin_revisar' => $saldo['sin_revisar'] ?? null,
                'verificado' => $saldo['verificado'] ?? null,
                'medios' => $motivo === null ? $this->cobro->medios() : [],
            ],
        ];
    }
}
