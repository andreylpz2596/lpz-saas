<?php

namespace ByLopez\CorteConfeccion\Repositories;

use ByLopez\CorteConfeccion\Models\CompraTela;
use Webkul\Core\Eloquent\Repository;

class CompraTelaRepository extends Repository
{
    public function model(): string
    {
        return CompraTela::class;
    }
}
