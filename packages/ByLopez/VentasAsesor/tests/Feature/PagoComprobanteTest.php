<?php

use ByLopez\VentasAsesor\Enums\TipoEventoPago;
use ByLopez\VentasAsesor\Exceptions\RegistroPagoInvalidoException;
use ByLopez\VentasAsesor\Models\Pago;
use ByLopez\VentasAsesor\Models\PagoEvento;
use ByLopez\VentasAsesor\Services\Cartera\RegistrarPagoService;
use ByLopez\VentasAsesor\Support\Cartera\AlcanceDePagos;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

require_once __DIR__.'/../helpersPagB.php';
uses(TestCase::class);

beforeEach(function () {
    pagb_corte('2000-01-01');
    Storage::fake('private');
    $this->actor = pagb_asesor(['ventas_asesor', 'ventas_asesor.cartera', 'ventas_asesor.cartera.registrar', 'ventas_asesor.cartera.view_all']);
    $this->pedido = pagb_pedido(pagb_cliente(), 500000);
    pagb_factura($this->pedido, 'pending');
    $this->datos = ['monto' => '10000', 'medio_cobro' => 'transferencia', 'plataforma' => 'nequi', 'fecha_pago' => now()->toDateString()];
});

test('transferencia permite referencia o soporte por pago sin cambiar estado ni duplicar reintentos', function ($referencia, $archivo) {
    $datos = $this->datos + ['referencia' => $referencia];
    if ($archivo) {
        $datos['comprobante'] = UploadedFile::fake()->create('soporte.pdf', 10, 'application/pdf');
    }
    $servicio = app(RegistrarPagoService::class);
    $r = $servicio->registrar($this->actor, $this->pedido->id, $datos, 'prueba-evidencia-0001');
    $pago = Pago::findOrFail($r->pagoId);
    expect($pago->estado->value)->toBe('sin_revisar')->and($r->saldo->saldo)->toBe(490000.0);
    $evidencias = $pago->eventos()->where('tipo', TipoEventoPago::EVIDENCIA_AGREGADA->value)->get();
    expect($evidencias)->toHaveCount($archivo ? 1 : 0);
    if ($archivo) {
        Storage::disk('private')->assertExists($evidencias->first()->datos['ruta']);
    }
    expect($servicio->registrar($this->actor, $this->pedido->id, $datos, 'prueba-evidencia-0001')->duplicado)->toBeTrue();
    expect(Storage::disk('private')->allFiles())->toHaveCount($archivo ? 1 : 0);
})->with([['REF-1', false], [null, true], ['REF-2', true]]);

test('transferencia sin evidencia bloquea y efectivo no exige soporte', function () {
    try {
        app(RegistrarPagoService::class)->registrar($this->actor, $this->pedido->id, $this->datos, 'prueba-evidencia-0002');
        $this->fail('Debio rechazar');
    } catch (RegistroPagoInvalidoException $e) {
        expect($e->getMessage())->toBe('Ingresa la referencia o adjunta el comprobante de la transferencia.');
    }
    expect(Pago::where('registrado_por_admin_id', $this->actor->id)->count())->toBe(0);
    $datos = array_replace($this->datos, ['medio_cobro' => 'efectivo', 'plataforma' => null]);
    app(RegistrarPagoService::class)->registrar($this->actor, $this->pedido->id, $datos, 'prueba-evidencia-0003');
    expect(Pago::where('registrado_por_admin_id', $this->actor->id)->count())->toBe(1);
});

test('POST multipart conserva el soporte y su descarga exige alcance del pago', function () {
    $response = $this->actingAs($this->actor, 'admin')->post(route('admin.ventas_asesor.pedidos.pagos.store', $this->pedido->id), $this->datos + [
        'idempotency_key' => 'prueba-multipart-0001', 'comprobante' => UploadedFile::fake()->create('abono.pdf', 10, 'application/pdf'),
    ])->assertCreated();
    $pago = $response->json('pago_id');
    $evento = PagoEvento::where('pago_id', $pago)->where('tipo', 'evidencia_agregada')->firstOrFail();
    $url = route('admin.ventas_asesor.cartera.index', ['pago' => $pago, 'comprobante' => $evento->id]);
    expect(app(AlcanceDePagos::class)->incluye($this->actor, $pago))->toBeTrue();
    Storage::disk('private')->assertExists($evento->datos['ruta']);
    $this->get($url)->assertOk()->assertDownload('abono.pdf');
    $ajeno = pagb_asesor(['ventas_asesor', 'ventas_asesor.cartera', 'ventas_asesor.cartera.view_own']);
    $this->actingAs($ajeno, 'admin')->get($url)->assertNotFound();
    $this->actingAs($this->actor, 'admin')->get(route('admin.ventas_asesor.cartera.index', ['pago' => $pago + 1000, 'comprobante' => $evento->id]))->assertNotFound();
});

test('archivos invalidos y plataforma ausente se rechazan sin registrar pagos', function () {
    $url = route('admin.ventas_asesor.pedidos.pagos.store', $this->pedido->id);
    $this->actingAs($this->actor, 'admin')->postJson($url, $this->datos + ['idempotency_key' => 'prueba-invalida-0001', 'comprobante' => UploadedFile::fake()->create('mal.html', 10, 'text/html')])->assertUnprocessable()->assertJsonValidationErrors('comprobante');
    $this->postJson($url, array_replace($this->datos, ['plataforma' => '', 'idempotency_key' => 'prueba-invalida-0002', 'comprobante' => UploadedFile::fake()->create('abono.pdf', 10, 'application/pdf')]))->assertUnprocessable();
    expect(Pago::where('registrado_por_admin_id', $this->actor->id)->count())->toBe(0)->and(Storage::disk('private')->allFiles())->toBe([]);
});

test('rollback combinado elimina archivos nuevos y cada parte conserva su evidencia', function () {
    $servicio = app(RegistrarPagoService::class);
    $partes = [
        ['monto' => '10000', 'plataforma' => 'nequi', 'comprobante' => UploadedFile::fake()->create('uno.pdf', 10, 'application/pdf')],
        ['monto' => '900000', 'plataforma' => 'nequi', 'referencia' => 'DOS'],
    ];
    try {
        $servicio->registrarCombinado($this->actor, $this->pedido->id, $partes, ['fecha_pago' => now()->toDateString()], 'prueba-combinada-0001');
        $this->fail('Debio rechazar');
    } catch (RegistroPagoInvalidoException $e) {
        expect($e->codigo)->toBe(RegistroPagoInvalidoException::MONTO_EXCEDE_SALDO);
    }
    expect(Pago::where('registrado_por_admin_id', $this->actor->id)->count())->toBe(0)->and(Storage::disk('private')->allFiles())->toBe([]);
    $partes[1] = ['monto' => '10000', 'plataforma' => 'nequi', 'comprobante' => UploadedFile::fake()->create('dos.pdf', 10, 'application/pdf')];
    $r = $servicio->registrarCombinado($this->actor, $this->pedido->id, $partes, ['fecha_pago' => now()->toDateString()], 'prueba-combinada-0002');
    foreach ($r['pagos'] as $pago) {
        expect(PagoEvento::where('pago_id', $pago->pagoId)->where('tipo', 'evidencia_agregada')->count())->toBe(1);
    }
    expect(Storage::disk('private')->allFiles())->toHaveCount(2);
});

test('un segundo abono tiene soporte propio y cambiar archivo con la misma clave causa conflicto', function () {
    $servicio = app(RegistrarPagoService::class);
    $primero = $servicio->registrar($this->actor, $this->pedido->id, $this->datos + ['comprobante' => UploadedFile::fake()->createWithContent('uno.pdf', "%PDF-1.4\nuno")], 'prueba-archivos-0001');
    $segundo = $servicio->registrar($this->actor, $this->pedido->id, $this->datos + ['comprobante' => UploadedFile::fake()->createWithContent('dos.pdf', "%PDF-1.4\ndos")], 'prueba-archivos-0002');
    expect($primero->pagoId)->not->toBe($segundo->pagoId);
    expect(PagoEvento::where('tipo', 'evidencia_agregada')->pluck('pago_id')->all())->toBe([$primero->pagoId, $segundo->pagoId]);
    try {
        $servicio->registrar($this->actor, $this->pedido->id, $this->datos + ['comprobante' => UploadedFile::fake()->createWithContent('cambio.pdf', "%PDF-1.4\ncambio")], 'prueba-archivos-0001');
        $this->fail('Debio rechazar');
    } catch (RegistroPagoInvalidoException $e) {
        expect($e->codigo)->toBe(RegistroPagoInvalidoException::IDEMPOTENCIA_EN_CONFLICTO);
    }
    expect(Storage::disk('private')->allFiles())->toHaveCount(2);
});
