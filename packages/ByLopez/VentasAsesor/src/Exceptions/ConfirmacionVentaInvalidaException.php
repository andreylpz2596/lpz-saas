<?php

namespace ByLopez\VentasAsesor\Exceptions;

use RuntimeException;

/**
 * Confirmacion de un borrador rechazada (03F). Codigos estables para POS, API, n8n,
 * WhatsApp y voz. Estado inexistente, propiedad y permiso siguen usando
 * BorradorVentaInvalidoException (misma convencion que 03C, 03D y 03E).
 *
 * «Ya confirmado» NO es un error: es un exito idempotente (ConfirmacionVenta::yaExistia).
 *
 * Todo rechazo ANTES del pedido devuelve el borrador a `activo` con un UPDATE condicionado
 * por el token del intento; ninguno reduce cantidades, inventa stock ni cambia precios.
 */
class ConfirmacionVentaInvalidaException extends RuntimeException
{
    /** Descartado, o un requisito que no encaja en otro codigo (detalles.motivo). */
    public const BORRADOR_NO_CONFIRMABLE = 'borrador_no_confirmable';

    /** Otro intento tiene el lease vigente. Reintentar mas tarde, nunca en paralelo. */
    public const CONFIRMACION_EN_PROCESO = 'confirmacion_en_proceso';

    /** El token de este intento ya no es el vigente (otro intento recupero la confirmacion). */
    public const CONFIRMACION_OBSOLETA = 'confirmacion_obsoleta';

    /** Evidencia contradictoria (detalles.motivo). Exige revision humana; nada se repara solo. */
    public const CONFIRMACION_INCONSISTENTE = 'confirmacion_inconsistente';

    /** Cliente inexistente, inactivo, suspendido o sin grupo comercial apto. */
    public const CLIENTE_NO_CONFIRMABLE = 'cliente_no_confirmable';

    public const CARRITO_VACIO = 'carrito_vacio';

    /** Carrito alterado fuera del modulo o que no cumple las invariantes (detalles.motivo). */
    public const CARRITO_INCONSISTENTE = 'carrito_inconsistente';

    /** Producto o variante inactivo: validateItems() lo quitaria en silencio; aqui se rechaza. */
    public const VARIANTE_NO_VENDIBLE = 'variante_no_vendible';

    /** detalles.fase = 'revalidacion' (antes del pedido) o 'cerco' (bajo bloqueo, D-54). */
    public const STOCK_INSUFICIENTE = 'stock_insuficiente';

    public const ENTREGA_INVALIDA = 'entrega_invalida';

    public const PAGO_INVALIDO = 'pago_invalido';

    /** El total recalculado por Bagisto no es el total_esperado (detalles.total_nuevo). */
    public const TOTALES_CAMBIARON = 'totales_cambiaron';

    /** G-11: tipo de operacion desconocido (solo venta_normal, muestra, reposicion, garantia). */
    public const TIPO_OPERACION_INVALIDO = 'tipo_operacion_invalido';

    /** G-11: muestra / reposicion / garantia sin `ventas_asesor.pedidos.tipo_operacion` (el asesor solo venta normal). */
    public const TIPO_OPERACION_SIN_PERMISO = 'tipo_operacion_sin_permiso';

    /** R2 (Doc. 23): con el bloqueo activo, una referencia no alcanza su minimo o falta configuracion. detalles.referencias (sin costos). */
    public const RENTABILIDAD_INSUFICIENTE = 'rentabilidad_insuficiente';

    /** R2: «Vender para» sin `ventas_asesor.pedidos.assign`, o asesor elegido invalido. */
    public const PROPIEDAD_COMERCIAL_INVALIDA = 'propiedad_comercial_invalida';

    /*
     * Cupo / limite de cartera (Doc. 35). Los decide el cerco bajo bloqueo (detalles.fase = 'cerco') y se traducen con el mismo codigo.
     * `detalles` lleva las cifras: la capa HTTP las quita si el actor no puede ver el cupo.
     */
    public const CUPO_EXCEDIDO = 'cupo_excedido';

    public const CUPO_BLOQUEADO = 'cupo_bloqueado';

    public const CUPO_SIN_ASIGNAR = 'cupo_sin_asignar';

    public const CUPO_NO_VERIFICABLE = 'cupo_no_verificable';

    /** Codigos de rechazo por cupo (para traduccion y presentacion). */
    public const CODIGOS_CUPO = [self::CUPO_EXCEDIDO, self::CUPO_BLOQUEADO, self::CUPO_SIN_ASIGNAR, self::CUPO_NO_VERIFICABLE];

    /** @param  array<string, mixed>  $detalles */
    public function __construct(
        public readonly string $codigo,
        public readonly array $detalles = [],
        ?\Throwable $previa = null,
    ) {
        parent::__construct('Confirmacion de venta no valida: '.$codigo, 0, $previa);
    }
}
