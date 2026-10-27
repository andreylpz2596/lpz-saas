<?php

namespace ByLopez\CorteConfeccion\Repositories;

use ByLopez\CorteConfeccion\Models\TarifaPrestador;
use Webkul\Core\Eloquent\Repository;

class TarifaPrestadorRepository extends Repository
{
    public function model(): string
    {
        return TarifaPrestador::class;
    }
}
