<?php

use ByLopez\Logistica\DataObjects\SolicitudDespacho;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

require_once __DIR__.'/../../VentasAsesor/tests/helpersRt04b1a.php';
require_once __DIR__.'/../../VentasAsesor/tests/helpersFase03b.php';
require_once __DIR__.'/../../VentasAsesor/tests/helpersPagB.php';

/*
| Ayudantes compartidos de las pruebas de Despacho V1 y de Corregir guia (solo lpz_saas_testing; datos de fixture).
*/

/** Pedido real de Bagisto SIN despachar: `$qty` unidades, bodega 1 con stock, direccion de envio y pago. */
function log2_pedido(int $qty = 5, array $o = []): array
{
    Mail::fake();
    $o += ['envio' => 'envio_nacional', 'pago' => 'cashondelivery', 'total' => 100000.0, 'stock' => null];

    ['pedido' => $pedido, 'item' => $item] = rt04b1a_pedido();

    $pedido->update([
        'shipping_method' => $o['envio'], 'shipping_title' => $o['envio'] === 'pickup' ? 'Recogida en bodega' : 'Envío nacional',
        'grand_total' => $o['total'], 'base_grand_total' => $o['total'],
    ]);
    DB::table('order_payment')->where('order_id', $pedido->id)->update(['method' => $o['pago']]);
    $item->update(['qty_ordered' => $qty, 'qty_shipped' => 0, 'qty_canceled' => 0, 'qty_refunded' => 0]);
    DB::table('product_inventories')->updateOrInsert(
        ['product_id' => $item->product_id, 'inventory_source_id' => 1, 'vendor_id' => 0],
        ['qty' => $o['stock'] ?? $qty + 10],
    );

    return ['pedido' => $pedido->fresh(), 'item' => $item->fresh()];
}

function log2_pdf(string $nombre = 'guia.pdf', string $extra = ''): UploadedFile
{
    return UploadedFile::fake()->createWithContent($nombre, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n".$extra."trailer<</Root 1 0 R>>\n%%EOF\n");
}

function log2_s(array $e, array $extra = []): SolicitudDespacho
{
    return new SolicitudDespacho(...$extra + [
        'orderId' => (int) $e['pedido']->id,
        'idempotencyKey' => 'dsp-'.Str::uuid(),
        'transportadora' => 'INTER_RAPIDISIMO',
        'numeroGuia' => 'G'.strtoupper(uniqid()),
        'origenGuia' => 'manual',
        'fletePagador' => 'cliente_al_recibir',
    ]);
}

function log2_error(callable $accion): ?string
{
    try {
        $accion();
    } catch (Invalida $e) {
        return $e->codigo;
    }

    return null;
}

// Permisos de BODEGA (#4) para Logistica. `corregir` se incluye: la operaria es quien se equivoca al digitar.
if (! defined('LOG2_PERMISOS_BODEGA')) {
    define('LOG2_PERMISOS_BODEGA', [
        'logistica', 'logistica.despachos', 'logistica.despachos.despachar', 'logistica.entregas', 'logistica.entregas.registrar',
        'logistica.guias', 'logistica.guias.ver', 'logistica.guias.archivo', 'logistica.guias.corregir',
    ]);
}

function log2_bodega()
{
    return f03b_admin('custom', LOG2_PERMISOS_BODEGA);
}

function log2_asesor()
{
    return f03b_admin('custom', ['ventas_asesor', 'ventas_asesor.operar_como_asesor', 'ventas_asesor.pedidos', 'ventas_asesor.pedidos.view_own']);
}
