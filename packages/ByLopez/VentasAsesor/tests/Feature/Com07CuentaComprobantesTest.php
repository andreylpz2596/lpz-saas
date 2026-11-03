<?php

use ByLopez\CorteConfeccion\Comprobantes\BorradorOperacion;
use ByLopez\VentasAsesor\Exceptions\OperacionComisionInvalidaException;
use ByLopez\VentasAsesor\Models\ComisionAbono;
use ByLopez\VentasAsesor\Models\ComisionLiquidacion;
use ByLopez\VentasAsesor\Models\ComisionSoporte;
use ByLopez\VentasAsesor\Services\Comisiones\AvanceAsesorService;
use ByLopez\VentasAsesor\Services\Comisiones\Comprobantes\FuenteAbonosAsesor;
use ByLopez\VentasAsesor\Services\Comisiones\ConsultaComisionesService;
use ByLopez\VentasAsesor\Services\Comisiones\CuentaAsesorService;
use ByLopez\VentasAsesor\Services\Comisiones\LiquidacionesComisionService;
use ByLopez\VentasAsesor\Services\Comisiones\MisComisionesService;
use ByLopez\VentasAsesor\Support\Comisiones\MesComercial;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use Webkul\Sales\Models\Order;

require_once __DIR__.'/../helpersCom6.php';

uses(TestCase::class);

/*
| CUENTA DE LA ASESORA Y COMPROBANTES DE PAGOS (jornada 2026-11-02). Solo lpz_saas_testing. Escenario base: 998 + 5 unidades, precio neto
| $10.000, costo $6.000, 10 % → 3 comisionables → $1.200, liquidado y pagado en abonos de $500 y $700 (calculado aparte, no leido del codigo).
| Los comprobantes son imagenes FICTICIAS dibujadas aqui; la lectura es el OCR local real (nada sale del servidor).
*/

/** @return array{e: array<string, mixed>, liq: ComisionLiquidacion, pedido: Order} */
function com7_liquidacion(): array
{
    $e = com4_escenario();
    com4_vender($e, 998);
    $pedido = com6_habilitar($e, 5);

    return ['e' => $e, 'liq' => com6_liquidar($e), 'pedido' => $pedido];
}

function com7_cuenta(array $e, ?string $mes = null): array
{
    return app(CuentaAsesorService::class)->deAsesor(f03b_admin('all'), (int) $e['asesor']->id, $mes ?? MesComercial::de(now()));
}

/** Abono manual por el servicio (la misma via del formulario). */
function com7_abonar($liq, array $datos, ?string $clave = null)
{
    return app(LiquidacionesComisionService::class)->registrarAbono(f03b_admin('all'), (int) $liq->id, $datos + ['fecha_pago' => now()->toDateString()], null, $clave ?? 'com7-ab-'.Str::uuid());
}

const COM7_LINEAS = ['Para', 'Karen Asesora', 'Cuanto?', '$ 500,00', 'Fecha', '02 de noviembre de 2026 a las 09:41 a. m.', 'Referencia', 'M07721456', 'Nequi'];

/** Sube un comprobante por la ruta real y devuelve [respuesta JSON, estado HTTP]. */
function com7_subir($test, $liq, string $png, string $nombre = 'comprobante.png', ?object $admin = null): array
{
    $r = $test->actingAs($admin ?? f03b_admin('all'), 'admin')->post(route('admin.ventas_asesor.comisiones.soportes.leer', $liq->id), ['comprobante' => com6_archivo($png, $nombre)], ['Accept' => 'application/json']);

    return [$r->json(), $r->getStatusCode()];
}

test('CU-1 la cuenta separa saldo ACUMULADO de la actividad del mes: 1.200 → abonos 500 y 700 → saldo 700 y luego 0, con reverso y sin doble descuento', function () {
    $e = com4_escenario();
    com4_vender($e, 998);
    $pedido = com6_habilitar($e, 5);
    com4_vender($e, 4);   // otro pedido, sin pagar: 4 comisionables × 400 = $1.600 ESTIMADOS (aun sin condiciones)

    $c = com7_cuenta($e);
    // Antes de liquidar: $1.200 habilitados y sin liquidar; $1.600 estimados pendientes; nada aprobado ni pagado.
    expect($c['acumulado']['habilitada_sin_liquidar'])->toBe('1200.00')->and($c['acumulado']['estimacion_pendiente'])->toBe('1600.00')
        ->and($c['acumulado']['saldo_aprobado'])->toBe('0.00')->and($c['acumulado']['pagos']['abonado'])->toBe('0.00');

    $liq = com6_liquidar($e);
    $c = com7_cuenta($e);
    expect($c['acumulado']['saldo_aprobado'])->toBe('1200.00')->and($c['acumulado']['pagable'])->toBe('1200.00')->and($c['acumulado']['retenido'])->toBe('0.00')
        ->and($c['acumulado']['habilitada_sin_liquidar'])->toBe('0.00')->and($c['acumulado']['estimacion_pendiente'])->toBe('1600.00');

    $a1 = com7_abonar($liq, ['monto' => '500', 'medio' => 'transferencia', 'referencia' => 'CU1-A']);
    $c = com7_cuenta($e);
    expect($c['acumulado']['saldo_aprobado'])->toBe('700.00')->and($c['acumulado']['pagos'])->toBe(['abonado' => '500.00', 'reversado' => '0.00', 'compensado' => '0.00', 'neto_pagado' => '500.00']);

    $a2 = com7_abonar($liq, ['monto' => '700', 'medio' => 'transferencia', 'referencia' => 'CU1-B']);
    $c = com7_cuenta($e);
    expect($c['acumulado']['saldo_aprobado'])->toBe('0.00')->and($c['acumulado']['pagos']['neto_pagado'])->toBe('1200.00');

    // Historial: cada pago con su fecha, medio, referencia, liquidacion y el pedido al que se aplico (el de la comision, no el de las 998).
    $mov = collect($c['movimientos']);
    expect($mov)->toHaveCount(2)->and($mov->firstWhere('referencia', 'CU1-A')['monto'])->toBe('500.00')->and($mov->firstWhere('referencia', 'CU1-B')['liquidacion'])->toBe($liq->numero())
        ->and($mov->firstWhere('referencia', 'CU1-B')['pedidos'])->toBe([(string) $pedido->increment_id]);

    // El mes filtrado NO oculta la deuda ni los pagos: otro mes cambia la ACTIVIDAD, nunca el acumulado.
    $otro = com7_cuenta($e, now()->addMonth()->format('Y-m'));
    expect($otro['actividad']['pedidos'])->toBe([])->and($otro['acumulado'])->toBe($c['acumulado']);

    // Reverso del segundo abono: vuelve el saldo, se diferencia de los abonos y no se descuenta dos veces.
    app(LiquidacionesComisionService::class)->revertirAbono(f03b_admin('all'), (int) $a2->id, 'Transferencia devuelta por el banco', 'com7-rev-'.Str::uuid());
    $c = com7_cuenta($e);
    expect($c['acumulado']['saldo_aprobado'])->toBe('700.00')->and($c['acumulado']['pagos'])->toBe(['abonado' => '1200.00', 'reversado' => '700.00', 'compensado' => '0.00', 'neto_pagado' => '500.00']);
    $mov = collect($c['movimientos']);
    expect($mov->where('tipo', 'reverso'))->toHaveCount(1)->and($mov->firstWhere('referencia', 'CU1-B')['reversado'])->toBeTrue()
        ->and($mov->firstWhere('referencia', 'CU1-A')['reversado'])->toBeFalse();
    expect((int) $a1->id)->toBeGreaterThan(0);
});

test('CU-2 una asesora no ve ni opera la cuenta de otra: el servicio la rechaza y la pantalla solo muestra la propia', function () {
    $this->withoutVite();
    ['e' => $a, 'liq' => $liqA] = com7_liquidacion();
    $b = com4_escenario();
    com4_vender($b, 3);
    com6_como_en_roles($a['asesor']);
    com6_como_en_roles($b['asesor']);

    expect(fn () => app(CuentaAsesorService::class)->deAsesor($b['asesor'], (int) $a['asesor']->id, MesComercial::de(now())))->toThrow(OperacionComisionInvalidaException::class);
    expect(app(CuentaAsesorService::class)->deAsesor($b['asesor'], (int) $b['asesor']->id, MesComercial::de(now()))['acumulado']['saldo_aprobado'])->toBe('0.00');

    // Pantalla: pedir la cuenta de otra no la muestra (el servicio toma a la autenticada).
    $url = route('admin.ventas_asesor.comisiones.index', ['vista' => 'liquidaciones', 'asesor' => $a['asesor']->id]);
    $html = $this->actingAs($b['asesor'], 'admin')->get($url)->assertOk()->getContent();
    expect($html)->not->toContain($liqA->numero())->not->toContain('1.200');

    // Y no puede cargar un comprobante ni ver el archivo de otra persona.
    $antes = [DB::table('bylopez_va_comision_soportes')->count(), DB::table('bylopez_va_comision_abonos')->count()];
    $png = com6_imagen_comprobante(COM7_LINEAS);

    if ($png !== null) {
        [, $codigo] = com7_subir($this, $liqA, $png, 'c.png', $b['asesor']);
        expect($codigo)->toBeIn([401, 403]);
    }

    expect([DB::table('bylopez_va_comision_soportes')->count(), DB::table('bylopez_va_comision_abonos')->count()])->toBe($antes);
});

test('CO-1 comprobante legible: la lectura SUGIERE los datos, no registra nada, y el abono confirmado guarda lo detectado y lo corregido', function () {
    $png = com6_imagen_comprobante(COM7_LINEAS);
    $png === null && $this->markTestSkipped('Falta la fuente de pruebas para dibujar el comprobante.');
    ['e' => $e, 'liq' => $liq] = com7_liquidacion();
    $abonosAntes = DB::table('bylopez_va_comision_abonos')->count();

    [$j, $codigo] = com7_subir($this, $liq, $png);
    expect($codigo)->toBe(200)->and($j['lectura']['estado'])->toBe('leida')
        ->and($j['lectura']['campos']['valor'])->toBe('500.00')->and($j['lectura']['campos']['fecha'])->toBe('2026-11-02')
        ->and($j['lectura']['campos']['referencia'])->toBe('M07721456')->and($j['lectura']['campos']['medio'])->toBe('Nequi')
        ->and($j['lectura']['campos']['destinatario'])->toBe('Karen Asesora')->and($j['duplicados']['estado'])->toBe('nueva')
        ->and($j['aviso'])->toContain('no verifica la autenticidad');

    // Cargar y leer NO registra el pago (ni lo aprueba): el saldo y los abonos siguen igual.
    expect(DB::table('bylopez_va_comision_abonos')->count())->toBe($abonosAntes)
        ->and(com7_cuenta($e)['acumulado']['saldo_aprobado'])->toBe('1200.00');

    // El archivo vive en disco PRIVADO y solo se sirve a quien registra pagos.
    $soporte = ComisionSoporte::query()->findOrFail($j['soporte_id']);
    expect(Storage::disk($soporte->disco)->exists($soporte->ruta))->toBeTrue()->and($soporte->sha256)->toBe(hash('sha256', $png));
    $this->actingAs(f03b_admin('all'), 'admin')->get($j['url'])->assertOk();

    // Confirmacion explicita: la administradora CORRIGE la referencia (el lector pudo equivocarse) y registra.
    $this->actingAs(f03b_admin('all'), 'admin')->post(route('admin.ventas_asesor.comisiones.abonos.store', $liq->id), [
        'clave' => 'com7-co1-'.Str::uuid(), 'soporte_id' => $j['soporte_id'], 'monto' => '500', 'operacion_valor' => '500', 'fecha_pago' => '2026-11-02',
        'medio' => 'Nequi', 'referencia' => 'M07721457',
    ])->assertRedirect();

    $abono = ComisionAbono::query()->where('liquidacion_id', $liq->id)->firstOrFail();
    expect($abono->soporte_id)->toBe((int) $j['soporte_id'])->and($abono->monto)->toBe('500.00')->and($abono->operacion_huella)->not->toBeNull()
        ->and($abono->lectura_detectada['valor'])->toBe('500.00')->and($abono->lectura_detectada['referencia'])->toBe('M07721456')
        ->and($abono->lectura_corregida)->toHaveKey('referencia')->and($abono->lectura_corregida['referencia'])->toBe(['detectado' => 'M07721456', 'final' => 'M07721457'])
        ->and($abono->lectura_corregida)->not->toHaveKey('valor')->not->toHaveKey('fecha')
        ->and(com7_cuenta($e)['acumulado']['saldo_aprobado'])->toBe('700.00');
});

test('CO-2 comprobante ilegible: nada se inventa, el archivo queda y el abono se registra a mano; los formatos falsos se rechazan', function () {
    $png = com6_imagen_comprobante(['xx', '###', 'Comprobante']);
    $png === null && $this->markTestSkipped('Falta la fuente de pruebas para dibujar el comprobante.');
    ['liq' => $liq] = com7_liquidacion();

    [$j, $codigo] = com7_subir($this, $liq, $png);
    expect($codigo)->toBe(200)->and($j['lectura']['campos']['valor'] ?? null)->toBeNull()->and($j['lectura']['campos']['referencia'] ?? null)->toBeNull()
        ->and($j['lectura']['no_detectados'])->toContain('valor', 'referencia')->and($j['soporte_id'])->toBeGreaterThan(0);

    // Manual: el soporte respalda el abono; sin referencia no hay huella (no se puede comparar la operacion) y queda asi, sin inventarla.
    $this->actingAs(f03b_admin('all'), 'admin')->post(route('admin.ventas_asesor.comisiones.abonos.store', $liq->id), [
        'clave' => 'com7-co2-'.Str::uuid(), 'soporte_id' => $j['soporte_id'], 'monto' => '300', 'fecha_pago' => '2026-11-02', 'medio' => 'transferencia',
    ])->assertRedirect();
    $abono = ComisionAbono::query()->where('liquidacion_id', $liq->id)->firstOrFail();
    expect($abono->soporte_id)->toBe((int) $j['soporte_id'])->and($abono->operacion_huella)->toBeNull();

    // Un archivo cuyo contenido no es lo que dice su extension se rechaza, y no queda ningun soporte nuevo.
    $antes = DB::table('bylopez_va_comision_soportes')->count();
    $falso = com6_archivo('<?php echo 1;', 'comprobante.png');
    $r = $this->actingAs(f03b_admin('all'), 'admin')->post(route('admin.ventas_asesor.comisiones.soportes.leer', $liq->id), ['comprobante' => $falso], ['Accept' => 'application/json']);
    expect($r->getStatusCode())->toBe(422)->and(DB::table('bylopez_va_comision_soportes')->count())->toBe($antes);
});

test('CO-3 duplicados: archivo identico, misma operacion con otro archivo, referencias distintas con igual monto y fecha, y una operacion que cubre varios abonos', function () {
    $png = com6_imagen_comprobante(COM7_LINEAS);
    $jpg = com6_imagen_comprobante(COM7_LINEAS, 'jpg');
    $png === null && $this->markTestSkipped('Falta la fuente de pruebas para dibujar el comprobante.');
    ['e' => $e, 'liq' => $liq] = com7_liquidacion();

    [$j1] = com7_subir($this, $liq, $png);
    [$j2] = com7_subir($this, $liq, $png);   // el MISMO archivo otra vez
    expect($j2['ya_cargado'])->toBeTrue()->and($j2['soporte_id'])->toBe($j1['soporte_id'])
        ->and(DB::table('bylopez_va_comision_soportes')->where('liquidacion_id', $liq->id)->count())->toBe(1);

    $datos = ['monto' => '500', 'operacion_valor' => '500', 'fecha_pago' => '2026-11-02', 'medio' => 'Nequi', 'referencia' => 'M07721456', 'soporte_id' => $j1['soporte_id']];
    $abono = com7_abonar($liq, $datos);
    expect($abono->operacion_huella)->not->toBeNull();

    // Doble clic / reintento con OTRA clave de la misma operacion: no se aplica dos veces (la operacion ya esta cubierta por completo).
    expect(fn () => com7_abonar($liq, $datos))->toThrow(OperacionComisionInvalidaException::class);
    expect(fn () => com7_abonar($liq, ['monto' => '100'] + $datos))->toThrow(OperacionComisionInvalidaException::class, 'solo quedan 0.00');
    expect(ComisionAbono::query()->where('liquidacion_id', $liq->id)->where('tipo', 'abono')->count())->toBe(1)->and(com7_cuenta($e)['acumulado']['saldo_aprobado'])->toBe('700.00');

    // El MISMO comprobante en otro formato (JPG, otro archivo, misma operacion): se detecta por la huella de la operacion, no del archivo.
    [$j3] = com7_subir($this, $liq, $jpg, 'comprobante.jpg');
    expect($j3['soporte_id'])->not->toBe($j1['soporte_id'])->and($j3['duplicados']['estado'])->toBe('duplicado')
        ->and($j3['duplicados']['coincidencias'][0]['abono'])->toBe((int) $abono->id);

    // Referencias DISTINTAS y legibles con igual monto y fecha NO son duplicado (pagar dos veces $500 el mismo dia es normal).
    $otra = com6_imagen_comprobante(['Para', 'Karen Asesora', 'Cuanto?', '$ 500,00', 'Fecha', '02 de noviembre de 2026 a las 09:41 a. m.', 'Referencia', 'M07729999', 'Nequi']);
    [$j4] = com7_subir($this, $liq, $otra, 'otra.png');
    expect($j4['duplicados']['estado'])->toBe('nueva');

    // Una transferencia de $700 que cubre el saldo en dos aplicaciones: la operacion se reutiliza, pero exige confirmar que es la misma.
    $op = ['operacion_valor' => '700', 'fecha_pago' => '2026-11-02', 'medio' => 'Bancolombia', 'referencia' => 'OP-700'];
    com7_abonar($liq, ['monto' => '300'] + $op);
    expect(fn () => com7_abonar($liq, ['monto' => '400'] + $op))->toThrow(OperacionComisionInvalidaException::class, 'misma operación');
    com7_abonar($liq, ['monto' => '400', 'confirmar_misma_operacion' => '1'] + $op);
    expect(fn () => com7_abonar($liq, ['monto' => '1', 'confirmar_misma_operacion' => '1'] + $op))->toThrow(OperacionComisionInvalidaException::class);
    expect(com7_cuenta($e)['acumulado']['saldo_aprobado'])->toBe('0.00');
});

test('CO-4 los pagos a asesoras se separan de los cobros de clientes y los permisos protegen la carga y el archivo', function () {
    ['e' => $e, 'liq' => $liq, 'pedido' => $pedido] = com7_liquidacion();

    // El cobro del cliente (Cartera) del pedido tiene su propia referencia: nunca aparece como coincidencia de un pago a la asesora.
    $referenciaCliente = (string) DB::table('bylopez_va_pagos')->orderByDesc('id')->value('referencia');
    expect($referenciaCliente)->not->toBe('');
    $borrador = new BorradorOperacion(valor: '500.00', fecha: now()->toDateString(), medio: 'transferencia', referencia: $referenciaCliente);
    expect(app(FuenteAbonosAsesor::class)->operacionesCandidatas($borrador))->toBe([]);

    // Permisos: una asesora (solo view_own) y una administradora sin `pay` no cargan comprobantes ni ven archivos.
    $png = com6_imagen_comprobante(COM7_LINEAS);
    $sinPagar = f03b_admin('custom', ['ventas_asesor.comisiones.view_all']);
    $soporte = ComisionSoporte::query()->create(['asesor_admin_id' => $e['asesor']->id, 'liquidacion_id' => $liq->id, 'disco' => 'private', 'ruta' => 'x/no-existe.png', 'nombre_original' => 'x.png', 'mime' => 'image/png', 'tamano' => 1, 'sha256' => str_repeat('a', 64), 'subido_por_admin_id' => 1]);

    foreach ([$e['asesor'], $sinPagar] as $quien) {
        $r = $this->actingAs($quien, 'admin')->get(route('admin.ventas_asesor.comisiones.soportes.ver', $soporte->id));
        expect($r->getStatusCode())->toBeIn([401, 403, 404]);

        if ($png !== null) {
            [, $codigo] = com7_subir($this, $liq, $png, 'c.png', $quien);
            expect($codigo)->toBeIn([401, 403]);
        }
    }
});

test('CU-3 el MISMO resultado en Pedidos, Comisiones, Mis comisiones, la cuenta y el detalle de la liquidacion (998 + 5 → $1.200)', function () {
    $e = com4_escenario();
    com4_vender($e, 998);
    com6_habilitar($e, 5);
    $mes = MesComercial::de(now());
    $id = (int) $e['asesor']->id;
    $admin = f03b_admin('all');

    // Con la comision habilitada y sin liquidar.
    $avance = app(AvanceAsesorService::class)->deAsesor($id, $mes);       // tarjeta de Pedidos y Asesores
    $panel = app(ConsultaComisionesService::class)->panelDelMes($admin, $mes, $id);   // Comisiones
    $mias = app(MisComisionesService::class)->resumen($e['asesor'], $mes);   // Mis comisiones
    $cuenta = com7_cuenta($e);

    expect($avance['comisionables'])->toBe(3)->and($avance['estimada'])->toBe('1200.00')
        ->and($panel['asesoras'][0]['total_mes'])->toBe('1200.00')->and($panel['asesoras'][0]['etapas']['habilitada'])->toBe('1200.00')
        ->and($mias['total_mes'])->toBe('1200.00')->and($mias['etapas']['habilitada'])->toBe('1200.00')
        ->and($cuenta['actividad']['total_mes'])->toBe('1200.00')->and($cuenta['acumulado']['habilitada_sin_liquidar'])->toBe('1200.00');

    // Liquidada y con un abono de $500: las mismas cifras, vistas desde cada lugar.
    $liq = com6_liquidar($e);
    com7_abonar($liq, ['monto' => '500', 'medio' => 'transferencia', 'referencia' => 'CU3']);
    $panel = app(ConsultaComisionesService::class)->panelDelMes($admin, $mes, $id);
    $mias = app(MisComisionesService::class)->resumen($e['asesor'], $mes);
    $cuenta = com7_cuenta($e);
    $detalle = app(ConsultaComisionesService::class)->cuenta($admin, $id);

    expect((string) $liq->fresh()->total)->toBe('1200.00')
        ->and($panel['asesoras'][0]['etapas'])->toBe(['bloqueada' => '0.00', 'habilitada' => '0.00', 'por_pagar' => '700.00', 'pagada' => '500.00'])
        ->and($mias['etapas'])->toBe($panel['asesoras'][0]['etapas'])->and($cuenta['actividad']['etapas'])->toBe($panel['asesoras'][0]['etapas'])
        ->and($mias['saldo_por_pagar'])->toBe('700.00')->and($cuenta['acumulado']['saldo_aprobado'])->toBe('700.00')->and($detalle['por_pagar'])->toBe('700.00')
        ->and($panel['asesoras'][0]['saldo_aprobado'])->toBe('700.00');
});
