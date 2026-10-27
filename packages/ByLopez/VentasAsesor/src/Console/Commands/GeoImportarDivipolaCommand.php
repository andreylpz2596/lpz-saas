<?php

namespace ByLopez\VentasAsesor\Console\Commands;

use ByLopez\VentasAsesor\Services\Geo\ImportadorDivipolaService;
use Illuminate\Console\Command;

/**
 * Importa el catalogo DIVIPOLA (D-58) desde el CSV oficial del DANE. Dry-run por defecto: sin
 * `--persistir` no se escribe nada. Falla cerrado ante cualquier error (nunca importa a medias).
 *
 * Fuente: https://www.datos.gov.co/Mapas-Nacionales/DIVIPOLA-C-digos-municipios/gdxc-w37w
 *   (Exportar → CSV; o https://www.datos.gov.co/api/views/gdxc-w37w/rows.csv?accessType=DOWNLOAD)
 */
class GeoImportarDivipolaCommand extends Command
{
    protected $signature = 'ventas-asesor:geo-importar-divipola
        {--archivo= : Ruta del CSV oficial del DANE (obligatorio)}
        {--dry-run : Solo analiza y reporta; no escribe nada (es el modo por defecto)}
        {--persistir : Escribe el catalogo. Sin esta opcion no se escribe}
        {--corte= : Fecha de corte de la fuente para trazabilidad, p. ej. 2024-12-30}';

    protected $description = 'Importa (dry-run por defecto) el catalogo local DANE/DIVIPOLA de departamentos y municipios';

    public function handle(ImportadorDivipolaService $importador): int
    {
        if ($this->option('dry-run') && $this->option('persistir')) {
            $this->error('--dry-run y --persistir son excluyentes.');

            return self::INVALID;
        }

        $archivo = (string) $this->option('archivo');

        if ($archivo === '') {
            $this->error('Indica el archivo oficial con --archivo=RUTA.csv');
            $this->line('Descarga: https://www.datos.gov.co/api/views/gdxc-w37w/rows.csv?accessType=DOWNLOAD');

            return self::INVALID;
        }

        $persistir = (bool) $this->option('persistir');
        $corte = $this->option('corte') !== null && $this->option('corte') !== '' ? (string) $this->option('corte') : null;

        $this->newLine();
        $this->line($persistir
            ? '<fg=yellow>MODO PERSISTENTE — se escribira el catalogo DIVIPOLA</>'
            : '<fg=cyan;options=bold>MODO DRY-RUN — NO SE REALIZARON ESCRITURAS</>');

        $informe = $importador->ejecutar($archivo, $persistir, $corte);

        $this->newLine();
        $this->line('Fuente   : '.ImportadorDivipolaService::FUENTE);
        $this->line('Archivo  : '.$archivo);
        $this->line('SHA-256  : '.($informe['checksum'] ?? '—'));
        $this->line('Corte    : '.($corte ?? '—'));
        $this->line('Filas    : '.$informe['filas']);

        if ($informe['errores'] !== []) {
            $this->newLine();
            $this->error('IMPORTACION RECHAZADA (falla cerrado, no se escribio nada):');

            foreach ($informe['errores'] as $error) {
                $this->line(' - '.$error);
            }

            return self::FAILURE;
        }

        $d = $informe['departamentos'];
        $m = $informe['municipios'];

        $this->newLine();
        $this->table(['', 'Altas', 'Cambios', 'Reactivados', 'Inactivados', 'Sin cambios'], [
            ['Departamentos', count($d['altas']), count($d['cambios']), '—', '—', $d['sin_cambios']],
            ['Municipios', $m['altas'], count($m['cambios']), count($m['reactivados']), count($m['inactivados']), $m['sin_cambios']],
        ]);

        if ($m['solo_metadatos'] > 0) {
            $this->line("Municipios con solo metadatos de fuente distintos (version/checksum): {$m['solo_metadatos']}");
        }

        foreach (['Departamentos nuevos' => $d['altas'], 'Departamentos con cambios' => $d['cambios'], 'Municipios con cambios' => $m['cambios'], 'Municipios reactivados' => $m['reactivados'], 'Municipios inactivados (ausentes de la fuente)' => $m['inactivados']] as $titulo => $lista) {
            if ($lista !== []) {
                $this->newLine();
                $this->line("<options=bold>{$titulo}:</>");

                foreach (array_slice($lista, 0, 30) as $linea) {
                    $this->line(' - '.$linea);
                }

                if (count($lista) > 30) {
                    $this->line(' … y '.(count($lista) - 30).' mas');
                }
            }
        }

        if ($informe['advertencias'] !== []) {
            $this->newLine();
            $this->line('<fg=yellow;options=bold>Advertencias (la equivalencia es por codigo, revisar si procede):</>');

            foreach ($informe['advertencias'] as $advertencia) {
                $this->line(' - '.$advertencia);
            }
        }

        $this->newLine();
        $this->line($informe['persistido'] ? '<info>Catalogo escrito.</info>' : 'Para escribir: agregue --persistir');

        return self::SUCCESS;
    }
}
