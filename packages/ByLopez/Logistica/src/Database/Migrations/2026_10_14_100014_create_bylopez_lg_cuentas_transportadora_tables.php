<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * CUENTAS CORRIENTES de transportadoras (custodia/obligacion transportador ↔ ByLopez). NO es Cartera (cliente ↔ ByLopez).
 *
 * - `bylopez_lg_cuenta_movimientos`: LIBRO de movimientos. El saldo NO se guarda: es la suma de `importe`.
 *   Convencion UNICA (importe firmado): + = la transportadora debe a ByLopez; - = ByLopez debe a la transportadora.
 *   Solo de adicion: un movimiento no se edita ni se borra; se corrige con un REVERSO (`revierte_id`, UNIQUE: un movimiento
 *   se revierte una sola vez) o con un AJUSTE. La unica escritura posterior es `abierto → liquidado` (+ `liquidacion_id`).
 *   `idempotency_key` UNIQUE: el mismo evento nunca genera dos veces el mismo dinero (refresh, doble clic, retry, carrera).
 * - `bylopez_lg_liquidaciones_transportadora`: CIERRE INMUTABLE de un conjunto de movimientos. Sus movimientos (incluidos
 *   el pago y, si no cuadro, la diferencia que se traslada) suman 0; la diferencia se reabre como movimiento abierto.
 *   El numero LIQ-000001 sale del contador `bylopez_lg_consecutivos` (clave `liquidacion_transportadora`) + UNIQUE.
 *
 * Sin backfill: los envios anteriores no generan movimientos solos (comando `logistica:cuentas:sincronizar`, explicito).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_lg_liquidaciones_transportadora', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('consecutivo');
            $table->foreignId('transportadora_id');
            $table->date('periodo_desde')->nullable();
            $table->date('periodo_hasta')->nullable();
            $table->unsignedInteger('movimientos_count');
            $table->decimal('total_recaudos', 14, 2);
            $table->decimal('total_servicios', 14, 2);
            $table->decimal('total_ajustes', 14, 2);
            $table->decimal('saldo_esperado', 14, 2);
            $table->string('sentido', 24);
            $table->decimal('valor_liquidado', 14, 2);
            $table->decimal('diferencia', 14, 2);
            $table->string('observacion', 500)->nullable();
            $table->string('referencia', 120)->nullable();
            $table->json('resumen');
            $table->string('estado', 12)->default('cerrada');
            $table->string('idempotency_key', 120);
            $table->char('huella', 64);
            $table->unsignedInteger('actor_admin_id');
            $table->string('actor_name_snapshot', 191);
            $table->dateTime('cerrada_en');
            $table->timestamps();

            $table->unique('consecutivo', 'byllq_consecutivo_unique');
            $table->unique('idempotency_key', 'byllq_idem_unique');
            $table->index(['transportadora_id', 'cerrada_en'], 'byllq_transportadora_idx');
            $table->foreign('transportadora_id', 'byllq_transportadora_fk')->references('id')->on('bylopez_lg_transportadoras')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE bylopez_lg_liquidaciones_transportadora ADD CONSTRAINT byllq_estado_chk CHECK (estado = 'cerrada')");
        DB::statement("ALTER TABLE bylopez_lg_liquidaciones_transportadora ADD CONSTRAINT byllq_sentido_chk CHECK (sentido IN ('transportador_entrega', 'bylopez_paga', 'al_dia'))");
        DB::statement('ALTER TABLE bylopez_lg_liquidaciones_transportadora ADD CONSTRAINT byllq_valor_chk CHECK (valor_liquidado >= 0)');

        Schema::create('bylopez_lg_cuenta_movimientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transportadora_id');
            $table->unsignedInteger('order_id')->nullable();
            $table->unsignedInteger('shipment_id')->nullable();
            $table->foreignId('guia_id')->nullable();
            $table->string('concepto', 30);
            $table->decimal('importe', 14, 2);
            $table->string('origen_tipo', 20);
            $table->unsignedBigInteger('origen_id')->nullable();
            $table->foreignId('revierte_id')->nullable();
            $table->date('fecha_operativa');
            $table->string('descripcion', 255)->nullable();
            $table->string('motivo', 500)->nullable();
            $table->string('referencia', 120)->nullable();
            $table->string('estado', 12)->default('abierto');
            $table->foreignId('liquidacion_id')->nullable();
            $table->string('idempotency_key', 120);
            $table->char('huella', 64)->nullable();
            $table->unsignedInteger('actor_admin_id')->nullable();
            $table->string('actor_name_snapshot', 191)->nullable();
            $table->timestamps();

            $table->unique('idempotency_key', 'bylcm_idem_unique');
            $table->unique('revierte_id', 'bylcm_revierte_unique');
            $table->index(['transportadora_id', 'estado'], 'bylcm_cuenta_idx');
            $table->index(['shipment_id', 'concepto'], 'bylcm_envio_idx');
            $table->index('liquidacion_id', 'bylcm_liquidacion_idx');

            $table->foreign('transportadora_id', 'bylcm_transportadora_fk')->references('id')->on('bylopez_lg_transportadoras')->restrictOnDelete();
            $table->foreign('order_id', 'bylcm_order_fk')->references('id')->on('orders')->restrictOnDelete();
            $table->foreign('shipment_id', 'bylcm_shipment_fk')->references('id')->on('shipments')->restrictOnDelete();
            $table->foreign('guia_id', 'bylcm_guia_fk')->references('id')->on('bylopez_lg_guias')->restrictOnDelete();
            $table->foreign('revierte_id', 'bylcm_revierte_fk')->references('id')->on('bylopez_lg_cuenta_movimientos')->restrictOnDelete();
            $table->foreign('liquidacion_id', 'bylcm_liquidacion_fk')->references('id')->on('bylopez_lg_liquidaciones_transportadora')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE bylopez_lg_cuenta_movimientos ADD CONSTRAINT bylcm_concepto_chk CHECK (concepto IN ('recaudo', 'servicio', 'costo_devolucion', 'ajuste', 'pago_liquidacion', 'diferencia_liquidacion'))");
        DB::statement("ALTER TABLE bylopez_lg_cuenta_movimientos ADD CONSTRAINT bylcm_origen_chk CHECK (origen_tipo IN ('envio', 'revision', 'ajuste', 'liquidacion', 'reverso'))");
        DB::statement("ALTER TABLE bylopez_lg_cuenta_movimientos ADD CONSTRAINT bylcm_estado_chk CHECK (estado IN ('abierto', 'liquidado'))");
        // Liquidado ⇔ pertenece a una liquidacion.
        DB::statement("ALTER TABLE bylopez_lg_cuenta_movimientos ADD CONSTRAINT bylcm_liquidado_chk CHECK ((estado = 'liquidado') = (liquidacion_id IS NOT NULL))");
        // Un movimiento con importe 0 solo existe como DECISION registrada (recaudo/servicio confirmado en revision) o su reverso.
        DB::statement("ALTER TABLE bylopez_lg_cuenta_movimientos ADD CONSTRAINT bylcm_importe_chk CHECK (importe <> 0 OR origen_tipo IN ('revision', 'reverso'))");
        // Un reverso siempre apunta al movimiento que revierte.
        DB::statement("ALTER TABLE bylopez_lg_cuenta_movimientos ADD CONSTRAINT bylcm_reverso_chk CHECK ((origen_tipo = 'reverso') = (revierte_id IS NOT NULL))");

        DB::table('bylopez_lg_consecutivos')->insertOrIgnore(['clave' => 'liquidacion_transportadora', 'ultimo' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('bylopez_lg_cuenta_movimientos');
        Schema::dropIfExists('bylopez_lg_liquidaciones_transportadora');
        DB::table('bylopez_lg_consecutivos')->where('clave', 'liquidacion_transportadora')->delete();
    }
};
