<?php

/*
| Prepara en la base DEMO el tipo «99 Envíos — Envíos completos» (reutiliza el módulo Cargues tal cual) y la equivalencia ficticia que
| el archivo de la demo necesita. Idempotente. Solo corre en lpz_saas_demo_*.
|
|   bash packages/ByLopez/VentasAsesor/tests/Demo/demo.sh php packages/ByLopez/VentasAsesor/tests/Demo/preparar_cargues.php
|
| Qué hace (todo por los servicios del módulo; nada se fuerza por SQL salvo la equivalencia de configuración):
|  1. Instala el procedimiento VERSIONADO sp_cg_envios_completos_v3 en la base demo (el motor no lo instala).
|  2. Crea el tipo con el map versionado, política «procesar las válidas» (decisión ENV-A2), confirma el contrato y lo ACTIVA.
|     Al activar, el módulo concede a la cuenta restringida de VALIDAR (cargues_validar) los permisos mínimos SOLO sobre esta base.
|  3. Equivalencia de configuración: código de proveedor «2» → Servientrega (03) para el escenario D1 de la demo. Es un dato de
|     configuración ficticio de la demo, no un estado financiero ni logístico.
| Para el worker: demo.sh worker (cola dedicada `cargues`, driver database, misma base).
*/

use ByLopez\Cargues\Models\Tipo;
use ByLopez\Cargues\Models\TipoVersion;
use ByLopez\Cargues\Services\Configuracion\ActivarTipoService;
use ByLopez\Cargues\Services\Configuracion\TiposService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Webkul\User\Models\Admin;

$raiz = dirname(__DIR__, 5);
require $raiz.'/vendor/autoload.php';
$app = require $raiz.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$base = (string) DB::selectOne('SELECT DATABASE() AS b')->b;

if (! str_starts_with($base, 'lpz_saas_demo_')) {
    fwrite(STDERR, "Bloqueado: solo corre en una base lpz_saas_demo_* (conectada: {$base}).\n");
    exit(2);
}

const PROC = 'sp_cg_envios_completos_v3';
$dir = $raiz.'/packages/ByLopez/Cargues/src/Database/Procedures/';

// 1) Procedimiento versionado (sin DELIMITER), igual que lo instalan las pruebas del modulo.
$sql = file_get_contents($dir.PROC.'.sql');
$sql = str_replace('END$$', 'END', preg_replace('/^DELIMITER.*$/m', '', $sql));
$cuerpo = trim(preg_split('/^DROP PROCEDURE IF EXISTS '.PROC.';/m', $sql)[1]);
DB::unprepared('DROP PROCEDURE IF EXISTS '.PROC);
DB::unprepared($cuerpo);
echo 'procedimiento '.PROC." instalado en {$base}\n";

// 2) Tipo
$admin = Admin::query()->where('email', 'demo.admin@bylopez.test')->firstOrFail();
$tipos = app(TiposService::class);
$tipo = Tipo::query()->whereIn('id', DB::table('bylopez_cg_tipo_versiones')->where('procedimiento', PROC)->pluck('tipo_id'))->first();

if (! $tipo) {
    $map = json_decode(file_get_contents($dir.PROC.'.map.json'), true);
    $tipo = $tipos->crear($admin, [
        'nombre' => '99 Envíos — Envíos completos v3 (DEMO)', 'descripcion' => 'Tipo v3 de la demo aislada: el reporte registra entrega/validación y retorno (hitos), sin dinero.',
        'conexion' => 'mysql', 'procedimiento' => PROC, 'accion' => 'actualizar_estado',
        'politica_errores' => 'procesar_validas', 'limites' => null, 'map' => $map,
    ]);
    $v = $tipo->versiones()->first();
    $tipos->confirmarContrato($admin, $v);
    $v = app(ActivarTipoService::class)->activar($admin, $v->fresh());
    echo "tipo {$tipo->id} creado y activado (version {$v->id})\n";
} else {
    // Idempotente: si una corrida anterior quedo a medias (p. ej. activacion rechazada por la proteccion de VALIDAR), se completa.
    $v = $tipo->versiones()->orderByDesc('id')->first();
    echo "tipo {$tipo->id} ya existia (version {$v->id}: {$v->estado})\n";

    if ($v->estado !== TipoVersion::ACTIVA) {
        try {
            $tipos->confirmarContrato($admin, $v);
        } catch (Throwable $e) {
            echo '  contrato: '.mb_substr($e->getMessage(), 0, 120)."\n";
        }

        $v = app(ActivarTipoService::class)->activar($admin, $v->fresh());
        echo "  version {$v->id} activada\n";
    }
}

// 3) Equivalencia ficticia del proveedor para el escenario D1 (Servientrega, sin cuenta corriente).
DB::table('bylopez_lg_proveedor_transportadoras')->updateOrInsert(
    ['proveedor' => '99envios', 'codigo_externo' => '2'],
    ['nombre_externo' => 'servientrega', 'transportadora_codigo' => '03', 'activo' => true, 'observacion' => 'DEMO: equivalencia ficticia para el escenario D1.', 'created_at' => now(), 'updated_at' => now()],
);
echo "equivalencia 99envios '2' -> 03 (Servientrega) lista\n";
