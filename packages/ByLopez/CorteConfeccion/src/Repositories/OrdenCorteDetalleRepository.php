<?php

namespace ByLopez\CorteConfeccion\Repositories;

use ByLopez\CorteConfeccion\Models\OrdenCorteDetalle;
use Webkul\Core\Eloquent\Repository;

class OrdenCorteDetalleRepository extends Repository
{
    public function model(): string
    {
        return OrdenCorteDetalle::class;
    }
}
