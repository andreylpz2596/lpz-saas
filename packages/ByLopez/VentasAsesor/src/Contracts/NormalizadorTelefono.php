<?php

namespace ByLopez\VentasAsesor\Contracts;

use ByLopez\VentasAsesor\DataObjects\TelefonoNormalizado;

/**
 * Contrato de normalizacion de telefonos (RT-04B1a). La implementacion actual no
 * usa librerias; una futura basada en libphonenumber puede sustituirla por binding
 * sin cambiar consumidores ni datos: ambas producen E.164.
 */
interface NormalizadorTelefono
{
    /**
     * @param  string|null  $paisPorDefecto  ISO 3166-1 alfa-2 para numeros sin '+'.
     *                                       null → config('ventasasesor.telefono.pais_por_defecto').
     */
    public function normalizar(?string $valor, ?string $paisPorDefecto = null): TelefonoNormalizado;
}
