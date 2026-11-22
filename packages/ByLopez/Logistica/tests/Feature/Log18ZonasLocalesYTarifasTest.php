<?php

use ByLopez\Logistica\Contracts\BuscaMunicipios;
use ByLopez\Logistica\Models\Guia;
use ByLopez\Logistica\Models\Tarifa;
use ByLopez\Logistica\Models\Zona;
use ByLopez\Logistica\Services\Tarifas\ZonaLocalDeOrigen;
use ByLopez\Logistica\Services\Tarifas\ZonasLocalesService;
use ByLopez\Logistica\Services\Tarifas\ZonasYTarifasService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

require_once __DIR__.'/../helpersDespacho.php';
require_once __DIR__.'/../helpersDomicilios.php';

uses(TestCase::class);

/*
| Zonas locales y tarifas (pantalla unificada, 2026-11-21): ciudades locales por bodega con buscador del catalogo, tarifas por operador y zona
| con estado vigente/programada/historica, permisos en el servidor y regresion de la regla de «entrega local». Solo lpz_saas_testing.
*/

/** Catalogo de municipios de la prueba (el catalogo DIVIPOLA real se prueba en VentasAsesor). */
function zl_catalogo(): void
{
    $m = [
        '11001' => ['Bogotá, D.C.', 'Bogotá, D.C.'], '25754' => ['Soacha', 'Cundinamarca'], '25175' => ['Chía', 'Cundinamarca'], '25126' => ['Cajicá', 'Cundinamarca'],
    ];

    app()->instance(BuscaMunicipios::class, new class($m) implements BuscaMunicipios
    {
        public function __construct(private array $m) {}

        public function buscar(string $texto, int $limite = 15): array
        {
            $n = Str::lower(Str::ascii($texto));

            return collect($this->m)->filter(fn ($v) => str_contains(Str::lower(Str::ascii($v[0])), $n))
                ->map(fn ($v, $c) => ['municipio_codigo' => (string) $c, 'municipio' => $v[0], 'departamento' => $v[1], 'etiqueta' => $v[0].' — '.$v[1]])->values()->take($limite)->all();
        }

        public function resolver(string $municipioCodigo): ?array
        {
            $v = $this->m[$municipioCodigo] ?? null;

            return $v === null ? null : ['municipio_codigo' => $municipioCodigo, 'municipio' => $v[0], 'departamento' => $v[1], 'etiqueta' => $v[0].' — '.$v[1]];
        }
    });
}

function zl_origen(): int
{
    return app(ZonaLocalDeOrigen::class)->origenPredeterminadoId();
}

function zl_admin_gestion()
{
    return f03b_admin('custom', ['logistica', 'logistica.zonas_locales', 'logistica.transportadoras', 'logistica.transportadoras.manage']);
}

function zl_admin_lectura()
{
    return f03b_admin('custom', ['logistica', 'logistica.zonas_locales']);
}

function zl_otra_bodega(): int
{
    return (int) DB::table('inventory_sources')->insertGetId([
        'code' => 'zl_'.Str::lower(Str::random(6)), 'name' => 'Bodega ZL prueba', 'contact_name' => 'x', 'contact_email' => 'x@x.test', 'contact_number' => '3000000000',
        'country' => 'CO', 'state' => 'MI', 'city' => 'Medellín', 'street' => 'x', 'postcode' => '050001', 'priority' => 9, 'status' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function zl_estado_tarifa(array $estado, int $zonaId, int $tarifaId): ?array
{
    return collect($estado['tarifas'][$zonaId] ?? [])->firstWhere('id', $tarifaId);
}

test('ZL-1 el estado trae las ciudades de cada bodega y las tarifas con su estado (vigente, programada, historica) y fechas de fin solo cuando las hay', function () {
    $c = log6_catalogo();
    zl_catalogo();
    $origen = zl_origen();
    $hoy = now()->toDateString();

    // Soacha / Jeison: 12.000 (ayer, la del catalogo) → 14.000 (hoy) → 16.000 (en 5 dias).
    $vigente = Tarifa::query()->create(['transportadora_id' => $c['jeison']->id, 'zona_id' => $c['soacha']->id, 'valor' => 14000, 'vigente_desde' => $hoy]);
    $futura = Tarifa::query()->create(['transportadora_id' => $c['jeison']->id, 'zona_id' => $c['soacha']->id, 'valor' => 16000, 'vigente_desde' => now()->addDays(5)->toDateString()]);
    $historica = Tarifa::query()->where('transportadora_id', $c['jeison']->id)->where('zona_id', $c['soacha']->id)->where('valor', 12000)->first();

    $estado = app(ZonasLocalesService::class)->estado();
    $bodega = collect($estado['bodegas'])->firstWhere('id', $origen);

    expect(collect($bodega['ciudades'])->pluck('zona_id')->all())->toContain($c['bogota']->id, $c['soacha']->id)
        ->and(collect($bodega['ciudades'])->firstWhere('zona_id', $c['soacha']->id)['departamento'])->toBe('Cundinamarca')
        ->and(zl_estado_tarifa($estado, $c['soacha']->id, $vigente->id))->toMatchArray(['estado' => 'vigente', 'hasta' => now()->addDays(4)->toDateString(), 'valor' => '14000'])
        ->and(zl_estado_tarifa($estado, $c['soacha']->id, $futura->id))->toMatchArray(['estado' => 'programada', 'hasta' => null])
        ->and(zl_estado_tarifa($estado, $c['soacha']->id, $historica->id))->toMatchArray(['estado' => 'historica', 'hasta' => now()->subDay()->toDateString()])
        ->and(collect($estado['operadores'])->pluck('id')->all())->toContain($c['jeison']->id, $c['brian']->id, $c['picap']->id);
});

test('ZL-2 el buscador del catalogo responde sin tildes ni mayusculas, devuelve departamento y pide al menos 2 letras', function () {
    zl_catalogo();
    $admin = zl_admin_lectura();

    $r = $this->actingAs($admin, 'admin')->getJson(route('admin.logistica.zonas_locales.municipios', ['q' => 'CHIA']))->assertOk()->json('resultados');
    expect($r)->toHaveCount(1)->and($r[0])->toMatchArray(['municipio_codigo' => '25175', 'etiqueta' => 'Chía — Cundinamarca']);

    $this->actingAs($admin, 'admin')->getJson(route('admin.logistica.zonas_locales.municipios', ['q' => 'zzzz']))->assertOk()->assertJson(['resultados' => []]);
});

test('ZL-3 agregar una ciudad crea su zona de un solo municipio, la vincula SOLO a esa bodega y rechaza el duplicado y el texto libre', function () {
    log6_catalogo();
    zl_catalogo();
    $origen = zl_origen();
    $otra = zl_otra_bodega();
    $admin = zl_admin_gestion();
    $antesOtra = DB::table('bylopez_lg_origen_zonas')->where('inventory_source_id', $otra)->count();
    $zonas = Zona::query()->count();

    $this->actingAs($admin, 'admin')->postJson(route('admin.logistica.zonas_locales.ciudades.agregar', $origen), ['municipio_codigo' => '25175'])
        ->assertOk()->assertJsonPath('message', trans('logistica::app.zonas_locales.ciudad-agregada'));

    $zona = Zona::query()->where('codigo', 'MUN_25175')->firstOrFail();

    expect($zona->nombre)->toBe('Chía')->and($zona->activo)->toBeTrue()
        ->and(DB::table('bylopez_lg_zona_municipios')->where('zona_id', $zona->id)->pluck('municipio_codigo')->all())->toBe(['25175'])
        ->and(DB::table('bylopez_lg_origen_zonas')->where('inventory_source_id', $origen)->where('zona_id', $zona->id)->exists())->toBeTrue()
        ->and(DB::table('bylopez_lg_origen_zonas')->where('inventory_source_id', $otra)->count())->toBe($antesOtra)
        ->and(Zona::query()->count())->toBe($zonas + 1);

    // Duplicado: mismo municipio para la misma bodega.
    $this->actingAs($admin, 'admin')->postJson(route('admin.logistica.zonas_locales.ciudades.agregar', $origen), ['municipio_codigo' => '25175'])
        ->assertStatus(422)->assertJsonPath('codigo', 'ciudad_ya_habilitada');
    expect(Zona::query()->where('codigo', 'MUN_25175')->count())->toBe(1);

    // Texto libre o codigo inexistente: nada se crea.
    foreach (['Chia', '99999', '', '2517'] as $malo) {
        $this->actingAs($admin, 'admin')->postJson(route('admin.logistica.zonas_locales.ciudades.agregar', $origen), ['municipio_codigo' => $malo])
            ->assertStatus(422)->assertJsonPath('codigo', 'municipio_invalido');
    }

    // Una ciudad habilitada en una bodega puede estarlo (o no) en otra, de forma independiente.
    $this->actingAs($admin, 'admin')->postJson(route('admin.logistica.zonas_locales.ciudades.agregar', $otra), ['municipio_codigo' => '25175'])->assertOk();
    expect(Zona::query()->where('codigo', 'MUN_25175')->count())->toBe(1)
        ->and(DB::table('bylopez_lg_origen_zonas')->where('zona_id', $zona->id)->count())->toBe(2);

    $this->actingAs($admin, 'admin')->postJson(route('admin.logistica.zonas_locales.ciudades.agregar', 999999), ['municipio_codigo' => '25126'])
        ->assertStatus(422)->assertJsonPath('codigo', 'origen_invalido');
});

test('ZL-4 la regla de entrega local sigue siendo origen + destino: la ciudad nueva es local al agregarla y deja de serlo al quitarla, sin borrar zona, tarifas ni guias', function () {
    $c = log6_catalogo();
    zl_catalogo();
    $origen = zl_origen();
    $admin = zl_admin_gestion();
    $locales = app(ZonaLocalDeOrigen::class);

    expect($locales->zonaDeMunicipio($origen, '25126'))->toBeNull();

    $this->actingAs($admin, 'admin')->postJson(route('admin.logistica.zonas_locales.ciudades.agregar', $origen), ['municipio_codigo' => '25126'])->assertOk();
    $zona = $locales->zonaDeMunicipio($origen, '25126');
    expect($zona)->not->toBeNull()->and($zona->nombre)->toBe('Cajicá');

    Tarifa::query()->create(['transportadora_id' => $c['jeison']->id, 'zona_id' => $zona->id, 'valor' => 15000, 'vigente_desde' => now()->toDateString()]);
    $guias = Guia::query()->count();

    $this->actingAs($admin, 'admin')->postJson(route('admin.logistica.zonas_locales.ciudades.quitar', [$origen, $zona->id]))->assertOk();

    expect($locales->zonaDeMunicipio($origen, '25126'))->toBeNull()
        ->and(Zona::query()->find($zona->id))->not->toBeNull()
        ->and(Tarifa::query()->where('zona_id', $zona->id)->count())->toBe(1)
        ->and(Guia::query()->count())->toBe($guias);

    // Quitar algo que no esta habilitado.
    $this->actingAs($admin, 'admin')->postJson(route('admin.logistica.zonas_locales.ciudades.quitar', [$origen, $zona->id]))
        ->assertStatus(422)->assertJsonPath('codigo', 'ciudad_no_habilitada');

    // Volver a habilitarla reutiliza la MISMA zona (y sus tarifas).
    $this->actingAs($admin, 'admin')->postJson(route('admin.logistica.zonas_locales.ciudades.agregar', $origen), ['municipio_codigo' => '25126'])->assertOk();
    expect(Zona::query()->where('codigo', 'MUN_25126')->count())->toBe(1)->and($locales->zonaDeMunicipio($origen, '25126')->id)->toBe($zona->id);
});

test('ZL-5 una zona con varios municipios no se habilita por uno solo', function () {
    log6_catalogo();
    zl_catalogo();
    $zona = Zona::query()->create(['codigo' => 'MULTI'.strtoupper(Str::random(4)), 'nombre' => 'Sabana', 'activo' => true]);
    DB::table('bylopez_lg_zona_municipios')->insert([
        ['zona_id' => $zona->id, 'municipio_codigo' => '25175', 'created_at' => now(), 'updated_at' => now()],
        ['zona_id' => $zona->id, 'municipio_codigo' => '25126', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $this->actingAs(zl_admin_gestion(), 'admin')->postJson(route('admin.logistica.zonas_locales.ciudades.agregar', zl_origen()), ['municipio_codigo' => '25175'])
        ->assertStatus(422)->assertJsonPath('codigo', 'zona_multimunicipio');

    expect(DB::table('bylopez_lg_origen_zonas')->where('zona_id', $zona->id)->exists())->toBeFalse();
});

test('ZL-6 nueva tarifa: valida operador local, destino de la bodega, valor y vigencia; es una fila nueva y la anterior pasa a historica sin cambiar', function () {
    $c = log6_catalogo();
    zl_catalogo();
    $origen = zl_origen();
    $admin = zl_admin_gestion();
    $url = route('admin.logistica.zonas_locales.tarifas.store', $origen);
    $base = ['operador_id' => $c['brian']->id, 'zona_id' => $c['bogota']->id, 'valor' => '13000', 'vigente_desde' => now()->toDateString()];
    $vigenteAntes = Tarifa::query()->where('transportadora_id', $c['brian']->id)->where('zona_id', $c['bogota']->id)->first();
    $tarifasAntes = Tarifa::query()->count();

    // Operador que no hace entregas locales (transportadora nacional) · valor 0/negativo/decimal/texto · fecha pasada/mal formada/lejana · destino ajeno a la bodega.
    $nacional = DB::table('bylopez_lg_transportadoras')->where('tipo', 'transportadora')->where('activo', 1)->value('id');
    $ajena = Zona::query()->create(['codigo' => 'AJENA'.strtoupper(Str::random(4)), 'nombre' => 'Ajena', 'activo' => true]);

    foreach ([
        ['operador_id' => $nacional, 'zona_id' => $c['bogota']->id], ['valor' => '0'], ['valor' => '-5'], ['valor' => '12.5'], ['valor' => 'abc'], ['valor' => '10000000'],
        ['vigente_desde' => now()->subDay()->toDateString()], ['vigente_desde' => '2026-02-31'], ['vigente_desde' => now()->addYears(2)->toDateString()], ['zona_id' => $ajena->id],
    ] as $malo) {
        $this->actingAs($admin, 'admin')->postJson($url, $malo + $base)->assertStatus(422);
    }

    expect(Tarifa::query()->count())->toBe($tarifasAntes);

    // Programada: queda como tal y la vigente sigue siendo la de siempre.
    $futura = now()->addDays(3)->toDateString();
    $this->actingAs($admin, 'admin')->postJson($url, ['vigente_desde' => $futura] + $base)->assertOk()->assertJsonPath('message', trans('logistica::app.zonas_locales.tarifa-creada'));

    $nueva = Tarifa::query()->where('transportadora_id', $c['brian']->id)->where('zona_id', $c['bogota']->id)->whereDate('vigente_desde', $futura)->firstOrFail();
    expect((float) $nueva->valor)->toBe(13000.0)
        ->and(Tarifa::query()->count())->toBe($tarifasAntes + 1)
        ->and((float) $vigenteAntes->fresh()->valor)->toBe(12000.0)
        ->and((float) app(ZonasYTarifasService::class)->tarifaVigente($c['brian']->id, $c['bogota']->id)->valor)->toBe(12000.0);

    // Mismo operador + zona + fecha: rechazado; el doble envio no duplica.
    $this->actingAs($admin, 'admin')->postJson($url, ['vigente_desde' => $futura] + $base)->assertStatus(422)->assertJsonPath('codigo', 'tarifa_duplicada');

    // Transicion: pasado el dia de inicio, la nueva es la vigente y la anterior queda historica con su fin el dia anterior.
    $this->travelTo(now()->addDays(3)->setTime(9, 0));
    $estado = app(ZonasLocalesService::class)->estado();
    expect(zl_estado_tarifa($estado, $c['bogota']->id, $nueva->id)['estado'])->toBe('vigente')
        ->and(zl_estado_tarifa($estado, $c['bogota']->id, $vigenteAntes->id))->toMatchArray(['estado' => 'historica', 'hasta' => now()->subDay()->toDateString()])
        ->and((float) app(ZonasYTarifasService::class)->tarifaVigente($c['brian']->id, $c['bogota']->id)->valor)->toBe(13000.0);
    $this->travelBack();
});

test('ZL-7 las tarifas son inmutables y sirven de sugerencia sin tocar fletes ya registrados', function () {
    $c = log6_catalogo();
    $tarifa = Tarifa::query()->where('transportadora_id', $c['jeison']->id)->where('zona_id', $c['bogota']->id)->firstOrFail();

    expect(fn () => $tarifa->update(['valor' => 1]))->toThrow(ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException::class)
        ->and(fn () => $tarifa->delete())->toThrow(ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException::class);

    // La tarifa sugerida de un despacho nuevo sale del mismo servicio de siempre.
    $nueva = Tarifa::query()->create(['transportadora_id' => $c['jeison']->id, 'zona_id' => $c['bogota']->id, 'valor' => 11000, 'vigente_desde' => now()->toDateString()]);
    expect((float) app(ZonasYTarifasService::class)->tarifaVigente($c['jeison']->id, $c['bogota']->id)->valor)->toBe(11000.0)
        ->and($nueva->id)->toBeGreaterThan($tarifa->id);
});

test('ZL-8 permisos en el servidor: lectura no modifica, sin permiso no entra, y las rutas viejas siguen respondiendo', function () {
    $c = log6_catalogo();
    zl_catalogo();
    $origen = zl_origen();
    $ciudades = DB::table('bylopez_lg_origen_zonas')->count();
    $tarifas = Tarifa::query()->count();
    $lectura = zl_admin_lectura();
    $sin = log2_bodega();

    $this->actingAs($lectura, 'admin')->get(route('admin.logistica.zonas_locales.index'))->assertOk()->assertSee('data-zonas-locales', false);
    $this->actingAs($lectura, 'admin')->getJson(route('admin.logistica.zonas_locales.estado'))->assertOk();

    // Solo lectura: ningun cambio (la ruta esta protegida por ACL y el servicio vuelve a comprobar).
    $this->actingAs($lectura, 'admin')->postJson(route('admin.logistica.zonas_locales.ciudades.agregar', $origen), ['municipio_codigo' => '25175'])->assertStatus(401);
    $this->actingAs($lectura, 'admin')->postJson(route('admin.logistica.zonas_locales.ciudades.quitar', [$origen, $c['bogota']->id]))->assertStatus(401);
    $this->actingAs($lectura, 'admin')->postJson(route('admin.logistica.zonas_locales.tarifas.store', $origen), ['operador_id' => $c['brian']->id, 'zona_id' => $c['bogota']->id, 'valor' => '9000'])->assertStatus(401);

    // Rol de bodega (sin zonas locales): no ve ni el estado ni el buscador.
    $this->actingAs($sin, 'admin')->get(route('admin.logistica.zonas_locales.index'))->assertStatus(401);
    $this->actingAs($sin, 'admin')->getJson(route('admin.logistica.zonas_locales.municipios', ['q' => 'chia']))->assertStatus(401);

    expect(DB::table('bylopez_lg_origen_zonas')->count())->toBe($ciudades)->and(Tarifa::query()->count())->toBe($tarifas);

    // El POST de casillas anterior sigue existiendo (compatibilidad) y exige gestionar.
    $this->actingAs($lectura, 'admin')->post(route('admin.logistica.zonas_locales.guardar', $origen), ['zonas' => []])->assertStatus(401);
    expect(DB::table('bylopez_lg_origen_zonas')->count())->toBe($ciudades);
});

test('ZL-9 Transportadoras ya no administra tarifas: remite a Zonas locales y tarifas (y la ruta anterior de alta sigue funcionando)', function () {
    $c = log6_catalogo();
    $admin = f03b_admin('all');

    $this->actingAs($admin, 'admin')->get(route('admin.logistica.transportadoras.index'))
        ->assertOk()->assertSee('data-tarifas-movidas', false)->assertSee('data-ir-zonas-tarifas', false)
        ->assertDontSee('data-nueva-tarifa', false)->assertDontSee('data-tarifas>', false);

    $this->actingAs($admin, 'admin')->post(route('admin.logistica.tarifas.store'), [
        'transportadora_id' => $c['brian']->id, 'zona_id' => $c['bogota']->id, 'valor' => '13000', 'vigente_desde' => now()->addDay()->toDateString(),
    ])->assertRedirect(route('admin.logistica.transportadoras.index'));
});
