<?php

namespace ByLopez\VentasAsesor\Enums;

/** Decision de ResolverClienteComercialService (RT-04B1b). */
enum EstadoResolucionCliente: string
{
    /** El telefono (o, si el telefono no tiene titular, el email) identifica a un Customer: usarlo. */
    case EXISTENTE = 'existente';

    /** Nadie tiene el telefono ni el email: se puede crear (CrearClienteComercialService). */
    case NUEVO = 'nuevo';

    /** Datos contradictorios (telefono de A y email de B, o telefono en varias fichas): decision humana. */
    case CONFLICTO = 'conflicto';
}
