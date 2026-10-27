<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * R1 (Doc. 22 §12, Doc. 23) — CONFIGURACION con vigencias e historial. ADITIVA; ninguna fila se crea aqui: sin valores
 * por defecto, sin porcentajes, umbrales, ganancias ni costos inventados (A-07). Todo nace de una accion explicita en el
 * panel, con usuario y motivo.
 *
 * Patron comun (igual que `bylopez_va_comision_reglas`): vigencia [vigente_desde, vigente_hasta), `vigente_hasta` NULL =
 * abierta; cambiar un valor = crear una VERSION nueva que cierra la anterior (los campos de calculo nunca se editan, lo
 * impone el modelo); no se borra; una version que aun no rige se cancela con `activo = false`. Sin FK al actor: la
 * auditoria sobrevive al usuario (snapshot del nombre).
 *
 * - `bylopez_va_comision_acuerdos`: porcentaje de comision POR ASESOR (A-01) + umbral mensual de unidades sin comision.
 *   `umbral_mensual_unidades` NULL = «umbral sin definir» (NO equivale a 0: bloquea la habilitacion); 0 = sin umbral
 *   explicito.
 * - `bylopez_va_rentabilidad_politicas`: ganancia minima por unidad DESPUES de comision y costos variables (opcion B);
 *   `familia_id` NULL = politica por defecto; % minimo y escalon de redondeo opcionales.
 * - `bylopez_va_costos_directos`: costos directos de venta ESTIMADOS (empaque, flete asumido, cargo de cobro...) con su
 *   base de calculo explicita y la fuente real con la que se comparan despues. No son costo del producto.
 * - `bylopez_va_costo_estados`: estado (confirmado / provisional) y fecha de revision del costo estandar por referencia
 *   (familia de Bagisto). Sin fila = «sin estado».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_va_comision_acuerdos', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('asesor_admin_id');
            $table->unsignedInteger('version');
            $table->decimal('porcentaje', 7, 4);
            $table->unsignedInteger('umbral_mensual_unidades')->nullable();
            $table->dateTime('vigente_desde');
            $table->dateTime('vigente_hasta')->nullable();
            $table->boolean('activo')->default(true);
            $table->string('motivo', 500);
            $table->unsignedInteger('creado_por_admin_id')->nullable();
            $table->string('creado_por_name_snapshot', 191)->nullable();
            $table->timestamps();

            $table->unique(['asesor_admin_id', 'version'], 'bvaca_version_unique');
            $table->index(['asesor_admin_id', 'activo', 'vigente_desde'], 'bvaca_vigencia_idx');
        });

        DB::statement('ALTER TABLE bylopez_va_comision_acuerdos ADD CONSTRAINT bvaca_porcentaje_rango CHECK (porcentaje >= 0 AND porcentaje < 100)');
        DB::statement('ALTER TABLE bylopez_va_comision_acuerdos ADD CONSTRAINT bvaca_vigencia_orden CHECK (vigente_hasta IS NULL OR vigente_hasta > vigente_desde)');

        Schema::create('bylopez_va_rentabilidad_politicas', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('familia_id')->nullable();
            $table->unsignedInteger('version');
            $table->decimal('ganancia_minima_unidad', 14, 2);
            $table->decimal('margen_minimo_pct', 7, 4)->nullable();
            $table->unsignedInteger('redondeo_escalon')->nullable();
            $table->dateTime('vigente_desde');
            $table->dateTime('vigente_hasta')->nullable();
            $table->boolean('activo')->default(true);
            $table->string('motivo', 500);
            $table->unsignedInteger('creado_por_admin_id')->nullable();
            $table->string('creado_por_name_snapshot', 191)->nullable();
            $table->timestamps();

            // familia NULL (por defecto) se versiona aparte: `familia_clave` = 0 para la de defecto.
            $table->unsignedInteger('familia_clave')->storedAs('COALESCE(familia_id, 0)');
            $table->unique(['familia_clave', 'version'], 'bvarp_version_unique');
            $table->index(['familia_clave', 'activo', 'vigente_desde'], 'bvarp_vigencia_idx');
        });

        DB::statement('ALTER TABLE bylopez_va_rentabilidad_politicas ADD CONSTRAINT bvarp_ganancia_rango CHECK (ganancia_minima_unidad >= 0)');
        DB::statement('ALTER TABLE bylopez_va_rentabilidad_politicas ADD CONSTRAINT bvarp_margen_rango CHECK (margen_minimo_pct IS NULL OR (margen_minimo_pct >= 0 AND margen_minimo_pct < 100))');
        DB::statement('ALTER TABLE bylopez_va_rentabilidad_politicas ADD CONSTRAINT bvarp_vigencia_orden CHECK (vigente_hasta IS NULL OR vigente_hasta > vigente_desde)');

        Schema::create('bylopez_va_costos_directos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 60);
            $table->unsignedInteger('version');
            $table->string('concepto', 30);
            $table->string('nombre', 120);
            $table->string('tipo_calculo', 20);
            $table->string('base_porcentaje', 30)->nullable();
            $table->decimal('valor', 14, 4);
            $table->unsignedInteger('familia_id')->nullable();
            $table->string('metodo_envio', 100)->nullable();
            $table->string('metodo_pago', 100)->nullable();
            $table->string('fuente_real', 40);
            $table->dateTime('vigente_desde');
            $table->dateTime('vigente_hasta')->nullable();
            $table->boolean('activo')->default(true);
            $table->string('motivo', 500);
            $table->unsignedInteger('creado_por_admin_id')->nullable();
            $table->string('creado_por_name_snapshot', 191)->nullable();
            $table->timestamps();

            $table->unique(['codigo', 'version'], 'bvacd_version_unique');
            $table->index(['activo', 'vigente_desde'], 'bvacd_vigencia_idx');
        });

        DB::statement("ALTER TABLE bylopez_va_costos_directos ADD CONSTRAINT bvacd_concepto_chk CHECK (concepto IN ('empaque', 'flete_asumido', 'cargo_cobro', 'transporte_venta', 'otro'))");
        DB::statement("ALTER TABLE bylopez_va_costos_directos ADD CONSTRAINT bvacd_tipo_chk CHECK (tipo_calculo IN ('por_unidad', 'por_pedido', 'porcentaje'))");
        // Un % siempre declara su base; un valor fijo nunca la tiene.
        DB::statement("ALTER TABLE bylopez_va_costos_directos ADD CONSTRAINT bvacd_base_chk CHECK ((tipo_calculo = 'porcentaje') = (base_porcentaje IS NOT NULL) AND (base_porcentaje IS NULL OR base_porcentaje IN ('precio_neto_mercancia', 'total_cobrado', 'valor_envio')))");
        DB::statement("ALTER TABLE bylopez_va_costos_directos ADD CONSTRAINT bvacd_valor_chk CHECK (valor >= 0 AND (tipo_calculo <> 'porcentaje' OR valor < 100))");
        DB::statement('ALTER TABLE bylopez_va_costos_directos ADD CONSTRAINT bvacd_vigencia_orden CHECK (vigente_hasta IS NULL OR vigente_hasta > vigente_desde)');

        Schema::create('bylopez_va_costo_estados', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('familia_id');
            $table->unsignedInteger('version');
            $table->string('estado', 20);
            $table->date('revisar_hasta')->nullable();
            $table->dateTime('vigente_desde');
            $table->dateTime('vigente_hasta')->nullable();
            $table->string('motivo', 500);
            $table->unsignedInteger('creado_por_admin_id')->nullable();
            $table->string('creado_por_name_snapshot', 191)->nullable();
            $table->timestamps();

            $table->unique(['familia_id', 'version'], 'bvace_version_unique');
            $table->index(['familia_id', 'vigente_desde'], 'bvace_vigencia_idx');
        });

        DB::statement("ALTER TABLE bylopez_va_costo_estados ADD CONSTRAINT bvace_estado_chk CHECK (estado IN ('confirmado', 'provisional'))");
        DB::statement('ALTER TABLE bylopez_va_costo_estados ADD CONSTRAINT bvace_vigencia_orden CHECK (vigente_hasta IS NULL OR vigente_hasta > vigente_desde)');
    }

    /** Solo estas tablas. Con configuracion real, revertir borra historia: no hacerlo fuera de desarrollo. */
    public function down(): void
    {
        Schema::dropIfExists('bylopez_va_costo_estados');
        Schema::dropIfExists('bylopez_va_costos_directos');
        Schema::dropIfExists('bylopez_va_rentabilidad_politicas');
        Schema::dropIfExists('bylopez_va_comision_acuerdos');
    }
};
