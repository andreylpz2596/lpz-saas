<?php

use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

require_once __DIR__.'/foto.php';
require_once __DIR__.'/util.php';
require_once __DIR__.'/a.php';
require_once __DIR__.'/c.php';
require_once __DIR__.'/i.php';

/*
| RECORRIDO L — TESTING POR ASESORA (2026-11-04). Tres asesoras ficticias (preparar_asesores.php) hacen TODOS los flujos de venta, cada una
| con sus propios clientes, y el resto del circuito lo cumplen Bodega y Administracion por HTTP (nada se fuerza por SQL):
|
|   · Local Bogota con domiciliario (Brian): transferencia y contra entrega (cobro del domiciliario + liquidacion de su cuenta).
|   · Nacional por Inter Rapidisimo: contra entrega y transferencia, con GUIAS REALES tomadas del Excel de 99 Envios (Envios_Completos_2026-11-03):
|       «Entregada»             → el cargue v3 registra la entrega y la valida;
|       «Devolución ratificada» → el cargue v3 registra el retorno (Bodega recibe unos; otros quedan «por recibir»);
|       «Creado»               → solo estado reportado (el paquete sigue en transito).
|     Del archivo real se conservan numero de guia, estado, transportadora y flete; nombre, telefono y direccion del destinatario se SUSTITUYEN por
|     datos ficticios, y las fechas se llevan a hoy (el hito no puede ser anterior al despacho).
|   · Cobros reportados por Inter, liquidacion de su cuenta (recaudo − flete), pagos confirmados → comisiones.
|
| Es reanudable: cada pedido y cada paso guardan su resultado en storage/logs/demo/estado_L.json (borrarlo para repetir en otra base).
| Archivo real: G99_ARCHIVO (por defecto ~/Downloads/Envios_Completos_2026-11-03.xlsx).
*/

function l_estado(?string $clave = null, mixed $valor = null): mixed
{
    $ruta = storage_path('logs/demo/estado_L.json');
    $e = is_file($ruta) ? (json_decode((string) file_get_contents($ruta), true) ?: []) : [];

    if ($clave === null) {
        return $e;
    }

    if ($valor === null) {
        return $e[$clave] ?? null;
    }

    $e[$clave] = $valor;
    file_put_contents($ruta, json_encode($e, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    return $valor;
}

/** Ejecuta $f una sola vez (reanudable) y guarda su resultado. */
function l_una_vez(string $clave, callable $f): mixed
{
    return l_estado($clave) ?? l_estado($clave, $f());
}

/** Guias REALES del Excel de 99 Envios: [estado => [fila, ...]] solo Inter Rapidisimo con contrapago. */
function l_guias_reales(): array
{
    $archivo = getenv('G99_ARCHIVO') ?: (getenv('USERPROFILE') ?: getenv('HOME')).'/Downloads/Envios_Completos_2026-11-03.xlsx';

    if (! is_file($archivo)) {
        throw new RuntimeException("No se encontro el Excel de 99 Envios en {$archivo} (G99_ARCHIVO).");
    }

    $hoja = IOFactory::load($archivo)->getActiveSheet()->toArray(null, true, false, false);
    $salida = [];

    foreach (array_slice($hoja, 1) as $f) {
        if (($f[26] ?? '') !== 'interrapidisimo' || ($f[3] ?? '') === '' || ! ctype_digit((string) $f[3])) {
            continue;
        }

        $salida[(string) $f[11]][] = ['guia' => (string) $f[3], 'estado' => (string) $f[11], 'flete' => (float) $f[1], 'ciudad' => (string) $f[9], 'contrapago' => $f[27] === 'Si'];
    }

    return $salida;
}

/** Reporta y confirma el pago de un pedido; reanudable (si ya hay un pago aplicado, solo confirma lo que falte). */
function l_pagar(Demo $d, int $o, float $total, string $ref, string $rol): void
{
    $pago = DB::table('bylopez_va_aplicaciones_pago as a')->join('bylopez_va_pagos as p', 'p.id', '=', 'a.pago_id')->where('a.order_id', $o)->whereNull('a.anulada_en')->first(['p.id', 'p.estado']);

    if ($pago === null) {
        confirmar_pago($d, reportar_pago($d, $o, (string) (int) $total, $ref, $rol));

        return;
    }

    if (! in_array($pago->estado, ['confirmado', 'verificado'], true)) {
        confirmar_pago($d, (int) $pago->id);
    }
}

function l_direccion(string $nombre, string $apellido, string $telefono, string $ciudad, string $municipio, string $dpto): array
{
    return ['tipo' => 'puntual', 'direccion' => [
        'nombre' => $nombre, 'apellido' => $apellido, 'lineas' => ['Carrera 10 # 20-30 (DEMO)'], 'pais' => 'co', 'telefono' => $telefono,
        'municipio_codigo' => $municipio, 'ciudad' => $ciudad, 'departamento' => $dpto,
    ]];
}

/** Destinos nacionales ficticios (codigo DANE real de la ciudad). */
const L_DESTINOS = [
    ['Cali', '76001', 'VAC'], ['Barranquilla', '08001', 'ATL'], ['Bucaramanga', '68001', 'SAN'], ['Cartagena', '13001', 'BOL'], ['Medellin', '05001', 'ANT'], ['Pereira', '66001', 'RIS'],
];

function recorrido_l(Demo $d): void
{
    $url = $d->url;
    $clave = (string) getenv('DEMO_PASSWORD');

    foreach (['karen', 'wendy', 'laura'] as $k) {
        $d->s[$k] ??= Sesion::entrar($url, $k, "demo.{$k}@bylopez.test", $clave);
    }

    // Inter Rapidisimo liquida cuenta corriente (capacidad que activa Administracion, igual que en el recorrido D2).
    $inter = (int) DB::table('bylopez_lg_transportadoras')->where('codigo', 'INTER_RAPIDISIMO')->value('id');

    if (! DB::table('bylopez_lg_transportadoras')->where('id', $inter)->value('liquida_cuenta_corriente')) {
        $d->form('Administracion activa «liquida cuenta corriente» en Inter Rapidisimo', 'admin', "/admin/logistica/transportadoras/{$inter}/capacidades", ['liquida_cuenta_corriente' => 1, 'genera_guia_interna' => 0], '/admin/logistica/transportadoras', [], fn () => (bool) DB::table('bylopez_lg_transportadoras')->where('id', $inter)->value('liquida_cuenta_corriente'));
    }

    $reales = l_guias_reales();
    echo '  Excel real de 99 Envios (Inter): '.implode(' · ', array_map(fn ($e, $f) => $e.' '.count($f), array_keys($reales), $reales))."\n";
    // Guias reales distintas para cada pedido (las primeras de cada estado, en el orden del archivo).
    $pool = ['Entregada' => $reales['Entregada'] ?? [], 'Devolución ratificada' => $reales['Devolución ratificada'] ?? [], 'Creado' => $reales['Creado'] ?? []];
    // Al reanudar, las guias ya usadas por pedidos de corridas anteriores no se reutilizan.
    $usadas = [];

    foreach (l_estado() as $k => $v) {
        if (str_starts_with((string) $k, 'pedido_') && isset($v['guia'])) {
            $usadas[] = $v['guia'];
        }
    }

    foreach ($pool as $e => $lista) {
        $pool[$e] = array_values(array_filter($lista, fn ($g) => ! in_array($g['guia'], $usadas, true)));
    }

    $tomar = function (string $estado) use (&$pool): array {
        $g = array_shift($pool[$estado]);

        if ($g === null) {
            throw new RuntimeException("El Excel real no tiene suficientes guias en estado «{$estado}».");
        }

        return $g;
    };

    // ---------------------------------------------------------------- plan: asesora → pedidos
    //  tipo: local | inter ; pago: cashondelivery | moneytransfer ; uds ; guia: estado de la guia real (solo inter) ; recibir: Bodega recibe la devolucion
    $plan = [
        'karen' => [
            ['id' => 'K1', 'tipo' => 'local', 'pago' => 'moneytransfer', 'uds' => 3],
            ['id' => 'K2', 'tipo' => 'local', 'pago' => 'cashondelivery', 'uds' => 2],
            ['id' => 'K3', 'tipo' => 'inter', 'pago' => 'cashondelivery', 'uds' => 3, 'guia' => 'Entregada'],
            ['id' => 'K4', 'tipo' => 'inter', 'pago' => 'moneytransfer', 'uds' => 2, 'guia' => 'Entregada'],
            ['id' => 'K5', 'tipo' => 'inter', 'pago' => 'cashondelivery', 'uds' => 2, 'guia' => 'Devolución ratificada', 'recibir' => true],
            // Segunda tanda (con el costo ya CONFIRMADO por Administracion): estas si pueden quedar habilitadas.
            ['id' => 'K6', 'tipo' => 'inter', 'pago' => 'moneytransfer', 'uds' => 3, 'guia' => 'Entregada'],
            ['id' => 'K7', 'tipo' => 'local', 'pago' => 'moneytransfer', 'uds' => 2],
        ],
        'wendy' => [
            ['id' => 'W1', 'tipo' => 'inter', 'pago' => 'cashondelivery', 'uds' => 4, 'guia' => 'Entregada'],
            ['id' => 'W2', 'tipo' => 'inter', 'pago' => 'cashondelivery', 'uds' => 3, 'guia' => 'Devolución ratificada', 'recibir' => true],
            ['id' => 'W3', 'tipo' => 'inter', 'pago' => 'cashondelivery', 'uds' => 3, 'guia' => 'Devolución ratificada', 'recibir' => false],
            ['id' => 'W4', 'tipo' => 'local', 'pago' => 'moneytransfer', 'uds' => 5],
            ['id' => 'W5', 'tipo' => 'inter', 'pago' => 'cashondelivery', 'uds' => 2, 'guia' => 'Creado'],
            ['id' => 'W6', 'tipo' => 'inter', 'pago' => 'moneytransfer', 'uds' => 4, 'guia' => 'Entregada'],
        ],
        'laura' => [
            ['id' => 'L1', 'tipo' => 'local', 'pago' => 'cashondelivery', 'uds' => 2],
            ['id' => 'L2', 'tipo' => 'inter', 'pago' => 'cashondelivery', 'uds' => 2, 'guia' => 'Entregada'],
            ['id' => 'L3', 'tipo' => 'inter', 'pago' => 'moneytransfer', 'uds' => 3, 'guia' => 'Entregada'],
            ['id' => 'L4', 'tipo' => 'inter', 'pago' => 'cashondelivery', 'uds' => 3, 'guia' => 'Entregada'],
        ],
    ];

    // ---------------------------------------------------------------- 1) ventas y despachos
    $pedidos = [];
    $n = 0;

    foreach ($plan as $rol => $lista) {
        $clientes = DB::table('customers')->where('first_name', ucfirst($rol))->orderBy('id')->pluck('id')->map(fn ($i) => (int) $i)->all();
        echo "\n  ===== Asesora {$rol} =====\n";

        foreach ($lista as $i => $p) {
            $pedidos[$p['id']] = l_una_vez("pedido_{$p['id']}", function () use ($d, $rol, $p, $clientes, $i, $tomar, &$n) {
                $cliente = $clientes[$i % count($clientes)];
                $n++;
                // 2 lineas cuando son 3 o mas unidades (un caballero + una dama); devoluciones con una sola linea (la recepcion se ejecuta por linea).
                $lineas = $p['uds'] >= 3 && empty($p['recibir']) && ($p['guia'] ?? '') !== 'Devolución ratificada'
                    ? [[215, [216 => $p['uds'] - 1]], [425, [426 => 1]]]
                    : [[215, [217 => $p['uds']]]];
                echo "\n  --- {$p['id']} · {$p['tipo']} · {$p['pago']} · {$p['uds']} uds".(isset($p['guia']) ? " · guia real «{$p['guia']}»" : '')." ---\n";

                if ($p['tipo'] === 'local') {
                    $dir = direccion_de($d, $cliente, $rol);
                    $v = $d->venta($cliente, $lineas, ['envio' => ['tipo' => 'guardada', 'address_id' => $dir], 'metodo' => 'flatrate_flatrate'], $p['pago'], 9, $rol);
                    $o = $v['orderId'];
                    $zona = ['zona_id' => 1, 'transportadora' => 'BRIAN', 'flete_pagador' => 'cliente_al_recibir', 'valor_flete' => 12000];

                    if ($p['pago'] === 'moneytransfer') {
                        l_pagar($d, $o, $v['total'], 'DEMO-'.$p['id'], $rol);
                    }

                    $g = despachar($d, $o, $zona, "Bodega despacha {$p['id']} con el domiciliario Brian");

                    return ['orderId' => $o, 'envio' => (int) $g['envio_id'], 'total' => $v['total'], 'asesora' => $rol, 'plan' => $p];
                }

                [$ciudad, $muni, $dpto] = L_DESTINOS[$n % count(L_DESTINOS)];
                $v = l_una_vez("venta_{$p['id']}", fn () => $d->venta($cliente, $lineas, ['envio' => l_direccion(ucfirst($rol), 'Cliente '.($i + 1), '3009990'.str_pad((string) (100 + $n), 3, '0', STR_PAD_LEFT), $ciudad, $muni, $dpto), 'metodo' => $p['pago'] === 'moneytransfer' ? 'free_free' : 'flatrate_flatrate'], $p['pago'], null, $rol) + ['_' => 1]);
                $o = $v['orderId'];

                if ($p['pago'] === 'moneytransfer') {
                    l_pagar($d, $o, $v['total'], 'DEMO-'.$p['id'], $rol);
                }

                $real = $tomar($p['guia']);
                $forma = ['transportadora' => 'INTER_RAPIDISIMO', 'numero_guia' => $real['guia'], 'origen_guia' => 'manual', 'flete_pagador' => 'bylopez', 'motivo_pagador' => 'DEMO: ByLopez asume el flete', 'valor_flete' => (int) round($real['flete'])];
                $g = despachar($d, $o, $forma, "Bodega despacha {$p['id']} por Inter con la guia REAL {$real['guia']} (estado en 99 Envios: {$real['estado']})");

                return ['orderId' => $o, 'envio' => (int) $g['envio_id'], 'total' => $v['total'], 'asesora' => $rol, 'guia' => $real['guia'], 'estado_real' => $real['estado'], 'plan' => $p, 'flete' => (int) round($real['flete'])];
            });
        }
    }

    // ---------------------------------------------------------------- 2) entregas locales (Bodega) y cobro del domiciliario
    echo "\n  ===== Entregas locales =====\n";

    foreach ($pedidos as $id => $p) {
        if ($p['plan']['tipo'] !== 'local') {
            continue;
        }

        l_una_vez("entrega_{$id}", function () use ($d, $id, $p) {
            registrar_entrega($d, $p['envio']);

            if ($p['plan']['pago'] === 'cashondelivery') {
                $d->paso("{$id} · el domiciliario cobra {$p['total']} contra entrega", $d->s['admin']->post("/admin/logistica/entregas/envios/{$p['envio']}/cobrar", [
                    'monto' => (string) (int) $p['total'], 'medio_cobro' => 'contraentrega_recaudo', 'referencia' => "DEMO-COBRO-{$id}", 'fecha_pago' => now()->toDateString(), 'idempotency_key' => Demo::clave(),
                ]));
            }

            validar_entrega($d, $p['envio']);

            return true;
        });
    }

    // ---------------------------------------------------------------- 3) cargue del Excel (99 Envios · v3): entregas y devoluciones de las guias reales
    echo "\n  ===== Cargue del reporte de 99 Envios (v3) =====\n";
    $tipoId = (int) DB::table('bylopez_cg_tipo_versiones')->where('procedimiento', 'sp_cg_envios_completos_v3')->where('estado', 'activa')->value('tipo_id');

    if ($tipoId === 0) {
        throw new RuntimeException('El tipo 99 Envios v3 no esta activo: corra preparar_cargues_v3.php.');
    }

    $filas = [];

    // La 1a tanda (K1–L3) ya se cargo; cada cargue lleva solo las guias nuevas.
    foreach (['K3', 'K4', 'K5', 'W1', 'W2', 'W3', 'W5', 'L2', 'L3'] as $hecho) {
        l_estado("cargada_{$hecho}") ?? l_estado("cargada_{$hecho}", true);
    }

    foreach ($pedidos as $id => $p) {
        if (isset($p['guia']) && ! l_estado("cargada_{$id}")) {
            l_estado("cargada_{$id}", true);
            $guia = DB::table('bylopez_lg_guias')->where('numero_guia', $p['guia'])->first();
            $filas[] = ['g' => $p['guia'], 'tr' => '1', 'est' => $p['estado_real'], 'vc' => (string) (int) $guia->recaudo_esperado, 'cp' => $p['plan']['pago'] === 'cashondelivery' ? '1' : '0',
                'fe' => now()->format('Y-m-d H:i:s'), 'fa' => now()->format('Y-m-d H:i:s'), 'vs' => (string) $p['flete']];
        }
    }

    $carga = l_una_vez('cargue_'.count($pedidos), function () use ($d, $tipoId, $filas) {
        $ruta = storage_path('logs/demo/cargues');
        @mkdir($ruta, 0777, true);
        $archivo = $ruta.'/envios_completos_REALES_anonimizado_demo.xlsx';
        escribir_xlsx_envios($archivo, $filas);
        echo '  Archivo: '.basename($archivo).' ('.count($filas)." filas con guias reales de 99 Envios; destinatarios ficticios)\n";
        $c = cargue_completo($d, $d->s['admin'], $tipoId, $archivo, 'Cargue del reporte', 0);
        mostrar_filas_cargue($d->paso('Resultado por fila', $d->s['admin']->get("/admin/cargues/cargas/{$c}/filas", ['estado' => 'todas'])));

        return $c;
    });

    foreach ($pedidos as $id => $p) {
        if (! isset($p['guia'])) {
            continue;
        }

        $entregas = (int) DB::table('bylopez_lg_entregas')->where('shipment_id', $p['envio'])->where('estado', 'confirmado')->count();
        $validas = (int) DB::table('bylopez_lg_validaciones_entrega')->where('shipment_id', $p['envio'])->where('estado', 'confirmado')->count();
        $retornos = (int) DB::table('bylopez_lg_retornos')->where('shipment_id', $p['envio'])->where('estado', 'confirmado')->count();
        $esperado = match ($p['estado_real']) {
            'Entregada' => [1, 1, 0],
            'Devolución ratificada' => [0, 0, 1],
            default => [0, 0, 0],
        };
        esperar_igual("{$id} · guia {$p['guia']} «{$p['estado_real']}»: [entrega, validacion, retorno]", $esperado, [$entregas, $validas, $retornos]);
    }

    // ---------------------------------------------------------------- 4) Bodega recibe las devoluciones que llegaron
    echo "\n  ===== Recepcion de devoluciones en bodega =====\n";

    foreach ($pedidos as $id => $p) {
        if (empty($p['plan']['recibir'])) {
            continue;
        }

        l_una_vez("recepcion_{$id}", function () use ($d, $id, $p) {
            $ctx = ctx_envio($d, 'bodega', $p['envio']);
            $retorno = $ctx['devoluciones'][0];
            $lineas = [];

            foreach ($retorno['lineas'] ?? $retorno['items'] ?? [] as $l) {
                $cantidad = (int) ($l['esperadas'] ?? 0);
                $lineas[(int) $l['retorno_item_id']] = ['recibidas' => $cantidad, 'aptas' => $cantidad, 'novedad' => 0, 'defectos' => []];
            }

            $d->paso("{$id} · Bodega recibe la devolucion completa y en buen estado", $d->s['bodega']->post("/admin/logistica/entregas/devoluciones/{$retorno['id']}/recibir", [
                'fecha' => now()->toDateString(), 'idempotency_key' => Demo::clave(), 'lineas' => $lineas,
            ]));

            return true;
        });
    }

    // ---------------------------------------------------------------- 5) Inter: cobros reportados y liquidacion de la cuenta
    echo "\n  ===== Cuenta de Inter Rapidisimo =====\n";
    // Los pedidos de la 1a tanda (K1–L3) ya se cobraron y liquidaron en la corrida anterior.
    foreach (['K3', 'W1', 'L2'] as $hecho) {
        l_estado("cobro_inter_{$hecho}") ?? l_estado("cobro_inter_{$hecho}", true);
    }

    $nuevos = [];
    l_una_vez('inter_cobros_'.count($pedidos), function () use ($d, $pedidos, $inter, &$nuevos) {
        foreach ($pedidos as $id => $p) {
            if (($p['estado_real'] ?? '') !== 'Entregada' || $p['plan']['pago'] !== 'cashondelivery' || l_estado("cobro_inter_{$id}")) {
                continue;
            }

            $nuevos[] = $id;
            l_estado("cobro_inter_{$id}", true);

            $guia = DB::table('bylopez_lg_guias')->where('shipment_id', $p['envio'])->first(['id', 'recaudo_esperado']);
            $d->paso("{$id} · Inter reporta el cobro de ".(int) $guia->recaudo_esperado, $d->s['admin']->post("/admin/logistica/cuentas/{$inter}/cobro-reportado", [
                'guia_id' => $guia->id, 'valor' => (string) (int) $guia->recaudo_esperado, 'fecha' => now()->toDateString(), 'referencia' => "DEMO-INTER-{$id}", 'idempotency_key' => Demo::clave(),
            ]));
        }

        return true;
    });

    l_una_vez('inter_liquidacion_'.count($pedidos), function () use ($d, $pedidos, $inter, &$nuevos) {
        if ($nuevos === []) {
            echo '  Sin cobros nuevos de Inter que liquidar.
';

            return 0;
        }

        $c = $d->paso('Administracion abre la cuenta de Inter', $d->s['admin']->get("/admin/logistica/cuentas/{$inter}/contexto"))['contexto'];
        echo '  Cuenta Inter: '.$c['saldo']['texto'].' · resumen '.json_encode($c['resumen'], JSON_UNESCAPED_UNICODE)."\n";
        $recaudo = 0;
        $fletes = 0;

        foreach ($pedidos as $id => $p) {
            if (in_array($id, $nuevos, true)) {
                $recaudo += (int) DB::table('bylopez_lg_guias')->where('shipment_id', $p['envio'])->value('recaudo_esperado');
                $fletes += $p['flete'];
            }
        }

        $efectivo = max(0, $recaudo - $fletes);
        echo "  Recaudo de Inter {$recaudo} − fletes a cargo de ByLopez {$fletes} = {$efectivo} en efectivo\n";
        $prop = $d->paso('Vista previa de la liquidacion', $d->s['admin']->post("/admin/logistica/cuentas/{$inter}/propuesta", ['valor' => $efectivo, 'movimientos' => $c['liquidable']['ids']]));
        $efectivo = (int) ($prop['propuesta']['efectivo_sugerido'] ?? $efectivo);
        echo '  propuesta: '.json_encode($prop['propuesta']['totales'] ?? $prop['propuesta'], JSON_UNESCAPED_UNICODE)."\n";
        $d->paso("Liquidacion: Inter entrega {$efectivo} en efectivo y los fletes se compensan", $d->s['admin']->post("/admin/logistica/cuentas/{$inter}/liquidar", [
            'movimientos' => $c['liquidable']['ids'], 'valor' => $efectivo, 'observacion' => 'DEMO: recaudo menos fletes', 'referencia' => 'DEMO-LIQ-INTER-L', 'idempotency_key' => Demo::clave(),
        ]));

        return $efectivo;
    });

    // ---------------------------------------------------------------- 6) Brian: liquidacion
    l_una_vez('brian_liquidacion_'.count($pedidos), function () use ($d, $pedidos) {
        $brian = (int) DB::table('bylopez_lg_transportadoras')->where('codigo', 'BRIAN')->value('id');
        $c = $d->paso('Administracion abre la cuenta de Brian', $d->s['admin']->get("/admin/logistica/cuentas/{$brian}/contexto"))['contexto'];
        $efectivo = 0;

        foreach ($pedidos as $id => $p) {
            if ($p['plan']['tipo'] === 'local' && $p['plan']['pago'] === 'cashondelivery' && ! in_array($id, ['K2', 'L1'], true)) {
                $efectivo += (int) $p['total'];
            }
        }

        if ($efectivo === 0) {
            return 0;
        }

        $d->paso("Brian entrega {$efectivo} del recaudo contra entrega", $d->s['admin']->post("/admin/logistica/cuentas/{$brian}/liquidar", [
            'movimientos' => $c['liquidable']['ids'], 'valor' => $efectivo, 'observacion' => 'DEMO: recaudo local', 'referencia' => 'DEMO-LIQ-BRIAN-L', 'idempotency_key' => Demo::clave(),
        ]));

        return $efectivo;
    });

    // ---------------------------------------------------------------- 7) resultado por pedido y por asesora (lo que ve Administracion)
    echo "\n  ===== Resultado =====\n";
    $mes = date('Y-m');

    foreach ($plan as $rol => $lista) {
        $admId = (int) DB::table('admins')->where('email', "demo.{$rol}@bylopez.test")->value('id');
        echo "\n  {$rol}:\n";

        foreach ($lista as $pl) {
            $o = $pedidos[$pl['id']]['orderId'];
            $c = DB::table('bylopez_va_comision_lineas')->where('order_id', $o)->get(['estado', 'unidades_comisionables', 'monto'])->map(fn ($x) => "{$x->estado}/{$x->unidades_comisionables}/{$x->monto}")->implode(' ');
            echo sprintf("    %s pedido #%d · %s %s · %d uds · total %s · comision: %s\n", $pl['id'], $o, $pl['tipo'], $pl['pago'], $pl['uds'], number_format((float) $pedidos[$pl['id']]['total'], 0, ',', '.'), $c ?: '-');
        }
    }

    l_estado('terminado', ['fecha' => now()->toDateTimeString(), 'mes' => $mes]);
}
