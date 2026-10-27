<?php

namespace ByLopez\CorteConfeccion\Repositories;

use ByLopez\CorteConfeccion\Models\OrdenConfeccion;
use Webkul\Core\Eloquent\Repository;

class OrdenConfeccionRepository extends Repository
{
    public function model(): string
    {
        return OrdenConfeccion::class;
    }
}
