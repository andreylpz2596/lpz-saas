<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Doc. 23 §10 — cierre tecnico de R1–R4. ADITIVA; no carga datos de negocio.
 *
 * - `bylopez_va_comision_reevaluaciones`: bandeja DURABLE de pedidos por reevaluar. El hecho de origen (validacion, cobro,
 *   liquidacion, cancelacion, reasignacion...) solo escribe aqui, en SU transaccion; el calculo corre DESPUES del commit
 *   (cola o comando) y si falla se reintenta. Una fila por pedido: `version` sube con cada solicitud y
 *   `version_procesada` alcanza la que se evaluo; pendiente = version > version_procesada.
 * - `bylopez_va_comision_incidencias`: hechos posteriores que afectan una linea YA incluida en una liquidacion. Una
 *   incidencia ABIERTA impide seguir pagando esa linea; nunca descuenta ni borra nada por si sola.
 * - `bylopez_va_comision_aplicaciones`: a que linea o ajuste se aplico cada abono, reverso o compensacion aprobada. Se
 *   GUARDA (antes se derivaba): un hecho posterior no reescribe en silencio a que se aplico un pago ya hecho.
 * - Politicas de rentabilidad por REFERENCIA (familia) + TIPO DE TELA (opcion del atributo `tipo_tela`), como pidio el
 *   Doc. 22 §3: la familia de Bagisto no garantiza una sola tela. Ambas opcionales; la mas especifica gana.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_va_comision_reevaluaciones', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('order_id');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('version_procesada')->default(0);
            $table->string('origen', 60);
            // Alguna solicitud pendiente viene de la CREACION del pedido: si su snapshot fallo se registra tarde y se
            // reevaluan tambien los pedidos posteriores del mismo asesor y mes (commits fuera de orden).
            $table->boolean('creacion')->default(false);
            $table->unsignedInteger('intentos')->default(0);
            $table->dateTime('siguiente_intento_en')->nullable();
            $table->string('ultimo_error', 500)->nullable();
            $table->dateTime('procesada_en')->nullable();
            $table->timestamps();

            $table->unique('order_id', 'bvacr_order_unique');
            $table->index(['version_procesada', 'siguiente_intento_en'], 'bvacr_pendientes_idx');
        });

        Schema::create('bylopez_va_comision_incidencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('linea_id');
            $table->foreignId('liquidacion_id');
            $table->unsignedInteger('order_id');
            $table->unsignedInteger('asesor_admin_id');
            $table->string('tipo', 40);
            $table->json('detalle')->nullable();
            $table->string('estado', 12)->default('abierta');
            $table->foreignId('ajuste_id')->nullable();
            // Solo una incidencia ABIERTA del mismo tipo por linea: `abierta_clave` = linea|tipo mientras esta abierta.
            $table->string('abierta_clave', 80)->nullable();
            $table->string('resolucion', 30)->nullable();
            $table->string('motivo_resolucion', 500)->nullable();
            $table->unsignedInteger('resuelta_por_admin_id')->nullable();
            $table->string('resuelta_por_name_snapshot', 191)->nullable();
            $table->dateTime('resuelta_en')->nullable();
            $table->timestamps();

            $table->unique('abierta_clave', 'bvaci_abierta_unique');
            $table->index(['liquidacion_id', 'estado'], 'bvaci_liquidacion_idx');
            $table->foreign('linea_id', 'bvaci_linea_fk')->references('id')->on('bylopez_va_comision_lineas')->restrictOnDelete();
            $table->foreign('liquidacion_id', 'bvaci_liquidacion_fk')->references('id')->on('bylopez_va_comision_liquidaciones')->restrictOnDelete();
            $table->foreign('ajuste_id', 'bvaci_ajuste_fk')->references('id')->on('bylopez_va_comision_ajustes')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE bylopez_va_comision_incidencias ADD CONSTRAINT bvaci_estado_chk CHECK (estado IN ('abierta', 'resuelta'))");
        DB::statement("ALTER TABLE bylopez_va_comision_incidencias ADD CONSTRAINT bvaci_abierta_chk CHECK ((estado = 'abierta') = (abierta_clave IS NOT NULL) AND (estado = 'abierta' OR (resuelta_en IS NOT NULL AND resolucion IS NOT NULL)))");

        Schema::create('bylopez_va_comision_aplicaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('liquidacion_id');
            $table->foreignId('abono_id')->nullable();
            $table->foreignId('compensa_ajuste_id')->nullable();
            $table->foreignId('linea_id')->nullable();
            $table->foreignId('ajuste_id')->nullable();
            $table->string('tipo', 14);
            // Firmado: + reduce el pendiente del destino; un reverso devuelve con signo −.
            $table->decimal('monto', 14, 2);
            $table->timestamp('created_at')->nullable();

            $table->index(['liquidacion_id', 'linea_id'], 'bvacap_linea_idx');
            $table->index('abono_id', 'bvacap_abono_idx');
            $table->foreign('liquidacion_id', 'bvacap_liquidacion_fk')->references('id')->on('bylopez_va_comision_liquidaciones')->restrictOnDelete();
            $table->foreign('abono_id', 'bvacap_abono_fk')->references('id')->on('bylopez_va_comision_abonos')->restrictOnDelete();
            $table->foreign('compensa_ajuste_id', 'bvacap_compensa_fk')->references('id')->on('bylopez_va_comision_ajustes')->restrictOnDelete();
            $table->foreign('linea_id', 'bvacap_linea_fk')->references('id')->on('bylopez_va_comision_lineas')->restrictOnDelete();
            $table->foreign('ajuste_id', 'bvacap_ajuste_fk')->references('id')->on('bylopez_va_comision_ajustes')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE bylopez_va_comision_aplicaciones ADD CONSTRAINT bvacap_tipo_chk CHECK (tipo IN ('abono', 'reverso', 'compensacion'))");
        // Exactamente un destino (linea o ajuste positivo) y un origen coherente con el tipo.
        DB::statement('ALTER TABLE bylopez_va_comision_aplicaciones ADD CONSTRAINT bvacap_destino_chk CHECK ((linea_id IS NULL) <> (ajuste_id IS NULL) AND monto <> 0)');
        DB::statement("ALTER TABLE bylopez_va_comision_aplicaciones ADD CONSTRAINT bvacap_origen_chk CHECK ((tipo = 'compensacion') = (compensa_ajuste_id IS NOT NULL AND abono_id IS NULL) AND (tipo = 'compensacion' OR abono_id IS NOT NULL))");

        // Politica por referencia + tipo de tela (tabla vacia en desarrollo: se cambia la unicidad del versionado).
        Schema::table('bylopez_va_rentabilidad_politicas', function (Blueprint $table) {
            $table->unsignedInteger('tipo_tela_id')->nullable()->after('familia_id');
        });

        Schema::table('bylopez_va_rentabilidad_politicas', function (Blueprint $table) {
            $table->unsignedInteger('tela_clave')->storedAs('COALESCE(tipo_tela_id, 0)')->after('familia_clave');
            $table->dropUnique('bvarp_version_unique');
            $table->dropIndex('bvarp_vigencia_idx');
            $table->unique(['familia_clave', 'tela_clave', 'version'], 'bvarp_version_unique');
            $table->index(['familia_clave', 'tela_clave', 'activo', 'vigente_desde'], 'bvarp_vigencia_idx');
        });

        Schema::table('bylopez_va_rentabilidad_evaluacion_lineas', function (Blueprint $table) {
            $table->unsignedInteger('tipo_tela_id')->nullable()->after('familia_id');
        });
    }

    /** Solo en desarrollo: con datos reales, revertir pierde historia. */
    public function down(): void
    {
        Schema::table('bylopez_va_rentabilidad_evaluacion_lineas', function (Blueprint $table) {
            $table->dropColumn('tipo_tela_id');
        });

        Schema::table('bylopez_va_rentabilidad_politicas', function (Blueprint $table) {
            $table->dropUnique('bvarp_version_unique');
            $table->dropIndex('bvarp_vigencia_idx');
            $table->dropColumn('tela_clave');
        });

        Schema::table('bylopez_va_rentabilidad_politicas', function (Blueprint $table) {
            $table->dropColumn('tipo_tela_id');
            $table->unique(['familia_clave', 'version'], 'bvarp_version_unique');
            $table->index(['familia_clave', 'activo', 'vigente_desde'], 'bvarp_vigencia_idx');
        });

        Schema::dropIfExists('bylopez_va_comision_aplicaciones');
        Schema::dropIfExists('bylopez_va_comision_incidencias');
        Schema::dropIfExists('bylopez_va_comision_reevaluaciones');
    }
};
