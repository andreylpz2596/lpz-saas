<?php

namespace ByLopez\CorteConfeccion\Repositories;

use ByLopez\CorteConfeccion\Models\Cortador;
use Webkul\Core\Eloquent\Repository;

class CortadorRepository extends Repository
{
    public function model(): string
    {
        return Cortador::class;
    }
}
