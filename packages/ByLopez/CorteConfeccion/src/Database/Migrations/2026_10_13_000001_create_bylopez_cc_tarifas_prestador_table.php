<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Doc. 05 (Fase 2) — historial INMUTABLE de tarifas por prenda de un taller o de un cortador.
 *
 * - Una fila es de un taller O de un cortador (CHECK). FK RESTRICT: la historia no se cascadea.
 * - No se edita: corregir es anular (`anulada_en` + motivo) y crear otra. No se guarda `vigente_hasta`:
 *   rige hasta el dia anterior a la siguiente tarifa activa.
 * - Los ids de administrador van SIN clave foranea para que la auditoria sobreviva al usuario.
 * - Cambiar o anular una tarifa NO altera las ordenes ya creadas: cada orden guarda su propia copia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_cc_tarifas_prestador', function (Blueprint $table) {
            $table->id();
            $table->foreignId('taller_id')->nullable()
                ->constrained('bylopez_cc_talleres', 'id', 'bcctp_taller_fk')->restrictOnDelete();
            $table->foreignId('cortador_id')->nullable()
                ->constrained('bylopez_cc_cortadores', 'id', 'bcctp_cortador_fk')->restrictOnDelete();
            $table->decimal('valor_unitario', 12, 4);
            $table->date('vigente_desde');
            $table->string('observacion', 500)->nullable();
            $table->unsignedInteger('creado_por_admin_id')->nullable();
            $table->timestamp('anulada_en')->nullable();
            $table->unsignedInteger('anulada_por_admin_id')->nullable();
            $table->string('motivo_anulacion', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['taller_id', 'vigente_desde'], 'bcctp_taller_vigencia_idx');
            $table->index(['cortador_id', 'vigente_desde'], 'bcctp_cortador_vigencia_idx');
        });

        DB::statement('ALTER TABLE bylopez_cc_tarifas_prestador ADD CONSTRAINT bcctp_valor_positivo CHECK (valor_unitario > 0)');
        DB::statement('ALTER TABLE bylopez_cc_tarifas_prestador ADD CONSTRAINT bcctp_un_prestador CHECK ((taller_id IS NULL) <> (cortador_id IS NULL))');
    }

    /** Falla (FK RESTRICT) si ya hay ordenes que referencian tarifas: la historia no se pierde por un rollback. */
    public function down(): void
    {
        Schema::dropIfExists('bylopez_cc_tarifas_prestador');
    }
};
