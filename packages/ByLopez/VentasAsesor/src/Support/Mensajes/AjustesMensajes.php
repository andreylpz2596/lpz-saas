<?php

namespace ByLopez\VentasAsesor\Support\Mensajes;

use ByLopez\VentasAsesor\Support\Cotizaciones\AjustesCotizacion;

/**
 * Lo que el administrador edita en Configuracion > Ventas Asesor > Mensajes de WhatsApp (Config/system.php). V1 es minimo a
 * proposito: las plantillas son codigo; solo se ajustan los emojis y la frase final. El NOMBRE COMERCIAL no se repite aqui: es el
 * mismo de las cotizaciones (AjustesCotizacion::encabezado), una sola fuente.
 */
class AjustesMensajes
{
    private const BASE = 'ventas_asesor.mensajes.general.';

    public function __construct(protected AjustesCotizacion $cotizacion) {}

    public function marca(): string
    {
        return $this->cotizacion->encabezado()['nombre'];
    }

    public function usarEmojis(): bool
    {
        $v = core()->getConfigData(self::BASE.'usar_emojis');

        // Sin fila guardada rige el valor por defecto del formulario (activo); «0» / «» apagan.
        return $v === null ? true : filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }

    /** Frase final propia; null = la de cada plantilla. Admite {marca}. */
    public function fraseFinal(): ?string
    {
        $v = core()->getConfigData(self::BASE.'frase_final');
        $v = is_scalar($v) ? trim((string) $v) : '';

        return $v === '' ? null : str_replace('{marca}', $this->marca(), $v);
    }
}
