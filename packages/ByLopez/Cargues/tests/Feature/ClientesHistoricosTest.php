<?php

use ByLopez\Cargues\Tests\CarguesTestCase;
use ByLopez\VentasAsesor\DataObjects\DatosClienteComercial;
use ByLopez\VentasAsesor\Services\Clientes\AntecedentesEnviosClienteService;
use ByLopez\VentasAsesor\Services\Clientes\CrearClienteComercialService;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../helpers.php';
require_once __DIR__.'/../helpersClientes.php';

uses(CarguesTestCase::class);

const CL_CUENTA = 'cg_validar';

beforeEach(function () {
    cg_limpiar();
    cl_limpiar();
    $c = cg_conn();
    $c->unprepared('DROP USER IF EXISTS `'.CL_CUENTA.'`@`127.0.0.1`');
    $c->unprepared('CREATE USER `'.CL_CUENTA."`@`127.0.0.1` IDENTIFIED BY 'cg_validar_pw'");
    config([
        'cargues.cola.conexion' => 'sync',
        'cargues.conexiones_autorizadas' => ['cargues_tipo_prueba', 'mysql'],
        'cargues.validar.exigir_cuenta_restringida' => true,
        'cargues.validar.conceder_al_activar' => true,
        'cargues.validar.cuentas.mysql' => ['usuario' => CL_CUENTA, 'clave' => 'cg_validar_pw'],
    ]);
    cl_geo();
});

afterEach(function () {
    cg_limpiar();
    cl_limpiar();
    cg_conn()->unprepared('DROP USER IF EXISTS `'.CL_CUENTA.'`@`127.0.0.1`');
});

function cl_cliente_previo(string $nombre, string $apellido, string $telefono): int
{
    $grupo = (int) DB::table('customer_groups')->where('code', 'general')->value('id');

    return (int) app(CrearClienteComercialService::class)->crear(new DatosClienteComercial($nombre, $apellido, $telefono, $grupo))->id;
}

test('el tipo se activa con SP, map y extensión versionados: contrato sin errores, intermedia con derivados y el SP no escribe negocio', function () {
    [, $v, , $revision] = cl_tipo();

    expect($v->estado)->toBe('activa')->and(collect($revision['hallazgos'])->where('nivel', 'error')->all())->toBe([])
        ->and($revision['tablas_escritas'])->toBe([CL_TABLA])                              // el SP solo escribe la intermedia
        ->and(DB::select('SHOW COLUMNS FROM '.CL_TABLA." LIKE 'telefono_e164'"))->not->toBeEmpty()
        ->and(DB::select('SHOW COLUMNS FROM '.CL_TABLA." LIKE 'cliente_estado'"))->not->toBeEmpty();
});

test('VALIDAR clasifica y rechaza con motivo sin escribir clientes; EJECUTAR crea, completa y deja antecedentes; todo auditado por fila', function () {
    [$tipo, , $admin] = cl_tipo();
    cl_cliente_previo('Sofia', 'Castro Lopez', '3001110005');
    cl_cliente_previo('Juan', 'Pardo', '3001110006');
    $antes = cl_conteos();

    $carga = cl_validar($tipo, $admin, [
        ['g' => 'G1', 'n' => 'Ana Maria Lopez Perez', 't' => '3001110001'],                                                // crea + dirección
        ['g' => 'G2', 't' => '3001110001'],                                                                                 // misma persona, otra guía
        ['g' => 'G3', 'n' => 'Pedro Gomez Ruiz', 't' => '3001110002', 'c' => 'ALBANIA/ALBANIA', 'e' => 'Devolución ratificada'], // ciudad ambigua
        ['g' => 'G4', 'n' => 'Luis', 't' => '3001110003'],                                                                  // una sola palabra: SE APLICA (apellido pendiente)
        ['g' => 'G5', 'n' => 'Carlos Ruiz', 't' => '12345'],                                                                // teléfono inválido
        ['g' => 'G6', 'n' => 'Maria Diaz', 't' => '3001110004'],                                                            // teléfono compartido en el archivo
        ['g' => 'G7', 'n' => 'Jose Torres', 't' => '3001110004'],
        ['g' => 'G8', 'n' => 'SOFIA  CASTRO LOPEZ', 't' => '3001110005', 'c' => 'SANTA ROSA/SANTA ROSA DE LIMA'],           // existente: mismo nombre salvo mayúsculas/espacios
        ['g' => 'G9', 'n' => 'Camila Rojas Vega', 't' => '3001110006'],                                                     // existente con otro nombre
        ['g' => 'G10', 'n' => 'Rosa Mejia Cano', 't' => '3001110007', 'e' => 'Estado inventado'],                           // estado sin equivalencia
    ]);

    expect($carga->estado)->toBe('validada')
        ->and(cl_conteos())->toBe($antes)                                                                                  // VALIDAR no escribe clientes, teléfonos, direcciones ni antecedentes
        ->and(cl_resultado($carga))->toMatchArray([
            'G5' => 'rechazada:-/TELEFONO_NO_VALIDO', 'G6' => 'rechazada:-/TELEFONO_COMPARTIDO_EN_ARCHIVO',
            'G7' => 'rechazada:-/TELEFONO_COMPARTIDO_EN_ARCHIVO', 'G9' => 'rechazada:-/CLIENTE_NOMBRE_DISTINTO',
        ])
        ->and($carga->contadores['filas_validas'])->toBe(6)->and($carga->contadores['filas_rechazadas'])->toBe(4);

    $carga = cl_ejecutar($carga, $admin);
    $r = cl_resultado($carga);
    expect(DB::table('customers')->where('phone', 'like', '+57300111%')->whereNotNull('gender')->count())->toBe(0);

    expect($carga->estado)->toBe('completada')
        ->and($r['G1'])->toBe('aplicada:cliente_creado')->and($r['G2'])->toBe('aplicada:cliente_completado')
        ->and($r['G3'])->toBe('aplicada:cliente_creado/UBICACION_AMBIGUA')                                                // se crea el cliente; NO se adivina el departamento
        ->and($r['G8'])->toBe('aplicada:cliente_completado')->and($r['G10'])->toBe('aplicada:cliente_creado')
        ->and($r['G4'])->toBe('aplicada:cliente_creado/APELLIDO_PENDIENTE')->and($r['G9'])->toStartWith('rechazada');

    // Cliente, teléfono, dirección y antecedentes en las tablas correspondientes.
    $ana = DB::table('customers')->where('phone', '+573001110001')->first();
    expect($ana)->not->toBeNull()->and($ana->email)->toBeNull()->and($ana->password)->toBeNull()->and($ana->gender)->toBeNull()                 // sin correo, sin contraseña y SIN género deducido                           // sin correo, sin contraseña, sin cuenta
        ->and(trim($ana->first_name.' '.$ana->last_name))->toBe('Ana Maria Lopez Perez')
        ->and(DB::table('bylopez_va_customer_phones')->where('customer_id', $ana->id)->where('principal', 1)->value('telefono_normalizado'))->toBe('+573001110001');

    $dir = DB::table('addresses')->where('customer_id', $ana->id)->get();
    expect($dir)->toHaveCount(1)->and($dir[0]->city)->toBe('Medellín')->and($dir[0]->state)->toBe('CLT1')->and($dir[0]->country)->toBe('CO')->and((int) $dir[0]->default_address)->toBe(1);

    $pedro = DB::table('customers')->where('phone', '+573001110002')->first();
    expect(DB::table('addresses')->where('customer_id', $pedro->id)->count())->toBe(0);                                    // ambigua: sin dirección

    $sofia = DB::table('customers')->where('phone', '+573001110005')->first();
    expect(DB::table('addresses')->where('customer_id', $sofia->id)->where('city', 'Santa Rosa de Lima')->count())->toBe(1);

    $servicio = app(AntecedentesEnviosClienteService::class);
    $a = $servicio->resumen((int) $ana->id);
    $p = $servicio->resumen((int) $pedro->id);
    $rosa = $servicio->resumen((int) DB::table('customers')->where('phone', '+573001110007')->value('id'));
    expect([$a['entregados'], $a['devoluciones'], $a['total']])->toBe([2, 0, 2])                                          // por ENVÍOS únicos (2 guías), no por filas
        ->and([$p['entregados'], $p['devoluciones']])->toBe([0, 1])
        ->and([$rosa['desconocidos'], $rosa['devoluciones']])->toBe([1, 0]);                                              // estado sin equivalencia: ni entrega ni devolución

    // No generó pedidos, entregas, pagos ni comisiones.
    expect(DB::table('orders')->where('customer_id', $ana->id)->count())->toBe(0);

    // Resultado auditable por fila: los rechazados con su motivo en T7 y observaciones como advertencia.
    $inc = DB::table('bylopez_cg_carga_incidencias')->where('carga_id', $carga->id)->where('etapa', 'ejecucion')->where('codigo', 'UBICACION_AMBIGUA')->first();
    expect($inc->severidad)->toBe('advertencia');
});

test('repetir el MISMO archivo no duplica clientes, teléfonos, direcciones ni antecedentes: todo queda sin cambio', function () {
    [$tipo, , $admin] = cl_tipo();
    $filas = [
        ['g' => 'G1', 'n' => 'Ana Maria Lopez Perez', 't' => '3001110001'],
        ['g' => 'G2', 't' => '3001110001'],
        ['g' => 'G3', 'n' => 'Pedro Gomez Ruiz', 't' => '3001110002', 'c' => 'ALBANIA/ALBANIA', 'e' => 'Devolución ratificada'],
    ];

    cl_ejecutar(cl_validar($tipo, $admin, $filas), $admin);
    $despues = cl_conteos();

    $carga = cl_ejecutar(cl_validar($tipo, $admin, $filas), $admin);

    expect(cl_conteos())->toBe($despues)
        ->and(collect(cl_resultado($carga))->every(fn ($v) => str_starts_with($v, 'sin_cambio:ya_registrado')))->toBeTrue()
        ->and($carga->contadores['filas_sin_cambio'])->toBe(3)->and($carga->contadores['filas_aplicadas'])->toBe(0);
});

test('reglas del antecedente: otro cliente no se queda con la guía, un estado más nuevo actualiza y uno anterior no retrocede; la transportadora se completa', function () {
    $s = app(AntecedentesEnviosClienteService::class);
    $a = cl_cliente_previo('Ana', 'Uno', '3001110011');
    $b = cl_cliente_previo('Bea', 'Dos', '3001110012');
    $g = CL_PREFIJO_GUIA.'R1';

    expect($s->registrar($a, '99envios', '', $g, 'Admitida', '2026-09-01 10:00:00', 1, 2)['resultado'])->toBe('creado')
        ->and($s->registrar($a, '99envios', '', $g, 'Admitida', '2026-09-01 10:00:00', 2, 2)['resultado'])->toBe('sin_cambio')       // mismo reporte: no suma
        ->and($s->registrar($a, '99envios', '', $g, 'Entregada', '2026-09-03 10:00:00', 2, 3)['resultado'])->toBe('actualizado')       // más nuevo
        ->and($s->registrar($a, '99envios', '', $g, 'Admitida', '2026-09-02 10:00:00', 3, 2)['resultado'])->toBe('omitida_antigua')    // no retrocede
        ->and($s->registrar($a, '99envios', '', $g, 'Devolución ratificada', '2026-09-03 10:00:00', 3, 2)['motivo'])->toBe('CONFLICTO_MISMA_FECHA')
        ->and($s->registrar($b, '99envios', '', $g, 'Entregada', null, 3, 2)['motivo'])->toBe('GUIA_DE_OTRO_CLIENTE')                   // no se reasigna
        ->and($s->registrar($a, '99envios', 'INTER_RAPIDISIMO', $g, 'Entregada', '2026-09-03 10:00:00', 4, 2)['motivo'])->toBe('TRANSPORTADORA_COMPLETADA');

    expect(DB::table('bylopez_va_cliente_envios_historicos')->where('numero_guia', $g)->count())->toBe(1)
        ->and(DB::table('bylopez_va_cliente_envios_historicos')->where('numero_guia', $g)->value('transportadora_clave'))->toBe('INTER_RAPIDISIMO')
        ->and($s->resumen($a)['entregados'])->toBe(1);
});

test('al identificar a un cliente existente (Nueva venta) se adjuntan sus antecedentes antes de crear un duplicado; sin antecedentes no se agrega nada', function () {
    $id = cl_cliente_previo('Ana', 'Uno', '3001110021');
    $sin = cl_cliente_previo('Bea', 'Dos', '3001110022');
    $s = app(AntecedentesEnviosClienteService::class);
    $s->registrar($id, '99envios', '', CL_PREFIJO_GUIA.'N1', 'Entregada', '2026-09-01 10:00:00', 1, 2);
    $s->registrar($id, '99envios', '', CL_PREFIJO_GUIA.'N2', 'Devolución ratificada', null, 1, 3);
    $s->registrar($id, '99envios', '', CL_PREFIJO_GUIA.'N3', 'Estado raro', null, 1, 4);

    $buscar = app(\ByLopez\VentasAsesor\Services\Clientes\BuscarClienteComercialService::class);
    $r = $buscar->buscar(new \ByLopez\VentasAsesor\DataObjects\ConsultaClienteComercial(telefono: '3001110021'))->toArray();
    $otro = $buscar->buscar(new \ByLopez\VentasAsesor\DataObjects\ConsultaClienteComercial(telefono: '3001110022'))->toArray();

    expect($r['cliente']['antecedentes'])->toMatchArray(['entregados' => 1, 'devoluciones' => 1, 'desconocidos' => 1, 'total' => 3])
        ->and($otro['cliente']['antecedentes'])->toBeNull();
});

test('P2 · transportadora que falta: completarla después NO duplica el envío, NO lo une a otro cliente y NO sube los conteos', function () {
    $s = app(AntecedentesEnviosClienteService::class);
    $a = cl_cliente_previo('Ana', 'Uno', '3001110031');
    $b = cl_cliente_previo('Bea', 'Dos', '3001110032');
    $g = CL_PREFIJO_GUIA.'T1';

    $s->registrar($a, '99envios', '', $g, 'Entregada', null, 1, 2, 'Ana Uno Original');
    $antes = [$s->resumen($a)['total'], $s->resumen($a)['entregados'], DB::table('bylopez_va_cliente_envios_historicos')->where('numero_guia', $g)->value('reportes')];

    // Otro cliente trae la misma guía CON transportadora: NO se la queda ni la completa (conflicto), y nada cambia.
    expect($s->registrar($b, '99envios', 'INTER_RAPIDISIMO', $g, 'Entregada', null, 2, 2)['motivo'])->toBe('GUIA_DE_OTRO_CLIENTE')
        ->and(DB::table('bylopez_va_cliente_envios_historicos')->where('numero_guia', $g)->pluck('transportadora_clave')->all())->toBe([''])
        ->and($s->resumen($b)['total'])->toBe(0);

    // El mismo cliente la completa: es el MISMO envío (sigue 1 renglón), la clave pasa de '' a la transportadora, reportes no sube.
    $r = $s->registrar($a, '99envios', 'INTER_RAPIDISIMO', $g, 'Entregada', null, 3, 2);
    expect($r['resultado'])->toBe('actualizado')->and($r['motivo'])->toBe('TRANSPORTADORA_COMPLETADA')
        ->and(DB::table('bylopez_va_cliente_envios_historicos')->where('numero_guia', $g)->count())->toBe(1)
        ->and(DB::table('bylopez_va_cliente_envios_historicos')->where('numero_guia', $g)->value('transportadora_clave'))->toBe('INTER_RAPIDISIMO')
        ->and([$s->resumen($a)['total'], $s->resumen($a)['entregados'], DB::table('bylopez_va_cliente_envios_historicos')->where('numero_guia', $g)->value('reportes')])->toBe($antes);

    // Repetir (sin y con transportadora) tampoco cambia nada.
    expect($s->registrar($a, '99envios', '', $g, 'Entregada', null, 4, 2)['resultado'])->toBe('sin_cambio')
        ->and($s->registrar($a, '99envios', 'INTER_RAPIDISIMO', $g, 'Entregada', null, 4, 2)['resultado'])->toBe('sin_cambio')
        ->and($s->resumen($a)['total'])->toBe(1);

    // Otra transportadora para la misma guía es OTRO envío (otra clave), nunca una unión; y con dos registradas, una fila sin transportadora es ambigua.
    $s->registrar($a, '99envios', 'SERVIENTREGA_X', $g, 'Entregada', null, 5, 2);
    expect($s->registrar($a, '99envios', '', $g, 'Entregada', null, 6, 2)['motivo'])->toBe('GUIA_AMBIGUA_TRANSPORTADORA');
});

test('P2 · por el cargue: Cartera sin transportadora y luego un archivo CON transportadora completan el MISMO envío sin duplicar', function () {
    [$tipo, , $admin] = cl_tipo();
    DB::table('bylopez_lg_proveedor_transportadoras')->updateOrInsert(['proveedor' => '99envios', 'codigo_externo' => '1'], ['nombre_externo' => 'interrapidisimo', 'transportadora_codigo' => 'INTER_RAPIDISIMO', 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
    cl_ejecutar(cl_validar($tipo, $admin, [['g' => 'T2']]), $admin);        // sin transportadora en la fila
    $antes = cl_conteos();

    // Mismo envío, ahora con la transportadora (nombre de 99 Envíos) en la columna «transportadora».
    $carga = cl_ejecutar(cl_validar($tipo, $admin, [['g' => 'T2', 'tr' => 'interrapidisimo']]), $admin);

    expect(cl_resultado($carga))->toBe(['T2' => 'aplicada:cliente_completado'])
        ->and(cl_conteos())->toBe($antes)                                                                                   // ni clientes, ni teléfonos, ni direcciones, ni envíos nuevos
        ->and(DB::table('bylopez_va_cliente_envios_historicos')->where('numero_guia', CL_PREFIJO_GUIA.'T2')->pluck('transportadora_clave')->all())->toBe(['INTER_RAPIDISIMO']);
});

test('P3 · el nombre completo ORIGINAL se conserva; la separación es propuesta; teléfono compartido o nombre distinto NO fusiona personas', function () {
    [$tipo, , $admin] = cl_tipo();
    $existente = cl_cliente_previo('Juan', 'Pardo', '3001110041');
    $antes = cl_conteos();

    $carga = cl_ejecutar(cl_validar($tipo, $admin, [
        ['g' => 'N1', 'n' => 'ANA MARIA LOPEZ PEREZ', 't' => '3001110040'],       // crea; separación propuesta 2+2
        ['g' => 'N2', 'n' => 'Camila Rojas', 't' => '3001110041'],                // el teléfono es de otro cliente con otro nombre
        ['g' => 'N3', 'n' => 'Maria Diaz', 't' => '3001110042'],                  // teléfono compartido en el archivo
        ['g' => 'N4', 'n' => 'Jose Torres', 't' => '3001110042'],
    ]), $admin);

    $ana = DB::table('customers')->where('phone', '+573001110040')->first();
    expect(DB::table('bylopez_va_cliente_envios_historicos')->where('numero_guia', CL_PREFIJO_GUIA.'N1')->value('nombre_reportado'))->toBe('ANA MARIA LOPEZ PEREZ')   // original intacto
        ->and([$ana->first_name, $ana->last_name])->toBe(['ANA MARIA', 'LOPEZ PEREZ'])                                                                               // propuesta
        ->and(cl_resultado($carga))->toMatchArray(['N2' => 'rechazada:-/CLIENTE_NOMBRE_DISTINTO', 'N3' => 'rechazada:-/TELEFONO_COMPARTIDO_EN_ARCHIVO', 'N4' => 'rechazada:-/TELEFONO_COMPARTIDO_EN_ARCHIVO'])
        // Nadie se fusionó: el cliente existente sigue igual y no recibió antecedentes; no se creó nadie para N2, N3 ni N4.
        ->and(DB::table('customers')->where('id', $existente)->value('first_name'))->toBe('Juan')
        ->and(app(AntecedentesEnviosClienteService::class)->resumen($existente)['total'])->toBe(0)
        ->and(cl_conteos()['clientes'] - $antes['clientes'])->toBe(1)
        ->and(DB::table('bylopez_va_cliente_envios_historicos')->whereIn('numero_guia', [CL_PREFIJO_GUIA.'N2', CL_PREFIJO_GUIA.'N3', CL_PREFIJO_GUIA.'N4'])->count())->toBe(0);
});

test('P4 · ubicación ambigua: la fila se APLICA (no es rechazada), queda «Dirección pendiente de completar» con el texto original y sale en la descarga de pendientes', function () {
    [$tipo, , $admin] = cl_tipo();
    $this->actingAs($admin, 'admin');
    $carga = cl_ejecutar(cl_validar($tipo, $admin, [
        ['g' => 'D1', 'n' => 'Pedro Gomez Ruiz', 't' => '3001110050', 'd' => 'Carrera 9 # 8-7 Barrio Centro', 'c' => 'ALBANIA/ALBANIA'],
        ['g' => 'D2', 'n' => 'Ana Maria Lopez Perez', 't' => '3001110051', 'd' => 'Calle 1 # 2-3', 'c' => 'MEDELLÍN/MEDELLÍN'],
        ['g' => 'D3', 'n' => 'Carlos Ruiz', 't' => '12345'],        // teléfono inválido: rechazada
    ]), $admin);

    $r = cl_resultado($carga);
    expect($r['D1'])->toBe('aplicada:cliente_creado/UBICACION_AMBIGUA')->and($r['D2'])->toBe('aplicada:cliente_creado')->and($r['D3'])->toStartWith('rechazada')
        ->and($carga->contadores['filas_aplicadas'])->toBe(2)->and($carga->contadores['filas_rechazadas'])->toBe(1)->and($carga->contadores['filas_observadas'])->toBe(1);

    $p = cl_pendientes();
    expect($p)->toHaveCount(1)->and($p[0]['direccion_original'])->toBe('Carrera 9 # 8-7 Barrio Centro')->and($p[0]['ciudad_original'])->toBe('ALBANIA/ALBANIA')->and($p[0]['motivo'])->toBe('UBICACION_AMBIGUA');
    $pedro = (int) DB::table('customers')->where('phone', '+573001110050')->value('id');
    expect(DB::table('addresses')->where('customer_id', $pedro)->count())->toBe(0)
        ->and(app(\ByLopez\VentasAsesor\Services\Clientes\DireccionesPendientesClienteService::class)->listar($pedro))->toHaveCount(1);

    // Descargas: «Pendientes» trae la fila APLICADA con el texto original; «No cargadas» solo la rechazada (no mezcla).
    $pend = $this->get(route('admin.cargues.cargas.pendientes', [$carga->id, 'csv']))->streamedContent();
    $rech = $this->get(route('admin.cargues.cargas.rechazadas', [$carga->id, 'csv']))->streamedContent();
    expect($pend)->toContain('Dirección pendiente de completar')->and($pend)->toContain('Carrera 9 # 8-7 Barrio Centro')->and($pend)->toContain('aplicada')
        ->and($pend)->not->toContain('Calle 1 # 2-3')->and($rech)->not->toContain('Carrera 9 # 8-7');

    // Repetir el archivo: la pendiente no se duplica.
    cl_ejecutar(cl_validar($tipo, $admin, [['g' => 'D1', 'n' => 'Pedro Gomez Ruiz', 't' => '3001110050', 'd' => 'Carrera 9 # 8-7 Barrio Centro', 'c' => 'ALBANIA/ALBANIA']]), $admin);
    expect(cl_pendientes())->toHaveCount(1);
});

test('P5 · la fecha del estado sale de «fecha_actualizacion» (no de la fecha de envío); un reporte sin fecha comprobada no sobrescribe un estado distinto', function () {
    [$tipo, , $admin] = cl_tipo();
    // F1 y F2 SIN fecha del estado (columna vacía); la fecha de ENVÍO sí viene y NO se usa como fecha de estado. F4 con fecha del estado.
    cl_ejecutar(cl_validar($tipo, $admin, [['g' => 'F1', 'e' => 'Admitida', 'fa' => ''], ['g' => 'F2', 'e' => 'Entregada', 'fa' => ''], ['g' => 'F4', 'e' => 'Entregada', 'fa' => '2026-09-22 15:30:00', 'fe' => '2026-09-01 08:00:00']]), $admin);

    expect(DB::table('bylopez_va_cliente_envios_historicos')->where('numero_guia', 'like', CL_PREFIJO_GUIA.'F%')->whereIn('numero_guia', [CL_PREFIJO_GUIA.'F1', CL_PREFIJO_GUIA.'F2'])->whereNotNull('fecha_estado')->count())->toBe(0)
        ->and(DB::table('bylopez_va_cliente_envios_historicos')->where('numero_guia', CL_PREFIJO_GUIA.'F4')->value('fecha_estado'))->toBe('2026-09-22 15:30:00');   // = fecha_actualizacion, no fecha_envio

    // Un archivo posterior, SIN fecha, con otro estado: no hay prueba de que sea más nuevo → no se sobrescribe, queda para revisión.
    $carga = cl_ejecutar(cl_validar($tipo, $admin, [['g' => 'F1', 'e' => 'Entregada', 'fa' => ''], ['g' => 'F2', 'e' => 'Devolución ratificada', 'fa' => '']]), $admin);
    expect(cl_resultado($carga))->toBe(['F1' => 'omitida_antigua:SIN_FECHA_NO_REEMPLAZA', 'F2' => 'omitida_antigua:SIN_FECHA_NO_REEMPLAZA'])
        ->and(DB::table('bylopez_va_cliente_envios_historicos')->where('numero_guia', CL_PREFIJO_GUIA.'F1')->value('estado_proveedor'))->toBe('Admitida')
        ->and(DB::table('bylopez_va_cliente_envios_historicos')->where('numero_guia', CL_PREFIJO_GUIA.'F2')->value('estado_proveedor'))->toBe('Entregada');

    // Servicio: con fecha comprobada SÍ actualiza a uno sin fecha; uno anterior no retrocede; sin fecha no pisa uno fechado.
    $s = app(AntecedentesEnviosClienteService::class);
    $c = cl_cliente_previo('Ana', 'Uno', '3001110060');
    $g = CL_PREFIJO_GUIA.'F3';
    $s->registrar($c, '99envios', '', $g, 'Admitida', null, 1, 2);
    expect($s->registrar($c, '99envios', '', $g, 'Entregada', '2026-09-30 10:00:00', 2, 2)['resultado'])->toBe('actualizado')
        ->and($s->registrar($c, '99envios', '', $g, 'Devolución ratificada', null, 3, 2)['motivo'])->toBe('SIN_FECHA_NO_REEMPLAZA_UN_ESTADO_FECHADO')
        ->and($s->registrar($c, '99envios', '', $g, 'Admitida', '2026-09-01 00:00:00', 4, 2)['resultado'])->toBe('omitida_antigua')
        ->and(DB::table('bylopez_va_cliente_envios_historicos')->where('numero_guia', $g)->value('estado_proveedor'))->toBe('Entregada');
});

test('P7 · el alta NO envía correos ni notificaciones: con el aviso al administrador apagado no se encola nada; con el aviso activo crear se BLOQUEA', function () {
    [$tipo, , $admin] = cl_tipo();
    \Illuminate\Support\Facades\Mail::fake();
    \Illuminate\Support\Facades\Notification::fake();

    cl_aviso_admin(false);
    $carga = cl_ejecutar(cl_validar($tipo, $admin, [['g' => 'M1', 'n' => 'Ana Maria Lopez Perez', 't' => '3001110070']]), $admin);
    expect(cl_resultado($carga))->toBe(['M1' => 'aplicada:cliente_creado']);
    \Illuminate\Support\Facades\Mail::assertNothingSent();
    \Illuminate\Support\Facades\Mail::assertNothingQueued();
    \Illuminate\Support\Facades\Notification::assertNothingSent();

    // Aviso ACTIVO: crear un cliente lo encolaría por cada fila → la operación de crear se bloquea; completar un existente sigue funcionando.
    cl_aviso_admin(true);
    $carga = cl_ejecutar(cl_validar($tipo, $admin, [
        ['g' => 'M2', 'n' => 'Pedro Gomez Ruiz', 't' => '3001110071'],                                  // crear → bloqueado
        ['g' => 'M3', 'n' => 'Ana Maria Lopez Perez', 't' => '3001110070', 'e' => 'Entregada'],         // completar → sigue
    ]), $admin);
    expect(cl_resultado($carga))->toBe(['M2' => 'rechazada:-/CREAR_CLIENTE_BLOQUEADO_NOTIFICACION', 'M3' => 'aplicada:cliente_completado'])
        ->and(DB::table('customers')->where('phone', '+573001110071')->count())->toBe(0);
    \Illuminate\Support\Facades\Mail::assertNothingQueued();
    cl_aviso_admin(false);
});

test('Envíos completos · un destinatario de UNA palabra NO se rechaza: se crea con el apellido «(pendiente)», conserva el original y deja el dato por completar; sin género deducido', function () {
    [$tipo, , $admin] = cl_tipo();
    $carga = cl_ejecutar(cl_validar($tipo, $admin, [['g' => 'U1', 'n' => 'Luis', 't' => '3001110080']]), $admin);

    $c = DB::table('customers')->where('phone', '+573001110080')->first();
    $pend = app(\ByLopez\VentasAsesor\Services\Clientes\DatosPendientesClienteService::class)->listar((int) $c->id);

    expect(cl_resultado($carga))->toBe(['U1' => 'aplicada:cliente_creado/APELLIDO_PENDIENTE'])
        ->and([$c->first_name, $c->last_name])->toBe(['Luis', '(pendiente)'])->and($c->gender)->toBeNull()
        ->and($pend)->toHaveCount(1)->and($pend[0]['campo'])->toBe('apellido')->and($pend[0]['texto_original'])->toBe('Luis')
        ->and(DB::table('bylopez_va_cliente_envios_historicos')->where('numero_guia', CL_PREFIJO_GUIA.'U1')->value('nombre_reportado'))->toBe('Luis');

    // Repetir no duplica la pendiente ni el cliente.
    cl_ejecutar(cl_validar($tipo, $admin, [['g' => 'U1', 'n' => 'Luis', 't' => '3001110080']]), $admin);
    expect(DB::table('bylopez_va_cliente_datos_pendientes')->where('customer_id', $c->id)->count())->toBe(1)->and(DB::table('customers')->where('phone', '+573001110080')->count())->toBe(1);

    // Editable: al completar el apellido desde la ficha, la pendiente deja de mostrarse sola (y el nombre editado ya no coincide con el archivo: eso queda para revisión).
    DB::table('customers')->where('id', $c->id)->update(['last_name' => 'Pardo']);
    expect(app(\ByLopez\VentasAsesor\Services\Clientes\DatosPendientesClienteService::class)->listar((int) $c->id))->toBe([]);


});

test('Envíos completos · transportadora sin equivalencia: la fila se APLICA conservando la transportadora ORIGINAL (clave propia, no «sin determinar») con TRANSPORTADORA_PENDIENTE; sin pedido en nuestro sistema', function () {
    [$tipo, , $admin] = cl_tipo();
    $antesPedidos = DB::table('orders')->count();
    $carga = cl_ejecutar(cl_validar($tipo, $admin, [['g' => 'C1', 'n' => 'Pedro Gomez Ruiz', 't' => '3001110081', 'tr' => '4']]), $admin);    // «4» = coordinadora, sin equivalencia
    $envio = fn () => DB::table('bylopez_va_cliente_envios_historicos')->where('numero_guia', CL_PREFIJO_GUIA.'C1')->first();

    expect(cl_resultado($carga))->toBe(['C1' => 'aplicada:cliente_creado/TRANSPORTADORA_PENDIENTE'])
        ->and($envio()->transportadora_clave)->toBe(AntecedentesEnviosClienteService::clavePendiente('4'))
        ->and($envio()->transportadora_original)->toBe('4')                                                           // el texto original NO se pierde
        ->and(DB::table('orders')->count())->toBe($antesPedidos)                                                    // no se exige ni se crea un pedido para la guía histórica
        ->and(DB::table('bylopez_lg_guias')->where('numero_guia', CL_PREFIJO_GUIA.'C1')->count())->toBe(0);

    // Repetir sin equivalencia: nada cambia.
    $antes = cl_conteos();
    expect(cl_resultado(cl_ejecutar(cl_validar($tipo, $admin, [['g' => 'C1', 'n' => 'Pedro Gomez Ruiz', 't' => '3001110081', 'tr' => '4']]), $admin)))->toBe(['C1' => 'sin_cambio:ya_registrado/TRANSPORTADORA_PENDIENTE'])
        ->and(cl_conteos())->toBe($antes);

    // Al configurar la equivalencia y repetir, el MISMO envío completa su transportadora (1 renglón, mismos conteos) y conserva el original.
    DB::table('bylopez_lg_proveedor_transportadoras')->updateOrInsert(['proveedor' => '99envios', 'codigo_externo' => '4'], ['nombre_externo' => 'coordinadora', 'transportadora_codigo' => 'INTER_RAPIDISIMO', 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
    $carga = cl_ejecutar(cl_validar($tipo, $admin, [['g' => 'C1', 'n' => 'Pedro Gomez Ruiz', 't' => '3001110081', 'tr' => '4']]), $admin);
    expect(cl_resultado($carga))->toBe(['C1' => 'aplicada:cliente_completado'])->and(cl_conteos())->toBe($antes)
        ->and([$envio()->transportadora_clave, $envio()->transportadora_original])->toBe(['INTER_RAPIDISIMO', '4']);
});

test('Transportadoras sin equivalencia DISTINTAS con la misma guía son envíos distintos (no se mezclan bajo «sin determinar»); el mismo original no duplica', function () {
    $s = app(AntecedentesEnviosClienteService::class);
    $a = cl_cliente_previo('Ana', 'Uno', '3001110051');
    $g = CL_PREFIJO_GUIA.'M1';

    $r1 = $s->registrar($a, '99envios', '', $g, 'Entregada', '2026-09-01 10:00:00', 1, 2, null, 'Transp X');
    $r2 = $s->registrar($a, '99envios', '', $g, 'Entregada', '2026-09-01 10:00:00', 1, 3, null, 'Transp Y');
    $r3 = $s->registrar($a, '99envios', '', $g, 'Entregada', '2026-09-01 10:00:00', 2, 2, null, '  transp   x ');     // mismo original (mayúsculas/espacios)

    expect([$r1['resultado'], $r2['resultado'], $r3['resultado']])->toBe(['creado', 'creado', 'sin_cambio'])
        ->and(DB::table('bylopez_va_cliente_envios_historicos')->where('numero_guia', $g)->orderBy('id')->pluck('transportadora_original')->all())->toBe(['Transp X', 'Transp Y'])
        ->and($s->resumen($a)['total'])->toBe(2)
        // Sin transportadora en el archivo y varias registradas: ambigua, nada se elige.
        ->and($s->registrar($a, '99envios', '', $g, 'Entregada', null, 3, 2)['motivo'])->toBe('GUIA_AMBIGUA_TRANSPORTADORA');

    // Llega la efectiva para «Transp X»: completa SOLO ese envío (el de «Transp Y» no se toca).
    $r4 = $s->registrar($a, '99envios', 'INTER_RAPIDISIMO', $g, 'Entregada', '2026-09-01 10:00:00', 4, 2, null, 'Transp X');
    expect($r4['resultado'])->toBe('actualizado')
        ->and(DB::table('bylopez_va_cliente_envios_historicos')->where('numero_guia', $g)->orderBy('id')->pluck('transportadora_clave')->all())->toBe(['INTER_RAPIDISIMO', AntecedentesEnviosClienteService::clavePendiente('Transp Y')])
        ->and($s->resumen($a)['total'])->toBe(2);
});

test('datos de Cartera ya importados: importar Envíos completos los COMPLEMENTA (fecha del estado, transportadora) sin duplicar y sin borrar la procedencia anterior', function () {
    [$tipo, , $admin] = cl_tipo();
    DB::table('bylopez_lg_proveedor_transportadoras')->updateOrInsert(['proveedor' => '99envios', 'codigo_externo' => '1'], ['nombre_externo' => 'interrapidisimo', 'transportadora_codigo' => 'INTER_RAPIDISIMO', 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
    // «Cartera» (antes): sin fecha del estado ni transportadora, nombre completo original en el antecedente.
    cl_ejecutar(cl_validar($tipo, $admin, [['g' => 'X1', 'n' => 'Ana Maria Lopez Perez', 't' => '3001110090', 'fa' => '', 'tr' => '']]), $admin);
    $previo = DB::table('bylopez_va_cliente_envios_historicos')->where('numero_guia', CL_PREFIJO_GUIA.'X1')->first();
    $antes = cl_conteos();

    $carga = cl_ejecutar(cl_validar($tipo, $admin, [['g' => 'X1', 'n' => 'Ana Maria Lopez Perez', 't' => '3001110090', 'fa' => '2026-09-22 15:30:00', 'tr' => '1']]), $admin);
    $nuevo = DB::table('bylopez_va_cliente_envios_historicos')->where('numero_guia', CL_PREFIJO_GUIA.'X1')->first();

    expect(cl_resultado($carga))->toBe(['X1' => 'aplicada:cliente_completado'])->and(cl_conteos())->toBe($antes)                    // ni clientes, ni teléfonos, ni direcciones, ni envíos nuevos
        ->and([$nuevo->transportadora_clave, $nuevo->fecha_estado])->toBe(['INTER_RAPIDISIMO', '2026-09-22 15:30:00'])
        ->and($nuevo->primer_carga_id)->toBe($previo->primer_carga_id)                                                              // la procedencia del primer reporte NO se borra
        ->and($nuevo->nombre_reportado)->toBe($previo->nombre_reportado)->and((int) $nuevo->reportes)->toBe(2);
});

test('Nombres · SOLO se normalizan mayúsculas, tildes y espacios: palabras añadidas, omitidas o reordenadas NO coinciden y quedan para revisión', function () {
    [$tipo, , $admin] = cl_tipo();
    $previo = cl_cliente_previo('Juan', 'Perez', '3001110104');
    cl_cliente_previo('Juan', 'Perez', '3001110102');
    cl_cliente_previo('Juan', 'Perez', '3001110103');
    $antes = cl_conteos();
    $carga = cl_ejecutar(cl_validar($tipo, $admin, [
        ['g' => 'V1', 'n' => 'JUAN   PÉREZ', 't' => '3001110100'],                // crea
        ['g' => 'V2', 'n' => 'juan perez', 't' => '3001110100'],                  // mismo nombre normalizado: UNA persona, otro envío
        ['g' => 'V3', 'n' => 'Juan Perez', 't' => '3001110101'],
        ['g' => 'V4', 'n' => 'Juan Carlos Perez Gomez', 't' => '3001110101'],     // palabras añadidas con el mismo teléfono: NO se une, ambas a revisión
        ['g' => 'V5', 'n' => 'Juan Carlos Perez', 't' => '3001110102'],           // existente «Juan Perez»: palabra añadida → revisión
        ['g' => 'V6', 'n' => 'Perez Juan', 't' => '3001110103'],                  // otro orden: tampoco coincide
        ['g' => 'V7', 'n' => 'JUAN PEREZ', 't' => '3001110104'],                  // idéntico tras normalizar: completa al existente
    ]), $admin);

    $r = cl_resultado($carga);
    expect($r['V1'])->toBe('aplicada:cliente_creado')->and($r['V2'])->toBe('aplicada:cliente_completado')
        ->and($r['V3'])->toBe('rechazada:-/TELEFONO_COMPARTIDO_EN_ARCHIVO')->and($r['V4'])->toBe('rechazada:-/TELEFONO_COMPARTIDO_EN_ARCHIVO')
        ->and($r['V5'])->toBe('rechazada:-/CLIENTE_NOMBRE_DISTINTO')->and($r['V6'])->toBe('rechazada:-/CLIENTE_NOMBRE_DISTINTO')
        ->and($r['V7'])->toBe('aplicada:cliente_completado')
        ->and(DB::table('customers')->where('phone', '+573001110100')->count())->toBe(1)
        ->and(app(AntecedentesEnviosClienteService::class)->resumen((int) DB::table('customers')->where('phone', '+573001110100')->value('id'))['total'])->toBe(2)
        ->and(DB::table('customers')->where('phone', '+573001110101')->count())->toBe(0)                                      // nadie creado con el teléfono compartido
        ->and(app(AntecedentesEnviosClienteService::class)->resumen($previo)['total'])->toBe(1)                                // solo V7 llegó al existente
        ->and(DB::table('bylopez_va_cliente_envios_historicos')->whereIn('numero_guia', [CL_PREFIJO_GUIA.'V3', CL_PREFIJO_GUIA.'V4', CL_PREFIJO_GUIA.'V5', CL_PREFIJO_GUIA.'V6'])->count())->toBe(0)
        ->and(cl_conteos()['clientes'] - $antes['clientes'])->toBe(1);
});
