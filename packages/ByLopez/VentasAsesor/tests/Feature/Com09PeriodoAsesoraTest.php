<?php

use ByLopez\VentasAsesor\Exceptions\AsesorInvalidoException as Rechazo;
use ByLopez\VentasAsesor\Models\Asesor;
use ByLopez\VentasAsesor\Models\ComisionAcuerdo;
use ByLopez\VentasAsesor\Models\ComisionLinea;
use ByLopez\VentasAsesor\Services\Asesores\GuardarAsesorService;
use ByLopez\VentasAsesor\Services\Comisiones\AvanceAsesorService;
use ByLopez\VentasAsesor\Services\Comisiones\CicloComisionService;
use ByLopez\VentasAsesor\Services\Comisiones\SimuladorProximaVentaService;
use ByLopez\VentasAsesor\Services\Rentabilidad\PanelMensualService;
use ByLopez\VentasAsesor\Support\Comisiones\MesComercial;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

require_once __DIR__.'/../helpersCom4.php';

uses(TestCase::class);

afterEach(fn () => Carbon::setTestNow());

/*
| PERÍODO DE COMISIÓN POR ASESORA (2026-11-16). Solo lpz_saas_testing. Cada asesora tiene día de ingreso: su período va del
| día de ingreso de un mes al día anterior del siguiente. La clave (`mes_comercial`) es el mes en que EMPIEZA el período.
| Sin día de ingreso = mes calendario (lo de siempre).
*/

function com9_ahora(string $fechaHora): void
{
    Carbon::setTestNow(CarbonImmutable::parse($fechaHora, 'America/Bogota'));
}

function com9_perfil(int $adminId): Asesor
{
    return Asesor::query()->where('admin_id', $adminId)->firstOrFail();
}

/** @return list<array{dia: int, desde: ?string, origen: string}> */
function com9_ciclos(int $adminId): array
{
    return DB::table('bylopez_va_comision_ciclos')->where('asesor_admin_id', $adminId)->orderBy('id')->get()
        ->map(fn ($f) => ['dia' => (int) $f->dia_inicio, 'desde' => $f->vigente_desde, 'origen' => $f->origen])->all();
}

test('asesora sin comisiones: el día de ingreso rige desde siempre y la ficha muestra su período', function () {
    com9_ahora('2026-11-16 10:00');
    $admin = f03b_admin('all');
    $karen = f03b_admin('custom', ['ventas_asesor.operar_como_asesor']);
    $perfil = com9_perfil($karen->id);

    // Antes de fijar nada: mes calendario.
    expect(MesComercial::calendario($karen->id)->clave(now()))->toBe('2026-11')
        ->and(MesComercial::calendario($karen->id)->periodoEn(now())->esMesCalendario())->toBeTrue();

    $this->actingAs($admin, 'admin')
        ->post(route('admin.ventas_asesor.asesores.update', ['asesor' => $perfil->id]), ['nombre' => 'Karen', 'telefono' => '', 'fecha_ingreso' => '2025-03-14'])
        ->assertRedirect()->assertSessionHas('success');

    expect($perfil->fresh()->fecha_ingreso->toDateString())->toBe('2025-03-14')
        ->and(com9_ciclos($karen->id))->toBe([['dia' => 14, 'desde' => null, 'origen' => CicloComisionService::RETROACTIVO]]);

    $p = MesComercial::calendario($karen->id)->periodoEn(now());
    expect([$p->clave, $p->inicio->toDateString(), $p->hasta()->toDateString()])->toBe(['2026-11', '2026-11-14', '2026-12-13'])
        ->and(MesComercial::calendario($karen->id)->clave(CarbonImmutable::parse('2026-11-13 23:00', 'America/Bogota')))->toBe('2026-10');

    $this->actingAs($admin, 'admin')->get(route('admin.ventas_asesor.asesores.index', ['asesor' => $perfil->id]))->assertOk()
        ->assertSee('data-periodo-comision="2026-11"', false)->assertSee('name="fecha_ingreso"', false);

    // Borrar la fecha vuelve al mes calendario (sin comisiones: también desde siempre).
    app(GuardarAsesorService::class)->actualizar($admin, $perfil->id, ['nombre' => 'Karen', 'fecha_ingreso' => '']);
    expect(com9_ciclos($karen->id))->toBe([])->and($perfil->fresh()->fecha_ingreso)->toBeNull()
        ->and(MesComercial::calendario($karen->id)->periodoEn(now())->esMesCalendario())->toBeTrue();
});

test('fecha de ingreso inválida o futura se rechaza; sin el campo, editar nombre no toca el período', function () {
    com9_ahora('2026-11-16 10:00');
    $admin = f03b_admin('all');
    $zuly = f03b_admin('custom', ['ventas_asesor.operar_como_asesor']);
    $perfil = com9_perfil($zuly->id);
    $guardar = app(GuardarAsesorService::class);

    foreach (['2026-02-30', '14/03/2025', '2026-11-17', '1999-12-31'] as $mala) {
        expect(fn () => $guardar->actualizar($admin, $perfil->id, ['nombre' => 'Zuly', 'fecha_ingreso' => $mala]))
            ->toThrow(fn (Rechazo $e) => $e->codigo === Rechazo::DATOS_INVALIDOS);
    }

    $guardar->actualizar($admin, $perfil->id, ['nombre' => 'Zuly', 'fecha_ingreso' => '2024-05-01']);
    $guardar->actualizar($admin, $perfil->id, ['nombre' => 'Zuly G.']);

    expect($perfil->fresh()->fecha_ingreso->toDateString())->toBe('2024-05-01')
        ->and(com9_ciclos($zuly->id))->toBe([['dia' => 1, 'desde' => null, 'origen' => CicloComisionService::RETROACTIVO]])
        ->and(MesComercial::calendario($zuly->id)->periodoEn(now())->esMesCalendario())->toBeTrue();
});

test('con comisiones ya calculadas: no reescribe períodos vividos, alarga el período en curso y un cambio pendiente se reemplaza', function () {
    com9_ahora('2026-11-20 10:00');
    $admin = f03b_admin('all');
    $wendy = f03b_admin('custom', ['ventas_asesor.operar_como_asesor']);
    $perfil = com9_perfil($wendy->id);
    // Comisiones previas (mes de umbral ya abierto): basta para que el cambio NO sea retroactivo.
    DB::table('bylopez_va_comision_meses')->insert(['asesor_admin_id' => $wendy->id, 'mes' => '2026-10', 'created_at' => now(), 'updated_at' => now()]);
    $guardar = app(GuardarAsesorService::class);

    $guardar->actualizar($admin, $perfil->id, ['nombre' => 'Wendy', 'fecha_ingreso' => '2026-01-14']);

    expect($guardar->resultadoCiclo)->toMatchArray(['modo' => CicloComisionService::TRANSICION, 'dia' => 14, 'desde' => '2026-12-14'])
        ->and(com9_ciclos($wendy->id))->toBe([['dia' => 14, 'desde' => '2026-12-14', 'origen' => CicloComisionService::TRANSICION]]);

    $cal = MesComercial::calendario($wendy->id);
    expect($cal->rango('2026-10')?->hasta()->toDateString())->toBe('2026-10-31')       // vivido: intacto
        ->and($cal->rango('2026-11')?->hasta()->toDateString())->toBe('2026-12-13')     // en curso: alargado
        ->and($cal->clave(CarbonImmutable::parse('2026-12-10', 'America/Bogota')))->toBe('2026-11')
        ->and($cal->clave(CarbonImmutable::parse('2026-12-14', 'America/Bogota')))->toBe('2026-12');

    // Otro cambio antes de que rija: reemplaza al pendiente (no se apilan). Día 18 → primer 18 desde el 1-dic.
    $guardar->actualizar($admin, $perfil->id, ['nombre' => 'Wendy', 'fecha_ingreso' => '2026-01-18']);
    expect(com9_ciclos($wendy->id))->toBe([['dia' => 18, 'desde' => '2026-12-18', 'origen' => CicloComisionService::TRANSICION]]);

    // Volver al día vigente (1) antes de que rija: se cancela el pendiente.
    $guardar->actualizar($admin, $perfil->id, ['nombre' => 'Wendy', 'fecha_ingreso' => '2026-02-01']);
    expect(com9_ciclos($wendy->id))->toBe([])
        ->and(MesComercial::calendario($wendy->id)->rango('2026-11')?->hasta()->toDateString())->toBe('2026-11-30');

    $this->actingAs($admin, 'admin')->post(route('admin.ventas_asesor.asesores.update', ['asesor' => $perfil->id]), ['nombre' => 'Wendy', 'fecha_ingreso' => '2026-01-18'])
        ->assertSessionHas('success', fn ($m) => str_contains($m, '2026-12-18'));
    $this->actingAs($admin, 'admin')->get(route('admin.ventas_asesor.asesores.index', ['asesor' => $perfil->id]))->assertOk()
        ->assertSee('data-periodo-comision="2026-11"', false);
});

test('ventas reales: el umbral se cuenta por el período de la asesora, no por el mes calendario', function () {
    com9_ahora('2026-11-10 09:00');
    $e = com4_escenario('10', 5);
    $karen = (int) $e['asesor']->id;
    // Acuerdo vigente desde antes de cualquier período en juego (el fixture lo crea al inicio del mes calendario).
    ComisionAcuerdo::query()->where('asesor_admin_id', $karen)->update(['vigente_desde' => CarbonImmutable::parse('2026-08-01', 'America/Bogota')]);
    app(CicloComisionService::class)->fijar($karen, '2025-03-14', null);

    com9_ahora('2026-11-13 15:00');
    $antes = com4_vender($e, 4);
    com9_ahora('2026-11-14 10:00');
    $despues = com4_vender($e, 4);

    expect(com4_linea($antes)->mes_comercial)->toBe('2026-10')
        ->and(com4_linea($despues)->mes_comercial)->toBe('2026-11');

    // Con mes calendario serían 8 unidades en noviembre (3 comisionables con umbral 5); por período son 4 y 4: ninguna comisiona.
    $avance = app(AvanceAsesorService::class);
    $oct = $avance->deAsesor($karen, '2026-10');
    $nov = $avance->deAsesor($karen, '2026-11');
    expect([$oct['computables'], $oct['comisionables'], $nov['computables'], $nov['comisionables']])->toBe([4, 0, 4, 0])
        ->and($nov['periodo'])->toMatchArray(['clave' => '2026-11', 'desde' => '2026-11-14', 'hasta' => '2026-12-13', 'mes_calendario' => false]);

    // Simulador y comparativo usan el período EN CURSO de la asesora.
    $sim = app(SimuladorProximaVentaService::class)->simular($e['asesor'], [['sku' => $e['variante']->sku, 'cantidad' => '2', 'precio_neto' => '10000']]);
    expect($sim['mes'])->toBe('2026-11')->and($sim['computables_actuales'])->toBe(4);
    expect($avance->comparativo([$karen])[0]['periodo']['clave'])->toBe('2026-11');

    // Panel de la empresa: por mes CALENDARIO de la confirmación (las dos ventas son de noviembre).
    expect(app(PanelMensualService::class)->mes('2026-11')['pedidos'])->toBeGreaterThanOrEqual(2);

    // Liquidación: la sugerencia es el último período terminado de la asesora; el filtro muestra su período exacto.
    com9_ahora('2026-11-16 10:00');
    $this->withoutVite()->actingAs(f03b_admin('all'), 'admin')
        ->get(route('admin.ventas_asesor.comisiones.index', ['vista' => 'liquidaciones', 'asesor' => $karen]))->assertOk()
        ->assertSee('data-sugerencia-liquidacion="2026-10-14"', false)
        ->assertSee('value="2026-11-13"', false)
        ->assertSee('data-periodo="2026-11"', false);
});

test('asesora sin día de ingreso: líneas y avance siguen en el mes calendario (sin cambios)', function () {
    com9_ahora('2026-11-13 15:00');
    $e = com4_escenario('10', 5);
    $pedido = com4_vender($e, 4);
    com9_ahora('2026-11-14 10:00');
    com4_vender($e, 4);

    $nov = app(AvanceAsesorService::class)->deAsesor((int) $e['asesor']->id, '2026-11');

    expect(com4_linea($pedido)->mes_comercial)->toBe('2026-11')
        ->and([$nov['computables'], $nov['comisionables']])->toBe([8, 3])
        ->and($nov['periodo']['mes_calendario'])->toBeTrue()
        ->and(ComisionLinea::query()->where('asesor_admin_id', $e['asesor']->id)->distinct()->pluck('mes_comercial')->all())->toBe(['2026-11']);
});
