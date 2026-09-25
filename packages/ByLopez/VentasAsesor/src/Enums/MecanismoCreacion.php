<?php

namespace ByLopez\VentasAsesor\Enums;

/**
 * Con que mecanismo se registro el pedido [M-§6.2].
 *
 * DESCONOCIDO se agrega en la Fase 02A para pedidos historicos sin evidencia.
 */
enum MecanismoCreacion: string
{
    case STOREFRONT = 'storefront';

    case ADMIN_BAGISTO = 'admin_bagisto';

    case VENTAS_ASESOR = 'ventas_asesor';

    case POS = 'pos';

    case API = 'api';

    case N8N = 'n8n';

    case IMPORTACION_EXCEL = 'importacion_excel';

    case DESCONOCIDO = 'desconocido';

    /** Mecanismos donde un administrador autenticado ejecuta la creacion. */
    public function exigeCreadorAdministrativo(): bool
    {
        return in_array($this, [self::ADMIN_BAGISTO, self::VENTAS_ASESOR, self::POS], true);
    }

    /** Mecanismos donde nunca hay administrador autenticado. */
    public function prohibeCreadorAdministrativo(): bool
    {
        return $this === self::STOREFRONT;
    }
}
