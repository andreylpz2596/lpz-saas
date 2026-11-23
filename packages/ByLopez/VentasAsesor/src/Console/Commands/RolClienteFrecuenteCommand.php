<?php

namespace ByLopez\VentasAsesor\Console\Commands;

use ByLopez\VentasAsesor\Support\Autoservicio\RolClienteFrecuente;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;
use Webkul\User\Models\Role;

/**
 * Doc. 33 §5 — Crea (o confirma) el rol seguro de autoservicio del cliente frecuente y lista los roles «CLIENTE FRECUENTE»
 * heredados de QA para que se revisen a mano. NO modifica, borra ni reasigna ningun rol ni usuario existente.
 *
 * MODO POR DEFECTO: vista previa. Crear el rol exige --aplicar. Repetible.
 */
class RolClienteFrecuenteCommand extends Command
{
    protected $signature = 'ventas-asesor:rol-cliente-frecuente {--aplicar : Crea el rol si no existe. Sin esto solo informa}';

    protected $description = 'Crea el rol seguro de autoservicio del cliente frecuente y audita los roles de QA con nombre parecido (sin modificarlos)';

    public function handle(RolClienteFrecuente $roles): int
    {
        $this->info('Base: '.DB::connection()->getDatabaseName());

        $parecidos = Role::query()->where('name', 'like', '%CLIENTE FRECUENTE%')->orderBy('id')->get();

        foreach ($parecidos as $rol) {
            $usuarios = DB::table('admins')->where('role_id', $rol->id)->count();
            $detalle = $rol->permission_type === 'all' ? 'ALL' : count((array) $rol->permissions).' permisos';
            $marca = $roles->esSeguro($rol) ? 'SEGURO' : 'NO es el rol de autoservicio (no se usa ni se modifica)';

            $this->line("  rol #{$rol->id} «{$rol->name}» — {$detalle} — {$usuarios} usuario(s) — {$marca}");
        }

        try {
            $existente = $roles->existente();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($existente) {
            $this->info("El rol seguro ya existe (#{$existente->id}).");

            return self::SUCCESS;
        }

        if (! $this->option('aplicar')) {
            $this->warn('El rol seguro «'.RolClienteFrecuente::NOMBRE.'» no existe. Vista previa: usa --aplicar para crearlo.');

            return self::SUCCESS;
        }

        $rol = $roles->asegurar();
        $this->info("Rol creado (#{$rol->id}) con permisos: ".implode(', ', RolClienteFrecuente::PERMISOS));

        return self::SUCCESS;
    }
}
