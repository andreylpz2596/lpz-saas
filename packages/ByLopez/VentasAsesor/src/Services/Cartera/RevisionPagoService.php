<?php

namespace ByLopez\VentasAsesor\Services\Cartera;

use ByLopez\VentasAsesor\Enums\AlcanceVisibilidad;
use ByLopez\VentasAsesor\Exceptions\RevisionPagoInvalidaException as Rechazo;
use ByLopez\VentasAsesor\Models\AplicacionPago;
use ByLopez\VentasAsesor\Models\Pago;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use ByLopez\VentasAsesor\Support\Cartera\AlcanceDePagos;
use ByLopez\VentasAsesor\Support\Cartera\FechaCorteCartera;
use Illuminate\Database\Eloquent\Collection;
use Webkul\User\Contracts\Admin as AdminContract;

/**
 * Base comun de la REVISION de pagos (PAG-E): confirmar y rechazar comparten autorizacion, alcance y bloqueo.
 *
 * Autorizacion en el servicio (no solo en la ruta ni en los botones): `ventas_asesor.cartera.revisar` MAS alcance de
 * cartera sobre el pago. Un pago esta en alcance si el actor tiene `view_all`, o `view_own` y TODOS los pedidos de sus
 * aplicaciones (tambien las anuladas) estan atribuidos a el: revisar un pago afecta a todos sus pedidos. Fuera de
 * alcance se responde como inexistente (no se revela).
 *
 * Orden de bloqueos (Doc. 05 §42.5): pago → aplicaciones → pedidos (id ascendente) → facturas (id ascendente).
 */
abstract class RevisionPagoService
{
    /** Reintentos ante deadlock / lock wait timeout. */
    protected const REINTENTOS = 3;

    public function __construct(
        protected AutorizacionVentasAsesor $autorizacion,
        protected CarteraConsultaService $consulta,
        protected FechaCorteCartera $fechaCorte,
        protected AlcanceDePagos $alcancePagos,
    ) {}

    protected function exigirRevisor(AdminContract $actor): void
    {
        if (! $this->autorizacion->tienePermiso($actor, AutorizacionVentasAsesor::PERMISO_CARTERA_REVISAR)) {
            throw new Rechazo(Rechazo::SIN_PERMISO, 'No tienes permiso para revisar pagos.');
        }

        if ($this->autorizacion->alcanceDeCartera($actor) === AlcanceVisibilidad::NINGUNO) {
            throw new Rechazo(Rechazo::SIN_PERMISO, 'Para revisar pagos necesitas ademas ver la cartera (propia o toda).');
        }
    }

    /** Pago bloqueado (`FOR UPDATE`) y dentro del alcance del actor. Debe llamarse DENTRO de la transaccion. */
    protected function bloquearPago(AdminContract $actor, int $pagoId): Pago
    {
        $pago = Pago::query()->whereKey($pagoId)->lockForUpdate()->first();

        if ($pago === null || ! $this->enAlcance($actor, $pagoId)) {
            throw new Rechazo(Rechazo::PAGO_INEXISTENTE, 'El pago no existe o no esta disponible para ti.');
        }

        return $pago;
    }

    /**
     * @return Collection<int, AplicacionPago> aplicaciones ACTIVAS del pago, bloqueadas, por pedido ascendente
     */
    protected function aplicacionesActivasBloqueadas(int $pagoId): Collection
    {
        return AplicacionPago::query()->where('pago_id', $pagoId)->whereNull('anulada_en')
            ->orderBy('order_id')->lockForUpdate()->get();
    }

    protected function enAlcance(AdminContract $actor, int $pagoId): bool
    {
        return $this->alcancePagos->incluye($actor, $pagoId);
    }
}
