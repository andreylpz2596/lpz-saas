<?php

namespace ByLopez\Cargues\Services\Cargas;

use ByLopez\Cargues\Support\Esquema;
use DateTimeImmutable;

/**
 * Normalizacion y validacion POR CAMPO (P6). Trabaja sobre el original SIN normalizar (T6.datos_originales) y produce
 * una estructura SEPARADA (datos_normalizados): el original nunca se modifica.
 *
 * Distingue (07 §4.4):
 *  - columna SIN RELACIONAR: el campo no tiene columna en la comparacion -> ausente, se registra en `ausentes`;
 *  - celda VACIA: ausente, "" o solo espacios -> se aplica la regla `vacio` del campo (rechazar | nulo | ignorar);
 *  - NULL: nunca viene del archivo (el texto «NULL» es texto); solo lo produce la regla `nulo`;
 *  - DEFAULT: no existe en la intermedia (lo decide la BD del destino; E2 en el procedimiento).
 *
 * Nada se trunca ni se corrige en silencio: lo que no cabe o no se entiende se rechaza con motivo.
 */
class Normalizador
{
    public const VERDADERO = ['1', 'true', 'verdadero', 'si', 'sí', 's', 'x', 'yes', 'y'];

    public const FALSO = ['0', 'false', 'falso', 'no', 'n'];

    /**
     * @param  array  $originales  lista por posicion de columna (FilaLeida::valores)
     * @param  array<string, int|null>  $relaciones  campo => indice de columna (null = sin relacionar)
     * @return array{valores: array<string, mixed>, ausentes: array<string, string>, errores: array<int, array{campo:string, codigo:string, motivo:string, severidad:string}>}
     */
    public function normalizar(array $originales, array $relaciones, array $campos, ?string $accion): array
    {
        $valores = [];
        $ausentes = [];
        $errores = [];

        foreach ($campos as $campo) {
            $nombre = $campo['nombre'];
            $idx = $relaciones[$nombre] ?? null;

            if ($idx === null) {
                $valores[$nombre] = null;
                $ausentes[$nombre] = 'sin_relacionar';

                continue;
            }

            $crudo = $originales[$idx] ?? null;
            $texto = $this->texto($crudo);

            if ($texto === null || $texto === '') {
                $this->vacio($campo, $accion, $valores, $ausentes, $errores);

                continue;
            }

            [$v, $err, $adv] = $this->convertir($campo, $crudo, $texto);

            if ($err) {
                $valores[$nombre] = null;
                $errores[] = ['campo' => $nombre, 'codigo' => $err[0], 'motivo' => $err[1], 'severidad' => 'error'];

                continue;
            }

            if ($adv) {
                $errores[] = ['campo' => $nombre, 'codigo' => $adv[0], 'motivo' => $adv[1], 'severidad' => 'advertencia'];
            }

            $valores[$nombre] = $v;
        }

        return ['valores' => $valores, 'ausentes' => $ausentes, 'errores' => $errores];
    }

    protected function vacio(array $campo, ?string $accion, array &$valores, array &$ausentes, array &$errores): void
    {
        $nombre = $campo['nombre'];
        $valores[$nombre] = null;
        $regla = $campo['vacio'] ?? (Esquema::esObligatorioEnAlguna($campo) ? 'rechazar' : 'nulo');

        if (Esquema::esObligatorio($campo, $accion)) {
            $errores[] = ['campo' => $nombre, 'codigo' => 'OBLIGATORIO_VACIO', 'motivo' => "«{$campo['etiqueta']}» es obligatorio y viene vacío.", 'severidad' => 'error'];

            return;
        }

        match ($regla) {
            'rechazar' => $errores[] = ['campo' => $nombre, 'codigo' => 'VACIO_RECHAZADO', 'motivo' => "«{$campo['etiqueta']}» viene vacío y la configuración rechaza las celdas vacías.", 'severidad' => 'error'],
            'ignorar' => $ausentes[$nombre] = 'vacio_ignorar',
            default => null, // nulo: queda NULL (ya asignado)
        };
    }

    /**
     * Texto de un valor original para validarlo (null = ausente). Los numeros no se pasan por `(string)` con la
     * precision del php.ini: se usa la representacion mas corta que reconstruye el valor.
     */
    public function texto(mixed $v): ?string
    {
        return match (true) {
            $v === null => null,
            is_string($v) => $this->recortar($v),
            is_bool($v) => $v ? '1' : '0',
            is_int($v) => (string) $v,
            is_float($v) => $this->flotante($v),
            is_array($v) => $this->textoEspecial($v),
            default => $this->recortar((string) $v),
        };
    }

    protected function textoEspecial(array $v): ?string
    {
        return match (true) {
            isset($v['d']) => (string) $v['d'],
            isset($v['n']) => $this->texto($v['n']),
            isset($v['c']) => $this->texto($v['c']),
            default => null, // error de Excel ({e}) o formula sin cache: no hay valor utilizable
        };
    }

    protected function flotante(float $f): string
    {
        if (is_nan($f) || is_infinite($f)) {
            return '';
        }

        if ($f == floor($f) && abs($f) < 1e15) {
            return (string) (int) $f;
        }

        // Representacion MAS CORTA que reconstruye el valor (serialize_precision = -1): fiel a lo que guarda el archivo.
        $r = json_encode($f);

        return str_contains(strtolower((string) $r), 'e') ? rtrim(rtrim(sprintf('%.20F', $f), '0'), '.') : (string) $r;
    }

    protected function recortar(string $s): string
    {
        return preg_replace('/^[\s\x{00A0}\x{FEFF}]+|[\s\x{00A0}\x{FEFF}]+$/u', '', $s) ?? $s;
    }

    /** @return array{0: mixed, 1: ?array{0:string,1:string}, 2: ?array{0:string,1:string}} */
    protected function convertir(array $campo, mixed $crudo, string $t): array
    {
        $e = $campo['etiqueta'];

        return match ($campo['tipo']) {
            'texto', 'texto_largo' => $this->cadena($campo, $t, $e),
            'identificador' => $this->identificador($campo, $crudo, $t, $e),
            'entero' => $this->entero($t, $e),
            'decimal' => $this->decimal($campo, $t, $e, $crudo),
            'fecha', 'fecha_hora' => $this->fecha($campo, $crudo, $t, $e),
            'booleano' => $this->booleano($campo, $t, $e),
        };
    }

    protected function cadena(array $campo, string $t, string $e): array
    {
        if ($campo['tipo'] === 'texto' && mb_strlen($t) > (int) $campo['longitud']) {
            return [null, ['LONGITUD_EXCEDIDA', "«{$e}» tiene ".mb_strlen($t)." caracteres y el máximo es {$campo['longitud']}."], null];
        }

        return [$t, null, null];
    }

    protected function identificador(array $campo, mixed $crudo, string $t, string $e): array
    {
        if (mb_strlen($t) > (int) $campo['longitud']) {
            return [null, ['LONGITUD_EXCEDIDA', "«{$e}» tiene ".mb_strlen($t)." caracteres y el máximo es {$campo['longitud']}."], null];
        }

        // Una celda numerica ya perdio sus ceros a la izquierda en el archivo: se acepta pero se advierte.
        $adv = (is_int($crudo) || is_float($crudo)) ? ['IDENTIFICADOR_NUMERICO', "«{$e}» llegó como número: si tenía ceros a la izquierda, el archivo ya los había perdido."] : null;

        return [$t, null, $adv];
    }

    protected function entero(string $t, string $e): array
    {
        if (! preg_match('/^[+-]?\d{1,19}$/', $t)) {
            return [null, ['ENTERO_INVALIDO', "«{$e}» no es un número entero."], null];
        }

        $n = ltrim(ltrim($t, '+'), '0') ?: '0';
        $neg = str_starts_with($n, '-');
        $abs = ltrim($neg ? substr($n, 1) : $n, '0') ?: '0';

        if (strlen($abs) > 19 || (strlen($abs) === 19 && strcmp($abs, $neg ? '9223372036854775808' : '9223372036854775807') > 0)) {
            return [null, ['ENTERO_FUERA_DE_RANGO', "«{$e}» no cabe en un entero de 64 bits."], null];
        }

        return [(int) $t, null, null];
    }

    protected function decimal(array $campo, string $t, string $e, mixed $crudo = null): array
    {
        $f = $campo['formato'] ?? [];
        // Un numero de Excel (int/float) no depende del formato regional configurado: siempre es «1234.5».
        $numerico = is_int($crudo) || is_float($crudo);
        $dec = $numerico ? '.' : ($f['decimal'] ?? '.');
        $miles = $numerico ? '' : ($f['miles'] ?? '');
        $invalido = [null, ['DECIMAL_INVALIDO', "«{$e}» no es un decimal con el formato configurado."], null];
        $partes = explode($dec, $t);

        if (count($partes) > 2) {
            return $invalido;
        }

        $entera = $partes[0];

        // El separador de miles solo vale entre grupos de 3 cifras: con «.» de miles, «1.5» NO es 15 ni 1,5.
        if ($miles !== '' && preg_match('/^[+-]?\d{1,3}(?:'.preg_quote($miles, '/').'\d{3})+$/', $entera)) {
            $entera = str_replace($miles, '', $entera);
        }

        if (! preg_match('/^([+-]?)(\d+)$/', $entera, $m) || (isset($partes[1]) && ! preg_match('/^\d+$/', $partes[1]))) {
            return $invalido;
        }

        $m[3] = $partes[1] ?? null;
        $escala = (int) $campo['escala'];
        $entera = ltrim($m[2], '0') ?: '0';
        $frac = $m[3] ?? '';

        if (strlen($frac) > $escala) {
            if (rtrim(substr($frac, $escala), '0') !== '') {
                return [null, ['DECIMAL_ESCALA_EXCEDIDA', "«{$e}» tiene más de {$escala} decimales."], null];
            }

            $frac = substr($frac, 0, $escala);
        }

        if (strlen($entera) > (int) $campo['precision'] - $escala) {
            return [null, ['DECIMAL_FUERA_DE_RANGO', "«{$e}» excede la precisión configurada."], null];
        }

        $frac = str_pad($frac, $escala, '0');
        $signo = ($m[1] === '-' && (trim($entera, '0') !== '' || trim($frac, '0') !== '')) ? '-' : '';

        return [$signo.$entera.($escala > 0 ? '.'.$frac : ''), null, null];
    }

    protected function fecha(array $campo, mixed $crudo, string $t, string $e): array
    {
        $conHora = $campo['tipo'] === 'fecha_hora';
        $salida = $conHora ? 'Y-m-d H:i:s' : 'Y-m-d';

        if (is_array($crudo) && isset($crudo['d'])) {
            return [substr((string) $crudo['d'], 0, $conHora ? 19 : 10), null, null];
        }

        if (is_int($crudo) || is_float($crudo)) {
            return [null, ['FECHA_INVALIDA', "«{$e}» es un número que no tiene formato de fecha en el archivo."], null];
        }

        $formatos = $campo['formato']['formatos'] ?? ($conHora ? ['Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d H:i'] : ['Y-m-d']);

        foreach ($formatos as $fmt) {
            $d = DateTimeImmutable::createFromFormat('!'.$fmt, $t);
            $err = DateTimeImmutable::getLastErrors();

            if ($d !== false && (! $err || ($err['warning_count'] === 0 && $err['error_count'] === 0))) {
                return [$d->format($salida), null, null];
            }
        }

        return [null, ['FECHA_INVALIDA', "«{$e}» no coincide con ningún formato de fecha configurado."], null];
    }

    protected function booleano(array $campo, string $t, string $e): array
    {
        $s = mb_strtolower($t);
        $ver = array_map('mb_strtolower', $campo['formato']['verdadero'] ?? self::VERDADERO);
        $fal = array_map('mb_strtolower', $campo['formato']['falso'] ?? self::FALSO);

        if (in_array($s, $ver, true)) {
            return [1, null, null];
        }

        if (in_array($s, $fal, true)) {
            return [0, null, null];
        }

        return [null, ['BOOLEANO_INVALIDO', "«{$e}» no es un valor verdadero/falso reconocido."], null];
    }
}
