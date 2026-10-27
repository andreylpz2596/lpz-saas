<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F0 - Recepcion por identidad de OrdenCorteDetalle (ver
 * docs/ORDEN_CORTE_CREACION_V2_AUDITORIA_02.md §12.2 y
 * docs/confeccion/STATUS.md).
 *
 * Nullable a proposito: las recepciones ya existentes (creadas antes de esta
 * fase) no tienen forma de reconstruir de forma confiable a cual
 * OrdenCorteDetalle correspondia cada fila -- no se reescribe historico. A
 * partir de esta migracion, todo INSERT nuevo de RecepcionCorteService::create()
 * llena esta columna siempre; solo las filas historicas quedan en NULL.
 *
 * Politica de borrado: nullOnDelete(), no cascadeOnDelete() ni
 * restrictOnDelete(). Razones:
 * - RecepcionCorteDetalle ya guarda su propio snapshot (product_id,
 *   producto_sku, producto_nombre, talla) tomado en el momento de la
 *   recepcion; no depende de que el OrdenCorteDetalle de origen siga
 *   existiendo para mostrarse correctamente. Es, por diseno, un registro
 *   historico/de auditoria de lo que Bodega conto fisicamente (mismo
 *   principio ya aplicado a OrdenCorteDetalle.cantidad_real_cortada, ver
 *   comentario en RecepcionCorteService::create()) -- no debe poder
 *   desaparecer solo porque algo le paso a la fila de origen.
 * - cascadeOnDelete() borraria silenciosamente ese registro historico si
 *   alguna vez se elimina el OrdenCorteDetalle (o su OrdenCorte via cascade),
 *   lo cual contradice ese principio.
 * - restrictOnDelete() bloquearia ese borrado, pero hoy no existe ningun
 *   endpoint/Service que borre un OrdenCorte o un OrdenCorteDetalle
 *   individualmente (solo existe "anular", que es un cambio de estado, no un
 *   DELETE) -- la proteccion nunca se activaria en la practica y solo
 *   quedaria como una promesa no verificable.
 * - nullOnDelete() dejaria intacta la fila de recepcion (con su snapshot) y
 *   simplemente perderia la referencia de identidad, exactamente el mismo
 *   estado en el que ya quedan las recepciones historicas anteriores a esta
 *   migracion. Es la opcion coherente con ambos escenarios (historico
 *   preexistente y borrado futuro hipotetico) sin necesitar dos
 *   justificaciones distintas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bylopez_cc_recepciones_corte_detalles', function (Blueprint $table) {
            $table->unsignedBigInteger('orden_corte_detalle_id')->nullable()->after('recepcion_corte_id');
            $table->index('orden_corte_detalle_id', 'bccrcd_detalle_idx');

            // Nombre de constraint corto y explicito: el default de Laravel
            // (bylopez_cc_recepciones_corte_detalles_orden_corte_detalle_id_foreign)
            // supera el limite de 64 caracteres de MySQL. Mismo patron ya usado
            // en 2026_06_14_000003_..._cantidad_audits_table.php (bccoca_*) y en
            // las migraciones de ordenes_confeccion_retornos (bccofr_*/bccofrd_*).
            $table->foreign('orden_corte_detalle_id', 'bccrcd_detalle_fk')
                ->references('id')
                ->on('bylopez_cc_ordenes_corte_detalles')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bylopez_cc_recepciones_corte_detalles', function (Blueprint $table) {
            $table->dropForeign('bccrcd_detalle_fk');
            $table->dropIndex('bccrcd_detalle_idx');
            $table->dropColumn('orden_corte_detalle_id');
        });
    }
};
