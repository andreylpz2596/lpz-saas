<?php

use ByLopez\VentasAsesor\Enums\AlcanceVisibilidad;
use ByLopez\VentasAsesor\Enums\EstadoPago;
use ByLopez\VentasAsesor\Enums\TipoEventoPago;
use ByLopez\VentasAsesor\Exceptions\PagoEventoInmutableException;
use ByLopez\VentasAsesor\Models\AplicacionPago;
use ByLopez\VentasAsesor\Models\Pago;
use ByLopez\VentasAsesor\Models\PagoEvento;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor as Autz;
use ByLopez\VentasAsesor\Support\Cartera\FechaCorteCartera;
use ByLopez\VentasAsesor\Support\Cartera\MediosCobro;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;
use Webkul\User\Models\Role;

require_once __DIR__.'/../helpersPagB.php';

uses(TestCase::class);

/*
| PAG-B · persistencia y soporte del dominio de Pagos / Cartera (D-59, Doc. 05 §42). Sin servicios de escritura:
| solo esquema, modelos, estados, medios de cobro, fecha de corte y ACL. Todo dentro de la transaccion de la
| prueba, sobre lpz_saas_testing.
*/

/* ---------------------------------------------------------------- esquema */

test('las tres tablas existen con los tipos de Bagisto (int unsigned), dinero decimal(12,4) y sin la tabla de evidencias', function () {
    foreach (['bylopez_va_pagos', 'bylopez_va_aplicaciones_pago', 'bylopez_va_pagos_eventos'] as $tabla) {
        expect(Schema::hasTable($tabla))->toBeTrue($tabla);
    }

    // Evidencias pertenece a PAG-G: no existe todavia.
    expect(Schema::hasTable('bylopez_va_pago_evidencias'))->toBeFalse();

    $tipo = fn (string $tabla, string $columna) => DB::select("SHOW COLUMNS FROM {$tabla} WHERE Field = ?", [$columna])[0]->Type;

    expect($tipo('bylopez_va_pagos', 'customer_id'))->toBe($tipo('customers', 'id'))
        ->and($tipo('bylopez_va_aplicaciones_pago', 'order_id'))->toBe($tipo('orders', 'id'))
        ->and($tipo('bylopez_va_aplicaciones_pago', 'invoice_id'))->toBe($tipo('invoices', 'id'))
        ->and($tipo('bylopez_va_aplicaciones_pago', 'order_transaction_id'))->toBe($tipo('order_transactions', 'id'))
        ->and($tipo('bylopez_va_pagos', 'monto'))->toBe('decimal(12,4)')
        ->and($tipo('bylopez_va_aplicaciones_pago', 'monto'))->toBe('decimal(12,4)')
        ->and($tipo('orders', 'base_grand_total'))->toBe('decimal(12,4)')
        ->and($tipo('order_transactions', 'amount'))->toBe('decimal(12,4)');

    // Sin columnas de saldo ni deuda (P-03): todo es derivado.
    $columnas = array_merge(...array_map(fn (string $t) => Schema::getColumnListing($t), ['bylopez_va_pagos', 'bylopez_va_aplicaciones_pago']));

    expect(array_intersect($columnas, ['saldo', 'deuda', 'balance', 'pendiente']))->toBe([]);
});

test('pagos: nace sin_revisar, los tipos se convierten y UNIQUE(mecanismo, idempotency_key) rechaza la repeticion', function () {
    $cliente = pagb_cliente();
    $pago = pagb_pago($cliente, ['monto' => 150000.5]);
    $pago = $pago->fresh();

    expect($pago->estado)->toBe(EstadoPago::SIN_REVISAR)
        ->and($pago->monto)->toBe('150000.5000')
        ->and($pago->fecha_pago->toDateString())->toBe(now()->toDateString())
        ->and($pago->revisado_por_admin_id)->toBeNull()
        ->and($pago->revisado_en)->toBeNull();

    // Misma clave y mismo mecanismo: la base lo impide.
    expect(fn () => pagb_pago($cliente, ['mecanismo' => $pago->mecanismo, 'idempotency_key' => $pago->idempotency_key]))
        ->toThrow(UniqueConstraintViolationException::class);

    // La unicidad es por (mecanismo, clave): otro canal puede reutilizar la clave.
    expect(pagb_pago($cliente, ['mecanismo' => 'api', 'idempotency_key' => $pago->idempotency_key])->exists)->toBeTrue();
});

test('pagos: monto > 0 (CHECK), cliente con FK RESTRICT y los ids de administrador sin FK', function () {
    $cliente = pagb_cliente();

    expect(fn () => pagb_pago($cliente, ['monto' => 0]))->toThrow(QueryException::class)
        ->and(fn () => pagb_pago($cliente, ['monto' => -5]))->toThrow(QueryException::class);

    pagb_pago($cliente);

    // La historia financiera no se cascadea ni se pierde con el cliente.
    expect(fn () => DB::table('customers')->where('id', $cliente->id)->delete())->toThrow(QueryException::class);

    // Un administrador inexistente no rompe nada: la auditoria sobrevive a su borrado (sin FK).
    expect(pagb_pago($cliente, ['registrado_por_admin_id' => 999999999])->exists)->toBeTrue();
});

test('aplicaciones: UNIQUE(pago, pedido), order_transaction_id UNIQUE (varios NULL permitidos), monto > 0 y FKs RESTRICT', function () {
    $cliente = pagb_cliente();
    $pedidoA = pagb_pedido($cliente, 200000);
    $pedidoB = pagb_pedido($cliente, 300000);
    $pago = pagb_pago($cliente, ['monto' => 500000]);

    $a = pagb_aplicar($pago, $pedidoA, 200000);
    pagb_aplicar($pago, $pedidoB, 300000);

    expect($a->fresh()->monto)->toBe('200000.0000')
        ->and($a->order_transaction_id)->toBeNull()
        ->and($a->anulada_en)->toBeNull()
        ->and($a->estaActiva())->toBeTrue();

    // Un pago aplica UNA vez a cada pedido.
    expect(fn () => pagb_aplicar($pago, $pedidoA, 1))->toThrow(UniqueConstraintViolationException::class);

    // monto > 0.
    $otro = pagb_pago($cliente);
    expect(fn () => pagb_aplicar($otro, $pedidoA, 0))->toThrow(QueryException::class);

    // Una Transaction nativa solo puede vincularse a UNA aplicacion.
    $facturaA = pagb_factura($pedidoA);
    $transaccion = pagb_transaccion($pedidoA, $facturaA, 200000);

    $a->update(['order_transaction_id' => $transaccion, 'invoice_id' => $facturaA]);

    $tercero = pagb_pago($cliente);

    expect(fn () => pagb_aplicar($tercero, $pedidoA, 1000, ['order_transaction_id' => $transaccion]))
        ->toThrow(UniqueConstraintViolationException::class);

    // FKs RESTRICT: no se borra historia financiera.
    expect(fn () => DB::table('bylopez_va_pagos')->where('id', $pago->id)->delete())->toThrow(QueryException::class)
        ->and(fn () => DB::table('orders')->where('id', $pedidoA->id)->delete())->toThrow(QueryException::class)
        ->and(fn () => DB::table('order_transactions')->where('id', $transaccion)->delete())->toThrow(QueryException::class);
});

test('aplicaciones: activas = no anuladas; anular conserva la fila', function () {
    $cliente = pagb_cliente();
    $pedido = pagb_pedido($cliente, 100000);
    $pagoUno = pagb_pago($cliente);
    $pagoDos = pagb_pago($cliente);

    $activa = pagb_aplicar($pagoUno, $pedido, 40000);
    $anulada = pagb_aplicar($pagoDos, $pedido, 60000, ['anulada_en' => now(), 'anulada_por_admin_id' => 7]);

    expect(AplicacionPago::query()->activas()->where('order_id', $pedido->id)->pluck('id')->all())->toBe([$activa->id])
        ->and($anulada->fresh()->estaActiva())->toBeFalse()
        ->and(AplicacionPago::query()->where('order_id', $pedido->id)->count())->toBe(2);
});

test('eventos: append-only por el modelo (sin update, delete ni save sobre filas existentes) y FK RESTRICT', function () {
    $cliente = pagb_cliente();
    $pago = pagb_pago($cliente);

    $evento = PagoEvento::query()->create([
        'pago_id' => $pago->id, 'tipo' => TipoEventoPago::REGISTRADO, 'actor_admin_id' => null,
        'mecanismo' => 'ventas_asesor', 'datos' => ['distribucion' => [['order_id' => 1, 'monto' => 100]]],
    ]);

    $evento = $evento->fresh();

    expect($evento->tipo)->toBe(TipoEventoPago::REGISTRADO)
        ->and($evento->datos['distribucion'][0]['monto'])->toBe(100)
        ->and($evento->actor_admin_id)->toBeNull();

    expect(fn () => $evento->update(['motivo' => 'cambio']))->toThrow(PagoEventoInmutableException::class)
        ->and(fn () => $evento->delete())->toThrow(PagoEventoInmutableException::class)
        ->and(fn () => $evento->save())->toThrow(PagoEventoInmutableException::class);

    // La historia no se pierde con el pago.
    expect(fn () => DB::table('bylopez_va_pagos')->where('id', $pago->id)->delete())->toThrow(QueryException::class)
        ->and($pago->eventos()->count())->toBe(1);
});

/* ---------------------------------------------------------------- estados */

test('EstadoPago: cuatro valores, transiciones aprobadas (P-04) y efecto sobre el saldo', function () {
    expect(EstadoPago::valores())->toBe(['sin_revisar', 'confirmado', 'rechazado', 'reversado']);

    $permitidas = [
        'sin_revisar>confirmado', 'sin_revisar>rechazado', 'confirmado>reversado',
    ];

    foreach (EstadoPago::cases() as $origen) {
        foreach (EstadoPago::cases() as $destino) {
            expect($origen->puedeTransicionarA($destino))
                ->toBe(in_array($origen->value.'>'.$destino->value, $permitidas, true), "{$origen->value} → {$destino->value}");
        }
    }

    expect(EstadoPago::SIN_REVISAR->transicionesPermitidas())->toBe([EstadoPago::CONFIRMADO, EstadoPago::RECHAZADO])
        ->and(EstadoPago::RECHAZADO->esFinal())->toBeTrue()
        ->and(EstadoPago::REVERSADO->esFinal())->toBeTrue()
        ->and(EstadoPago::SIN_REVISAR->esFinal())->toBeFalse()
        ->and(EstadoPago::CONFIRMADO->esFinal())->toBeFalse();

    // Solo sin_revisar y confirmado bajan el saldo; solo confirmado es dinero verificado.
    expect(array_map(fn (EstadoPago $e) => $e->cuentaParaSaldo(), EstadoPago::cases()))->toBe([true, true, false, false])
        ->and(array_map(fn (EstadoPago $e) => $e->esVerificado(), EstadoPago::cases()))->toBe([false, true, false, false]);

    $pago = pagb_pago(pagb_cliente());

    expect($pago->puedeTransicionarA(EstadoPago::CONFIRMADO))->toBeTrue()
        ->and($pago->puedeTransicionarA(EstadoPago::REVERSADO))->toBeFalse();
});

test('TipoEventoPago: catalogo, estado resultante y motivo obligatorio solo al rechazar o reversar', function () {
    expect(array_map(fn (TipoEventoPago $t) => $t->value, TipoEventoPago::cases()))
        ->toBe(['registrado', 'confirmado', 'rechazado', 'reversado', 'evidencia_agregada', 'posible_duplicado']);

    expect(TipoEventoPago::REGISTRADO->estadoResultante())->toBe(EstadoPago::SIN_REVISAR)
        ->and(TipoEventoPago::RECHAZADO->estadoResultante())->toBe(EstadoPago::RECHAZADO)
        ->and(TipoEventoPago::EVIDENCIA_AGREGADA->estadoResultante())->toBeNull()
        ->and(TipoEventoPago::POSIBLE_DUPLICADO->estadoResultante())->toBeNull()
        ->and(TipoEventoPago::RECHAZADO->exigeMotivo())->toBeTrue()
        ->and(TipoEventoPago::REVERSADO->exigeMotivo())->toBeTrue()
        ->and(TipoEventoPago::CONFIRMADO->exigeMotivo())->toBeFalse();
});

/* ---------------------------------------------------------------- medios de cobro */

test('medios de cobro: una sola fuente; transferencia exige referencia; medio de cobro != metodo de pago del pedido', function () {
    $medios = app(MediosCobro::class);

    expect(array_keys($medios->todos()))->toBe(['transferencia', 'efectivo', 'contraentrega_recaudo'])
        ->and($medios->exigeReferencia('transferencia'))->toBeTrue()
        ->and($medios->politicaReferencia('efectivo'))->toBe(MediosCobro::REFERENCIA_OPCIONAL)
        ->and($medios->exigeReferencia('efectivo'))->toBeFalse()
        ->and($medios->admiteReferencia('contraentrega_recaudo'))->toBeTrue()
        ->and($medios->existe('moneytransfer'))->toBeFalse()          // metodo de pago del pedido, no medio de cobro
        ->and($medios->existe('cashondelivery'))->toBeFalse()
        ->and($medios->etiqueta('transferencia'))->not->toBe('ventasasesor::app.cartera.medios.transferencia')
        ->and($medios->etiqueta('transferencia'))->toBe(trans('ventasasesor::app.cartera.medios.transferencia'));

    // Los metodos de pago del pedido (03E) siguen siendo otra lista, sin solaparse.
    expect(array_intersect(config('ventasasesor.pagos.metodos_permitidos'), array_keys($medios->todos())))->toBe([]);
});

test('medios de cobro: se configuran (activo y politica), y una configuracion invalida falla en voz alta', function () {
    $medios = app(MediosCobro::class);

    config(['ventasasesor.pagos.medios_cobro.efectivo.activo' => false]);

    expect(array_keys($medios->activos()))->toBe(['transferencia', 'contraentrega_recaudo'])
        ->and($medios->estaActivo('efectivo'))->toBeFalse()
        ->and($medios->existe('efectivo'))->toBeTrue();

    config(['ventasasesor.pagos.medios_cobro.efectivo.referencia' => 'requerida']);

    expect($medios->exigeReferencia('efectivo'))->toBeTrue();

    config(['ventasasesor.pagos.medios_cobro.efectivo.referencia' => 'siempre']);
    expect(fn () => $medios->todos())->toThrow(LogicException::class);

    config(['ventasasesor.pagos.medios_cobro.efectivo.referencia' => 'opcional']);
    config(['ventasasesor.pagos.medios_cobro' => ['Mal Codigo' => ['referencia' => 'opcional']]]);
    expect(fn () => $medios->todos())->toThrow(LogicException::class);

    config(['ventasasesor.pagos.medios_cobro' => ['efectivo' => ['referencia' => 'opcional']]]);
    expect(fn () => $medios->etiqueta('desconocido'))->toThrow(LogicException::class)
        ->and(fn () => $medios->politicaReferencia('desconocido'))->toThrow(LogicException::class);
});

test('referencia normalizada: sin espacios ni signos, en mayusculas; vacia = null', function () {
    expect(MediosCobro::normalizarReferencia('  ab-12 3 '))->toBe('AB123')
        ->and(MediosCobro::normalizarReferencia('trf/0099.x'))->toBe('TRF0099X')
        ->and(MediosCobro::normalizarReferencia('---'))->toBeNull()
        ->and(MediosCobro::normalizarReferencia(''))->toBeNull()
        ->and(MediosCobro::normalizarReferencia(null))->toBeNull();
});

test('los codigos de medio de cobro viven solo en la configuracion y los textos (ningun otro archivo del paquete los repite)', function () {
    $repetidos = [];

    foreach ((new Finder)->files()->in(dirname(__DIR__, 2).'/src')->name('*.php') as $archivo) {
        $ruta = str_replace('\\', '/', $archivo->getRealPath());

        if (str_contains($ruta, '/Config/ventasasesor.php') || str_contains($ruta, '/Resources/lang/')) {
            continue;
        }

        foreach (["'transferencia'", "'efectivo'", "'contraentrega_recaudo'"] as $literal) {
            if (str_contains($archivo->getContents(), $literal)) {
                $repetidos[] = basename($ruta).' '.$literal;
            }
        }
    }

    expect($repetidos)->toBe([]);
});

/* ---------------------------------------------------------------- fecha de corte */

test('fecha de corte: sin fecha Cartera esta inactiva (falla cerrado); con fecha, incluye desde ese instante', function () {
    $corte = app(FechaCorteCartera::class);

    foreach ([null, '', '   '] as $vacio) {
        pagb_corte($vacio);

        expect($corte->valor())->toBeNull()
            ->and($corte->activa())->toBeFalse()
            ->and($corte->incluye(now()))->toBeFalse();
    }

    pagb_corte('2026-10-01 08:00:00');

    expect($corte->activa())->toBeTrue()
        ->and($corte->valor()->toDateTimeString())->toBe('2026-10-01 08:00:00')
        ->and($corte->valor()->timezoneName)->toBe(config('app.timezone'))
        ->and($corte->incluye(CarbonImmutable::parse('2026-10-01 08:00:00', config('app.timezone'))))->toBeTrue()      // el limite entra
        ->and($corte->incluye(CarbonImmutable::parse('2026-10-01 07:59:59', config('app.timezone'))))->toBeFalse()
        ->and($corte->incluye(CarbonImmutable::parse('2026-12-31', config('app.timezone'))))->toBeTrue();

    pagb_corte('no-es-una-fecha');
    expect(fn () => $corte->valor())->toThrow(InvalidArgumentException::class);
});

test('la fecha de corte no se inventa: por defecto Cartera esta inactiva', function () {
    expect(config('ventasasesor.cartera'))->toHaveKey('fecha_corte')
        ->and(app(FechaCorteCartera::class)->activa())->toBe(false);
})->skip(fn () => ! empty(env('VENTASASESOR_CARTERA_FECHA_CORTE')), 'El entorno define VENTASASESOR_CARTERA_FECHA_CORTE.');

/* ---------------------------------------------------------------- ACL */

test('ACL de cartera: alcance independiente de pedidos; la agrupadora sola no concede nada; sin roles asignados', function () {
    $autz = app(Autz::class);

    // No se asigno automaticamente a ningun rol existente (P-12: tampoco se quita sales.transactions aqui).
    expect(Role::query()->where('permissions', 'like', '%ventas_asesor.cartera%')->count())->toBe(0);

    $todo = pagb_asesor([Autz::PERMISO_MODULO, Autz::PERMISO_CARTERA_VER_TODA]);
    $propio = pagb_asesor([Autz::PERMISO_MODULO, Autz::PERMISO_CARTERA_VER_PROPIA]);
    $ambos = pagb_asesor([Autz::PERMISO_CARTERA_VER_PROPIA, Autz::PERMISO_CARTERA_VER_TODA]);
    $agrupadora = pagb_asesor([Autz::PERMISO_MODULO, 'ventas_asesor.cartera']);
    $soloPedidos = pagb_asesor([Autz::PERMISO_MODULO, Autz::PERMISO_PEDIDOS_VER_TODOS]);

    expect($autz->alcanceDeCartera($todo))->toBe(AlcanceVisibilidad::TODOS)
        ->and($autz->alcanceDeCartera($propio))->toBe(AlcanceVisibilidad::PROPIOS)
        ->and($autz->alcanceDeCartera($ambos))->toBe(AlcanceVisibilidad::TODOS)
        ->and($autz->alcanceDeCartera($agrupadora))->toBe(AlcanceVisibilidad::NINGUNO)
        ->and($autz->alcanceDeCartera($soloPedidos))->toBe(AlcanceVisibilidad::NINGUNO)   // pedidos.view_all NO da cartera
        ->and($autz->alcanceDeCartera(f03b_admin('all')))->toBe(AlcanceVisibilidad::TODOS)
        ->and($autz->alcanceDeCartera(null))->toBe(AlcanceVisibilidad::NINGUNO);

    // Espejo de puedeVerRegistroDe: PROPIOS solo ve lo suyo; sin atribucion valida se niega (RA-6).
    expect($autz->puedeVerCarteraDe($todo, null))->toBeTrue()
        ->and($autz->puedeVerCarteraDe($propio, (int) $propio->id))->toBeTrue()
        ->and($autz->puedeVerCarteraDe($propio, (int) $propio->id + 1))->toBeFalse()
        ->and($autz->puedeVerCarteraDe($propio, null))->toBeFalse()
        ->and($autz->puedeVerCarteraDe($agrupadora, (int) $agrupadora->id))->toBeFalse();

    // Las seis hojas existen como permisos distintos (registrar y revisar son capacidades separadas).
    expect(array_unique([
        Autz::PERMISO_CARTERA_VER_PROPIA, Autz::PERMISO_CARTERA_VER_TODA, Autz::PERMISO_CARTERA_REGISTRAR,
        Autz::PERMISO_CARTERA_ABONO_GENERAL, Autz::PERMISO_CARTERA_REVISAR, Autz::PERMISO_CARTERA_REVERSAR,
    ]))->toHaveCount(6);

    $soloRegistra = pagb_asesor([Autz::PERMISO_MODULO, Autz::PERMISO_CARTERA_REGISTRAR]);

    expect($autz->tienePermiso($soloRegistra, Autz::PERMISO_CARTERA_REGISTRAR))->toBeTrue()
        ->and($autz->tienePermiso($soloRegistra, Autz::PERMISO_CARTERA_REVISAR))->toBeFalse();
});

test('hasta PAG-C solo existe registrar: no hay clases de confirmar, rechazar ni reversar pagos (PAG-E / PAG-I)', function () {
    $encontradas = [];

    foreach ((new Finder)->files()->in(dirname(__DIR__, 2).'/src')->name('*.php') as $archivo) {
        if (preg_match('/(Confirmar|Rechazar|Reversar)Pago/', $archivo->getFilename())) {
            $encontradas[] = $archivo->getFilename();
        }
    }

    expect($encontradas)->toBe([])
        ->and(method_exists(Pago::class, 'confirmar'))->toBeFalse()
        ->and(method_exists(Pago::class, 'rechazar'))->toBeFalse()
        ->and(method_exists(Pago::class, 'reversar'))->toBeFalse();
});
