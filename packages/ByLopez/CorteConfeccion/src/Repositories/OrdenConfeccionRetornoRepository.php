<?php

namespace ByLopez\CorteConfeccion\Repositories;

use ByLopez\CorteConfeccion\Models\OrdenConfeccionRetorno;
use Webkul\Core\Eloquent\Repository;

class OrdenConfeccionRetornoRepository extends Repository
{
    public function model(): string
    {
        return OrdenConfeccionRetorno::class;
    }
}
