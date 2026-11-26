<?php

namespace ByLopez\VentasAsesor\Support\Dictado;

use Illuminate\Support\Str;

/**
 * Doc. 34 · Pedido por voz: convierte el TEXTO dictado (lo que el navegador transcribio) en lineas «tipo + color + talla +
 * cantidad». Solo reglas, sin servicios externos ni base de datos: recibe la lista de colores del catalogo y devuelve lo que
 * entendio de cada linea, con sus problemas y las palabras que sustentan cada campo. No decide productos ni toca la venta.
 *
 * Dos pasos (revision 2026-11-24, caso «roja 68 niño 10»):
 *  1. PIEZAS SEGURAS arman las lineas: color, tipo (dama/caballero/niño), crop top, talla por letra, talla con marcador
 *     («talla 6», «talla 68»), rango explicito («6-8», «6 a 8», «seis ocho» cuando el contexto admite niño) y cantidad con
 *     marcador («cantidad 10», «10 unidades», «x 10»). Una linea se cierra cuando llega una pieza que ya tenia.
 *     Los NUMEROS SUELTOS no cierran lineas ni se asignan todavia: se guardan en la linea donde se dijeron.
 *  2. ASIGNACION por linea: los numeros sueltos se reparten entre la talla que falte (solo alias de niño: 24, 68, 1012, 1416,
 *     «6.8», «6, 8», «6 y 8») y la cantidad. Se toma la lectura UNICA que usa todos los numeros; si hay dos lecturas se pregunta
 *     («¿68 es la talla 6-8 o 68 unidades?»); si sobran numeros se muestran como problema. Un numero no sirve para dos campos
 *     ni pasa a otra linea, salvo la cantidad dicha ANTES del producto siguiente cuando esa es la unica lectura
 *     («negro M 10, 5 blanco M»).
 *
 * Nunca se inventa: color ambiguo, talla o cantidad faltante, cantidad decimal/negativa/cero quedan como problema.
 */
final class InterpreteDictado
{
    public const FALTA_COLOR = 'falta_color';

    public const COLOR_AMBIGUO = 'color_ambiguo';

    public const FALTA_TALLA = 'falta_talla';

    public const TALLA_NO_RECONOCIDA = 'talla_no_reconocida';

    public const FALTA_CANTIDAD = 'falta_cantidad';

    public const CANTIDAD_INVALIDA = 'cantidad_invalida';

    public const CANTIDAD_CONTRADICTORIA = 'cantidad_contradictoria';

    /** Un mismo numero puede ser la talla o la cantidad: se ofrecen las lecturas (`opciones`). */
    public const TALLA_O_CANTIDAD = 'talla_o_cantidad';

    /** Numeros que no encajan en ningun campo de la linea (`sin_asignar`). */
    public const NUMEROS_SIN_ASIGNAR = 'numeros_sin_asignar';

    /** Dos tallas distintas para el mismo producto («talla M talla L 3»): no se elige ninguna (`detalle.tallas_dobles`). */
    public const TALLA_CONTRADICTORIA = 'talla_contradictoria';

    /** Dos tipos distintos para la misma prenda sin «no» entre ellos («dama caballero»): `detalle.tipos_dobles`. */
    public const TIPO_CONTRADICTORIO = 'tipo_contradictorio';

    /** Un tipo dicho entre dos prendas sin pausa que diga de cual es (`detalle.tipo_dudoso`): la anterior se pregunta. */
    public const TIPO_DUDOSO = 'tipo_dudoso';

    /** «no» / «mejor» / «perdón» sin un reemplazo claro del mismo tipo que lo ultimo dicho (`detalle.correccion`). */
    public const CORRECCION_DUDOSA = 'correccion_dudosa';

    /** Algo con forma de codigo que no es una referencia del catalogo (otra tela, otro color, talla inexistente). */
    public const SKU_NO_RECONOCIDO = 'sku_no_reconocido';

    /** Un SKU seguido de un atributo que no coincide con el («124H-DZ-M dama»): `detalle.sku_conflictos`. */
    public const SKU_CONTRADICTORIO = 'sku_contradictorio';

    /** Palabras que anuncian una corrección de lo ultimo dicho. */
    private const MARCAS_CORRECCION = ['no', 'mejor', 'perdon', 'perdona', 'digo', 'corrijo', 'osea'];

    /**
     * Palabras de apoyo del habla («quiero», «dame», «por favor», articulos): no son datos. Cualquier otra palabra que no se
     * use se muestra en la linea (`detalle.no_usadas`). «no» NO es de apoyo: es una corrección o una negación.
     */
    private const APOYO = ['de', 'del', 'la', 'las', 'el', 'los', 'lo', 'le', 'les', 'en', 'y', 'e', 'o', 'a', 'al', 'con', 'para', 'por', 'favor',
        'porfa', 'porfavor', 'quiero', 'quisiera', 'queria', 'necesito', 'necesitamos', 'dame', 'deme', 'denos', 'danos', 'me', 'nos', 'mi', 'su',
        'regalas', 'regala', 'regalame', 'manda', 'mandame', 'envia', 'enviame', 'pon', 'ponme', 'poner', 'agrega', 'agregame', 'agregar', 'agregue',
        'anade', 'anota', 'anotame', 'apunta', 'pedido', 'pedir', 'pido', 'listo', 'listos', 'lista', 'bueno', 'buenas', 'buenos', 'ok', 'okay', 'vale',
        'entonces', 'tambien', 'mas', 'otra', 'otro', 'otras', 'otros', 'una', 'un', 'uno', 'unas', 'unos', 'referencia', 'referencias', 'ref',
        'camiseta', 'camisetas', 'prenda', 'prendas', 'talla', 'tallas', 'color', 'colores', 'tipo', 'cantidad', 'unidad', 'unidades', 'und',
        'unds', 'uds', 'ud', 'pieza', 'piezas', 'que', 'son', 'es', 'sea', 'seria', 'hola', 'gracias', 'hay', 'tengo', 'tiene', 'x', 'cada', 'total',
        'eso', 'esa', 'ese', 'este', 'esta', 'esto', 'aqui', 'ahi', 'si', 'piel', 'durazno', 'linea', 'dz', 'dia', 'dias', 'tarde', 'tardes',
        'noche', 'noches', 'senorita', 'cliente', 'clienta', 'va', 'van', 'llevar', 'lleva', 'llevo'];

    private const GENEROS = [
        'dama' => 'D', 'damas' => 'D', 'mujer' => 'D', 'mujeres' => 'D', 'femenino' => 'D', 'femenina' => 'D', 'senora' => 'D', 'senoras' => 'D',
        'caballero' => 'H', 'caballeros' => 'H', 'hombre' => 'H', 'hombres' => 'H', 'masculino' => 'H', 'masculina' => 'H', 'senor' => 'H', 'senores' => 'H',
        'nino' => 'N', 'ninos' => 'N', 'nina' => 'N', 'ninas' => 'N', 'infantil' => 'N', 'infantiles' => 'N',
    ];

    /** Palabras que confirman que el numero anterior es la cantidad. */
    private const UNIDADES = ['unidad', 'unidades', 'und', 'unds', 'uds', 'ud', 'u', 'pieza', 'piezas', 'camiseta', 'camisetas', 'prenda', 'prendas'];

    /** Despues de «una» expresan una unidad («una camiseta», «una unidad»); «una referencia» no. */
    private const UNO_CON = ['unidad', 'unidades', 'pieza', 'piezas', 'camiseta', 'camisetas', 'prenda', 'prendas', 'top', 'crop'];

    /** Talla de niño: inicio → fin del rango. El codigo del SKU es el inicio (Doc. 33; confirmado en el catalogo, grupo_de_edad_ninos). */
    private const RANGOS_NINO = [2 => 4, 6 => 8, 10 => 12, 14 => 16];

    /** Rango de niño dicho pegado (el navegador escribe «68» por «seis ocho»): solo cuenta segun el contexto de la linea. */
    private const ALIAS_PEGADOS = ['24' => '2', '68' => '6', '1012' => '10', '1416' => '14'];

    private const TALLAS_LETRA = ['xs' => 'XS', 's' => 'S', 'm' => 'M', 'l' => 'L', 'xl' => 'XL', 'xxl' => 'XXL', '2xl' => 'XXL',
        'small' => 'S', 'medium' => 'M', 'mediana' => 'M', 'large' => 'L', 'extragrande' => 'XL'];

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

    private const CANTIDAD_MAXIMA = 9999;

    /** @var array<string, list<string>> frase normalizada → colores del catalogo (1 = exacto; varios = ambiguo) */
    private array $frases = [];

    private int $maxPalabrasColor = 1;

    /** @var array<int, string> numero del codigo unico → nombre de color («124» → «Blanco»): permite dictar el SKU entero */
    private array $codigos = [];

    /** @var list<string>|null tokens con la escritura original de la interpretacion en curso (null = no alinean) */
    private ?array $originales = null;

    /** @var list<array{0: int, 1: int}> token de salida (tras unir numeros en palabras) → tokens de entrada que lo forman */
    private array $mapa = [];

    /** Tipo elegido para lo que no se diga en la interpretacion en curso (D, H, N, TP) o null. */
    private ?string $tipoDefecto = null;

    /**
     * @param  list<string>  $colores  nombres de color del catalogo («Azul Rey», «Curuba/Salmon»)
     * @param  array<string, string|list<string>>  $alias  alias extra: «turquesa» => «Azul Turquesa»; «rosa» => [«Rosado», «Palo de Rosa»]
     */
    public function __construct(array $colores, array $alias = [], array $codigos = [])
    {
        $this->codigos = $codigos;
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

    /** Etiqueta comercial de un codigo de talla del SKU: «6» → «6-8», «U» → «Única». */
    public static function etiquetaTalla(?string $codigo): ?string
    {
        if ($codigo === null) {
            return null;
        }

        if (ctype_digit($codigo) && isset(self::RANGOS_NINO[(int) $codigo])) {
            return $codigo.'-'.self::RANGOS_NINO[(int) $codigo];
        }

        return $codigo === 'U' ? 'Única' : $codigo;
    }

    /**
     * @return list<array{texto: string, color: ?string, candidatos: list<string>, genero: ?string, genero_explicito: bool,
     *                     linea: string, talla: ?string, talla_etiqueta: ?string, cantidad: ?int, problemas: list<string>,
     *                     fuentes: array<string, ?string>, sin_asignar: list<string>, opciones: list<array<string, mixed>>,
     *                     detalle: array<string, mixed>}>
     */
    public function interpretar(string $texto, ?string $tipoDefecto = null): array
    {
        // «Si no digo el tipo» (D, H, N, TP): un tipo de adulto descarta que un numero suelto sea talla de niño («lila 2» = 2 u.).
        $this->tipoDefecto = $tipoDefecto;
        $normalizados = $this->tokens($texto);
        $this->originales = $this->tokensOriginales($texto, count($normalizados));
        $t = $this->numeros($normalizados);
        $lineas = [];
        $actual = $this->nueva();
        $contextoAdulto = null;
        // Pieza anterior: la misma pieza dicha dos veces seguidas («negro negro», «M M») es eco o tartamudeo, no otro producto.
        $previa = null;
        // Ultimo token usado por una pieza o un numero (para saber si hubo una pausa «,» / «y» antes de un tipo).
        $ultimoFin = -1;

        for ($i = 0, $n = count($t); $i < $n; $i++) {
            $inicio = $i;
            [$pieza, $consumidos] = $this->pieza($t, $i, $actual);
            $i += max(1, $consumidos) - 1;
            $frag = implode(' ', array_filter(array_slice($t, $inicio, $i - $inicio + 1), fn ($x) => $x !== '|'));

            // do/while(false): cada «continue» de abajo termina el fragmento y deja registrar su rango en la linea donde quedo.
            do {
            if ($pieza === null) {
                if ($frag !== '') {
                    $actual['palabras'][] = $frag;
                    // Palabras que no son de apoyo y no se usaron: se muestran, nunca desaparecen en silencio.
                    if (! in_array($frag, self::APOYO, true)) {
                        $actual['sueltas'][] = $frag;
                    }
                }

                continue;
            }

            [$tipo, $valor] = $pieza;
            $clave = $this->clavePieza($tipo, $valor);

            if ($clave !== null && $clave === $previa) {
                $actual['palabras'][] = $frag;
                $actual['repetidas'][] = $frag;
                $ultimoFin = $i;

                continue;
            }
            $previa = $clave;

            if ($tipo === 'correccion') {
                // «no», «mejor», «perdón»: corrige la ULTIMA pieza dicha en la linea con la siguiente del mismo tipo.
                $actual['palabras'][] = $frag;
                $actual['correccion'] = ['marca' => $frag, 'objetivo' => $actual['ultima']];

                continue;
            }

            $familia = $this->familia($tipo);
            $negarDespues = null;

            if ($actual['correccion'] !== null) {
                ['marca' => $marca, 'objetivo' => $objetivo] = $actual['correccion'];
                $actual['correccion'] = null;

                if ($objetivo === null) {
                    // «no negro, blanco»: lo dicho tras «no» queda negado hasta que llegue su reemplazo.
                    $negarDespues = $familia;
                } elseif ($objetivo === $familia && ($antes = $this->reemplazar($actual, $tipo, $valor, $frag, $inicio, $i)) !== null) {
                    $actual['palabras'][] = $frag;
                    $actual['correcciones'][] = $antes.' → '.$frag;
                    $actual['ultima'] = $familia;
                    $ultimoFin = $i;

                    continue;
                } else {
                    // La corrección no coincide con lo último dicho («M 2, no, blanco»): no se adivina qué cambiar.
                    $actual['correccion_dudosa'][] = $marca;
                }
            } elseif ($actual['negado'] !== null && $actual['negado']['familia'] === $familia
                && ($antes = $this->reemplazar($actual, $tipo, $valor, $frag, $inicio, $i)) !== null) {
                $actual['palabras'][] = $frag;
                $actual['correcciones'][] = 'no '.$antes.' → '.$frag;
                $actual['negado'] = null;
                $actual['ultima'] = $familia;
                $ultimoFin = $i;

                continue;
            }

            $this->aplicarPieza($actual, $lineas, $t, $inicio, $i, $frag, $tipo, $valor, $contextoAdulto, $ultimoFin);

            if ($negarDespues !== null) {
                $actual['negado'] = ['familia' => $negarDespues, 'frag' => $frag];
            }
            if ($familia !== null) {
                $actual['ultima'] = $familia;
            }
            $ultimoFin = $i;
            } while (false);

            // Rango de tokens de la linea donde quedo este fragmento: con el se muestra lo que se dijo TAL CUAL.
            if ($frag !== '') {
                $actual['r_ini'] = $actual['r_ini'] === null ? $inicio : min($actual['r_ini'], $inicio);
                $actual['r_fin'] = max($actual['r_fin'] ?? -1, $i);
            }
        }

        $lineas[] = $actual;

        return $this->asignarLineas($this->depurar($lineas));
    }

    /**
     * Paso 1: lleva una pieza segura a la linea. Una pieza que la linea ya tenia la cierra y la siguiente empieza con ella,
     * salvo los casos donde eso inventaria un producto (tipos o SKU contradictorios, tipo entre dos prendas).
     */
    private function aplicarPieza(array &$actual, array &$lineas, array $t, int $inicio, int $i, string $frag, string $tipo, mixed $valor, ?string &$contextoAdulto, int $ultimoFin): void
    {
        $cerrar = function (array $heredar) use (&$actual, &$lineas) {
            $lineas[] = $actual;
            $actual = $this->heredar($actual, $heredar);
        };
        $palabra = function (bool $repetida = false) use (&$actual, $frag) {
            $actual['palabras'][] = $frag;
            if ($repetida) {
                $actual['repetidas'][] = $frag;
            }
        };

        if ($tipo === 'numero') {
            // El paso 2 decide si es talla o cantidad. No cierra la linea.
            $actual['palabras'][] = $frag;
            $actual['numeros'][] = $valor + ['texto' => $frag, 'ini' => $inicio, 'fin' => $i];

            return;
        }

        if ($tipo === 'par_suelto') {
            // «2 4» que no puede ser talla aqui («negro dama M 2 4»): dos numeros sueltos, sin linea fantasma de niño.
            $actual['palabras'][] = $frag;
            foreach ($valor as $k => $num) {
                $actual['numeros'][] = $num + ['texto' => (string) $num['valor'], 'ini' => $inicio + $k, 'fin' => $inicio + $k];
            }

            return;
        }

        if ($tipo === 'sku' || $tipo === 'sku_invalido') {
            // El SKU es un producto propio. Lo dicho antes que solo era cantidad («cinco 124H-DZ-M») es de este producto; si
            // la linea ya tenia producto, se cierra. No hereda nada.
            $previo = $actual;
            $soloCantidad = ! $this->tieneProducto($actual);
            if (! $soloCantidad && $this->tieneDatos($actual)) {
                $cerrar([]);
            }
            $actual = $this->nueva();
            if ($soloCantidad) {
                foreach (['numeros', 'cantidad', 'cantidad_fuente', 'uno', 'palabras', 'sueltas', 'r_ini', 'r_fin'] as $campo) {
                    $actual[$campo] = $previo[$campo];
                }
            }
            $actual['palabras'][] = $frag;
            $actual['ancla'] = $i;

            if ($tipo === 'sku_invalido') {
                $actual['skus_invalidos'][] = $valor;

                return;
            }

            $actual['sku_dicho'] = $valor['sku'];
            $actual['sku_fijo'] = $valor;
            $actual['color'] = $valor['color'];
            $actual['color_dicho'] = $frag;
            $actual['genero'] = $valor['genero'];
            $actual['genero_explicito'] = true;
            $actual['genero_dicho'] = $frag;
            $actual['linea'] = $valor['linea'];
            if ($valor['talla'] !== null) {
                $actual['talla'] = $valor['talla'];
                $actual['talla_fuente'] = $frag;
            } elseif ($valor['linea'] === 'TP') {
                // Crop top sin talla: la decide el catalogo (si existe en M y Única, se pregunta).
                $actual['talla'] = 'U';
                $actual['talla_fuente'] = $frag;
                $actual['heredado']['talla'] = true;
                $actual['talla_asumida'] = true;
            }
            if ($valor['genero'] !== 'N') {
                $contextoAdulto = $valor['genero'];
            }

            return;
        }

        if ($tipo === 'talla_invalida') {
            $actual['palabras'][] = $frag;
            $actual['tallas_invalidas'][] = $frag;
            $actual['ancla'] = $i;

            return;
        }

        // Un atributo despues de un SKU: si coincide no aporta nada; si no coincide y no empieza otro producto, se pregunta.
        if ($actual['sku_fijo'] !== null && in_array($tipo, ['genero', 'color', 'talla', 'linea'], true)) {
            $fijo = $actual['sku_fijo'];
            $coincide = match ($tipo) {
                'genero' => $valor === $fijo['genero'],
                'color' => count($valor) === 1 && $valor[0] === $fijo['color'],
                'talla' => $fijo['talla'] === null || $valor === $fijo['talla'],
                'linea' => $fijo['linea'] === 'TP',
            };

            if ($coincide && ! ($tipo === 'talla' && $fijo['talla'] === null)) {
                $palabra(true);

                return;
            }
            if (! $coincide && (! $this->completa($actual) || ! $this->siguenPiezas($t, $i + 1))) {
                $palabra();
                $actual['sku_conflictos'][] = $frag;

                return;
            }
            // SKU sin talla + talla dicha: la completa (abajo). SKU completo + otro producto: sigue la regla general.
        }

        if ($tipo === 'linea') {
            // «top» fija linea, genero Dama y talla unica (blandas: una talla dicha despues las reemplaza). Si la linea ya tiene
            // su color y un tipo dicho que no es dama («rojo 10 niño, crop top negro 2»), el crop top es OTRA prenda: nunca
            // convierte en crop top a la de niño o caballero (prueba de navegador, 2026-11-26).
            $tipoAjeno = $actual['genero'] !== null && $actual['genero'] !== 'D' && $actual['genero_explicito'] && ! isset($actual['heredado']['genero']);
            if (($actual['linea'] === 'TP' && ! isset($actual['heredado']['linea'])) || ($this->completa($actual) && ! isset($actual['heredado']['talla']))
                || ($this->colorPropio($actual) && $tipoAjeno)) {
                $cerrar([]);
            }
            $actual['palabras'][] = $frag;
            $actual['ancla'] = $i;
            $actual['linea'] = 'TP';
            unset($actual['heredado']['linea']);
            $actual['genero'] = 'D';
            if ($actual['talla'] === null || isset($actual['heredado']['talla'])) {
                $actual['talla'] = 'U';
                $actual['talla_fuente'] = $frag;
                $actual['heredado']['talla'] = true;
                $actual['talla_asumida'] = true;
            }

            return;
        }

        if ($tipo === 'uno') {
            // «una camiseta roja talla M»: 1 expresado (decision A). Tras una linea completa empieza una nueva; tambien cuando
            // la linea ya tiene su color y «una» viene delante de OTRO color («… negras L, una blanca S»).
            if ($this->completa($actual) || $actual['uno'] !== null || $actual['cantidad'] !== null
                || ($valor['antes_de_color'] && $this->colorPropio($actual))) {
                $cerrar(['genero', 'linea']);
            }
            $actual['palabras'][] = $frag;
            $actual['ancla'] = $i;
            $actual['uno'] = $frag;

            return;
        }

        // La talla Única que se asume al decir «crop top» es blanda: una talla dicha despues la reemplaza aunque la linea ya
        // tenga cantidad («tres crop top lila M» = M × 3; evaluacion ciega E41, 2026-11-26). Tras una pausa y con la prenda
        // completa, la talla es otra prenda («crop top lila 3, M 2»).
        if ($tipo === 'talla' && $actual['talla_asumida']
            && ! ($this->completa($actual) && $this->haySeparador($t, $ultimoFin + 1, $inicio - 1))) {
            $actual['palabras'][] = $frag;
            $actual['ancla'] = $i;
            $actual['talla'] = $valor;
            $actual['talla_fuente'] = $frag;
            $actual['talla_asumida'] = false;
            unset($actual['heredado']['talla']);

            return;
        }

        $campo = $tipo;
        // Ya lo tiene propio, o lo tiene heredado pero la linea ya esta completa («negro M 10, XL 3 y blanco M 4»).
        $propio = $this->tiene($actual, $campo) && (! isset($actual['heredado'][$campo]) || $this->completa($actual));

        if ($tipo === 'genero') {
            $this->aplicarGenero($actual, $cerrar, $palabra, $t, $inicio, $i, $frag, $valor, $contextoAdulto, $ultimoFin);

            return;
        }

        if ($tipo === 'talla' && $propio && ! isset($actual['heredado']['talla']) && ! $this->completa($actual)) {
            // «negro dama talla M talla L 3»: dos tallas para un producto que aun no tiene cantidad. No se elige en silencio
            // ni se abre otra linea: se pregunta. La misma talla repetida («M dama M») no contradice nada.
            $actual['palabras'][] = $frag;
            $actual['ancla'] = $i;
            if ($valor === $actual['talla']) {
                $actual['repetidas'][] = $frag;
            } else {
                $actual['tallas_dobles'] = array_values(array_unique([...($actual['tallas_dobles'] ?: [$actual['talla_fuente']]), $frag]));
            }

            return;
        }

        if ($propio) {
            $cerrar($tipo === 'color' ? ['genero', 'linea'] : ['color', 'genero', 'linea']);
        }

        $actual['palabras'][] = $frag;
        $actual['ancla'] = $i;

        if ($tipo === 'color') {
            $actual['color'] = count($valor) === 1 ? $valor[0] : null;
            $actual['candidatos'] = count($valor) === 1 ? [] : $valor;
            $actual['color_dicho'] = $frag;
            unset($actual['heredado']['color'], $actual['heredado']['candidatos']);

            return;
        }

        if ($tipo === 'cantidad') {
            $actual['cantidad'] = $valor;
            $actual['cantidad_fuente'] = $frag;

            return;
        }

        // Talla segura.
        $actual['talla'] = $valor;
        $actual['talla_fuente'] = $frag;
        $actual['talla_asumida'] = false;
        unset($actual['heredado']['talla']);
        $contextoAdulto = $this->ajustarGeneroPorTalla($actual, $valor, $contextoAdulto);
    }

    /**
     * El tipo (dama/caballero/niño) con alcance definido:
     *  - Un tipo dicho en la linea manda sobre el heredado o el elegido en pantalla; vale para las prendas siguientes del mismo
     *    dictado hasta que se diga otro (heredado, marcado como tal).
     *  - Dos tipos distintos para la misma prenda, sin «no», se preguntan.
     *  - Tras una prenda completa, un tipo seguido de OTRO color está entre dos prendas: es de la siguiente si ella no dice su
     *    tipo y hubo pausa antes; de la anterior si la pausa fue despues o si la siguiente ya dice el suyo; sin pausas, la
     *    anterior queda en duda (se pregunta) y la siguiente lo toma. Nunca se cambia en silencio el tipo de la anterior.
     */
    private function aplicarGenero(array &$actual, callable $cerrar, callable $palabra, array $t, int $inicio, int $i, string $frag, string $valor, ?string &$contextoAdulto, int $ultimoFin): void
    {
        // El crop top dicho en ESTA linea ya es de dama: «dama» no agrega nada; otro tipo es de la prenda siguiente (si sigue
        // otra) o una contradicción (si no).
        if ($actual['linea'] === 'TP' && ! isset($actual['heredado']['linea'])) {
            if ($valor === 'D' && ! ($this->completa($actual) && $this->siguenPiezas($t, $i + 1))) {
                $palabra(true);

                return;
            }
            if (! ($this->completa($actual) && $this->siguenPiezas($t, $i + 1))) {
                $palabra();
                $actual['tipos_dobles'] = array_values(array_unique(['crop top', $frag]));

                return;
            }
            $cerrar([]);
        }

        $propioExplicito = $actual['genero'] !== null && $actual['genero_explicito'] && ! isset($actual['heredado']['genero']);

        if ($propioExplicito && $valor === $actual['genero']) {
            $palabra(true);

            return;
        }

        if ($propioExplicito && $this->completa($actual)) {
            if (! $this->siguenPiezas($t, $i + 1)) {
                // «negro dama M 2 caballero»: un tipo distinto al final, sin otra prenda despues → se pregunta.
                $palabra();
                $actual['tipos_dobles'] = array_values(array_unique([...($actual['tipos_dobles'] ?: [$actual['genero_dicho']]), $frag]));

                return;
            }
            // «negro dama M 2 caballero L 3»: otra prenda del mismo color con otro tipo.
            $cerrar(['color']);
        } elseif ($propioExplicito) {
            // «terracota dama caballero XL 4»: dos tipos para la misma prenda.
            $palabra();
            $actual['tipos_dobles'] = array_values(array_unique([...($actual['tipos_dobles'] ?: [$actual['genero_dicho']]), $frag]));

            return;
        } elseif ($this->completa($actual) && ($this->colorPropio($actual) || $actual['sku_dicho'] !== null)
            && ($siguiente = $this->siguienteProducto($t, $i + 1)) !== null) {
            // Solo hay «dos prendas» si la anterior ya tiene su color: «una XL dama roja» es UNA prenda (roja la completa).
            $pausaAntes = $this->haySeparador($t, $ultimoFin + 1, $inicio - 1);
            $destino = match (true) {
                $siguiente['con_tipo'] => 'anterior',
                $pausaAntes && ! $siguiente['pausa'] => 'siguiente',
                ! $pausaAntes && $siguiente['pausa'] => 'anterior',
                default => 'duda',
            };

            if ($destino !== 'anterior') {
                if ($destino === 'duda') {
                    $actual['tipo_dudoso'] = $frag;
                    $actual['genero'] = null;
                    $actual['genero_explicito'] = false;
                    $actual['genero_dicho'] = null;
                    unset($actual['heredado']['genero']);
                }
                $cerrar([]);
            }
        }

        // Decir el tipo es hablar de la camiseta (DZ): el crop top heredado de la prenda anterior no sigue
        // («crop top negro M 5 y roja dama M 2» → la roja es camiseta de dama; propiedad «otra prenda no cambia», 2026-11-26).
        if (isset($actual['heredado']['linea'])) {
            $actual['linea'] = 'DZ';
            unset($actual['heredado']['linea']);
            if ($actual['talla_asumida'] || isset($actual['heredado']['talla'])) {
                $actual['talla'] = null;
                $actual['talla_fuente'] = null;
                $actual['talla_asumida'] = false;
                unset($actual['heredado']['talla']);
            }
        }

        $actual['palabras'][] = $frag;
        $actual['ancla'] = $i;
        $actual['genero'] = $valor;
        $actual['genero_explicito'] = true;
        $actual['genero_dicho'] = $frag;
        unset($actual['heredado']['genero']);
        if ($valor !== 'N') {
            $contextoAdulto = $valor;
        }
    }

    /**
     * Despues del paso 1: descarta lineas sin datos (sus palabras sueltas pasan a la linea vecina para no perderlas) y une la
     * cantidad huerfana a su linea («negro dama M 2 unidades 3 unidades» = dos cantidades del mismo producto → se pregunta).
     *
     * @param  list<array<string, mixed>>  $lineas
     * @return list<array<string, mixed>>
     */
    private function depurar(array $lineas): array
    {
        $con = [];
        $sueltasPendientes = [];

        foreach ($lineas as $l) {
            if (! $this->tieneDatos($l)) {
                array_push($sueltasPendientes, ...$l['sueltas']);
                if ($con !== [] && ($l['correccion'] !== null || $l['negado'] !== null)) {
                    $con[count($con) - 1]['correccion_dudosa'][] = $l['correccion']['marca'] ?? $l['negado']['frag'];
                }

                continue;
            }
            if ($sueltasPendientes !== []) {
                $destino = $con === [] ? null : count($con) - 1;
                if ($destino === null) {
                    $l['sueltas'] = [...$sueltasPendientes, ...$l['sueltas']];
                } else {
                    array_push($con[$destino]['sueltas'], ...$sueltasPendientes);
                }
                $sueltasPendientes = [];
            }
            $con[] = $l;
        }

        if ($sueltasPendientes !== []) {
            if ($con === []) {
                // Nada reconocible: una linea que muestra lo que no se entendio (nunca una respuesta vacia en silencio).
                $vacia = $this->nueva();
                $vacia['sueltas'] = $sueltasPendientes;
                $vacia['palabras'] = $sueltasPendientes;
                $con[] = $vacia;
            } else {
                array_push($con[count($con) - 1]['sueltas'], ...$sueltasPendientes);
            }
        }

        $limpias = [];
        foreach ($con as $l) {
            $huerfana = $limpias !== [] && $l['cantidad'] !== null && $l['numeros'] === [] && $l['uno'] === null && $l['tallas_invalidas'] === []
                && $l['sku_dicho'] === null && $l['skus_invalidos'] === []
                && ($l['color'] === null && $l['candidatos'] === [] || isset($l['heredado']['color']))
                && ($l['talla'] === null || isset($l['heredado']['talla']))
                && ! ($l['linea'] === 'TP' && ! isset($l['heredado']['linea']));
            if ($huerfana) {
                $limpias[count($limpias) - 1]['cantidades_extra'][] = $l['cantidad_fuente'];
                $limpias[count($limpias) - 1]['r_fin'] = max($limpias[count($limpias) - 1]['r_fin'] ?? -1, $l['r_fin'] ?? -1);
                array_push($limpias[count($limpias) - 1]['palabras'], ...$l['palabras']);

                continue;
            }
            $limpias[] = $l;
        }

        return $limpias;
    }

    /**
     * Paso 2 en orden. La cantidad dicha ANTES del producto siguiente («negro M 10, 5 blanco M») solo pasa a ese producto si
     * esa es la unica lectura de las dos lineas; si no, vuelve como numero sin asignar de la linea donde se dijo.
     *
     * @param  list<array<string, mixed>>  $lineas
     * @return list<array<string, mixed>>
     */
    private function asignarLineas(array $lineas): array
    {
        $salida = [];
        $arrastre = [];

        foreach ($lineas as $k => $l) {
            $propios = $l['numeros'];

            if ($arrastre !== []) {
                $conArrastre = $this->asignar($l, array_merge($arrastre, $propios));

                if ($conArrastre['unica_completa']) {
                    // El texto original acompaña al numero: sale de la linea anterior y encabeza esta.
                    $dichos = implode(' ', array_column($arrastre, 'texto'));
                    $salida[$k - 1]['texto'] = trim(preg_replace('/\s*'.preg_quote($dichos, '/').'(?!.*'.preg_quote($dichos, '/').')/', '', $salida[$k - 1]['texto'], 1) ?? $salida[$k - 1]['texto']);
                    // Lo mismo con el fragmento original: el numero sale de la anterior y encabeza esta.
                    $desde = min(array_column($arrastre, 'ini'));
                    $salida[$k - 1]['texto_original'] = $this->fragmentoOriginal($lineas[$k - 1]['r_ini'] ?? -1, $desde - 1) ?? $salida[$k - 1]['texto_original'];
                    $l['r_ini'] = min($l['r_ini'] ?? $desde, $desde);
                    $salida[] = ['texto' => trim($dichos.' '.implode(' ', $l['palabras']))] + $this->salida($l, $conArrastre);
                    $arrastre = [];

                    continue;
                }

                // No es lectura unica: el numero no viaja; queda como problema de la linea anterior.
                $salida[$k - 1] = $this->conSobrantes($salida[$k - 1], $arrastre);
                $arrastre = [];
            }

            $asignacion = $this->asignar($l, $propios);

            if (! $asignacion['unica_completa'] && isset($lineas[$k + 1])) {
                // ¿Los numeros finales (despues de la ultima pieza segura) son la cantidad del producto siguiente?
                $finales = count(array_filter($propios, fn ($x) => $x['ini'] > $l['ancla']));

                for ($s = 1; $s <= $finales; $s++) {
                    $parcial = $this->asignar($l, array_slice($propios, 0, count($propios) - $s));

                    if ($parcial['unica_completa']) {
                        $asignacion = $parcial;
                        $arrastre = array_slice($propios, count($propios) - $s);
                        break;
                    }
                }
            }

            $salida[] = $this->salida($l, $asignacion);
        }

        if ($arrastre !== []) {
            $ultima = count($salida) - 1;
            $salida[$ultima] = $this->conSobrantes($salida[$ultima], $arrastre);
        }

        return $salida;
    }

    /**
     * Reparte los numeros sueltos de una linea entre la talla que falte y la cantidad. Solo se acepta una lectura que use TODOS
     * los numeros; con dos o mas lecturas posibles se pregunta.
     *
     * @param  list<array<string, mixed>>  $nums
     * @return array{unica_completa: bool, talla: ?string, talla_fuente: ?string, cantidad: ?int, cantidad_fuente: ?string,
     *               sin_asignar: list<string>, opciones: list<array<string, mixed>>, cantidad_invalida: ?string}
     */
    private function asignar(array $l, array $nums): array
    {
        $base = ['unica_completa' => false, 'talla' => null, 'talla_fuente' => null, 'cantidad' => null, 'cantidad_fuente' => null,
            'sin_asignar' => [], 'opciones' => [], 'cantidad_invalida' => null];
        $faltaTalla = $l['talla'] === null;
        $faltaCantidad = $l['cantidad'] === null && $l['uno'] === null;

        if ($nums === []) {
            return ['unica_completa' => ! $faltaTalla && ! $faltaCantidad] + $base;
        }

        // Lecturas de talla (cada una usa uno o dos numeros) — solo si la linea admite niño: alias pegados (24, 68, 1012, 1416),
        // pares («6.8», «6 y 8») y el codigo suelto del inicio del rango (2, 6, 10, 14: «rojo 10 niño» → ¿talla 10-12 o 10
        // unidades?). El codigo suelto es la lectura mas debil: nunca parte un par que forma rango ni gana a un alias o un par.
        $tallas = [['usa' => [], 'codigo' => null, 'fuente' => null, 'debil' => false, 'suelto' => false]];
        if ($faltaTalla && $this->admiteNino($l)) {
            $hayPar = false;
            foreach ($nums as $j => $x) {
                $sig = $nums[$j + 1] ?? null;
                $hayPar = $hayPar || ($sig !== null && $x['clase'] === 'entero' && $sig['clase'] === 'entero' && $this->esRango($x['valor'], $sig['valor']))
                    || ($x['clase'] === 'compuesto' && $this->esRango($x['a'], $x['b']));
            }
            foreach ($nums as $j => $x) {
                if ($x['clase'] === 'entero' && isset(self::ALIAS_PEGADOS[(string) $x['valor']])) {
                    $tallas[] = ['usa' => [$j], 'codigo' => self::ALIAS_PEGADOS[(string) $x['valor']], 'fuente' => $x['texto'], 'debil' => false, 'suelto' => false];
                }
                if (! $hayPar && $x['clase'] === 'entero' && isset(self::RANGOS_NINO[$x['valor']])) {
                    $tallas[] = ['usa' => [$j], 'codigo' => (string) $x['valor'], 'fuente' => $x['texto'], 'debil' => false, 'suelto' => true];
                }
                if ($x['clase'] === 'compuesto' && $this->esRango($x['a'], $x['b'])) {
                    $tallas[] = ['usa' => [$j], 'codigo' => (string) $x['a'], 'fuente' => $x['texto'], 'debil' => true, 'suelto' => false];
                }
                $sig = $nums[$j + 1] ?? null;
                if ($sig !== null && $x['clase'] === 'entero' && $sig['clase'] === 'entero' && $this->esRango($x['valor'], $sig['valor'])) {
                    $tallas[] = ['usa' => [$j, $j + 1], 'codigo' => (string) $x['valor'], 'fuente' => $x['texto'].' '.$sig['texto'], 'debil' => true, 'suelto' => false];
                }
            }
        }

        // Lecturas de cantidad: un numero entero de 1 a 9999; cualquier otro numero es una cantidad invalida.
        $cantidades = [['usa' => [], 'valor' => null, 'invalida' => null, 'fuente' => null]];
        if ($faltaCantidad) {
            foreach ($nums as $j => $x) {
                $valida = $x['clase'] === 'entero' && $x['valor'] >= 1 && $x['valor'] <= self::CANTIDAD_MAXIMA;
                $cantidades[] = ['usa' => [$j], 'valor' => $valida ? $x['valor'] : null, 'invalida' => $valida ? null : $x['texto'], 'fuente' => $x['texto']];
            }
        }

        $lecturas = [];
        foreach ($tallas as $tt) {
            foreach ($cantidades as $cc) {
                $usa = array_merge($tt['usa'], $cc['usa']);
                if (count(array_unique($usa)) !== count($usa) || count($usa) !== count($nums)) {
                    continue;
                }
                $completa = (! $faltaTalla || $tt['codigo'] !== null) && (! $faltaCantidad || $cc['valor'] !== null);
                // «6.8», «6, 8» y «6 y 8» solo son talla cuando el resto de la linea queda completo con ellos.
                if ($tt['debil'] && ! $completa) {
                    continue;
                }
                $lecturas[] = ['t' => $tt, 'c' => $cc, 'completa' => $completa];
            }
        }

        // Una cantidad invalida no es una lectura razonable si hay otra que use los mismos numeros.
        $sinInvalida = array_values(array_filter($lecturas, fn ($x) => $x['c']['invalida'] === null));
        if ($sinInvalida !== []) {
            $lecturas = $sinInvalida;
        }

        // El codigo suelto no compite con un alias o un par que completan la linea («roja 68 niño 10» = 6-8 × 10).
        $fuerte = array_filter($lecturas, fn ($x) => $x['completa'] && $x['t']['codigo'] !== null && ! $x['t']['suelto']);
        if ($fuerte !== []) {
            $lecturas = array_values(array_filter($lecturas, fn ($x) => ! ($x['completa'] && $x['t']['suelto'])));
        }

        // Dos lecturas con el mismo resultado solo salen de numeros iguales («68 68»): puede ser eco del dictado → se confirma.

        // Las lecturas con talla van primero (la pantalla las ofrece en ese orden).
        usort($lecturas, fn ($x, $y) => ($y['t']['codigo'] !== null) <=> ($x['t']['codigo'] !== null));
        $completas = array_values(array_filter($lecturas, fn ($x) => $x['completa']));
        $elegida = count($completas) === 1 ? $completas[0] : ($completas === [] && count($lecturas) === 1 ? $lecturas[0] : null);

        if ($elegida !== null) {
            return [
                'unica_completa' => $elegida['completa'],
                'talla' => $elegida['t']['codigo'],
                'talla_fuente' => $elegida['t']['fuente'],
                'cantidad' => $elegida['c']['valor'],
                'cantidad_fuente' => $elegida['c']['fuente'],
                'cantidad_invalida' => $elegida['c']['invalida'],
            ] + $base;
        }

        if ($lecturas !== []) {
            $opciones = [];
            foreach ($completas !== [] ? $completas : $lecturas as $x) {
                $o = [
                    'talla' => $x['t']['codigo'] ?? $l['talla'],
                    'talla_etiqueta' => self::etiquetaTalla($x['t']['codigo'] ?? $l['talla']),
                    'cantidad' => $x['c']['valor'] ?? ($l['cantidad']['valor'] ?? null),
                    'fuente' => trim(($x['t']['fuente'] ?? '').' '.($x['c']['fuente'] ?? '')),
                ];
                // La pantalla muestra cada resultado una vez («68 68» → un solo botón «talla 6-8 y 68 unidades» para confirmar).
                $opciones[json_encode([$o['talla'], $o['cantidad']])] ??= $o;
            }

            return ['opciones' => array_values($opciones), 'sin_asignar' => array_column($nums, 'texto')] + $base;
        }

        return ['sin_asignar' => array_column($nums, 'texto')] + $base;
    }

    /** @return array{0: ?array{0: string, 1: mixed}, 1: int} la pieza que empieza en $i y cuantos tokens ocupa */
    private function pieza(array $t, int $i, array $actual): array
    {
        $tok = $t[$i];
        $sig = $t[$i + 1] ?? null;
        $sig2 = $t[$i + 2] ?? null;

        if ($tok === '|') {
            return [null, 1];
        }

        // SKU dictado («124H-DZ-M», «124h dz m»): color + tipo + linea + talla de un solo golpe; o algo con forma de codigo
        // que no es del catalogo (se marca, nunca se ignora).
        if (($sku = $this->skuEn($t, $i)) !== null) {
            return [[$sku[0], $sku[1]], $sku[2]];
        }

        if (in_array($tok, self::MARCAS_CORRECCION, true)) {
            return [['correccion', $tok], 1];
        }
        if ($tok === 'o' && $sig === 'sea') {
            return [['correccion', 'o sea'], 2];
        }

        // «talla …»: el marcador manda (talla 6 = 6-8, talla 68 = 6-8, talla 6 8, talla 6.8, talla S, talla ese, talla doble equis ele).
        if ($tok === 'talla' && $sig !== null) {
            if (($despues = $this->tallaTrasMarcador($t, $i + 1)) !== null) {
                return [['talla', $despues[0]], 1 + $despues[1]];
            }
            if ($this->numero($sig) !== null) {
                return [['talla_invalida', $sig], 2];
            }
        }

        // «cantidad …», «x 10», «por 10»: el marcador manda.
        if (in_array($tok, ['cantidad', 'x', 'por'], true) && $sig !== null && ($num = $this->numero($sig)) !== null) {
            return [['cantidad', $this->valorCantidad($num, $sig)], 2];
        }

        if (($num = $this->numero($tok)) !== null) {
            // «10 unidades», «10 camisetas».
            if (in_array($sig, self::UNIDADES, true)) {
                return [['cantidad', $this->valorCantidad($num, $tok)], 2];
            }

            // Rango explicito: «6-8», «6 a 8»; «6 8» solo si la linea admite niño y no esta esperando su cantidad.
            if ($num['clase'] === 'rango' && $this->esRango($num['a'], $num['b'])) {
                return [['talla', (string) $num['a']], 1];
            }
            if ($num['clase'] === 'entero' && in_array($sig, ['a', 'al'], true) && ($fin = $this->numero((string) $sig2)) !== null
                && $fin['clase'] === 'entero' && $this->esRango($num['valor'], $fin['valor'])) {
                return [['talla', (string) $num['valor']], 3];
            }
            if ($num['clase'] === 'entero' && ($fin = $this->numero((string) $sig)) !== null && $fin['clase'] === 'entero'
                && $this->esRango($num['valor'], $fin['valor'])) {
                return $this->parAdmitido($actual) ? [['talla', (string) $num['valor']], 2] : [['par_suelto', [$num, $fin]], 2];
            }

            return [['numero', $num], 1];
        }

        if (($letra = $this->tallaLetra($t, $i)) !== null) {
            return [['talla', $letra[0]], $letra[1]];
        }

        // El navegador escribe «ese», «eme», «ele» por S, M, L. Sin «talla» delante cuentan si la linea aun no tiene talla y no
        // viene una «talla …» explicita enseguida («ese rojo talla M»: ahi «ese» es la palabra). La pantalla lo marca con aviso.
        if (in_array($tok, ['ese', 'eme', 'ele'], true)
            && ($actual['talla'] === null || isset($actual['heredado']['talla']))
            && ! in_array('talla', array_slice($t, $i + 1, 4), true)
            && ($sig === null || $sig !== 'color')) {
            return [['talla', self::TALLAS_DELETREADAS[$tok]], 1];
        }

        if ($tok === 'unica') {
            return [['talla', 'U'], 1];
        }

        if (in_array($tok, ['un', 'una', 'uno'], true) && $sig !== null) {
            $antesDeColor = $this->colorEn($t, $i + 1) !== null || ($sig === 'de' && isset($t[$i + 2]) && $this->colorEn($t, $i + 2) !== null);

            if ($antesDeColor || in_array($sig, self::UNO_CON, true) || isset(self::GENEROS[$sig])
                // «una XL dama roja», «una ESE …», «una equis ele …», «una 2XL …»: artículo + talla = 1 unidad.
                || in_array($sig, ['ese', 'eme', 'ele'], true) || $this->tallaLetra($t, $i + 1) !== null
                || ($sig === 'de' && isset($t[$i + 2]) && (in_array($t[$i + 2], ['ese', 'eme', 'ele'], true) || isset(self::GENEROS[$t[$i + 2]])))) {
                return [['uno', ['antes_de_color' => $antesDeColor]], 1];
            }
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

        if (($color = $this->colorEn($t, $i)) !== null) {
            return [['color', $color[0]], $color[1]];
        }

        return [null, 1];
    }

    /**
     * SKU del codigo unico (Doc. 33): «124H-DZ-M», «124h-dz», «124h dz m». Devuelve ['sku', valor, tokens] con el texto del
     * codigo tal como se dijo (el resolutor lo busca EXACTO), o ['sku_invalido', texto, tokens] si tiene forma de codigo pero
     * no es del catalogo (otro color, otra tela/linea, talla inexistente). null si no parece un codigo.
     *
     * @return array{0: string, 1: mixed, 2: int}|null
     */
    private function skuEn(array $t, int $i): ?array
    {
        $tok = $t[$i];
        $consumidos = 1;
        $tallaDicha = null;

        if (preg_match('/^(\d{3})([dhn])-(dz|tp)(?:-([a-z0-9]{1,3}))?$/', $tok, $m)) {
            $tallaDicha = ($m[4] ?? '') !== '' ? $m[4] : null;
        } elseif (preg_match('/^(\d{3})([dhn])$/', $tok, $m) && in_array($t[$i + 1] ?? null, ['dz', 'tp'], true)) {
            $m[3] = $t[$i + 1];
            $consumidos = 2;
            if (($cand = $t[$i + 2] ?? null) !== null && $this->tallaDeSku($cand) !== null) {
                $tallaDicha = $cand;
                $consumidos = 3;
            }
        } elseif (preg_match('/^\d{3}[a-z]{1,2}-[a-z0-9]{1,4}(?:-[a-z0-9]{1,4})?$/', $tok)) {
            return ['sku_invalido', strtoupper($tok), 1];
        } else {
            return null;
        }

        $texto = strtoupper(implode('-', array_filter([$m[1].$m[2], $m[3], $tallaDicha], fn ($x) => $x !== null)));
        $color = $this->codigos[(int) $m[1]] ?? null;
        $talla = $tallaDicha === null ? null : $this->tallaDeSku($tallaDicha);

        if ($color === null || ($tallaDicha !== null && $talla === null)) {
            return ['sku_invalido', $texto, $consumidos];
        }

        return ['sku', ['color' => $color, 'genero' => strtoupper($m[2]), 'linea' => strtoupper($m[3]), 'talla' => $talla, 'sku' => $texto], $consumidos];
    }

    /** Clave para reconocer la misma pieza dicha dos veces seguidas (los numeros no: «2 2» se revisa). */
    private function clavePieza(string $tipo, mixed $valor): ?string
    {
        return match ($tipo) {
            'color' => 'color:'.implode('|', (array) $valor),
            'genero' => 'genero:'.$valor,
            'talla' => 'talla:'.$valor,
            'linea' => 'linea:'.$valor,
            'uno' => 'uno',
            default => null,
        };
    }

    /** Campo que corrige una pieza: lo que «no» / «mejor» puede reemplazar. */
    private function familia(string $tipo): ?string
    {
        return match ($tipo) {
            'color' => 'color',
            'genero' => 'genero',
            'talla', 'talla_invalida' => 'talla',
            'numero', 'cantidad', 'uno' => 'cantidad',
            'linea' => 'linea',
            default => null,
        };
    }

    /**
     * Aplica una corrección: la pieza nueva reemplaza a la del mismo campo que la linea ya tenia. Devuelve lo que se reemplazo
     * (para mostrarlo) o null si no habia nada de ese campo que corregir.
     */
    private function reemplazar(array &$l, string $tipo, mixed $valor, string $frag, int $inicio, int $i): ?string
    {
        if ($tipo === 'color' && $this->colorPropio($l)) {
            $antes = (string) $l['color_dicho'];
            $l['color'] = count($valor) === 1 ? $valor[0] : null;
            $l['candidatos'] = count($valor) === 1 ? [] : $valor;
            $l['color_dicho'] = $frag;

            return $antes;
        }

        if ($tipo === 'genero' && $l['genero'] !== null && ! isset($l['heredado']['genero'])) {
            $antes = (string) ($l['genero_dicho'] ?? $l['genero']);
            $l['genero'] = $valor;
            $l['genero_explicito'] = true;
            $l['genero_dicho'] = $frag;
            $l['tipos_dobles'] = [];

            return $antes;
        }

        if ($tipo === 'talla' && $l['talla'] !== null && ! isset($l['heredado']['talla'])) {
            $antes = (string) $l['talla_fuente'];
            $l['talla'] = $valor;
            $l['talla_fuente'] = $frag;
            $l['talla_asumida'] = false;
            $l['tallas_dobles'] = [];

            return $antes;
        }

        if (in_array($tipo, ['numero', 'cantidad', 'uno'], true)) {
            $nuevo = match ($tipo) {
                'numero' => ['cantidad' => $this->valorCantidad($valor, $frag)],
                'cantidad' => ['cantidad' => $valor],
                'uno' => ['uno' => $frag],
            };

            if ($l['numeros'] !== []) {
                $antes = (string) end($l['numeros'])['texto'];
                array_pop($l['numeros']);
            } elseif ($l['cantidad'] !== null) {
                $antes = (string) $l['cantidad_fuente'];
            } elseif ($l['uno'] !== null) {
                $antes = (string) $l['uno'];
            } else {
                return null;
            }

            // La cantidad corregida queda firme (con su texto), sin competir con la anterior.
            $l['cantidad'] = $nuevo['cantidad'] ?? null;
            $l['cantidad_fuente'] = isset($nuevo['cantidad']) ? $frag : null;
            $l['uno'] = $nuevo['uno'] ?? null;
            $l['cantidades_extra'] = [];

            return $antes;
        }

        return null;
    }

    /** La linea tiene producto propio (no solo cantidad o datos heredados). */
    private function tieneProducto(array $l): bool
    {
        return $this->colorPropio($l)
            || ($l['talla'] !== null && ! isset($l['heredado']['talla']))
            || ($l['genero'] !== null && $l['genero_explicito'] && ! isset($l['heredado']['genero']))
            || ($l['linea'] === 'TP' && ! isset($l['heredado']['linea']))
            || $l['sku_dicho'] !== null || $l['skus_invalidos'] !== [] || $l['tallas_invalidas'] !== [];
    }

    private function colorPropio(array $l): bool
    {
        return ($l['color'] !== null || $l['candidatos'] !== []) && ! isset($l['heredado']['color']);
    }

    /** ¿Queda alguna pieza (color, talla, cantidad, tipo, SKU) despues de $j? */
    private function siguenPiezas(array $t, int $j): bool
    {
        $vacia = $this->nueva();
        for ($k = $j, $n = count($t); $k < $n; $k++) {
            [$p, $c] = $this->pieza($t, $k, $vacia);
            if ($p !== null && $p[0] !== 'correccion') {
                return true;
            }
            $k += max(1, $c) - 1;
        }

        return false;
    }

    /**
     * Mira la prenda que empieza despues de un tipo: null si lo siguiente no es otra prenda (un color o un SKU, con o sin
     * cantidad delante). Si lo es: si hubo pausa («,» «.» «y») antes de ella y si dice su propio tipo.
     *
     * @return array{pausa: bool, con_tipo: bool}|null
     */
    private function siguienteProducto(array $t, int $j): ?array
    {
        $pausa = false;
        $vacia = $this->nueva();

        for ($k = $j, $n = count($t); $k < $n; $k++) {
            if (in_array($t[$k], ['|', 'y'], true)) {
                $pausa = true;

                continue;
            }
            [$p, $c] = $this->pieza($t, $k, $vacia);
            if ($p === null) {
                continue;
            }
            if (in_array($p[0], ['numero', 'uno', 'cantidad'], true)) {
                $k += max(1, $c) - 1;

                continue;
            }
            if (! in_array($p[0], ['color', 'sku', 'sku_invalido'], true)) {
                return null;
            }

            $conTipo = $p[0] !== 'color';
            for ($m = $k + max(1, $c); ! $conTipo && $m < $n; $m++) {
                [$q, $d] = $this->pieza($t, $m, $vacia);
                if ($q !== null && in_array($q[0], ['color', 'sku', 'sku_invalido'], true)) {
                    break;
                }
                if ($q !== null && in_array($q[0], ['genero', 'linea'], true)) {
                    $conTipo = true;
                }
                $m += max(1, $d) - 1;
            }

            return ['pausa' => $pausa, 'con_tipo' => $conTipo];
        }

        return null;
    }

    private function haySeparador(array $t, int $desde, int $hasta): bool
    {
        for ($k = max(0, $desde); $k <= $hasta; $k++) {
            if (in_array($t[$k] ?? null, ['|', 'y'], true)) {
                return true;
            }
        }

        return false;
    }

    /** Codigo de talla del SKU en minuscula («m», «xl», «6», «u») → codigo oficial, o null si no es una talla del catalogo. */
    private function tallaDeSku(string $x): ?string
    {
        if (in_array($x, ['2', '6', '10', '14'], true)) {
            return $x;
        }

        return $x === 'u' ? 'U' : (in_array($x, ['s', 'm', 'l', 'xl', 'xxl'], true) ? strtoupper($x) : null);
    }

    private function tallaTrasMarcador(array $t, int $i): ?array
    {
        $tok = $t[$i];

        if (($letra = $this->tallaLetra($t, $i)) !== null) {
            return $letra;
        }
        if (isset(self::TALLAS_DELETREADAS[$tok])) {
            return [self::TALLAS_DELETREADAS[$tok], 1];
        }

        $num = $this->numero($tok);
        if ($num === null) {
            return null;
        }

        if ($num['clase'] === 'entero') {
            $valor = $num['valor'];
            if (isset(self::RANGOS_NINO[$valor])) {
                // «talla 6 8», «talla 6 a 8», «talla 6 y 8»
                $sig = $t[$i + 1] ?? null;
                $j = in_array($sig, ['a', 'al', 'y'], true) ? $i + 2 : $i + 1;
                $fin = $this->numero((string) ($t[$j] ?? ''));
                if ($fin !== null && $fin['clase'] === 'entero' && $this->esRango($valor, $fin['valor'])) {
                    return [(string) $valor, $j - $i + 1];
                }

                // «talla 6» = 6-8: equivalencia unica confirmada en el catalogo (codigo del SKU ↔ grupo_de_edad_ninos).
                return [(string) $valor, 1];
            }
            if (isset(self::ALIAS_PEGADOS[(string) $valor])) {
                return [self::ALIAS_PEGADOS[(string) $valor], 1];
            }

            return null;
        }

        if (in_array($num['clase'], ['compuesto', 'rango'], true) && $this->esRango($num['a'], $num['b'])) {
            return [(string) $num['a'], 1];
        }

        return null;
    }

    /** @return ?array{0: string, 1: int} talla por letra (XXL nunca se degrada a XL) */
    private function tallaLetra(array $t, int $i): ?array
    {
        $tok = $t[$i];
        $sig = $t[$i + 1] ?? null;
        $sig2 = $t[$i + 2] ?? null;
        $esL = fn ($x) => in_array($x, ['l', 'ele'], true);
        $esX = fn ($x) => in_array($x, ['x', 'equis'], true);

        // XXL: «doble equis ele», «doble x l», «doble xl», «xx l», «equis equis ele», «extra extra grande».
        if ($tok === 'doble') {
            if ($sig === 'xl') {
                return ['XXL', 2];
            }
            if ($esX($sig) && $esL($sig2)) {
                return ['XXL', 3];
            }
        }
        if ($tok === 'xx' && $esL($sig)) {
            return ['XXL', 2];
        }
        if ($esX($tok) && $esX($sig) && $esL($sig2)) {
            return ['XXL', 3];
        }
        if ($tok === 'extra' && $sig === 'extra' && in_array($sig2, ['grande', 'large'], true)) {
            return ['XXL', 3];
        }

        if ($esX($tok) && $esL($sig)) {
            return ['XL', 2];
        }
        if ($tok === 'extra' && in_array($sig, ['grande', 'large'], true)) {
            return ['XL', 2];
        }
        if (isset(self::TALLAS_LETRA[$tok])) {
            return [self::TALLAS_LETRA[$tok], 1];
        }

        return null;
    }

    /** @return ?array{0: list<string>, 1: int} colores y tokens: la frase mas larga del catalogo que empiece aqui (forma base: «roja» = «rojo») */
    private function colorEn(array $t, int $i): ?array
    {
        // «de»/«del» intermedios no cuentan: «verde de petróleo» = «Verde Petróleo» (el navegador los agrega).
        $relleno = ['de', 'del'];

        for ($largo = min($this->maxPalabrasColor + 2, count($t) - $i); $largo >= 1; $largo--) {
            $trozo = array_slice($t, $i, $largo);
            if (in_array('|', $trozo, true) || in_array($trozo[0], $relleno, true) || in_array(end($trozo), $relleno, true)) {
                continue;
            }
            $clave = implode(' ', array_map(fn ($p) => $this->base($p), array_values(array_diff($trozo, $relleno))));
            if (isset($this->frases[$clave])) {
                return [$this->frases[$clave], $largo];
            }
        }

        return null;
    }

    /**
     * Clasifica un token numerico: entero («68»), rango con guion («6-8»), compuesto («6.8», «6,8», «6:08», «2.5») o negativo.
     *
     * @return ?array{clase: string, valor?: int, a?: int, b?: int}
     */
    private function numero(string $tok): ?array
    {
        if ($tok !== '' && ctype_digit($tok)) {
            return ['clase' => 'entero', 'valor' => (int) $tok];
        }
        if (preg_match('/^(\d+)-(\d+)$/', $tok, $m)) {
            return ['clase' => 'rango', 'a' => (int) $m[1], 'b' => (int) $m[2]];
        }
        if (preg_match('/^(\d+)\.(\d+)$/', $tok, $m)) {
            return ['clase' => 'compuesto', 'a' => (int) $m[1], 'b' => (int) $m[2]];
        }
        if (preg_match('/^-\d+(\.\d+)?$/', $tok)) {
            return ['clase' => 'negativo'];
        }

        return null;
    }

    /** Cantidad con marcador: un entero de 1 a 9999, o invalida conservando lo dicho. */
    private function valorCantidad(array $num, string $texto): array
    {
        $valida = $num['clase'] === 'entero' && $num['valor'] >= 1 && $num['valor'] <= self::CANTIDAD_MAXIMA;

        return ['valor' => $valida ? $num['valor'] : null, 'invalida' => $valida ? null : $texto];
    }

    /** La linea admite una talla de niño por alias: sin tipo, Niño, o un tipo heredado (no dicho en ESTA linea). */
    private function admiteNino(array $l): bool
    {
        if ($l['linea'] === 'TP') {
            return false;
        }

        if ($l['genero'] === null && in_array($this->tipoDefecto, ['D', 'H', 'TP'], true)) {
            return false;
        }

        return $l['genero'] === null || $l['genero'] === 'N' || ! $l['genero_explicito'] || isset($l['heredado']['genero']);
    }

    /**
     * «6 8» como talla: la linea admite niño y no esta esperando la cantidad de una talla de adulto ya dicha
     * («negro dama M 2 4» no crea una linea fantasma de Niño 2-4).
     */
    private function parAdmitido(array $l): bool
    {
        if (! $this->admiteNino($l)) {
            return false;
        }

        $tallaAdulto = $l['talla'] !== null && ! ctype_digit((string) $l['talla']) && ! isset($l['heredado']['talla']);

        return ! ($tallaAdulto && $l['cantidad'] === null && $l['uno'] === null && $l['numeros'] === []);
    }

    /** La talla de niño implica Niño (salvo tipo dicho en ESTA linea); una de adulto descarta un Niño heredado. */
    private function ajustarGeneroPorTalla(array &$l, string $talla, ?string $contextoAdulto): ?string
    {
        $esNino = ctype_digit($talla) && isset(self::RANGOS_NINO[(int) $talla]);
        $generoHeredado = isset($l['heredado']['genero']) || ! $l['genero_explicito'];

        if ($esNino && ($l['genero'] === null || $generoHeredado)) {
            $l['genero'] = 'N';
            $l['genero_explicito'] = false;
            unset($l['heredado']['genero']);
        } elseif (! $esNino && $l['genero'] === 'N' && $generoHeredado) {
            // «dama: azul S 10, roja 2-4 5, negra M 3»: la negra vuelve a ser Dama (el ultimo adulto dicho).
            $l['genero'] = $contextoAdulto;
            $l['genero_explicito'] = $contextoAdulto !== null;
            unset($l['heredado']['genero']);
        }

        return $contextoAdulto;
    }

    private function esRango(int $inicio, int $fin): bool
    {
        return (self::RANGOS_NINO[$inicio] ?? null) === $fin;
    }

    /**
     * Minusculas y sin tildes. «2/4», «2 - 4» → «2-4»; «6.8», «6,8», «6:08» se conservan como UN token («6.8», decide el
     * contexto); el resto de la puntuacion queda como separador debil «|» (no parte productos por si solo).
     *
     * @return list<string>
     */
    private function tokens(string $texto): array
    {
        $texto = mb_strtolower(Str::ascii($texto));
        $texto = preg_replace('#(\d)\s*[-/]\s*(\d)#', '$1-$2', $texto) ?? $texto;
        $texto = preg_replace('/(\d)[.,:](\d)/', '$1.$2', $texto) ?? $texto;
        $texto = preg_replace('/(^|\s)-\s*(\d)/', '$1-$2', $texto) ?? $texto;
        $texto = preg_replace('/(?<!\d)[.,:]|[.,:](?!\d)|[;!?\n\r]+/', ' | ', $texto) ?? $texto;
        $texto = preg_replace('/[^a-z0-9|.\- ]+/', ' ', $texto) ?? $texto;

        return array_values(array_filter(preg_split('/\s+/', trim($texto)) ?: [], fn ($t) => ! in_array($t, ['', '-', '.'], true)));
    }

    /** Numeros en palabras → cifras. «un/una/uno» solo cuenta como numero antes de «unidad(es)»/«pieza(s)» o al final de frase. */
    private function numeros(array $t): array
    {
        $salida = [];
        // Para el fragmento original: que tokens de entrada forman cada token de salida («sesenta y ocho» → «68»).
        $this->mapa = [];

        for ($i = 0, $n = count($t); $i < $n; $i++) {
            if ($t[$i] === 'menos' && isset($t[$i + 1])) {
                $j = $i + 1;
                $valor = ctype_digit($t[$j]) ? (int) $t[$j++] : $this->leerNumero($t, $j);
                if ($valor !== null && $j > $i + 1) {
                    $salida[] = '-'.$valor;
                    $this->mapa[] = [$i, $j - 1];
                    $i = $j - 1;

                    continue;
                }
            }

            $j = $i;
            $valor = $this->leerNumero($t, $j);

            if ($valor === null || $j === $i) {
                $salida[] = $t[$i];
                $this->mapa[] = [$i, $i];

                continue;
            }

            $salida[] = (string) $valor;
            $this->mapa[] = [$i, $j - 1];
            $i = $j - 1;
        }

        return $salida;
    }

    /**
     * Los mismos tokens que tokens(), pero con la escritura ORIGINAL (mayusculas, tildes, numeros en palabras): sirven para
     * mostrar lo que se dijo en cada linea. Si no alinean uno a uno con los normalizados, se usan los normalizados.
     *
     * @return list<string>
     */
    private function tokensOriginales(string $texto, int $esperados): ?array
    {
        $t = preg_replace('#(\d)\s*[-/]\s*(\d)#u', '$1-$2', $texto) ?? $texto;
        $t = preg_replace('/(\d)[.,:](\d)/u', '$1.$2', $t) ?? $t;
        $t = preg_replace('/(^|\s)-\s*(\d)/u', '$1-$2', $t) ?? $t;
        $t = preg_replace('/(?<!\d)[.,:]|[.,:](?!\d)|[;!?\n\r]+/u', ' | ', $t) ?? $t;
        $t = preg_replace('/[^\p{L}\p{N}|.\- ]+/u', ' ', $t) ?? $t;
        $tokens = array_values(array_filter(preg_split('/\s+/u', trim($t)) ?: [], fn ($x) => ! in_array($x, ['', '-', '.'], true)));

        return count($tokens) === $esperados ? $tokens : null;
    }

    /** Fragmento original de los tokens de salida [$ini, $fin] (sin separadores). */
    private function fragmentoOriginal(int $ini, int $fin): ?string
    {
        if ($this->originales === null || $ini < 0 || $fin < $ini || ! isset($this->mapa[$ini], $this->mapa[$fin])) {
            return null;
        }

        $trozo = array_slice($this->originales, $this->mapa[$ini][0], $this->mapa[$fin][1] - $this->mapa[$ini][0] + 1);

        return trim(implode(' ', array_filter($trozo, fn ($x) => $x !== '|'))) ?: null;
    }

    /** Lee un numero en palabras desde $j (avanza $j). */
    private function leerNumero(array $t, int &$j): ?int
    {
        $valor = null;

        if (isset(self::CENTENAS_PALABRA[$t[$j] ?? ''])) {
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
            $esArticulo = in_array($palabra, ['un', 'una', 'uno'], true)
                && ! in_array($siguiente, ['unidad', 'unidades', 'und', 'unds', 'uds', 'ud', 'u', 'pieza', 'piezas', '|'], true);
            if (! $esArticulo || $valor !== null) {
                $valor = ($valor ?? 0) + self::UNIDADES_PALABRA[$palabra];
                $j++;
                // «diez y seis» (forma antigua de dieciséis) = 16..19.
                if ($palabra === 'diez' && ($t[$j] ?? null) === 'y' && in_array($t[$j + 1] ?? null, ['seis', 'siete', 'ocho', 'nueve'], true)) {
                    $valor += self::UNIDADES_PALABRA[$t[$j + 1]];
                    $j += 2;
                }
            }
        }

        // «mil», «dos mil», «dos mil quinientos»: queda dentro del rango; el tope 9999 lo valida la cantidad.
        if (($t[$j] ?? null) === 'mil' && ($valor === null || $valor < 10)) {
            $miles = ($valor ?? 1) * 1000;
            $j++;
            $resto = $this->leerNumero($t, $j);

            return $miles + ($resto ?? 0);
        }

        return $valor;
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

        // Clave sin «de»/«del» («palo rosa»), igual que se compara lo dictado.
        $palabras = array_values(array_diff($palabras, ['de', 'del']));
        if ($palabras === []) {
            return;
        }

        $clave = implode(' ', $palabras);
        $this->frases[$clave] = array_values(array_unique([...($this->frases[$clave] ?? []), $color]));
        $this->maxPalabrasColor = max($this->maxPalabrasColor, count($palabras));
    }

    private function nueva(): array
    {
        return ['color' => null, 'candidatos' => [], 'color_dicho' => null, 'genero' => null, 'genero_explicito' => false, 'genero_dicho' => null,
            'linea' => 'DZ', 'talla' => null, 'talla_fuente' => null, 'cantidad' => null, 'cantidad_fuente' => null, 'uno' => null,
            'numeros' => [], 'tallas_invalidas' => [], 'tallas_dobles' => [], 'cantidades_extra' => [], 'palabras' => [], 'heredado' => [], 'ancla' => -1,
            // Jornada 2026-11-26: palabras no usadas, repeticiones, correcciones, tipos y SKU.
            'sueltas' => [], 'repetidas' => [], 'correccion' => null, 'negado' => null, 'correcciones' => [], 'correccion_dudosa' => [],
            'ultima' => null, 'tipos_dobles' => [], 'tipo_dudoso' => null, 'sku_dicho' => null, 'sku_fijo' => null, 'skus_invalidos' => [],
            'sku_conflictos' => [], 'talla_asumida' => false, 'r_ini' => null, 'r_fin' => null];
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
                $nueva['genero_dicho'] = $anterior['genero_dicho'];
                $nueva['heredado']['genero'] = true;
            } elseif ($campo === 'linea' && $anterior['linea'] !== 'DZ') {
                $nueva['linea'] = $anterior['linea'];
                $nueva['heredado']['linea'] = true;
                if ($anterior['talla'] === 'U') {
                    // La talla no se hereda de otra prenda: la Única queda ASUMIDA y la decide el catalogo (si el color
                    // tambien tiene M, se pregunta). Antes pasaba como dicha y se aceptaba sin preguntar.
                    $nueva['talla'] = 'U';
                    $nueva['talla_fuente'] = $anterior['talla_fuente'];
                    $nueva['heredado']['talla'] = true;
                    $nueva['talla_asumida'] = true;
                }
            }
        }

        return $nueva;
    }

    private function tiene(array $l, string $campo): bool
    {
        return $campo === 'color' ? ($l['color'] !== null || $l['candidatos'] !== []) : $l[$campo] !== null;
    }

    /** Completa a efectos de cerrar la linea: tiene talla y algo que pueda ser su cantidad. */
    private function completa(array $l): bool
    {
        return $l['talla'] !== null && ($l['cantidad'] !== null || $l['uno'] !== null || $l['numeros'] !== []);
    }

    /** Una linea cuenta si trae algo propio (no solo lo heredado). */
    private function tieneDatos(array $l): bool
    {
        foreach (['color', 'talla', 'cantidad'] as $campo) {
            if ($this->tiene($l, $campo) && ! isset($l['heredado'][$campo])) {
                return true;
            }
        }

        return $l['numeros'] !== [] || $l['tallas_invalidas'] !== [] || $l['uno'] !== null
            || ($l['linea'] === 'TP' && ! isset($l['heredado']['linea']))
            || $l['sku_dicho'] !== null || $l['skus_invalidos'] !== [] || $l['tipo_dudoso'] !== null
            || ($l['genero'] !== null && $l['genero_explicito'] && ! isset($l['heredado']['genero']) && $l['tipos_dobles'] !== []);
    }

    /** Agrega a una linea ya armada numeros que no encajaron en ninguna parte. */
    private function conSobrantes(array $linea, array $nums): array
    {
        $linea['sin_asignar'] = array_values(array_merge($linea['sin_asignar'], array_column($nums, 'texto')));
        if (! in_array(self::NUMEROS_SIN_ASIGNAR, $linea['problemas'], true)) {
            $linea['problemas'][] = self::NUMEROS_SIN_ASIGNAR;
        }

        return $linea;
    }

    private function salida(array $l, array $a): array
    {
        $problemas = [];
        $detalle = [];

        $talla = $l['talla'] ?? $a['talla'];
        $tallaFuente = $l['talla'] !== null ? $l['talla_fuente'] : $a['talla_fuente'];
        if ($l['talla'] === null && $a['talla'] !== null) {
            $this->ajustarGeneroPorTalla($l, $a['talla'], null);
        }

        // Cantidad: con marcador, «una» expresada, o asignada en el paso 2. Dos cantidades distintas = revisar.
        $firme = $l['cantidad'];
        $cantidad = null;
        $cantidadFuente = null;
        $invalida = null;
        $sinAsignar = $a['sin_asignar'];

        if ($firme !== null && $l['uno'] !== null) {
            $problemas[] = self::CANTIDAD_CONTRADICTORIA;
            $detalle['contradiccion'] = [$l['uno'], $l['cantidad_fuente']];
        } elseif ($firme !== null) {
            $cantidad = $firme['valor'];
            $invalida = $firme['invalida'];
            $cantidadFuente = $l['cantidad_fuente'];
        } elseif ($l['uno'] !== null && $sinAsignar !== [] && $a['opciones'] === []) {
            // «una camiseta … 5»: «una» y un numero suelto compiten por la cantidad.
            $problemas[] = self::CANTIDAD_CONTRADICTORIA;
            $detalle['contradiccion'] = [$l['uno'], ...$sinAsignar];
            $sinAsignar = [];
        } elseif ($l['uno'] !== null) {
            $cantidad = 1;
            $cantidadFuente = $l['uno'];
        } else {
            $cantidad = $a['cantidad'];
            $invalida = $a['cantidad_invalida'];
            $cantidadFuente = $a['cantidad_fuente'];
        }

        if ($l['color'] === null) {
            array_unshift($problemas, $l['candidatos'] === [] ? self::FALTA_COLOR : self::COLOR_AMBIGUO);
        }

        $ambigua = $a['opciones'] !== [];
        if ($ambigua) {
            $problemas[] = self::TALLA_O_CANTIDAD;
            $detalle['dudoso'] = $a['sin_asignar'];
        }
        if ($l['tallas_dobles'] !== []) {
            $problemas[] = self::TALLA_CONTRADICTORIA;
            $detalle['tallas_dobles'] = $l['tallas_dobles'];
            $talla = null;
            $tallaFuente = null;
        } elseif ($talla === null && ! $ambigua) {
            $problemas[] = $l['tallas_invalidas'] !== [] ? self::TALLA_NO_RECONOCIDA : self::FALTA_TALLA;
        }
        if ($l['cantidades_extra'] !== [] && ! in_array(self::CANTIDAD_CONTRADICTORIA, $problemas, true)) {
            $problemas[] = self::CANTIDAD_CONTRADICTORIA;
            $detalle['contradiccion'] = array_values(array_filter([$cantidadFuente, ...$l['cantidades_extra']]));
            $cantidad = null;
            $cantidadFuente = null;
            $invalida = null;
        }
        if ($l['tallas_invalidas'] !== []) {
            $detalle['talla_invalida'] = $l['tallas_invalidas'];
        }
        if ($invalida !== null) {
            $problemas[] = self::CANTIDAD_INVALIDA;
            $detalle['cantidad_invalida'] = $invalida;
            $cantidad = null;
        } elseif ($cantidad === null && ! $ambigua && ! in_array(self::CANTIDAD_CONTRADICTORIA, $problemas, true)) {
            $problemas[] = self::FALTA_CANTIDAD;
        }
        if ($sinAsignar !== [] && ! $ambigua) {
            $problemas[] = self::NUMEROS_SIN_ASIGNAR;
        }

        // Tipo: dos distintos en la misma prenda, o uno dicho entre dos prendas sin saber de cual es.
        $genero = $l['genero'];
        if ($l['tipos_dobles'] !== []) {
            $problemas[] = self::TIPO_CONTRADICTORIO;
            $detalle['tipos_dobles'] = $l['tipos_dobles'];
            $genero = null;
        }
        if ($l['tipo_dudoso'] !== null) {
            $problemas[] = self::TIPO_DUDOSO;
            $detalle['tipo_dudoso'] = $l['tipo_dudoso'];
        }

        // Correcciones: las aplicadas se muestran; una corrección sin reemplazo claro se pregunta.
        $dudosas = $l['correccion_dudosa'];
        if ($l['correccion'] !== null) {
            $dudosas[] = $l['correccion']['marca'];
        }
        if ($l['negado'] !== null) {
            $dudosas[] = 'no '.$l['negado']['frag'];
        }
        if ($dudosas !== []) {
            $problemas[] = self::CORRECCION_DUDOSA;
            $detalle['correccion'] = array_values(array_unique($dudosas));
        }
        if ($l['correcciones'] !== []) {
            $detalle['correcciones'] = $l['correcciones'];
        }

        // SKU: lo que tenia forma de codigo y no es del catalogo, o un atributo que lo contradice.
        if ($l['skus_invalidos'] !== []) {
            $problemas[] = self::SKU_NO_RECONOCIDO;
            $detalle['skus_invalidos'] = $l['skus_invalidos'];
        }
        if ($l['sku_conflictos'] !== []) {
            $problemas[] = self::SKU_CONTRADICTORIO;
            $detalle['sku_conflictos'] = $l['sku_conflictos'];
        }

        // Lo que se dijo y no se uso (ni es palabra de apoyo) se muestra; las repeticiones tambien.
        if ($l['sueltas'] !== []) {
            $detalle['no_usadas'] = array_values(array_unique($l['sueltas']));
        }
        if ($l['repetidas'] !== []) {
            $detalle['repetidas'] = $l['repetidas'];
        }

        // Sin datos de prenda (solo palabras no reconocidas): se dice que falta todo, nunca se devuelve vacio.
        if ($problemas === [] && $l['color'] === null) {
            $problemas[] = self::FALTA_COLOR;
        }

        return [
            'texto' => trim(implode(' ', $l['palabras'])),
            // Lo que se dijo para esta linea con su escritura original (mayusculas, tildes, numeros en palabras).
            'texto_original' => $this->fragmentoOriginal($l['r_ini'] ?? -1, $l['r_fin'] ?? -1) ?? trim(implode(' ', $l['palabras'])),
            'color' => $l['color'],
            'candidatos' => $l['candidatos'],
            'genero' => $genero,
            'genero_explicito' => $genero !== null && $l['genero_explicito'] && ! isset($l['heredado']['genero']),
            // Contexto: el tipo vino de una prenda anterior del mismo dictado, o de una talla de niño.
            'genero_heredado' => $genero !== null && isset($l['heredado']['genero']),
            'genero_por_talla' => $genero === 'N' && ! $l['genero_explicito'] && ! isset($l['heredado']['genero']),
            'sku_dicho' => $l['sku_dicho'],
            // Crop top sin talla dicha: el resolutor decide con el catalogo (nunca asume Única si existe M).
            'talla_asumida' => $l['talla_asumida'] && $talla === 'U',
            'linea' => $l['linea'],
            'talla' => $talla,
            'talla_etiqueta' => self::etiquetaTalla($talla),
            'cantidad' => $cantidad,
            'problemas' => array_values(array_unique($problemas)),
            'fuentes' => [
                'color' => $l['color_dicho'],
                'genero' => $l['genero_dicho'],
                'talla' => $tallaFuente,
                'cantidad' => $cantidadFuente,
            ],
            'sin_asignar' => $ambigua ? [] : array_values($sinAsignar),
            'opciones' => $a['opciones'],
            'detalle' => $detalle,
        ];
    }
}
