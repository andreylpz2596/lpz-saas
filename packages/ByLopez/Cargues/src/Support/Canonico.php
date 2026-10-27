<?php

namespace ByLopez\Cargues\Support;

/** JSON canonico (claves ordenadas, sin escapes de unicode) y huellas estables. */
final class Canonico
{
    public static function json(mixed $valor): string
    {
        return json_encode(self::ordenar($valor), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    public static function huella(mixed $valor): string
    {
        return hash('sha256', self::json($valor));
    }

    private static function ordenar(mixed $v): mixed
    {
        if (! is_array($v)) {
            return $v;
        }

        if (array_is_list($v)) {
            return array_map(fn ($x) => self::ordenar($x), $v);
        }

        ksort($v);

        return array_map(fn ($x) => self::ordenar($x), $v);
    }
}
