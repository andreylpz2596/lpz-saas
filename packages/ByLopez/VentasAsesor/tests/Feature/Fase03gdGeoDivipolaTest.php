<?php

use ByLopez\VentasAsesor\Services\Geo\CatalogoUbicaciones;
use ByLopez\VentasAsesor\Services\Geo\ImportadorDivipolaService;
use ByLopez\VentasAsesor\Services\Venta\BorradorVentaService;
use ByLopez\VentasAsesor\Support\AutorizacionVentasAsesor as Autz;
use ByLopez\VentasAsesor\Support\Geo\NombresGeograficos;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Webkul\User\Models\Admin;

require_once __DIR__.'/../helpersFase03f.php';
require_once __DIR__.'/../helpersGeo.php';

uses(TestCase::class);

/*
| D-58: catalogo local DANE/DIVIPOLA, importador y autocomplete «Departamento — Municipio».
| Los datos son un fixture minimo cargado por el MISMO importador dentro de cada transaccion:
| nada depende de haber importado el catalogo completo en lpz_saas_testing.
*/

function geo_asesor(): Admin
{
    return f03b_admin('custom', [Autz::PERMISO_MODULO, BorradorVentaService::PERMISO]);
}

function geo_buscar(string $q): array
{
    return test()->actingAs(geo_asesor(), 'admin')
        ->getJson(route('admin.ventas_asesor.ubicaciones.buscar', ['q' => $q]))
        ->assertOk()->json();
}

function geo_etiquetas(string $q): array
{
    return array_column(geo_buscar($q), 'etiqueta');
}

/** Limpia el catalogo (dentro de la transaccion) por si alguien importo el real en la base de pruebas. */
function geo_vaciar(): void
{
    DB::table('bylopez_geo_municipios')->delete();
    DB::table('bylopez_geo_departamentos')->delete();
}

beforeEach(function () {
    geo_vaciar();
});

/* ---------------------------------------------------------------- nombres */

test('presentacion y busqueda: DANE en mayusculas → «Boyacá»; busqueda sin tildes ni signos', function () {
    expect(NombresGeograficos::presentacion('BOYACÁ'))->toBe('Boyacá')
        ->and(NombresGeograficos::presentacion('PESCA'))->toBe('Pesca')
        ->and(NombresGeograficos::presentacion('BOGOTÁ, D.C.'))->toBe('Bogotá, D.C.')
        ->and(NombresGeograficos::presentacion('LA GUAJIRA'))->toBe('La Guajira')
        ->and(NombresGeograficos::presentacion('VALLE DEL CAUCA'))->toBe('Valle del Cauca')
        ->and(NombresGeograficos::presentacion('AGUA DE DIOS'))->toBe('Agua de Dios')
        ->and(NombresGeograficos::presentacion('PIENDAMÓ - TUNÍA'))->toBe('Piendamó - Tunía')
        ->and(NombresGeograficos::presentacion('ARCHIPIÉLAGO DE SAN ANDRÉS, PROVIDENCIA Y SANTA CATALINA'))
        ->toBe('Archipiélago de San Andrés, Providencia y Santa Catalina')
        ->and(NombresGeograficos::busqueda('Boyacá'))->toBe('boyaca')
        ->and(NombresGeograficos::busqueda('CHÍA'))->toBe('chia')
        ->and(NombresGeograficos::busqueda('Bogotá, D.C.'))->toBe('bogota d c')
        ->and(NombresGeograficos::busqueda('  Ñandú  '))->toBe('nandu');
});

/* ---------------------------------------------------------------- importador */

test('Q. dry-run: calcula el informe completo y NO escribe nada', function () {
    geo_estados();

    $informe = app(ImportadorDivipolaService::class)->ejecutar(geo_csv(geo_filas()), false, 'test');

    expect($informe['errores'])->toBe([])
        ->and($informe['persistido'])->toBeFalse()
        ->and($informe['filas'])->toBe(9)
        ->and($informe['municipios']['altas'])->toBe(9)
        ->and(count($informe['departamentos']['altas']))->toBe(5)
        ->and(DB::table('bylopez_geo_departamentos')->count())->toBe(0)
        ->and(DB::table('bylopez_geo_municipios')->count())->toBe(0);
});

test('R. persistir: inserta con codigos DIVIPOLA como cadena, equivalencia con country_states y trazabilidad', function () {
    $informe = geo_cargar();

    expect($informe['persistido'])->toBeTrue()
        ->and(DB::table('bylopez_geo_departamentos')->count())->toBe(5)
        ->and(DB::table('bylopez_geo_municipios')->count())->toBe(9);

    // Cero a la izquierda preservado (no se trata como entero).
    $medellin = DB::table('bylopez_geo_municipios')->where('codigo_divipola', '05001')->first();
    expect($medellin->codigo_divipola)->toBe('05001')
        ->and($medellin->departamento_codigo)->toBe('05')
        ->and($medellin->nombre_oficial)->toBe('Medellín');

    $pesca = DB::table('bylopez_geo_municipios')->where('codigo_divipola', '15542')->first();
    expect($pesca->nombre_oficial)->toBe('Pesca')
        ->and($pesca->nombre_busqueda)->toBe('pesca')
        ->and((bool) $pesca->activo)->toBeTrue()
        ->and($pesca->fuente)->toBe(ImportadorDivipolaService::FUENTE)
        ->and($pesca->version_fuente)->toBe('test')
        ->and($pesca->checksum_fuente)->toBe($informe['checksum'])->toHaveLength(64);

    // Equivalencia DIVIPOLA → country_states resuelta en el catalogo (no por nombre en runtime).
    $estadoDe = fn (string $dep) => DB::table('bylopez_geo_departamentos as d')->join('country_states as s', 's.id', '=', 'd.country_state_id')
        ->where('d.codigo_divipola', $dep)->value('s.code');

    expect($estadoDe('15'))->toBe('BOY')
        ->and($estadoDe('25'))->toBe('CUN')
        ->and($estadoDe('68'))->toBe('SAN')
        ->and($estadoDe('05'))->toBe('ANT');
});

test('F. Bogotá, D.C. es entidad propia: estado BOG, nunca Cundinamarca', function () {
    geo_cargar();

    $bogota = DB::table('bylopez_geo_departamentos as d')->join('country_states as s', 's.id', '=', 'd.country_state_id')
        ->where('d.codigo_divipola', '11')->first(['d.nombre_oficial', 's.code', 's.default_name']);

    expect($bogota->nombre_oficial)->toBe('Bogotá, D.C.')
        ->and($bogota->code)->toBe('BOG')
        ->and($bogota->default_name)->toBe('Bogotá, D.C.');

    $u = app(CatalogoUbicaciones::class)->resolver('11001');

    expect($u->stateCode)->toBe('BOG')->and($u->departamento)->toBe('Bogotá, D.C.')->and($u->municipio)->toBe('Bogotá, D.C.')
        ->and(DB::table('bylopez_geo_municipios')->where('departamento_codigo', '25')->where('nombre_busqueda', 'like', 'bogota%')->count())->toBe(0);

    // La migracion de ByLopez dejo el estado en la base de pruebas, sin duplicarlo.
    expect(DB::table('country_states')->where('country_code', 'CO')->where('code', 'BOG')->count())->toBe(1);
});

test('S. segunda importacion del mismo archivo: idempotente, ni un INSERT ni un UPDATE', function () {
    geo_cargar();
    $ruta = geo_csv(geo_filas());

    DB::enableQueryLog();
    $informe = app(ImportadorDivipolaService::class)->ejecutar($ruta, true, 'test');
    $escrituras = collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => preg_match('/^\s*(insert|update|delete)/i', $q))->all();
    DB::disableQueryLog();

    expect($informe['errores'])->toBe([])
        ->and($informe['departamentos']['altas'])->toBe([])
        ->and($informe['departamentos']['cambios'])->toBe([])
        ->and($informe['departamentos']['sin_cambios'])->toBe(5)
        ->and($informe['municipios']['altas'])->toBe(0)
        ->and($informe['municipios']['cambios'])->toBe([])
        ->and($informe['municipios']['reactivados'])->toBe([])
        ->and($informe['municipios']['inactivados'])->toBe([])
        ->and($informe['municipios']['sin_cambios'])->toBe(9)
        ->and($escrituras)->toBe([])
        ->and(DB::table('bylopez_geo_municipios')->count())->toBe(9);
});

test('cambios, ausentes e historicos: se actualiza, se INACTIVA (no se borra) y se reactiva si reaparece', function () {
    geo_cargar();

    $sinChia = array_values(array_filter(geo_filas(), fn ($f) => $f[2] !== '25175'));
    $sinChia = array_map(fn ($f) => $f[2] === '15759' ? ['15', 'BOYACÁ', '15759', 'SOGAMOSO NUEVO', 'Municipio', '0', '0'] : $f, $sinChia);

    $informe = app(ImportadorDivipolaService::class)->ejecutar(geo_csv($sinChia), true, 'test');

    expect($informe['municipios']['cambios'])->toHaveCount(1)
        ->and($informe['municipios']['cambios'][0])->toContain('15759')
        ->and($informe['municipios']['inactivados'])->toHaveCount(1)
        ->and($informe['municipios']['inactivados'][0])->toContain('25175')
        ->and(DB::table('bylopez_geo_municipios')->count())->toBe(9);                       // nada se borra

    $chia = DB::table('bylopez_geo_municipios')->where('codigo_divipola', '25175')->first();
    expect((bool) $chia->activo)->toBeFalse()
        ->and(geo_etiquetas('Chí'))->toBe([])                                               // inactivo: no se ofrece
        ->and(app(CatalogoUbicaciones::class)->resolver('25175'))->toBeNull()               // ni se resuelve
        ->and(geo_etiquetas('Sogamoso'))->toBe(['Boyacá — Sogamoso Nuevo']);

    $informe = app(ImportadorDivipolaService::class)->ejecutar(geo_csv(geo_filas()), true, 'test');

    expect($informe['municipios']['reactivados'])->toHaveCount(1)
        ->and($informe['municipios']['reactivados'][0])->toContain('25175')
        ->and(geo_etiquetas('Chí'))->toBe(['Cundinamarca — Chía'])
        ->and(geo_etiquetas('Sogamoso'))->toBe(['Boyacá — Sogamoso']);
});

test('T. falla cerrado: departamento sin equivalencia, estado inexistente, esquema distinto o datos mal formados → no escribe nada', function () {
    geo_estados();
    $servicio = app(ImportadorDivipolaService::class);
    $vacio = fn () => DB::table('bylopez_geo_departamentos')->count() + DB::table('bylopez_geo_municipios')->count();

    // 1. Departamento DIVIPOLA sin equivalencia en la tabla: se rechaza (no se adivina).
    $conDesconocido = array_merge(geo_filas(), [['77', 'DEPARTAMENTO NUEVO', '77001', 'CIUDAD NUEVA', 'Municipio', '0', '0']]);
    $informe = $servicio->ejecutar(geo_csv($conDesconocido), true, 'test');
    expect($informe['errores'])->toHaveCount(1)->and($informe['errores'][0])->toContain('77')->toContain('sin equivalencia')
        ->and($informe['persistido'])->toBeFalse()->and($vacio())->toBe(0);

    // 2. El estado de Bagisto de un departamento no existe: falla cerrado aunque se pida persistir.
    DB::table('country_states')->where('country_code', 'CO')->where('code', 'BOY')->delete();
    $informe = $servicio->ejecutar(geo_csv(geo_filas()), true, 'test');
    expect($informe['errores'])->toHaveCount(1)->and($informe['errores'][0])->toContain('BOY')->toContain('no existe')
        ->and($informe['persistido'])->toBeFalse()->and($vacio())->toBe(0);

    geo_estados();

    // 3. Esquema distinto (la fuente cambio): se rechaza antes de leer filas.
    $informe = $servicio->ejecutar(geo_csv(geo_filas(), ['Departamento', 'Nombre', 'Municipio', 'Nombre', 'Tipo']), true, 'test');
    expect($informe['errores'][0])->toContain('Encabezado inesperado')->and($vacio())->toBe(0);

    // 4. Municipio que no pertenece a su departamento, codigo con menos digitos, duplicado.
    $malos = array_merge(geo_filas(), [
        ['15', 'BOYACÁ', '25999', 'AJENO', 'Municipio', '0', '0'],
        ['15', 'BOYACÁ', '1599', 'CORTO', 'Municipio', '0', '0'],
        ['15', 'BOYACÁ', '15542', 'PESCA DUPLICADA', 'Municipio', '0', '0'],
    ]);
    $informe = $servicio->ejecutar(geo_csv($malos), true, 'test');
    expect($informe['errores'])->toHaveCount(3)->and($informe['persistido'])->toBeFalse()->and($vacio())->toBe(0);

    // 5. Archivo inexistente.
    expect($servicio->ejecutar('no-existe.csv', true)['errores'][0])->toContain('No se pudo leer');
});

test('comando: dry-run por defecto, --persistir escribe, rechazo devuelve error y opciones excluyentes', function () {
    geo_estados();
    $ruta = geo_csv(geo_filas());

    $this->artisan('ventas-asesor:geo-importar-divipola', ['--archivo' => $ruta])->assertExitCode(0);
    expect(DB::table('bylopez_geo_municipios')->count())->toBe(0);

    $this->artisan('ventas-asesor:geo-importar-divipola', ['--archivo' => $ruta, '--persistir' => true, '--corte' => '2024-12-30'])->assertExitCode(0);
    expect(DB::table('bylopez_geo_municipios')->count())->toBe(9)
        ->and(DB::table('bylopez_geo_municipios')->where('codigo_divipola', '15542')->value('version_fuente'))->toBe('2024-12-30');

    $this->artisan('ventas-asesor:geo-importar-divipola', ['--archivo' => $ruta, '--dry-run' => true, '--persistir' => true])->assertExitCode(2);
    $this->artisan('ventas-asesor:geo-importar-divipola')->assertExitCode(2);
    $this->artisan('ventas-asesor:geo-importar-divipola', ['--archivo' => geo_csv(geo_filas(), ['x', 'y', 'z', 'w', 'v'])])->assertExitCode(1);
});

/* ---------------------------------------------------------------- autocomplete */

test('A–E. escribir «Pes», «Tun», «Buc», «Boy» y «Chí» ofrece «Departamento — Municipio» en ese orden', function () {
    geo_cargar();

    expect(geo_etiquetas('Pes'))->toBe(['Boyacá — Pesca'])
        ->and(geo_etiquetas('Tun'))->toBe(['Boyacá — Tunja'])
        ->and(geo_etiquetas('Buc'))->toBe(['Santander — Bucaramanga'])
        ->and(geo_etiquetas('Chí'))->toBe(['Cundinamarca — Chía']);

    // «Boy»: por departamento → los municipios de Boyacá (y solo ellos), no «Pesca — Boyacá».
    $boy = geo_etiquetas('Boy');

    expect($boy)->toEqualCanonicalizing(['Boyacá — Duitama', 'Boyacá — Pesca', 'Boyacá — Sogamoso', 'Boyacá — Tunja'])
        ->and(collect($boy)->every(fn ($e) => str_starts_with($e, 'Boyacá — ')))->toBeTrue()
        ->and(collect($boy)->contains(fn ($e) => str_ends_with($e, '— Boyacá')))->toBeFalse();
});

test('busqueda: sin tildes ni mayusculas, varias palabras, municipio antes que departamento, Bogotá aparte', function () {
    geo_cargar();

    expect(geo_etiquetas('CHI'))->toBe(['Cundinamarca — Chía'])
        ->and(geo_etiquetas('chia'))->toBe(['Cundinamarca — Chía'])
        ->and(geo_etiquetas('boy pes'))->toBe(['Boyacá — Pesca'])            // departamento + municipio
        ->and(geo_etiquetas('pesca boyaca'))->toBe(['Boyacá — Pesca'])
        ->and(geo_etiquetas('agua de'))->toBe(['Cundinamarca — Agua de Dios'])
        ->and(geo_etiquetas('%%'))->toBe([])                                // comodines de LIKE sin efecto
        ->and(geo_etiquetas('zzz'))->toBe([]);

    // Bogotá: la denominacion oficial, jamas «Cundinamarca — Bogotá».
    expect(geo_etiquetas('Bog'))->toBe(['Bogotá, D.C. — Bogotá, D.C.'])
        ->and(collect(geo_etiquetas('Cun'))->contains('Cundinamarca — Bogotá'))->toBeFalse();

    // «Bo»: Bogotá (coincide por municipio) va antes que los municipios de Boyacá (solo por departamento).
    expect(geo_etiquetas('Bo')[0])->toBe('Bogotá, D.C. — Bogotá, D.C.');
});

test('el endpoint devuelve un maximo de 15 resultados con campos minimos y exige 2 caracteres', function () {
    $filas = geo_filas();

    for ($i = 0; $i < 20; $i++) {
        $filas[] = ['15', 'BOYACÁ', '151'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'LOCALIDAD '.$i, 'Municipio', '0', '0'];
    }

    geo_cargar($filas);

    $r = geo_buscar('Boy');

    expect($r)->toHaveCount(15)
        ->and(array_keys($r[0]))->toBe(['municipio_codigo', 'municipio', 'departamento_codigo', 'departamento', 'etiqueta']);

    $this->actingAs(geo_asesor(), 'admin')->getJson(route('admin.ventas_asesor.ubicaciones.buscar', ['q' => 'P']))->assertStatus(422);
    $this->actingAs(geo_asesor(), 'admin')->getJson(route('admin.ventas_asesor.ubicaciones.buscar'))->assertStatus(422);
});

test('el endpoint exige sesion de administrador y el permiso del modulo (Bouncer real)', function () {
    $this->get(route('admin.ventas_asesor.ubicaciones.buscar', ['q' => 'pes']))
        ->assertRedirect(route('admin.session.create'));

    $this->actingAs(f03b_admin('custom', ['dashboard']), 'admin')
        ->getJson(route('admin.ventas_asesor.ubicaciones.buscar', ['q' => 'pes']))
        ->assertUnauthorized();
});

test('resolver: el codigo es la unica entrada; texto parcial, inexistente e inactivo no se resuelven', function () {
    geo_cargar();
    $catalogo = app(CatalogoUbicaciones::class);

    $u = $catalogo->resolver('15542');

    expect([$u->municipio, $u->departamento, $u->stateCode, $u->pais, $u->municipioCodigo, $u->departamentoCodigo])
        ->toBe(['Pesca', 'Boyacá', 'BOY', 'CO', '15542', '15'])
        ->and($u->etiqueta())->toBe('Boyacá — Pesca')
        ->and($catalogo->resolver('Pes'))->toBeNull()
        ->and($catalogo->resolver('99999'))->toBeNull()
        ->and($catalogo->resolver('1554'))->toBeNull()
        ->and($catalogo->resolver('155420'))->toBeNull()
        ->and($catalogo->resolver('15542; DROP'))->toBeNull();

    DB::table('bylopez_geo_municipios')->where('codigo_divipola', '15542')->update(['activo' => false]);

    expect($catalogo->resolver('15542'))->toBeNull();
});
