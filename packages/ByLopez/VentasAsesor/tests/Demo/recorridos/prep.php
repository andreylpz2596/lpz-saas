<?php

use Illuminate\Support\Facades\DB;

require_once __DIR__.'/foto.php';
require_once __DIR__.'/util.php';

/*
| PREPARACION de los recorridos (paso 0), por los mismos endpoints del panel:
|  1. El administrador CONFIRMA el costo de las referencias demo (familias): sin costo confirmado al vender la comision queda en
|     «costo_sin_estado» y no se habilita (regla R3). Aplica a las ventas que se hagan DESPUES.
|  2. La fixture antigua de 998 unidades de la asesora DEMO (pedido sin despachar) se cancela con «Cancelar pendiente» para que el
|     acumulado de esa asesora no cruce el umbral por accidente. El ejemplo 998 + 5 usa una asesora EXCLUSIVA (preparar_demo.php).
*/
function recorrido_prep(Demo $d): void
{
    foreach (DB::table('bylopez_va_comision_lineas')->distinct()->pluck('familia_id')->filter()->all() as $familia) {
        $vigente = DB::table('bylopez_va_costo_estados')->where('familia_id', $familia)->whereNull('vigente_hasta')->where('estado', 'confirmado')->exists();

        if (! $vigente) {
            $d->paso("Administrador confirma el costo de la familia #{$familia}", $d->s['admin']->post('/admin/ventas-asesor/rentabilidad/estados-costo', [
                'familia_id' => $familia, 'estado' => 'confirmado', 'motivo' => 'DEMO: costo ficticio (6.000) confirmado para la presentacion',
            ]), [200, 302]);
        }
    }

    $asesora = (int) DB::table('admins')->where('email', 'demo.asesora@bylopez.test')->value('id');
    $viejo = DB::table('orders as o')->join('bylopez_va_order_attributions as at', 'at.order_id', '=', 'o.id')->join('customers as c', 'c.id', '=', 'o.customer_id')
        ->where('c.first_name', 'Fixture')->where('at.advisor_id', $asesora)->whereNotIn('o.status', ['canceled'])->value('o.id');

    if ($viejo !== null) {
        $d->paso("Administrador cancela lo pendiente de la fixture antigua #{$viejo} (998 uds)", $d->s['admin']->post("/admin/logistica/despachos/pedidos/{$viejo}/cancelar-pendiente", ['motivo' => 'DEMO: la fixture del umbral pasa a una asesora exclusiva']));
        mostrar_foto("fixture antigua #{$viejo} tras cancelar", foto((int) $viejo));
    }
}
