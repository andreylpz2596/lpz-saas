<?php

/*
|--------------------------------------------------------------------------
| Fixtures compartidos de las pruebas del plan de validacion (H01-H10, R01-R23).
| Prefijo "qa_" para no chocar con los helpers de otros archivos. Se cargan con
| require_once desde cada test. Construyen el flujo por los SERVICIOS reales
| (proveedor -> compra -> rollo -> corte -> reporte -> recepcion), nunca por SQL.
|--------------------------------------------------------------------------
*/

use ByLopez\CorteConfeccion\Enums\EstadoOrdenCorte;
use ByLopez\CorteConfeccion\Models\OrdenCorte;
use ByLopez\CorteConfeccion\Models\Taller;
use ByLopez\CorteConfeccion\Services\CompraTelaService;
use ByLopez\CorteConfeccion\Services\OrdenCorteService;
use ByLopez\CorteConfeccion\Services\ProveedorTelaService;
use ByLopez\CorteConfeccion\Services\RecepcionCorteService;
use ByLopez\CorteConfeccion\Services\TallerService;
use Illuminate\Support\Facades\Auth;
use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Models\AttributeOption;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

if (! function_exists('qa_admin')) {
    /** Admin con un rol de nombre dado; $permisos solo aplica si permission_type = custom. */
    function qa_admin(string $nombreRol, string $permissionType = 'all', array $permisos = []): Admin
    {
        $role = Role::factory()->create([
            'name' => $nombreRol,
            'permission_type' => $permissionType,
            'permissions' => $permisos,
        ]);

        return Admin::factory()->create(['role_id' => $role->id]);
    }

    function qa_taller(array $overrides = []): Taller
    {
        $usuarioId = array_key_exists('usuario_id', $overrides)
            ? $overrides['usuario_id']
            : qa_admin('TALLER CONFECCION', 'all')->id;

        return app(TallerService::class)->create(array_merge([
            'nombre' => 'Taller QA '.uniqid(),
            'nit' => null, 'contacto' => null, 'telefono' => null, 'direccion' => null,
            'estado' => 'activo', 'observacion' => null,
            'usuario_id' => $usuarioId,
        ], $overrides));
    }

    /**
     * Orden de corte REAL llevada hasta recepcion en bodega (y cierre si $cerrar).
     * $items: [['product' => Product, 'cantidad_recibida' => int, 'cantidad_defectuosa' => int], ...]
     * $opciones: peso_rollo (kg), merma (kg), cerrar (bool)
     */
    function qa_orden_corte_recibida(array $items, array $opciones = []): OrdenCorte
    {
        $pesoRollo = $opciones['peso_rollo'] ?? 300;
        $merma = $opciones['merma'] ?? 0;
        $cerrar = $opciones['cerrar'] ?? true;

        Auth::guard('admin')->setUser(qa_admin('QA Creador '.uniqid()));

        $atributo = Attribute::firstWhere('code', 'tipo_tela')
            ?? Attribute::factory()->create(['code' => 'tipo_tela', 'type' => 'select', 'admin_name' => 'Tipo de tela']);
        $tela = AttributeOption::factory()->for($atributo, 'attribute')->create(['admin_name' => 'Tela QA '.uniqid()]);
        $color = cc_test_color_option();

        $proveedor = app(ProveedorTelaService::class)->create([
            'nombre' => 'Proveedor QA '.uniqid(), 'nit' => (string) random_int(900000000, 999999999),
            'telefono' => '3000000000', 'contacto' => 'QA', 'direccion' => 'Calle QA',
            'tipo_tela_ids' => [$tela->id],
            'referencias' => [['tipo_tela_id' => $tela->id, 'color_option_id' => $color->id, 'color' => 'Blanco', 'referencia' => 'QA-REF', 'gramaje' => '180', 'valor_kilo_referencia' => 15500, 'estado' => 'activo']],
            'estado' => 'activo', 'observacion' => null,
        ]);

        $compra = app(CompraTelaService::class)->create([
            'codigo' => 'CT-QA-'.uniqid(), 'proveedor_id' => $proveedor->id, 'numero_factura' => null,
            'fecha_compra' => now()->toDateString(), 'descuento' => 0, 'impuesto' => 0, 'observacion' => null,
            'rollos' => [['codigo' => 'R-QA-'.uniqid(), 'tipo_tela_id' => $tela->id, 'referencia_tela_id' => $proveedor->referenciasTela()->first()->id, 'color' => 'Blanco', 'peso_inicial' => $pesoRollo, 'valor_kilo' => 15500]],
        ]);
        $rollo = $compra->rollos()->first();

        $servicio = app(OrdenCorteService::class);

        $orden = $servicio->create([
            'rollo_ids' => [$rollo->id],
            'cortador_id' => qa_admin('CORTADOR')->id,
            'fecha_entrega' => now()->toDateString(),
            'observacion' => null,
            'detalles' => collect($items)->map(fn ($p) => [
                'product_id' => $p['product']->id,
                'genero_linea' => 'Caballero',
                'talla' => $p['talla'] ?? '',
                'cantidad_proyectada' => $p['cantidad_recibida'] + $p['cantidad_defectuosa'],
            ])->values()->all(),
        ]);

        if ($opciones['sin_entrega'] ?? false) {
            return $orden->fresh()->load(['detalles', 'rollosCorte.rollo']);
        }

        $orden = $servicio->entregar($orden);
        $orden = $servicio->cambiarEstado($orden, EstadoOrdenCorte::EntregadoACortador->value, EstadoOrdenCorte::EnCorte->value);

        Auth::guard('admin')->setUser(qa_admin('QA Cortador Reporte '.uniqid()));
        $orden = $servicio->reportarResultado($orden, [
            'resultados' => $orden->detalles->values()->map(fn ($d, $i) => [
                'detalle_id' => $d->id,
                'cantidad_real_cortada' => $items[$i]['cantidad_recibida'] + $items[$i]['cantidad_defectuosa'],
            ])->all(),
        ]);

        if ($opciones['sin_recepcion'] ?? false) {
            return $orden->fresh()->load('detalles');
        }

        Auth::guard('admin')->setUser(qa_admin('QA Bodega '.uniqid()));
        app(RecepcionCorteService::class)->create($orden, [
            'fecha_recepcion' => now()->toDateString(),
            'merma_total' => $merma,
            'recibido_por' => 'QA Bodega',
            'observacion' => null,
            'detalles' => $orden->detalles->values()->map(fn ($d, $i) => [
                'orden_corte_detalle_id' => $d->id,
                'cantidad_recibida' => $items[$i]['cantidad_recibida'] + $items[$i]['cantidad_defectuosa'],
                'cantidad_defectuosa' => $items[$i]['cantidad_defectuosa'],
                'observacion' => null,
            ])->all(),
        ]);

        if ($cerrar) {
            $servicio->cerrar($orden->fresh());
        }

        return $orden->fresh()->load(['recepcion.detalles', 'ordenesConfeccion.detalles']);
    }
}

/*
|--------------------------------------------------------------------------
| Escenario maestro del plan (seccion 4): 1.000 aptas, Jairo 700 / Andrea 300.
|--------------------------------------------------------------------------
*/

use ByLopez\CorteConfeccion\Models\OrdenConfeccion;
use ByLopez\CorteConfeccion\Models\OrdenConfeccionDetalle;
use ByLopez\CorteConfeccion\Services\DisponibilidadConfeccionService;
use ByLopez\CorteConfeccion\Services\InventarioMovimientoService;
use ByLopez\CorteConfeccion\Services\OrdenConfeccionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\Product\Models\Product;

if (! function_exists('qa_escenario_maestro')) {
    /**
     * @return array{corte: OrdenCorte, jairo: Taller, andrea: Taller, ordJ: OrdenConfeccion, ordA: OrdenConfeccion, S: Product, M: Product, L: Product, admin: Admin}
     */
    function qa_escenario_maestro(): array
    {
        $admin = qa_admin('QA Admin Maestro '.uniqid());
        $S = Product::factory()->simple()->create();
        $M = Product::factory()->simple()->create();
        $L = Product::factory()->simple()->create();

        $corte = qa_orden_corte_recibida([
            ['product' => $S, 'cantidad_recibida' => 300, 'cantidad_defectuosa' => 0],
            ['product' => $M, 'cantidad_recibida' => 400, 'cantidad_defectuosa' => 0],
            ['product' => $L, 'cantidad_recibida' => 300, 'cantidad_defectuosa' => 0],
        ], ['peso_rollo' => 500]);

        Auth::guard('admin')->setUser($admin);

        $jairo = qa_taller(['nombre' => 'Taller Jairo QA '.uniqid()]);
        $andrea = qa_taller(['nombre' => 'Taller Andrea QA '.uniqid()]);

        $ordenes = app(OrdenConfeccionService::class)->distribuir([
            'orden_corte_id' => $corte->id,
            'asignaciones' => [
                ['taller_id' => $jairo->id, 'detalles' => [
                    ['product_id' => $S->id, 'cantidad' => 200],
                    ['product_id' => $M->id, 'cantidad' => 300],
                    ['product_id' => $L->id, 'cantidad' => 200],
                ]],
                ['taller_id' => $andrea->id, 'detalles' => [
                    ['product_id' => $S->id, 'cantidad' => 100],
                    ['product_id' => $M->id, 'cantidad' => 100],
                    ['product_id' => $L->id, 'cantidad' => 100],
                ]],
            ],
        ]);

        return ['corte' => $corte->fresh(), 'jairo' => $jairo, 'andrea' => $andrea, 'ordJ' => $ordenes[0], 'ordA' => $ordenes[1], 'S' => $S, 'M' => $M, 'L' => $L, 'admin' => $admin];
    }

    function qa_detalle(OrdenConfeccion $orden, Product $producto): OrdenConfeccionDetalle
    {
        return $orden->detalles()->where('product_id', $producto->id)->firstOrFail();
    }

    /** Responsabilidad vigente de un taller sobre una variante, sumando TODAS sus ordenes del corte (no anuladas). */
    function qa_vigente_taller(OrdenCorte $corte, Taller $taller, Product $producto): int
    {
        return (int) DB::table('bylopez_cc_ordenes_confeccion_detalles as d')
            ->join('bylopez_cc_ordenes_confeccion as o', 'o.id', '=', 'd.orden_confeccion_id')
            ->where('o.orden_corte_id', $corte->id)
            ->where('o.taller_id', $taller->id)
            ->where('o.estado', '!=', 'anulada')
            ->where('d.product_id', $producto->id)
            ->sum(DB::raw('d.cantidad_entregada - d.cantidad_reasignada_salida'));
    }

    function qa_total_taller(OrdenCorte $corte, Taller $taller, array $productos): int
    {
        return array_sum(array_map(fn ($p) => qa_vigente_taller($corte, $taller, $p), $productos));
    }

    /** Σ responsabilidades vigentes + saldo sin asignar de una variante (debe ser siempre la apta del corte). */
    function qa_conservacion(OrdenCorte $corte, Product $producto): int
    {
        $vigentes = (int) DB::table('bylopez_cc_ordenes_confeccion_detalles as d')
            ->join('bylopez_cc_ordenes_confeccion as o', 'o.id', '=', 'd.orden_confeccion_id')
            ->where('o.orden_corte_id', $corte->id)
            ->where('o.estado', '!=', 'anulada')
            ->where('d.product_id', $producto->id)
            ->sum(DB::raw('d.cantidad_entregada - d.cantidad_reasignada_salida'));

        return $vigentes + app(DisponibilidadConfeccionService::class)
            ->disponiblePara(OrdenCorte::findOrFail($corte->id), $producto->id);
    }

    function qa_payload_reasignar(OrdenConfeccion $origen, Taller $destino, array $lineas, array $over = []): array
    {
        return array_merge([
            'orden_confeccion_origen_id' => $origen->id,
            'taller_destino_id' => $destino->id,
            'idempotency_key' => (string) Str::uuid(),
            'motivo' => 'Jairo no alcanza la fecha de entrega',
            'situacion_fisica' => 'sin_entrega_fisica',
            'lineas' => collect($lineas)->map(fn ($l) => ['detalle_id' => $l[0], 'cantidad' => $l[1]] + (isset($l[2]) ? ['cantidad_en_proceso' => $l[2]] : []))->all(),
        ], $over);
    }

    /** Siembra el stock fisico + indice de una variante en la fuente activa unica del canal default. */
    function qa_sembrar_stock(Product $producto, int $cantidad): object
    {
        $source = app(InventarioMovimientoService::class)->resolverFuenteInventario();

        DB::table('product_inventories')->updateOrInsert(
            ['product_id' => $producto->id, 'inventory_source_id' => $source->id, 'vendor_id' => 0],
            ['qty' => $cantidad]
        );

        DB::table('product_inventory_indices')->updateOrInsert(
            ['product_id' => $producto->id, 'channel_id' => core()->getDefaultChannel()->id],
            ['qty' => $cantidad, 'created_at' => now(), 'updated_at' => now()]
        );

        return $source;
    }

    function qa_stock_fisico(Product $producto): int
    {
        return (int) DB::table('product_inventories')->where('product_id', $producto->id)->sum('qty');
    }
}
