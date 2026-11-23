<?php

namespace ByLopez\VentasAsesor\Support\Autoservicio;

/**
 * Doc. 33 D-CF-08: lo que el cliente frecuente puede ver del inventario. 0 = Agotado; 1..10 = cantidad exacta; mas de 10 =
 * «Más de 10 disponibles». El limite es SOLO de presentacion: el stock real no cambia y el servidor valida siempre las
 * cantidades exactas. Este saneador se aplica a TODA respuesta JSON del autoservicio (matrices, estado, errores): quita la
 * cifra exacta, la produccion/abastecimiento y las senales de otras ventas.
 */
class LimiteInventarioCliente
{
    public const TOPE = 10;

    /** Claves que nunca salen al cliente (internas o de otras ventas/asesores). */
    protected const PROHIBIDAS = [
        'abastecimiento', 'produccion', 'por_fabricar', 'porFabricar', 'enProduccion', 'en_produccion', 'senal',
        'exceso_carrito_sobre_stock', 'excesoCarritoSobreStock', 'disponibleInmediato', 'faltanteInmediato',
        'lineas_afectadas', 'detalles', 'propietario_admin_id', 'propietarioAdminId', 'creadoPorAdminId',
        'grupo', 'grupo_id', 'grupo_nombre', 'grupoNombre', 'grupo_override',
    ];

    public static function disponibilidad(int $stock): array
    {
        if ($stock <= 0) {
            return ['nivel' => 'agotado', 'texto' => 'Agotado', 'cantidad' => 0];
        }

        if ($stock <= self::TOPE) {
            return ['nivel' => 'exacto', 'texto' => $stock.' disponible'.($stock === 1 ? '' : 's'), 'cantidad' => $stock];
        }

        return ['nivel' => 'mas_de_10', 'texto' => 'Más de '.self::TOPE.' disponibles', 'cantidad' => self::TOPE + 1];
    }

    public static function sanear(mixed $dato): mixed
    {
        if (! is_array($dato)) {
            return $dato;
        }

        $salida = [];

        foreach ($dato as $clave => $valor) {
            if (is_string($clave) && in_array($clave, self::PROHIBIDAS, true)) {
                continue;
            }

            if (is_string($clave) && in_array($clave, ['stockVendible', 'stock_vendible'], true)) {
                $d = self::disponibilidad((int) $valor);
                $salida['stockVendible'] = $d['cantidad'];
                $salida['disponibilidad'] = $d;

                continue;
            }

            $salida[$clave] = self::sanear($valor);
        }

        return $salida;
    }
}
