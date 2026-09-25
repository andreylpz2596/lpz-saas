<?php

namespace ByLopez\VentasAsesor\Repositories;

use ByLopez\VentasAsesor\Models\AtribucionPedido;
use Webkul\Core\Eloquent\Repository;

class AtribucionPedidoRepository extends Repository
{
    public function model(): string
    {
        return AtribucionPedido::class;
    }

    public function buscarPorPedido(int $orderId): ?AtribucionPedido
    {
        return $this->model->newQuery()->where('order_id', $orderId)->first();
    }
}
