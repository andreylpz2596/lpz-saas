<?php

namespace ByLopez\CorteConfeccion\Repositories;

use ByLopez\CorteConfeccion\Models\RecepcionCorteDetalle;
use Webkul\Core\Eloquent\Repository;

class RecepcionCorteDetalleRepository extends Repository
{
    public function model(): string
    {
        return RecepcionCorteDetalle::class;
    }
}
