<?php

namespace ByLopez\Cargues\Support;

/**
 * Estados de una carga (T5.estado) y de una fila (T6.estado_final). E1 llega a `validada`; E2 ejecuta (07 §8, 09 §2.2).
 */
final class Estados
{
    // Carga
    public const RECIBIDA = 'recibida';

    public const EN_COLA = 'en_cola';

    public const LEYENDO = 'leyendo';

    public const PREPARANDO = 'preparando';

    public const VALIDANDO = 'validando';

    public const VALIDADA = 'validada';

    public const FALLIDA_LECTURA = 'fallida_lectura';

    public const FALLIDA_PREPARACION = 'fallida_preparacion';

    public const FALLIDA_VALIDACION = 'fallida_validacion';

    public const CANCELADA = 'cancelada';

    // Ejecucion (E2)
    /** Ejecucion pedida y encolada (CAS desde `validada` o `fallida_ejecucion`); el procedimiento aun no corre. */
    public const LISTA = 'lista';

    /** CALL EJECUTAR en curso en el servidor del tipo (bloqueo con nombre tomado). */
    public const EJECUTANDO = 'ejecutando';

    /** Efectos confirmados en el servidor del tipo; falta reflejar las marcas en T6/T7. */
    public const CONSOLIDANDO = 'consolidando';

    public const COMPLETADA = 'completada';

    /** La ejecucion NO aplico nada (no empezo o se deshizo): se puede ejecutar de nuevo. */
    public const FALLIDA_EJECUCION = 'fallida_ejecucion';

    public const EN_EJECUCION = [self::LISTA, self::EJECUTANDO, self::CONSOLIDANDO];

    /** Estados en los que un job puede estar trabajando (se reclaman con CAS y vigilan por "sin noticias"). */
    public const EN_CURSO = [self::LEYENDO, self::PREPARANDO, self::VALIDANDO];

    /** Estados de los que se puede relanzar el procesamiento (E1: antes de cualquier ejecucion). */
    public const REINTENTABLES = [self::RECIBIDA, self::FALLIDA_LECTURA, self::FALLIDA_PREPARACION, self::FALLIDA_VALIDACION, self::VALIDADA];

    // Fila
    public const FILA_PENDIENTE = 'pendiente';

    public const FILA_VACIA = 'vacia';

    public const FILA_RECHAZADA = 'rechazada';

    public const FILA_APLICADA = 'aplicada';

    public const FILA_SIN_CAMBIO = 'sin_cambio';

    public const FILA_OMITIDA_ANTIGUA = 'omitida_antigua';

    /** Fila valida que la ejecucion no alcanzo (no deberia ocurrir en el modelo `motor`: se registra si pasa). */
    public const FILA_NO_EJECUTADA = 'no_ejecutada';

    /** Marcas que el procedimiento puede escribir en EJECUTAR (07 §8.2). */
    public const MARCAS_EJECUCION = [self::FILA_APLICADA, self::FILA_SIN_CAMBIO, self::FILA_OMITIDA_ANTIGUA, self::FILA_RECHAZADA];

    // Etapas de incidencia / de progreso
    public const ETAPA_LECTURA = 'lectura';

    public const ETAPA_VALIDACION_MOTOR = 'validacion_motor';

    public const ETAPA_VALIDACION_PROCEDIMIENTO = 'validacion_procedimiento';

    public const ETAPA_EJECUCION = 'ejecucion';

    public const POLITICAS = ['bloquear_todo', 'procesar_validas'];
}
