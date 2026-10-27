<?php

namespace ByLopez\Logistica\Contracts;

use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException;
use Webkul\User\Contracts\Admin;

/**
 * Frontera de Logistica con Cartera para el COBRO CONTRA ENTREGA al validar una entrega. Logistica no escribe pagos,
 * facturas ni estados de pedido: pide el saldo y, si el actor lo decide, registra el cobro por la MISMA via oficial de
 * Cartera (registrar pago → confirmar → la factura pasa a pagada y el pedido recalcula su estado por Bagisto).
 * Nunca se fuerza una factura como pagada sin dinero registrado.
 */
interface GestionaCobroPedido
{
    /**
     * Saldo comercial del pedido para el actor. Null si el actor no puede ver la cartera de ese pedido.
     *
     * @return array{en_cartera: bool, motivo_fuera: ?string, total: float, saldo: ?float, sin_revisar: ?float, verificado: ?float, requiere_revision: bool}|null
     */
    public function saldo(Admin $actor, int $orderId): ?array;

    /** Medios de cobro activos que no exigen plataforma (efectivo, recaudo contra entrega...). @return list<array{codigo: string, etiqueta: string, referencia: string}> */
    public function medios(): array;

    /**
     * Registra el cobro y, si el actor puede revisar pagos, lo confirma en el acto. Idempotente por `$clave`.
     * C6: con `$guiaId` (guia de cuenta corriente) el pago queda vinculado a esa guia y, al confirmarse, el dinero queda en custodia.
     *
     * @return array{pago_id: int, confirmado: bool, duplicado: bool, aviso: ?string}
     *
     * @throws OperacionLogisticaInvalidaException con COBRO_RECHAZADO (mensaje de Cartera)
     */
    public function cobrar(Admin $actor, int $orderId, string $monto, string $medio, ?string $referencia, string $fecha, string $clave, ?int $guiaId = null): array;
}
