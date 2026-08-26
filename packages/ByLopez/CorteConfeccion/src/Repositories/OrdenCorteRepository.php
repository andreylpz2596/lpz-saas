<?php

namespace ByLopez\CorteConfeccion\Repositories;

use ByLopez\CorteConfeccion\Models\OrdenCorte;
use Webkul\Core\Eloquent\Repository;

class OrdenCorteRepository extends Repository
{
    public function model(): string
    {
        return OrdenCorte::class;
    }
}
