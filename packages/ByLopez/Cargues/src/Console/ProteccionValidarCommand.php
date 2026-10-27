<?php

namespace ByLopez\Cargues\Console;

use ByLopez\Cargues\Models\TipoVersion;
use ByLopez\Cargues\Services\Configuracion\ConexionTipo;
use ByLopez\Cargues\Services\Configuracion\MapValidator;
use ByLopez\Cargues\Services\Configuracion\ProteccionValidar;
use Illuminate\Console\Command;

/**
 * Muestra (o aplica) la proteccion preventiva de VALIDAR de cada version ACTIVA de procedimiento:
 *   php artisan cargues:proteccion-validar              (solo evalua y muestra los GRANT necesarios)
 *   php artisan cargues:proteccion-validar --aplicar    (ejecuta los GRANT minimos con la conexion del tipo)
 * Sale con 1 si algun tipo activo no cumple.
 */
class ProteccionValidarCommand extends Command
{
    protected $signature = 'cargues:proteccion-validar {--aplicar : Conceder los permisos minimos a la cuenta restringida}';

    protected $description = 'Evalua la proteccion de VALIDAR (cuenta restringida, SQL SECURITY, rutinas, triggers) de los tipos activos';

    public function handle(ProteccionValidar $proteccion, ConexionTipo $conexiones, MapValidator $validador): int
    {
        $ok = true;
        $versiones = TipoVersion::where('estado', TipoVersion::ACTIVA)->get();

        if ($versiones->isEmpty()) {
            $this->info('No hay versiones activas.');

            return self::SUCCESS;
        }

        foreach ($versiones as $v) {
            $c = $conexiones->conexion($v->conexion);
            $map = $validador->normalizar((array) $v->map);
            $this->line("<options=bold>{$v->tipo->nombre}</> (v{$v->numero}) · {$v->conexion} · {$v->procedimiento} · {$map['tabla']}");

            if (! $proteccion->cuenta($v->conexion)) {
                $this->error('  Sin cuenta restringida configurada (CARGUES_VALIDAR_DB_USERNAME).');
                $ok = false;

                continue;
            }

            foreach ($proteccion->conceder($c, $v->conexion, $v->procedimiento, $map['tabla'], (array) ($map['tablas_negocio'] ?? []), (bool) $this->option('aplicar')) as $g) {
                $this->line(($g['ok'] ? '  [aplicado] ' : '  [sentencia] ').$g['sentencia'].($g['error'] ? "  -> {$g['error']}" : ''));
            }

            $hall = $proteccion->verificar($c, $v->conexion, $v->procedimiento, $map['tabla'], null, true);
            $errores = array_filter($hall, fn ($h) => $h['nivel'] === 'error');

            foreach ($hall as $h) {
                $this->line('  '.($h['nivel'] === 'error' ? '<fg=red>ERROR</>' : '<fg=yellow>AVISO</>')." {$h['codigo']}: {$h['mensaje']}");
            }

            if ($errores) {
                $ok = false;
            } else {
                $this->info('  Protección de VALIDAR: cumple.');
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
