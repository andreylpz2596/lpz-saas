<?php

namespace ByLopez\CorteConfeccion\Console\Commands;

use ByLopez\CorteConfeccion\Exceptions\ConfiguracionCatalogoCorteException;
use ByLopez\CorteConfeccion\Services\ResolvedorVarianteCorteService;
use Illuminate\Console\Command;

/**
 * Diagnostico de SOLO LECTURA del catalogo Bagisto para Orden de Corte.
 * Nunca corrige nada: solo informa que configurables/simples impedirian o
 * ensuciarian la resolucion de variantes.
 */
class DiagnosticarCatalogoCorte extends Command
{
    protected $signature = 'corteconfeccion:diagnosticar-catalogo
        {--tipo-tela= : ID de la opcion del atributo tipo_tela (attribute_options.id) para acotar el diagnostico}
        {--sku=* : Muestra solo lo relacionado con estos SKU (configurable o simple)}
        {--json : Salida en JSON}';

    protected $description = 'Diagnostico de solo lectura de la estructura del catalogo Bagisto para Orden de Corte';

    public function handle(ResolvedorVarianteCorteService $resolvedor): int
    {
        $tipoTela = $this->option('tipo-tela');

        if ($tipoTela !== null && ! ctype_digit((string) $tipoTela)) {
            $this->error('--tipo-tela debe ser el ID numerico de la opcion (attribute_options.id).');

            return self::INVALID;
        }

        try {
            $diagnostico = $resolvedor->diagnosticarCatalogo($tipoTela !== null ? (int) $tipoTela : null);
        } catch (ConfiguracionCatalogoCorteException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $skus = array_filter((array) $this->option('sku'));

        if ($skus) {
            $diagnostico = $this->filtrarPorSku($diagnostico, $skus);
        }

        if ($this->option('json')) {
            $this->line(json_encode($diagnostico, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->imprimir($diagnostico, (bool) $skus);

        return self::SUCCESS;
    }

    /**
     * Deja solo los hallazgos y configurables afectados que involucran alguno
     * de los SKU pedidos (como configurable o como simple).
     */
    protected function filtrarPorSku(array $diagnostico, array $skus): array
    {
        $coincide = fn (array $configurable) => in_array($configurable['sku'], $skus, true)
            || array_intersect($skus, array_column($configurable['hijos'], 'sku'));

        $diagnostico['hallazgos'] = array_values(array_filter(
            $diagnostico['hallazgos'],
            fn (array $hallazgo) => array_filter($hallazgo['configurables'], $coincide)
        ));

        $diagnostico['configurables_afectados'] = array_values(array_filter(
            $diagnostico['configurables_afectados'],
            fn (array $afectado) => $coincide($afectado['configurable'])
        ));

        $diagnostico['por_codigo'] = array_count_values(array_column($diagnostico['hallazgos'], 'codigo'));

        return $diagnostico;
    }

    protected function imprimir(array $diagnostico, bool $detalleCompleto): void
    {
        $totales = $diagnostico['totales'];

        $this->info(sprintf('Diagnostico de catalogo para Orden de Corte (solo lectura) - canal "%s"', $diagnostico['alcance']['canal']));
        $this->line(sprintf(
            'Configurables: %d | Simples: %d | Errores: %d | Advertencias: %d',
            $totales['configurables'],
            $totales['simples'],
            $totales['errores'],
            $totales['advertencias']
        ));

        if ($diagnostico['alcance']['tipo_tela_option_id'] !== null) {
            $this->line('Acotado a tipo_tela option_id = '.$diagnostico['alcance']['tipo_tela_option_id']);
        }

        $filas = [];

        foreach ($diagnostico['por_codigo'] as $codigo => $cantidad) {
            $severidad = collect($diagnostico['hallazgos'])->firstWhere('codigo', $codigo)['severidad'] ?? '';
            $filas[] = [$codigo, $severidad, $cantidad];
        }

        if ($filas) {
            $this->newLine();
            $this->table(['Codigo', 'Severidad', 'Hallazgos'], $filas);
        }

        $hallazgos = collect($diagnostico['hallazgos']);

        $this->imprimirHallazgos('ERRORES (inconsistencias de catalogo)', $hallazgos->where('severidad', ResolvedorVarianteCorteService::SEVERIDAD_ERROR));
        $this->imprimirHallazgos('ADVERTENCIAS (no bloquean produccion)', $hallazgos->where('severidad', ResolvedorVarianteCorteService::SEVERIDAD_ADVERTENCIA));

        $afectados = collect($diagnostico['configurables_afectados'])
            ->filter(fn (array $afectado) => $detalleCompleto || $afectado['errores']);

        if ($afectados->isNotEmpty()) {
            $this->newLine();
            $this->info($detalleCompleto ? 'CONFIGURABLES SELECCIONADOS' : 'CONFIGURABLES CON ERRORES');

            foreach ($afectados as $afectado) {
                $this->imprimirConfigurable($afectado);
            }
        }
    }

    protected function imprimirHallazgos(string $titulo, $hallazgos): void
    {
        if ($hallazgos->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->info($titulo);

        foreach ($hallazgos as $hallazgo) {
            $skus = collect($hallazgo['simples'])->isNotEmpty()
                ? collect($hallazgo['simples'])->pluck('sku')->implode(', ').' (padre '.$hallazgo['configurables'][0]['sku'].')'
                : collect($hallazgo['configurables'])->pluck('sku')->implode(', ');

            $detalle = $hallazgo['detalle'] ? ' '.json_encode($hallazgo['detalle'], JSON_UNESCAPED_UNICODE) : '';

            $this->line(sprintf('  [%s] %s: %s%s', $hallazgo['codigo'], $hallazgo['familia'], $skus, $detalle));
        }
    }

    protected function imprimirConfigurable(array $afectado): void
    {
        $configurable = $afectado['configurable'];

        $this->line(sprintf(
            '  %s "%s" (%s) - %s en tienda',
            $configurable['sku'],
            $configurable['nombre'] ?? 'sin nombre',
            $configurable['familia'],
            $configurable['activo'] ? 'activo' : 'INACTIVO'
        ));

        $hijos = collect($configurable['hijos'])->map(fn (array $hijo) => sprintf(
            '%s/%s%s',
            $hijo['color'] ?? '(sin color)',
            $hijo['eje'] ?? '(sin eje)',
            $hijo['activo'] ? '' : ' [inactivo]'
        ));

        $this->line('    hijos: '.($hijos->implode(', ') ?: '(sin hijos)'));

        if ($afectado['errores']) {
            $this->line('    errores: '.implode(', ', $afectado['errores']));
        }

        if ($afectado['advertencias']) {
            $this->line('    advertencias: '.implode(', ', $afectado['advertencias']));
        }
    }
}
