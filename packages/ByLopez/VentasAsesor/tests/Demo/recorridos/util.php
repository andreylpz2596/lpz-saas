<?php

use ByLopez\VentasAsesor\Services\Comisiones\ConsultaComisionesService;
use Illuminate\Support\Facades\DB;
use Webkul\User\Models\Admin;

/** Ids de envio (Shipment) de un pedido, en orden de creacion. */
function envios_de(int $orderId): array
{
    return DB::table('shipments')->where('order_id', $orderId)->orderBy('id')->pluck('id')->map(fn ($i) => (int) $i)->all();
}

function ctx_envio(Demo $d, string $rol, int $envio): array
{
    $r = $d->paso("Abre el envio #{$envio} ({$rol})", $d->s[$rol]->get("/admin/logistica/entregas/envios/{$envio}/contexto"));

    return $r['contexto'];
}

/** Unidades pendientes de entregar de un envio: shipment_item_id => cantidad (opcionalmente limitadas a $maximo unidades en total). */
function pendientes_envio(array $ctx, ?int $maximo = null): array
{
    $items = [];
    $resto = $maximo;

    foreach ($ctx['productos'] as $p) {
        foreach ($p['filas'] as $f) {
            $n = (int) $f['pendientes'];

            if ($resto !== null) {
                $n = min($n, $resto);
                $resto -= $n;
            }

            if ($n > 0) {
                $items[(int) $f['shipment_item_id']] = $n;
            }
        }
    }

    return $items;
}

function registrar_entrega(Demo $d, int $envio, ?array $items = null, string $rol = 'bodega'): array
{
    $ctx = ctx_envio($d, $rol, $envio);
    $items ??= pendientes_envio($ctx);

    return $d->paso('Registra la entrega (bodega)', $d->s[$rol]->post("/admin/logistica/entregas/envios/{$envio}/drawer/entregar", [
        'fecha' => now()->toDateString(), 'items' => $items, 'idempotency_key' => Demo::clave(), 'evidencia_referencia' => 'DEMO foto firmada (ficticia)',
    ]));
}

function validar_entrega(Demo $d, int $envio): array
{
    return $d->paso('Administrador valida la entrega', $d->s['admin']->post("/admin/logistica/entregas/envios/{$envio}/validar", ['idempotency_key' => Demo::clave(), 'observacion' => 'DEMO: entrega verificada']));
}

/** Despacha por HTTP (bodega). $extra sobreescribe campos del formulario del drawer. */
function despachar(Demo $d, int $pedido, array $extra = [], string $texto = 'Bodega despacha y registra la guia'): array
{
    return $d->paso($texto, $d->s['bodega']->post("/admin/logistica/despachos/pedidos/{$pedido}/despachar", $extra + ['idempotency_key' => Demo::clave()]));
}

function reportar_pago(Demo $d, int $pedido, string $monto, string $referencia, string $rol = 'asesora'): int
{
    $r = $d->paso("Asesora reporta pago de {$monto} (transferencia, con comprobante ficticio)", $d->s[$rol]->post("/admin/ventas-asesor/pedidos/{$pedido}/pagos", [
        'monto' => $monto, 'medio_cobro' => 'transferencia', 'plataforma' => 'nequi', 'fecha_pago' => now()->toDateString(), 'referencia' => $referencia,
        'idempotency_key' => Demo::clave(),
    ], ['comprobante' => Demo::comprobante()]));

    return (int) ($r['pago']['id'] ?? $r['pago_id'] ?? $r['id'] ?? 0);
}

function confirmar_pago(Demo $d, int $pago): array
{
    return $d->paso("Administrador confirma el pago #{$pago}", $d->s['admin']->post("/admin/ventas-asesor/cartera/pagos/{$pago}/confirmar"));
}

/** Estado de la comision del pedido tal como la dejo la reevaluacion AUTOMATICA (sin pulsar «evaluar»). */
function comision_de(int $orderId): array
{
    return DB::table('bylopez_va_comision_lineas')->where('order_id', $orderId)->orderBy('id')
        ->get(['id', 'estado', 'cantidad_vendida', 'unidades_comisionables', 'monto', 'alerta'])->map(fn ($c) => (array) $c)->all();
}

/** Lo que muestra el modulo Comisiones para el pedido (lectura por el servicio de consulta del administrador). */
function pantalla_comision(int $orderId): array
{
    $admin = Admin::query()->where('email', 'demo.admin@bylopez.test')->firstOrFail();
    $p = app(ConsultaComisionesService::class)->detallePedido($admin, $orderId);

    return [
        'condiciones_pendientes' => $p['condiciones'] ?? [],
        'lineas' => array_map(fn ($l) => array_intersect_key($l, array_flip(['id', 'estado', 'alerta', 'vendidas', 'comisionables', 'estimada', 'habilitada', 'porcentaje', 'costo', 'base'])), $p['lineas'] ?? []),
    ];
}

function mostrar_comision(string $titulo, int $orderId): array
{
    $c = pantalla_comision($orderId);
    echo "  Comision · {$titulo}: pendientes=".json_encode($c['condiciones_pendientes']).' lineas='.json_encode($c['lineas'], JSON_UNESCAPED_UNICODE)."\n";

    return $c;
}

function esperar_igual(string $texto, mixed $esperado, mixed $observado): void
{
    $ok = $esperado == $observado;
    echo sprintf("  [%s] %s: esperado %s, observado %s\n", $ok ? 'OK' : 'FALLO', $texto, json_encode($esperado), json_encode($observado));

    if (! $ok) {
        throw new RuntimeException("Verificacion fallida: {$texto}");
    }
}
