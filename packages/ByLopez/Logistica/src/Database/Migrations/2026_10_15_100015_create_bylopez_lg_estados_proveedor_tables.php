<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * ESTADO DEL ENVIO SEGUN EL PROVEEDOR (p. ej. 99 Envios) — INFORMATIVO y ADITIVO.
 *
 *   ESTADO REPORTADO (estas tablas)  ≠  ENTREGA REGISTRADA (bylopez_lg_entregas)  ≠  ENTREGA VALIDADA  ≠  PAGO (Cartera)
 *
 * Un proveedor intermediario (99 Envios) NO es la transportadora fisica: se guardan por separado el `proveedor` (de donde
 * viene el dato) y la `transportadora_codigo` efectiva de la guia. Estas tablas NO generan entregas, retornos,
 * recepciones, validaciones, recaudos ni comisiones: la fuente `integracion_transportadora` sigue reservada.
 *
 * - `bylopez_lg_proveedor_transportadoras`: CONFIGURACION que traduce el codigo de transportadora del proveedor ("1",
 *   "4"...) al catalogo interno. Sin fila activa, el codigo es desconocido (nunca se deduce del nombre).
 * - `bylopez_lg_guia_estados_proveedor`: el ULTIMO estado reportado por guia y proveedor (texto literal del proveedor,
 *   con su fecha), los valores reportados y la conciliacion del recaudo. Lo escribe solo el procedimiento del cargue.
 * - `bylopez_lg_guia_estado_proveedor_eventos`: historial APPEND-ONLY de cada reporte aplicado (con el estado anterior),
 *   con su procedencia (carga y fila del cargue). UNIQUE (carga, fila): reprocesar la misma carga no duplica.
 *
 * Procedencia sin FK a Cargues (frontera entre paquetes, como los actores sin FK del resto de Logistica). Sin backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_lg_proveedor_transportadoras', function (Blueprint $table) {
            $table->id();
            $table->string('proveedor', 40);
            $table->string('codigo_externo', 40);
            $table->string('nombre_externo', 120)->nullable();
            $table->string('transportadora_codigo', 40);
            $table->boolean('activo')->default(true);
            $table->string('observacion', 255)->nullable();
            $table->timestamps();

            $table->unique(['proveedor', 'codigo_externo'], 'bylgpt_codigo_unique');
            $table->foreign('transportadora_codigo', 'bylgpt_transportadora_fk')->references('codigo')->on('bylopez_lg_transportadoras')->restrictOnDelete();
        });

        $valores = function (Blueprint $table) {
            $table->decimal('valor_comercial_reportado', 14, 2)->nullable();
            // Literal tal como lo reporta el proveedor (trae artefactos de coma flotante): no se interpreta ni se redondea.
            $table->string('valor_servicio_reportado', 40)->nullable();
            $table->decimal('valor_seguro_reportado', 14, 2)->nullable();
            $table->string('seguro_reportado', 60)->nullable();
            $table->boolean('aplica_contrapago_reportado')->nullable();
            // Conciliacion del recaudo: reportado = valor_comercial si aplica contrapago, 0 si no; interno = recaudo_esperado
            // de la guia AL MOMENTO del reporte. Diferencia = reportado - interno, exacta en decimal (sin tolerancia).
            $table->decimal('recaudo_reportado', 14, 2)->nullable();
            $table->decimal('recaudo_interno', 12, 2)->nullable();
            $table->decimal('recaudo_diferencia', 14, 2)->nullable();
            $table->string('conciliacion_recaudo', 20);
        };

        Schema::create('bylopez_lg_guia_estados_proveedor', function (Blueprint $table) use ($valores) {
            $table->id();
            $table->foreignId('guia_id');
            $table->string('proveedor', 40);
            $table->string('transportadora_codigo', 40);
            $table->string('numero_guia', 100);
            $table->string('estado_proveedor', 191);
            $table->dateTime('fecha_estado_proveedor');
            $table->dateTime('fecha_envio_proveedor');
            $valores($table);
            $table->unsignedBigInteger('carga_id');
            $table->unsignedInteger('fila_origen');
            $table->unsignedInteger('reportes')->default(1);
            $table->timestamps();

            $table->unique(['guia_id', 'proveedor'], 'bylgep_guia_proveedor_unique');
            $table->index('estado_proveedor', 'bylgep_estado_idx');
            $table->index('conciliacion_recaudo', 'bylgep_conciliacion_idx');
            $table->foreign('guia_id', 'bylgep_guia_fk')->references('id')->on('bylopez_lg_guias')->restrictOnDelete();
        });

        Schema::create('bylopez_lg_guia_estado_proveedor_eventos', function (Blueprint $table) use ($valores) {
            $table->id();
            $table->foreignId('guia_id');
            $table->string('proveedor', 40);
            $table->string('estado_proveedor', 191);
            $table->dateTime('fecha_estado_proveedor');
            $table->dateTime('fecha_envio_proveedor');
            $table->string('estado_anterior', 191)->nullable();
            $table->dateTime('fecha_estado_anterior')->nullable();
            $valores($table);
            $table->unsignedBigInteger('carga_id');
            $table->unsignedInteger('fila_origen');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['carga_id', 'fila_origen'], 'bylgepe_carga_fila_unique');
            $table->index(['guia_id', 'fecha_estado_proveedor'], 'bylgepe_guia_fecha_idx');
            $table->foreign('guia_id', 'bylgepe_guia_fk')->references('id')->on('bylopez_lg_guias')->restrictOnDelete();
        });

        foreach (['bylopez_lg_guia_estados_proveedor' => 'bylgep', 'bylopez_lg_guia_estado_proveedor_eventos' => 'bylgepe'] as $t => $p) {
            DB::statement("ALTER TABLE {$t} ADD CONSTRAINT {$p}_conciliacion_chk CHECK (conciliacion_recaudo IN ('coincide', 'difiere', 'sin_dato'))");
            DB::statement("ALTER TABLE {$t} ADD CONSTRAINT {$p}_diferencia_chk CHECK ((conciliacion_recaudo = 'sin_dato') = (recaudo_diferencia IS NULL) AND (conciliacion_recaudo <> 'coincide' OR recaudo_diferencia = 0) AND (conciliacion_recaudo <> 'difiere' OR recaudo_diferencia <> 0))");
        }
    }

    /** Con reportes reales, revertir borra la trazabilidad del estado reportado: no hacerlo en produccion. */
    public function down(): void
    {
        Schema::dropIfExists('bylopez_lg_guia_estado_proveedor_eventos');
        Schema::dropIfExists('bylopez_lg_guia_estados_proveedor');
        Schema::dropIfExists('bylopez_lg_proveedor_transportadoras');
    }
};
