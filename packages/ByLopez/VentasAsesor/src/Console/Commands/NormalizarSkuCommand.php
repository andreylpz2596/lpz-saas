<?php

namespace ByLopez\VentasAsesor\Console\Commands;

use ByLopez\VentasAsesor\Services\Catalogo\CodigoSkuService;
use ByLopez\VentasAsesor\Services\Catalogo\RenombradorSkuService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Doc. 33 — Lleva TODO el catálogo de las familias del patrón al código único «115D-DZ-M» y corrige los nombres.
 *
 * MODO POR DEFECTO: vista previa, no escribe nada. Escribir exige --aplicar.
 *
 *  1. Número de cada color: el que más usan los productos actuales (por el número inicial de su SKU y el color de sus variantes),
 *     sin repetir número entre colores. --color=Terracota=127 fija uno a mano. Un color sin número toma el siguiente libre.
 *  2. Por cada configurable: SKU del padre y de sus variantes por patrón; nombre por la plantilla de la familia (con el color REAL
 *     de la variante: quita «Copia de», colores equivocados, dobles espacios) y url_key desde el nombre (con 301 del anterior).
 *  3. Corrige textos de opciones de color mal escritos (--sin-etiquetas para no hacerlo).
 *  4. El SKU nuevo se lleva a pedidos, facturas, envíos, carritos, costos, comisiones, cortes… (RenombradorSkuService).
 *
 * Todo en UNA transacción. Repetible: una segunda corrida no cambia nada. Deja un CSV con el antes y el después.
 */
class NormalizarSkuCommand extends Command
{
    /** Textos de opciones de color mal escritos → texto correcto (admin_name y etiqueta del idioma). */
    public const ETIQUETAS_COLOR = [
        'Amarillo Paste' => 'Amarillo Pastel',
        'Verde Paste' => 'Verde Pastel',
        'Verde Petroleo' => 'Verde Petróleo',
        'Verde Neon' => 'Verde Neón',
        'Cafe' => 'Café',
    ];

    protected $signature = 'ventas-asesor:sku-normalizar
        {--aplicar : Escribe los cambios. Sin esta opción solo muestra la vista previa}
        {--color=* : Número fijo para un color: --color="Terracota=127"}
        {--sin-nombres : No cambia nombres ni url_key, solo SKU}
        {--sin-etiquetas : No corrige los textos de las opciones de color}
        {--csv= : Ruta del CSV con el antes y el después (por defecto en storage/app)}';

    protected $description = 'Vista previa (o --aplicar) del código único por patrón 115D-DZ-M para todo el catálogo, con nombres y copias del SKU';

    public function handle(RenombradorSkuService $renombrador): int
    {
        $aplicar = (bool) $this->option('aplicar');
        $this->info(($aplicar ? 'APLICAR' : 'VISTA PREVIA (no escribe nada)').' · base '.DB::selectOne('SELECT DATABASE() AS b')->b);

        $fijos = [];

        foreach ((array) $this->option('color') as $par) {
            if (! preg_match('/^(.+)=(\d{1,4})$/u', trim((string) $par), $m)) {
                $this->error('--color debe ser Nombre=número: '.$par);

                return self::FAILURE;
            }

            $fijos[CodigoSkuService::clave($m[1])] = (int) $m[2];
        }

        try {
            DB::beginTransaction();
            $resultado = $this->ejecutar($renombrador, $fijos, $aplicar);
            $aplicar ? DB::commit() : DB::rollBack();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($resultado === null) {
            return self::FAILURE;
        }

        $this->info($aplicar ? 'Cambios aplicados.' : 'Nada escrito. Para aplicar: --aplicar (con las mismas opciones).');

        return self::SUCCESS;
    }

    protected function ejecutar(RenombradorSkuService $renombrador, array $fijos, bool $aplicar): ?bool
    {
        $color = (int) DB::table('attributes')->where('code', 'color')->value('id');

        // 0. Textos de color (antes de armar nombres).
        if (! $this->option('sin-etiquetas')) {
            $todas = DB::table('attribute_options')->where('attribute_id', $color)->pluck('id');

            foreach (self::ETIQUETAS_COLOR as $mal => $bien) {
                $a = DB::table('attribute_options')->whereIn('id', $todas)->where('admin_name', $mal)->update(['admin_name' => $bien]);
                $t = DB::table('attribute_option_translations')->whereIn('attribute_option_id', $todas)->where('label', $mal)->update(['label' => $bien]);
                ($a + $t) > 0 && $this->line("Color «{$mal}» → «{$bien}»");
            }
        }

        // 1. Números de color.
        $codigos = $this->numerosDeColor($color, $fijos);

        if ($codigos === null) {
            return null;
        }

        $servicio = new CodigoSkuService;
        $nuevosColores = array_diff_key($codigos, $servicio->codigos());

        foreach ($nuevosColores as $opcion => $n) {
            DB::table(CodigoSkuService::TABLA_COLORES)->insert([
                'attribute_option_id' => $opcion, 'codigo' => $n, 'color_snapshot' => $servicio->texto($opcion), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $servicio = new CodigoSkuService;                         // relee los números (y los textos corregidos)
        $this->table(['Número', 'Color'], collect($codigos)->sort()->map(fn ($n, $o) => [$n, $servicio->texto((int) $o).(isset($nuevosColores[$o]) ? ' (nuevo)' : '')])->values()->all());

        // 2. Plan de todos los configurables de las familias del patrón.
        $familias = DB::table('attribute_families')->whereIn('code', array_keys((array) config('ventasasesor.sku.familias')))->pluck('id');
        $raices = DB::table('products')->where('type', 'configurable')->whereNull('parent_id')->whereIn('attribute_family_id', $familias)->orderBy('id')->pluck('id');
        $planes = [];

        foreach ($raices as $raiz) {
            $planes[] = $servicio->plan((int) $raiz, true);
        }

        $flat = DB::table('product_flat')->get(['product_id', 'name', 'url_key'])->keyBy('product_id');
        $cambios = [];
        $filasCsv = [['product_id', 'tipo', 'sku_antes', 'sku_despues', 'nombre_antes', 'nombre_despues', 'url_antes', 'url_despues']];
        $sinCalcular = [];
        $seRenombran = [];
        $nombresPorId = [];

        foreach ($planes as $plan) {
            $nombresPorId += $this->option('sin-nombres') ? [] : $servicio->nombres($plan);
        }

        foreach ($nombresPorId as $id => $nombre) {
            if (trim((string) ($flat[$id]->name ?? '')) !== $nombre) {
                $seRenombran[] = $id;
            }
        }

        $reservadosUrl = [];

        foreach ($planes as $plan) {
            foreach ([$plan['padre'], ...$plan['variantes']] as $p) {
                $id = (int) $p['id'];
                $c = [];

                if ($p['nuevo'] === null) {
                    $sinCalcular[] = [$id, $p['actual']];
                } elseif ($p['nuevo'] !== $p['actual']) {
                    $c['sku'] = $p['nuevo'];
                }

                if (isset($nombresPorId[$id])) {
                    $antes = (string) ($flat[$id]->name ?? '');
                    $url = $renombrador->urlKeyLibre($nombresPorId[$id], $id, $reservadosUrl, $seRenombran);
                    $reservadosUrl[$url] = $id;
                    $nombresPorId[$id] !== $antes && $c['name'] = $nombresPorId[$id];
                    $url !== (string) ($flat[$id]->url_key ?? '') && $c['url_key'] = $url;
                }

                if ($c !== []) {
                    $cambios[$id] = $c;
                    $filasCsv[] = [$id, $id === (int) $plan['padre']['id'] ? 'producto' : 'variante', $p['actual'], $c['sku'] ?? $p['actual'],
                        $flat[$id]->name ?? '', $c['name'] ?? ($flat[$id]->name ?? ''), $flat[$id]->url_key ?? '', $c['url_key'] ?? ($flat[$id]->url_key ?? '')];
                }
            }
        }

        $nuevos = array_filter(array_map(fn ($c) => $c['sku'] ?? null, $cambios));
        $repetidos = array_keys(array_filter(array_count_values(array_map('strtoupper', $nuevos)), fn ($n) => $n > 1));

        if ($repetidos !== []) {
            $this->error('SKU nuevos repetidos (no se aplica nada): '.implode(', ', $repetidos));

            return null;
        }

        $this->table(['ID', 'SKU antes', 'SKU después', 'Nombre después'], array_map(
            fn ($f) => [$f[0], $f[2], $f[3] === $f[2] ? '=' : $f[3], $f[5] === $f[4] ? '=' : $f[5]],
            array_slice($filasCsv, 1)
        ));

        if ($sinCalcular !== []) {
            $this->warn('Conservan su SKU (falta color, talla o línea, o el código lo tiene otro producto): '.implode(', ', array_map(fn ($s) => '#'.$s[0].' '.$s[1], $sinCalcular)));
        }

        $this->line(sprintf('Configurables: %d · cambian SKU: %d · cambian nombre: %d · cambian url_key: %d',
            count($planes), count($nuevos), count(array_filter($cambios, fn ($c) => isset($c['name']))), count(array_filter($cambios, fn ($c) => isset($c['url_key'])))));

        $ruta = (string) ($this->option('csv') ?: storage_path('app/sku-normalizar-'.now()->format('Ymd_His').($aplicar ? '-aplicado' : '-vista').'.csv'));
        $fh = fopen($ruta, 'w');
        fwrite($fh, "\xEF\xBB\xBF");

        foreach ($filasCsv as $f) {
            fputcsv($fh, $f, ';');
        }

        fclose($fh);
        $this->line('CSV: '.$ruta);

        // 3. Escribir (dentro de la transacción del comando; sin --aplicar se revierte al final).
        $copias = $renombrador->aplicar($cambios);
        $this->table(['Copias del SKU actualizadas', 'Filas'], collect($copias)->map(fn ($n, $t) => [$t, $n])->values()->all());

        return true;
    }

    /**
     * Número de cada color: los ya guardados se respetan; los fijados con --color mandan; el resto, el número más usado por los
     * productos actuales sin repetir; un color usado sin número toma el siguiente libre.
     *
     * @return array<int,int>|null opción => número
     */
    protected function numerosDeColor(int $colorAttr, array $fijos): ?array
    {
        $guardados = (new CodigoSkuService)->codigos();
        $opciones = DB::table('attribute_options')->where('attribute_id', $colorAttr)->pluck('admin_name', 'id');
        $codigos = $guardados;

        foreach ($fijos as $clave => $n) {
            $opcion = $opciones->filter(fn ($t) => CodigoSkuService::clave($t) === $clave)->keys();

            if ($opcion->count() !== 1) {
                $this->error("--color: «{$clave}» no coincide con UNA opción de color.");

                return null;
            }

            $o = (int) $opcion->first();

            if (($dueno = array_search($n, $codigos, true)) !== false && $dueno !== $o) {
                $this->error("--color: el número {$n} ya es de «{$opciones[$dueno]}».");

                return null;
            }

            $codigos[$o] = $n;
        }

        // Votos: (color, número inicial del SKU del padre) por configurable de las familias del patrón.
        $familias = DB::table('attribute_families')->whereIn('code', array_keys((array) config('ventasasesor.sku.familias')))->pluck('id');
        $votos = [];
        $usados = [];

        foreach (DB::table('products')->where('type', 'configurable')->whereNull('parent_id')->whereIn('attribute_family_id', $familias)->get(['id', 'sku']) as $p) {
            $colores = DB::table('products as v')->join('product_attribute_values as pav', fn ($j) => $j->on('pav.product_id', '=', 'v.id')->where('pav.attribute_id', $colorAttr))
                ->where('v.parent_id', $p->id)->whereNotNull('pav.integer_value')->distinct()->pluck('pav.integer_value')->map(fn ($c) => (int) $c)->all();

            foreach ($colores as $c) {
                $usados[$c] = true;
            }

            if (count($colores) === 1 && preg_match('/^(\d{3})/', (string) $p->sku, $m)) {
                $votos[$colores[0].'|'.(int) $m[1]] = ($votos[$colores[0].'|'.(int) $m[1]] ?? 0) + 1;
            }
        }

        uksort($votos, function ($a, $b) use ($votos) {
            return [$votos[$b], (int) explode('|', $a)[1]] <=> [$votos[$a], (int) explode('|', $b)[1]];
        });

        foreach ($votos as $par => $n) {
            [$c, $numero] = array_map('intval', explode('|', $par));

            if (! isset($codigos[$c]) && ! in_array($numero, $codigos, true)) {
                $codigos[$c] = $numero;
            }
        }

        $siguiente = max([...array_values($codigos), (int) config('ventasasesor.sku.primer_codigo_color', 101) - 1]) + 1;

        foreach (array_keys($usados) as $c) {
            if (! isset($codigos[$c])) {
                $codigos[$c] = $siguiente++;
            }
        }

        return $codigos;
    }
}
