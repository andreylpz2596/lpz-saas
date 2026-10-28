<?php

/*
| DEMOSTRACION 2026-10-28 — recorridos de punta a punta por HTTP contra el servidor de la demo (los mismos endpoints que usa el panel).
| Reproducible sobre la base `lpz_saas_demo_*` preparada con preparar_demo.php. Nunca corre contra otra base.
|
|   DEMO_PASSWORD=... BASE_URL=http://127.0.0.1:8020 DB_DATABASE=lpz_saas_demo_20261027 php recorridos.php <A|B|C|D|E|F|G|H|todos>
|
| El servidor de la demo se levanta con (variables SOLO de ese proceso; el .env compartido no se toca):
|   cd public && DB_DATABASE=lpz_saas_demo_20261027 MAIL_MAILER=log QUEUE_CONNECTION=sync \
|     VENTASASESOR_COMISIONES_FECHA_INICIO=2026-10-26 LOGISTICA_RECAUDO_DESDE_COBRO=2026-10-28 \
|     php -S 127.0.0.1:8020 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
|
| TODAS las operaciones son peticiones HTTP autenticadas por rol (asesora, bodega, administrador): nada se fuerza por SQL. Lo unico
| precargado es lo que crea preparar_demo.php (usuarios, productos, clientes, acuerdo, fixture de 998 unidades). Los numeros que se
| imprimen al final de cada recorrido (unidades, inventario, reservas, obligacion, pagos, custodia, comision) se LEEN de la base.
| Sin correos, sin transportadoras ni cobros reales: las guias son ficticias y el servidor usa MAIL_MAILER=log.
*/

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$raiz = dirname(__DIR__, 5);
require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$base = (string) DB::selectOne('SELECT DATABASE() AS b')->b;

if (! str_starts_with($base, 'lpz_saas_demo_')) {
    fwrite(STDERR, "Bloqueado: solo corre en una base lpz_saas_demo_* (conectada: {$base}).\n");
    exit(1);
}

$clave = (string) getenv('DEMO_PASSWORD');
$url = rtrim((string) (getenv('BASE_URL') ?: 'http://127.0.0.1:8020'), '/');

if (strlen($clave) < 10) {
    fwrite(STDERR, "Falta DEMO_PASSWORD.\n");
    exit(1);
}

if (! in_array(parse_url($url, PHP_URL_HOST), ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "Bloqueado: BASE_URL debe ser local.\n");
    exit(1);
}

final class Sesion
{
    public CookieJar $jar;

    public Client $http;

    public function __construct(public string $url, public string $rol)
    {
        $this->jar = new CookieJar;
        $this->http = new Client(['base_uri' => $url, 'cookies' => $this->jar, 'http_errors' => false, 'allow_redirects' => false, 'timeout' => 120]);
    }

    public static function entrar(string $url, string $rol, string $email, string $clave): self
    {
        $s = new self($url, $rol);
        $login = (string) $s->http->get('/admin/login')->getBody();
        preg_match('/name="_token" value="([^"]+)"/', $login, $m);
        $r = $s->http->post('/admin/login', ['form_params' => ['_token' => $m[1] ?? '', 'email' => $email, 'password' => $clave]]);

        if ($r->getStatusCode() !== 302) {
            throw new RuntimeException("Login {$rol} fallo ({$r->getStatusCode()})");
        }

        return $s;
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    public function enviar(string $metodo, string $ruta, array $datos = [], array $archivos = []): array
    {
        $xsrf = $this->jar->getCookieByName('XSRF-TOKEN');
        $cabeceras = ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest', 'X-XSRF-TOKEN' => $xsrf ? urldecode($xsrf->getValue()) : ''];
        $opciones = ['headers' => $cabeceras];

        if ($archivos !== []) {
            $partes = [];

            foreach (self::plano($datos) as $k => $v) {
                $partes[] = ['name' => $k, 'contents' => (string) $v];
            }

            foreach ($archivos as $campo => $ruta2) {
                $partes[] = ['name' => $campo, 'contents' => fopen($ruta2, 'r'), 'filename' => basename($ruta2)];
            }

            $opciones['multipart'] = $partes;
        } elseif ($metodo !== 'GET') {
            $opciones['json'] = $datos;
        } else {
            $opciones['query'] = $datos;
        }

        $r = $this->http->request($metodo, $ruta, $opciones);
        $json = json_decode((string) $r->getBody(), true);

        return [$r->getStatusCode(), is_array($json) ? $json : ['_cuerpo' => mb_substr((string) $r->getBody(), 0, 300)]];
    }

    public function get(string $ruta, array $q = []): array
    {
        return $this->enviar('GET', $ruta, $q);
    }

    public function post(string $ruta, array $d = [], array $archivos = []): array
    {
        return $this->enviar('POST', $ruta, $d, $archivos);
    }

    /**
     * Formulario del panel (POST que responde con redireccion + mensaje flash). Sigue la redireccion con la MISMA sesion y devuelve
     * el mensaje que veria la persona. @return array{0: int, 1: array<string, mixed>}
     */
    public function formulario(string $ruta, array $datos, string $volver, array $archivos = []): array
    {
        $xsrf = $this->jar->getCookieByName('XSRF-TOKEN');
        $opciones = ['headers' => ['Accept' => 'text/html', 'X-XSRF-TOKEN' => $xsrf ? urldecode($xsrf->getValue()) : '', 'Referer' => $this->url.$volver]];

        if ($archivos !== []) {
            $opciones['multipart'] = array_map(fn ($k, $v) => ['name' => $k, 'contents' => (string) $v], array_keys(self::plano($datos)), self::plano($datos));

            foreach ($archivos as $campo => $archivo) {
                $opciones['multipart'][] = ['name' => $campo, 'contents' => fopen($archivo, 'r'), 'filename' => basename($archivo)];
            }
        } else {
            $opciones['form_params'] = $datos;
        }

        $r = $this->http->request('POST', $ruta, $opciones);
        $destino = $r->getHeaderLine('Location');
        $flash = ['tipo' => null, 'mensaje' => null];

        if ($destino !== '') {
            $html = (string) $this->http->get($destino, ['headers' => ['Accept' => 'text/html']])->getBody();

            if (preg_match('/data-flash="(\w+)"[^>]*>.*?<span>(.*?)<\/span>/s', $html, $m)) {
                $flash = ['tipo' => $m[1], 'mensaje' => html_entity_decode(strip_tags($m[2]))];
            } elseif (preg_match("/type: '(success|error)', message: '([^']*)'/", $html, $m)) {
                $flash = ['tipo' => $m[1], 'mensaje' => $m[2]];
            }
        }

        return [$r->getStatusCode(), ['location' => $destino, 'flash' => $flash['tipo'], 'mensaje' => $flash['mensaje']]];
    }

    private static function plano(array $a, string $prefijo = ''): array
    {
        $salida = [];

        foreach ($a as $k => $v) {
            $clave = $prefijo === '' ? (string) $k : $prefijo.'['.$k.']';
            is_array($v) ? $salida += self::plano($v, $clave) : $salida[$clave] = $v;
        }

        return $salida;
    }
}

/** Contexto de ejecucion: sesiones por rol, evidencia y utilidades. */
final class Demo
{
    public array $s = [];

    public array $evidencia = [];

    public function __construct(public string $url, string $clave)
    {
        foreach (['asesora' => 'demo.asesora', 'umbral' => 'demo.umbral', 'bodega' => 'demo.bodega', 'admin' => 'demo.admin'] as $rol => $u) {
            $this->s[$rol] = Sesion::entrar($url, $rol, $u.'@bylopez.test', $clave);
        }
    }

    public function paso(string $texto, array $r, array $esperado = [200, 201]): array
    {
        [$estado, $json] = $r;
        $ok = in_array($estado, $esperado, true);
        $detalle = $ok ? '' : ' → '.mb_substr(json_encode($json, JSON_UNESCAPED_UNICODE), 0, 260);
        echo sprintf("  [%s] %s (HTTP %d)%s\n", $ok ? 'OK' : 'FALLO', $texto, $estado, $detalle);

        if (! $ok) {
            throw new RuntimeException("Paso fallido: {$texto}");
        }

        return $json;
    }

    /** Un paso que DEBE ser rechazado por el servidor (la regla es el resultado esperado). */
    public function rechazo(string $texto, array $r, string $codigo): array
    {
        [$estado, $json] = $r;
        $ok = $estado >= 400 && (($json['codigo'] ?? null) === $codigo || str_contains(json_encode($json), $codigo));
        echo sprintf("  [%s] %s → rechazo esperado «%s» (HTTP %d, codigo %s)\n", $ok ? 'OK' : 'FALLO', $texto, $codigo, $estado, $json['codigo'] ?? '-');

        if (! $ok) {
            throw new RuntimeException("Rechazo no ocurrio: {$texto}");
        }

        return $json;
    }

    /** Formulario del panel que DEBE terminar en exito (mensaje flash «success»). */
    public function form(string $texto, string $rol, string $ruta, array $datos, string $volver, array $archivos = []): array
    {
        [$estado, $r] = $this->s[$rol]->formulario($ruta, $datos, $volver, $archivos);
        $ok = $estado === 302 && ($r['flash'] ?? null) !== 'error';
        echo sprintf("  [%s] %s (HTTP %d) %s\n", $ok ? 'OK' : 'FALLO', $texto, $estado, $r['mensaje'] ? '«'.mb_substr($r['mensaje'], 0, 140).'»' : '');

        if (! $ok) {
            throw new RuntimeException("Formulario fallido: {$texto}");
        }

        return $r;
    }

    /** Formulario que DEBE ser rechazado por el servidor (mensaje flash «error» con $fragmento). */
    public function formRechazo(string $texto, string $rol, string $ruta, array $datos, string $volver, string $fragmento = ''): array
    {
        [$estado, $r] = $this->s[$rol]->formulario($ruta, $datos, $volver);
        $ok = ($r['flash'] ?? null) === 'error' && ($fragmento === '' || stripos((string) $r['mensaje'], $fragmento) !== false);
        echo sprintf("  [%s] %s → rechazo esperado (HTTP %d) «%s»\n", $ok ? 'OK' : 'FALLO', $texto, $estado, mb_substr((string) $r['mensaje'], 0, 150));

        if (! $ok) {
            throw new RuntimeException("Rechazo no ocurrio: {$texto}");
        }

        return $r;
    }

    public static function clave(): string
    {
        return 'demo-'.bin2hex(random_bytes(10));
    }

    /** Comprobante FICTICIO (PNG de 1 px) para los pagos por transferencia. */
    public static function comprobante(): string
    {
        $ruta = sys_get_temp_dir().'/comprobante_demo_ficticio.png';
        file_put_contents($ruta, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));

        return $ruta;
    }

    /**
     * Venta completa por la API de Nueva venta (como la asesora). $lineas = [[producto_id, [variante_id => cantidad]], ...].
     *
     * @param  array<string, mixed>  $entrega
     */
    public function venta(int $clienteId, array $lineas, array $entrega, string $metodoPago, ?int $domiciliario = null, string $rol = 'asesora'): array
    {
        $a = $this->s[$rol];
        $b = $this->paso('Asesora inicia la venta', $a->post('/admin/ventas-asesor/nueva-venta/iniciar', ['customer_id' => $clienteId, 'canal' => 'whatsapp']));
        $id = (int) ($b['borrador']['id'] ?? $b['id'] ?? 0);

        foreach ($lineas as [$producto, $variantes]) {
            $this->paso("Agrega producto {$producto} ".json_encode($variantes), $a->post("/admin/ventas-asesor/nueva-venta/{$id}/tallas", ['producto_id' => $producto, 'cantidades' => $variantes]));
        }

        $e = $this->paso('Define entrega', $a->post("/admin/ventas-asesor/nueva-venta/{$id}/entrega", $entrega));

        if ($domiciliario !== null) {
            $this->paso('Elige domiciliario', $a->post("/admin/ventas-asesor/nueva-venta/{$id}/domiciliario", ['transportadora_id' => $domiciliario]));
        }

        $this->paso("Elige pago {$metodoPago}", $a->post("/admin/ventas-asesor/nueva-venta/{$id}/pago", ['metodo' => $metodoPago]));
        $estado = $this->paso('Lee el estado', $a->get("/admin/ventas-asesor/nueva-venta/{$id}"));
        $total = (float) ($estado['borrador']['totales']['grand_total'] ?? 0);
        $r = $this->paso("Confirma la venta (total {$total})", $a->post("/admin/ventas-asesor/nueva-venta/{$id}/confirmar", ['total_esperado' => $total]));

        return ['borrador' => $id, 'orderId' => (int) ($r['orderId'] ?? $r['order_id'] ?? $r['resultado']['orderId'] ?? 0), 'total' => $total, 'respuesta' => $r];
    }
}

$demo = new Demo($url, $clave);
$cual = strtoupper($argv[1] ?? 'TODOS');

foreach (glob(__DIR__.'/recorridos/*.php') ?: [] as $archivo) {
    require_once $archivo;
}

$mapa = ['A' => 'recorrido_a', 'B' => 'recorrido_b', 'C' => 'recorrido_c', 'D' => 'recorrido_d', 'E' => 'recorrido_e', 'F' => 'recorrido_f', 'G' => 'recorrido_g', 'H' => 'recorrido_h', 'PREP' => 'recorrido_prep', 'RENDIMIENTO' => 'recorrido_rendimiento', 'V' => 'recorrido_v'];
$aEjecutar = $cual === 'TODOS' ? array_slice($mapa, 0, 8, true) : [$cual => $mapa[$cual] ?? null];

foreach ($aEjecutar as $letra => $funcion) {
    if ($funcion === null || ! function_exists($funcion)) {
        fwrite(STDERR, "Recorrido {$letra} no definido.\n");

        continue;
    }

    echo "\n=== RECORRIDO {$letra} ===\n";
    $funcion($demo);
}
