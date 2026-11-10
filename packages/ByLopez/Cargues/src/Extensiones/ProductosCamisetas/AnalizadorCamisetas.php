<?php

namespace ByLopez\Cargues\Extensiones\ProductosCamisetas;

/**
 * Reglas del tipo «Productos — Camisetas piel de durazno» (docs/17 §3). SOLO LEE (por CatalogoCamisetas). Analiza el archivo POR GRUPO
 * (codigo del producto base) y devuelve, por fila, el plan y su motivo. Lo usan `derivar` (vista previa, antes de VALIDAR) y `aplicar`
 * (otra vez, dentro de la transaccion del grupo: el plan pudo quedar viejo).
 *
 * Un grupo con UN problema queda BLOQUEADO entero (nunca un padre incompleto ni variantes sueltas); los demas grupos siguen.
 * Nunca agrupa por nombre, nunca compara SKU por partes y nunca crea combinaciones color/talla que no esten en el archivo.
 */
class AnalizadorCamisetas
{
    public const NUEVO = 'nuevo';

    public const AGREGAR = 'agregar';

    public const SIN_CAMBIO = 'sin_cambio';

    public const BLOQUEADO = 'bloqueado';

    public const CREAR_VARIANTE = 'crear_variante';

    public const VARIANTE_SIN_CAMBIO = 'sin_cambio';

    public const ATRIBUTOS_PADRE = ['manga', 'cuello', 'tipo_tela', 'composicion'];

    protected const ETIQUETAS = [
        'color' => 'Color', 'talla' => 'Talla', 'manga' => 'Manga', 'cuello' => 'Cuello', 'tipo_tela' => 'Tipo de tela',
        'descripcion_corta' => 'Descripción corta', 'descripcion' => 'Descripción',
        'composicion' => 'Composición', 'marca' => 'Marca',
    ];

    public function __construct(protected CatalogoCamisetas $catalogo) {}

    public static function etiqueta(string $campo): string
    {
        return self::ETIQUETAS[$campo] ?? $campo;
    }

    /**
     * @param  list<array{id:int, fila:int, v:array<string,mixed>, rechazo:?string}>  $filas  todas las filas del archivo que se conocen
     *                                                                                        (validas y rechazadas por el motor: una fila rechazada de un grupo lo bloquea)
     * @return array<int, array<string,mixed>> id => derivados
     */
    public function analizar(array $filas): array
    {
        $grupos = [];

        foreach ($filas as $f) {
            $base = trim((string) ($f['v']['codigo_base'] ?? ''));

            if ($base !== '') {
                $grupos[mb_strtolower($base)][] = $f;
            }
        }

        // SKU de variante igual a un codigo base del archivo: se confundirian padre e hijo.
        $bases = array_fill_keys(array_keys($grupos), true);
        $salida = [];

        foreach ($grupos as $clave => $g) {
            usort($g, fn ($a, $b) => $a['fila'] <=> $b['fila']);
            $salida += $this->grupo((string) $clave, $g, $bases);
        }

        return $salida;
    }

    /** @return array<int, array<string,mixed>> */
    protected function grupo(string $clave, array $filas, array $bases): array
    {
        $cfg = (array) config('cargues.productos_camisetas');
        $attr = (array) $cfg['atributos'];
        $base = trim((string) $filas[0]['v']['codigo_base']);
        $errores = [];                                      // id => [codigo, mensaje]
        $desconocidas = [];                                 // id => list<string>
        $observaciones = [];                                // id => list<codigo>
        $res = [];                                          // id => derivados parciales

        $error = function (int $id, string $codigo, string $mensaje) use (&$errores) {
            $errores[$id] ??= [$codigo, $mensaje];
        };

        foreach ($filas as $f) {
            $res[$f['id']] = ['grupo_clave' => mb_substr($base, 0, 64)];

            if ($f['rechazo'] !== null) {
                $error($f['id'], 'FILA_RECHAZADA', $f['rechazo']);
            }
        }

        // 1. Datos del PADRE: iguales en todas las filas del grupo (vacio = «no informado»).
        $padre = [];

        foreach (['nombre', 'publico', 'composicion', 'manga', 'cuello', 'tipo_tela', 'descripcion_corta', 'descripcion'] as $campo) {
            $valores = [];

            foreach ($filas as $f) {
                $t = trim((string) ($f['v'][$campo] ?? ''));

                if ($t !== '') {
                    $valores[CatalogoCamisetas::clave($t)] ??= $t;
                }
            }

            if (count($valores) > 1) {
                $error($filas[0]['id'], 'GRUPO_INCONSISTENTE', 'Las filas del producto base «'.$base.'» no coinciden en '.($campo === 'nombre' ? 'el nombre' : ($campo === 'publico' ? 'el público' : mb_strtolower(self::etiqueta($campo)))).': «'.implode('», «', $valores).'». Todas las filas de un mismo producto deben traer el mismo valor.');
            }

            $padre[$campo] = $valores === [] ? null : reset($valores);
        }

        // 2. Publico → familia y categorias.
        $publico = null;
        $familia = null;
        $sinEje = [];                                       // ejes que la familia no tiene: no se resuelven sus opciones (el bloqueo ya dice por que)
        $codigoPublico = CatalogoCamisetas::clave($padre['publico']);
        $codigoPublico = $cfg['sinonimos_publico'][$codigoPublico] ?? $codigoPublico;

        if ($padre['publico'] === null || ! isset($cfg['publicos'][$codigoPublico])) {
            $error($filas[0]['id'], 'PUBLICO_DESCONOCIDO', 'Público «'.($padre['publico'] ?? '').'» no reconocido. Use Hombre, Dama o Niño.');
        } else {
            $publico = $cfg['publicos'][$codigoPublico] + ['codigo' => $codigoPublico];
            $familia = $this->catalogo->familia((string) $publico['familia']);
            // Eje de talla DEL PUBLICO: adulto `size`; niño `grupo_de_edad_ninos` (2-4, 6-8…), como su catalogo real. Desde aqui todo usa ese.
            $attr['talla'] = (string) ($publico['atributo_talla'] ?? $attr['talla']);

            if ($familia === null) {
                $error($filas[0]['id'], 'FAMILIA_NO_EXISTE', 'No existe la familia de atributos «'.$publico['familia'].'» configurada para el público «'.$padre['publico'].'».');
            } else {
                foreach (['color', 'talla'] as $eje) {
                    if (! in_array($attr[$eje], $familia['atributos'], true)) {
                        $sinEje[$eje] = true;
                        $error($filas[0]['id'], 'FAMILIA_SIN_ATRIBUTO', 'La familia «'.$familia['nombre'].'» no tiene el atributo '.self::etiqueta($eje).': no se pueden crear variantes por color y talla. Agréguelo en Catálogo › Familias de atributos y vuelva a cargar el archivo.');
                    }
                }
            }
        }

        // 3. Opciones del padre (archivo o valor comun del tipo). Desconocida = bloqueo; vacio sin valor comun = dato pendiente.
        $ids = [];
        $explicitos = [];

        foreach ([...self::ATRIBUTOS_PADRE, 'marca'] as $campo) {
            $texto = $padre[$campo] ?? null;
            $explicitos[$campo] = $texto !== null;
            $texto ??= $cfg['por_defecto'][$campo] ?? null;
            $ids[$campo] = null;

            if ($texto === null) {
                if ($campo !== 'marca') {
                    $observaciones[$filas[0]['id']][] = 'DATO_PENDIENTE_'.strtoupper($campo);
                }

                continue;
            }

            if ($familia !== null && ! in_array($attr[$campo], $familia['atributos'], true)) {
                $error($filas[0]['id'], 'FAMILIA_SIN_ATRIBUTO', 'La familia «'.$familia['nombre'].'» no tiene el atributo '.self::etiqueta($campo).' («'.$texto.'»).');

                continue;
            }

            [$id, $estado] = $this->catalogo->opcion($attr[$campo], $texto);

            if ($id === null) {
                $error($filas[0]['id'], 'OPCION_DESCONOCIDA', self::etiqueta($campo).' «'.$texto.'» '.($estado === 'ambigua' ? 'coincide con varias opciones' : 'no existe').' en el catálogo. Créela en Catálogo › Atributos (o corrija el archivo) y vuelva a cargar.');
                $desconocidas[$filas[0]['id']][] = self::etiqueta($campo).'='.$texto;
            }

            $ids[$campo] = $id;
        }

        // 4. Filas (variantes): color, talla, precio y costo.
        $tallasPublico = $publico ? array_map([CatalogoCamisetas::class, 'clave'], (array) ($cfg['tallas'][$publico['tallas']] ?? [])) : null;
        $combinaciones = [];

        foreach ($filas as $f) {
            $v = $f['v'];
            $id = $f['id'];
            $sku = trim((string) ($v['sku_variante'] ?? ''));

            if ($sku !== '' && isset($bases[mb_strtolower($sku)])) {
                $error($id, 'SKU_IGUAL_A_CODIGO_BASE', 'El SKU de la variante «'.$sku.'» es también un código de producto base del archivo: el padre y la variante deben tener SKU distintos.');
            }

            $color = trim((string) ($v['color'] ?? ''));
            $talla = trim((string) ($v['talla'] ?? ''));
            $res[$id]['color_id'] = null;
            $res[$id]['talla_id'] = null;

            if (isset($sinEje['color'])) {
                // la familia no tiene color: el bloqueo del grupo ya lo explica
            } elseif ($color === '') {
                $error($id, 'COLOR_VACIO', 'La variante «'.$sku.'» no trae color. El color es uno de los dos ejes de la variante (color y talla): sin él no se puede elegir en Nueva venta.');
            } else {
                [$cid, $estado] = $this->catalogo->opcion($attr['color'], $color);
                $res[$id]['color_id'] = $cid;

                if ($cid === null) {
                    $error($id, 'OPCION_DESCONOCIDA', 'Color «'.$color.'» '.($estado === 'ambigua' ? 'coincide con varias opciones' : 'no existe').' en el catálogo. Créelo en Catálogo › Atributos › Color (o corrija el archivo) y vuelva a cargar.');
                    $desconocidas[$id][] = 'Color='.$color;
                }
            }

            if ($talla !== '' && ! isset($sinEje['talla'])) {
                if ($tallasPublico !== null && ! in_array(CatalogoCamisetas::clave($talla), $tallasPublico, true)) {
                    $error($id, 'TALLA_NO_CORRESPONDE', 'La talla «'.$talla.'» no es una talla de '.($publico['tallas'] === 'nino' ? 'niño' : 'adulto').' ('.implode(', ', (array) $cfg['tallas'][$publico['tallas']]).').');
                } else {
                    [$tid, $estado] = $this->catalogo->opcion($attr['talla'], $talla);
                    $res[$id]['talla_id'] = $tid;

                    if ($tid === null) {
                        $error($id, 'OPCION_DESCONOCIDA', 'Talla «'.$talla.'» '.($estado === 'ambigua' ? 'coincide con varias opciones' : 'no existe').' en el catálogo. Créela en Catálogo › Atributos › Talla y vuelva a cargar.');
                        $desconocidas[$id][] = 'Talla='.$talla;
                    }
                }
            }

            foreach (['precio' => 'precio de venta', 'costo' => 'costo'] as $campo => $nombre) {
                $n = $v[$campo] ?? null;

                if ($n !== null && $n !== '' && (float) $n <= 0) {
                    $error($id, (float) $n == 0.0 ? strtoupper($campo).'_CERO' : strtoupper($campo).'_NEGATIVO', 'El '.$nombre.' es '.$n.'. '.((float) $n == 0.0 ? 'En el aplicativo 0 significa «sin '.$nombre.'»: deje la celda vacía si aún no lo tiene.' : 'No puede ser negativo.'));
                }
            }

            $combo = CatalogoCamisetas::clave($color).'|'.CatalogoCamisetas::clave($talla);

            if ($color !== '' && $talla !== '') {
                if (isset($combinaciones[$combo])) {
                    $error($id, 'COMBINACION_REPETIDA', 'La combinación '.$color.' / '.$talla.' aparece dos veces en el producto «'.$base.'» (filas '.$combinaciones[$combo].' y '.$f['fila'].') con distinto SKU.');
                }

                $combinaciones[$combo] ??= $f['fila'];
            }
        }

        // 5. Catalogo: padre y variantes existentes.
        $productos = $this->catalogo->productosPorSku([$base, ...array_map(fn ($f) => trim((string) ($f['v']['sku_variante'] ?? '')), $filas)]);
        $existente = $productos[mb_strtolower($base)] ?? null;
        $guardados = [];

        if ($existente !== null) {
            if ($existente->type !== 'configurable' || $existente->parent_id !== null) {
                $error($filas[0]['id'], 'CODIGO_BASE_NO_CONFIGURABLE', 'El código «'.$base.'» ya pertenece a un producto '.($existente->parent_id !== null ? 'que es variante de otro' : 'de tipo «'.$existente->type.'»').' (#'.$existente->id.'): no puede ser el producto base de estas variantes.');
                $existente = null;
            } else {
                $sa = $this->catalogo->superAtributos((int) $existente->id);
                $esperados = [$attr['color'], $attr['talla']];
                sort($esperados);

                if ($sa !== $esperados) {
                    $error($filas[0]['id'], 'SUPER_ATRIBUTOS_DISTINTOS', 'El producto existente «'.$base.'» (#'.$existente->id.') no varía por color y talla (varía por: '.($sa ? implode(', ', $sa) : 'nada').'): no se le agregan variantes desde este cargue.');
                }

                if ($familia !== null && (int) $existente->attribute_family_id !== $familia['id']) {
                    $error($filas[0]['id'], 'CONFLICTO_DATOS', 'Conflicto con el producto existente «'.$base.'» (#'.$existente->id.') — Público: el archivo dice «'.$padre['publico'].'» (familia «'.$familia['nombre'].'») y el catálogo tiene la familia «'.$this->catalogo->nombreFamilia((int) $existente->attribute_family_id).'». No se sobrescribe.');
                }

                $g = $this->catalogo->valores([(int) $existente->id], ['name', $attr['manga'], $attr['cuello'], $attr['tipo_tela'], $attr['composicion']])[(int) $existente->id] ?? [];

                if (CatalogoCamisetas::clave($g['name'] ?? '') !== CatalogoCamisetas::clave($padre['nombre'])) {
                    $error($filas[0]['id'], 'CONFLICTO_DATOS', 'Conflicto con el producto existente «'.$base.'» (#'.$existente->id.') — Nombre: el archivo dice «'.$padre['nombre'].'» y el catálogo «'.($g['name'] ?? '').'». No se sobrescribe.');
                }

                foreach (self::ATRIBUTOS_PADRE as $campo) {
                    $guardado = $g[$attr[$campo]] ?? null;

                    if (! $explicitos[$campo] || $ids[$campo] === null) {
                        continue;                              // sin dato en el archivo (o desconocido): se conserva lo guardado
                    }

                    if ($guardado === null) {
                        $observaciones[$filas[0]['id']][] = 'EXISTENTE_SIN_'.strtoupper($campo);
                    } elseif ((int) $guardado !== (int) $ids[$campo]) {
                        $error($filas[0]['id'], 'CONFLICTO_DATOS', 'Conflicto con el producto existente «'.$base.'» (#'.$existente->id.') — '.self::etiqueta($campo).': el archivo dice «'.$padre[$campo].'» y el catálogo «'.$this->catalogo->textoOpcion($attr[$campo], (int) $guardado).'». No se sobrescribe.');
                    }
                }

                $hijos = $this->catalogo->variantes((int) $existente->id);
                $vals = $this->catalogo->valores(array_map(fn ($h) => (int) $h->id, $hijos), [$attr['color'], $attr['talla'], 'price', 'cost']);

                foreach ($hijos as $h) {
                    $guardados[(int) $h->id] = ['sku' => (string) $h->sku] + ($vals[(int) $h->id] ?? []);
                }
            }
        }

        foreach ($filas as $f) {
            $id = $f['id'];
            $v = $f['v'];
            $sku = trim((string) ($v['sku_variante'] ?? ''));
            $p = $productos[mb_strtolower($sku)] ?? null;
            $res[$id]['variante_id'] = null;
            $res[$id]['fila_plan'] = self::CREAR_VARIANTE;

            if ($p !== null) {
                if ($existente === null || (int) $p->parent_id !== (int) $existente->id) {
                    $error($id, 'SKU_DE_OTRO_PRODUCTO', 'El SKU «'.$sku.'» ya existe en el catálogo (#'.$p->id.($p->parent_id ? ', variante del producto #'.$p->parent_id : ', producto '.$p->type).') y no es una variante de «'.$base.'». No se reasigna.');

                    continue;
                }

                $gv = $guardados[(int) $p->id] ?? [];
                $res[$id]['variante_id'] = (int) $p->id;
                $res[$id]['fila_plan'] = self::VARIANTE_SIN_CAMBIO;
                $diferencias = [];

                if ($res[$id]['color_id'] !== null && (int) ($gv[$attr['color']] ?? 0) !== (int) $res[$id]['color_id']) {
                    $diferencias[] = 'color «'.$v['color'].'» frente a «'.$this->catalogo->textoOpcion($attr['color'], $gv[$attr['color']] ?? null).'»';
                }

                if ($res[$id]['talla_id'] !== null && (int) ($gv[$attr['talla']] ?? 0) !== (int) $res[$id]['talla_id']) {
                    $diferencias[] = 'talla «'.$v['talla'].'» frente a «'.$this->catalogo->textoOpcion($attr['talla'], $gv[$attr['talla']] ?? null).'»';
                }

                foreach (['precio' => 'price', 'costo' => 'cost'] as $campo => $codigo) {
                    $n = $v[$campo] ?? null;

                    if ($n !== null && $n !== '' && (float) $n > 0 && abs((float) $n - (float) ($gv[$codigo] ?? 0)) >= 0.005) {
                        $diferencias[] = $campo.' '.self::dinero((float) $n).' frente a '.(isset($gv[$codigo]) && $gv[$codigo] !== null ? self::dinero((float) $gv[$codigo]) : 'sin '.$campo);
                    }
                }

                if ($diferencias !== []) {
                    $error($id, 'CONFLICTO_DATOS', 'Conflicto con la variante existente «'.$sku.'» (#'.$p->id.'): '.implode('; ', $diferencias).' (archivo frente a catálogo). No se sobrescribe.');
                }

                continue;
            }

            // Variante nueva: la combinacion no puede existir ya en el padre con otro SKU.
            foreach ($guardados as $hid => $gv) {
                if ($res[$id]['color_id'] !== null && $res[$id]['talla_id'] !== null && (int) ($gv[$attr['color']] ?? 0) === (int) $res[$id]['color_id'] && (int) ($gv[$attr['talla']] ?? 0) === (int) $res[$id]['talla_id']) {
                    $error($id, 'COMBINACION_YA_EXISTE', 'El producto «'.$base.'» ya tiene la combinación '.$v['color'].' / '.$v['talla'].' con el SKU «'.$gv['sku'].'» (#'.$hid.'). No se crea otra variante igual.');
                }
            }

            if (($v['precio'] ?? null) === null || $v['precio'] === '') {
                $observaciones[$id][] = 'PRECIO_PENDIENTE';
            }

            if (($v['costo'] ?? null) === null || $v['costo'] === '') {
                $observaciones[$id][] = 'COSTO_PENDIENTE';
            }

            if (($v['peso'] ?? null) === null || $v['peso'] === '') {
                $observaciones[$id][] = 'PESO_PENDIENTE';
            }
        }

        // Un dato vacio del archivo solo es «pendiente» para un producto NUEVO: el existente conserva lo que ya tiene.
        if ($existente !== null) {
            $observaciones = array_map(fn ($c) => array_values(array_filter($c, fn ($x) => ! str_starts_with($x, 'DATO_PENDIENTE_'))), $observaciones);
        } else {
            if ($padre['descripcion_corta'] === null || $padre['descripcion'] === null) {
                // Bagisto exige ambas descripciones para guardar desde su formulario: sin ellas en el archivo se arman SOLO con datos confirmados.
                $observaciones[$filas[0]['id']][] = 'DESCRIPCION_GENERADA';
            }

            // Nueva venta elige UN producto por linea (familia) y color: avisar si el color ya lo tiene otro producto de la misma linea.
            $ocupados = $familia === null ? [] : $this->catalogo->coloresOcupados($familia['id'], $attr['color']);
            $avisados = [];

            foreach ($filas as $f) {
                $cid = $res[$f['id']]['color_id'] ?? null;

                if ($cid !== null && isset($ocupados[$cid]) && ! isset($avisados[$cid])) {
                    $observaciones[$f['id']][] = 'COLOR_YA_EN_LINEA';
                    $avisados[$cid] = true;
                }
            }
        }

        // 6. Estado del grupo y derivados finales.
        $estado = match (true) {
            $errores !== [] => self::BLOQUEADO,
            $existente === null => self::NUEVO,
            in_array(self::CREAR_VARIANTE, array_column($res, 'fila_plan'), true) => self::AGREGAR,
            default => self::SIN_CAMBIO,
        };

        $primerError = $errores === [] ? null : array_key_first($errores);
        $filaDe = array_column(array_map(fn ($f) => ['id' => $f['id'], 'fila' => $f['fila']], $filas), 'fila', 'id');

        foreach ($res as $id => &$r) {
            $r += [
                'grupo_estado' => $estado,
                'producto_id' => $existente ? (int) $existente->id : null,
                'familia_id' => $familia['id'] ?? null,
                'manga_id' => $ids['manga'], 'cuello_id' => $ids['cuello'], 'tela_id' => $ids['tipo_tela'],
                'composicion_id' => $ids['composicion'], 'marca_id' => $ids['marca'],
                'desconocidas' => isset($desconocidas[$id]) ? mb_substr(implode('|', array_unique($desconocidas[$id])), 0, 500) : null,
                'observaciones' => isset($observaciones[$id]) && $estado !== self::BLOQUEADO ? implode(',', array_unique($observaciones[$id])) : null,
                'bloqueo_codigo' => null, 'bloqueo_mensaje' => null,
            ];

            if ($estado === self::BLOQUEADO) {
                [$c, $m] = $errores[$id] ?? ['GRUPO_BLOQUEADO', 'No se carga ninguna variante del producto base «'.$base.'» porque la fila '.$filaDe[$primerError].' tiene un problema: '.$errores[$primerError][1]];
                $r['bloqueo_codigo'] = $c;
                $r['bloqueo_mensaje'] = mb_substr($c === 'FILA_RECHAZADA' ? 'Esta fila no superó la validación ('.$m.'). Ninguna variante del producto base «'.$base.'» se carga: un producto no se crea incompleto.' : $m, 0, 500);
                $r['fila_plan'] = null;
            }
        }

        return $res;
    }

    public static function dinero(float $n): string
    {
        return '$ '.number_format($n, $n == floor($n) ? 0 : 2, ',', '.');
    }

    /** Texto de una observacion (codigo → mensaje para el resultado). */
    public static function mensajeObservacion(string $codigo): string
    {
        if (str_starts_with($codigo, 'DATO_PENDIENTE_')) {
            return self::etiqueta(strtolower(substr($codigo, 15))).' sin dato: queda vacío para completarlo en Catálogo (no se inventa).';
        }

        if (str_starts_with($codigo, 'EXISTENTE_SIN_')) {
            return 'El producto existente no tiene '.mb_strtolower(self::etiqueta(strtolower(substr($codigo, 14)))).' y el archivo sí: no se modificó el producto existente; complételo en Catálogo si corresponde.';
        }

        return match ($codigo) {
            'PRECIO_PENDIENTE' => 'Sin precio de venta: la variante queda sin precio y el producto, inactivo. Bagisto exige el precio de cada variante para guardar el producto desde su formulario: complételo allí.',
            'COLOR_YA_EN_LINEA' => 'Otro producto de esta línea ya tiene este color: Nueva venta muestra UN producto por línea y color, así que este no se podrá elegir allí por el selector de color (el producto se crea igual).',
            'PESO_PENDIENTE' => 'Sin peso: Bagisto exige el peso de cada variante para guardar el producto desde su formulario; complételo allí (no se inventa).',
            'DESCRIPCION_GENERADA' => 'Descripción armada solo con los datos confirmados del archivo y del catálogo (nombre, público, tela, manga, cuello, composición y marca que existan); reemplácela en Catálogo cuando quiera.',
            'COSTO_PENDIENTE' => 'Sin costo: la variante queda sin costo comisionable (no se guarda 0).',
            'PRODUCTO_INACTIVO' => 'Producto creado INACTIVO (acción «Crear inactivos» o falta de precio): actívelo en Catálogo cuando esté listo para vender; sus variantes con precio ya quedan activas.',
            'VARIANTE_INACTIVA' => 'Variante agregada INACTIVA: actívela en Catálogo cuando esté lista para vender.',
            'CATEGORIA_NO_RESUELTA' => 'Alguna categoría configurada para el público no existe o está repetida: el producto quedó sin ella.',
            default => $codigo,
        };
    }
}
