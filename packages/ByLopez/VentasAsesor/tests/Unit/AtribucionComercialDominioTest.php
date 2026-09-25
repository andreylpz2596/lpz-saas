<?php

use ByLopez\VentasAsesor\DataObjects\ContextoComercial;
use ByLopez\VentasAsesor\DataObjects\SeleccionPropiedadComercial;
use ByLopez\VentasAsesor\Enums\CanalVenta;
use ByLopez\VentasAsesor\Enums\EstadoAtribucion;
use ByLopez\VentasAsesor\Enums\MecanismoCreacion;
use ByLopez\VentasAsesor\Enums\OrigenPropiedadComercial;
use ByLopez\VentasAsesor\Enums\TipoPropiedadComercial;
use ByLopez\VentasAsesor\Exceptions\AtribucionInvalidaException;
use ByLopez\VentasAsesor\Services\ResolverAtribucionService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor;
use Tests\TestCase;
use Webkul\User\Models\Admin;

require_once __DIR__.'/../helpers.php';

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Dominio de atribucion comercial (Fase 02A)
|--------------------------------------------------------------------------
|
| Administradores y roles se construyen EN MEMORIA: estas pruebas no necesitan
| base de datos y no consumen AUTO_INCREMENT.
|
*/

function va02_resolver(): ResolverAtribucionService
{
    return new ResolverAtribucionService(new AutorizacionVentasAsesor);
}

function va02_asesor(int $id = 7, string $nombre = 'VA02 Asesor'): Admin
{
    return va01_admin(
        [AutorizacionVentasAsesor::PERMISO_MODULO, AutorizacionVentasAsesor::PERMISO_OPERAR_COMO_ASESOR],
        'custom',
        'ASESOR VA02',
        ['id' => $id, 'name' => $nombre],
    );
}

function va02_administradorTotal(int $id = 1, string $nombre = 'VA02 Admin total'): Admin
{
    return va01_admin(null, 'all', 'Administrador', ['id' => $id, 'name' => $nombre]);
}

test('los enums exponen exactamente los valores documentados', function () {
    expect(array_column(TipoPropiedadComercial::cases(), 'value'))
        ->toBe(['advisor', 'company', 'unassigned'])
        ->and(array_column(EstadoAtribucion::cases(), 'value'))
        ->toBe(['completa', 'sin_asesor', 'fallo_tecnico'])
        ->and(array_column(CanalVenta::cases(), 'value'))
        ->toBe(['whatsapp', 'ecommerce', 'telefono', 'tienda_fisica', 'otro', 'desconocido'])
        ->and(array_column(MecanismoCreacion::cases(), 'value'))
        ->toBe(['storefront', 'admin_bagisto', 'ventas_asesor', 'pos', 'api', 'n8n', 'importacion_excel', 'desconocido'])
        ->and(array_column(OrigenPropiedadComercial::cases(), 'value'))
        ->toBe(['auto_asesor', 'seleccion_explicita', 'preferencia_cliente', 'regla_storefront', 'sin_contexto', 'reasignacion']);
});

test('solo la venta de asesor exige asesor y el estado se deriva de la propiedad', function () {
    expect(TipoPropiedadComercial::ASESOR->exigeAsesor())->toBeTrue()
        ->and(TipoPropiedadComercial::EMPRESA->exigeAsesor())->toBeFalse()
        ->and(TipoPropiedadComercial::SIN_ASIGNAR->exigeAsesor())->toBeFalse()
        ->and(EstadoAtribucion::desdePropiedad(TipoPropiedadComercial::ASESOR))->toBe(EstadoAtribucion::COMPLETA)
        ->and(EstadoAtribucion::desdePropiedad(TipoPropiedadComercial::EMPRESA))->toBe(EstadoAtribucion::COMPLETA)
        ->and(EstadoAtribucion::desdePropiedad(TipoPropiedadComercial::SIN_ASIGNAR))->toBe(EstadoAtribucion::SIN_ASESOR)
        ->and(EstadoAtribucion::FALLO_TECNICO->esIncidencia())->toBeTrue();
});

test('el contexto comercial rechaza combinaciones incoherentes', function (
    TipoPropiedadComercial $propiedad,
    ?int $advisorId,
    ?int $creadorId,
    MecanismoCreacion $mecanismo,
) {
    expect(fn () => new ContextoComercial(
        propiedad: $propiedad,
        advisorId: $advisorId,
        createdByAdminId: $creadorId,
        canal: CanalVenta::WHATSAPP,
        mecanismo: $mecanismo,
        origenPropiedad: OrigenPropiedadComercial::SELECCION_EXPLICITA,
    ))->toThrow(AtribucionInvalidaException::class);
})->with([
    'asesor sin advisor_id' => [TipoPropiedadComercial::ASESOR, null, 5, MecanismoCreacion::VENTAS_ASESOR],
    'empresa con asesor' => [TipoPropiedadComercial::EMPRESA, 7, 5, MecanismoCreacion::VENTAS_ASESOR],
    'sin asignar con asesor' => [TipoPropiedadComercial::SIN_ASIGNAR, 7, 5, MecanismoCreacion::VENTAS_ASESOR],
    'storefront con creador administrativo' => [TipoPropiedadComercial::EMPRESA, null, 5, MecanismoCreacion::STOREFRONT],
    'creacion administrativa sin creador' => [TipoPropiedadComercial::EMPRESA, null, null, MecanismoCreacion::ADMIN_BAGISTO],
    'pos sin creador' => [TipoPropiedadComercial::EMPRESA, null, null, MecanismoCreacion::POS],
]);

test('la referencia externa vacia se rechaza', function () {
    expect(fn () => new ContextoComercial(
        propiedad: TipoPropiedadComercial::EMPRESA,
        advisorId: null,
        createdByAdminId: null,
        canal: CanalVenta::ECOMMERCE,
        mecanismo: MecanismoCreacion::STOREFRONT,
        origenPropiedad: OrigenPropiedadComercial::REGLA_STOREFRONT,
        referenciaExterna: '   ',
    ))->toThrow(AtribucionInvalidaException::class);
});

test('regla A: un pedido del storefront pertenece a la empresa, sin asesor ni creador', function () {
    $contexto = va02_resolver()->paraStorefront(cartId: 33);

    expect($contexto->propiedad)->toBe(TipoPropiedadComercial::EMPRESA)
        ->and($contexto->advisorId)->toBeNull()
        ->and($contexto->createdByAdminId)->toBeNull()
        ->and($contexto->canal)->toBe(CanalVenta::ECOMMERCE)
        ->and($contexto->mecanismo)->toBe(MecanismoCreacion::STOREFRONT)
        ->and($contexto->origenPropiedad)->toBe(OrigenPropiedadComercial::REGLA_STOREFRONT)
        ->and($contexto->cartId)->toBe(33)
        ->and($contexto->estado())->toBe(EstadoAtribucion::COMPLETA);
});

test('regla B: un asesor identificado por permiso explicito se asigna a si mismo', function () {
    $contexto = va02_resolver()->paraCreacionAdministrativa(
        va02_asesor(7),
        MecanismoCreacion::VENTAS_ASESOR,
        CanalVenta::WHATSAPP,
    );

    expect($contexto->propiedad)->toBe(TipoPropiedadComercial::ASESOR)
        ->and($contexto->advisorId)->toBe(7)
        ->and($contexto->createdByAdminId)->toBe(7)
        ->and($contexto->origenPropiedad)->toBe(OrigenPropiedadComercial::AUTO_ASESOR)
        ->and($contexto->creadorEsAsesor())->toBeTrue()
        ->and($contexto->estado())->toBe(EstadoAtribucion::COMPLETA);
});

test('regla C: un administrador con acceso total nunca recibe la venta automaticamente', function () {
    $contexto = va02_resolver()->paraCreacionAdministrativa(
        va02_administradorTotal(1),
        MecanismoCreacion::ADMIN_BAGISTO,
        CanalVenta::TIENDA_FISICA,
    );

    expect($contexto->propiedad)->toBe(TipoPropiedadComercial::SIN_ASIGNAR)
        ->and($contexto->advisorId)->toBeNull()
        ->and($contexto->createdByAdminId)->toBe(1)
        ->and($contexto->origenPropiedad)->toBe(OrigenPropiedadComercial::SIN_CONTEXTO)
        ->and($contexto->estado())->toBe(EstadoAtribucion::SIN_ASESOR);
});

test('regla C: el administrador total elige venta de empresa, asignarse o asignar otro asesor', function () {
    $resolver = va02_resolver();
    $admin = va02_administradorTotal(1);

    $empresa = $resolver->paraCreacionAdministrativa(
        $admin, MecanismoCreacion::ADMIN_BAGISTO, CanalVenta::WHATSAPP, SeleccionPropiedadComercial::ventaDeEmpresa()
    );

    $propia = $resolver->paraCreacionAdministrativa(
        $admin, MecanismoCreacion::ADMIN_BAGISTO, CanalVenta::WHATSAPP, SeleccionPropiedadComercial::asignarmeComoAsesor()
    );

    $otro = $resolver->paraCreacionAdministrativa(
        $admin, MecanismoCreacion::ADMIN_BAGISTO, CanalVenta::WHATSAPP, SeleccionPropiedadComercial::asignarAsesor(va02_asesor(9))
    );

    expect($empresa->propiedad)->toBe(TipoPropiedadComercial::EMPRESA)
        ->and($empresa->advisorId)->toBeNull()
        ->and($empresa->createdByAdminId)->toBe(1)
        ->and($empresa->origenPropiedad)->toBe(OrigenPropiedadComercial::SELECCION_EXPLICITA)
        ->and($propia->propiedad)->toBe(TipoPropiedadComercial::ASESOR)
        ->and($propia->advisorId)->toBe(1)
        ->and($propia->creadorEsAsesor())->toBeTrue()
        ->and($otro->advisorId)->toBe(9)
        ->and($otro->createdByAdminId)->toBe(1)
        ->and($otro->creadorEsAsesor())->toBeFalse();
});

test('un rol sin el permiso marcador no recibe la venta ni puede asignarsela', function () {
    $resolver = va02_resolver();
    $bodega = va01_admin(['ventas_asesor', 'ventas_asesor.logistica'], 'custom', 'BODEGA', ['id' => 4]);

    $contexto = $resolver->paraCreacionAdministrativa($bodega, MecanismoCreacion::ADMIN_BAGISTO, CanalVenta::OTRO);

    expect($contexto->propiedad)->toBe(TipoPropiedadComercial::SIN_ASIGNAR)
        ->and($contexto->createdByAdminId)->toBe(4)
        ->and(fn () => $resolver->paraCreacionAdministrativa(
            $bodega, MecanismoCreacion::ADMIN_BAGISTO, CanalVenta::OTRO, SeleccionPropiedadComercial::asignarmeComoAsesor()
        ))->toThrow(AtribucionInvalidaException::class);
});

test('asignar la venta a otro asesor exige el permiso de asignacion y un destino valido', function () {
    $resolver = va02_resolver();
    $asesorSinAsignar = va02_asesor(7);
    $asesorConAsignar = va01_admin(
        [AutorizacionVentasAsesor::PERMISO_OPERAR_COMO_ASESOR, AutorizacionVentasAsesor::PERMISO_PEDIDOS_ASIGNAR],
        'custom',
        'SUPERVISOR',
        ['id' => 8],
    );
    $noAsesor = va01_admin(['ventas_asesor'], 'custom', 'BODEGA', ['id' => 4]);

    expect(fn () => $resolver->paraCreacionAdministrativa(
        $asesorSinAsignar, MecanismoCreacion::VENTAS_ASESOR, CanalVenta::WHATSAPP,
        SeleccionPropiedadComercial::asignarAsesor(va02_asesor(9))
    ))->toThrow(AtribucionInvalidaException::class)
        ->and(fn () => $resolver->paraCreacionAdministrativa(
            $asesorConAsignar, MecanismoCreacion::VENTAS_ASESOR, CanalVenta::WHATSAPP,
            SeleccionPropiedadComercial::asignarAsesor($noAsesor)
        ))->toThrow(AtribucionInvalidaException::class);

    $valido = $resolver->paraCreacionAdministrativa(
        $asesorConAsignar, MecanismoCreacion::VENTAS_ASESOR, CanalVenta::WHATSAPP,
        SeleccionPropiedadComercial::asignarAsesor(va02_asesor(9))
    );

    expect($valido->advisorId)->toBe(9)->and($valido->createdByAdminId)->toBe(8);
});

test('un rol con permission_type desconocido que lista el permiso no es asesor', function () {
    // Brecha real de Admin::hasPermission() del core, cerrada en la Fase 01A.
    $sospechoso = va01_admin(
        [AutorizacionVentasAsesor::PERMISO_OPERAR_COMO_ASESOR],
        'otro',
        'ROL RARO',
        ['id' => 12],
    );

    $contexto = va02_resolver()->paraCreacionAdministrativa(
        $sospechoso, MecanismoCreacion::ADMIN_BAGISTO, CanalVenta::OTRO
    );

    expect($contexto->propiedad)->toBe(TipoPropiedadComercial::SIN_ASIGNAR)
        ->and($contexto->advisorId)->toBeNull();
});

test('esAsesorParaAtribucionAutomatica exige custom mas el permiso explicito', function (mixed $permisos, ?string $tipo, bool $esperado) {
    $autorizacion = new AutorizacionVentasAsesor;

    expect($autorizacion->esAsesorParaAtribucionAutomatica(va01_admin($permisos, $tipo, 'Rol', ['id' => 3])))
        ->toBe($esperado);
})->with([
    'custom con el permiso' => [[AutorizacionVentasAsesor::PERMISO_OPERAR_COMO_ASESOR], 'custom', true],
    'custom sin el permiso' => [['ventas_asesor'], 'custom', false],
    'acceso total' => [null, 'all', false],
    'tipo desconocido con el permiso' => [[AutorizacionVentasAsesor::PERMISO_OPERAR_COMO_ASESOR], 'otro', false],
    'tipo vacio' => [[AutorizacionVentasAsesor::PERMISO_OPERAR_COMO_ASESOR], '', false],
    'tipo nulo' => [[AutorizacionVentasAsesor::PERMISO_OPERAR_COMO_ASESOR], null, false],
]);

test('el administrador con acceso total sigue pudiendo ser elegido asesor', function () {
    $autorizacion = new AutorizacionVentasAsesor;
    $admin = va02_administradorTotal();

    expect($autorizacion->esAsesor($admin))->toBeTrue()
        ->and($autorizacion->esAsesorParaAtribucionAutomatica($admin))->toBeFalse();
});

test('regla D: sin evidencia la venta queda sin asignar y no se infiere nada', function () {
    $contexto = va02_resolver()->paraPedidoSinEvidencia('Pedido historico previo al modulo');

    expect($contexto->propiedad)->toBe(TipoPropiedadComercial::SIN_ASIGNAR)
        ->and($contexto->advisorId)->toBeNull()
        ->and($contexto->createdByAdminId)->toBeNull()
        ->and($contexto->canal)->toBe(CanalVenta::DESCONOCIDO)
        ->and($contexto->mecanismo)->toBe(MecanismoCreacion::DESCONOCIDO)
        ->and($contexto->origenPropiedad)->toBe(OrigenPropiedadComercial::SIN_CONTEXTO)
        ->and($contexto->estado())->toBe(EstadoAtribucion::SIN_ASESOR);
});

test('la creacion administrativa exige administrador autenticado y mecanismo coherente', function () {
    $resolver = va02_resolver();

    expect(fn () => $resolver->paraCreacionAdministrativa(null, MecanismoCreacion::ADMIN_BAGISTO, CanalVenta::OTRO))
        ->toThrow(AtribucionInvalidaException::class)
        ->and(fn () => $resolver->paraCreacionAdministrativa(
            va01_admin(['ventas_asesor'], 'custom', 'Rol'), MecanismoCreacion::ADMIN_BAGISTO, CanalVenta::OTRO
        ))->toThrow(AtribucionInvalidaException::class)
        ->and(fn () => $resolver->paraCreacionAdministrativa(
            va02_asesor(7), MecanismoCreacion::STOREFRONT, CanalVenta::ECOMMERCE
        ))->toThrow(AtribucionInvalidaException::class);
});

test('las automatizaciones reciben asesor explicito verificado o quedan sin asignar', function () {
    $resolver = va02_resolver();

    $sinAsesor = $resolver->paraAutomatizacion(MecanismoCreacion::N8N, CanalVenta::WHATSAPP, referenciaExterna: 'wa-001');
    $conAsesor = $resolver->paraAutomatizacion(MecanismoCreacion::API, CanalVenta::WHATSAPP, va02_asesor(9));

    expect($sinAsesor->propiedad)->toBe(TipoPropiedadComercial::SIN_ASIGNAR)
        ->and($sinAsesor->createdByAdminId)->toBeNull()
        ->and($sinAsesor->referenciaExterna)->toBe('wa-001')
        ->and($conAsesor->advisorId)->toBe(9)
        ->and($conAsesor->createdByAdminId)->toBeNull()
        ->and(fn () => $resolver->paraAutomatizacion(
            MecanismoCreacion::API, CanalVenta::WHATSAPP, va01_admin(['ventas_asesor'], 'custom', 'Rol', ['id' => 4])
        ))->toThrow(AtribucionInvalidaException::class)
        ->and(fn () => $resolver->paraAutomatizacion(MecanismoCreacion::VENTAS_ASESOR, CanalVenta::WHATSAPP))
        ->toThrow(AtribucionInvalidaException::class);
});

test('el contexto conserva separados creador, asesor, canal y mecanismo', function () {
    $contexto = va02_resolver()->paraCreacionAdministrativa(
        va01_admin(
            [AutorizacionVentasAsesor::PERMISO_OPERAR_COMO_ASESOR, AutorizacionVentasAsesor::PERMISO_PEDIDOS_ASIGNAR],
            'custom', 'SUPERVISOR', ['id' => 8],
        ),
        MecanismoCreacion::VENTAS_ASESOR,
        CanalVenta::WHATSAPP,
        SeleccionPropiedadComercial::asignarAsesor(va02_asesor(9)),
        cartId: 41,
        referenciaExterna: 'conv-123',
    );

    $atributos = $contexto->aAtributos();

    expect($atributos['created_by_admin_id'])->toBe(8)
        ->and($atributos['advisor_id'])->toBe(9)
        ->and($atributos['sales_channel'])->toBe(CanalVenta::WHATSAPP)
        ->and($atributos['creation_source'])->toBe(MecanismoCreacion::VENTAS_ASESOR)
        ->and($atributos['commercial_owner_type'])->toBe(TipoPropiedadComercial::ASESOR)
        ->and($atributos['attribution_status'])->toBe(EstadoAtribucion::COMPLETA)
        ->and($atributos['external_reference'])->toBe('conv-123')
        ->and($atributos['cart_id'])->toBe(41);
});
