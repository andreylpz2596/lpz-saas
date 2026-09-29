<?php

namespace ByLopez\VentasAsesor\DataObjects;

/**
 * Matriz de variantes de un producto para vender por tallas (03G-B), lista para que la UI la
 * reciba en UNA respuesta: producto, color, variantes en orden de talla, stock y precio de
 * Bagisto, y la senal de ventas simultaneas calculada en lote. No es disponibilidad: el
 * stock es el de Bagisto y la senal es contexto que nunca se resta de el.
 */
final class MatrizVariantes
{
    /**
     * @param  list<array{option_id: int|null, etiqueta: string|null}>  $colores
     * @param  list<CeldaMatriz>  $celdas
     */
    public function __construct(
        public readonly int $productoId,
        public readonly string $tipo,
        public readonly string $sku,
        public readonly string $nombre,
        public readonly array $colores,
        public readonly array $celdas,
        public readonly int $cantidadConsultada,
    ) {}

    /** @return list<int> */
    public function varianteIds(): array
    {
        return array_map(fn (CeldaMatriz $c) => $c->variante->productId, $this->celdas);
    }

    public function toArray(): array
    {
        return [
            'producto_id' => $this->productoId,
            'tipo' => $this->tipo,
            'sku' => $this->sku,
            'nombre' => $this->nombre,
            'colores' => $this->colores,
            'celdas' => array_map(fn (CeldaMatriz $c) => $c->toArray(), $this->celdas),
            'cantidad_consultada' => $this->cantidadConsultada,
        ];
    }
}
