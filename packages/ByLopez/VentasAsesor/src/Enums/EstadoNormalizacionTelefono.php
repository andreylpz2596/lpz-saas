<?php

namespace ByLopez\VentasAsesor\Enums;

/**
 * Resultado de NORMALIZAR un telefono (RT-04B1a).
 *
 * Normalizar no es validar: NINGUN estado afirma que el numero exista, este asignado
 * o pertenezca a quien lo dio. Solo describe si su forma permite escribirlo en E.164
 * sin adivinar. ESTRUCTURA_RECONOCIDA e INTERNACIONAL_NO_VERIFICADO producen un E.164
 * utilizable para buscar o indexar.
 */
enum EstadoNormalizacionTelefono: string
{
    /** null, cadena vacia o solo espacios. */
    case VACIO = 'vacio';

    /**
     * Prefijo y longitud coinciden con el plan de numeracion de un pais con reglas
     * propias (hoy solo CO). No prueba que el numero exista ni que este asignado.
     */
    case ESTRUCTURA_RECONOCIDA = 'estructura_reconocida';

    /**
     * Con '+' y un codigo de pais sin reglas propias: solo se comprobo la forma E.164
     * ('+' y 8 a 15 digitos). Ni el pais, ni el plan de numeracion, ni el tipo de linea.
     */
    case INTERNACIONAL_NO_VERIFICADO = 'internacional_no_verificado';

    /** Forma plausible pero sin informacion suficiente (p. ej. fijo sin indicativo): no se adivina. */
    case AMBIGUO = 'ambiguo';

    /** Caracteres no permitidos o una estructura imposible para las reglas conocidas. */
    case INVALIDO = 'invalido';

    public function esBuscable(): bool
    {
        return $this === self::ESTRUCTURA_RECONOCIDA || $this === self::INTERNACIONAL_NO_VERIFICADO;
    }
}
