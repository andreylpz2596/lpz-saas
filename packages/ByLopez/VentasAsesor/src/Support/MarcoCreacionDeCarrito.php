<?php

namespace ByLopez\VentasAsesor\Support;

/**
 * Contexto de CREACION: se conoce al cliente comercial pero su carrito todavia no
 * existe (p. ej. volver a pedir un pedido anterior). Es transitorio: en cuanto se
 * crea el carrito de ESE cliente, ContextoPrecioComercial lo sustituye por un
 * MarcoPrecioComercial normal (cart_id + customer_id). Si se crea un carrito de
 * otro cliente mientras esta activo, se rechaza (fail closed).
 *
 * @internal Solo lo usa ContextoPrecioComercial.
 */
final class MarcoCreacionDeCarrito extends MarcoDePrecio {}
