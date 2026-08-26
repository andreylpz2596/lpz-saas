<?php

namespace ByLopez\CorteConfeccion\Repositories;

use ByLopez\CorteConfeccion\Models\ProveedorTela;
use Webkul\Core\Eloquent\Repository;

class ProveedorTelaRepository extends Repository
{
    public function model(): string
    {
        return ProveedorTela::class;
    }
}
