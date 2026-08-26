<?php

namespace ByLopez\CorteConfeccion\Repositories;

use ByLopez\CorteConfeccion\Models\RecepcionCorte;
use Webkul\Core\Eloquent\Repository;

class RecepcionCorteRepository extends Repository
{
    public function model(): string
    {
        return RecepcionCorte::class;
    }
}
