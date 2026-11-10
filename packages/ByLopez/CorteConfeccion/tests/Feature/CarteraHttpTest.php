<?php

require_once __DIR__.'/../Support/QaCartera.php';

use ByLopez\CorteConfeccion\Models\Taller;
use ByLopez\CorteConfeccion\Services\OrdenConfeccionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Cartera por HTTP: autorizacion por vinculo con el prestador (no por nombre de rol), aislamiento, soportes privados,
| idempotencia por endpoint y pintado de la cuenta y de la orden.
|--------------------------------------------------------------------------
*/

const CC_PERMISOS_STAFF = ['corte_confeccion', 'corte_confeccion.cartera', 'corte_confeccion.talleres', 'corte_confeccion.ordenes_confeccion', 'corte_confeccion.ordenes_corte'];

function cc_staff(array $extra = [], string $rol = 'Contabilidad'): mixed
{
    return qa_admin($rol, 'custom', array_merge(CC_PERMISOS_STAFF, $extra));
}

function cc_denegado($respuesta): void
{
    expect($respuesta->status())->toBeIn([401, 403]);
}

/** Escenario con Jairo vinculado a un usuario prestador y un segundo taller ajeno. */
function cc_http(): array
{
    $e = cc_escenario();
    $usuarioJairo = qa_admin('TALLER CONFECCION', 'custom', ['corte_confeccion', 'corte_confeccion.mi_cuenta', 'corte_confeccion.ordenes_confeccion']);
    $e['jairo']->update(['usuario_id' => $usuarioJairo->id]);
    Auth::guard('admin')->setUser($e['admin']);
    $otro = qa_taller(['nombre' => 'Taller Ajeno '.uniqid()]);

    return $e + ['usuarioJairo' => $usuarioJairo, 'otro' => $otro];
}

function cc_url(string $nombre, array $p = []): string
{
    return route('admin.corte_confeccion.cartera.'.$nombre, $p);
}

test('el administrador ve la cuenta de Jairo con las cifras del plan y el listado de cartera', function () {
    $e = cc_http();
    cc_mov($e['jairo'], 'abono', 70000, ['aplicaciones' => [['destino' => 'orden', 'id' => $e['ordA']->id, 'valor' => '70000']]]);

    $html = $this->actingAs($e['admin'], 'admin')->get(cc_url('cuenta', ['tipo' => 'taller', 'id' => $e['jairo']->id]))->assertOk()->getContent();

    expect($html)->toContain('$ 945.000')->toContain('$ 800.000')->toContain('$ 870.000')->toContain('$ 145.000')->toContain('$ 70.000')
        ->toContain('Registrar movimiento')->toContain('Hacer corte de cuentas')->toContain('Pendiente de recibir')
        ->not->toContain('Saldo confirmado por trabajo');

    $this->actingAs($e['admin'], 'admin')->get(cc_url('index'))->assertOk()->assertSee($e['jairo']->nombre);
});

test('el prestador ve SOLO su cuenta, en modo consulta, sin botones de registro', function () {
    $e = cc_http();
    cc_mov($e['jairo'], 'abono', 70000);

    $html = $this->actingAs($e['usuarioJairo'], 'admin')->get(cc_url('mi_cuenta'))->assertOk()->getContent();
    expect($html)->toContain('Mi cuenta')->toContain('$ 1.015.000')->toContain('solo de consulta')
        ->not->toContain('Registrar movimiento')->not->toContain('Hacer corte de cuentas')->not->toContain('data-c-reversar="')->not->toContain('id="c-modal-movimiento"');

    // Un id enviado por el cliente no cambia de quien es la cuenta.
    $this->actingAs($e['usuarioJairo'], 'admin')->get(cc_url('mi_cuenta').'?id='.$e['otro']->id.'&tipo=taller')->assertOk()->assertDontSee($e['otro']->nombre);

    // Cuenta ajena por la ruta administrativa: denegada.
    cc_denegado($this->actingAs($e['usuarioJairo'], 'admin')->get(cc_url('cuenta', ['tipo' => 'taller', 'id' => $e['otro']->id])));
    cc_denegado($this->actingAs($e['usuarioJairo'], 'admin')->get(cc_url('index')));
});

test('el prestador no registra, reversa, descuenta, define arranque, lee comprobantes ni abre soportes, tenga lo que tenga su rol', function () {
    $e = cc_http();
    $abono = cc_mov($e['jairo'], 'abono', 70000);

    // Variantes del rol del prestador: el propio, uno RENOMBRADO con permission_type=all y uno con todas las llaves de cartera.
    $todas = array_merge(CC_PERMISOS_STAFF, ['corte_confeccion.cartera.registrar', 'corte_confeccion.cartera.prestamos', 'corte_confeccion.cartera.corte', 'corte_confeccion.cartera.reversar', 'corte_confeccion.cartera.arranque', 'corte_confeccion.mi_cuenta']);
    $usuarios = [
        'propio' => $e['usuarioJairo'],
        'renombrado all' => qa_admin('Satelite Externo', 'all'),
        'renombrado con llaves' => qa_admin('Maquila Sur', 'custom', $todas),
        'rol taller all' => qa_admin('TALLER CONFECCION', 'all'),
    ];
    $usuarios['renombrado all']->role; // vinculos: los tres de abajo son DEL prestador Jairo / otro
    // El modulo solo vincula usuarios con rol «Taller Confeccion», pero un rol puede renombrarse DESPUES: el vinculo queda.
    qa_taller()->update(['usuario_id' => $usuarios['renombrado all']->id]);
    qa_taller()->update(['usuario_id' => $usuarios['renombrado con llaves']->id]);
    $movs = cc_count_movs();

    foreach ($usuarios as $nombre => $u) {
        $ctx = " ({$nombre})";
        cc_denegado($this->actingAs($u, 'admin')->postJson(cc_url('movimientos.store', ['tipo' => 'taller', 'id' => $e['jairo']->id]), ['tipo' => 'abono', 'valor' => '1000', 'fecha_efectiva' => now()->toDateString(), 'idempotency_key' => (string) Str::uuid()]));
        cc_denegado($this->actingAs($u, 'admin')->postJson(cc_url('prestamos.store', ['tipo' => 'taller', 'id' => $e['jairo']->id]), ['tipo' => 'prestamo', 'valor' => '1000', 'fecha_efectiva' => now()->toDateString(), 'idempotency_key' => (string) Str::uuid()]));
        cc_denegado($this->actingAs($u, 'admin')->postJson(cc_url('movimientos.reversar', ['id' => $abono->id]), ['motivo' => 'x'.$ctx]));
        cc_denegado($this->actingAs($u, 'admin')->postJson(cc_url('movimientos.aplicar', ['id' => $abono->id]), ['aplicaciones' => [['destino' => 'orden', 'id' => $e['ordA']->id, 'valor' => '1000']]]));
        cc_denegado($this->actingAs($u, 'admin')->postJson(cc_url('corte.confirmar', ['tipo' => 'taller', 'id' => $e['jairo']->id]), ['idempotency_key' => (string) Str::uuid()]));
        cc_denegado($this->actingAs($u, 'admin')->getJson(cc_url('corte.vista', ['tipo' => 'taller', 'id' => $e['jairo']->id])));
        cc_denegado($this->actingAs($u, 'admin')->postJson(cc_url('arranque'), ['fecha' => now()->toDateString()]));
        cc_denegado($this->actingAs($u, 'admin')->get(cc_url('index')));
    }

    expect(cc_count_movs())->toBe($movs);

    // Cuenta de otra persona por la ruta administrativa: tampoco con rol renombrado ni con llaves de cartera.
    cc_denegado($this->actingAs($usuarios['renombrado con llaves'], 'admin')->get(cc_url('cuenta', ['tipo' => 'taller', 'id' => $e['jairo']->id])));
    cc_denegado($this->actingAs($usuarios['renombrado all'], 'admin')->get(cc_url('cuenta', ['tipo' => 'taller', 'id' => $e['jairo']->id])));
});

test('un prestador con rol renombrado ve su propia cuenta por el vinculo, no la de otros', function () {
    $e = cc_http();
    $maquila = qa_admin('Maquila Sur', 'custom', ['corte_confeccion', 'corte_confeccion.mi_cuenta']);
    qa_taller(['nombre' => 'Taller Maquila'])->update(['usuario_id' => $maquila->id]);

    $this->actingAs($maquila, 'admin')->get(cc_url('mi_cuenta'))->assertOk()->assertDontSee($e['jairo']->nombre);
    cc_denegado($this->actingAs($maquila, 'admin')->get(cc_url('cuenta', ['tipo' => 'taller', 'id' => $e['jairo']->id])));
});

test('personal de la empresa: cada accion exige su llave concreta; sin llave falla cerrado', function () {
    $e = cc_http();
    $abono = cc_mov($e['jairo'], 'abono', 70000);
    $solo = cc_staff();
    $registra = cc_staff(['corte_confeccion.cartera.registrar']);
    $prestamos = cc_staff(['corte_confeccion.cartera.prestamos']);
    $reversa = cc_staff(['corte_confeccion.cartera.reversar']);
    $payload = fn ($tipo = 'abono') => ['tipo' => $tipo, 'valor' => '10000', 'fecha_efectiva' => now()->toDateString(), 'idempotency_key' => (string) Str::uuid()];
    $abonoUrl = cc_url('movimientos.store', ['tipo' => 'taller', 'id' => $e['jairo']->id]);
    $prestamoUrl = cc_url('prestamos.store', ['tipo' => 'taller', 'id' => $e['jairo']->id]);

    $this->actingAs($solo, 'admin')->get(cc_url('cuenta', ['tipo' => 'taller', 'id' => $e['jairo']->id]))->assertOk();
    cc_denegado($this->actingAs($solo, 'admin')->postJson($abonoUrl, $payload()));
    cc_denegado($this->actingAs($solo, 'admin')->postJson(cc_url('movimientos.reversar', ['id' => $abono->id]), ['motivo' => 'x']));

    $this->actingAs($registra, 'admin')->postJson($abonoUrl, $payload())->assertOk();
    cc_denegado($this->actingAs($registra, 'admin')->postJson($prestamoUrl, $payload('prestamo')));
    // Los saldos iniciales exigen ademas la llave de arranque.
    cc_denegado($this->actingAs($registra, 'admin')->postJson($abonoUrl, array_merge($payload('saldo_inicial_trabajo'), ['fecha_efectiva' => now()->subDays(5)->toDateString()])));

    $this->actingAs($prestamos, 'admin')->postJson($prestamoUrl, $payload('prestamo'))->assertOk();
    cc_denegado($this->actingAs($prestamos, 'admin')->postJson($abonoUrl, $payload()));

    cc_denegado($this->actingAs($reversa, 'admin')->postJson($abonoUrl, $payload()));
    $this->actingAs($reversa, 'admin')->postJson(cc_url('movimientos.reversar', ['id' => $abono->id]), ['motivo' => 'Duplicado'])->assertOk();
    expect($abono->fresh()->estado)->toBe('reversado');
});

test('registrar por HTTP: idempotente con la misma llave, 422 con datos invalidos y 409 con la misma llave y otro contenido', function () {
    $e = cc_http();
    $url = cc_url('movimientos.store', ['tipo' => 'taller', 'id' => $e['jairo']->id]);
    $llave = (string) Str::uuid();
    $cuerpo = ['tipo' => 'abono', 'valor' => '$ 70.000', 'fecha_efectiva' => now()->toDateString(), 'medio' => 'nequi', 'idempotency_key' => $llave, 'modo_detalle' => 'total', 'num_transferencias' => 15];
    $antes = cc_count_movs();

    $a = $this->actingAs($e['admin'], 'admin')->postJson($url, $cuerpo)->assertOk()->json('data.codigo');
    $b = $this->actingAs($e['admin'], 'admin')->postJson($url, $cuerpo)->assertOk()->json('data.codigo');

    expect($b)->toBe($a)->and(cc_count_movs())->toBe($antes + 1);
    $this->actingAs($e['admin'], 'admin')->postJson($url, array_merge($cuerpo, ['valor' => '80000']))->assertStatus(409);
    $this->actingAs($e['admin'], 'admin')->postJson($url, array_merge($cuerpo, ['idempotency_key' => (string) Str::uuid(), 'valor' => '-5']))->assertStatus(422);
    $this->actingAs($e['admin'], 'admin')->postJson($url, array_merge($cuerpo, ['idempotency_key' => (string) Str::uuid(), 'fecha_efectiva' => now()->subDays(30)->toDateString()]))->assertStatus(422);
});

test('comprobantes por HTTP: sin lectura activada se guardan como soporte; el mismo archivo renombrado se reconoce; soporte privado solo para quien tiene permiso', function () {
    Storage::fake('local');
    config(['corteconfeccion.comprobantes.lector' => 'ninguno']);
    $e = cc_http();
    $url = cc_url('comprobantes.leer', ['tipo' => 'taller', 'id' => $e['jairo']->id]);
    $png = base64_decode(CC_PNG);

    $r1 = $this->actingAs($e['admin'], 'admin')->post($url, ['comprobantes' => [UploadedFile::fake()->createWithContent('a.png', $png)]], ['Accept' => 'application/json'])->assertOk();
    expect($r1->json('lector_disponible'))->toBeFalse()->and($r1->json('soportes.0.lectura'))->toBe('no_disponible')->and($r1->json('borradores'))->toBe([]);

    $r2 = $this->actingAs($e['admin'], 'admin')->post($url, ['comprobantes' => [UploadedFile::fake()->createWithContent('otro-nombre.png', $png)]], ['Accept' => 'application/json'])->assertOk();
    expect($r2->json('soportes.0.ya_cargado'))->toBeTrue()->and($r2->json('soportes.0.id'))->toBe($r1->json('soportes.0.id'));

    $soporteUrl = cc_url('soporte', ['id' => $r1->json('soportes.0.id')]);
    $this->actingAs($e['admin'], 'admin')->get($soporteUrl)->assertOk();
    $this->actingAs(cc_staff(), 'admin')->get($soporteUrl)->assertOk();                 // personal con la llave de cartera
    cc_denegado($this->actingAs($e['usuarioJairo'], 'admin')->get($soporteUrl));          // el prestador no abre soportes
    cc_denegado($this->actingAs(qa_admin('Ventas', 'custom', ['corte_confeccion', 'corte_confeccion.talleres']), 'admin')->get($soporteUrl));
    cc_denegado($this->actingAs($e['usuarioJairo'], 'admin')->post($url, ['comprobantes' => [UploadedFile::fake()->createWithContent('b.png', $png)]], ['Accept' => 'application/json']));
});

test('la cuenta de la orden aparece en la orden de confeccion y de corte, solo para quien puede ver esa cuenta', function () {
    $e = cc_http();
    cc_mov($e['jairo'], 'abono', 70000, ['aplicaciones' => [['destino' => 'orden', 'id' => $e['ordA']->id, 'valor' => '70000']]]);

    $html = $this->actingAs($e['admin'], 'admin')->get(route('admin.corte_confeccion.ordenes_confeccion.show', $e['ordA']->id))->assertOk()->getContent();
    expect($html)->toContain('Cuenta de esta orden')->toContain('$ 870.000')->toContain('Abono AB-')->toContain('$ 800.000');

    // El prestador la ve en SU orden; no ve la cuenta de la orden de otro taller.
    $this->actingAs($e['usuarioJairo'], 'admin')->get(route('admin.corte_confeccion.ordenes_confeccion.show', $e['ordA']->id))->assertOk()->assertSee('Cuenta de esta orden');

    $ajena = app(OrdenConfeccionService::class)->create(['orden_corte_id' => $e['corte']->id, 'taller_id' => $e['otro']->id, 'detalles' => [['product_id' => $e['producto']->id, 'talla' => '', 'cantidad_entregada' => 10]]]);
    $this->actingAs($cuentaSinLlave = cc_staff([], 'Operaciones'), 'admin')->get(route('admin.corte_confeccion.ordenes_confeccion.show', $ajena->id))->assertOk();

    // Ficha del taller: resumen con enlace a la cuenta completa.
    $this->actingAs($e['admin'], 'admin')->get(route('admin.corte_confeccion.talleres.show', $e['jairo']->id))->assertOk()->assertSee('Ver cuenta completa')->assertSee('$ 945.000', false);
});

test('observaciones con HTML se escapan en la cuenta', function () {
    $e = cc_http();
    cc_mov($e['jairo'], 'abono', 1000, ['observacion' => '<script>alert("x")</script>']);

    $html = $this->actingAs($e['admin'], 'admin')->get(cc_url('cuenta', ['tipo' => 'taller', 'id' => $e['jairo']->id]))->assertOk()->getContent();
    expect($html)->not->toContain('<script>alert("x")</script>');
});

test('la fecha de arranque no se puede cambiar una vez hay movimientos; y sin arranque la pantalla no calcula', function () {
    $e = cc_escenario('1450', 600, 100, false);
    $admin = $e['admin'];

    $html = $this->actingAs($admin, 'admin')->get(cc_url('cuenta', ['tipo' => 'taller', 'id' => $e['jairo']->id]))->assertOk()->getContent();
    expect($html)->toContain('La cartera todavía no ha iniciado')->not->toContain('Faltaría por pagar');

    $this->actingAs($admin, 'admin')->postJson(cc_url('arranque'), ['fecha' => now()->subDay()->toDateString()])->assertOk();
    cc_mov($e['jairo'], 'abono', 1000);
    $this->actingAs($admin, 'admin')->postJson(cc_url('arranque'), ['fecha' => now()->subDays(10)->toDateString()])->assertStatus(422);
});
