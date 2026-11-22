<?php

namespace ByLopez\Logistica\Exceptions;

use RuntimeException;

/**
 * Operacion de entrega, retorno o catalogo rechazada (LOG-01). `codigo` es estable para pruebas, UI e
 * integraciones futuras; `detalles` lleva el contexto (item, pendiente, etc.).
 */
class OperacionLogisticaInvalidaException extends RuntimeException
{
    public const SIN_PERMISO = 'sin_permiso';

    public const ENVIO_INEXISTENTE = 'envio_inexistente';

    public const ENVIO_DE_OTRO_PEDIDO = 'envio_de_otro_pedido';

    public const SIN_UNIDADES = 'sin_unidades';

    public const ITEM_NO_DESPACHADO = 'item_no_despachado';

    public const CANTIDAD_INVALIDA = 'cantidad_invalida';

    public const CANTIDAD_EXCEDE_PENDIENTE = 'cantidad_excede_pendiente';

    public const FECHA_INVALIDA = 'fecha_invalida';

    public const FECHA_ANTERIOR_AL_DESPACHO = 'fecha_anterior_al_despacho';

    public const FUENTE_INVALIDA = 'fuente_invalida';

    public const TRANSPORTADORA_REQUERIDA = 'transportadora_requerida';

    public const TRANSPORTADORA_INVALIDA = 'transportadora_invalida';

    public const MOTIVO_REQUERIDO = 'motivo_requerido';

    public const IDEMPOTENCIA_INVALIDA = 'idempotencia_invalida';

    /** La misma clave llego con OTRA solicitud: nunca se reinterpreta. */
    public const IDEMPOTENCIA_CONFLICTO = 'idempotencia_conflicto';

    public const REGISTRO_INEXISTENTE = 'registro_inexistente';

    public const YA_ANULADO = 'ya_anulado';

    public const REGISTRO_INMUTABLE = 'registro_inmutable';

    public const CODIGO_INVALIDO = 'codigo_invalido';

    public const CODIGO_EN_USO = 'codigo_en_uso';

    public const DATOS_INVALIDOS = 'datos_invalidos';

    // ---- Despacho V1 (guia, flete, recaudo, archivo) ----

    public const PEDIDO_INEXISTENTE = 'pedido_inexistente';

    public const PEDIDO_NO_DESPACHABLE = 'pedido_no_despachable';

    public const ES_RECOGIDA = 'es_recogida';

    public const NO_ES_RECOGIDA = 'no_es_recogida';

    public const LINEA_NO_DEL_PEDIDO = 'linea_no_del_pedido';

    public const STOCK_INSUFICIENTE = 'stock_insuficiente';

    public const GUIA_REQUERIDA = 'guia_requerida';

    public const GUIA_DUPLICADA = 'guia_duplicada';

    public const ORIGEN_GUIA_INVALIDO = 'origen_guia_invalido';

    public const PAGADOR_FLETE_INVALIDO = 'pagador_flete_invalido';

    public const FLETE_INVALIDO = 'flete_invalido';

    public const FLETE_REQUERIDO = 'flete_requerido';

    public const RECAUDO_INVALIDO = 'recaudo_invalido';

    /** C1: la suma de los recaudos de las guias del pedido superaria el saldo exigible (`maximo` = lo que aun se puede asignar). */
    public const RECAUDO_SUPERA_SALDO = 'recaudo_supera_saldo';

    /** C1: el recaudo de la guia difiere del que calculo el sistema (`sugerido`): motivo obligatorio. */
    public const MOTIVO_RECAUDO_REQUERIDO = 'motivo_recaudo_requerido';

    public const ARCHIVO_INVALIDO = 'archivo_invalido';

    public const ARCHIVO_NO_PDF = 'archivo_no_pdf';

    public const ARCHIVO_MUY_GRANDE = 'archivo_muy_grande';

    public const ARCHIVO_NO_RECIBIDO = 'archivo_no_recibido';

    public const GUIA_INEXISTENTE = 'guia_inexistente';

    public const GUIA_SIN_ARCHIVO = 'guia_sin_archivo';

    public const SIN_ACCESO_GUIA = 'sin_acceso_guia';

    public const DESPACHO_FALLIDO = 'despacho_fallido';

    /** PHP rechazo el archivo por el limite del SERVIDOR (upload_max_filesize / post_max_size), no por el maximo logico de 5 MB. */
    public const ARCHIVO_LIMITE_SERVIDOR = 'archivo_limite_servidor';

    // ---- Incidencias de stock (regularizacion para despachar) ----

    public const INCIDENCIA_INEXISTENTE = 'incidencia_inexistente';

    /** Ya hay una solicitud abierta para el pedido. */
    public const INCIDENCIA_ABIERTA = 'incidencia_abierta';

    public const INCIDENCIA_YA_RESUELTA = 'incidencia_ya_resuelta';

    /** Ninguna linea del pedido tiene faltante de stock: no hay nada que regularizar. */
    public const SIN_FALTANTE = 'sin_faltante';

    public const CONTEO_INVALIDO = 'conteo_invalido';

    /** El conteo declarado no supera el stock del sistema: no hay descuadre que corregir. */
    public const CONTEO_NO_SUPERA_STOCK = 'conteo_no_supera_stock';

    /** Regularizar solo SUBE el stock hasta el conteo fisico; bajarlo es otro control de inventario. */
    public const CONTEO_MENOR_AL_SISTEMA = 'conteo_menor_al_sistema';

    /** Todas las lineas coinciden con el sistema: se rechaza en vez de regularizar. */
    public const SIN_DIFERENCIA = 'sin_diferencia';

    public const CONFIRMACION_REQUERIDA = 'confirmacion_requerida';

    public const LINEA_NO_SOPORTADA = 'linea_no_soportada';

    public const AJUSTE_NO_AUTORIZADO = 'ajuste_no_autorizado';

    public const AJUSTE_FALLIDO = 'ajuste_fallido';

    /** Corregir guia: el numero y la transportadora nuevos son iguales a los vigentes. */
    public const SIN_CAMBIOS = 'sin_cambios';

    // Guia interna GI-000001.
    /** El numero GI lo asigna el sistema y no se cambia. */
    public const GUIA_INTERNA_INMUTABLE = 'guia_interna_inmutable';

    /** Corregir guia no convierte una guia interna en externa ni viceversa (la transportadora nueva debe usar el mismo tipo de guia). */
    public const GUIA_CAMBIO_DE_TIPO = 'guia_cambio_de_tipo';

    /** Los numeros GI-… los asigna solo el sistema: una guia externa no puede usarlos. */
    public const GUIA_RESERVADA = 'guia_reservada';

    // ---- Recepcion fisica de devoluciones + reingreso a inventario ----

    /** La devolucion esta anulada: no se puede recibir. */
    public const RETORNO_ANULADO = 'retorno_anulado';

    public const RECEPCION_YA_REGISTRADA = 'recepcion_ya_registrada';

    /** El Shipment no tiene una bodega determinable: no se adivina, no se toca el stock. */
    public const BODEGA_ORIGEN_INDETERMINADA = 'bodega_origen_indeterminada';

    public const RECEPCION_LINEAS_INCOMPLETAS = 'recepcion_lineas_incompletas';

    public const RECEPCION_CANTIDADES_INVALIDAS = 'recepcion_cantidades_invalidas';

    /** C5 · defectuosas: motivo / origen fuera del catalogo activo, cantidades que no suman la novedad, o «otro» sin descripcion. */
    public const DEFECTO_MOTIVO_INVALIDO = 'defecto_motivo_invalido';

    public const DEFECTO_ORIGEN_INVALIDO = 'defecto_origen_invalido';

    public const DEFECTO_DETALLE_REQUERIDO = 'defecto_detalle_requerido';

    public const DEFECTO_CANTIDADES_INVALIDAS = 'defecto_cantidades_invalidas';

    public const RECEPCION_MOTIVO_REQUERIDO = 'recepcion_motivo_requerido';

    public const RETORNO_CON_RECEPCION = 'retorno_con_recepcion';

    public const RECEPCION_YA_REINGRESADA = 'recepcion_ya_reingresada';

    /** D10: anular la recepcion dejaria credito ya aplicado/reembolsado sin respaldo. */
    public const RECEPCION_CON_CREDITO_CONSUMIDO = 'recepcion_con_credito_consumido';

    public const REINGRESO_FALLIDO = 'reingreso_fallido';

    // Validacion de entrega (Fase D).
    public const VALIDACION_YA_REGISTRADA = 'validacion_ya_registrada';

    public const VALIDACION_SIN_ENTREGAS = 'validacion_sin_entregas';

    public const VALIDACION_CON_PENDIENTES = 'validacion_con_pendientes';

    public const VALIDACION_DEVOLUCION_SIN_RECIBIR = 'validacion_devolucion_sin_recibir';

    /** Cartera rechazo el cobro (saldo, medio, referencia, permisos...): `causa` lleva su mensaje. */
    public const COBRO_RECHAZADO = 'cobro_rechazado';

    /** La entrega esta validada: sus registros no se anulan hasta anular la validacion (la comision depende de ella). */
    public const ENVIO_VALIDADO = 'envio_validado';

    // Domicilios locales F1.
    /** Un pago SIN CONFIRMAR es lo que deja el saldo en 0: no se despacha como pagado hasta que Cartera decida. */
    public const PAGO_PENDIENTE_CONFIRMAR = 'pago_pendiente_confirmar';

    /** El motivo de devolucion elegido no existe o esta inactivo en el catalogo. */
    public const MOTIVO_DEVOLUCION_INVALIDO = 'motivo_devolucion_invalido';

    // F6: pedido que no es contra entrega con dinero sin confirmar.
    /** Falta decidir como se cobra (esperar el pago, cobrar al entregar o credito autorizado), o la decision quedo vieja. */
    public const DISPOSICION_COBRO_REQUERIDA = 'disposicion_cobro_requerida';

    /** Tipo de decision de cobro desconocido. */
    public const DISPOSICION_COBRO_INVALIDA = 'disposicion_cobro_invalida';

    /** El pedido no necesita decision de cobro (contra entrega, pagado o fuera de Cartera). */
    public const DISPOSICION_NO_APLICA = 'disposicion_no_aplica';

    /** El credito autorizado exige un motivo. */
    public const MOTIVO_CREDITO_REQUERIDO = 'motivo_credito_requerido';

    // Cancelacion de lo pendiente de despacho.
    /** La cancelacion exige un motivo escrito. */
    public const MOTIVO_CANCELACION_REQUERIDO = 'motivo_cancelacion_requerido';

    /** El pedido no tiene unidades pendientes de despachar (ya se cancelaron o ya se enviaron). */
    public const CANCELACION_SIN_PENDIENTE = 'cancelacion_sin_pendiente';

    /** El pedido quedaria totalmente cancelado con dinero ya aplicado: devolver ese dinero es una decision humana (D10). */
    public const CANCELACION_CON_PAGOS = 'cancelacion_con_pagos';

    /** La base no tiene la migracion del historial de cancelaciones. */
    public const CANCELACION_NO_DISPONIBLE = 'cancelacion_no_disponible';

    /** Metodo de envio desconocido: el pagador del flete no se deduce y hay que elegirlo. */
    public const PAGADOR_REQUERIDO = 'pagador_requerido';

    /** El pagador elegido no es el deducido de la condicion de envio (o no se pudo deducir): motivo obligatorio. */
    public const MOTIVO_PAGADOR_REQUERIDO = 'motivo_pagador_requerido';

    /** El costo final del domicilio se aparta de la tarifa sugerida: motivo obligatorio. */
    public const MOTIVO_COSTO_REQUERIDO = 'motivo_costo_requerido';

    /** Transportadora con cuenta corriente: el costo real del domicilio es obligatorio. */
    public const COSTO_REQUERIDO = 'costo_requerido';

    public const ZONA_INVALIDA = 'zona_invalida';

    public const TARIFA_DUPLICADA = 'tarifa_duplicada';

    // ---- Zonas locales y tarifas (pantalla unificada) ----

    public const ORIGEN_INVALIDO = 'origen_invalido';

    public const MUNICIPIO_INVALIDO = 'municipio_invalido';

    public const CIUDAD_YA_HABILITADA = 'ciudad_ya_habilitada';

    public const CIUDAD_NO_HABILITADA = 'ciudad_no_habilitada';

    /** El municipio ya pertenece a una zona con varios municipios: habilitarlo habilitaria tambien a los demas. */
    public const ZONA_MULTIMUNICIPIO = 'zona_multimunicipio';

    public const OPERADOR_NO_LOCAL = 'operador_no_local';

    public const TARIFA_VALOR_INVALIDO = 'tarifa_valor_invalido';

    public const TARIFA_FECHA_INVALIDA = 'tarifa_fecha_invalida';

    // ---- Cuentas corrientes de transportadoras y liquidaciones ----

    /** La transportadora no existe o no tiene (ni tuvo) cuenta corriente. */
    public const CUENTA_INEXISTENTE = 'cuenta_inexistente';

    public const LIQUIDACION_INEXISTENTE = 'liquidacion_inexistente';

    /** Una liquidacion cerrada no se edita ni se borra: una diferencia posterior es un AJUSTE. */
    public const LIQUIDACION_CERRADA = 'liquidacion_cerrada';

    public const SIN_MOVIMIENTOS = 'sin_movimientos';

    /** Algun movimiento revisado no existe, es de otra transportadora o la lista no es valida. */
    public const MOVIMIENTOS_INVALIDOS = 'movimientos_invalidos';

    /** Otro usuario ya liquido (o empezo a liquidar) alguno de esos movimientos. */
    public const MOVIMIENTOS_YA_LIQUIDADOS = 'movimientos_ya_liquidados';

    public const VALOR_INVALIDO = 'valor_invalido';

    /** El valor entregado no coincide con el saldo: la diferencia se traslada, pero con observacion obligatoria. */
    public const OBSERVACION_REQUERIDA = 'observacion_requerida';

    /** Un costo de devolucion o una revision exigen una guia de ESA transportadora. */
    public const GUIA_NO_DE_TRANSPORTADORA = 'guia_no_de_transportadora';

    /** El envio no esta (o ya no esta) en «Requiere revision» para ese concepto. */
    public const REVISION_NO_APLICA = 'revision_no_aplica';

    /** @param  array<string, mixed>  $detalles */
    public function __construct(public readonly string $codigo, public readonly array $detalles = [], ?string $mensaje = null)
    {
        parent::__construct($mensaje ?? 'Operacion logistica no valida: '.$codigo);
    }
}
