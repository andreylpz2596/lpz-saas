<?php

namespace ByLopez\Logistica\Support;

use ByLopez\Logistica\Contracts\ResuelveMunicipio;

/** Implementacion por defecto: sin catalogo geografico no se resuelve ningun municipio (la zona se elige a mano). */
class SinResolucionMunicipio implements ResuelveMunicipio
{
    public function municipioDePedido(int $orderId): ?string
    {
        return null;
    }

    public function municipioDeDireccion(?string $ciudad, ?string $estado, ?string $pais): ?string
    {
        return null;
    }
}
