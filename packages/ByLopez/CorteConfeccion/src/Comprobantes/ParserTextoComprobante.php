<?php

namespace ByLopez\CorteConfeccion\Comprobantes;

use ByLopez\CorteConfeccion\Support\Dinero;

/**
 * Convierte el TEXTO de un comprobante (salida de un OCR o texto de un PDF) en un borrador. Reconoce por ETIQUETA y contexto
 * (nunca por posicion en la imagen): un dato solo se toma si esta junto a una etiqueta conocida o en una seccion reconocible
 * («Origen», «Destino»). Lo que no aparece queda en null con una advertencia; no usa la fecha de hoy ni rellena medios.
 *
 * Para soportar un formato nuevo se agregan etiquetas a las listas de abajo (ver docs/LECTURA_COMPROBANTES_PAGOS.md); no hace
 * falta una plantilla por comprobante. Subir VERSION cuando cambien las reglas: las lecturas guardadas con otra version se
 * vuelven a procesar.
 */
class ParserTextoComprobante
{
    public const VERSION = '2';

    /** Etiquetas del importe transferido (se prueban en este orden). */
    protected const ETIQUETAS_VALOR = ['valor de venta', 'valor enviado', 'valor transferido', 'valor pagado', 'valor de la transferencia', 'cuanto', 'valor', 'monto', 'importe', 'total'];

    /** Identificador de la operacion. */
    protected const ETIQUETAS_REFERENCIA = ['referencia', 'numero de referencia', 'id de la transaccion', 'id de transaccion', 'id transaccion', 'numero de transaccion', 'no de transaccion', 'numero de comprobante', 'comprobante no', 'comprobante n', 'numero de operacion', 'codigo de transaccion', 'cus'];

    /** Codigo de autorizacion o aprobacion (puede venir ademas del ID). */
    protected const ETIQUETAS_AUTORIZACION = ['numero de aprobacion', 'no de aprobacion', 'codigo de aprobacion', 'cod autorizacion', 'codigo de autorizacion', 'numero de autorizacion', 'autorizacion', 'aprobacion'];

    protected const ETIQUETAS_DESTINATARIO = ['para', 'destinatario', 'beneficiario', 'nombre del destinatario', 'nombre del beneficiario', 'a nombre de'];

    protected const ETIQUETAS_REMITENTE = ['remitente', 'enviado por', 'ordenante'];

    protected const ETIQUETAS_BANCO_DESTINO = ['banco destino', 'entidad destino', 'banco del destinatario'];

    protected const ETIQUETAS_BANCO_ORIGEN = ['banco origen', 'entidad origen', 'desde'];

    /** Cualquier linea cuya etiqueta contenga una de estas palabras NUNCA es el importe transferido. */
    protected const NO_VALOR = ['saldo', 'comision', 'costo', 'cupo', 'limite', 'disponible', 'gmf', 'iva', 'cuenta', 'celular', 'telefono', 'llave'];

    /** Medios/entidades conocidos: texto a buscar (sin tildes, minusculas) => nombre mostrado. */
    protected const MEDIOS = [
        'nequi' => 'Nequi', 'daviplata' => 'DaviPlata', 'bancolombia' => 'Bancolombia', 'davivienda' => 'Davivienda',
        'bbva' => 'BBVA', 'banco de bogota' => 'Banco de Bogotá', 'banco popular' => 'Banco Popular', 'av villas' => 'AV Villas',
        'scotiabank' => 'Scotiabank Colpatria', 'colpatria' => 'Scotiabank Colpatria', 'banco agrario' => 'Banco Agrario',
        'bancoomeva' => 'Bancoomeva', 'itau' => 'Itaú', 'lulo bank' => 'Lulo Bank', 'nubank' => 'Nu', 'movii' => 'Movii',
    ];

    protected const MESES = [
        'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6, 'julio' => 7, 'agosto' => 8,
        'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12,
        'ene' => 1, 'feb' => 2, 'mar' => 3, 'abr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'ago' => 8, 'sep' => 9, 'sept' => 9, 'oct' => 10, 'nov' => 11, 'dic' => 12,
    ];

    public function parsear(string $texto): BorradorOperacion
    {
        $lineas = $this->lineas($texto);
        $adv = [];

        $secciones = $this->secciones($lineas);

        [$valor, $monedaVista, $variosImportes] = $this->valor($lineas);
        [$fecha, $hora] = $this->fechaHora($lineas);
        $autorizacion = $this->codigo($lineas, self::ETIQUETAS_AUTORIZACION);
        $referencia = $this->codigo($lineas, self::ETIQUETAS_REFERENCIA);
        $telefono = $this->telefono($lineas);

        // Un telefono o el mismo codigo de autorizacion nunca se toma como un ID distinto.
        if ($referencia !== null && $telefono !== null && preg_replace('/\D/', '', $referencia) === $telefono) {
            $referencia = null;
        }

        if ($referencia !== null && $autorizacion !== null && $this->alfanum($referencia) === $this->alfanum($autorizacion)) {
            $autorizacion = null;
        }

        $bancoOrigen = $this->banco($secciones['origen'], ['banco', 'entidad']) ?? $this->bancoDeDesde($lineas) ?? $this->bancoPorEtiqueta($lineas, self::ETIQUETAS_BANCO_ORIGEN);
        $bancoDestino = $this->banco($secciones['destino'], ['entidad', 'banco']) ?? $this->bancoPorEtiqueta($lineas, self::ETIQUETAS_BANCO_DESTINO);
        $remitente = $this->nombre($secciones['origen'], ['dueno', 'titular', 'nombre'], false) ?? $this->nombrePorEtiqueta($lineas, self::ETIQUETAS_REMITENTE);
        $destinatario = $this->nombre($secciones['destino'], ['dueno', 'titular', 'nombre'], false) ?? $this->nombrePorEtiqueta($lineas, self::ETIQUETAS_DESTINATARIO, true) ?? $this->destinatarioDaviplata($lineas);
        $medio = $bancoOrigen && $secciones['origen'] ? $bancoOrigen : $this->medio($this->sinEntidadDestino($lineas, $secciones));

        $valor === null && $adv[] = 'valor no legible';
        $fecha === null && $adv[] = 'fecha no legible';
        ($referencia === null && $autorizacion === null) && $adv[] = 'referencia ilegible';
        $medio === null && $adv[] = 'medio no identificado';
        $variosImportes && $adv[] = 'el comprobante muestra importes distintos: verifica cuál es el transferido';
        ($valor !== null && ! $monedaVista) && $adv[] = 'moneda no indicada (se asume COP)';

        return new BorradorOperacion(
            valor: $valor, fecha: $fecha, hora: $hora, bancoOrigen: $bancoOrigen, bancoDestino: $bancoDestino, medio: $medio,
            referencia: $referencia, autorizacion: $autorizacion, contraparte: $destinatario, remitente: $remitente, advertencias: $adv,
        );
    }

    // ------------------------------------------------------------------ texto

    /** @return list<string> */
    protected function lineas(string $texto): array
    {
        $texto = str_replace(["\r\n", "\r", "\f", "\u{00A0}"], ["\n", "\n", "\n", ' '], $texto);
        $lineas = [];

        foreach (explode("\n", $texto) as $l) {
            $l = trim(preg_replace('/\s+/u', ' ', $l) ?? '');

            if ($l !== '') {
                $lineas[] = $l;
            }
        }

        return $lineas;
    }

    protected function plano(string $s): string
    {
        $ascii = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);

        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]/', ' ', strtolower($ascii)) ?? '') ?? '');
    }

    protected function alfanum(string $s): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $s) ?? '');
    }

    /**
     * Candidatos que acompanan a una etiqueta, en orden de prioridad de etiqueta: primero lo que sigue en la MISMA linea
     * («Etiqueta: valor» o «Etiqueta valor») y despues la linea siguiente («Etiqueta» / «valor»).
     *
     * @param  list<string>  $lineas
     * @param  list<string>  $etiquetas
     * @return \Generator<int, string>
     */
    protected function candidatos(array $lineas, array $etiquetas, bool $exacta = false)
    {
        foreach ($etiquetas as $etiqueta) {
            $compacta = str_replace(' ', '', $etiqueta);
            $palabras = count(explode(' ', $etiqueta));

            foreach ($lineas as $i => $linea) {
                $completo = $this->plano($linea);

                if ($this->contieneNoValor($completo, $etiqueta)) {
                    continue;
                }

                $igual = fn (string $r) => $r === $etiqueta || str_replace(' ', '', $r) === $compacta;

                // A) «Etiqueta: valor»  (lo que va ANTES de los dos puntos es exactamente la etiqueta)
                if (preg_match('/^([^:]{1,40}):\s*(\S.*)$/u', $linea, $previo) === 1 && $igual($this->plano($previo[1]))) {
                    yield trim($previo[2]);

                    continue;
                }

                // B) «Etiqueta» sola en su linea: el dato esta en la linea siguiente.
                if ($igual($completo)) {
                    if (isset($lineas[$i + 1])) {
                        yield $lineas[$i + 1];
                    }

                    continue;
                }

                // C) «Etiqueta valor» en la misma linea: se saltan las palabras de la etiqueta.
                if (! $exacta && str_starts_with($completo, $etiqueta.' ')) {
                    $resto = trim(implode(' ', array_slice(preg_split('/\s+/', $linea) ?: [], $palabras)), ' :.');

                    if ($resto !== '') {
                        yield $resto;
                    }
                }
            }
        }
    }

    protected function contieneNoValor(string $rotulo, string $etiqueta): bool
    {
        // Solo aplica a etiquetas de importe: «Valor del costo», «Saldo disponible» nunca son el valor transferido.
        if (! in_array($etiqueta, self::ETIQUETAS_VALOR, true)) {
            return false;
        }

        foreach (self::NO_VALOR as $n) {
            if (str_contains(' '.$rotulo.' ', ' '.$n.' ') || str_contains($rotulo, $n)) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------ importe

    /**
     * @param  list<string>  $lineas
     * @return array{0: ?string, 1: bool, 2: bool} [valor, se vio el simbolo de moneda, hay importes distintos sin etiqueta]
     */
    protected function valor(array $lineas): array
    {
        foreach ($this->candidatos($lineas, self::ETIQUETAS_VALOR) as $c) {
            $r = $this->importeDe($c);

            if ($r !== null) {
                return [$r[0], $r[1], false];
            }
        }

        // Sin etiqueta (p. ej. el importe grande bajo el titulo): lineas que son SOLO un importe con simbolo de moneda. Si el mismo
        // importe aparece repetido se cuenta una vez; si hay importes distintos se toma el primero y se avisa.
        $encontrados = [];

        foreach ($lineas as $i => $l) {
            if (! preg_match('/^(?:\$|COP)\s?\d[\d.,]*$/iu', $l)) {
                continue;
            }

            $previa = $this->plano($lineas[$i - 1] ?? '');

            if (preg_match('/costo|comision|saldo|cupo|limite|disponible/', $previa)) {
                continue;
            }

            $r = $this->importeDe($l);

            if ($r !== null && bccomp($r[0], '0', 2) > 0) {
                $encontrados[] = $r[0];
            }
        }

        if (! $encontrados) {
            return [null, false, false];
        }

        return [$encontrados[0], true, count(array_unique($encontrados)) > 1];
    }

    /** @return ?array{0: string, 1: bool} */
    protected function importeDe(string $texto): ?array
    {
        if (! preg_match('/(\$|COP)?\s*(\d{1,3}(?:[.,]\d{3})+(?:[.,]\d{1,2})?|\d+(?:[.,]\d{1,2})?)(?![\d])/iu', $texto, $m)) {
            return null;
        }

        $valor = Dinero::parsear($m[2]);

        return $valor !== null && bccomp($valor, '0', 2) > 0 ? [$valor, $m[1] !== ''] : null;
    }

    // ------------------------------------------------------------------ codigos

    /**
     * @param  list<string>  $lineas
     * @param  list<string>  $etiquetas
     */
    protected function codigo(array $lineas, array $etiquetas): ?string
    {
        foreach ($this->candidatos($lineas, $etiquetas) as $c) {
            // Un codigo alfanumerico (puede traer ceros iniciales y espacios que el OCR parte). Debe tener al menos un digito.
            $c = trim($c, " \t.:;,'\"|[]()");
            $compacto = preg_replace('/\s+/', '', $c) ?? '';

            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9\-_.\/]{3,39}$/', $compacto) && preg_match('/\d/', $compacto) && ! preg_match('/^\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{4}$/', $compacto)) {
                return $compacto;
            }
        }

        return null;
    }

    /** @param  list<string>  $lineas */
    protected function telefono(array $lineas): ?string
    {
        foreach ($this->candidatos($lineas, ['numero nequi', 'numero daviplata', 'numero de celular', 'celular', 'telefono']) as $c) {
            $digitos = preg_replace('/\D/', '', $c) ?? '';

            if (strlen($digitos) >= 10) {
                return $digitos;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------ personas y entidades

    /**
     * Secciones «Origen» y «Destino» (lineas hasta la siguiente seccion).
     *
     * @param  list<string>  $lineas
     * @return array{origen: list<string>, destino: list<string>}
     */
    protected function secciones(array $lineas): array
    {
        $res = ['origen' => [], 'destino' => []];
        $actual = null;

        foreach ($lineas as $l) {
            $p = $this->plano($l);

            if ($p === 'origen' || $p === 'destino') {
                $actual = $p;

                continue;
            }

            if ($actual !== null) {
                $res[$actual][] = $l;
            }
        }

        return $res;
    }

    /**
     * @param  list<string>  $seccion
     * @param  list<string>  $etiquetas
     */
    protected function banco(array $seccion, array $etiquetas): ?string
    {
        foreach ($this->candidatos($seccion, $etiquetas) as $c) {
            $b = $this->limpiarEntidad($c);

            if ($b !== null) {
                return $b;
            }
        }

        return null;
    }

    /** @param  list<string>  $etiquetas */
    protected function bancoPorEtiqueta(array $lineas, array $etiquetas): ?string
    {
        foreach ($this->candidatos($lineas, $etiquetas, true) as $c) {
            $b = $this->limpiarEntidad(explode(' - ', $c)[0]);

            if ($b !== null) {
                return $b;
            }
        }

        return null;
    }

    /** «Desde: DaviPlata - 300 000 0002» → DaviPlata. */
    protected function bancoDeDesde(array $lineas): ?string
    {
        foreach ($this->candidatos($lineas, ['desde'], true) as $c) {
            if (preg_match('/^([A-Za-zÁÉÍÓÚáéíóúñÑ ]{3,30})\s+-\s+[\d ]{7,}$/u', $c, $m)) {
                return $this->limpiarEntidad($m[1]);
            }
        }

        return null;
    }

    /** Quita el ruido que el OCR deja en el logo («S Bold» → «Bold»); una entidad son palabras de letras. */
    protected function limpiarEntidad(string $texto): ?string
    {
        $palabras = array_values(array_filter(preg_split('/\s+/', trim($texto)) ?: [], fn ($w) => mb_strlen($w) >= 2 && preg_match('/^[\p{L}.]+$/u', $w)));
        $b = trim(implode(' ', $palabras));

        return $b !== '' && mb_strlen($b) <= 40 ? $b : null;
    }

    /**
     * @param  list<string>  $seccion
     * @param  list<string>  $etiquetas
     */
    protected function nombre(array $seccion, array $etiquetas, bool $exacta = false): ?string
    {
        foreach ($this->candidatos($seccion, $etiquetas, $exacta) as $c) {
            $n = $this->limpiarNombre($c);

            if ($n !== null) {
                return $n;
            }
        }

        return null;
    }

    /** @param  list<string>  $etiquetas */
    protected function nombrePorEtiqueta(array $lineas, array $etiquetas, bool $exacta = false): ?string
    {
        return $this->nombre($lineas, $etiquetas, true);
    }

    protected function limpiarNombre(string $c): ?string
    {
        $c = trim($c, " \t|:;");

        if ($c === '' || preg_match('/\d{4,}/', $c) || mb_strlen($c) > 80 || ! preg_match('/\p{L}{2}/u', $c)) {
            return null;
        }

        return $c;
    }

    /** DaviPlata: «Pasaste Plata a otro DaviPlata» y debajo «A <nombre>» (el OCR suele pegar la «A» al nombre). */
    protected function destinatarioDaviplata(array $lineas): ?string
    {
        foreach ($lineas as $i => $l) {
            if (! preg_match('/pasaste\s*plata/i', $this->plano($l))) {
                continue;
            }

            $siguiente = $lineas[$i + 1] ?? '';

            if (preg_match('/\bA\s?([A-ZÁÉÍÓÚÑ][a-záéíóúñ]+(?: [A-ZÁÉÍÓÚÑ][a-záéíóúñ]+){1,4})/u', $siguiente, $m)) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * Quita las lineas que solo nombran la entidad que RECIBE («Banco destino: Bancolombia», seccion «Destino»): no son el medio
     * con el que se hizo el pago.
     *
     * @param  list<string>  $lineas
     * @param  array{origen: list<string>, destino: list<string>}  $secciones
     * @return list<string>
     */
    protected function sinEntidadDestino(array $lineas, array $secciones): array
    {
        $excluir = $secciones['destino'];

        foreach ($this->candidatos($lineas, self::ETIQUETAS_BANCO_DESTINO, true) as $c) {
            $excluir[] = $c;
        }

        return array_values(array_filter($lineas, fn ($l) => ! in_array($l, $excluir, true)));
    }

    /**
     * Primer medio conocido en el texto (por posicion). Tolera UNA letra mal leida por el OCR en palabras de 5+ letras.
     *
     * @param  list<string>  $lineas
     */
    protected function medio(array $lineas): ?string
    {
        $plano = $this->plano(implode(' ', $lineas));
        $mejor = null;
        $posMejor = PHP_INT_MAX;

        foreach (self::MEDIOS as $clave => $nombre) {
            if (preg_match('/\b'.preg_quote($clave, '/').'\b/', $plano, $m, PREG_OFFSET_CAPTURE) && $m[0][1] < $posMejor) {
                $mejor = $nombre;
                $posMejor = $m[0][1];
            }
        }

        $pos = 0;

        foreach (preg_split('/\s+/', $plano) ?: [] as $palabra) {
            $pos = strpos($plano, $palabra, $pos) ?: $pos;

            foreach (self::MEDIOS as $clave => $nombre) {
                if (strlen($clave) >= 5 && ! str_contains($clave, ' ') && strlen($palabra) >= 5 && $palabra !== $clave && levenshtein($palabra, $clave) === 1 && $pos < $posMejor) {
                    $mejor = $nombre;
                    $posMejor = $pos;
                }
            }
        }

        return $mejor;
    }

    // ------------------------------------------------------------------ fecha y hora

    /**
     * @param  list<string>  $lineas
     * @return array{0: ?string, 1: ?string} [fecha AAAA-MM-DD, hora HH:MM(:SS)]
     */
    protected function fechaHora(array $lineas): array
    {
        $fecha = null;
        $hora = null;

        foreach ($this->candidatos($lineas, ['fecha y hora de la transaccion', 'fecha y hora', 'fecha de la transaccion', 'fecha de pago', 'fecha']) as $c) {
            $f = $this->fecha($c);

            if ($f !== null) {
                $fecha = $f;
                $hora = $this->hora($c);
                break;
            }
        }

        // Sin etiqueta legible (el OCR a veces pierde la palabra «Fecha»): primera linea que sea una fecha completa.
        if ($fecha === null) {
            foreach ($lineas as $l) {
                $f = $this->fecha($l);

                if ($f !== null) {
                    $fecha = $f;
                    $hora = $this->hora($l);
                    break;
                }
            }
        }

        if ($hora === null) {
            foreach ($this->candidatos($lineas, ['hora']) as $c) {
                $hora = $this->hora($c);

                if ($hora !== null) {
                    break;
                }
            }
        }

        return [$fecha, $fecha === null ? null : $hora];
    }

    protected function fecha(string $linea): ?string
    {
        $plano = $this->plano($linea);

        // «30 de septiembre de 2026», «30 sep 2026»
        if (preg_match('/\b(\d{1,2}) (?:de )?([a-z]{3,10}) (?:de |del )?(\d{4})\b/', $plano, $m) && isset(self::MESES[$m[2]])) {
            return $this->armar((int) $m[3], self::MESES[$m[2]], (int) $m[1]);
        }

        // «Octubre 19 de 2026»
        if (preg_match('/\b([a-z]{3,10}) (\d{1,2}) (?:de |del )?(\d{4})\b/', $plano, $m) && isset(self::MESES[$m[1]])) {
            return $this->armar((int) $m[3], self::MESES[$m[1]], (int) $m[2]);
        }

        // «30/09/2026» o «30-09-2026» (dia primero, formato colombiano)
        if (preg_match('/\b(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})\b/', $linea, $m)) {
            return $this->armar((int) $m[3], (int) $m[2], (int) $m[1]);
        }

        if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/', $linea, $m)) {
            return $this->armar((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        return null;
    }

    protected function armar(int $a, int $m, int $d): ?string
    {
        return checkdate($m, $d, $a) ? sprintf('%04d-%02d-%02d', $a, $m, $d) : null;
    }

    protected function hora(string $linea): ?string
    {
        if (! preg_match('/\b(\d{1,2}):(\d{2})(?::(\d{2}))?\s*((?:[ap])\.?\s?m\.?)?/iu', $linea, $m)) {
            return null;
        }

        $h = (int) $m[1];
        $min = (int) $m[2];
        $seg = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null;
        $sufijo = isset($m[4]) && $m[4] !== '' ? strtolower(str_replace([' ', '.'], '', $m[4])) : null;

        if ($sufijo === 'pm' && $h < 12) {
            $h += 12;
        } elseif ($sufijo === 'am' && $h === 12) {
            $h = 0;
        }

        if ($h > 23 || $min > 59 || ($seg !== null && $seg > 59)) {
            return null;
        }

        return $seg === null ? sprintf('%02d:%02d', $h, $min) : sprintf('%02d:%02d:%02d', $h, $min, $seg);
    }
}
