<?php

namespace ByLopez\VentasAsesor\Support\Venta;

use Closure;

/**
 * Memoria de UNA operacion por lote (03G-B2): lecturas de catalogo ya hechas en la misma
 * transaccion (producto, hijos con sus relaciones, super atributos, cliente apto) para no
 * repetirlas por talla o por solicitud. Solo guarda resultados exitosos: un fallo se vuelve
 * a evaluar y lanza igual. Nunca se persiste ni sobrevive a la operacion que la creo; no es
 * una cache comercial: precio y stock se siguen calculando con Bagisto en cada lectura.
 */
final class LoteCatalogo
{
    private array $recordados = [];

    public function recordar(string $clave, Closure $leer): mixed
    {
        if (! array_key_exists($clave, $this->recordados)) {
            $this->recordados[$clave] = $leer();
        }

        return $this->recordados[$clave];
    }
}
