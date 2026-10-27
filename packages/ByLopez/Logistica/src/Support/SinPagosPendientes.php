<?php

namespace ByLopez\Logistica\Support;

use ByLopez\Logistica\Contracts\PagosPendientesDeConfirmar;

/** Implementacion por defecto: sin Cartera no existen pagos sin revisar, asi que nada bloquea el despacho. */
class SinPagosPendientes implements PagosPendientesDeConfirmar
{
    public function estado(int $orderId): array
    {
        return ['sin_revisar' => 0.0, 'saldo' => null];
    }
}
