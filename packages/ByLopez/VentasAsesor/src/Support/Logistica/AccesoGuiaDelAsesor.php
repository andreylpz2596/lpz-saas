<?php

namespace ByLopez\VentasAsesor\Support\Logistica;

use ByLopez\Logistica\Contracts\AccesoGuiaPedido;
use ByLopez\Logistica\Support\AutorizacionLogistica;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use Illuminate\Support\Facades\DB;
use Webkul\User\Contracts\Admin;

/**
 * Quien puede ver / descargar la guia (PDF) de un pedido:
 *  - quien tenga `logistica.guias.ver` (BODEGA, administracion): cualquier pedido;
 *  - el ASESOR: solo los pedidos que le pertenecen segun la atribucion comercial (`view_own`), o todos con `view_all`.
 *
 * El asesor solo LEE: no puede adjuntar, reemplazar ni borrar (eso exige `logistica.guias.archivo`, que no tiene).
 * Un pedido sin atribucion valida no es de ningun asesor: `view_own` no lo ve (mismo criterio que «Mis ventas»).
 */
class AccesoGuiaDelAsesor implements AccesoGuiaPedido
{
    public function __construct(
        protected AutorizacionLogistica $logistica,
        protected AutorizacionVentasAsesor $ventas,
    ) {}

    public function puedeVer(?Admin $actor, int $orderId): bool
    {
        if ($this->logistica->tienePermiso($actor, AutorizacionLogistica::PERMISO_GUIAS_VER)) {
            return true;
        }

        if ($actor === null || ! $this->ventas->tienePermiso($actor, AutorizacionVentasAsesor::PERMISO_MODULO)) {
            return false;
        }

        $asesor = DB::table('bylopez_va_order_attributions')->where('order_id', $orderId)->value('advisor_id');

        return $this->ventas->puedeVerRegistroDe($actor, $asesor === null ? null : (int) $asesor);
    }
}
