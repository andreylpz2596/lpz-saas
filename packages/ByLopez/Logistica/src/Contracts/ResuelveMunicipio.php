<?php

namespace ByLopez\Logistica\Contracts;

/**
 * Municipio DIVIPOLA (5 digitos) de la direccion de envio de un pedido, SOLO si coincide exactamente con el catalogo; si
 * no, null (no se adivina por texto ambiguo). Logistica trae una implementacion que no resuelve nada; VentasAsesor, que
 * es dueno del catalogo geografico, la reemplaza.
 */
interface ResuelveMunicipio
{
    public function municipioDePedido(int $orderId): ?string;

    /** Misma regla para una direccion que aun no es un pedido (carrito de una venta en construccion). */
    public function municipioDeDireccion(?string $ciudad, ?string $estado, ?string $pais): ?string;
}
