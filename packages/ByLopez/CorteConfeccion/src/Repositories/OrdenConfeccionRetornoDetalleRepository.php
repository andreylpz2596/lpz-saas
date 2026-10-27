<?php

namespace ByLopez\CorteConfeccion\Repositories;

use ByLopez\CorteConfeccion\Models\OrdenConfeccionRetornoDetalle;
use Webkul\Core\Eloquent\Repository;

class OrdenConfeccionRetornoDetalleRepository extends Repository
{
    public function model(): string
    {
        return OrdenConfeccionRetornoDetalle::class;
    }
}
