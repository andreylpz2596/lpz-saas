<?php

namespace ByLopez\Logistica\Services\Entregas;

use ByLopez\Logistica\Contracts\GestionaCobroPedido;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Support\AutorizacionLogistica;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Webkul\User\Contracts\Admin;

/**
 * COBRO CONTRA ENTREGA desde el drawer de Entregas. Logistica NO escribe pagos ni facturas: valida que haya una entrega
 * y delega en Cartera (`GestionaCobroPedido`), que registra el pago, lo confirma si el actor puede revisar y deja la
 * factura `paid` y el pedido recalculado por los repositorios de Bagisto. Cartera vuelve a validar saldo, medio,
 * referencia, permisos e idempotencia: ocultar o mostrar el formulario no autoriza nada.
 */
class CobrarEntregaService
{
    public function __construct(
        protected AutorizacionLogistica $autorizacion,
        protected ConsultaEntregaService $consulta,
        protected GestionaCobroPedido $cobro,
    ) {}

    /** @return array{pago_id: int, confirmado: bool, duplicado: bool, aviso: ?string} */
    public function cobrar(?Admin $actor, int $shipmentId, string $monto, string $medio, ?string $referencia, ?string $fecha, string $idempotencyKey): array
    {
        if (! $this->autorizacion->tienePermiso($actor, AutorizacionLogistica::PERMISO_VALIDAR)) {
            throw new Invalida(Invalida::SIN_PERMISO);
        }

        $clave = trim($idempotencyKey);

        if (! preg_match(RegistrarEventoEnvio::PATRON_IDEMPOTENCIA, $clave) || strlen($clave) < 16) {
            throw new Invalida(Invalida::IDEMPOTENCIA_INVALIDA);
        }

        $orderId = (int) (DB::table('shipments')->where('id', $shipmentId)->value('order_id') ?? 0);

        if ($orderId === 0) {
            throw new Invalida(Invalida::ENVIO_INEXISTENTE);
        }

        // Solo se cobra una entrega que ya ocurrio (al menos una unidad entregada).
        if (array_sum(array_column($this->consulta->saldosDeEnvio($shipmentId), 'entregadas')) <= 0) {
            throw new Invalida(Invalida::VALIDACION_SIN_ENTREGAS);
        }

        $fecha = $fecha === null || trim($fecha) === '' ? CarbonImmutable::today()->format('Y-m-d') : trim($fecha);

        // C6: el cobro de una guia que liquida por cuenta corriente queda vinculado a ella (el dinero queda en custodia del transportador).
        $guia = DB::table('bylopez_lg_guias')->where('shipment_id', $shipmentId)->first(['id', 'liquida_cuenta_corriente']);
        $guiaId = $guia !== null && $guia->liquida_cuenta_corriente ? (int) $guia->id : null;

        return $this->cobro->cobrar($actor, $orderId, trim($monto), trim($medio), $referencia === null || trim($referencia) === '' ? null : trim($referencia), $fecha, $clave, $guiaId);
    }
}
