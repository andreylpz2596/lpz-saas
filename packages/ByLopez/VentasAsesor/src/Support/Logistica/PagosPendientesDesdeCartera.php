<?php

namespace ByLopez\VentasAsesor\Support\Logistica;

use ByLopez\Logistica\Contracts\PagosPendientesDeConfirmar;
use ByLopez\VentasAsesor\Services\Cartera\CarteraConsultaService;

/**
 * Pagos registrados SIN CONFIRMAR de un pedido y el saldo que queda descontandolos, desde la fuente unica de Cartera
 * (CarteraConsultaService). Solo lectura: no confirma, no rechaza ni mueve el saldo. Pedido fuera de Cartera (historico
 * o Cartera inactiva): no hay pagos sin revisar que considerar.
 */
class PagosPendientesDesdeCartera implements PagosPendientesDeConfirmar
{
    public function __construct(protected CarteraConsultaService $cartera) {}

    public function estado(int $orderId): array
    {
        $saldo = $this->cartera->saldosDePedidos([$orderId])[$orderId] ?? null;

        if ($saldo === null || ! $saldo->enCartera) {
            return ['sin_revisar' => 0.0, 'saldo' => null];
        }

        return ['sin_revisar' => round((float) $saldo->sinRevisar, 2), 'saldo' => round((float) $saldo->saldo, 2)];
    }
}
