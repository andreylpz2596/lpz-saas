<?php

namespace ByLopez\CorteConfeccion\Repositories;

use ByLopez\CorteConfeccion\Models\Rollo;
use Webkul\Core\Eloquent\Repository;

class RolloRepository extends Repository
{
    public function model(): string
    {
        return Rollo::class;
    }
}
