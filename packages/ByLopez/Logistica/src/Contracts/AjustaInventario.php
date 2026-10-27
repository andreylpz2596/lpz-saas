<?php

namespace ByLopez\Logistica\Contracts;

use Webkul\User\Contracts\Admin;

/**
 * Unica puerta de Logistica para CORREGIR el inventario fisico de Bagisto. Logistica no tiene logica de stock propia: la
 * implementacion por defecto delega en el ajuste manual que ya existe en CorteConfeccion (libro de movimientos,
 * idempotencia, bloqueo de fila, sin negativos, indice vendible), sin modificarlo. Si algun dia hay un dominio de
 * inventario neutral, solo cambia el enlace de este contrato.
 *
 * Solo SUBE el stock hasta un conteo fisico: bajarlo no es parte de la excepcion de despacho.
 */
interface AjustaInventario
{
    /** Bodega que se afecta (hoy hay una sola activa en el canal; con varias, la implementacion falla de forma explicita). */
    public function fuente(): object;

    /** Stock fisico (`product_inventories.qty`) de la variante en esa bodega, tal como lo mide el despacho. */
    public function stockActual(int $productId): int;

    /**
     * Sube el stock de la variante hasta `$conteo` y devuelve el movimiento del libro de inventario.
     *
     * @return array{movimiento_id: int, anterior: int, nueva: int, diferencia: int}
     */
    public function elevarAConteo(Admin $actor, int $productId, int $conteo, string $motivo, string $clave): array;

    /**
     * Ultimos movimientos del libro para una variante (contexto de la revision).
     *
     * @return list<array<string, mixed>>
     */
    public function ultimosMovimientos(int $productId, int $limite = 5): array;
}
