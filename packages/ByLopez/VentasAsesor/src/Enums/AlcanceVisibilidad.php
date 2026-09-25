<?php

namespace ByLopez\VentasAsesor\Enums;

/**
 * Alcance de visibilidad de pedidos de un administrador, derivado solo de ACL.
 */
enum AlcanceVisibilidad: string
{
    case TODOS = 'todos';

    case PROPIOS = 'propios';

    case NINGUNO = 'ninguno';
}
