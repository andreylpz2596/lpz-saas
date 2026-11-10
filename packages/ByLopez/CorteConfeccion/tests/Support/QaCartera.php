<?php

require_once __DIR__.'/QaFlujo.php';

use ByLopez\CorteConfeccion\Models\CarteraMovimiento;
use ByLopez\CorteConfeccion\Models\Taller;
use ByLopez\CorteConfeccion\Services\CarteraCuentaService;
use ByLopez\CorteConfeccion\Services\CarteraMovimientoService;
use ByLopez\CorteConfeccion\Services\OrdenConfeccionService;
use ByLopez\CorteConfeccion\Services\OrdenTarifaService;
use ByLopez\CorteConfeccion\Support\CarteraConfig;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\Product\Models\Product;

/*
|--------------------------------------------------------------------------
| Escenario de la cartera: el ejemplo de Jairo del plan.
|   OF-A: 600 asignadas, reportadas y recibidas por bodega (buenas) a $ 1.450  -> reconocido $ 870.000
|   OF-B: 100 asignadas y reportadas, pendientes de recibir                    -> estimado   $ 145.000
|--------------------------------------------------------------------------
*/

if (! defined('CC_PNG')) {
    define('CC_PNG', 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
}

if (! function_exists('cc_cuentas')) {
    function cc_cuentas(): CarteraCuentaService
    {
        return app(CarteraCuentaService::class);
    }

    function cc_movs(): CarteraMovimientoService
    {
        return app(CarteraMovimientoService::class);
    }

    function cc_recibir(object $orden, ?int $buenas = null): void
    {
        foreach ($orden->fresh()->retornos()->where('estado', 'pendiente_bodega')->get() as $retorno) {
            app(OrdenConfeccionService::class)->recibirBodega($retorno, ['detalles' => $retorno->detalles->map(fn ($d) => [
                'id' => $d->id,
                'cantidad_recibida' => $buenas ?? $d->cantidad_reportada,
                'cantidad_defectuosa' => $buenas === null ? 0 : $d->cantidad_reportada - $buenas,
                'cantidad_faltante' => 0,
                'observacion_bodega' => 'QA',
            ])->all()]);
        }
    }

    /**
     * @return array{admin: mixed, jairo: Taller, producto: Product, ordA: mixed, ordB: mixed, tela: int, familia: int}
     */
    function cc_escenario(string $tarifa = '1450', int $buenasA = 600, int $pendientesB = 100, bool $arrancar = true): array
    {
        $admin = qa_admin('Admin Cartera '.uniqid());
        $p = Product::factory()->simple()->create();
        $corte = qa_orden_corte_recibida([['product' => $p, 'cantidad_recibida' => $buenasA + $pendientesB + 50, 'cantidad_defectuosa' => 0]]);
        Auth::guard('admin')->setUser($admin);
        $jairo = qa_taller(['nombre' => 'Taller Jairo Cartera '.uniqid()]);

        $servicio = app(OrdenConfeccionService::class);
        $crear = fn (int $n) => $servicio->create(['orden_corte_id' => $corte->id, 'taller_id' => $jairo->id, 'detalles' => [['product_id' => $p->id, 'talla' => '', 'cantidad_entregada' => $n]]]);

        $ordA = $crear($buenasA);
        $ordB = $pendientesB > 0 ? $crear($pendientesB) : null;

        $combo = app(OrdenTarifaService::class)->combinaciones($ordA->fresh())['lineas'][0];

        foreach (array_filter([$ordA, $ordB]) as $orden) {
            app(OrdenTarifaService::class)->pactar($orden->fresh(), $combo['tipo_tela_id'], $combo['attribute_family_id'], $tarifa, 'Tarifa de prueba', $admin->id);
        }

        $detA = $ordA->detalles()->first();
        $servicio->reportarTaller($ordA->fresh(), ['detalles' => [['id' => $detA->id, 'cantidad' => $buenasA]]]);
        cc_recibir($ordA);

        if ($ordB) {
            $detB = $ordB->detalles()->first();
            $servicio->reportarTaller($ordB->fresh(), ['detalles' => [['id' => $detB->id, 'cantidad' => $pendientesB]]]);
        }

        if ($arrancar) {
            Auth::guard('admin')->setUser($admin);
            CarteraConfig::establecerFechaArranque(now()->subDay()->toDateString());
        }

        return ['admin' => $admin, 'jairo' => $jairo, 'producto' => $p, 'corte' => $corte, 'ordA' => $ordA->fresh(), 'ordB' => $ordB?->fresh(), 'tela' => $combo['tipo_tela_id'], 'familia' => $combo['attribute_family_id']];
    }

    /** Registra un movimiento con valores por defecto sensatos para las pruebas. */
    function cc_mov(Taller $p, string $tipo, string|int $valor, array $extra = []): CarteraMovimiento
    {
        return cc_movs()->registrar(array_merge([
            'tipo' => $tipo, 'prestador_tipo' => 'taller', 'prestador_id' => $p->id, 'valor' => (string) $valor,
            'fecha_efectiva' => now()->toDateString(), 'medio' => 'nequi', 'idempotency_key' => (string) Str::uuid(),
        ], $extra));
    }

    function cc_op(string $ref, string|int $valor, array $extra = []): array
    {
        return array_merge(['valor' => (string) $valor, 'fecha' => now()->toDateString(), 'banco_destino' => 'Nequi', 'banco_origen' => 'Bancolombia', 'referencia' => $ref, 'estado' => 'aceptada'], $extra);
    }

    function cc_count_movs(): int
    {
        return (int) DB::table('bylopez_cc_cartera_movimientos')->count();
    }
}
