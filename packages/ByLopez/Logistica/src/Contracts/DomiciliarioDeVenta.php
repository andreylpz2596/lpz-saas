<?php

namespace ByLopez\Logistica\Contracts;

/**
 * Lo que la VENTA (VentasAsesor) necesita de Logistica para que el asesor elija QUIEN ENTREGA un domicilio local, y para
 * comunicar esa eleccion al confirmar. Logistica es la duena de las reglas (zonas, clases de transportadora, historial)
 * y del registro; la venta solo muestra opciones y guarda el id elegido en su borrador.
 */
interface DomiciliarioDeVenta
{
    /**
     * Opciones para una venta en construccion. null = no es un domicilio local (recogida, nacional o direccion fuera de toda
     * zona local): no se muestra selector ni se exige nada. Con zona local el domiciliario es OBLIGATORIO: `requerido`;
     * `listo` = hay un domiciliario valido con tarifa resoluble en esa zona (lo que exige confirmar la venta). `tarifa` es
     * la del elegido en la zona; cada opcion trae la suya (null = sin tarifa en esa zona, no se puede usar en la venta).
     * Metodo de envio null = aun no elegido (admite local).
     *
     * @return array{zona: array{id: int, nombre: string}, opciones: list<array{id: int, nombre: string, tarifa: ?float}>, sugerido_id: ?int, elegido_id: ?int, tarifa: ?float, requerido: bool, listo: bool}|null
     */
    public function opcionesParaVenta(?string $municipioCodigo, ?string $metodoEnvio, ?int $elegidoId): ?array;

    public function esSeleccionable(int $transportadoraId): bool;

    /**
     * Guarda la eleccion del asesor con el pedido recien creado (corre dentro de la transaccion del pedido). Solo si el
     * pedido es domicilio local y la transportadora sigue siendo seleccionable; si no, no guarda nada (el despacho usara
     * la sugerencia por historial). Idempotente por pedido.
     */
    public function registrarDesdeVenta(int $orderId, int $transportadoraId, ?int $actorAdminId): void;
}
