<?php

namespace ByLopez\VentasAsesor\Support\Dictado;

use Illuminate\Support\Str;

/**
 * Doc. 34 · Pedido por voz: convierte el TEXTO dictado (lo que el navegador transcribio) en lineas «color + genero + talla +
 * cantidad». Solo reglas, sin servicios externos ni base de datos: recibe la lista de colores del catalogo y devuelve lo que
 * entendio de cada linea, con sus problemas. No decide productos ni toca la venta (eso es ResolutorDictado y la pantalla).
 *
 * Como lee «roja 2-4, 2 unidades; roja 6-8, 10 unidades; azul rey talla S de dama, 15 unidades»:
 *  - Normaliza: minusculas, sin tildes, numeros en palabras a cifras («quince» → 15), «2 a 4» → talla 2-4.
 *  - Reconoce piezas: COLOR (nombre exacto del catalogo, tambien en femenino o plural: «roja», «negras»), GENERO (dama/mujer,
 *    caballero/hombre, niño/niña), TALLA (S, M, L, XL, 2-4, 6-8, 10-12, 14-16, unica; tambien «ese», «eme», «equis ele»),
 *    CANTIDAD (numero, con o sin «unidades»), TOP (crop top).
 *  - Arma lineas: una linea se cierra cuando llega una pieza que ya tenia (otro color, otra talla u otra cantidad). La
 *    siguiente HEREDA el color y el genero que no se repitan («roja 2-4 2 unidades, 6-8 10 unidades» = dos lineas rojas).
 *  - La talla de niño implica genero Niño. Un color generico («azul», «verde») queda ambiguo con sus candidatos: no se elige.
 */
final class InterpreteDictado
{
    public const FALTA_COLOR = 'falta_color';

    public const COLOR_AMBIGUO = 'color_ambiguo';

    public const FALTA_TALLA = 'falta_talla';

    public const FALTA_CANTIDAD = 'falta_cantidad';

    public const CANTIDAD_INVALIDA = 'cantidad_invalida';

    private const GENEROS = [
        'dama' => 'D', 'damas' => 'D', 'mujer' => 'D', 'mujeres' => 'D', 'femenino' => 'D', 'femenina' => 'D', 'senora' => 'D', 'senoras' => 'D',
        'caballero' => 'H', 'caballeros' => 'H', 'hombre' => 'H', 'hombres' => 'H', 'masculino' => 'H', 'masculina' => 'H', 'senor' => 'H', 'senores' => 'H',
        'nino' => 'N', 'ninos' => 'N', 'nina' => 'N', 'ninas' => 'N', 'infantil' => 'N', 'infantiles' => 'N',
    ];

    private const UNIDADES = ['unidad', 'unidades', 'und', 'unds', 'uds', 'ud', 'u', 'pieza', 'piezas', 'camiseta', 'camisetas', 'prenda', 'prendas'];

    /** Talla de niño: inicio → fin del rango. El codigo del SKU es el inicio (Doc. 33). */
    private const RANGOS_NINO = [2 => 4, 6 => 8, 10 => 12, 14 => 16];

    private const TALLAS_LETRA = ['xs' => 'XS', 's' => 'S', 'm' => 'M', 'l' => 'L', 'xl' => 'XL', 'xxl' => 'XXL', 'small' => 'S', 'medium' => 'M', 'mediana' => 'M', 'large' => 'L'];

    /** Letras deletreadas: solo cuentan justo despues de «talla» («ese color» no es una talla). */
    private const TALLAS_DELETREADAS = ['ese' => 'S', 'eme' => 'M', 'ele' => 'L', 'u' => 'U', 'unica' => 'U'];

    private const UNIDADES_PALABRA = [
        'cero' => 0, 'uno' => 1, 'una' => 1, 'un' => 1, 'dos' => 2, 'tres' => 3, 'cuatro' => 4, 'cinco' => 5, 'seis' => 6, 'siete' => 7, 'ocho' => 8,
        'nueve' => 9, 'diez' => 10, 'once' => 11, 'doce' => 12, 'trece' => 13, 'catorce' => 14, 'quince' => 15, 'dieciseis' => 16,
        'diecisiete' => 17, 'dieciocho' => 18, 'diecinueve' => 19, 'veinte' => 20, 'veintiuno' => 21, 'veintiun' => 21, 'veintiuna' => 21,
        'veintidos' => 22, 'veintitres' => 23, 'veinticuatro' => 24, 'veinticinco' => 25, 'veintiseis' => 26, 'veintisiete' => 27,
        'veintiocho' => 28, 'veintinueve' => 29,
    ];

    private const DECENAS_PALABRA = ['treinta' => 30, 'cuarenta' => 40, 'cincuenta' => 50, 'sesenta' => 60, 'setenta' => 70, 'ochenta' => 80, 'noventa' => 90];

    private const CENTENAS_PALABRA = [
        'cien' => 100, 'ciento' => 100, 'doscientos' => 200, 'doscientas' => 200, 'trescientos' => 300, 'trescientas' => 300,
        'cuatrocientos' => 400, 'cuatrocientas' => 400, 'quinientos' => 500, 'quinientas' => 500,
    ];

    /** Palabras de los nombres de color que no identifican un color por si solas. */
    private const PALABRAS_NO_COLOR = ['de', 'del', 'claro', 'oscuro', 'palo'];

    /** @var array<string, list<string>> frase normalizada → colores del catalogo (1 = exacto; varios = ambiguo) */
    private array $frases = [];

    private int $maxPalabrasColor = 1;

    /**
     * @param  list<string>  $colores  nombres de color del catalogo («Azul Rey», «Curuba/Salmon»)
     * @param  array<string, string|list<string>>  $alias  alias extra: «turquesa» => «Azul Turquesa»; «rosa» => [«Rosado», «Palo de Rosa»]
     */
    public function __construct(array $colores, array $alias = [])
    {
        $porPalabra = [];

        foreach (array_unique($colores) as $color) {
            foreach (preg_split('#/#', $color) ?: [] as $parte) {
                $this->agregarFrase($this->palabras($parte), $color);
            }
            $this->agregarFrase($this->palabras(str_replace('/', ' ', $color)), $color);

            foreach (array_unique($this->palabras(str_replace('/', ' ', $color))) as $palabra) {
                if (! in_array($palabra, self::PALABRAS_NO_COLOR, true) && mb_strlen($palabra) >= 3) {
                    $porPalabra[$palabra][] = $color;
                }
            }
        }

        // Una palabra suelta: si es de UN solo color lo identifica («turquesa»); si es de varios, queda ambigua («azul»).
        foreach ($porPalabra as $palabra => $deColores) {
            $this->frases[$palabra] ??= array_values(array_unique($deColores));
        }

        foreach ($alias as $frase => $destino) {
            $destinos = array_values(array_intersect((array) $destino, $colores));
            $palabras = $this->palabras($frase);
            if ($destinos !== [] && $palabras !== []) {
                $this->frases[implode(' ', $palabras)] = $destinos;
                $this->maxPalabrasColor = max($this->maxPalabrasColor, count($palabras));
            }
        }
    }

    /**
     * @return list<array{texto: string, color: ?string, candidatos: list<string>, genero: ?string, genero_explicito: bool,
     *                     linea: string, talla: ?string, talla_etiqueta: ?string, cantidad: ?int, problemas: list<string>}>
     */
    public function interpretar(string $texto): array
    {
        $tokens = $this->numeros($this->tokens($texto));
        $lineas = [];
        $actual = $this->nueva();
        $contextoAdulto = null;

        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            [$pieza, $consumidos] = $this->pieza($tokens, $i);
            $texto = implode(' ', array_filter(array_slice($tokens, $i, max(1, $consumidos)), fn ($t) => $t !== '|'));
            $i += max(1, $consumidos) - 1;

            if ($pieza === null) {
                if ($texto !== '') {
                    $actual['palabras'][] = $texto;
                }

                continue;
            }

            [$tipo, $valor] = $pieza;

            if ($tipo === 'linea') {
                // «top» fija linea, genero Dama y talla unica (blandas: una talla dicha despues las reemplaza).
                if (($actual['linea'] === 'TP' && ! isset($actual['heredado']['linea'])) || ($this->completa($actual) && ! isset($actual['heredado']['talla']))) {
                    $lineas[] = $actual;
                    $actual = $this->heredar($actual, ['genero']);
                }
                $actual['linea'] = 'TP';
                unset($actual['heredado']['linea']);
                $actual['genero'] = 'D';
                if ($actual['talla'] === null || isset($actual['heredado']['talla'])) {
                    $actual['talla'] = 'U';
                    $actual['heredado']['talla'] = true;
                }
                $actual['palabras'][] = $texto;

                continue;
            }

            if ($tipo === 'uno') {
                // «una blanca talla M»: el articulo vale 1 solo si la linea termina sin otra cantidad. Tras una linea completa
                // empieza una nueva.
                if ($this->completa($actual)) {
                    $lineas[] = $actual;
                    $actual = $this->heredar($actual, ['genero', 'linea']);
                }
                $actual['uno'] = true;
                $actual['palabras'][] = $texto;

                continue;
            }

            $campo = $tipo;
            // Ya lo tiene propio, o lo tiene heredado pero la linea ya esta completa («negro M 10, XL 3 y blanco M 4»).
            $propio = $this->tiene($actual, $campo) && (! isset($actual['heredado'][$campo]) || $this->completa($actual));

            if ($tipo === 'genero') {
                if ($propio && $actual['genero_explicito'] && $this->completa($actual)) {
                    $lineas[] = $actual;
                    $actual = $this->heredar($actual, ['color']);
                }
                $actual['genero'] = $valor;
                $actual['genero_explicito'] = true;
                unset($actual['heredado']['genero']);
                $actual['palabras'][] = $texto;
                if ($valor !== 'N') {
                    $contextoAdulto = $valor;
                }

                continue;
            }

            if ($propio) {
                $lineas[] = $actual;
                $actual = $this->heredar($actual, $tipo === 'color' ? ['genero', 'linea'] : ['color', 'genero', 'linea']);
            }

            if ($tipo === 'color') {
                $actual['color'] = count($valor) === 1 ? $valor[0] : null;
                $actual['candidatos'] = count($valor) === 1 ? [] : $valor;
                $actual['color_dicho'] = $texto;
                // Un color nuevo sin genero propio no hereda la linea «top» ni la talla unica.
                unset($actual['heredado']['color'], $actual['heredado']['candidatos']);
            } else {
                $actual[$campo] = $valor;
                unset($actual['heredado'][$campo]);
            }

            // La talla de niño implica Niño (salvo genero dicho en ESTA linea); una talla de adulto descarta un Niño heredado.
            if ($tipo === 'talla') {
                $esNino = ctype_digit((string) $valor) && isset(self::RANGOS_NINO[(int) $valor]);
                $generoHeredado = isset($actual['heredado']['genero']) || ! $actual['genero_explicito'];

                if ($esNino && ($actual['genero'] === null || $generoHeredado)) {
                    $actual['genero'] = 'N';
                    $actual['genero_explicito'] = false;
                    unset($actual['heredado']['genero']);
                } elseif (! $esNino && $actual['genero'] === 'N' && $generoHeredado) {
                    // «dama: azul S 10, roja 2-4 5, negra M 3»: la negra vuelve a ser Dama (el ultimo adulto dicho).
                    $actual['genero'] = $contextoAdulto;
                    $actual['genero_explicito'] = $contextoAdulto !== null;
                    unset($actual['heredado']['genero']);
                }
            }

            $actual['palabras'][] = $texto;
        }

        $lineas[] = $actual;

        return array_values(array_map(fn ($l) => $this->salida($l), array_filter($lineas, fn ($l) => $this->tieneDatos($l))));
    }

    /** @return array{0: ?array{0: string, 1: mixed}, 1: int} la pieza que empieza en $i y cuantos tokens ocupa */
    private function pieza(array $t, int $i): array
    {
        $tok = $t[$i];
        $sig = $t[$i + 1] ?? null;
        $sig2 = $t[$i + 2] ?? null;
        $antes = $t[$i - 1] ?? null;

        if ($tok === '|') {
            return [null, 1];
        }

        // Rango de niño: «2-4», «2 4», «2 a 4», «de 2 a 4».
        if (preg_match('/^(\d{1,2})-(\d{1,2})$/', $tok, $m) && $this->esRango((int) $m[1], (int) $m[2])) {
            return [['talla', (string) (int) $m[1]], 1];
        }
        if (ctype_digit($tok) && in_array($sig, ['a', 'al'], true) && $sig2 !== null && ctype_digit($sig2) && $this->esRango((int) $tok, (int) $sig2)) {
            return [['talla', (string) (int) $tok], 3];
        }
        if (ctype_digit($tok) && $sig !== null && ctype_digit($sig) && $this->esRango((int) $tok, (int) $sig)) {
            return [['talla', (string) (int) $tok], 2];
        }

        if (ctype_digit($tok)) {
            // «talla 10» = 10-12; «talla 24» = 2-4 (el navegador a veces junta los numeros).
            if ($antes === 'talla') {
                if (isset(self::RANGOS_NINO[(int) $tok])) {
                    return [['talla', (string) (int) $tok], 1];
                }
                foreach (self::RANGOS_NINO as $inicio => $fin) {
                    if ($tok === $inicio.$fin) {
                        return [['talla', (string) $inicio], 1];
                    }
                }
            }

            return [['cantidad', (int) $tok], in_array($sig, self::UNIDADES, true) ? 2 : 1];
        }

        if (in_array($tok, ['x', 'por'], true) && $sig !== null && ctype_digit($sig)) {
            return [['cantidad', (int) $sig], 2];
        }

        // Tallas por letra.
        if ($tok === 'x' && in_array($sig, ['l', 'ele'], true)) {
            return [['talla', 'XL'], 2];
        }
        if ($tok === 'equis' && in_array($sig, ['l', 'ele'], true)) {
            return [['talla', 'XL'], 2];
        }
        if ($tok === 'extra' && in_array($sig, ['grande', 'large'], true)) {
            return [['talla', 'XL'], 2];
        }
        if ($tok === 'extragrande') {
            return [['talla', 'XL'], 1];
        }
        if (isset(self::TALLAS_LETRA[$tok]) && ! ($tok === 'u')) {
            return [['talla', self::TALLAS_LETRA[$tok]], 1];
        }
        if ($antes === 'talla' && isset(self::TALLAS_DELETREADAS[$tok])) {
            return [['talla', self::TALLAS_DELETREADAS[$tok]], 1];
        }
        if ($tok === 'unica' || ($tok === 'talla' && $sig === 'unica')) {
            return [['talla', 'U'], $tok === 'talla' ? 2 : 1];
        }

        if (in_array($tok, ['un', 'una', 'uno'], true)) {
            return [['uno', 1], 1];
        }

        if (isset(self::GENEROS[$tok])) {
            return [['genero', self::GENEROS[$tok]], 1];
        }

        if ($tok === 'crop' && in_array($sig, ['top', 'tops'], true)) {
            return [['linea', 'TP'], 2];
        }
        if (in_array($tok, ['top', 'tops', 'crop'], true)) {
            return [['linea', 'TP'], 1];
        }

        // Color: la frase mas larga del catalogo que empiece aqui (comparando en forma base: «roja» = «rojo»).
        for ($largo = min($this->maxPalabrasColor, count($t) - $i); $largo >= 1; $largo--) {
            $trozo = array_slice($t, $i, $largo);
            if (in_array('|', $trozo, true)) {
                continue;
            }
            $clave = implode(' ', array_map(fn ($p) => $this->base($p), $trozo));
            if (isset($this->frases[$clave])) {
                return [['color', $this->frases[$clave]], $largo];
            }
        }

        return [null, 1];
    }

    private function esRango(int $inicio, int $fin): bool
    {
        return (self::RANGOS_NINO[$inicio] ?? null) === $fin;
    }

    /** @return list<string> */
    private function tokens(string $texto): array
    {
        $texto = mb_strtolower(Str::ascii($texto));
        // «2/4» y «2 - 4» → «2-4»; signos de puntuacion → separador.
        $texto = preg_replace('#(\d)\s*[-/]\s*(\d)#', '$1-$2', $texto) ?? $texto;
        $texto = preg_replace('/[,;:.!?\n\r]+/', ' | ', $texto) ?? $texto;
        $texto = preg_replace('/[^a-z0-9|\- ]+/', ' ', $texto) ?? $texto;

        return array_values(array_filter(preg_split('/\s+/', trim($texto)) ?: [], fn ($t) => $t !== '' && $t !== '-'));
    }

    /** Numeros en palabras → cifras. «un/una/uno» solo cuenta como numero si va antes de «unidades» o al final de una frase. */
    private function numeros(array $t): array
    {
        $salida = [];

        for ($i = 0, $n = count($t); $i < $n; $i++) {
            $valor = null;
            $j = $i;

            if (isset(self::CENTENAS_PALABRA[$t[$j]])) {
                $valor = self::CENTENAS_PALABRA[$t[$j]];
                $j++;
            }
            if (isset(self::DECENAS_PALABRA[$t[$j] ?? ''])) {
                $valor = ($valor ?? 0) + self::DECENAS_PALABRA[$t[$j]];
                $j++;
                if (($t[$j] ?? null) === 'y' && isset(self::UNIDADES_PALABRA[$t[$j + 1] ?? '']) && self::UNIDADES_PALABRA[$t[$j + 1]] < 10) {
                    $valor += self::UNIDADES_PALABRA[$t[$j + 1]];
                    $j += 2;
                }
            } elseif (isset(self::UNIDADES_PALABRA[$t[$j] ?? ''])) {
                $palabra = $t[$j];
                $siguiente = $t[$j + 1] ?? '|';
                // «una camiseta roja … 5 unidades»: «camiseta» no confirma cantidad; «una unidad» o «una» al final de frase si.
                $esArticulo = in_array($palabra, ['un', 'una', 'uno'], true)
                    && ! in_array($siguiente, ['unidad', 'unidades', 'und', 'unds', 'uds', 'ud', 'u', 'pieza', 'piezas', '|'], true);
                if (! $esArticulo || $valor !== null) {
                    $valor = ($valor ?? 0) + self::UNIDADES_PALABRA[$palabra];
                    $j++;
                }
            }

            if ($valor === null || $j === $i) {
                $salida[] = $t[$i];

                continue;
            }

            $salida[] = (string) $valor;
            $i = $j - 1;
        }

        return $salida;
    }

    /** @return list<string> palabras en forma base de un nombre de color o alias */
    private function palabras(string $texto): array
    {
        $limpio = preg_replace('/[^a-z0-9 ]+/', ' ', mb_strtolower(Str::ascii($texto))) ?? '';

        return array_values(array_map(fn ($p) => $this->base($p), array_filter(explode(' ', $limpio), fn ($p) => $p !== '')));
    }

    /** Forma base para comparar colores: sin plural y en masculino («rojas» → «rojo», «azules» → «azul»). */
    private function base(string $palabra): string
    {
        if (mb_strlen($palabra) > 4 && preg_match('/(l|n|r)es$/', $palabra)) {
            $palabra = mb_substr($palabra, 0, -2);
        } elseif (mb_strlen($palabra) > 3 && str_ends_with($palabra, 's')) {
            $palabra = mb_substr($palabra, 0, -1);
        }

        return mb_strlen($palabra) > 3 && str_ends_with($palabra, 'a') ? mb_substr($palabra, 0, -1).'o' : $palabra;
    }

    private function agregarFrase(array $palabras, string $color): void
    {
        if ($palabras === []) {
            return;
        }

        $clave = implode(' ', $palabras);
        $this->frases[$clave] = array_values(array_unique([...($this->frases[$clave] ?? []), $color]));
        $this->maxPalabrasColor = max($this->maxPalabrasColor, count($palabras));
    }

    private function nueva(): array
    {
        return ['color' => null, 'candidatos' => [], 'color_dicho' => null, 'genero' => null, 'genero_explicito' => false, 'linea' => 'DZ',
            'talla' => null, 'cantidad' => null, 'uno' => false, 'palabras' => [], 'heredado' => []];
    }

    /** Linea nueva que hereda (en blando) los campos indicados de la anterior. */
    private function heredar(array $anterior, array $campos): array
    {
        $nueva = $this->nueva();

        foreach ($campos as $campo) {
            if ($campo === 'color') {
                $nueva['color'] = $anterior['color'];
                $nueva['candidatos'] = $anterior['candidatos'];
                $nueva['color_dicho'] = $anterior['color_dicho'];
                $nueva['heredado']['color'] = true;
            } elseif ($campo === 'genero') {
                $nueva['genero'] = $anterior['genero'];
                $nueva['genero_explicito'] = $anterior['genero_explicito'];
                $nueva['heredado']['genero'] = true;
            } elseif ($campo === 'linea' && $anterior['linea'] !== 'DZ') {
                $nueva['linea'] = $anterior['linea'];
                $nueva['heredado']['linea'] = true;
                if ($anterior['talla'] === 'U') {
                    $nueva['talla'] = 'U';
                    $nueva['heredado']['talla'] = true;
                }
            }
        }

        return $nueva;
    }

    private function tiene(array $l, string $campo): bool
    {
        return $campo === 'color' ? ($l['color'] !== null || $l['candidatos'] !== []) : $l[$campo] !== null;
    }

    private function completa(array $l): bool
    {
        return $l['talla'] !== null && $l['cantidad'] !== null;
    }

    /** Una linea cuenta si trae algo propio (no solo lo heredado). */
    private function tieneDatos(array $l): bool
    {
        foreach (['color', 'talla', 'cantidad'] as $campo) {
            if (($campo === 'color' ? ($l['color'] !== null || $l['candidatos'] !== []) : $l[$campo] !== null) && ! isset($l['heredado'][$campo])) {
                return true;
            }
        }

        return $l['linea'] === 'TP' && ! isset($l['heredado']['linea']);
    }

    private function salida(array $l): array
    {
        $problemas = [];
        $l['cantidad'] ??= $l['uno'] ? 1 : null;

        if ($l['color'] === null) {
            $problemas[] = $l['candidatos'] === [] ? self::FALTA_COLOR : self::COLOR_AMBIGUO;
        }
        if ($l['talla'] === null) {
            $problemas[] = self::FALTA_TALLA;
        }
        if ($l['cantidad'] === null) {
            $problemas[] = self::FALTA_CANTIDAD;
        } elseif ($l['cantidad'] < 1 || $l['cantidad'] > 9999) {
            $problemas[] = self::CANTIDAD_INVALIDA;
        }

        $talla = $l['talla'];

        return [
            'texto' => trim(implode(' ', $l['palabras'])),
            'color' => $l['color'],
            'candidatos' => $l['candidatos'],
            'genero' => $l['genero'],
            'genero_explicito' => $l['genero_explicito'],
            'linea' => $l['linea'],
            'talla' => $talla,
            'talla_etiqueta' => $talla === null ? null : (isset(self::RANGOS_NINO[(int) $talla]) && ctype_digit($talla)
                ? $talla.'-'.self::RANGOS_NINO[(int) $talla] : ($talla === 'U' ? 'Única' : $talla)),
            'cantidad' => $l['cantidad'],
            'problemas' => $problemas,
        ];
    }
}
