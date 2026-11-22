<?php

use ByLopez\Logistica\Contracts\DomiciliarioDeVenta;
use ByLopez\Logistica\Contracts\PagosPendientesDeConfirmar;
use ByLopez\Logistica\DataObjects\SolicitudRecogida;
use ByLopez\Logistica\Enums\CondicionEnvio;
use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Models\Guia;
use ByLopez\Logistica\Models\Tarifa;
use ByLopez\Logistica\Models\Transportadora;
use ByLopez\Logistica\Models\Zona;
use ByLopez\Logistica\Services\Despachos\ContextoDespachoService;
use ByLopez\Logistica\Services\Despachos\DespachoService;
use ByLopez\Logistica\Services\Despachos\DisposicionCobroService;
use ByLopez\Logistica\Services\Tarifas\ZonasYTarifasService;
use ByLopez\Logistica\Services\Transportadoras\TransportadorasService;
use ByLopez\Logistica\Support\CondicionesEnvio;
use ByLopez\VentasAsesor\Models\AplicacionPago;
use ByLopez\VentasAsesor\Models\Pago;
use ByLopez\VentasAsesor\Services\Cartera\CarteraConsultaService;
use ByLopez\VentasAsesor\Services\Cartera\ConfirmarPagoService;
use ByLopez\VentasAsesor\Services\Cartera\RechazarPagoService;
use ByLopez\VentasAsesor\Services\Cartera\RegistrarPagoService;
use ByLopez\VentasAsesor\Support\Logistica\MunicipioDesdeCatalogo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

require_once __DIR__.'/../helpersDespacho.php';
require_once __DIR__.'/../helpersDomicilios.php';

uses(TestCase::class);

/*
| Domicilios locales F1 (2026-10-11): condicion de envio deducida, capacidades de transportadora, zonas, tarifas
| sugeridas, costo final con snapshot y bloqueo por pago sin confirmar. Solo lpz_saas_testing, datos de fixture.
*/

function log6_pagos(float $sinRevisar, ?float $saldo): void
{
    app()->instance(PagosPendientesDeConfirmar::class, new class($sinRevisar, $saldo) implements PagosPendientesDeConfirmar
    {
        public function __construct(private float $sinRevisar, private ?float $saldo) {}

        public function estado(int $orderId): array
        {
            return ['sin_revisar' => $this->sinRevisar, 'saldo' => $this->saldo];
        }
    });
}

test('F1-1 condicion de envio por CODIGO de metodo (no por monto) y pagador deducido; la CartRule de envio gratis cuenta', function () {
    $condicion = fn (array $o) => app(CondicionesEnvio::class)->deOrden((object) $o);

    expect($condicion(['shipping_method' => 'free_free']))->toBe(CondicionEnvio::GRATIS)
        ->and($condicion(['shipping_method' => 'flatrate_flatrate']))->toBe(CondicionEnvio::POR_PAGAR)
        ->and($condicion(['shipping_method' => 'domicilio_local', 'base_shipping_amount' => 10000]))->toBe(CondicionEnvio::COBRADO_EN_PEDIDO)
        ->and($condicion(['shipping_method' => 'pickup']))->toBe(CondicionEnvio::RECOGIDA)
        ->and($condicion(['shipping_method' => 'metodo_raro']))->toBe(CondicionEnvio::SIN_DEFINIR)
        ->and($condicion(['shipping_method' => null]))->toBe(CondicionEnvio::SIN_DEFINIR)
        // Domicilio con CartRule de envio gratis (descuento = tarifa): gratis.
        ->and($condicion(['shipping_method' => 'domicilio_local', 'base_shipping_amount' => 10000, 'base_shipping_discount_amount' => 10000]))->toBe(CondicionEnvio::GRATIS)
        ->and(CondicionEnvio::GRATIS->pagador()->value)->toBe('bylopez')
        ->and(CondicionEnvio::POR_PAGAR->pagador()->value)->toBe('cliente_al_recibir')
        ->and(CondicionEnvio::COBRADO_EN_PEDIDO->pagador()->value)->toBe('bylopez')
        ->and(CondicionEnvio::RECOGIDA->pagador())->toBeNull()
        ->and(CondicionEnvio::SIN_DEFINIR->pagador())->toBeNull();
});

test('F1-2 despacho: free_free → ByLopez, flatrate → cliente al recibir, domicilio_local → ByLopez, sin elegir pagador', function () {
    $c = log6_catalogo();
    log6_municipio('11001');

    foreach (['free_free' => ['bylopez', 'gratis'], 'flatrate_flatrate' => ['cliente_al_recibir', 'por_pagar'], 'domicilio_local' => ['bylopez', 'cobrado_en_pedido']] as $metodo => [$pagador, $cond]) {
        $e = log2_pedido(1, ['envio' => $metodo]);
        $ctx = app(ContextoDespachoService::class)->paraPedido((int) $e['pedido']->id, log2_bodega());

        expect($ctx['sugerencias']['flete_pagador'])->toBe($pagador)
            ->and($ctx['envio']['condicion'])->toBe($cond);

        $guia = log6_despachar($e, $c['jeison']);

        expect($guia->flete_pagador->value)->toBe($pagador)
            ->and($guia->condicion_envio)->toBe($cond)
            ->and($guia->pagador_deducido)->toBe($pagador)
            ->and($guia->motivo_pagador)->toBeNull();
    }
});

test('F1-3 pickup no se despacha por guia; metodo desconocido no adivina: exige pagador y motivo', function () {
    $c = log6_catalogo();
    log6_municipio('11001');
    $servicio = app(DespachoService::class);

    $recogida = log2_pedido(1, ['envio' => 'pickup']);
    expect(log2_error(fn () => log6_despachar($recogida, $c['jeison'])))->toBe(Invalida::ES_RECOGIDA);

    $raro = log2_pedido(1, ['envio' => 'metodo_raro']);
    $ctx = app(ContextoDespachoService::class)->paraPedido((int) $raro['pedido']->id, log2_bodega());

    expect($ctx['envio']['condicion'])->toBe('sin_definir')
        ->and($ctx['sugerencias']['flete_pagador'])->toBeNull()
        ->and(log2_error(fn () => log6_despachar($raro, $c['jeison'])))->toBe(Invalida::PAGADOR_REQUERIDO)
        ->and(log2_error(fn () => log6_despachar($raro, $c['jeison'], ['fletePagador' => 'bylopez'])))->toBe(Invalida::MOTIVO_PAGADOR_REQUERIDO);

    $guia = log6_despachar($raro, $c['jeison'], ['fletePagador' => 'bylopez', 'motivoPagador' => 'Domicilio acordado por WhatsApp']);

    expect($guia->condicion_envio)->toBe('sin_definir')
        ->and($guia->pagador_deducido)->toBeNull()
        ->and($guia->motivo_pagador)->toBe('Domicilio acordado por WhatsApp');

    // Cambiar un pagador deducido tambien exige motivo, y queda guardado.
    $gratis = log2_pedido(1, ['envio' => 'free_free']);
    expect(log2_error(fn () => log6_despachar($gratis, $c['jeison'], ['fletePagador' => 'cliente_al_recibir'])))->toBe(Invalida::MOTIVO_PAGADOR_REQUERIDO);
});

test('F1-4 tarifas sugeridas por domiciliario y zona: Jeison 10.000/12.000 y Brian 12.000/12.000', function () {
    $c = log6_catalogo();

    foreach ([['jeison', '11001', 10000], ['jeison', '25754', 12000], ['brian', '11001', 12000], ['brian', '25754', 12000]] as [$quien, $municipio, $esperado]) {
        log6_municipio($municipio);
        $guia = log6_despachar(log2_pedido(1, ['envio' => 'free_free']), $c[$quien]);

        expect((float) $guia->tarifa_sugerida)->toBe((float) $esperado)
            ->and((float) $guia->valor_flete)->toBe((float) $esperado)
            ->and((float) $guia->costo_adicional)->toBe(0.0)
            ->and($guia->zona_origen)->toBe('auto')
            ->and($guia->motivo_costo)->toBeNull();
    }
});

test('F1-5 costo distinto a la tarifa exige motivo; el snapshot no cambia si despues cambia la tarifa', function () {
    $c = log6_catalogo();
    log6_municipio('11001');
    $e = log2_pedido(1, ['envio' => 'free_free']);

    expect(log2_error(fn () => log6_despachar($e, $c['jeison'], ['valorFlete' => '15000'])))->toBe(Invalida::MOTIVO_COSTO_REQUERIDO);

    $guia = log6_despachar($e, $c['jeison'], ['valorFlete' => '15000', 'motivoCosto' => 'Pedido de alto volumen']);

    expect((float) $guia->tarifa_sugerida)->toBe(10000.0)
        ->and((float) $guia->costo_adicional)->toBe(5000.0)
        ->and((float) $guia->valor_flete)->toBe(15000.0)
        ->and($guia->motivo_costo)->toBe('Pedido de alto volumen');

    // Nueva tarifa vigente: los despachos nuevos la usan; la guia anterior conserva su snapshot.
    app(ZonasYTarifasService::class)->crearTarifa(f03b_admin('all'), ['transportadora_id' => $c['jeison']->id, 'zona_id' => $c['bogota']->id, 'valor' => '11000', 'vigente_desde' => now()->toDateString()]);

    $nueva = log6_despachar(log2_pedido(1, ['envio' => 'free_free']), $c['jeison']);
    $guia->refresh();

    expect((float) $nueva->tarifa_sugerida)->toBe(11000.0)
        ->and((float) $guia->tarifa_sugerida)->toBe(10000.0)
        ->and((float) $guia->valor_flete)->toBe(15000.0)
        // La tarifa no se edita: un cambio es una fila nueva.
        ->and(log2_error(fn () => Tarifa::query()->first()->update(['valor' => 1])))->toBe(Invalida::REGISTRO_INMUTABLE);
});

test('F1-6 zona: exacta con el catalogo DIVIPOLA; texto ambiguo no se adivina (sin zona o elegida a mano)', function () {
    $c = log6_catalogo();
    $e = log2_pedido(1, ['envio' => 'free_free']);
    $direccion = fn (string $ciudad, string $estado) => DB::table('addresses')->where('order_id', $e['pedido']->id)->where('address_type', 'order_shipping')
        ->update(['city' => $ciudad, 'state' => $estado, 'country' => 'CO']);

    // Catalogo minimo de fixture: Bogota, D.C. (departamento 11 ↔ estado BOG).
    $estado = DB::table('country_states')->where('country_code', 'CO')->where('code', 'BOG')->value('id');
    DB::table('bylopez_geo_departamentos')->updateOrInsert(['codigo_divipola' => '11'], ['nombre_oficial' => 'Bogotá, D.C.', 'nombre_busqueda' => 'bogota dc', 'country_state_id' => $estado]);
    DB::table('bylopez_geo_municipios')->updateOrInsert(['codigo_divipola' => '11001'], [
        'nombre_oficial' => 'Bogotá, D.C.', 'nombre_busqueda' => 'bogota dc', 'departamento_codigo' => '11', 'tipo' => 'Distrito', 'activo' => true, 'fuente' => 'prueba', 'checksum_fuente' => str_repeat('0', 64),
    ]);
    $real = app(MunicipioDesdeCatalogo::class);

    $direccion('Bogotá, D.C.', 'BOG');
    expect($real->municipioDePedido((int) $e['pedido']->id))->toBe('11001');

    foreach ([['BOGOTA', 'MI'], ['Bogotá', 'CAS'], ['bogotá, d.c.', 'BOG'], ['Bogota, D.C.', 'BOG']] as [$ciudad, $codigo]) {
        $direccion($ciudad, $codigo);
        expect($real->municipioDePedido((int) $e['pedido']->id))->toBeNull();
    }

    // Sin municipio: sin zona automatica, sin tarifa; la operaria elige la zona (queda «manual»).
    log6_municipio(null);
    $ctx = app(ContextoDespachoService::class)->paraPedido((int) $e['pedido']->id, log2_bodega());

    expect($ctx['envio']['zona_auto'])->toBeNull()
        ->and(log2_error(fn () => log6_despachar($e, $c['jeison'])))->toBe(Invalida::COSTO_REQUERIDO);

    $guia = log6_despachar($e, $c['jeison'], ['zonaId' => $c['soacha']->id]);

    expect($guia->zona_origen)->toBe('manual')
        ->and($guia->zona_nombre_snapshot)->toBe('Soacha')
        ->and((float) $guia->valor_flete)->toBe(12000.0);

    // Sin zona y con costo escrito: queda «sin_zona», sin tarifa sugerida.
    $otra = log6_despachar(log2_pedido(1, ['envio' => 'free_free']), $c['jeison'], ['valorFlete' => '9000']);

    expect($otra->zona_origen)->toBe('sin_zona')->and($otra->tarifa_sugerida)->toBeNull()->and((float) $otra->valor_flete)->toBe(9000.0);
});

test('F1-7 capacidades independientes del tipo; PICAP sin cuenta corriente; el generico no lleva cuenta', function () {
    $c = log6_catalogo();
    log6_municipio('11001');
    $admin = f03b_admin('all');
    $servicio = app(TransportadorasService::class);

    // PICAP: plataforma sin cuenta → no exige costo y la guia queda sin cuenta corriente.
    $picap = log6_despachar(log2_pedido(1, ['envio' => 'free_free']), $c['picap'], ['valorFlete' => '8000']);

    expect($picap->transportadora_tipo)->toBe('plataforma')
        ->and($picap->liquida_cuenta_corriente)->toBeFalse()
        ->and($picap->condicion_envio)->toBe('gratis');

    // Un domiciliario SIN cuenta es valido: la capacidad no se deduce del tipo.
    $sinCuenta = $servicio->crear($admin, ['codigo' => 'DOM_'.strtoupper(Str::random(4)), 'nombre' => 'Nuevo', 'tipo' => 'domiciliario']);

    expect($sinCuenta->liquida_cuenta_corriente)->toBeFalse();

    $servicio->cambiarCapacidades($admin, $sinCuenta->id, true, false);
    expect($sinCuenta->fresh()->liquida_cuenta_corriente)->toBeTrue()->and($sinCuenta->fresh()->genera_guia_interna)->toBeFalse();

    // El snapshot de la guia no cambia si despues cambia la capacidad.
    $guia = log6_despachar(log2_pedido(1, ['envio' => 'free_free']), $c['jeison']);
    $servicio->cambiarCapacidades($admin, $c['jeison']->id, false, false);

    expect($guia->fresh()->liquida_cuenta_corriente)->toBeTrue()
        ->and(log2_error(fn () => $servicio->cambiarCapacidades(log2_bodega(), $c['jeison']->id, true, true)))->toBe(Invalida::SIN_PERMISO)
        // El generico DOMICILIARIO no lleva cuenta corriente.
        ->and((bool) DB::table('bylopez_lg_transportadoras')->where('codigo', 'DOMICILIARIO')->value('liquida_cuenta_corriente'))->toBeFalse();
});

test('F1-8 saldo pendiente nunca bloquea; un pago EN VALIDACION solo bloquea si quien entrega cobra dinero del pedido', function () {
    $c = log6_catalogo();
    log6_municipio('11001');
    $estado = fn (array $e) => app(ContextoDespachoService::class)->paraPedido((int) $e['pedido']->id, log2_bodega())['envio']['pago_pendiente'];

    // Saldo pendiente sin pagos en revision: contra entrega se despacha (cobra quien entrega; la deuda sigue en Cartera).
    // F6 (2026-10-26): la TRANSFERENCIA con saldo ya no sale con «no cobrar nada»: sin decision se bloquea; con «cobrar al
    // entregar» sale (la decision es del pedido y queda en la guia).
    log6_pagos(0, 51000);
    $codDebe = log2_pedido(1, ['envio' => 'free_free', 'pago' => 'cashondelivery']);
    $trDebe = log2_pedido(1, ['envio' => 'free_free', 'pago' => 'moneytransfer']);

    expect($estado($codDebe))->toBe(['bloquea' => false, 'sin_revisar' => 0.0, 'verificado' => null, 'saldo' => 51000.0, 'estado' => 'pendiente'])
        ->and(log6_despachar($codDebe, $c['jeison'])->id)->toBeInt()
        ->and(log2_error(fn () => log6_despachar($trDebe, $c['jeison'])))->toBe(Invalida::DISPOSICION_COBRO_REQUERIDA);

    app(DisposicionCobroService::class)->registrar(log2_bodega(), (int) $trDebe['pedido']->id, 'cobrar_al_entregar', null);
    expect(log6_despachar($trDebe, $c['jeison'])->disposicion_cobro_id)->toBeInt();

    // Contra entrega + pago en validacion (total o parcial): el valor a cobrar es incierto → bloqueado.
    $cod = log2_pedido(1, ['envio' => 'free_free', 'pago' => 'cashondelivery']);

    foreach ([[51000, 0], [20000, 31000]] as [$sinRevisar, $saldo]) {
        log6_pagos($sinRevisar, $saldo);

        expect($estado($cod)['estado'])->toBe('en_validacion')
            ->and($estado($cod)['bloquea'])->toBeTrue()
            ->and(log2_error(fn () => log6_despachar($cod, $c['jeison'])))->toBe(Invalida::PAGO_PENDIENTE_CONFIRMAR);
    }

    expect(DB::table('shipments')->where('order_id', $cod['pedido']->id)->count())->toBe(0);

    // Sin recaudo (transferencia, envio gratis) + pago en validacion: el pago de recaudo no bloquea (nadie cobra), pero F6 exige
    // decision porque lo reportado no es verificado; «cobrar al entregar» tampoco cabe (valor incierto) → credito autorizado.
    log6_pagos(51000, 0);
    $transferencia = log2_pedido(1, ['envio' => 'free_free', 'pago' => 'moneytransfer']);

    expect($estado($transferencia))->toBe(['bloquea' => false, 'sin_revisar' => 51000.0, 'verificado' => null, 'saldo' => 0.0, 'estado' => 'en_validacion'])
        ->and(log2_error(fn () => log6_despachar($transferencia, $c['jeison'])))->toBe(Invalida::DISPOSICION_COBRO_REQUERIDA)
        ->and(log2_error(fn () => app(DisposicionCobroService::class)->registrar(log2_bodega(), (int) $transferencia['pedido']->id, 'cobrar_al_entregar', null)))->toBe(Invalida::PAGO_PENDIENTE_CONFIRMAR);

    app(DisposicionCobroService::class)->registrar(f03b_admin('all'), (int) $transferencia['pedido']->id, 'credito_autorizado', 'Prueba: sale mientras Cartera revisa');
    expect(log6_despachar($transferencia, $c['jeison'])->id)->toBeInt();

    // Pagado y fuera de Cartera: se despacha.
    log6_pagos(0, 0);
    expect($estado($cod)['estado'])->toBe('pagado')->and(log6_despachar($cod, $c['jeison'])->id)->toBeInt();

    log6_pagos(0, null);
    $fuera = log2_pedido(1, ['envio' => 'free_free']);

    expect($estado($fuera))->toBe(['bloquea' => false, 'sin_revisar' => 0.0, 'verificado' => null, 'saldo' => null, 'estado' => null])
        ->and(log6_despachar($fuera, $c['jeison'])->id)->toBeInt();
});

test('F1-9 con Cartera real: contra entrega con pago en validacion se bloquea; rechazar o confirmar recalcula el cobro; transferencia solo avisa', function () {
    $c = log6_catalogo();
    log6_municipio('11001');
    $bodega = log2_bodega();
    $admin = f03b_admin('all');
    $ctx = fn (array $e) => app(ContextoDespachoService::class)->paraPedido((int) $e['pedido']->id, $bodega);

    // Contra entrega 51.000 con 20.000 reportados sin confirmar: ¿cobrar 51.000 o 31.000? → bloqueado.
    $rechazado = log6_en_cartera(['envio' => 'free_free', 'pago' => 'cashondelivery']);
    $pago = log6_transferencia($rechazado, $admin, '20000');

    expect($ctx($rechazado)['envio']['pago_pendiente']['bloquea'])->toBeTrue()
        ->and(log2_error(fn () => log6_despachar($rechazado, $c['jeison'], ['recaudoEsperado' => '31000'])))->toBe(Invalida::PAGO_PENDIENTE_CONFIRMAR);

    // Rechazado: vuelve a cobrar 51.000 y se despacha.
    app(RechazarPagoService::class)->rechazar($admin, $pago, 'no_encontrado', 'No aparece en el banco');

    expect($ctx($rechazado)['envio']['pago_pendiente']['bloquea'])->toBeFalse()
        ->and($ctx($rechazado)['recaudo_sugerido'])->toBe(51000.0)
        ->and((float) log6_despachar($rechazado, $c['jeison'], ['recaudoEsperado' => '51000'])->recaudo_esperado)->toBe(51000.0);

    // Confirmado: cobra 31.000 y se despacha.
    $confirmado = log6_en_cartera(['envio' => 'free_free', 'pago' => 'cashondelivery']);
    app(ConfirmarPagoService::class)->confirmar($admin, log6_transferencia($confirmado, $admin, '20000'));

    expect($ctx($confirmado)['envio']['pago_pendiente']['bloquea'])->toBeFalse()
        ->and($ctx($confirmado)['recaudo_sugerido'])->toBe(31000.0)
        ->and((float) log6_despachar($confirmado, $c['jeison'], ['recaudoEsperado' => '31000'])->recaudo_esperado)->toBe(31000.0);

    // Transferencia (nadie cobra al entregar) con pago en validacion. F6 (2026-10-26): un pago reportado NO es dinero verificado,
    // asi que ya no sale en silencio: sin decision se bloquea; con credito autorizado sale. Cartera conserva el pago sin revisar.
    $transferencia = log6_en_cartera(['envio' => 'free_free', 'pago' => 'moneytransfer']);
    log6_transferencia($transferencia, $admin);
    $antes = log6_dinero($transferencia);

    expect($ctx($transferencia)['envio']['pago_pendiente'])->toBe(['bloquea' => false, 'sin_revisar' => 51000.0, 'verificado' => 0.0, 'saldo' => 0.0, 'estado' => 'en_validacion'])
        ->and(log2_error(fn () => log6_despachar($transferencia, $c['jeison'])))->toBe(Invalida::DISPOSICION_COBRO_REQUERIDA);

    app(DisposicionCobroService::class)->registrar($admin, (int) $transferencia['pedido']->id, 'credito_autorizado', 'Prueba: sale mientras Cartera revisa');

    expect(log6_despachar($transferencia, $c['jeison'])->condicion_envio)->toBe('gratis')
        ->and($ctx($transferencia)['envio']['pago_pendiente']['sin_revisar'])->toBe(51000.0)
        ->and(log6_dinero($transferencia))->toBe($antes);
});

test('F1-10 nacionales siguen igual: guia externa, pagador por condicion y sin tarifa ni costo obligatorio', function () {
    log6_municipio(null);
    $e = log2_pedido(2);

    $guia = app(DespachoService::class)->despachar(log2_bodega(), log2_s($e))->guia;

    expect($guia->transportadora_codigo)->toBe('INTER_RAPIDISIMO')
        ->and($guia->flete_pagador->value)->toBe('cliente_al_recibir')
        ->and($guia->condicion_envio)->toBe('por_pagar')
        ->and($guia->zona_origen)->toBe('sin_zona')
        ->and($guia->tarifa_sugerida)->toBeNull()
        ->and($guia->liquida_cuenta_corriente)->toBeFalse();
});

test('F1-11 pantalla Transportadoras: capacidades y tarifas visibles; solo quien gestiona cambia capacidades o crea tarifas', function () {
    $c = log6_catalogo();
    $admin = f03b_admin('all');

    $this->actingAs($admin, 'admin')->get(route('admin.logistica.transportadoras.index'))
        ->assertOk()
        ->assertSee('data-tarifas-movidas', false)
        ->assertDontSee('data-nueva-tarifa', false)
        ->assertSee($c['jeison']->codigo)
        ->assertSee(trans('logistica::app.transportadoras.cap-cuenta'));

    $this->actingAs($admin, 'admin')->post(route('admin.logistica.transportadoras.capacidades', $c['picap']->id), ['genera_guia_interna' => 1])
        ->assertRedirect(route('admin.logistica.transportadoras.index'));

    expect($c['picap']->fresh()->liquida_cuenta_corriente)->toBeFalse()->and($c['picap']->fresh()->genera_guia_interna)->toBeTrue();

    $this->actingAs($admin, 'admin')->post(route('admin.logistica.tarifas.store'), [
        'transportadora_id' => $c['brian']->id, 'zona_id' => $c['bogota']->id, 'valor' => '13000', 'vigente_desde' => now()->addDay()->toDateString(),
    ])->assertRedirect(route('admin.logistica.transportadoras.index'));

    // Tarifa futura: queda en el historial pero la vigente hoy sigue siendo 12.000.
    expect(Tarifa::query()->where('transportadora_id', $c['brian']->id)->where('zona_id', $c['bogota']->id)->count())->toBe(2)
        ->and((float) app(ZonasYTarifasService::class)->tarifaVigente($c['brian']->id, $c['bogota']->id)->valor)->toBe(12000.0);

    // BODEGA no gestiona el catalogo (ACL de la ruta).
    $this->actingAs(log2_bodega(), 'admin')->post(route('admin.logistica.transportadoras.capacidades', $c['picap']->id), ['liquida_cuenta_corriente' => 1])
        ->assertStatus(401);

    expect($c['picap']->fresh()->liquida_cuenta_corriente)->toBeFalse();
});

/** Pedido real en Cartera (factura pendiente) por 51.000. */
function log6_en_cartera(array $o): array
{
    pagb_corte('2000-01-01');
    $e = log2_pedido(1, $o + ['total' => 51000.0]);
    pagb_factura($e['pedido'], 'pending', 51000.0);

    return $e;
}

function log6_saldo(array $e): float
{
    return (float) app(CarteraConsultaService::class)->saldosDePedidos([(int) $e['pedido']->id])[(int) $e['pedido']->id]->saldo;
}

/** Huella del dinero del pedido: entregar o despachar nunca debe crear un pago, una aplicacion ni una transaccion. */
function log6_dinero(array $e): array
{
    return [
        Pago::query()->count(),
        AplicacionPago::query()->where('order_id', $e['pedido']->id)->count(),
        DB::table('order_transactions')->where('order_id', $e['pedido']->id)->count(),
    ];
}

function log6_transferencia(array $e, $admin, string $monto = '51000'): int
{
    return app(RegistrarPagoService::class)->registrar($admin, (int) $e['pedido']->id, [
        'monto' => $monto, 'medio_cobro' => 'transferencia', 'plataforma' => 'nequi', 'fecha_pago' => now()->toDateString(), 'referencia' => 'TRX-'.Str::random(6),
    ], 'f1-pago-'.Str::uuid())->pagoId;
}

test('F1-12 recogida con Cartera real: F6 exige una decision de cobro con saldo o pago sin confirmar; con ella SE ENTREGA y la deuda sigue; confirmar/rechazar recalcula', function () {
    $bodega = log2_bodega();
    $admin = f03b_admin('all');
    $credito = f03b_admin('custom', array_merge(LOG2_PERMISOS_BODEGA, ['logistica.despachos.autorizar_credito']));
    $servicio = app(DespachoService::class);
    $decisiones = app(DisposicionCobroService::class);
    $ctx = fn (array $e) => app(ContextoDespachoService::class)->paraPedido((int) $e['pedido']->id, $bodega);
    $entregar = fn (array $e) => $servicio->entregarEnBodega($bodega, new SolicitudRecogida((int) $e['pedido']->id, 'bod-'.Str::uuid()));

    // 1) Debe 51.000 y no hay pagos en revision: F6 (jornada 2026-10-28) tambien aplica a la recogida, asi que NO sale «sin cobrar» en
    //    silencio. Con la instruccion «cobrar al retirar» BODEGA entrega; nada inventa un pago y el saldo sigue en 51.000.
    $debe = log6_en_cartera(['envio' => 'pickup', 'pago' => 'moneytransfer']);
    $antes = log6_dinero($debe);

    expect($ctx($debe)['modo'])->toBe('entregar_bodega')
        ->and($ctx($debe)['envio']['pago_pendiente'])->toBe(['bloquea' => false, 'sin_revisar' => 0.0, 'verificado' => 0.0, 'saldo' => 51000.0, 'estado' => 'pendiente'])
        ->and($ctx($debe)['disposicion_cobro']['requiere'])->toBeTrue()
        ->and(log2_error(fn () => $entregar($debe)))->toBe(Invalida::DISPOSICION_COBRO_REQUERIDA)
        ->and(DB::table('shipments')->where('order_id', $debe['pedido']->id)->count())->toBe(0);

    $decisiones->registrar($bodega, (int) $debe['pedido']->id, 'cobrar_al_entregar', null);

    expect($entregar($debe)->entrega)->not->toBeNull()
        ->and(DB::table('bylopez_lg_guias')->where('order_id', $debe['pedido']->id)->count())->toBe(0)
        ->and(log6_saldo($debe))->toBe(51000.0)
        ->and(log6_dinero($debe))->toBe($antes);

    // 2) Pago en validacion: tambien pide decision; «cobrar» no aplica (el valor es incierto) y el credito exige autoridad. SE ENTREGA y
    //    Cartera conserva el pago sin revisar; al rechazarlo vuelve a deber 51.000.
    $validacion = log6_en_cartera(['envio' => 'pickup', 'pago' => 'moneytransfer']);
    $pago = log6_transferencia($validacion, $admin);
    $antes = log6_dinero($validacion);

    expect($ctx($validacion)['envio']['pago_pendiente'])->toBe(['bloquea' => false, 'sin_revisar' => 51000.0, 'verificado' => 0.0, 'saldo' => 0.0, 'estado' => 'en_validacion'])
        ->and(log2_error(fn () => $entregar($validacion)))->toBe(Invalida::DISPOSICION_COBRO_REQUERIDA)
        ->and(log2_error(fn () => $decisiones->registrar($bodega, (int) $validacion['pedido']->id, 'cobrar_al_entregar', null)))->toBe(Invalida::PAGO_PENDIENTE_CONFIRMAR);

    $decisiones->registrar($credito, (int) $validacion['pedido']->id, 'credito_autorizado', 'Autoriza administracion: el comprobante esta en revision');

    expect($entregar($validacion)->entrega)->not->toBeNull()
        ->and(DB::table('bylopez_va_pagos')->where('id', $pago)->value('estado'))->toBe('sin_revisar')
        ->and(log6_dinero($validacion))->toBe($antes);

    app(RechazarPagoService::class)->rechazar($admin, $pago, 'no_encontrado', 'No aparece en el banco');

    expect(log6_saldo($validacion))->toBe(51000.0);

    // 3) Pago CONFIRMADO: no hay nada que decidir; se recalcula a pagado y se entrega.
    $pagado = log6_en_cartera(['envio' => 'pickup', 'pago' => 'moneytransfer']);
    app(ConfirmarPagoService::class)->confirmar($admin, log6_transferencia($pagado, $admin));
    $antes = log6_dinero($pagado);

    expect($ctx($pagado)['envio']['pago_pendiente']['estado'])->toBe('pagado')
        ->and($ctx($pagado)['disposicion_cobro']['requiere'])->toBeFalse()
        ->and($entregar($pagado)->entrega)->not->toBeNull()
        ->and(log6_saldo($pagado))->toBe(0.0)
        ->and(log6_dinero($pagado))->toBe($antes);
});

test('F1-13 contraentrega y domiciliario con saldo pendiente se despachan; el recaudo sugerido es el saldo y no se crea ningun pago', function () {
    $c = log6_catalogo();
    log6_municipio('11001');
    $bodega = log2_bodega();

    // Contraentrega nacional: debe 51.000, se despacha y el transportador cobrara 51.000.
    $cod = log6_en_cartera(['envio' => 'envio_nacional', 'pago' => 'cashondelivery']);
    $antes = log6_dinero($cod);

    expect(app(ContextoDespachoService::class)->paraPedido((int) $cod['pedido']->id, $bodega)['recaudo_sugerido'])->toBe(51000.0);

    $guia = app(DespachoService::class)->despachar($bodega, log2_s($cod, ['recaudoEsperado' => '51000']))->guia;

    expect((float) $guia->recaudo_esperado)->toBe(51000.0)
        ->and(log6_saldo($cod))->toBe(51000.0)
        ->and(log6_dinero($cod))->toBe($antes);

    // Domiciliario local (Jeison) con un pedido por TRANSFERENCIA pendiente de pago. F6: sin decision no sale; con «cobrar al
    // entregar» el domiciliario lleva el saldo (51.000) como recaudo. Despachar sigue sin ser cobrar: ningun pago nuevo.
    $dom = log6_en_cartera(['envio' => 'domicilio_local', 'pago' => 'moneytransfer']);
    $antes = log6_dinero($dom);

    expect(log2_error(fn () => log6_despachar($dom, $c['jeison'])))->toBe(Invalida::DISPOSICION_COBRO_REQUERIDA);

    app(DisposicionCobroService::class)->registrar($bodega, (int) $dom['pedido']->id, 'cobrar_al_entregar', null);
    $guiaDom = log6_despachar($dom, $c['jeison']);

    expect($guiaDom->condicion_envio)->toBe('cobrado_en_pedido')
        ->and((float) $guiaDom->recaudo_esperado)->toBe(51000.0)
        ->and(log6_saldo($dom))->toBe(51000.0)
        ->and(log6_dinero($dom))->toBe($antes);
});

test('F1-14 domiciliario sugerido: el ultimo REALMENTE usado en la zona; nunca nacional, desactivado ni el generico', function () {
    $c = log6_catalogo();
    $bodega = log2_bodega();
    $ctx = fn (array $e) => app(ContextoDespachoService::class)->paraPedido((int) $e['pedido']->id, $bodega);
    $local = fn () => log2_pedido(1, ['envio' => 'domicilio_local', 'pago' => 'moneytransfer']);
    $porZona = fn (array $e) => (array) $ctx($e)['envio']['ultimo_por_zona'];

    // Sin historial en la zona: sin seleccionar.
    log6_municipio('11001');
    expect($ctx($local())['sugerencias']['transportadora'])->toBeNull();

    // Ultimo usado en Bogota = Jeison; en Soacha = Brian. Cada zona sugiere el suyo.
    log6_despachar($local(), $c['jeison']);
    log6_municipio('25754');
    log6_despachar($local(), $c['brian']);

    expect($ctx($local())['sugerencias']['transportadora'])->toBe($c['brian']->codigo);
    log6_municipio('11001');
    expect($ctx($local())['sugerencias']['transportadora'])->toBe($c['jeison']->codigo)
        // El drawer recibe la tabla por zona para recalcular si la zona se elige a mano.
        ->and($porZona($local()))->toBe([$c['bogota']->id => $c['jeison']->codigo, $c['soacha']->id => $c['brian']->codigo]);

    // Un envio NACIONAL que paso por Bogota no cuenta, y a un pedido nacional no se le sugiere domiciliario.
    $nacional = log2_pedido(1, ['envio' => 'envio_nacional', 'pago' => 'moneytransfer']);
    app(DespachoService::class)->despachar($bodega, log2_s($nacional));

    expect($ctx($local())['sugerencias']['transportadora'])->toBe($c['jeison']->codigo)
        ->and($ctx(log2_pedido(1, ['envio' => 'envio_nacional']))['sugerencias']['transportadora'])->toBeNull()
        ->and($ctx(log2_pedido(1, ['envio' => 'envio_nacional']))['envio']['domicilio_local'])->toBeFalse();

    // El generico DOMICILIARIO, aunque estuviera activo y se usara, nunca se sugiere.
    DB::table('bylopez_lg_transportadoras')->updateOrInsert(['codigo' => 'DOMICILIARIO'], ['nombre' => 'Domiciliario', 'tipo' => 'domiciliario', 'activo' => true]);
    log6_despachar($local(), Transportadora::query()->where('codigo', 'DOMICILIARIO')->firstOrFail(), ['valorFlete' => '9000']);

    expect($ctx($local())['sugerencias']['transportadora'])->toBe($c['jeison']->codigo);

    // Elegir a mano otro domiciliario (Brian en Bogota) es valido y pasa a ser el ultimo usado alli.
    log6_despachar($local(), $c['brian']);
    expect($ctx($local())['sugerencias']['transportadora'])->toBe($c['brian']->codigo);

    // Brian desactivado: nunca se sugiere; vuelve el ultimo valido (Jeison).
    $c['brian']->update(['activo' => false]);
    expect($ctx($local())['sugerencias']['transportadora'])->toBe($c['jeison']->codigo)
        ->and($porZona($local()))->not->toContain($c['brian']->codigo);

    // Sin zona automatica: nada preseleccionado (la tabla por zona sigue disponible para la eleccion manual).
    log6_municipio(null);
    expect($ctx($local())['sugerencias']['transportadora'])->toBeNull()
        ->and($porZona($local())[$c['bogota']->id])->toBe($c['jeison']->codigo);
});

test('F1-15 cierre: elegido en la venta gana al historial; domicilio local = metodo + ZONA; PICAP manual pero nunca sugerido', function () {
    $c = log6_catalogo();
    $bodega = log2_bodega();
    // Se resuelve en cada uso: el municipio de prueba se cambia durante la prueba.
    $ventas = fn () => app(DomiciliarioDeVenta::class);
    $ctx = fn (array $e) => app(ContextoDespachoService::class)->paraPedido((int) $e['pedido']->id, $bodega);
    $local = fn (string $metodo = 'domicilio_local') => log2_pedido(1, ['envio' => $metodo, 'pago' => 'moneytransfer']);
    $preferencias = fn () => DB::table('bylopez_lg_preferencias_entrega')->count();

    // Domicilio local = metodo que admite entrega local + ZONA local. Sin zona (Medellin), recogida o nacional: nada.
    $opciones = fn (?string $m, ?string $metodo) => $ventas()->opcionesParaVenta($m, $metodo, null);
    expect($opciones('11001', 'free_free')['zona']['id'])->toBe($c['bogota']->id)
        ->and($opciones('11001', 'flatrate_flatrate')['zona']['id'])->toBe($c['bogota']->id)
        ->and($opciones('25754', 'domicilio_local')['zona']['id'])->toBe($c['soacha']->id)
        ->and($opciones('05001', 'free_free'))->toBeNull()
        ->and($opciones('11001', 'pickup'))->toBeNull()
        ->and($opciones('11001', 'envio_nacional'))->toBeNull()
        // Metodo aun sin elegir: en zona local ya se pide el domiciliario (la tarifa del domicilio depende de el).
        ->and($opciones('11001', null)['requerido'])->toBeTrue()
        ->and($opciones('11001', null)['listo'])->toBeFalse()
        ->and($opciones('11001', null)['tarifa'])->toBeNull()
        ->and($opciones('05001', null))->toBeNull();

    // PICAP se puede elegir a mano (venta y drawer), pero usarlo NO lo convierte en sugerencia: sigue Jeison.
    log6_municipio('11001');
    log6_despachar($local(), $c['jeison']);
    log6_despachar($local(), $c['picap'], ['valorFlete' => '8000']);
    $ids = array_column($opciones('11001', 'free_free')['opciones'], 'id');

    expect($ids)->toContain($c['picap']->id, $c['jeison']->id, $c['brian']->id)
        ->and($opciones('11001', 'free_free')['sugerido_id'])->toBe($c['jeison']->id)
        ->and($ctx($local())['sugerencias']['transportadora'])->toBe($c['jeison']->codigo)
        ->and(array_column($ctx($local())['transportadoras'], 'codigo'))->toContain($c['picap']->codigo)
        ->and($ventas()->esSeleccionable($c['picap']->id))->toBeTrue();

    // Free_free en Bogota tambien es candidato local; free_free en Medellin (sin zona): sin sugerencia ni memoria.
    expect($ctx($local('free_free'))['sugerencias']['transportadora'])->toBe($c['jeison']->codigo);
    log6_municipio('05001');
    $medellin = $ctx($local('free_free'));
    expect($medellin['sugerencias']['transportadora'])->toBeNull()
        ->and($medellin['envio']['domicilio_local'])->toBeFalse()
        ->and($medellin['envio']['admite_domicilio_local'])->toBeTrue();

    // Eleccion del asesor (Brian) en un pedido local: gana al historial (Jeison). Idempotente por pedido.
    log6_municipio('11001');
    $elegido = $local();
    $ventas()->registrarDesdeVenta((int) $elegido['pedido']->id, $c['brian']->id, (int) $bodega->id);
    $ventas()->registrarDesdeVenta((int) $elegido['pedido']->id, $c['jeison']->id, (int) $bodega->id);

    expect($ctx($elegido)['sugerencias']['transportadora'])->toBe($c['brian']->codigo)
        ->and($ctx($elegido)['envio']['elegido_venta'])->toBe($c['brian']->codigo)
        ->and(DB::table('bylopez_lg_preferencias_entrega')->where('order_id', $elegido['pedido']->id)->first())
        ->transportadora_id->toBe($c['brian']->id)
        ->origen->toBe('venta')
        ->seleccionado_por_admin_id->toBe((int) $bodega->id);

    // No se guarda: recogida, nacional, sin zona, generico o desactivada.
    $antes = $preferencias();
    $ventas()->registrarDesdeVenta((int) log2_pedido(1, ['envio' => 'pickup'])['pedido']->id, $c['brian']->id, null);
    $ventas()->registrarDesdeVenta((int) log2_pedido(1, ['envio' => 'envio_nacional'])['pedido']->id, $c['brian']->id, null);
    DB::table('bylopez_lg_transportadoras')->updateOrInsert(['codigo' => 'DOMICILIARIO'], ['nombre' => 'Domiciliario', 'tipo' => 'domiciliario', 'activo' => true]);
    $generico = (int) DB::table('bylopez_lg_transportadoras')->where('codigo', 'DOMICILIARIO')->value('id');
    $ventas()->registrarDesdeVenta((int) $local()['pedido']->id, $generico, null);
    log6_municipio('05001');
    $ventas()->registrarDesdeVenta((int) $local()['pedido']->id, $c['brian']->id, null);

    expect($preferencias())->toBe($antes)
        ->and($ventas()->esSeleccionable($generico))->toBeFalse();

    // Elegido luego desactivado: el despacho vuelve al historial de la zona.
    log6_municipio('11001');
    $c['brian']->update(['activo' => false]);

    expect($ctx($elegido)['sugerencias']['transportadora'])->toBe($c['jeison']->codigo)
        ->and($ctx($elegido)['envio']['elegido_venta'])->toBeNull()
        ->and($ventas()->esSeleccionable($c['brian']->id))->toBeFalse();
});
