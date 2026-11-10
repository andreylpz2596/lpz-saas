<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cartera de talleres (satelites) y cortadores. Aditiva; no toca datos historicos ni ninguna tabla existente.
 *
 * Principios (ver docs/confeccion/08_CARTERA_PRESTADORES.md):
 *  - El dinero vive en UN solo registro (movimiento). Aplicar un anticipo crea otra aplicacion del mismo movimiento.
 *  - Nada se edita ni se borra: los reversos conservan la operacion original, el motivo y el responsable.
 *  - Saldos y estados de pago son DERIVADOS; no se almacenan.
 *  - El arranque (fecha) es configuracion; todo lo anterior entra como saldo inicial por persona.
 *  - Los soportes son privados; la lectura asistida solo prepara un borrador (nunca confirma dinero).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_cc_cartera_config', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 60)->unique();
            $table->string('valor')->nullable();
            $table->unsignedInteger('actualizado_por')->nullable();
            $table->timestamps();

            $table->foreign('actualizado_por', 'bcccfg_actualizado_por_fk')->references('id')->on('admins')->nullOnDelete();
        });

        Schema::create('bylopez_cc_cartera_liquidaciones', function (Blueprint $table) {
            $table->id();
            $table->string('codigo')->unique();
            $table->foreignId('taller_id')->nullable()->constrained('bylopez_cc_talleres', 'id', 'bcccl_taller_fk')->restrictOnDelete();
            $table->foreignId('cortador_id')->nullable()->constrained('bylopez_cc_cortadores', 'id', 'bcccl_cortador_fk')->restrictOnDelete();
            $table->date('fecha');
            $table->decimal('trabajo_reconocido', 14, 2);
            $table->decimal('abonos_aplicados', 14, 2);
            $table->decimal('descuento_prestamo', 14, 2)->default(0);
            $table->decimal('queda_por_pagar', 14, 2);
            // Cifras y ordenes cubiertas congeladas al confirmar (el corte es inmutable).
            $table->json('cifras')->nullable();
            $table->text('observacion')->nullable();
            $table->string('estado', 20)->default('registrado')->index(); // registrado | reversado
            $table->unsignedInteger('creado_por')->nullable();
            $table->dateTime('reversado_en')->nullable();
            $table->unsignedInteger('reversado_por')->nullable();
            $table->text('motivo_reverso')->nullable();
            $table->string('idempotency_key', 100)->unique();
            $table->timestamps();

            $table->foreign('creado_por', 'bcccl_creado_por_fk')->references('id')->on('admins')->nullOnDelete();
            $table->foreign('reversado_por', 'bcccl_reversado_por_fk')->references('id')->on('admins')->nullOnDelete();
        });

        Schema::create('bylopez_cc_cartera_movimientos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo')->unique();
            // abono | prestamo | devolucion | saldo_inicial_trabajo | saldo_inicial_anticipo | saldo_inicial_prestamo
            $table->string('tipo', 40)->index();
            $table->foreignId('taller_id')->nullable()->constrained('bylopez_cc_talleres', 'id', 'bcccm_taller_fk')->restrictOnDelete();
            $table->foreignId('cortador_id')->nullable()->constrained('bylopez_cc_cortadores', 'id', 'bcccm_cortador_fk')->restrictOnDelete();
            $table->decimal('valor', 14, 2);
            $table->date('fecha_efectiva');
            $table->string('medio', 40)->nullable(); // nequi | transferencia | efectivo | daviplata | otro
            $table->string('referencia')->nullable();
            $table->text('observacion')->nullable();
            // 'total' = solo valor + cantidad de transferencias; 'detalle' = una operacion por transferencia.
            $table->string('modo_detalle', 10)->default('total');
            $table->unsignedInteger('num_transferencias')->nullable();
            // En una devolucion: el prestamo que reduce.
            $table->unsignedBigInteger('prestamo_id')->nullable()->index();
            $table->string('estado', 20)->default('registrado')->index(); // registrado | reversado
            $table->unsignedInteger('registrado_por')->nullable();
            $table->dateTime('reversado_en')->nullable();
            $table->unsignedInteger('reversado_por')->nullable();
            $table->text('motivo_reverso')->nullable();
            $table->string('idempotency_key', 100)->unique();
            $table->char('payload_hash', 64);
            $table->timestamps();

            $table->index(['taller_id', 'tipo', 'estado'], 'bcccm_taller_tipo_idx');
            $table->index(['cortador_id', 'tipo', 'estado'], 'bcccm_cortador_tipo_idx');
            $table->foreign('registrado_por', 'bcccm_registrado_por_fk')->references('id')->on('admins')->nullOnDelete();
            $table->foreign('reversado_por', 'bcccm_reversado_por_fk')->references('id')->on('admins')->nullOnDelete();
            $table->foreign('prestamo_id', 'bcccm_prestamo_fk')->references('id')->on('bylopez_cc_cartera_movimientos')->restrictOnDelete();
        });

        Schema::create('bylopez_cc_cartera_soportes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('taller_id')->nullable()->constrained('bylopez_cc_talleres', 'id', 'bcccs_taller_fk')->restrictOnDelete();
            $table->foreignId('cortador_id')->nullable()->constrained('bylopez_cc_cortadores', 'id', 'bcccs_cortador_fk')->restrictOnDelete();
            $table->foreignId('movimiento_id')->nullable()->constrained('bylopez_cc_cartera_movimientos', 'id', 'bcccs_movimiento_fk')->restrictOnDelete();
            $table->string('ruta'); // disco privado, nunca publico
            $table->string('nombre_original');
            $table->string('mime', 100);
            $table->unsignedInteger('tamano');
            $table->char('sha256', 64)->index(); // huella del archivo: reconoce el mismo adjunto aunque cambie de nombre
            // sin_leer | leida | error | no_disponible
            $table->string('lectura_estado', 20)->default('sin_leer');
            $table->string('lectura_proveedor', 60)->nullable();
            $table->json('lectura_resultado')->nullable();
            $table->text('lectura_error')->nullable();
            $table->unsignedInteger('subido_por')->nullable();
            $table->timestamps();

            $table->foreign('subido_por', 'bcccs_subido_por_fk')->references('id')->on('admins')->nullOnDelete();
        });

        Schema::create('bylopez_cc_cartera_operaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movimiento_id')->constrained('bylopez_cc_cartera_movimientos', 'id', 'bccco_movimiento_fk')->restrictOnDelete();
            $table->foreignId('soporte_id')->nullable()->constrained('bylopez_cc_cartera_soportes', 'id', 'bccco_soporte_fk')->restrictOnDelete();
            $table->unsignedSmallInteger('soporte_pagina')->nullable();
            $table->decimal('valor', 14, 2);
            $table->char('moneda', 3)->default('COP');
            $table->string('direccion', 10)->default('egreso'); // egreso (pago a prestador) | ingreso
            $table->date('fecha_operacion')->nullable();
            $table->time('hora_operacion')->nullable();
            $table->string('banco_origen', 80)->nullable();
            $table->string('banco_destino', 80)->nullable();
            $table->string('medio', 40)->nullable();
            $table->string('referencia')->nullable();
            $table->string('contraparte')->nullable();
            // Datos normalizados: referencia + contexto. NULL si falta algun dato de contexto (no hay certeza).
            $table->char('huella', 64)->nullable()->index();
            // Igual a la huella solo mientras la operacion cuenta (aceptada y movimiento registrado). UNIQUE = bloqueo duro,
            // tambien frente a doble clic y a dos usuarios simultaneos. Al reversar el movimiento queda NULL.
            $table->char('huella_activa', 64)->nullable()->unique('bccco_huella_activa_unique');
            $table->string('estado', 20)->default('aceptada'); // aceptada | descartada
            $table->unsignedBigInteger('duplicado_de_id')->nullable()->index();
            $table->text('motivo_duplicado')->nullable(); // motivo auditado si se continuo pese a una coincidencia dudosa
            $table->timestamps();

            $table->foreign('duplicado_de_id', 'bccco_duplicado_fk')->references('id')->on('bylopez_cc_cartera_operaciones')->nullOnDelete();
        });

        Schema::create('bylopez_cc_cartera_aplicaciones', function (Blueprint $table) {
            $table->id();
            // Abono / anticipo (concepto pago_trabajo) o prestamo (concepto descuento_prestamo).
            $table->foreignId('movimiento_id')->constrained('bylopez_cc_cartera_movimientos', 'id', 'bcccap_movimiento_fk')->restrictOnDelete();
            $table->string('concepto', 30)->index(); // pago_trabajo | descuento_prestamo
            $table->foreignId('orden_confeccion_id')->nullable()->constrained('bylopez_cc_ordenes_confeccion', 'id', 'bcccap_orden_conf_fk')->restrictOnDelete();
            $table->foreignId('orden_corte_id')->nullable()->constrained('bylopez_cc_ordenes_corte', 'id', 'bcccap_orden_corte_fk')->restrictOnDelete();
            // Pago contra la deuda previa por trabajo (saldo inicial), sin orden.
            $table->foreignId('saldo_inicial_id')->nullable()->constrained('bylopez_cc_cartera_movimientos', 'id', 'bcccap_saldo_ini_fk')->restrictOnDelete();
            $table->decimal('valor', 14, 2);
            $table->foreignId('liquidacion_id')->nullable()->constrained('bylopez_cc_cartera_liquidaciones', 'id', 'bcccap_liquidacion_fk')->restrictOnDelete();
            $table->dateTime('anulada_en')->nullable();
            $table->unsignedInteger('anulada_por')->nullable();
            $table->text('motivo_anulacion')->nullable();
            $table->unsignedInteger('creado_por')->nullable();
            $table->timestamps();

            $table->index(['orden_confeccion_id', 'anulada_en'], 'bcccap_oc_idx');
            $table->index(['orden_corte_id', 'anulada_en'], 'bcccap_ocorte_idx');
            $table->foreign('anulada_por', 'bcccap_anulada_por_fk')->references('id')->on('admins')->nullOnDelete();
            $table->foreign('creado_por', 'bcccap_creado_por_fk')->references('id')->on('admins')->nullOnDelete();
        });

        // Bitacora de lo que no se puede deducir de las demas tablas: lecturas de comprobantes, corte, reversos.
        // (La bitacora general `bylopez_cc_cuenta_eventos` ya existe y es append-only; se reutiliza.)

        foreach ([
            'bylopez_cc_cartera_movimientos' => ['bcccm_valor_chk' => 'valor > 0', 'bcccm_un_prestador_chk' => '(taller_id IS NULL) <> (cortador_id IS NULL)'],
            'bylopez_cc_cartera_operaciones' => ['bccco_valor_chk' => 'valor > 0'],
            'bylopez_cc_cartera_aplicaciones' => ['bcccap_valor_chk' => 'valor > 0'],
            'bylopez_cc_cartera_liquidaciones' => ['bcccl_un_prestador_chk' => '(taller_id IS NULL) <> (cortador_id IS NULL)'],
        ] as $tabla => $restricciones) {
            foreach ($restricciones as $nombre => $condicion) {
                DB::statement("ALTER TABLE {$tabla} ADD CONSTRAINT {$nombre} CHECK ({$condicion})");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bylopez_cc_cartera_aplicaciones');
        Schema::dropIfExists('bylopez_cc_cartera_operaciones');
        Schema::dropIfExists('bylopez_cc_cartera_soportes');
        Schema::dropIfExists('bylopez_cc_cartera_movimientos');
        Schema::dropIfExists('bylopez_cc_cartera_liquidaciones');
        Schema::dropIfExists('bylopez_cc_cartera_config');
    }
};
