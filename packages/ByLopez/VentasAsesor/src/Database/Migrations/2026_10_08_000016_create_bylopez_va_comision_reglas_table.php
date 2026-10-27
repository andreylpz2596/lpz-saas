<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * COM-03 (Doc. 17 §7.2, COM-D-03/06) — decimosexta migracion del modulo, ADITIVA: reglas de comision con VIGENCIA.
 *
 * - El porcentaje vive aqui, nunca en el codigo: el motor consulta la regla vigente en la fecha que corresponda.
 * - `porcentaje` en puntos (20.0000 = 20 %), CHECK 0 < porcentaje <= 100.
 * - Vigencia [vigente_desde, vigente_hasta): `vigente_hasta` NULL = abierta. Cambiar el porcentaje = cerrar la
 *   vigente y crear otra; los campos de calculo de una regla NUNCA se editan (lo impone el modelo).
 * - `alcance` = `global` hoy; preparado para asesor/producto/categoria sin construirlo (COM-D-06).
 * - Sin FK de `creado_por_admin_id`: la auditoria sobrevive al borrado del usuario. `mecanismo` = cli | panel.
 * - No hay borrado: una regla programada que aun no rige se cancela con `activo = false`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_va_comision_reglas', function (Blueprint $table) {
            $table->id();

            $table->string('codigo', 60);
            $table->string('nombre', 120);
            $table->string('tipo', 40);
            $table->string('base', 40);
            $table->decimal('porcentaje', 7, 4);
            $table->string('alcance', 20)->default('global');
            $table->unsignedSmallInteger('prioridad')->default(0);

            $table->dateTime('vigente_desde');
            $table->dateTime('vigente_hasta')->nullable();
            $table->boolean('activo')->default(true);

            $table->unsignedInteger('creado_por_admin_id')->nullable();
            $table->string('mecanismo', 30);
            $table->string('motivo', 500)->nullable();

            $table->timestamps();

            $table->unique('codigo', 'bvacr_codigo_unique');
            $table->index(['alcance', 'activo', 'vigente_desde'], 'bvacr_vigencia_idx');
        });

        DB::statement('ALTER TABLE bylopez_va_comision_reglas ADD CONSTRAINT bvacr_porcentaje_rango CHECK (porcentaje > 0 AND porcentaje <= 100)');
        DB::statement('ALTER TABLE bylopez_va_comision_reglas ADD CONSTRAINT bvacr_vigencia_orden CHECK (vigente_hasta IS NULL OR vigente_hasta > vigente_desde)');
    }

    /** Solo esta tabla. */
    public function down(): void
    {
        Schema::dropIfExists('bylopez_va_comision_reglas');
    }
};
