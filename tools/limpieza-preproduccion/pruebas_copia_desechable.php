<?php

/*
| Pruebas de la RONDA 1 (base con clientes conservados); la ronda 2 usa pruebas_ronda2.php.
| Pruebas funcionales sobre una COPIA DESECHABLE de la base limpia (nunca sobre la base limpia final).
|
|   source <entorno_pruebas.sh>   # DB_DATABASE=lpz_saas_preproduccion_pruebas_*, usuario restringido, cola/caché/correo aislados
|   php tools/limpieza-preproduccion/pruebas_copia_desechable.php <url del servidor de pruebas> <carpeta privada de evidencia>
|
| Requiere el servidor (`php -S`) y el worker de Cargues levantados con el mismo entorno. Crea un administrador sintético solo en la
| copia desechable. Comprueba:
|   P1 navegación del panel (acceso, clientes, Nueva venta, Cargues, configuración)
|   P2 búsqueda de cliente y matriz de variantes con stock 0
|   P3 Nueva venta no deja vender una variante sin existencias
|   P4 carga de inventario inicial por Cargues («Inventario — Actualizar cantidades»): fija las cantidades del archivo
|   P5 reintentos: ejecutar otra vez la misma carga y subir el mismo archivo no duplican cantidades ni movimientos
|   P6 un conteo posterior fija el valor absoluto (no suma)
|   P7 venta con stock y despacho: si el stock físico vuelve a 0 antes de despachar, el despacho se rechaza
*/

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$raiz = dirname(__DIR__, 2);
require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$base = (string) DB::selectOne('SELECT DATABASE() AS b')->b;
if (! preg_match('/^lpz_saas_preproduccion_pruebas_[a-z0-9_]+$/', $base)) {
    fwrite(STDERR, "Bloqueado: solo corre en una copia desechable lpz_saas_preproduccion_pruebas_* (conectada: {$base}).\n");
    exit(2);
}
[$_, $url, $carpeta] = $argv + [null, null, null];
if (! $url || ! $carpeta || ! in_array(parse_url($url, PHP_URL_HOST), ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "Uso: php pruebas_copia_desechable.php http://127.0.0.1:<puerto> <carpeta privada>\n");
    exit(2);
}

$resultados = [];
function caso(string $id, string $texto, bool $ok, array $evidencia = []): void
{
    global $resultados;
    $resultados[] = compact('id', 'texto', 'ok', 'evidencia');
    echo sprintf("[%s] %s %s%s\n", $ok ? 'PASA' : 'FALLA', $id, $texto, $evidencia ? ' · '.json_encode($evidencia, JSON_UNESCAPED_UNICODE) : '');
}

final class Sesion
{
    public CookieJar $jar;

    public Client $http;

    public function __construct(string $url, string $email, string $clave)
    {
        $this->jar = new CookieJar;
        $this->http = new Client(['base_uri' => $url, 'cookies' => $this->jar, 'http_errors' => false, 'allow_redirects' => false, 'timeout' => 300]);
        $login = (string) $this->http->get('/admin/login')->getBody();
        preg_match('/name="_token" value="([^"]+)"/', $login, $m);
        $r = $this->http->post('/admin/login', ['form_params' => ['_token' => $m[1] ?? '', 'email' => $email, 'password' => $clave]]);
        if ($r->getStatusCode() !== 302 || str_contains($r->getHeaderLine('Location'), 'login')) {
            throw new RuntimeException('Login falló ('.$r->getStatusCode().' → '.$r->getHeaderLine('Location').')');
        }
    }

    public function pagina(string $ruta): int
    {
        return $this->http->get($ruta, ['headers' => ['Accept' => 'text/html']])->getStatusCode();
    }

    public function json(string $metodo, string $ruta, array $datos = [], array $archivos = []): array
    {
        $xsrf = $this->jar->getCookieByName('XSRF-TOKEN');
        $op = ['headers' => ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest', 'X-XSRF-TOKEN' => $xsrf ? urldecode($xsrf->getValue()) : '']];
        if ($archivos) {
            $op['multipart'] = [];
            foreach ($datos as $k => $v) {
                $op['multipart'][] = ['name' => $k, 'contents' => (string) $v];
            }
            foreach ($archivos as $k => $ruta2) {
                $op['multipart'][] = ['name' => $k, 'contents' => fopen($ruta2, 'r'), 'filename' => basename($ruta2)];
            }
        } elseif ($metodo === 'GET') {
            $op['query'] = $datos;
        } else {
            $op['json'] = $datos;
        }
        $r = $this->http->request($metodo, $ruta, $op);
        $j = json_decode((string) $r->getBody(), true);

        return [$r->getStatusCode(), is_array($j) ? $j : ['_cuerpo' => mb_substr(strip_tags((string) $r->getBody()), 0, 200)]];
    }
}

// ---------------------------------------------------------------- preparación (solo en la copia desechable)
$email = 'qa.limpieza@bylopez.test';
$clave = 'qa-'.bin2hex(random_bytes(8));
DB::table('admins')->updateOrInsert(['email' => $email], [
    'name' => 'QA Limpieza (sintético)', 'password' => Hash::make($clave), 'status' => 1, 'role_id' => 1,
    'created_at' => now(), 'updated_at' => now(),
]);
$adminId = (int) DB::table('admins')->where('email', $email)->value('id');
DB::table('bylopez_va_asesores')->updateOrInsert(['admin_id' => $adminId], ['nombre' => 'QA Limpieza', 'activo' => 1, 'created_at' => now(), 'updated_at' => now()]);
$s = new Sesion($url, $email, $clave);

[$A, $B, $C] = [216, 217, 426]; // variantes simples de 124DURZ-H (S, M) y 102DURZ-D (S)
$stock = fn (int $p) => (int) DB::table('product_inventories')->where('product_id', $p)->sum('qty');
$indice = fn (int $p) => (int) DB::table('product_inventory_indices')->where('product_id', $p)->sum('qty');
$movs = fn (int $p) => (int) DB::table('bylopez_cc_inventario_movimientos')->where('product_id', $p)->count();
$cliente = (int) DB::table('customers')->join('bylopez_va_customer_phones as t', 't.customer_id', '=', 'customers.id')
    ->whereExists(fn ($q) => $q->from('addresses')->whereColumn('addresses.customer_id', 'customers.id')->where('address_type', 'customer')->where('default_address', 1))
    ->orderBy('customers.id')->value('customers.id');
$direccion = (int) DB::table('addresses')->where('customer_id', $cliente)->where('address_type', 'customer')->where('default_address', 1)->orderBy('id')->value('id');

// ---------------------------------------------------------------- P1 navegación
$rutas = ['/admin/dashboard', '/admin/customers', '/admin/catalog/products', '/admin/catalog/products/edit/215',
    '/admin/ventas-asesor/nueva-venta/pantalla', '/admin/cargues', '/admin/configuration/sales/order_settings',
    '/admin/logistica/despachos', '/admin/ventas-asesor/cartera', '/admin/ventas-asesor/pedidos'];
$codigos = array_combine($rutas, array_map(fn ($r) => $s->pagina($r), $rutas));
caso('P1', 'El panel abre las pantallas principales sobre la base limpia', ! array_filter($codigos, fn ($c) => $c !== 200), $codigos);

// ---------------------------------------------------------------- P2 búsqueda y matriz
$tel = (string) DB::table('bylopez_va_customer_phones')->where('customer_id', $cliente)->value('telefono_normalizado');
[$c1, $j1] = $s->json('POST', '/admin/ventas-asesor/nueva-venta/clientes/buscar', ['telefono' => $tel]);
$encontrado = (int) ($j1['cliente']['customerId'] ?? 0) === $cliente;
caso('P2a', 'Búsqueda de un cliente conservado por teléfono', $c1 === 200 && $encontrado, ['http' => $c1, 'cliente_id' => $cliente]);
[$c2, $j2] = $s->json('GET', '/admin/ventas-asesor/nueva-venta/productos/215/matriz', ['customer_id' => $cliente]);
$txt = json_encode($j2);
caso('P2b', 'Matriz de variantes de 124DURZ-H con stock 0', $c2 === 200 && str_contains($txt, (string) $A), ['http' => $c2, 'extracto' => mb_substr($txt, 0, 220)]);

// ---------------------------------------------------------------- P3 vender sin existencias
[$c3, $j3] = $s->json('POST', '/admin/ventas-asesor/nueva-venta/iniciar', ['customer_id' => $cliente, 'canal' => 'whatsapp']);
$borrador = (int) ($j3['borrador']['id'] ?? $j3['id'] ?? 0);
[$c4, $j4] = $s->json('POST', "/admin/ventas-asesor/nueva-venta/{$borrador}/tallas", ['producto_id' => 215, 'cantidades' => [$A => 1]]);
caso('P3', 'Nueva venta rechaza agregar una variante con stock 0', $c4 >= 400, ['borrador' => $borrador, 'http' => $c4, 'codigo' => $j4['codigo'] ?? null, 'mensaje' => mb_substr((string) ($j4['mensaje'] ?? $j4['message'] ?? ''), 0, 160)]);

// ---------------------------------------------------------------- P4–P6 carga de inventario inicial
function archivo_conteo(string $carpeta, string $nombre, array $cantidades): string
{
    $f = fopen($ruta = rtrim($carpeta, '/\\')."/$nombre", 'w');
    fputcsv($f, ['Nombre', 'SKU', 'Familia', 'Precio', 'Cantidad', 'ID', 'Estado', 'Categoría', 'Tipo']);
    foreach ($cantidades as $id => $qty) {
        $p = DB::table('products')->where('id', $id)->first(['sku', 'type']);
        fputcsv($f, ['SINTETICO QA', $p->sku, '', '', $qty, $id, 'Activo', '', $p->type]);
    }
    fclose($f);

    return $ruta;
}

function esperar(Sesion $s, int $id, array $estados): string
{
    $limite = time() + 240;
    do {
        $e = (string) DB::table('bylopez_cg_cargas')->where('id', $id)->value('estado');
        if (in_array($e, $estados, true)) {
            return $e;
        }
        sleep(2);
    } while (time() < $limite);

    return "timeout($e)";
}

function cargue_conteo(Sesion $s, string $archivo): array
{
    $tipo = (int) DB::table('bylopez_cg_tipos')->where('nombre', 'Inventario — Actualizar cantidades (conteo físico)')->value('id');
    [$c, $j] = $s->json('POST', '/admin/cargues/cargas', ['tipo_id' => $tipo], ['archivo' => $archivo]);
    $id = (int) ($j['carga']['id'] ?? 0);
    if (! $id) {
        return ['id' => 0, 'estado' => "subida HTTP $c", 'detalle' => $j];
    }
    $sug = (array) ($j['carga']['encabezados']['sugerencias'] ?? []);
    $s->json('POST', "/admin/cargues/cargas/{$id}/validar", ['relaciones' => $sug]);
    $e = esperar($s, $id, ['validada', 'fallida_validacion', 'fallida_lectura', 'fallida_preparacion']);
    if ($e !== 'validada') {
        return ['id' => $id, 'estado' => $e];
    }
    $rech = (int) (json_decode((string) DB::table('bylopez_cg_cargas')->where('id', $id)->value('contadores'), true)['filas_rechazadas'] ?? 0);
    [$ce, $je] = $s->json('POST', "/admin/cargues/cargas/{$id}/ejecutar", ['confirmar_rechazadas' => $rech]);
    $e = esperar($s, $id, ['completada', 'fallida_ejecucion']);

    return ['id' => $id, 'estado' => $e, 'http_ejecutar' => $ce, 'contadores' => json_decode((string) DB::table('bylopez_cg_cargas')->where('id', $id)->value('contadores'), true)];
}

$antesMov = [$A => $movs($A), $B => $movs($B), $C => $movs($C)];
$r1 = cargue_conteo($s, archivo_conteo($carpeta, 'SINTETICO_conteo_1.csv', [$A => 5, $B => 3, $C => 0]));
$foto1 = ['stock' => [$A => $stock($A), $B => $stock($B), $C => $stock($C)], 'indice' => [$A => $indice($A), $B => $indice($B)], 'movs' => [$A => $movs($A), $B => $movs($B), $C => $movs($C)]];
caso('P4', 'Carga de inventario inicial fija 5 / 3 / 0 y registra un movimiento por variante cambiada',
    $r1['estado'] === 'completada' && $foto1['stock'] === [$A => 5, $B => 3, $C => 0] && $foto1['indice'] === [$A => 5, $B => 3]
    && $foto1['movs'][$A] === $antesMov[$A] + 1 && $foto1['movs'][$B] === $antesMov[$B] + 1 && $foto1['movs'][$C] === $antesMov[$C],
    ['carga' => $r1['id'], 'estado' => $r1['estado']] + $foto1);

[$cr, $jr] = $s->json('POST', "/admin/cargues/cargas/{$r1['id']}/ejecutar", ['confirmar_rechazadas' => 0]);
sleep(3);
$fotoR = ['stock' => [$A => $stock($A), $B => $stock($B)], 'movs' => [$A => $movs($A), $B => $movs($B)]];
caso('P5a', 'Volver a ejecutar la misma carga no cambia cantidades ni movimientos', $fotoR['stock'] === [$A => 5, $B => 3] && $fotoR['movs'] === [$A => $foto1['movs'][$A], $B => $foto1['movs'][$B]],
    ['http' => $cr, 'codigo' => $jr['codigo'] ?? null] + $fotoR);

$r2 = cargue_conteo($s, archivo_conteo($carpeta, 'SINTETICO_conteo_1_repetido.csv', [$A => 5, $B => 3, $C => 0]));
$foto2 = ['stock' => [$A => $stock($A), $B => $stock($B), $C => $stock($C)], 'movs' => [$A => $movs($A), $B => $movs($B), $C => $movs($C)]];
caso('P5b', 'Subir otra vez el mismo archivo deja 5 / 3 / 0 (sin cambio) y no agrega movimientos', $r2['estado'] === 'completada'
    && $foto2['stock'] === $foto1['stock'] && $foto2['movs'] === $foto1['movs'], ['carga' => $r2['id'], 'estado' => $r2['estado']] + $foto2);

$r3 = cargue_conteo($s, archivo_conteo($carpeta, 'SINTETICO_conteo_2.csv', [$A => 7]));
caso('P6', 'Un conteo posterior de 7 deja 7 (valor absoluto, no 12)', $r3['estado'] === 'completada' && $stock($A) === 7 && $indice($A) === 7,
    ['carga' => $r3['id'], 'stock' => $stock($A), 'indice' => $indice($A)]);

// ---------------------------------------------------------------- P7 venta con stock y despacho sin existencias físicas
$entrega = ['envio' => ['tipo' => 'guardada', 'address_id' => $direccion], 'metodo' => 'flatrate_flatrate'];
[, $jv] = $s->json('POST', '/admin/ventas-asesor/nueva-venta/iniciar', ['customer_id' => $cliente, 'canal' => 'whatsapp']);
$b2 = (int) ($jv['borrador']['id'] ?? $jv['id'] ?? 0);
$pasos = [];
$pasos['tallas'] = $s->json('POST', "/admin/ventas-asesor/nueva-venta/{$b2}/tallas", ['producto_id' => 215, 'cantidades' => [$A => 2]])[0];
$pasos['entrega'] = $s->json('POST', "/admin/ventas-asesor/nueva-venta/{$b2}/entrega", $entrega)[0];
$pasos['domiciliario'] = $pasos['entrega'] < 300 ? $s->json('POST', "/admin/ventas-asesor/nueva-venta/{$b2}/domiciliario", ['transportadora_id' => 9])[0] : null;
$pasos['pago'] = $s->json('POST', "/admin/ventas-asesor/nueva-venta/{$b2}/pago", ['metodo' => 'cashondelivery'])[0];
[, $est] = $s->json('GET', "/admin/ventas-asesor/nueva-venta/{$b2}");
$total = (float) ($est['borrador']['totales']['grand_total'] ?? 0);
[$cc, $jc] = $s->json('POST', "/admin/ventas-asesor/nueva-venta/{$b2}/confirmar", ['total_esperado' => $total]);
$pedido = (int) ($jc['orderId'] ?? $jc['order_id'] ?? $jc['resultado']['orderId'] ?? 0);
caso('P7a', 'Con stock 7, la venta de 2 unidades se confirma', $cc < 300 && $pedido > 0, ['pasos' => $pasos, 'http_confirmar' => $cc, 'pedido' => $pedido, 'total' => $total]);

if ($pedido) {
    $r4 = cargue_conteo($s, archivo_conteo($carpeta, 'SINTETICO_conteo_3_cero.csv', [$A => 0]));
    $antes = ['stock' => $stock($A), 'envios' => (int) DB::table('shipments')->where('order_id', $pedido)->count()];
    [$cd, $jd] = $s->json('POST', "/admin/logistica/despachos/pedidos/{$pedido}/despachar", ($pasos['domiciliario'] === 200
        ? ['zona_id' => 1, 'transportadora' => 'BRIAN', 'flete_pagador' => 'cliente_al_recibir', 'valor_flete' => 12000]
        : ['transportadora' => 'INTER_RAPIDISIMO', 'numero_guia' => 'QA-SINTETICA-0001', 'origen_guia' => 'manual', 'flete_pagador' => 'bylopez', 'motivo_pagador' => 'QA: prueba sin stock', 'valor_flete' => 10000])
        + ['idempotency_key' => 'qa-'.bin2hex(random_bytes(8))]);
    $despues = ['stock' => $stock($A), 'envios' => (int) DB::table('shipments')->where('order_id', $pedido)->count()];
    caso('P7b', 'Conteo físico deja la variante en 0 y el despacho del pedido se rechaza sin crear envío ni descontar stock',
        $r4['estado'] === 'completada' && $cd >= 400 && preg_match('/stock|existencia|inventario|disponib/i', json_encode($jd, JSON_UNESCAPED_UNICODE)) && $despues === $antes && $despues['stock'] === 0,
        ['carga_cero' => $r4['id'], 'http_despacho' => $cd, 'codigo' => $jd['codigo'] ?? null, 'mensaje' => mb_substr((string) ($jd['mensaje'] ?? $jd['message'] ?? json_encode($jd)), 0, 200), 'antes' => $antes, 'despues' => $despues]);
}

$archivo = rtrim($carpeta, '/\\').'/pruebas_copia_desechable_'.date('Ymd_His').'.json';
file_put_contents($archivo, json_encode(['base' => $base, 'resultados' => $resultados], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$fallas = count(array_filter($resultados, fn ($r) => ! $r['ok']));
echo "Evidencia: $archivo\nRESULTADO: ".(count($resultados) - $fallas).' de '.count($resultados)." casos pasan\n";
exit($fallas ? 1 : 0);
