<?php

namespace ByLopez\Logistica\Support;

use Webkul\User\Contracts\Admin;

/**
 * Punto unico de autorizacion de Logistica (bloque 13). Mismo criterio que VentasAsesor (falla cerrado), sin depender
 * de ese paquete: 'all' concede todo; 'custom' solo con la clave EXACTA en una lista de cadenas; cualquier otro caso
 * niega. No se delega en Admin::hasPermission() (concede con tipos desconocidos y compara sin estricto).
 *
 * Los servicios validan SIEMPRE: ocultar botones no autoriza nada.
 */
class AutorizacionLogistica
{
    public const PERMISO_MODULO = 'logistica';

    /** Ver la bandeja de entregas y el detalle de un envio. */
    public const PERMISO_ENTREGAS = 'logistica.entregas';

    /** Registrar entregas y retornos de transportadora (BODEGA, ADMIN / SUPERVISION; nunca el asesor). */
    public const PERMISO_REGISTRAR = 'logistica.entregas.registrar';

    /** Recibir fisicamente una devolucion en bodega y confirmar la recepcion (BODEGA, ADMIN). El reingreso de las aptas es parte de la misma operacion. */
    public const PERMISO_RECIBIR_DEVOLUCION = 'logistica.entregas.recibir_devolucion';

    /** Validar la entrega de un envio (ADMIN / SUPERVISION): habilita la comision del asesor y el cierre del pedido. */
    public const PERMISO_VALIDAR = 'logistica.entregas.validar';

    /** Anular por correccion entregas y retornos (ADMIN / SUPERVISION). */
    public const PERMISO_ANULAR = 'logistica.entregas.anular';

    /** Alias claro para quien registra entregas (BODEGA): lo exige tambien «Entregar en bodega». */
    public const PERMISO_ENTREGAS_REGISTRAR = self::PERMISO_REGISTRAR;

    /** Despacho V1: ver la bandeja «Despachos» y el contexto/detalle de un envio. */
    public const PERMISO_DESPACHOS = 'logistica.despachos';

    /** Registrar guia y despachar, completar un despacho parcial y «Entregar en bodega». */
    public const PERMISO_DESPACHAR = 'logistica.despachos.despachar';

    /** F6: despachar a credito (sin cobro) un pedido con dinero sin confirmar. Ningun rol la trae por defecto. */
    public const PERMISO_AUTORIZAR_CREDITO = 'logistica.despachos.autorizar_credito';

    /** Cancelar lo PENDIENTE de despacho de un pedido (libera la reserva; no toca el stock fisico). Autoridad administrativa. */
    public const PERMISO_CANCELAR_PENDIENTE = 'logistica.despachos.cancelar_pendiente';

    public const PERMISO_GUIAS = 'logistica.guias';

    /** Ver y descargar el PDF de cualquier guia (el asesor lo hace por sus pedidos, via AccesoGuiaPedido). */
    public const PERMISO_GUIAS_VER = 'logistica.guias.ver';

    /** Adjuntar el primer PDF o reemplazarlo (con motivo, auditado). */
    public const PERMISO_GUIAS_ARCHIVO = 'logistica.guias.archivo';

    /** Corregir el numero de guia / la transportadora de una guia ya registrada (auditado, con motivo). */
    public const PERMISO_GUIAS_CORREGIR = 'logistica.guias.corregir';

    /** Ver las incidencias de stock (la pestaña de Despachos). */
    public const PERMISO_STOCK = 'logistica.stock';

    /** BODEGA: solicitar autorizacion cuando el sistema muestra menos stock del que hay fisicamente. Nunca corrige inventario. */
    public const PERMISO_STOCK_SOLICITAR = 'logistica.stock.solicitar';

    /** ADMINISTRADOR: revisar, regularizar (subir el stock al conteo fisico) o rechazar; tambien en una sola operacion. */
    public const PERMISO_STOCK_REGULARIZAR = 'logistica.stock.regularizar';

    public const PERMISO_TRANSPORTADORAS = 'logistica.transportadoras';

    public const PERMISO_TRANSPORTADORAS_GESTIONAR = 'logistica.transportadoras.manage';

    /** Zonas locales por origen: la configuracion de que destinos son LOCALES para cada bodega. */
    public const PERMISO_ZONAS_LOCALES = 'logistica.zonas_locales';

    /** Cuentas de transportadoras: ver saldos, movimientos, el cuadre y el historico (BODEGA y ADMIN). */
    public const PERMISO_CUENTAS = 'logistica.cuentas';

    /** Cerrar una liquidacion (ADMIN). Queda asociada al usuario que la confirma. */
    public const PERMISO_CUENTAS_LIQUIDAR = 'logistica.cuentas.liquidar';

    /** Registrar ajustes, costos de devolucion y resolver envios «Requiere revision» (ADMIN). Nunca se edita un saldo. */
    public const PERMISO_CUENTAS_AJUSTAR = 'logistica.cuentas.ajustar';

    public function tienePermiso(?Admin $admin, string $permiso): bool
    {
        $rol = $admin?->role;

        if (! $rol) {
            return false;
        }

        if ($rol->permission_type === 'all') {
            return true;
        }

        if ($rol->permission_type !== 'custom') {
            return false;
        }

        $permisos = $rol->permissions;

        if (! is_array($permisos) || count(array_filter($permisos, 'is_string')) !== count($permisos)) {
            return false;
        }

        return in_array($permiso, $permisos, true);
    }
}
