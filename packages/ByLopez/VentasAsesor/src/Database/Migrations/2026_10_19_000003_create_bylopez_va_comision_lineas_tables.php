<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * R3 (Doc. 21 §5, Doc. 22 §5, Doc. 23) — LINEAS DE COMISION, umbral mensual y su historial.
 *
 * - `bylopez_va_comision_lineas`: una linea por item PADRE de un pedido de asesor, creada al confirmar (estimada) con las
 *   fuentes historicas congeladas: asesor, acuerdo y % vigentes AL CONFIRMAR (A-02), mes comercial (America/Bogota, del
 *   instante real de confirmacion), precio neto y costo. `vigente_unica` = order_item mientras la linea no este anulada:
 *   nunca dos lineas vivas para la misma unidad (una reasignacion anula y crea otra, con historial).
 *   Unidades comisionables y monto se CONGELAN al habilitar; antes son una estimacion derivada.
 * - `bylopez_va_comision_eventos`: historial append-only de cada linea (creada, habilitada, deshabilitada, anulada,
 *   liquidada, marcada para politica pendiente...).
 * - `bylopez_va_umbral_movimientos`: LIBRO de unidades computables por asesor y mes. El acumulado no se guarda: es la suma.
 *   Cada movimiento es unico por origen (`idempotency_key` UNIQUE): venta, cancelacion, unidades no entregadas y reverso
 *   por reasignacion. Nunca se edita ni se borra.
 * - `bylopez_va_comision_meses`: una fila por asesor y mes. Es el CERROJO (FOR UPDATE) que serializa la habilitacion: dos
 *   pedidos simultaneos no pueden consumir el mismo tramo sin comision. Guarda el umbral aplicado cuando se fija.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_va_comision_lineas', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('order_id');
            $table->unsignedInteger('order_item_id');
            $table->unsignedInteger('asesor_admin_id');
            $table->string('asesor_name_snapshot', 191)->nullable();
            $table->foreignId('acuerdo_id')->nullable();
            $table->decimal('porcentaje', 7, 4)->nullable();
            $table->char('mes_comercial', 7);
            $table->dateTime('confirmado_en');
            $table->unsignedInteger('familia_id')->nullable();
            $table->string('referencia', 191)->nullable();
            $table->string('sku', 191)->nullable();
            $table->unsignedInteger('cantidad_vendida');
            $table->decimal('precio_neto_unitario', 14, 4);
            $table->decimal('costo_unitario', 14, 4)->nullable();
            $table->decimal('base_unitaria', 14, 4)->nullable();
            $table->string('estado', 20)->default('estimada');
            $table->unsignedInteger('unidades_comisionables')->nullable();
            $table->decimal('monto', 14, 2)->nullable();
            $table->dateTime('habilitada_en')->nullable();
            $table->json('evidencia')->nullable();
            $table->string('alerta', 40)->nullable();
            $table->unsignedBigInteger('liquidacion_id')->nullable();
            $table->dateTime('anulada_en')->nullable();
            $table->string('motivo_anulacion', 500)->nullable();
            $table->timestamps();

            $table->unsignedInteger('vigente_unica')->nullable()->storedAs("IF(estado <> 'anulada', order_item_id, NULL)");
            $table->unique('vigente_unica', 'bvacl_vigente_unica');
            $table->index(['asesor_admin_id', 'mes_comercial'], 'bvacl_asesor_mes_idx');
            $table->index(['estado', 'order_id'], 'bvacl_estado_idx');
            $table->index('liquidacion_id', 'bvacl_liquidacion_idx');

            $table->foreign('order_id', 'bvacl_order_fk')->references('id')->on('orders')->restrictOnDelete();
            $table->foreign('order_item_id', 'bvacl_item_fk')->references('id')->on('order_items')->restrictOnDelete();
            $table->foreign('acuerdo_id', 'bvacl_acuerdo_fk')->references('id')->on('bylopez_va_comision_acuerdos')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE bylopez_va_comision_lineas ADD CONSTRAINT bvacl_estado_chk CHECK (estado IN ('estimada', 'habilitada', 'anulada'))");
        DB::statement("ALTER TABLE bylopez_va_comision_lineas ADD CONSTRAINT bvacl_habilitada_chk CHECK (estado <> 'habilitada' OR (habilitada_en IS NOT NULL AND monto IS NOT NULL AND unidades_comisionables IS NOT NULL))");
        // Una linea en una liquidacion siempre esta habilitada (la anulacion de una liquidada se corrige con un ajuste).
        DB::statement("ALTER TABLE bylopez_va_comision_lineas ADD CONSTRAINT bvacl_liquidacion_chk CHECK (liquidacion_id IS NULL OR estado = 'habilitada')");
        DB::statement("ALTER TABLE bylopez_va_comision_lineas ADD CONSTRAINT bvacl_anulada_chk CHECK ((estado = 'anulada') = (anulada_en IS NOT NULL AND motivo_anulacion IS NOT NULL))");
        DB::statement('ALTER TABLE bylopez_va_comision_lineas ADD CONSTRAINT bvacl_monto_chk CHECK (monto IS NULL OR monto >= 0)');

        Schema::create('bylopez_va_comision_eventos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('linea_id');
            $table->string('tipo', 40);
            $table->json('detalle')->nullable();
            $table->unsignedInteger('actor_admin_id')->nullable();
            $table->string('actor_name_snapshot', 191)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['linea_id', 'id'], 'bvace2_linea_idx');
            $table->foreign('linea_id', 'bvace2_linea_fk')->references('id')->on('bylopez_va_comision_lineas')->restrictOnDelete();
        });

        Schema::create('bylopez_va_umbral_movimientos', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('asesor_admin_id');
            $table->char('mes', 7);
            $table->unsignedInteger('order_id');
            $table->unsignedInteger('order_item_id');
            $table->foreignId('linea_id');
            $table->string('tipo', 30);
            $table->integer('unidades');
            $table->dateTime('confirmado_en');
            $table->string('idempotency_key', 120);
            $table->timestamp('created_at')->useCurrent();

            $table->unique('idempotency_key', 'bvaum_idem_unique');
            $table->index(['asesor_admin_id', 'mes'], 'bvaum_asesor_mes_idx');
            $table->index('linea_id', 'bvaum_linea_idx');
            $table->foreign('linea_id', 'bvaum_linea_fk')->references('id')->on('bylopez_va_comision_lineas')->restrictOnDelete();
            $table->foreign('order_id', 'bvaum_order_fk')->references('id')->on('orders')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE bylopez_va_umbral_movimientos ADD CONSTRAINT bvaum_tipo_chk CHECK (tipo IN ('venta', 'cancelacion', 'no_entregada', 'reverso_reasignacion', 'correccion'))");
        DB::statement("ALTER TABLE bylopez_va_umbral_movimientos ADD CONSTRAINT bvaum_signo_chk CHECK (unidades <> 0 AND ((tipo = 'venta') = (unidades > 0) OR tipo = 'correccion'))");

        Schema::create('bylopez_va_comision_meses', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('asesor_admin_id');
            $table->char('mes', 7);
            $table->foreignId('acuerdo_umbral_id')->nullable();
            $table->unsignedInteger('umbral_aplicado')->nullable();
            $table->dateTime('umbral_fijado_en')->nullable();
            $table->timestamps();

            $table->unique(['asesor_admin_id', 'mes'], 'bvacm_asesor_mes_unique');
            $table->foreign('acuerdo_umbral_id', 'bvacm_acuerdo_fk')->references('id')->on('bylopez_va_comision_acuerdos')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bylopez_va_comision_meses');
        Schema::dropIfExists('bylopez_va_umbral_movimientos');
        Schema::dropIfExists('bylopez_va_comision_eventos');
        Schema::dropIfExists('bylopez_va_comision_lineas');
    }
};
