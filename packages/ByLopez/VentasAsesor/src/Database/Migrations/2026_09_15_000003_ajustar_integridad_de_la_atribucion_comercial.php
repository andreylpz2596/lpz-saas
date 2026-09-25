<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Fase 02A1 — correccion de integridad. Migracion ADITIVA: las dos migraciones
 * originales no se tocan, porque la forma segura de corregir un esquema es
 * avanzar, no reescribir historia (aunque aqui solo se hubiera aplicado en
 * testing).
 *
 * Corrige cuatro cosas:
 *
 * 1. Cascadas que destruian auditoria. `orders -> atribucion` y
 *    `atribucion -> historial` pasan a RESTRICT: borrar un pedido atribuido o una
 *    atribucion con historial falla en la base de datos.
 * 2. Claves foraneas de administradores. Se RETIRAN de la atribucion: con
 *    nullOnDelete, dar de baja a un administrador dejaba la combinacion invalida
 *    `commercial_owner_type = advisor` con `advisor_id = null`. Ahora el id
 *    historico se conserva sin clave foranea, junto al snapshot de nombre, igual
 *    que ya hacia la tabla de historial. Se prefiere esto a RESTRICT para no
 *    bloquear operaciones administrativas del core desde un modulo externo.
 * 3. Historial de la asignacion inicial: `tipo_propiedad_anterior` pasa a
 *    nullable y se agregan tipo de operacion y contexto comercial del evento.
 * 4. Se eliminan `is_excluded` y `exclusion_reason`: decidir elegibilidad de
 *    comision no corresponde a esta fase (D-01, D-03, D-31 y D-32 pendientes).
 *
 * No toca ninguna tabla ni dato del core.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bylopez_va_order_attributions', function (Blueprint $table) {
            $table->dropForeign('bvaoa_order_fk');
            $table->dropForeign('bvaoa_created_by_fk');
            $table->dropForeign('bvaoa_advisor_fk');
            $table->dropForeign('bvaoa_assigned_by_fk');

            $table->foreign('order_id', 'bvaoa_order_fk')
                ->references('id')->on('orders')->restrictOnDelete();

            $table->dropIndex('bvaoa_excluded_idx');
            $table->dropColumn(['is_excluded', 'exclusion_reason']);
        });

        Schema::table('bylopez_va_advisor_assignment_logs', function (Blueprint $table) {
            $table->dropForeign('bvaal_attribution_fk');

            $table->foreign('order_attribution_id', 'bvaal_attribution_fk')
                ->references('id')->on('bylopez_va_order_attributions')->restrictOnDelete();

            $table->string('tipo_operacion')->default('initial_assignment')->after('order_id')->index('bvaal_operacion_idx');
            $table->string('origen_propiedad')->nullable()->after('tipo_propiedad_nuevo');
            $table->string('sales_channel')->nullable()->after('origen_propiedad');
            $table->string('creation_source')->nullable()->after('sales_channel');
            $table->string('codigo_tecnico')->nullable()->after('changed_by_name_snapshot')->index('bvaal_codigo_idx');
        });

        // La asignacion inicial no tiene propiedad anterior.
        Schema::table('bylopez_va_advisor_assignment_logs', function (Blueprint $table) {
            $table->string('tipo_propiedad_anterior')->nullable()->change();
        });
    }

    /*
     * El rollback vuelve a un esquema que NO puede representar lo que la 02A1
     * introdujo, asi que primero trata los datos reales de las tablas propias
     * (Fase 02A2). Ambas conversiones pierden informacion a proposito y quedan
     * documentadas: son el precio de volver atras, no un efecto inadvertido.
     */
    public function down(): void
    {
        /*
         * 1. El esquema viejo exige `tipo_propiedad_anterior` NOT NULL, pero las
         *    asignaciones iniciales no tienen estado previo. Se copia de forma
         *    determinista la propiedad NUEVA del mismo evento: la fila queda valida
         *    y el dato es reconstruible, aunque deja de distinguirse una asignacion
         *    inicial de un cambio que no movio la propiedad.
         */
        DB::table('bylopez_va_advisor_assignment_logs')
            ->whereNull('tipo_propiedad_anterior')
            ->update(['tipo_propiedad_anterior' => DB::raw('tipo_propiedad_nuevo')]);

        /*
         * 2. El esquema viejo vuelve a poner claves foraneas hacia `admins`. Los ids
         *    de administradores ya eliminados romperian esa restriccion, asi que se
         *    anulan. Esto REINTRODUCE el defecto que la 02A1 corrigio: una venta de
         *    asesor puede quedar sin advisor_id. Es inevitable en el esquema viejo.
         */
        foreach (['created_by_admin_id', 'advisor_id', 'assigned_by_admin_id'] as $columna) {
            DB::table('bylopez_va_order_attributions')
                ->whereNotNull($columna)
                ->whereNotIn($columna, function ($consulta) {
                    $consulta->select('id')->from('admins');
                })
                ->update([$columna => null]);
        }

        // Solo revierte tablas propias, y en el orden inverso.
        Schema::table('bylopez_va_advisor_assignment_logs', function (Blueprint $table) {
            $table->dropIndex('bvaal_operacion_idx');
            $table->dropIndex('bvaal_codigo_idx');
            $table->dropColumn(['tipo_operacion', 'origen_propiedad', 'sales_channel', 'creation_source', 'codigo_tecnico']);

            $table->dropForeign('bvaal_attribution_fk');
            $table->foreign('order_attribution_id', 'bvaal_attribution_fk')
                ->references('id')->on('bylopez_va_order_attributions')->cascadeOnDelete();
        });

        Schema::table('bylopez_va_advisor_assignment_logs', function (Blueprint $table) {
            $table->string('tipo_propiedad_anterior')->nullable(false)->change();
        });

        Schema::table('bylopez_va_order_attributions', function (Blueprint $table) {
            $table->boolean('is_excluded')->default(false)->index('bvaoa_excluded_idx');
            $table->string('exclusion_reason')->nullable();

            $table->dropForeign('bvaoa_order_fk');
            $table->foreign('order_id', 'bvaoa_order_fk')
                ->references('id')->on('orders')->cascadeOnDelete();

            $table->foreign('created_by_admin_id', 'bvaoa_created_by_fk')
                ->references('id')->on('admins')->nullOnDelete();
            $table->foreign('advisor_id', 'bvaoa_advisor_fk')
                ->references('id')->on('admins')->nullOnDelete();
            $table->foreign('assigned_by_admin_id', 'bvaoa_assigned_by_fk')
                ->references('id')->on('admins')->nullOnDelete();
        });
    }
};
