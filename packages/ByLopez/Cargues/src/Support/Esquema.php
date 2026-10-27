<?php

namespace ByLopez\Cargues\Support;

/**
 * Lista CERRADA de tipos de campo del map y calculo de bytes (07 §4.2, §5.6). Todo lo que acaba en la DDL sale de aqui:
 * nunca de texto libre del JSON.
 */
final class Esquema
{
    public const TIPOS = ['texto', 'texto_largo', 'identificador', 'entero', 'decimal', 'fecha', 'fecha_hora', 'booleano'];

    /** Tipos que pueden formar parte de una llave primaria o unica: la comparacion de la BD coincide con la de PHP. */
    public const TIPOS_LLAVE_UNICA = ['identificador', 'entero', 'decimal', 'fecha', 'fecha_hora', 'booleano'];

    /** Tipos indexables (no unicos). */
    public const TIPOS_INDEXABLES = ['texto', 'identificador', 'entero', 'decimal', 'fecha', 'fecha_hora', 'booleano'];

    public const MAX_CLAVE_BYTES = 3072;

    public const MAX_FILA_BYTES = 65535;

    /** Bytes que las columnas de sistema aportan a la fila (varchar de 4 bytes por caracter incluidos). */
    public const FILA_SISTEMA_BYTES = 3500;

    /** Columnas de sistema: nombre => descripcion corta. El prefijo `cg_` esta reservado. */
    public const COLUMNAS_SISTEMA = [
        'cg_id', 'cg_carga_id', 'cg_carga_dato_id', 'cg_fila_origen', 'cg_duplicado', 'cg_ausentes',
        'cg_estado', 'cg_detalle', 'cg_codigo', 'cg_mensaje', 'cg_procesado_en',
    ];

    /** Datos de ENTRADA (inmutables desde el token de ejecucion) frente a MARCAS de resultado (07 §5.2). */
    public const MARCAS = ['cg_estado', 'cg_detalle', 'cg_codigo', 'cg_mensaje', 'cg_procesado_en'];

    /**
     * Columnas de datos de la intermedia: los campos que se relacionan con columnas del archivo y los DERIVADOS (los calcula la
     * extension del tipo al preparar la carga; no se relacionan ni se ven en el mapeo). Sin derivados, es `campos`.
     *
     * @return list<array<string,mixed>>
     */
    public static function columnas(array $map): array
    {
        return array_values(array_merge((array) ($map['campos'] ?? []), (array) ($map['derivados'] ?? [])));
    }

    /** Bytes que ocupa un campo dentro de una CLAVE de indice. */
    public static function bytesClave(array $campo): int
    {
        return match ($campo['tipo']) {
            'texto', 'identificador' => 4 * (int) $campo['longitud'],
            'entero' => 8,
            'decimal' => (int) (intdiv((int) $campo['precision'] - (int) $campo['escala'], 9) * 4 + 4 + intdiv((int) $campo['escala'], 9) * 4 + 4),
            'fecha' => 3,
            'fecha_hora' => 8,
            'booleano' => 1,
            default => 0,
        };
    }

    /** Bytes que ocupa un campo dentro de la FILA. */
    public static function bytesFila(array $campo): int
    {
        return match ($campo['tipo']) {
            'texto', 'identificador' => 4 * (int) $campo['longitud'] + 2,
            'texto_largo' => 12,
            default => self::bytesClave($campo),
        };
    }

    public static function normalizarCampo(array $campo): array
    {
        $campo['etiqueta'] = $campo['etiqueta'] ?? $campo['nombre'];
        $campo['nulo'] = (bool) ($campo['nulo'] ?? true);
        $campo['sinonimos'] = array_values($campo['sinonimos'] ?? []);

        return $campo;
    }

    /** ¿El campo es obligatorio para esta accion? `nulo:false` lo es siempre. */
    public static function esObligatorio(array $campo, ?string $accion): bool
    {
        if (($campo['nulo'] ?? true) === false) {
            return true;
        }

        $o = $campo['obligatorio'] ?? false;

        return $o === true || (is_array($o) && $accion !== null && in_array($accion, $o, true));
    }

    /** ¿El campo es obligatorio en alguna accion? (para la regla `vacio`). */
    public static function esObligatorioEnAlguna(array $campo): bool
    {
        $o = $campo['obligatorio'] ?? false;

        return ($campo['nulo'] ?? true) === false || $o === true || (is_array($o) && $o !== []);
    }
}
