<?php

use ByLopez\VentasAsesor\DataObjects\ConsultaClienteComercial;
use ByLopez\VentasAsesor\DataObjects\DatosClienteComercial;
use ByLopez\VentasAsesor\DataObjects\ResolucionCliente;
use ByLopez\VentasAsesor\DataObjects\ResultadoBusquedaCliente;
use ByLopez\VentasAsesor\DataObjects\TitularTelefono;
use ByLopez\VentasAsesor\Enums\EstadoResolucionCliente as Estado;
use ByLopez\VentasAsesor\Enums\TipoResultadoBusquedaCliente as Tipo;
use ByLopez\VentasAsesor\Exceptions\ClienteComercialExistenteException;
use ByLopez\VentasAsesor\Exceptions\ConflictoIdentidadClienteException;
use ByLopez\VentasAsesor\Exceptions\DatosClienteComercialInvalidosException;
use ByLopez\VentasAsesor\Exceptions\GrupoComercialInvalidoException;
use ByLopez\VentasAsesor\Models\TelefonoCliente;
use ByLopez\VentasAsesor\Services\Clientes\BuscarClienteComercialService;
use ByLopez\VentasAsesor\Services\Clientes\CrearClienteComercialService;
use ByLopez\VentasAsesor\Services\Clientes\ResolverClienteComercialService;
use ByLopez\VentasAsesor\Services\Clientes\TelefonosClienteService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Webkul\Admin\Mail\Customer\NewCustomerNotification;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Mail\Customer\RegistrationNotification;

require_once __DIR__.'/../helpersRt04b1b.php';

uses(TestCase::class);

/*
| RT-04B1b — Buscar, crear y resolver cliente comercial. Solo lpz_saas_testing.
*/

function rt04b1b_crear(array $cambios = []): Customer
{
    $d = $cambios + ['nombre' => 'Aurora', 'apellido' => 'Ejemplo', 'telefono' => rt04b1b_movil(),
        'grupo' => rt04b1b_grupo()->id, 'email' => null];

    return app(CrearClienteComercialService::class)->crear(
        new DatosClienteComercial($d['nombre'], $d['apellido'], $d['telefono'], $d['grupo'], $d['email']));
}

function rt04b1b_buscar(?string $telefono = null, ?string $email = null, ?string $nombre = null): ResultadoBusquedaCliente
{
    return app(BuscarClienteComercialService::class)->buscar(new ConsultaClienteComercial($telefono, $email, $nombre));
}

beforeEach(function () {
    config(['mail.from.address' => 'remitente@example.test', 'mail.from.name' => 'RT04B1b']);
});

// ---------------------------------------------------------------- crear

test('crear sin email: Customer real con email NULL, sin cuenta, grupo explicito y principal sincronizado', function () {
    $grupo = rt04b1b_grupo();
    $cliente = rt04b1b_crear(['telefono' => '300 '.substr($m = rt04b1b_movil(), 3, 3).' '.substr($m, 6), 'grupo' => $grupo->id, 'email' => '   ']);
    $movil = '300'.substr($m, 3);
    $crudo = DB::table('customers')->where('id', $cliente->id)->first();

    expect($crudo->email)->toBeNull()
        ->and($crudo->password)->toBeNull()
        ->and((int) $crudo->is_verified)->toBe(0)
        ->and($crudo->token)->toBeNull()
        ->and((int) $crudo->customer_group_id)->toBe($grupo->id)
        ->and((int) $crudo->channel_id)->toBe(core()->getDefaultChannel()->id)
        ->and($crudo->phone)->toBe('+57'.$movil)
        ->and(TelefonoCliente::query()->where('customer_id', $cliente->id)->sole()->esPrincipal())->toBeTrue()
        ->and(auth()->guard('customer')->attempt(['email' => null, 'password' => '']))->toBeFalse();
});

test('crear con email: se guarda normalizado (sin espacios) y dos clientes sin email conviven', function () {
    $a = rt04b1b_crear(['email' => '  aurora-'.($u = uniqid()).'@example.test ']);
    $b = rt04b1b_crear();
    $c = rt04b1b_crear();

    expect($a->email)->toBe('aurora-'.$u.'@example.test')
        ->and($b->email)->toBeNull()->and($c->email)->toBeNull();
});

test('grupo: NULL, inexistente e invitado se rechazan sin crear nada', function (?callable $grupo, string $codigo) {
    $antes = Customer::query()->count();

    try {
        rt04b1b_crear(['grupo' => $grupo ? $grupo() : null]);
        $this->fail('Debio rechazarse');
    } catch (GrupoComercialInvalidoException $e) {
        expect($e->codigo)->toBe($codigo);
    }

    expect(Customer::query()->count())->toBe($antes);
})->with([
    'null' => [null, GrupoComercialInvalidoException::GRUPO_REQUERIDO],
    'inexistente' => [fn () => 99_999_999, GrupoComercialInvalidoException::GRUPO_INEXISTENTE],
    'invitado' => [fn () => core()->getGuestCustomerGroup()->id, GrupoComercialInvalidoException::GRUPO_INVITADO_NO_PERMITIDO],
]);

test('datos invalidos: nombre vacio, email invalido y telefono ambiguo, con codigos por campo', function () {
    try {
        rt04b1b_crear(['nombre' => ' ', 'email' => 'no-es-email', 'telefono' => '2345678']);
        $this->fail('Debio rechazarse');
    } catch (DatosClienteComercialInvalidosException $e) {
        expect($e->errores)->toBe(['nombre' => 'requerido', 'email' => 'email_invalido', 'telefono' => 'telefono_no_normalizable']);
    }
});

test('telefono ya usado (principal, secundario, otro formato o ficha historica): no crea duplicado', function (string $origen) {
    $movil = rt04b1b_movil();
    $titular = match ($origen) {
        'principal' => rt04b1b_crear(['telefono' => $movil]),
        'secundario' => tap(rt04b1b_crear(), fn ($c) => app(TelefonosClienteService::class)->agregarSecundario($c->id, $movil)),
        'ficha' => Customer::factory()->create(['phone' => $movil]),
    };
    $antes = Customer::query()->count();

    try {
        rt04b1b_crear(['telefono' => '+57 '.$movil]);
        $this->fail('Debio rechazarse');
    } catch (ClienteComercialExistenteException $e) {
        expect($e->customerId)->toBe($titular->id)->and($e->por)->toBe('telefono');
    }

    expect(Customer::query()->count())->toBe($antes);
})->with(['principal', 'secundario', 'ficha']);

test('email ya registrado en el canal: no crea duplicado', function () {
    $existente = rt04b1b_crear(['email' => $email = 'existe-'.uniqid().'@example.test']);

    expect(fn () => rt04b1b_crear(['email' => strtoupper($email)]))
        ->toThrow(fn (ClienteComercialExistenteException $e) => expect($e->customerId)->toBe($existente->id)->and($e->por)->toBe('email'));
});

test('CONFLICTO: telefono del Customer A y email del Customer B → fail closed, nada se crea', function () {
    $a = rt04b1b_crear(['telefono' => $movil = rt04b1b_movil()]);
    $b = rt04b1b_crear(['email' => $email = 'b-'.uniqid().'@example.test']);
    $antes = Customer::query()->count();

    try {
        rt04b1b_crear(['telefono' => $movil, 'email' => $email]);
        $this->fail('Debio rechazarse');
    } catch (ConflictoIdentidadClienteException $e) {
        expect($e->customerIdPorTelefono)->toBe($a->id)->and($e->customerIdPorEmail)->toBe($b->id);
    }

    expect(Customer::query()->count())->toBe($antes);
});

test('rollback: si el telefono falla dentro de la transaccion, no queda Customer huerfano', function () {
    $movil = rt04b1b_movil();
    $antes = Customer::query()->count();
    // Carrera simulada: tras comprobar que el numero esta libre, otro cliente lo toma.
    $otro = Customer::factory()->create(['phone' => null]);
    $antes++;
    Customer::created(function ($c) use ($otro, $movil) {
        if ((int) $c->id !== (int) $otro->id) {
            DB::table('bylopez_va_customer_phones')->insert([
                'customer_id' => $otro->id, 'telefono_normalizado' => '+57'.$movil,
                'telefono_original' => $movil, 'principal' => null,
            ]);
        }
    });

    expect(fn () => rt04b1b_crear(['nombre' => 'Huerfano', 'telefono' => $movil]))->toThrow(Exception::class)
        ->and(Customer::query()->where('first_name', 'Huerfano')->exists())->toBeFalse()
        ->and(Customer::query()->count())->toBe($antes);
});

test('eventos: customer.create.before/after una vez; nunca customer.registration.*; sin correos al cliente ni credenciales', function () {
    Mail::fake();
    Event::fake(['customer.create.before', 'customer.create.after', 'customer.registration.before', 'customer.registration.after']);

    $cliente = rt04b1b_crear(['email' => 'eventos-'.uniqid().'@example.test']);

    Event::assertDispatchedTimes('customer.create.before', 1);
    Event::assertDispatched('customer.create.after', fn ($evento, $payload) => (int) $payload->id === $cliente->id);
    Event::assertDispatchedTimes('customer.create.after', 1);
    Event::assertNotDispatched('customer.registration.before');
    Event::assertNotDispatched('customer.registration.after');
    Mail::assertNotQueued(NewCustomerNotification::class);
    Mail::assertNotQueued(RegistrationNotification::class);
});

test('un fallo de un listener de customer.create.after no deshace el alta confirmada', function () {
    Event::listen('customer.create.after', fn () => throw new RuntimeException('listener roto'));

    $cliente = rt04b1b_crear();

    expect(Customer::query()->whereKey($cliente->id)->exists())->toBeTrue();
});

// ---------------------------------------------------------------- buscar

test('buscar por telefono: principal y secundario encuentran al mismo Customer, en cualquier formato', function () {
    $cliente = rt04b1b_crear(['telefono' => $principal = rt04b1b_movil()]);
    app(TelefonosClienteService::class)->agregarSecundario($cliente->id, $secundario = rt04b1b_movil());

    foreach ([[$principal, TitularTelefono::INDICE_PRINCIPAL], ['+57 '.$principal, TitularTelefono::INDICE_PRINCIPAL],
        ['57'.$secundario, TitularTelefono::INDICE_SECUNDARIO], ['0057 '.$secundario, TitularTelefono::INDICE_SECUNDARIO]] as [$consulta, $via]) {
        $r = rt04b1b_buscar($consulta);

        expect($r->tipo)->toBe(Tipo::TELEFONO_EXACTO)->and($r->cliente->customerId)->toBe($cliente->id)->and($r->cliente->via)->toBe($via);
    }
});

test('buscar por telefono de un cliente historico sin indexar lo encuentra sin escribir nada', function () {
    $historico = Customer::factory()->create(['phone' => $movil = rt04b1b_movil()]);

    $r = rt04b1b_buscar('+57'.$movil);

    expect($r->tipo)->toBe(Tipo::TELEFONO_EXACTO)
        ->and($r->cliente->customerId)->toBe($historico->id)
        ->and($r->cliente->via)->toBe(TitularTelefono::FICHA_SIN_INDEXAR)
        ->and(TelefonoCliente::query()->where('customer_id', $historico->id)->count())->toBe(0);
});

test('buscar por email exacto (sin telefono coincidente)', function () {
    $cliente = rt04b1b_crear(['email' => $email = 'buscar-'.uniqid().'@example.test']);

    $r = rt04b1b_buscar(rt04b1b_movil(), $email);

    expect($r->tipo)->toBe(Tipo::EMAIL_EXACTO)->and($r->cliente->customerId)->toBe($cliente->id);
});

test('buscar por nombre: solo candidatos (nunca identidad), con el grupo cargado en una consulta', function () {
    $sufijo = 'Zq'.uniqid();
    rt04b1b_crear(['nombre' => 'Aurora', 'apellido' => 'Ejemplo '.$sufijo]);
    rt04b1b_crear(['nombre' => 'Aurora', 'apellido' => 'T. '.$sufijo]);

    DB::enableQueryLog();
    $r = rt04b1b_buscar(nombre: 'aurora '.$sufijo);
    $consultas = count(DB::getQueryLog());

    expect($r->tipo)->toBe(Tipo::CANDIDATOS_NOMBRE)
        ->and($r->cliente)->toBeNull()
        ->and($r->candidatos)->toHaveCount(2)
        ->and($r->candidatos[0]->grupoNombre)->toBe('RT04B1b')
        ->and($consultas)->toBeLessThanOrEqual(2);
});

test('buscar: telefono de A y email de B → CONFLICTO con ambos ids', function () {
    $a = rt04b1b_crear(['telefono' => $movil = rt04b1b_movil()]);
    $b = rt04b1b_crear(['email' => $email = 'c-'.uniqid().'@example.test']);

    $r = rt04b1b_buscar($movil, $email);

    expect($r->tipo)->toBe(Tipo::CONFLICTO)
        ->and($r->conflicto)->toBe(ResultadoBusquedaCliente::CONFLICTO_TELEFONO_Y_EMAIL)
        ->and($r->clientesEnConflicto)->toBe([$a->id, $b->id]);
});

test('buscar inexistente, telefono ambiguo y email invalido: sin resultados con avisos', function () {
    $r = rt04b1b_buscar('2345678', 'no-es-email', 'Nadie'.uniqid());

    expect($r->tipo)->toBe(Tipo::SIN_RESULTADOS)
        ->and($r->avisos)->toBe([ResultadoBusquedaCliente::AVISO_TELEFONO_NO_NORMALIZABLE, ResultadoBusquedaCliente::AVISO_EMAIL_INVALIDO]);
});

// ---------------------------------------------------------------- resolver (casos A-F)

/** Ejecuta el resolver registrando cualquier sentencia que no sea SELECT. */
function rt04b1b_resolverSoloLectura(string $telefono, ?string $email = null, ?string $nombre = null): array
{
    $escrituras = [];
    DB::listen(function ($q) use (&$escrituras) {
        if (! preg_match('/^\s*select\b/i', $q->sql)) {
            $escrituras[] = $q->sql;
        }
    });

    $r = app(ResolverClienteComercialService::class)->resolver($telefono, $email, $nombre);

    return [$r, $escrituras];
}

test('A: telefono → A y email → A = EXISTENTE A, sin avisos ni escrituras', function () {
    $a = rt04b1b_crear(['telefono' => $movil = rt04b1b_movil(), 'email' => $email = 'a-'.uniqid().'@example.test']);

    [$r, $escrituras] = rt04b1b_resolverSoloLectura('+57 '.$movil, strtoupper($email));

    expect($r->estado)->toBe(Estado::EXISTENTE)
        ->and($r->cliente->customerId)->toBe($a->id)
        ->and($r->avisos)->toBe([])
        ->and($escrituras)->toBe([]);
});

test('B: telefono → A y email inexistente = EXISTENTE A (aviso de email distinto; no se modifica el email)', function () {
    $a = rt04b1b_crear(['telefono' => $movil = rt04b1b_movil()]);

    [$r, $escrituras] = rt04b1b_resolverSoloLectura($movil, 'nuevo-'.uniqid().'@example.test');

    expect($r->estado)->toBe(Estado::EXISTENTE)
        ->and($r->cliente->customerId)->toBe($a->id)
        ->and($r->avisos)->toBe([ResolucionCliente::AVISO_EMAIL_DISTINTO])
        ->and($escrituras)->toBe([])
        ->and($a->fresh()->email)->toBeNull();
});

test('C: telefono inexistente y email → A = EXISTENTE A; el telefono NO se agrega a A', function () {
    $a = rt04b1b_crear(['telefono' => $principal = rt04b1b_movil(), 'email' => $email = 'c-'.uniqid().'@example.test']);
    $telefonosAntes = TelefonoCliente::query()->where('customer_id', $a->id)->count();

    [$r, $escrituras] = rt04b1b_resolverSoloLectura($nuevo = rt04b1b_movil(), $email);

    expect($r->estado)->toBe(Estado::EXISTENTE)
        ->and($r->cliente->customerId)->toBe($a->id)
        ->and($r->cliente->via)->toBe('email')
        ->and($r->avisos)->toBe([ResolucionCliente::AVISO_TELEFONO_NO_REGISTRADO])
        ->and($escrituras)->toBe([])
        ->and(TelefonoCliente::query()->where('customer_id', $a->id)->count())->toBe($telefonosAntes)
        ->and(TelefonoCliente::query()->where('telefono_normalizado', '+57'.$nuevo)->exists())->toBeFalse()
        ->and($a->fresh()->phone)->toBe('+57'.$principal);
});

test('D: telefono → A y email → B = CONFLICTO con ambos ids, sin escrituras', function () {
    $a = rt04b1b_crear(['telefono' => $movil = rt04b1b_movil()]);
    $b = rt04b1b_crear(['email' => $email = 'd-'.uniqid().'@example.test']);

    [$r, $escrituras] = rt04b1b_resolverSoloLectura($movil, $email);

    expect($r->estado)->toBe(Estado::CONFLICTO)
        ->and($r->conflicto)->toBe(ResolucionCliente::CONFLICTO_TELEFONO_Y_EMAIL)
        ->and($r->clientesEnConflicto)->toBe([$a->id, $b->id])
        ->and($r->cliente)->toBeNull()
        ->and($escrituras)->toBe([]);
});

test('E: telefono inexistente y email inexistente = NUEVO, sin crear nada', function () {
    $antes = Customer::query()->count();

    [$r, $escrituras] = rt04b1b_resolverSoloLectura(rt04b1b_movil(), 'e-'.uniqid().'@example.test');

    expect($r->estado)->toBe(Estado::NUEVO)
        ->and($r->cliente)->toBeNull()
        ->and($escrituras)->toBe([])
        ->and(Customer::query()->count())->toBe($antes);
});

test('F: nombre coincidente = solo candidatos; nunca identidad automatica', function () {
    $sufijo = 'Fx'.uniqid();
    rt04b1b_crear(['nombre' => 'Aurora', 'apellido' => 'Ejemplo '.$sufijo]);

    [$r, $escrituras] = rt04b1b_resolverSoloLectura(rt04b1b_movil(), null, 'Aurora Ejemplo '.$sufijo);

    expect($r->estado)->toBe(Estado::NUEVO)
        ->and($r->cliente)->toBeNull()
        ->and($r->candidatos)->toHaveCount(1)
        ->and($r->avisos)->toBe([ResolucionCliente::AVISO_NOMBRES_PARECIDOS])
        ->and($escrituras)->toBe([]);
});

test('crear sigue sin duplicar en el caso C: telefono nuevo + email de A → cliente existente por email', function () {
    $a = rt04b1b_crear(['email' => $email = 'cc-'.uniqid().'@example.test']);

    expect(fn () => rt04b1b_crear(['email' => $email]))
        ->toThrow(fn (ClienteComercialExistenteException $e) => expect($e->customerId)->toBe($a->id)->and($e->por)->toBe('email'));
});

test('resolver exige un telefono normalizable', function () {
    expect(fn () => app(ResolverClienteComercialService::class)->resolver('2345678'))
        ->toThrow(DatosClienteComercialInvalidosException::class);
});
