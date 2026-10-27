<?php

namespace ByLopez\CorteConfeccion\Repositories;

use ByLopez\CorteConfeccion\Models\Taller;
use Webkul\Core\Eloquent\Repository;

class TallerRepository extends Repository
{
    public function model(): string
    {
        return Taller::class;
    }
}
