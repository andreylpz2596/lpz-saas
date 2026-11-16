<?php

/*
| Pruebas de la ronda 2 sobre una COPIA DESECHABLE de la base limpia (nunca sobre la base limpia final).
|
|   source <entorno_pruebas.sh>   # DB_DATABASE=lpz_saas_preproduccion_pruebas_*, usuario restringido, cola/caché/correo aislados
|   php tools/limpieza-preproduccion/pruebas_ronda2.php <url servidor A> <url servidor B> <carpeta privada de evidencia>
|
| Dos servidores (`php -S`) con el mismo entorno sobre la misma copia: así dos peticiones llegan de verdad al mismo tiempo
| (el servidor embebido atiende una petición por vez). Requiere también el worker de Cargues.
|
| Usa la cuenta administrativa conservada con una contraseña TEMPORAL fijada solo en la copia desechable (la base final no
| se toca; su hash queda igual al del respaldo). Comprueba:
|   R1 acceso de la cuenta conservada y pantallas principales
|   R2 carga de clientes desde cero por Cargues («Clientes históricos — 99 Envíos»), y repetirla no duplica
|   R3 carga inicial de inventario por Cargues y reintento sin duplicar
|   R4 creación de usuarios con roles operativos, ficha de asesor y acceso de cada uno; gestión de roles
|   R5 numeración: tres pedidos y tres facturas seguidos (BYL-00000001-CO…, FAC-BYL-00000001-2026…)
|   R6 concurrencia: dos pedidos y dos facturas simultáneos, sin duplicados ni errores
*/

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Promise\Utils;
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
[$_, $urlA, $urlB, $carpeta] = $argv + [null, null, null, null];
foreach ([$urlA, $urlB] as $u) {
    if (! $u || ! in_array(parse_url($u, PHP_URL_HOST), ['127.0.0.1', 'localhost'], true)) {
        fwrite(STDERR, "Uso: php pruebas_ronda2.php http://127.0.0.1:<puertoA> http://127.0.0.1:<puertoB> <carpeta privada>\n");
        exit(2);
    }
}
if (! $carpeta || ! is_dir($carpeta)) {
    fwrite(STDERR, "Falta la carpeta privada de evidencia\n");
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

    public int $loginStatus = 0;

    public function __construct(public string $url, string $email, string $clave, ?CookieJar $jar = null)
    {
        $this->jar = $jar ?? new CookieJar;
        $this->http = new Client(['base_uri' => $url, 'cookies' => $this->jar, 'http_errors' => false, 'allow_redirects' => false, 'timeout' => 300]);
        if ($jar) {
            return; // reutiliza una sesión ya iniciada (misma cookie, otro servidor)
        }
        $login = (string) $this->http->get('/admin/login')->getBody();
        preg_match('/name="_token" value="([^"]+)"/', $login, $m);
        $r = $this->http->post('/admin/login', ['form_params' => ['_token' => $m[1] ?? '', 'email' => $email, 'password' => $clave]]);
        $this->loginStatus = $r->getStatusCode();
        if ($r->getStatusCode() !== 302 || str_contains($r->getHeaderLine('Location'), 'login')) {
            throw new RuntimeException('Login falló ('.$r->getStatusCode().' → '.$r->getHeaderLine('Location').')');
        }
    }

    public function pagina(string $ruta): int
    {
        return $this->http->get($ruta, ['headers' => ['Accept' => 'text/html']])->getStatusCode();
    }

    public function opciones(string $metodo, array $datos = [], array $archivos = [], bool $formulario = false): array
    {
        $xsrf = $this->jar->getCookieByName('XSRF-TOKEN');
        $op = ['headers' => ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest', 'X-XSRF-TOKEN' => $xsrf ? urldecode($xsrf->getValue()) : '']];
        if ($archivos) {
            $op['multipart'] = [];
            foreach ($datos as $k => $v) {
                $op['multipart'][] = ['name' => $k, 'contents' => (string) $v];
            }
            foreach ($archivos as $k => $ruta) {
                $op['multipart'][] = ['name' => $k, 'contents' => fopen($ruta, 'r'), 'filename' => basename($ruta)];
            }
        } elseif ($metodo === 'GET') {
            $op['query'] = $datos;
        } elseif ($formulario) {
            $op['form_params'] = $datos;
        } else {
            $op['json'] = $datos;
        }

        return $op;
    }

    public function json(string $metodo, string $ruta, array $datos = [], array $archivos = [], bool $formulario = false): array
    {
        $r = $this->http->request($metodo, $ruta, $this->opciones($metodo, $datos, $archivos, $formulario));
        $j = json_decode((string) $r->getBody(), true);

        return [$r->getStatusCode(), is_array($j) ? $j : ['_cuerpo' => mb_substr(strip_tags((string) $r->getBody()), 0, 300), '_location' => $r->getHeaderLine('Location')]];
    }
}

function esperar(int $id, array $estados): string
{
    $limite = time() + 300;
    do {
        $e = (string) DB::table('bylopez_cg_cargas')->where('id', $id)->value('estado');
        if (in_array($e, $estados, true)) {
            return $e;
        }
        sleep(2);
    } while (time() < $limite);

    return "timeout($e)";
}

function cargue(Sesion $s, string $tipoNombre, string $archivo): array
{
    $tipo = (int) DB::table('bylopez_cg_tipos')->where('nombre', $tipoNombre)->value('id');
    [$c, $j] = $s->json('POST', '/admin/cargues/cargas', ['tipo_id' => $tipo], ['archivo' => $archivo]);
    $id = (int) ($j['carga']['id'] ?? 0);
    if (! $id) {
        return ['id' => 0, 'estado' => "subida HTTP $c", 'detalle' => $j];
    }
    $sug = (array) ($j['carga']['encabezados']['sugerencias'] ?? []);
    [$cv, $jv] = $s->json('POST', "/admin/cargues/cargas/{$id}/validar", ['relaciones' => $sug]);
    $e = esperar($id, ['validada', 'fallida_validacion', 'fallida_lectura', 'fallida_preparacion']);
    if ($e !== 'validada') {
        return ['id' => $id, 'estado' => $e, 'http_validar' => $cv, 'validar' => $jv, 'error' => DB::table('bylopez_cg_cargas')->where('id', $id)->value('error_codigo')];
    }
    $rech = (int) (json_decode((string) DB::table('bylopez_cg_cargas')->where('id', $id)->value('contadores'), true)['filas_rechazadas'] ?? 0);
    [$ce] = $s->json('POST', "/admin/cargues/cargas/{$id}/ejecutar", ['confirmar_rechazadas' => $rech]);
    $e = esperar($id, ['completada', 'fallida_ejecucion']);

    return ['id' => $id, 'estado' => $e, 'http_ejecutar' => $ce, 'contadores' => json_decode((string) DB::table('bylopez_cg_cargas')->where('id', $id)->value('contadores'), true)];
}

function csv(string $carpeta, string $nombre, array $encabezados, array $filas): string
{
    $f = fopen($ruta = rtrim($carpeta, '/\\')."/$nombre", 'w');
    fputcsv($f, $encabezados);
    foreach ($filas as $fila) {
        fputcsv($f, $fila);
    }
    fclose($f);

    return $ruta;
}

// ---------------------------------------------------------------- preparación (solo en la copia desechable)
$adminId = 1;
$emailAdmin = (string) DB::table('admins')->where('id', $adminId)->value('email');
$hashOriginal = (string) DB::table('admins')->where('id', $adminId)->value('password');
$claveTemporal = 'tmp-'.bin2hex(random_bytes(10));
DB::table('admins')->where('id', $adminId)->update(['password' => Hash::make($claveTemporal)]);
file_put_contents(rtrim($carpeta, '/\\').'/.clave_temporal_admin_copia_desechable', $claveTemporal);

// ---------------------------------------------------------------- R1 acceso de la cuenta conservada
$s = new Sesion($urlA, $emailAdmin, $claveTemporal);
$rutas = ['/admin/dashboard', '/admin/customers', '/admin/catalog/products', '/admin/ventas-asesor/nueva-venta/pantalla',
    '/admin/cargues', '/admin/settings/users', '/admin/settings/roles', '/admin/configuration/sales/invoice_settings',
    '/admin/logistica/despachos', '/admin/ventas-asesor/asesores'];
$codigos = array_combine($rutas, array_map(fn ($r) => $s->pagina($r), $rutas));
caso('R1', 'La cuenta administrativa conservada inicia sesión y abre las pantallas principales (usuarios y roles incluidos)',
    $s->loginStatus === 302 && ! array_filter($codigos, fn ($c) => $c !== 200), ['login' => $s->loginStatus] + $codigos);

// ---------------------------------------------------------------- R2 clientes desde cero
$antesClientes = (int) DB::table('customers')->count();
$encClientes = ['nombre_destinatario', 'telefono_destinatario', 'direccion_destinatario', 'ciudad_destino', 'departamento',
    'numero_de_guia', 'transportadora', 'estado_del_envio', 'fecha_actualizacion'];
$filasClientes = [
    ['Cliente Sintetico Uno', '3009990001', 'Calle 1 # 2-03', 'Medellín', 'Antioquia', 'QA-SINT-0001', '', 'Entregada', '2026-11-10 10:00:00'],
    ['Cliente Sintetico Dos', '3009990002', 'Carrera 4 # 5-06', 'Bogotá, D.C.', 'Bogotá, D.C.', 'QA-SINT-0002', '', 'Entregada', '2026-11-10 11:00:00'],
    ['Cliente Sintetico Tres', '3009990003', 'Avenida 7 # 8-09', 'Santiago de Cali', 'Valle del Cauca', 'QA-SINT-0003', '', 'Entregada', '2026-11-10 12:00:00'],
];
$rc1 = cargue($s, 'Clientes históricos — 99 Envíos', csv($carpeta, 'SINTETICO_clientes_1.csv', $encClientes, $filasClientes));
$clientes = DB::table('bylopez_va_customer_phones')->whereIn('telefono_normalizado', ['+573009990001', '+573009990002', '+573009990003'])
    ->orderBy('telefono_normalizado')->pluck('customer_id', 'telefono_normalizado')->map(fn ($v) => (int) $v)->all();
$direcciones = DB::table('addresses')->whereIn('customer_id', array_values($clientes))->where('address_type', 'customer')->count();
caso('R2a', 'La carga de clientes crea 3 clientes con teléfono indexado y dirección sobre una base sin clientes',
    $antesClientes === 0 && $rc1['estado'] === 'completada' && count($clientes) === 3 && $direcciones === 3 && DB::table('customers')->count() === 3,
    ['antes' => $antesClientes, 'carga' => $rc1, 'clientes' => $clientes, 'direcciones' => $direcciones]);
$rc2 = cargue($s, 'Clientes históricos — 99 Envíos', csv($carpeta, 'SINTETICO_clientes_1_repetido.csv', $encClientes, $filasClientes));
caso('R2b', 'Repetir la misma carga de clientes no duplica clientes, teléfonos, direcciones ni antecedentes',
    $rc2['estado'] === 'completada' && DB::table('customers')->count() === 3 && DB::table('bylopez_va_customer_phones')->count() === 3
    && DB::table('addresses')->where('address_type', 'customer')->count() === 3 && DB::table('bylopez_va_cliente_envios_historicos')->count() === 3,
    ['carga' => $rc2['id'], 'estado' => $rc2['estado'], 'customers' => DB::table('customers')->count(), 'envios_historicos' => DB::table('bylopez_va_cliente_envios_historicos')->count()]);

// ---------------------------------------------------------------- R3 inventario inicial
[$A, $B] = [216, 217]; // variantes simples de 124DURZ-H (S, M)
$stock = fn (int $p) => (int) DB::table('product_inventories')->where('product_id', $p)->sum('qty');
$indice = fn (int $p) => (int) DB::table('product_inventory_indices')->where('product_id', $p)->sum('qty');
$movs = fn (int $p) => (int) DB::table('bylopez_cc_inventario_movimientos')->where('product_id', $p)->count();
$encInv = ['Nombre', 'SKU', 'Familia', 'Precio', 'Cantidad', 'ID', 'Estado', 'Categoría', 'Tipo'];
$filaInv = function (int $id, int $qty) {
    $p = DB::table('products')->where('id', $id)->first(['sku', 'type']);

    return ['SINTETICO QA', $p->sku, '', '', $qty, $id, 'Activo', '', $p->type];
};
$ri1 = cargue($s, 'Inventario — Actualizar cantidades (conteo físico)', csv($carpeta, 'SINTETICO_inventario_1.csv', $encInv, [$filaInv($A, 20), $filaInv($B, 20)]));
$f1 = ['stock' => [$stock($A), $stock($B)], 'indice' => [$indice($A), $indice($B)], 'movs' => [$movs($A), $movs($B)]];
$ri2 = cargue($s, 'Inventario — Actualizar cantidades (conteo físico)', csv($carpeta, 'SINTETICO_inventario_1_repetido.csv', $encInv, [$filaInv($A, 20), $filaInv($B, 20)]));
$f2 = ['stock' => [$stock($A), $stock($B)], 'movs' => [$movs($A), $movs($B)]];
caso('R3', 'La carga inicial de inventario fija 20 / 20 (stock e índice vendible) y repetirla no duplica cantidades ni movimientos',
    $ri1['estado'] === 'completada' && $f1['stock'] === [20, 20] && $f1['indice'] === [20, 20] && $f1['movs'] === [1, 1]
    && $ri2['estado'] === 'completada' && $f2['stock'] === [20, 20] && $f2['movs'] === [1, 1],
    ['carga1' => $ri1['id'], 'carga2' => $ri2['id']] + $f1 + ['tras_repetir' => $f2]);

// ---------------------------------------------------------------- R4 usuarios, roles y asesores
$roles = ['ASESOR VENTAS' => 2, 'BODEGA' => 4, 'TALLER CONFECCION' => 52, 'CORTADOR' => 12050];
$usuarios = [];
foreach ($roles as $nombreRol => $rolId) {
    $email = 'qa.'.strtolower(str_replace(' ', '.', $nombreRol)).'@bylopez.test';
    $clave = 'qa-'.bin2hex(random_bytes(8));
    [$cu, $ju] = $s->json('POST', '/admin/settings/users/create', ['name' => "QA sintético $nombreRol", 'email' => $email, 'password' => $clave,
        'password_confirmation' => $clave, 'role_id' => $rolId, 'status' => 1]);
    $usuarios[$nombreRol] = ['http' => $cu, 'id' => (int) DB::table('admins')->where('email', $email)->value('id'), 'email' => $email, 'clave' => $clave];
}
$asesorId = $usuarios['ASESOR VENTAS']['id'];
[$ca, $ja] = $s->json('POST', '/admin/ventas-asesor/asesores', ['admin_id' => $asesorId, 'nombre' => 'QA Asesor Sintético', 'telefono' => '3009990099', 'activo' => 1], [], true);
$ficha = DB::table('bylopez_va_asesores')->where('admin_id', $asesorId)->first();
$accesos = [];
foreach ($usuarios as $nombreRol => $u) {
    try {
        $su = new Sesion($urlA, $u['email'], $u['clave']);
        $accesos[$nombreRol] = ['login' => $su->loginStatus, 'dashboard' => $su->pagina('/admin/dashboard'), 'usuarios' => $su->pagina('/admin/settings/users'),
            'nueva_venta' => $su->pagina('/admin/ventas-asesor/nueva-venta/pantalla')];
    } catch (Throwable $e) {
        $accesos[$nombreRol] = ['error' => $e->getMessage()];
    }
}
$creados = ! array_filter($usuarios, fn ($u) => $u['http'] !== 200 || ! $u['id']);
$sinAccesoUsuarios = ! array_filter($accesos, fn ($a) => ($a['login'] ?? 0) !== 302 || ($a['usuarios'] ?? 200) === 200);
caso('R4a', 'La cuenta conservada crea 4 usuarios con roles operativos; cada uno inicia sesión y ninguno puede gestionar usuarios',
    $creados && $sinAccesoUsuarios, ['usuarios' => array_map(fn ($u) => ['http' => $u['http'], 'id' => $u['id']], $usuarios), 'accesos' => $accesos]);
caso('R4b', 'Ficha de asesor creada para el usuario con rol ASESOR VENTAS, que abre Nueva venta',
    $ca < 400 && $ficha && ($accesos['ASESOR VENTAS']['nueva_venta'] ?? 0) === 200, ['http' => $ca, 'ficha' => $ficha->id ?? null, 'nueva_venta' => $accesos['ASESOR VENTAS']['nueva_venta'] ?? null]);
[$cr] = $s->json('POST', '/admin/settings/roles/create', ['name' => 'QA rol sintético', 'description' => 'prueba', 'permission_type' => 'custom', 'permissions' => ['dashboard']], [], true);
$rolNuevo = (int) DB::table('roles')->where('name', 'QA rol sintético')->value('id');
[$cd] = $rolNuevo ? $s->json('DELETE', "/admin/settings/roles/edit/{$rolNuevo}") : [0];
caso('R4c', 'La cuenta conservada crea y elimina un rol', $rolNuevo > 0 && $cd === 200 && ! DB::table('roles')->where('id', $rolNuevo)->exists(),
    ['http_crear' => $cr, 'rol' => $rolNuevo, 'http_eliminar' => $cd]);

// ---------------------------------------------------------------- R5 / R6 numeración
function borrador(Sesion $s, int $cliente, int $variante): array
{
    $direccion = (int) DB::table('addresses')->where('customer_id', $cliente)->where('address_type', 'customer')->orderByDesc('default_address')->orderBy('id')->value('id');
    [, $jv] = $s->json('POST', '/admin/ventas-asesor/nueva-venta/iniciar', ['customer_id' => $cliente, 'canal' => 'whatsapp']);
    $b = (int) ($jv['borrador']['id'] ?? $jv['id'] ?? 0);
    $p = [];
    $p['tallas'] = $s->json('POST', "/admin/ventas-asesor/nueva-venta/{$b}/tallas", ['producto_id' => 215, 'cantidades' => [$variante => 1]]);
    $p['entrega'] = $s->json('POST', "/admin/ventas-asesor/nueva-venta/{$b}/entrega", ['envio' => ['tipo' => 'guardada', 'address_id' => $direccion], 'metodo' => 'flatrate_flatrate']);
    $p['domiciliario'] = $s->json('POST', "/admin/ventas-asesor/nueva-venta/{$b}/domiciliario", ['transportadora_id' => 9]);
    $p['pago'] = $s->json('POST', "/admin/ventas-asesor/nueva-venta/{$b}/pago", ['metodo' => 'cashondelivery']);
    [, $est] = $s->json('GET', "/admin/ventas-asesor/nueva-venta/{$b}");

    return ['id' => $b, 'total' => (float) ($est['borrador']['totales']['grand_total'] ?? 0),
        'pasos' => array_map(fn ($r) => $r[0].(($r[0] >= 400) ? ' '.json_encode($r[1], JSON_UNESCAPED_UNICODE) : ''), $p)];
}

$ids = array_values($clientes);
$pedidos = [];
$pasosPedidos = [];
for ($i = 0; $i < 3; $i++) {
    $b = borrador($s, $ids[$i], $A);
    [$cc, $jc] = $s->json('POST', "/admin/ventas-asesor/nueva-venta/{$b['id']}/confirmar", ['total_esperado' => $b['total']]);
    $pedidos[] = (int) ($jc['orderId'] ?? $jc['order_id'] ?? $jc['resultado']['orderId'] ?? 0);
    $pasosPedidos[] = $b['pasos'] + ['confirmar' => $cc.($cc >= 400 ? ' '.json_encode($jc, JSON_UNESCAPED_UNICODE) : '')];
}
$numPedidos = DB::table('orders')->whereIn('id', $pedidos)->orderBy('id')->pluck('increment_id')->all();
caso('R5a', 'Tres pedidos seguidos: BYL-00000001-CO, BYL-00000002-CO, BYL-00000003-CO', $numPedidos === ['BYL-00000001-CO', 'BYL-00000002-CO', 'BYL-00000003-CO'],
    ['pedidos' => $pedidos, 'numeros' => $numPedidos, 'pasos' => $pasosPedidos]);

function facturar(Sesion $s, int $pedido): array
{
    $items = DB::table('order_items')->where('order_id', $pedido)->whereNull('parent_id')->pluck('qty_ordered', 'id')->map(fn ($q) => (int) $q)->all();

    return ['/admin/sales/invoices/create/'.$pedido, ['invoice' => ['items' => $items]]];
}
$facturas = [];
foreach ($pedidos as $p) {
    [$ruta, $datos] = facturar($s, $p);
    $facturas[$p] = $s->json('POST', $ruta, $datos, [], true)[0];
}
$numFacturas = DB::table('invoices')->whereIn('order_id', $pedidos)->orderBy('id')->pluck('increment_id')->all();
caso('R5b', 'Tres facturas seguidas: FAC-BYL-00000001-2026 … 00000003-2026', $numFacturas === ['FAC-BYL-00000001-2026', 'FAC-BYL-00000002-2026', 'FAC-BYL-00000003-2026'],
    ['http' => $facturas, 'numeros' => $numFacturas]);

// R6: dos confirmaciones al mismo tiempo, una a cada servidor.
$sB = new Sesion($urlB, $emailAdmin, $claveTemporal, $s->jar);
$b4 = borrador($s, $ids[0], $B);
$b5 = borrador($s, $ids[1], $B);
$antesPedidos = (int) DB::table('orders')->max('id');
$t0 = microtime(true);
$resp = Utils::settle([
    $s->http->requestAsync('POST', "/admin/ventas-asesor/nueva-venta/{$b4['id']}/confirmar", $s->opciones('POST', ['total_esperado' => $b4['total']])),
    $sB->http->requestAsync('POST', "/admin/ventas-asesor/nueva-venta/{$b5['id']}/confirmar", $sB->opciones('POST', ['total_esperado' => $b5['total']])),
])->wait();
$durPedidos = round(microtime(true) - $t0, 2);
$httpPed = array_map(fn ($r) => $r['state'] === 'fulfilled' ? $r['value']->getStatusCode() : 'error', $resp);
$cuerpos = array_map(fn ($r) => $r['state'] === 'fulfilled' ? mb_substr((string) $r['value']->getBody(), 0, 300) : $r['reason']->getMessage(), $resp);
$nuevos = DB::table('orders')->where('id', '>', $antesPedidos)->orderBy('id')->pluck('increment_id', 'id')->all();
$todos = DB::table('orders')->pluck('increment_id')->all();
caso('R6a', 'Dos pedidos confirmados al mismo tiempo (dos servidores): ambos se crean con números distintos y consecutivos, sin errores',
    $httpPed === [200, 200] && array_values($nuevos) === ['BYL-00000004-CO', 'BYL-00000005-CO'] && count($todos) === count(array_unique($todos)),
    ['http' => $httpPed, 'segundos' => $durPedidos, 'nuevos' => $nuevos, 'cuerpos' => $httpPed === [200, 200] ? null : $cuerpos]);

$nuevosIds = array_keys($nuevos);
if (count($nuevosIds) === 2) {
    [$r1, $d1] = facturar($s, $nuevosIds[0]);
    [$r2, $d2] = facturar($sB, $nuevosIds[1]);
    $t0 = microtime(true);
    $resp = Utils::settle([
        $s->http->requestAsync('POST', $r1, $s->opciones('POST', $d1, [], true)),
        $sB->http->requestAsync('POST', $r2, $sB->opciones('POST', $d2, [], true)),
    ])->wait();
    $httpFac = array_map(fn ($r) => $r['state'] === 'fulfilled' ? $r['value']->getStatusCode() : 'error', $resp);
    $numF = DB::table('invoices')->whereIn('order_id', $nuevosIds)->orderBy('id')->pluck('increment_id')->all();
    $todasF = DB::table('invoices')->pluck('increment_id')->all();
    caso('R6b', 'Dos facturas creadas al mismo tiempo (dos servidores): números distintos, sin errores',
        count($numF) === 2 && count($todasF) === count(array_unique($todasF)) && ! array_filter($httpFac, fn ($c) => $c === 'error' || $c >= 500),
        ['http' => $httpFac, 'segundos' => round(microtime(true) - $t0, 2), 'numeros' => $numF, 'todas' => $todasF]);
}

// La contraseña temporal solo existe en la copia desechable; el hash original se repone tras las pruebas de navegador
// (se guarda aquí para reponerlo; la copia se descarta de todos modos).
file_put_contents(rtrim($carpeta, '/\\').'/.hash_original_admin_copia_desechable', $hashOriginal);

$archivo = rtrim($carpeta, '/\\').'/pruebas_ronda2_'.date('Ymd_His').'.json';
file_put_contents($archivo, json_encode(['base' => $base, 'resultados' => $resultados], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$fallas = count(array_filter($resultados, fn ($r) => ! $r['ok']));
echo "Evidencia: $archivo\nRESULTADO: ".(count($resultados) - $fallas).' de '.count($resultados)." casos pasan\n";
exit($fallas ? 1 : 0);
