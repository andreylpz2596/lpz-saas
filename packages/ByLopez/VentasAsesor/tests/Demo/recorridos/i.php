<?php

use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require_once __DIR__.'/foto.php';
require_once __DIR__.'/util.php';
require_once __DIR__.'/a.php';
require_once __DIR__.'/c.php';

/*
| RECORRIDO I — CARGUES / «99 Envíos — Envíos completos» (módulo existente, sin cambios), por HTTP como el administrador, con el WORKER real
| de la cola `cargues` (demo.sh worker). Archivo FICTICIO de 7 filas con las 28 columnas de la exportación real:
|
|   fila 1  DEMO-IR-569215 (Inter, D2)  «Entregada», contrapago 30.000      → aplicada, el recaudo coincide con el de la guía
|   fila 2  DEMO-IR-480283 (Inter, C)   estado NUEVO no catalogado           → aplicada literal (la lista de estados es abierta por decisión)
|   fila 3  DEMO-SV-665558 (Servientrega, D1, SIN cuenta corriente) «Entregada», contrapago 25.000 vs 20.000 de la guía
|                                                                            → aplicada CON OBSERVACION (discrepancia de recaudo)
|   fila 8  DEMO-IR-PENDIENTE (Inter, despachada, SIN entrega ni cobro en ByLopez) «Entregada» → aplicada, pero ByLopez NO registra la entrega,
|            NO confirma ningún pago, NO mueve custodia y NO habilita comisión: es lo que informa el proveedor, nada más
|   fila 4  guía desconocida                                                 → rechazada GUIA_NO_REGISTRADA
|   fila 5  código de proveedor sin equivalencia                              → rechazada TRANSPORTADORA_SIN_EQUIVALENCIA
|   fila 6  estado vacío                                                      → rechazada (motor: OBLIGATORIO_VACIO)
|   fila 7  fecha ilegible                                                    → rechazada (motor: formato de fecha)
|
| Se demuestra: trazabilidad carga → fila → transportadora → guía; rechazadas con motivo; repetir el MISMO archivo no duplica nada; y que
| el estado «Entregada» del proveedor NO registra entrega, NO confirma pagos y NO habilita la comisión (D1 sigue bloqueada).
*/
function recorrido_i(Demo $d): void
{
    $admin = $d->s['admin'];
    $tipoId = (int) DB::table('bylopez_cg_tipo_versiones')->where('procedimiento', 'sp_cg_envios_completos_v2')->value('tipo_id');

    if ($tipoId === 0) {
        throw new RuntimeException('El tipo 99 Envíos no está preparado: corra preparar_cargues.php.');
    }

    // --- ANTES: lo que el reporte del proveedor NO puede tocar ---
    $tablasIntocables = ['orders', 'bylopez_lg_guias', 'bylopez_lg_entregas', 'bylopez_lg_retornos', 'bylopez_lg_validaciones_entrega', 'bylopez_lg_recepciones_devolucion',
        'bylopez_va_pagos', 'bylopez_va_aplicaciones_pago', 'bylopez_va_pagos_eventos', 'bylopez_va_comision_lineas', 'bylopez_va_comision_reglas',
        'bylopez_lg_cuenta_movimientos', 'bylopez_lg_conciliaciones_movimiento'];
    $checksum = fn () => collect($tablasIntocables)->mapWithKeys(fn ($t) => [$t => (string) DB::selectOne("CHECKSUM TABLE `{$t}`")->Checksum])->all();
    $antes = $checksum();
    $pedidoD1 = (int) DB::table('bylopez_lg_guias')->where('numero_guia', 'DEMO-SV-665558')->value('order_id');
    $pedidoD2 = (int) DB::table('bylopez_lg_guias')->where('numero_guia', 'DEMO-IR-569215')->value('order_id');
    $fotoD1 = foto($pedidoD1);
    $fotoD2 = foto($pedidoD2);
    mostrar_foto('I0 · D1 (Servientrega, sin cuenta corriente) ANTES del cargue', $fotoD1);
    $estadosAntes = DB::table('bylopez_lg_guia_estados_proveedor')->count();
    $eventosAntes = DB::table('bylopez_lg_guia_estado_proveedor_eventos')->count();

    // --- Pedido despachado SIN entrega ni cobro registrados en ByLopez (solo existe el despacho y la guía) ---
    $guiaPend = DB::table('bylopez_lg_guias')->where('numero_guia', 'DEMO-IR-PENDIENTE')->first();

    if (! $guiaPend) {
        $cliente = (int) DB::table('customers')->where('first_name', 'Ana')->value('id');
        $op = $d->venta($cliente, [[215, [216 => 2]]], ['envio' => direccion_medellin('Ana', 'Demo Pendiente', '3009990202'), 'metodo' => 'flatrate_flatrate'], 'cashondelivery')['orderId'];
        despachar($d, $op, ['transportadora' => 'INTER_RAPIDISIMO', 'numero_guia' => 'DEMO-IR-PENDIENTE', 'origen_guia' => 'manual', 'flete_pagador' => 'cliente_al_recibir', 'valor_flete' => 16000], 'Bodega despacha por Inter (contra entrega); NO se registra entrega ni cobro');
        $guiaPend = DB::table('bylopez_lg_guias')->where('numero_guia', 'DEMO-IR-PENDIENTE')->first();
    }

    $pedidoP = (int) $guiaPend->order_id;
    $recaudoP = (string) number_format((float) $guiaPend->recaudo_esperado, 0, '', '');
    $fotoP = foto($pedidoP);
    mostrar_foto('I0 · pedido PENDIENTE (despachado; sin entrega ni cobro) ANTES del cargue', $fotoP);
    esperar_igual('Antes: 0 entregadas y ningún pago aplicado', [0, []], [$fotoP['unidades']['entregadas'], $fotoP['pagos']]);
    $entregasAntesP = (int) DB::table('bylopez_lg_entregas')->where('order_id', $pedidoP)->count();
    $antes = $checksum(); // la línea base se toma DESPUÉS de crear el pedido pendiente

    // --- Archivo ficticio ---
    $ruta = storage_path('logs/demo/cargues');
    @mkdir($ruta, 0777, true);
    $archivo = $ruta.'/envios_completos_FICTICIO_demo.xlsx';
    escribir_xlsx_envios($archivo, [
        ['g' => 'DEMO-IR-569215', 'tr' => '1', 'est' => 'Entregada', 'vc' => '30000', 'cp' => '1'],
        ['g' => 'DEMO-IR-480283', 'tr' => '1', 'est' => 'Estado nuevo sin catalogar', 'vc' => '0', 'cp' => ''],
        ['g' => 'DEMO-SV-665558', 'tr' => '2', 'est' => 'Entregada', 'vc' => '25000', 'cp' => '1'],
        ['g' => 'DEMO-XX-000000', 'tr' => '1', 'est' => 'Entregada', 'vc' => '10000', 'cp' => '1'],
        ['g' => 'DEMO-IR-480283', 'tr' => '9', 'est' => 'Entregada', 'vc' => '10000', 'cp' => '1', 'nom' => 'otra'],
        ['g' => 'DEMO-IR-000002', 'tr' => '1', 'est' => '', 'vc' => '10000', 'cp' => '1'],
        ['g' => 'DEMO-IR-000003', 'tr' => '1', 'est' => 'Entregada', 'vc' => '10000', 'cp' => '1', 'fa' => 'no-es-fecha'],
        ['g' => 'DEMO-IR-PENDIENTE', 'tr' => '1', 'est' => 'Entregada', 'vc' => $recaudoP, 'cp' => '1'],
    ]);
    echo '  Archivo ficticio: '.basename($archivo).' (8 filas, 28 columnas)'."\n";

    // --- Cargue 1: crear → validar (worker) → ejecutar (worker) ---
    $yaCargado = $estadosAntes >= 4;
    $c1 = cargue_completo($d, $admin, $tipoId, $archivo, 'Cargue 1', 4);
    $filas1 = $d->paso('Resultado por fila del cargue 1', $admin->get("/admin/cargues/cargas/{$c1}/filas", ['estado' => 'todas']));
    mostrar_filas_cargue($filas1);

    esperar_igual($yaCargado ? 'Filas aplicadas por el cargue 1 (base que ya recibió el archivo: 0, el resto sin cambio)' : 'Filas aplicadas por el cargue 1', $yaCargado ? 0 : 4, (int) DB::table('bylopez_cg_carga_datos')->where('carga_id', $c1)->where('estado_final', 'aplicada')->count());
    esperar_igual('Filas rechazadas por el cargue 1 (con motivo)', 4, (int) DB::table('bylopez_cg_carga_datos')->where('carga_id', $c1)->where('estado_final', 'rechazada')->count());
    $codigos = DB::table('bylopez_cg_carga_incidencias')->where('carga_id', $c1)->where('severidad', 'error')->pluck('codigo', 'fila_origen')->all();
    ksort($codigos);
    echo '  Motivos de rechazo por fila de origen: '.json_encode($codigos, JSON_UNESCAPED_UNICODE)."\n";
    esperar_igual('La guía desconocida se rechaza como GUIA_NO_REGISTRADA', true, in_array('GUIA_NO_REGISTRADA', $codigos, true));
    esperar_igual('El código sin equivalencia se rechaza como TRANSPORTADORA_SIN_EQUIVALENCIA', true, in_array('TRANSPORTADORA_SIN_EQUIVALENCIA', $codigos, true));

    // Trazabilidad: estado reportado → carga + fila + transportadora efectiva + guía.
    $rep = DB::table('bylopez_lg_guia_estados_proveedor as e')->join('bylopez_lg_guias as g', 'g.id', '=', 'e.guia_id')->orderBy('e.guia_id')
        ->get(['g.numero_guia', 'g.transportadora_codigo', 'e.estado_proveedor as estado_reportado', 'e.carga_id', 'e.fila_origen', 'e.recaudo_reportado', 'e.recaudo_interno', 'e.recaudo_diferencia as diferencia_recaudo', 'e.conciliacion_recaudo as conciliacion']);
    foreach ($rep as $r) {
        echo sprintf("  TRAZA guía %s · %s · «%s» · cargue #%d fila %d · recaudo reportado %s vs interno %s (%s)\n", $r->numero_guia, $r->transportadora_codigo, $r->estado_reportado, $r->carga_id, $r->fila_origen, $r->recaudo_reportado ?? '-', $r->recaudo_interno ?? '-', $r->conciliacion ?? '-');
    }
    esperar_igual('Estados del proveedor guardados (4 guías)', $yaCargado ? $estadosAntes : $estadosAntes + 4, (int) DB::table('bylopez_lg_guia_estados_proveedor')->count());
    esperar_igual('Eventos del historial (4)', $yaCargado ? $eventosAntes : $eventosAntes + 4, (int) DB::table('bylopez_lg_guia_estado_proveedor_eventos')->count());
    $d1 = $rep->firstWhere('numero_guia', 'DEMO-SV-665558');
    esperar_igual('D1: recaudo reportado 25.000 frente a 20.000 internos → discrepancia registrada, NO conciliada', ['25000.00', '20000.00', '5000.00'], [(string) $d1->recaudo_reportado, (string) $d1->recaudo_interno, (string) $d1->diferencia_recaudo]);
    esperar_igual('El estado nuevo sin catalogar se guarda literal', 'Estado nuevo sin catalogar', (string) $rep->firstWhere('numero_guia', 'DEMO-IR-480283')->estado_reportado);

    // Descarga de no cargadas con motivo comprensible.
    $csv = $d->s['admin']->http->get("/admin/cargues/cargas/{$c1}/descargas/rechazadas.csv");
    $dest = $ruta.'/no_cargadas_cargue_'.$c1.'.csv';
    file_put_contents($dest, (string) $csv->getBody());
    echo '  Descarga «no cargadas» (HTTP '.$csv->getStatusCode().', '.strlen((string) $csv->getBody()).' bytes) → '.basename($dest)."\n";
    esperar_igual('La descarga de no cargadas responde 200', 200, $csv->getStatusCode());
    $res = $d->s['admin']->http->get("/admin/cargues/cargas/{$c1}/descargas/resultado.csv");
    file_put_contents($ruta.'/resultado_completo_cargue_'.$c1.'.csv', (string) $res->getBody());

    // --- Cargue 2: EL MISMO archivo → sin duplicar ---
    $eventosTras1 = (int) DB::table('bylopez_lg_guia_estado_proveedor_eventos')->count();
    $c2 = cargue_completo($d, $admin, $tipoId, $archivo, 'Cargue 2 (mismo archivo)', 4);
    $porEstado2 = DB::table('bylopez_cg_carga_datos')->where('carga_id', $c2)->selectRaw('estado_final, COUNT(*) n')->groupBy('estado_final')->pluck('n', 'estado_final')->all();
    echo '  Cargue 2 por estado: '.json_encode($porEstado2)."\n";
    esperar_igual('Repetir el archivo: 4 filas «sin cambio» y 4 rechazadas otra vez', [4, 4, 0], [(int) ($porEstado2['sin_cambio'] ?? 0), (int) ($porEstado2['rechazada'] ?? 0), (int) ($porEstado2['aplicada'] ?? 0)]);
    esperar_igual('Repetir el archivo no crea eventos ni estados nuevos', [$eventosTras1, $yaCargado ? $estadosAntes : $estadosAntes + 4], [(int) DB::table('bylopez_lg_guia_estado_proveedor_eventos')->count(), (int) DB::table('bylopez_lg_guia_estados_proveedor')->count()]);

    // --- Separación: lo reportado por la transportadora NO es entrega, pago, custodia ni comisión ---
    $despues = $checksum();
    esperar_igual('CHECKSUM de guías, entregas, retornos, validaciones, pagos, aplicaciones, comisiones y cuentas: SIN CAMBIOS', $antes, $despues);
    $fotoD1b = foto($pedidoD1);
    $fotoD2b = foto($pedidoD2);
    mostrar_foto('I1 · D1 DESPUÉS del cargue (el proveedor dice «Entregada»)', $fotoD1b);
    esperar_igual('D1: obligación, pagos, custodia y comisión idénticos (la comisión sigue bloqueada, no se habilitó)', [$fotoD1['obligacion'], $fotoD1['comision']], [$fotoD1b['obligacion'], $fotoD1b['comision']]);
    esperar_igual('D2: obligación, pagos, custodia y comisión idénticos', [$fotoD2['obligacion'], $fotoD2['comision']], [$fotoD2b['obligacion'], $fotoD2b['comision']]);
    $linea = DB::table('bylopez_va_comision_lineas')->where('order_id', $pedidoD1)->first();
    echo '  Comisión de D1 tras el cargue: estado «'.($linea->estado ?? '-').'» (el reporte del proveedor no la habilita)'."\n";

    // El proveedor dijo «Entregada» para una guía que ByLopez NO ha entregado ni cobrado: no cambia nada del negocio.
    $fotoPb = foto($pedidoP);
    mostrar_foto('I1 · pedido PENDIENTE DESPUÉS del cargue (el proveedor dice «Entregada»)', $fotoPb);
    esperar_igual('Pendiente: el reporte «Entregada» NO registra entrega (0 entregadas, mismas entregas)', [0, $entregasAntesP], [$fotoPb['unidades']['entregadas'], (int) DB::table('bylopez_lg_entregas')->where('order_id', $pedidoP)->count()]);
    esperar_igual('Pendiente: ningún pago confirmado, el saldo sigue entero y la custodia en cero', [$fotoP['pagos'], $fotoP['obligacion']['saldo'], $fotoP['custodia']], [$fotoPb['pagos'], $fotoPb['obligacion']['saldo'], $fotoPb['custodia']]);
    esperar_igual('Pendiente: la comisión no se habilita', $fotoP['comision'], $fotoPb['comision']);
    $reportado = DB::table('bylopez_lg_guia_estados_proveedor')->where('guia_id', $guiaPend->id)->value('estado_proveedor');
    echo "  El proveedor reporta «{$reportado}» para DEMO-IR-PENDIENTE; en ByLopez el envío sigue sin entrega registrada ni validada.\n";

    // Logística › Entregas muestra el reporte aparte, sin registrar entrega.
    $ent = $d->s['bodega']->http->get('/admin/logistica/entregas', ['headers' => ['Accept' => 'text/html']]);
    echo '  Logística › Entregas (HTTP '.$ent->getStatusCode().") se muestra con el reporte del proveedor como dato aparte\n";
}

/** Sube, relaciona, valida (worker), ejecuta (worker) y espera. Devuelve el id de la carga. */
function cargue_completo(Demo $d, Sesion $admin, int $tipoId, string $archivo, string $titulo, int $confirmar): int
{
    echo "  --- {$titulo} ---\n";
    $r = $d->paso('Administrador sube el archivo', $admin->post('/admin/cargues/cargas', ['tipo_id' => $tipoId], ['archivo' => $archivo]), [200, 201]);
    $id = (int) $r['carga']['id'];
    $sug = (array) ($r['carga']['encabezados']['sugerencias'] ?? []);
    echo '  Sugeridas '.count($sug).' relaciones; el contrapago se elige a mano (columna AB «Aplica Contrapago»)'."\n";
    $sug['aplica_contrapago'] = 27;
    $d->paso('Relaciona columnas y pide validar (lo procesa el worker)', $admin->post("/admin/cargues/cargas/{$id}/validar", ['relaciones' => $sug]), [200, 202]);
    $est = esperar_carga($admin, $id, ['validada', 'fallida_validacion', 'fallida_lectura', 'fallida_preparacion']);
    esperar_igual('La carga termina validada (por el worker)', 'validada', $est['estado']);
    $res = $d->s['admin']->get("/admin/cargues/cargas/{$id}")[1]['resumen'] ?? [];
    echo '  Validación: '.json_encode(array_intersect_key($res, array_flip(['filas_total', 'filas_validas', 'filas_rechazadas', 'rechazadas_motor', 'rechazadas_procedimiento', 'confirmar_rechazadas'])))."\n";
    $d->paso('Ejecuta confirmando las rechazadas ('.$confirmar.')', $admin->post("/admin/cargues/cargas/{$id}/ejecutar", ['confirmar_rechazadas' => $confirmar]), [200, 202]);
    $est = esperar_carga($admin, $id, ['completada', 'fallida_ejecucion']);
    esperar_igual('La ejecución termina completada (por el worker)', 'completada', $est['estado']);

    return $id;
}

/** Espera (hasta 3 minutos) a que la carga llegue a uno de los estados; el trabajo lo hace el WORKER, no esta petición. */
function esperar_carga(Sesion $s, int $id, array $estados): array
{
    $limite = time() + 180;

    do {
        [, $p] = $s->get("/admin/cargues/cargas/{$id}/progreso");
        $estado = (string) ($p['estado'] ?? DB::table('bylopez_cg_cargas')->where('id', $id)->value('estado'));

        if (in_array($estado, $estados, true)) {
            return ['estado' => $estado, 'progreso' => $p];
        }

        sleep(2);
    } while (time() < $limite);

    throw new RuntimeException("La carga {$id} no llegó a ".implode('/', $estados)." (¿está corriendo el worker? demo.sh worker). Último: {$estado}");
}

function mostrar_filas_cargue(array $filas): void
{
    foreach ($filas['filas'] ?? [] as $f) {
        $guia = collect($f['identificacion'] ?? [])->firstWhere('etiqueta', 'Número de guía')['valor'] ?? '-';
        $comp = collect($f['complemento'] ?? []);
        $efectiva = $comp->firstWhere('etiqueta', 'Transportadora efectiva')['valor'] ?? '-';
        $guiaLg = $comp->firstWhere('etiqueta', 'Guía en Logística')['valor'] ?? '-';
        $motivo = collect($f['incidencias'] ?? [])->map(fn ($i) => ($i['codigo'] ?? '?').(isset($i['campo']) ? ' ('.$i['campo'].')' : ''))->implode(', ');
        echo sprintf("  fila %s · guía %s · %s · guía Logística %s · %s%s\n", $f['fila_origen'], $guia, $efectiva, $guiaLg, $f['estado'].($f['detalle'] ? ' ('.$f['detalle'].')' : ''), $motivo !== '' ? ' · motivo: '.$motivo : '');
    }
}

/** XLSX con las 28 columnas reales de la exportación y datos ficticios. */
function escribir_xlsx_envios(string $ruta, array $filas): void
{
    $enc = ['codigo_sucursal', 'valor_servicio', 'fecha_envio', 'numero_de_guia', 'transportadora', 'Producto', 'nombre_destinatario', 'direccion_destinatario', 'telefono_destinatario', 'ciudad_destino',
        'valor_comercial', 'estado_del_envio', 'nombre_sucursal', 'fecha_actualizacion', 'aplica_contrapago', 'seguro', 'valor_seguro_99', 'codigo', 'numero_guia', 'producto', 'destinatario',
        'direccion', 'telefono', 'ciudad', 'valor', 'estado', 'transportadora_nombre', 'Aplica Contrapago'];
    $libro = new Spreadsheet;
    $hoja = $libro->getActiveSheet()->setTitle('Envíos Completos');
    $hoja->fromArray($enc, null, 'A1');
    $i = 2;

    foreach ($filas as $o) {
        $o += ['fe' => '2026-10-20 10:00:00', 'fa' => '2026-10-27 15:30:00', 'vs' => '23426.22', 'seg' => '3337', 'tseg' => 'Seguro 99'];
        $cpt = $o['cp'] === '1' ? 'Si' : 'No';
        $nombre = $o['nom'] ?? ['1' => 'interrapidisimo', '2' => 'servientrega'][$o['tr']] ?? 'otra';
        $fila = ['668099', $o['vs'], $o['fe'], $o['g'], $o['tr'], 'CAMISETAS', 'Destinatario Ficticio', 'Calle 1 # 2-3', '3000000000', 'CIUDAD FICTICIA', $o['vc'], $o['est'], 'By Lopez', $o['fa'], $o['cp'], $o['tseg'], $o['seg'],
            '668099', $o['g'], 'CAMISETAS', 'Destinatario Ficticio', 'Calle 1 # 2-3', '3000000000', 'CIUDAD FICTICIA', $o['vc'], $o['est'], $nombre, $cpt];

        foreach ($fila as $col => $valor) {
            $hoja->setCellValueExplicit([$col + 1, $i], (string) $valor, DataType::TYPE_STRING);
        }

        $i++;
    }

    (new Xlsx($libro))->save($ruta);
}
