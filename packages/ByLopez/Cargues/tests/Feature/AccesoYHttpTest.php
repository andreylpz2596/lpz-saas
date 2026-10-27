<?php

use ByLopez\Cargues\Models\Carga;
use ByLopez\Cargues\Models\CargaDato;
use ByLopez\Cargues\Models\Comparacion;
use ByLopez\Cargues\Tests\CarguesTestCase;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/../helpers.php';
require_once __DIR__.'/../helpersXlsx.php';

uses(CarguesTestCase::class);

beforeEach(function () {
    cg_limpiar();
    config(['cargues.cola.conexion' => 'sync']);
});

afterEach(fn () => cg_limpiar());

/** Todas las rutas del módulo, con parámetros de relleno. */
function cg_rutas(): array
{
    $r = [];

    foreach (Route::getRoutes()->getRoutes() as $ruta) {
        if (! str_starts_with((string) $ruta->getName(), 'admin.cargues.')) {
            continue;
        }

        $uri = '/'.ltrim(preg_replace(['/\{carga\}/', '/\{tipo\}/', '/\{comparacion\}/'], '1', $ruta->uri()), '/');
        $uri = str_replace('{formato}', 'csv', $uri);
        $r[$ruta->getName()] = [$ruta->methods()[0], $uri];
    }

    return $r;
}

function cg_archivos_guardados(): array
{
    return glob(storage_path('app/private/cargues/*/*/*')) ?: [];
}

test('F1-S1 TODA ruta del módulo da 403 a quien no es el administrador, aunque tenga un rol con permission_type = all o uno personalizado', function () {
    $rutas = cg_rutas();

    expect(count($rutas))->toBeGreaterThanOrEqual(24);   // si se agrega una ruta nueva, entra sola en la prueba

    foreach ([2 => 'rol «todo» que no es el administrador', 3 => 'rol personalizado'] as $rol => $quien) {
        $u = cg_admin($rol);

        foreach ($rutas as $nombre => [$metodo, $uri]) {
            $r = $this->actingAs($u, 'admin')->call($metodo, $uri);

            expect($r->getStatusCode())->toBe(403, "{$quien}: {$metodo} {$nombre}");
        }
    }

    // El administrador no recibe 403 en ninguna (puede ser 200, 404 o 422 según los datos de relleno).
    $admin = cg_admin(1);

    foreach ($rutas as $nombre => [$metodo, $uri]) {
        $codigo = $this->actingAs($admin, 'admin')->call($metodo, $uri)->getStatusCode();

        expect($codigo)->not->toBeIn([401, 403], "administrador: {$nombre} ({$codigo})");
    }
});

test('F1-S1 sin sesión se pide iniciar sesión; un administrador deshabilitado no entra', function () {
    foreach (cg_rutas() as $nombre => [$metodo, $uri]) {
        $codigo = $this->call($metodo, $uri)->getStatusCode();

        expect($codigo)->toBeIn([302, 401], $nombre);
    }

    $admin = cg_admin(1);
    $admin->update(['status' => 0]);

    expect($this->actingAs($admin->fresh(), 'admin')->get(route('admin.cargues.index'))->getStatusCode())->toBeIn([302, 403]);
});

test('F1-S2 el ítem de menú «Cargues» solo se renderiza para el administrador', function () {
    $url = route('admin.cargues.index');

    $conMenu = $this->actingAs(cg_admin(1), 'admin')->get(route('admin.dashboard.index'));
    $conMenu->assertOk();
    expect($conMenu->getContent())->toContain('href="'.$url.'"');

    foreach ([2, 3] as $rol) {
        $this->refreshApplication();   // el menú se arma una vez por proceso: cada rol, una aplicación nueva
        $sin = $this->actingAs(cg_admin($rol), 'admin')->get(route('admin.dashboard.index'));

        expect($sin->getStatusCode())->toBeIn([200, 302, 401, 403]);
        expect($sin->getContent())->not->toContain('/cargues');
    }
});

test('F1-S2 la pantalla «Configurar tipos» y su botón solo existen para el administrador', function () {
    $cfg = cg_config_de_pagina($this->actingAs(cg_admin(1), 'admin')->get(route('admin.cargues.index'))->assertOk()->getContent());

    expect($cfg['rutas']['configurar'])->toBe(route('admin.cargues.tipos.index'));

    $this->actingAs(cg_admin(2), 'admin')->get(route('admin.cargues.index'))->assertForbidden();
    $this->actingAs(cg_admin(2), 'admin')->get(route('admin.cargues.tipos.index'))->assertForbidden();
});

test('F1-S3 ningún parámetro del request puede fijar conexión, tabla, procedimiento, política, tipos ni límites: se rechaza y no cambia nada', function () {
    [$tipo, , $admin] = cg_tipo_activo();
    $this->actingAs($admin, 'admin');
    $archivosAntes = cg_archivos_guardados();
    $json = ['Accept' => 'application/json'];
    $archivo = fn () => cg_csv_filas(cg_filas(2));

    foreach ([
        'conexion' => 'mysql', 'procedimiento' => 'sp_malo', 'tabla' => 'users', 'tabla_destino' => 'users', 'politica_errores' => 'procesar_validas',
        'tipos' => ['x'], 'limites' => ['max_celdas' => 1], 'map' => ['tabla' => 'x'], 'sql' => 'DROP TABLE x',
    ] as $clave => $valor) {
        $r = $this->post(route('admin.cargues.cargas.store'), ['tipo_id' => $tipo->id, 'archivo' => $archivo(), $clave => $valor], $json);

        expect($r->getStatusCode())->toBe(422, $clave)->and($r->json('codigo'))->toBe('PARAMETROS_DESCONOCIDOS')->and($r->json('detalles.parametros'))->toBe([$clave]);
    }

    expect(Carga::count())->toBe(0)->and(cg_archivos_guardados())->toBe($archivosAntes);   // ni carga ni archivo

    // Una acción fuera de la lista del tipo también se rechaza.
    $r = $this->post(route('admin.cargues.cargas.store'), ['tipo_id' => $tipo->id, 'archivo' => $archivo(), 'accion' => 'borrar_todo'], $json);
    expect($r->getStatusCode())->toBe(422)->and($r->json('codigo'))->toBe('ACCION_NO_PERMITIDA')->and(Carga::count())->toBe(0);

    // Con una carga real: lectura y validación tampoco aceptan claves ajenas.
    $r = $this->post(route('admin.cargues.cargas.store'), ['tipo_id' => $tipo->id, 'archivo' => $archivo()], $json);
    $r->assertStatus(201);
    $id = $r->json('carga.id');
    $antes = Carga::find($id)->only(['estado', 'accion', 'relaciones', 'config_congelada']);

    foreach (['conexion', 'procedimiento', 'politica_errores', 'limites', 'tabla', 'map'] as $clave) {
        $r = $this->postJson(route('admin.cargues.cargas.validar', $id), ['relaciones' => ['guia' => 0], $clave => 'x']);
        expect($r->getStatusCode())->toBe(422, "validar+{$clave}")->and($r->json('codigo'))->toBe('PARAMETROS_DESCONOCIDOS');

        $r = $this->postJson(route('admin.cargues.cargas.lectura', $id), ['fila_encabezado' => 1, $clave => 'x']);
        expect($r->getStatusCode())->toBe(422, "lectura+{$clave}");
    }

    expect(Carga::find($id)->only(['estado', 'accion', 'relaciones', 'config_congelada']))->toBe($antes);

    // Ni siquiera el administrador puede configurar un tipo con una clave ajena.
    $r = $this->postJson(route('admin.cargues.tipos.store'), ['nombre' => 'Otro', 'tabla_destino' => 'users']);
    expect($r->getStatusCode())->toBe(422)->and($r->json('codigo'))->toBe('PARAMETROS_DESCONOCIDOS');
});

test('flujo HTTP completo: subir → lectura → validar (cola) → progreso → resumen → descargas', function () {
    [$tipo, , $admin] = cg_tipo_activo();
    $this->actingAs($admin, 'admin');
    $json = ['Accept' => 'application/json'];

    $filas = [CG_ENCABEZADOS, ['G1', '3001', 'ENTREGADO', '1.234,50', '01/10/2026 10:30'], ['G2', '', 'EN RUTA', '', ''], ['G3', '3003', '', '', '']];
    $r = $this->post(route('admin.cargues.cargas.store'), ['tipo_id' => $tipo->id, 'archivo' => cg_csv_filas($filas)], $json)->assertStatus(201);
    $id = $r->json('carga.id');

    expect($r->json('carga.estado'))->toBe('recibida')
        ->and($r->json('carga.encabezados.columnas.0.texto'))->toBe('Número de guía')
        ->and($r->json('carga.encabezados.sugerencias'))->toBe(['guia' => 0, 'telefono' => 1, 'estado' => 2, 'valor' => 3, 'fecha_estado' => 4])
        ->and($r->json('carga.lectura.codificacion'))->toBe('UTF-8')->and($r->json('carga.lectura.delimitador'))->toBe(';');

    // Falta un obligatorio: se rechaza con motivo claro, sin encolar nada.
    $r = $this->postJson(route('admin.cargues.cargas.validar', $id), ['relaciones' => ['telefono' => 1]])->assertStatus(422);
    expect($r->json('codigo'))->toBe('RELACION_FALTA_OBLIGATORIO')->and(Carga::find($id)->estado)->toBe('recibida');

    // Una columna usada dos veces o inexistente también.
    expect($this->postJson(route('admin.cargues.cargas.validar', $id), ['relaciones' => ['guia' => 0, 'estado' => 0]])->json('codigo'))->toBe('RELACION_COLUMNA_REPETIDA');
    expect($this->postJson(route('admin.cargues.cargas.validar', $id), ['relaciones' => ['guia' => 99, 'estado' => 2]])->json('codigo'))->toBe('RELACION_COLUMNA_INVALIDA');
    expect($this->postJson(route('admin.cargues.cargas.validar', $id), ['relaciones' => ['inventado' => 1, 'guia' => 0, 'estado' => 2]])->json('codigo'))->toBe('RELACION_CAMPO_DESCONOCIDO');

    $r = $this->postJson(route('admin.cargues.cargas.validar', $id), [
        'relaciones' => ['guia' => 0, 'telefono' => 1, 'estado' => 2, 'valor' => 3, 'fecha_estado' => 4], 'guardar_comparacion' => true, 'nombre_comparacion' => 'Estándar',
    ])->assertStatus(202);

    $c = Carga::find($id);
    expect($c->estado)->toBe('validada')->and($c->config_congelada['politica_errores'])->toBe('bloquear_todo')->and($c->config_congelada['conexion'])->toBe('cargues_tipo_prueba');

    $p = $this->getJson(route('admin.cargues.cargas.progreso', $id))->json();
    expect($p['estado'])->toBe('validada')->and($p['etapa'])->toBe('finalizado')->and($p['porcentaje'])->toBe(100)->and($p['terminal'])->toBeTrue()->and($p['en_curso'])->toBeFalse();

    $d = $this->getJson(route('admin.cargues.cargas.show', $id))->json();
    expect($d['resumen']['filas_total'])->toBe(3)->and($d['resumen']['filas_rechazadas'])->toBe(2)->and($d['resumen']['filas_validas'])->toBe(1)
        ->and($d['resumen']['rechazadas_motor'])->toBe(1)->and($d['resumen']['rechazadas_procedimiento'])->toBe(1)
        ->and($d['resumen']['puede_ejecutar'])->toBeFalse();

    // Descargas
    $csv = $this->get(route('admin.cargues.cargas.rechazadas', [$id, 'csv']));
    $csv->assertOk();
    expect($csv->headers->get('content-type'))->toContain('text/csv')->and($csv->streamedContent())->toContain('fila_origen;estado_final');
    $this->get(route('admin.cargues.cargas.rechazadas', [$id, 'xlsx']))->assertOk();

    // Mapeos guardados: se listan y muestran sus diferencias antes de aplicarse.
    $lista = $this->getJson(route('admin.cargues.cargas.comparaciones', $id))->json('comparaciones');
    expect($lista)->toHaveCount(1)->and($lista[0]['mismos_encabezados'])->toBeTrue();
    $aplicar = $this->getJson(route('admin.cargues.cargas.comparaciones.aplicar', [$id, $lista[0]['id']]))->json();
    expect($aplicar['relaciones']['guia'])->toBe(['indice' => 0, 'estado' => 'igual']);

    // Historial
    $h = $this->getJson(route('admin.cargues.historial'))->json('historial');
    expect($h[0]['id'])->toBe($id)->and($h[0]['estado'])->toBe('validada');

    // Una carga ya validada puede cancelarse (antes de ejecutar), y ya no admite más procesamiento.
    $this->postJson(route('admin.cargues.cargas.cancelar', $id))->assertOk();
    expect(Carga::find($id)->estado)->toBe('cancelada');
    expect($this->postJson(route('admin.cargues.cargas.validar', $id), ['relaciones' => ['guia' => 0, 'estado' => 2]])->status())->toBe(409);
});

test('una mapeo guardado con encabezados movidos o ausentes muestra las diferencias', function () {
    [$tipo, , $admin] = cg_tipo_activo();
    $this->actingAs($admin, 'admin');
    $a = cg_validar(cg_carga($tipo, $admin, cg_filas(2)), $admin, null, ['guardar_comparacion' => true, 'nombre_comparacion' => 'Base']);

    // Otro archivo: la columna «Teléfono» cambió de lugar y «Valor» ya no existe.
    $f = [['Estado', 'Teléfono', 'Número de guía', 'Fecha del estado'], ['X', '3001', 'G1', '01/10/2026 10:30']];
    $b = cg_carga($tipo, $admin, $f);
    $comp = Comparacion::first();
    $r = $this->getJson(route('admin.cargues.cargas.comparaciones.aplicar', [$b->id, $comp->id]))->json('relaciones');

    expect($r['guia'])->toBe(['indice' => 2, 'estado' => 'movida', 'antes' => 0])
        ->and($r['telefono'])->toBe(['indice' => 1, 'estado' => 'igual'])
        ->and($r['valor']['estado'])->toBe('no_existe')->and($r['valor']['indice'])->toBeNull();
    expect($a->estado)->toBe('validada');
});

// ------------------------------------------------------------------------------------------------------------
// Política de errores (F1-P1, F1-P2): el cálculo y la puerta de "Ejecutar". La ejecución real llega con E2.
// ------------------------------------------------------------------------------------------------------------

function cg_validada(string $politica, array $filas): array
{
    [$tipo, , $admin] = cg_tipo_activo(['politica' => $politica, 'nombre' => 'Pol '.$politica.uniqid()]);
    test()->actingAs($admin, 'admin');
    $carga = cg_validar(cg_carga($tipo, $admin, $filas), $admin);

    return [$carga, $admin];
}

test('F1-P1 «bloquear todo»: con una fila rechazada «Ejecutar» responde 409 aunque se llame directamente al endpoint, y no cambia nada', function () {
    [$carga] = cg_validada('bloquear_todo', [CG_ENCABEZADOS, ['G1', '3001', 'A', '', ''], ['G2', '', 'B', '', '']]);   // la 2.ª la rechaza el procedimiento
    $antes = [Carga::find($carga->id)->only(['estado', 'resultado']), cg_conn()->table(CG_TABLA)->count(), (string) cg_conn()->selectOne('CHECKSUM TABLE cg_negocio_a')->Checksum];

    foreach ([[], ['confirmar_rechazadas' => 1]] as $cuerpo) {
        $r = $this->postJson(route('admin.cargues.cargas.ejecutar', $carga->id), $cuerpo);

        expect($r->status())->toBe(409)->and($r->json('codigo'))->toBe('EJECUCION_BLOQUEADA_POR_POLITICA')->and($r->json('detalles.politica'))->toBe('bloquear_todo');
    }

    expect([Carga::find($carga->id)->only(['estado', 'resultado']), cg_conn()->table(CG_TABLA)->count(), (string) cg_conn()->selectOne('CHECKSUM TABLE cg_negocio_a')->Checksum])->toBe($antes);
});

test('F1-P1 «bloquear todo» sin filas rechazadas: la puerta pasa y se ejecuta; el SP sintético de E1 no implementa EJECUTAR (SIGNAL), así que todo se deshace', function () {
    [$carga] = cg_validada('bloquear_todo', cg_filas(3));
    $negocio = (string) cg_conn()->selectOne('CHECKSUM TABLE cg_negocio_a')->Checksum;

    $r = $this->postJson(route('admin.cargues.cargas.ejecutar', $carga->id));
    $c = Carga::find($carga->id);

    expect($r->status())->toBe(202)->and($c->estado)->toBe('fallida_ejecucion')->and($c->mensaje)->toContain('PROCEDIMIENTO_FALLO')
        ->and((string) cg_conn()->selectOne('CHECKSUM TABLE cg_negocio_a')->Checksum)->toBe($negocio)
        ->and(cg_conn()->table(CG_TABLA)->where('cg_carga_id', $carga->id)->whereNotNull('cg_estado')->count())->toBe(0)   // ni marcas
        ->and(CargaDato::where('carga_id', $carga->id)->where('estado_final', 'pendiente')->count())->toBe(3)
        ->and($c->ejecucion_token)->not->toBeNull();
});

test('F1-P2 «procesar las válidas»: exige confirmar el NÚMERO EXACTO de rechazadas; sin él o con otro número responde 422', function () {
    [$carga] = cg_validada('procesar_validas', [CG_ENCABEZADOS, ['G1', '3001', 'A', '', ''], ['G2', '', 'B', '', ''], ['G3', '3003', '', '', '']]);   // 2 rechazadas

    $d = $this->getJson(route('admin.cargues.cargas.show', $carga->id))->json('resumen');
    expect($d['filas_rechazadas'])->toBe(2)->and($d['puede_ejecutar'])->toBeTrue()->and($d['confirmar_rechazadas'])->toBe(2);

    foreach ([[], ['confirmar_rechazadas' => 1], ['confirmar_rechazadas' => 3], ['confirmar_rechazadas' => 'dos'], ['confirmar_rechazadas' => '']] as $cuerpo) {
        $r = $this->postJson(route('admin.cargues.cargas.ejecutar', $carga->id), $cuerpo);

        expect($r->status())->toBe(422)->and($r->json('codigo'))->toBe('CONFIRMACION_REQUERIDA')->and($r->json('detalles.rechazadas'))->toBe(2);
    }

    // Con una clave ajena se rechaza antes de todo; con el número exacto la puerta pasa y se ejecuta (el SP sintético
    // de E1 no implementa EJECUTAR: se deshace y la carga queda `fallida_ejecucion`, lista para ejecutar de nuevo).
    expect($this->postJson(route('admin.cargues.cargas.ejecutar', $carga->id), ['confirmar_rechazadas' => 2, 'forzar' => true])->json('codigo'))->toBe('PARAMETROS_DESCONOCIDOS');
    expect(Carga::find($carga->id)->estado)->toBe('validada');

    $r = $this->postJson(route('admin.cargues.cargas.ejecutar', $carga->id), ['confirmar_rechazadas' => 2]);
    expect($r->status())->toBe(202)->and(Carga::find($carga->id)->estado)->toBe('fallida_ejecucion');
});

test('«procesar las válidas» sin filas válidas no se puede ejecutar; sin rechazadas no pide confirmación', function () {
    [$carga] = cg_validada('procesar_validas', [CG_ENCABEZADOS, ['G1', '', 'A', '', '']]);
    expect($this->getJson(route('admin.cargues.cargas.show', $carga->id))->json('resumen.puede_ejecutar'))->toBeFalse();
    expect($this->postJson(route('admin.cargues.cargas.ejecutar', $carga->id))->json('codigo'))->toBe('EJECUCION_BLOQUEADA_POR_POLITICA');

    cg_limpiar();
    [$limpia] = cg_validada('procesar_validas', cg_filas(2));
    expect($this->getJson(route('admin.cargues.cargas.show', $limpia->id))->json('resumen.confirmar_rechazadas'))->toBeNull();
});

test('solo se ejecuta una carga validada; las descargas tampoco existen antes de validar', function () {
    [$tipo, , $admin] = cg_tipo_activo();
    $this->actingAs($admin, 'admin');
    $c = cg_carga($tipo, $admin, cg_filas(2));

    expect($this->postJson(route('admin.cargues.cargas.ejecutar', $c->id))->json('codigo'))->toBe('ESTADO_NO_EJECUTABLE')
        ->and($this->getJson(route('admin.cargues.cargas.rechazadas', [$c->id, 'csv']))->status())->toBe(409);
});

// ------------------------------------------------------------------------------------------------------------
// Pantallas (solo comprobaciones de estructura: la revisión visual es manual)
// ------------------------------------------------------------------------------------------------------------

test('las pantallas responden 200 y entregan la configuración que usa la interfaz', function () {
    [$tipo, , $admin] = cg_tipo_activo(['nombre' => 'Tipo visible']);
    $this->actingAs($admin, 'admin');

    $i = $this->get(route('admin.cargues.index'));
    $i->assertOk()->assertSee('v-cargues-app', false);
    $cfg = cg_config_de_pagina($i->getContent());

    expect($cfg['tipos'])->toHaveCount(1)->and($cfg['tipos'][0]['nombre'])->toBe('Tipo visible')->and($cfg['tipos'][0]['campos'])->toHaveCount(5)
        ->and($cfg['rutas']['subir'])->toBe(route('admin.cargues.cargas.store'))
        ->and($cfg['t']['subtitulo'])->toBe('Importa archivos, relaciona columnas y valida tus datos.')
        ->and($cfg['t']['paso_relacionar'])->toBe('Relacionar columnas')
        ->and($cfg['t']['validar_archivo'])->toBe('Validar archivo');

    $this->get(route('admin.cargues.tipos.index'))->assertOk()->assertSee('Tipo visible')->assertSee('Bloquear todo');
    $this->get(route('admin.cargues.tipos.crear'))->assertOk()->assertSee('v-cargues-tipo-form', false);

    $f = $this->get(route('admin.cargues.tipos.editar', $tipo->id))->assertOk();
    $e = cg_config_de_pagina($f->getContent(), 'window.__carguesTipo')['estado'];

    expect($e['nombre'])->toBe('Tipo visible')->and($e['estado'])->toBe('activa')->and($e['politica'])->toBe('bloquear_todo')->and($e['revision_vigente'])->toBeTrue();
});

test('sin tipos activos la configuración entrega una lista vacía (la pantalla ofrece Configurar tipos)', function () {
    $cfg = cg_config_de_pagina($this->actingAs(cg_admin(), 'admin')->get(route('admin.cargues.index'))->assertOk()->getContent());

    expect($cfg['tipos'])->toBe([])->and($cfg['rutas']['configurar'])->not->toBe('');
});

test('una carga que termina se ve en el historial con su estado', function () {
    [$tipo, , $admin] = cg_tipo_activo();
    $c = cg_validar(cg_carga($tipo, $admin, cg_filas(2)), $admin);

    $this->actingAs($admin, 'admin')->get(route('admin.cargues.index'))->assertOk();
    $h = cg_config_de_pagina($this->get(route('admin.cargues.index'))->getContent())['historial'];
    expect($h[0]['id'])->toBe($c->id)->and($h[0]['estado'])->toBe('validada');
    expect(CargaDato::where('carga_id', $c->id)->count())->toBe(2);
});
