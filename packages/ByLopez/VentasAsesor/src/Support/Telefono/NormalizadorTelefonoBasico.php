<?php

namespace ByLopez\VentasAsesor\Support\Telefono;

use ByLopez\VentasAsesor\Contracts\NormalizadorTelefono;
use ByLopez\VentasAsesor\DataObjects\TelefonoNormalizado;
use ByLopez\VentasAsesor\Enums\EstadoNormalizacionTelefono as Estado;
use ByLopez\VentasAsesor\Enums\TipoTelefono as Tipo;

/**
 * Normalizador conservador a E.164, sin dependencias (RT-04B1a).
 *
 * No es un `preg_replace('/\D/', '')`: solo retira separadores de formato; cualquier
 * otro caracter (letras, "ext", "#") invalida el valor en vez de desaparecer. El '+'
 * y el prefijo internacional '00' se respetan, porque distinguen un numero
 * internacional de uno nacional.
 *
 * Reglas propias solo para Colombia (+57), segun el plan de numeracion vigente:
 * movil = 10 digitos que empiezan por 3; fijo = 10 digitos que empiezan por 60 + un
 * indicativo de region 1-8. Nunca se inventa indicativo, ciudad, pais ni extension:
 * un fijo de 7 u 8 digitos es AMBIGUO. Otros paises con '+' explicito se conservan
 * como INTERNACIONAL_NO_VERIFICADO; sin '+' y sin reglas para el pais, AMBIGUO.
 *
 * Normaliza, no valida: comprueba estructura (prefijo y longitud), nunca que el numero
 * exista o este asignado. Validar de verdad exigiria metadatos por pais
 * (libphonenumber), que esta fase no agrega.
 */
class NormalizadorTelefonoBasico implements NormalizadorTelefono
{
    /** Longitud maxima del numero E.164 sin el '+'. */
    private const MAX_DIGITOS_E164 = 15;

    /** Minimo razonable para aceptar un numero internacional explicito. */
    private const MIN_DIGITOS_INTERNACIONAL = 8;

    public function normalizar(?string $valor, ?string $paisPorDefecto = null): TelefonoNormalizado
    {
        $original = $valor === null ? null : trim($valor);

        if ($original === null || $original === '') {
            return $this->resultado($original, Estado::VACIO, 'vacio');
        }

        // Solo separadores de formato: espacios (incluido el no separable), punto, guion, parentesis y barra.
        $compacto = preg_replace('/[\s\x{00A0}.\-()\/]+/u', '', $original);

        if (! preg_match('/^\+?\d+$/', $compacto)) {
            return $this->resultado($original, Estado::INVALIDO, 'caracteres_no_permitidos');
        }

        if (str_starts_with($compacto, '00')) {
            $compacto = '+'.substr($compacto, 2);
        }

        if (str_starts_with($compacto, '+')) {
            return $this->internacional($original, substr($compacto, 1));
        }

        $pais = strtoupper($paisPorDefecto ?? (string) config('ventasasesor.telefono.pais_por_defecto', 'CO'));

        if ($pais !== 'CO') {
            return $this->resultado($original, Estado::AMBIGUO, 'pais_por_defecto_sin_reglas');
        }

        // 57 + numero nacional completo, escrito sin '+'.
        if (strlen($compacto) === 12 && str_starts_with($compacto, '57') && $this->tipoColombiano(substr($compacto, 2)) !== null) {
            return $this->colombiano($original, substr($compacto, 2));
        }

        return $this->colombiano($original, $compacto);
    }

    private function internacional(string $original, string $digitos): TelefonoNormalizado
    {
        $largo = strlen($digitos);

        if ($largo < self::MIN_DIGITOS_INTERNACIONAL || $largo > self::MAX_DIGITOS_E164) {
            return $this->resultado($original, Estado::INVALIDO, 'longitud_internacional');
        }

        if (str_starts_with($digitos, '57')) {
            return $this->colombiano($original, substr($digitos, 2));
        }

        return new TelefonoNormalizado($original, '+'.$digitos, null, Tipo::DESCONOCIDO, Estado::INTERNACIONAL_NO_VERIFICADO, 'pais_sin_reglas');
    }

    /** $nacional: el numero colombiano sin el 57. */
    private function colombiano(string $original, string $nacional): TelefonoNormalizado
    {
        $tipo = $this->tipoColombiano($nacional);

        if ($tipo !== null) {
            return new TelefonoNormalizado($original, '+57'.$nacional, 'CO', $tipo, Estado::ESTRUCTURA_RECONOCIDA);
        }

        // Fijo antiguo: 7 digitos sin indicativo, u 8 con el indicativo de una cifra.
        if (in_array(strlen($nacional), [7, 8], true)) {
            return $this->resultado($original, Estado::AMBIGUO, 'fijo_sin_indicativo_vigente');
        }

        return $this->resultado($original, Estado::INVALIDO, 'numeracion_colombiana_no_reconocida');
    }

    private function tipoColombiano(string $nacional): ?Tipo
    {
        if (preg_match('/^3\d{9}$/', $nacional)) {
            return Tipo::MOVIL;
        }

        if (preg_match('/^60[1-8]\d{7}$/', $nacional)) {
            return Tipo::FIJO;
        }

        return null;
    }

    private function resultado(?string $original, Estado $estado, string $motivo): TelefonoNormalizado
    {
        return new TelefonoNormalizado($original, null, null, Tipo::DESCONOCIDO, $estado, $motivo);
    }
}
