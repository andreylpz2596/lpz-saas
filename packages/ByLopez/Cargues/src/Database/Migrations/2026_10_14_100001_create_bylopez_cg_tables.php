<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * CARGUES E1 — tablas FIJAS del modulo (07 §3.1), en la conexion principal. Ninguna tabla por tipo: las intermedias las
 * crea el motor dinamicamente (bylopez_cgi_*) en la conexion del tipo.
 *
 *  T1 tipos · T2 versiones del tipo (inmutables al activarse) · T3 estructuras dinamicas · T4 comparaciones guardadas
 *  T5 cargas (cabecera + progreso + ciclo de ejecucion de E2) · T6 datos originales por fila · T7 incidencias por fila/campo
 *
 * El original de cada fila se guarda sin normalizar (T6.datos_originales); lo normalizado va aparte (datos_normalizados).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_cg_tipos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 191);
            $table->string('descripcion', 500)->nullable();
            $table->boolean('activo')->default(false);
            $table->unsignedBigInteger('version_vigente_id')->nullable();
            $table->unsignedInteger('creado_por_admin_id');
            $table->string('creado_por_nombre', 191);
            $table->timestamps();

            $table->unique('nombre', 'bylcgt_nombre_unique');
        });

        Schema::create('bylopez_cg_estructuras', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tipo_id');
            $table->string('conexion', 64);
            $table->string('nombre_tabla', 64);
            $table->char('estructura_huella', 64);
            $table->longText('ddl_auditoria')->nullable();
            // activa | archivada | en_cambio (cambio estructural a medias: ver reconciliacion, 07 §5.4)
            $table->string('estado', 20);
            $table->string('archivada_como', 64)->nullable();
            $table->string('nombre_nueva', 64)->nullable();
            $table->unsignedBigInteger('version_pendiente_id')->nullable();
            $table->timestamps();

            $table->index(['conexion', 'nombre_tabla', 'estado'], 'bylcge_tabla_idx');
            $table->index('tipo_id', 'bylcge_tipo_idx');
            $table->foreign('tipo_id', 'bylcge_tipo_fk')->references('id')->on('bylopez_cg_tipos')->restrictOnDelete();
        });

        Schema::create('bylopez_cg_tipo_versiones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tipo_id');
            $table->unsignedInteger('numero');
            // borrador | activa | retirada. Una version activa o retirada NO se edita.
            $table->string('estado', 20)->default('borrador');
            $table->string('conexion', 64);
            $table->string('modo', 20);
            $table->string('tabla_destino', 64)->nullable();
            $table->string('procedimiento', 64)->nullable();
            $table->string('accion', 40);
            $table->json('map');
            $table->char('map_huella', 64);
            // Obligatoria para ACTIVAR, sin valor por defecto: bloquear_todo | procesar_validas | NULL (sin elegir).
            $table->string('politica_errores', 20)->nullable();
            $table->json('limites')->nullable();
            $table->unsignedBigInteger('estructura_id')->nullable();
            $table->longText('procedimiento_definicion')->nullable();
            $table->char('procedimiento_huella', 64)->nullable();
            // Revision AUTOMATICA del contrato del procedimiento + confirmacion HUMANA ligada a su huella.
            $table->json('revision_contrato')->nullable();
            $table->unsignedInteger('revision_admin_id')->nullable();
            $table->string('revision_admin_nombre', 191)->nullable();
            $table->timestamp('revision_en')->nullable();
            $table->char('revision_huella', 64)->nullable();
            $table->unsignedInteger('actor_admin_id');
            $table->string('actor_nombre', 191);
            $table->timestamp('activada_en')->nullable();
            $table->timestamps();

            $table->unique(['tipo_id', 'numero'], 'bylcgv_tipo_numero_unique');
            $table->index(['tipo_id', 'estado'], 'bylcgv_tipo_estado_idx');
            $table->foreign('tipo_id', 'bylcgv_tipo_fk')->references('id')->on('bylopez_cg_tipos')->restrictOnDelete();
            $table->foreign('estructura_id', 'bylcgv_estructura_fk')->references('id')->on('bylopez_cg_estructuras')->restrictOnDelete();
        });

        Schema::create('bylopez_cg_comparaciones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tipo_id');
            $table->unsignedBigInteger('tipo_version_id');
            $table->string('nombre', 191);
            $table->char('firma_encabezados', 64);
            $table->json('relaciones');
            $table->boolean('predeterminada')->default(false);
            $table->unsignedInteger('creada_por_admin_id');
            $table->string('creada_por_nombre', 191);
            $table->timestamps();

            $table->index(['tipo_id', 'firma_encabezados'], 'bylcgc_tipo_firma_idx');
            $table->foreign('tipo_id', 'bylcgc_tipo_fk')->references('id')->on('bylopez_cg_tipos')->cascadeOnDelete();
            $table->foreign('tipo_version_id', 'bylcgc_version_fk')->references('id')->on('bylopez_cg_tipo_versiones')->cascadeOnDelete();
        });

        Schema::create('bylopez_cg_cargas', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36);
            $table->unsignedBigInteger('tipo_id');
            $table->unsignedBigInteger('tipo_version_id');
            $table->unsignedInteger('admin_id');
            $table->string('admin_nombre', 191);
            $table->string('estado', 30);
            $table->string('etapa', 30)->nullable();
            $table->string('accion', 40);

            // Archivo (disco privado, nombre UUID) y parametros de lectura elegidos por quien carga.
            $table->string('archivo_ruta', 255);
            $table->string('archivo_nombre', 255);
            $table->char('archivo_hash', 64);
            $table->unsignedBigInteger('archivo_bytes');
            $table->string('formato', 10);
            $table->string('hoja', 191)->nullable();
            $table->unsignedInteger('fila_encabezado')->default(1);
            $table->string('delimitador', 5)->nullable();
            $table->string('entrecomillado', 5)->nullable();
            $table->string('codificacion', 20)->nullable();
            $table->json('encabezados')->nullable();
            $table->json('relaciones')->nullable();
            // Copia CONGELADA de la configuracion del tipo (version, map, politica, limites) al validar.
            $table->json('config_congelada')->nullable();
            $table->unsignedBigInteger('comparacion_id')->nullable();

            // Progreso consultable (lo escribe el job fuera de la transaccion del lote).
            $table->unsignedBigInteger('filas_total')->nullable();
            $table->unsignedBigInteger('filas_procesadas')->default(0);
            $table->timestamp('progreso_actualizado_en')->nullable();
            $table->string('progreso_mensaje', 255)->nullable();
            $table->char('job_uuid', 36)->nullable();

            // Ciclo de ejecucion (E2): columnas reservadas, sin uso en E1.
            $table->char('ejecucion_token', 64)->nullable();
            $table->unsignedBigInteger('conexion_ejecutor')->nullable();
            $table->string('bloqueo_nombre', 64)->nullable();
            $table->char('huella_entrada', 64)->nullable();

            $table->json('contadores')->nullable();
            $table->json('resultado')->nullable();
            $table->text('mensaje')->nullable();
            $table->timestamp('encolada_en')->nullable();
            $table->timestamp('iniciada_en')->nullable();
            $table->timestamp('finalizada_en')->nullable();
            $table->timestamps();

            $table->unique('uuid', 'bylcgg_uuid_unique');
            $table->index(['tipo_id', 'estado'], 'bylcgg_tipo_estado_idx');
            $table->index(['estado', 'progreso_actualizado_en'], 'bylcgg_estado_progreso_idx');
            $table->index('admin_id', 'bylcgg_admin_idx');
            $table->foreign('tipo_id', 'bylcgg_tipo_fk')->references('id')->on('bylopez_cg_tipos')->restrictOnDelete();
            $table->foreign('tipo_version_id', 'bylcgg_version_fk')->references('id')->on('bylopez_cg_tipo_versiones')->restrictOnDelete();
            $table->foreign('comparacion_id', 'bylcgg_comparacion_fk')->references('id')->on('bylopez_cg_comparaciones')->nullOnDelete();
        });

        Schema::create('bylopez_cg_carga_datos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('carga_id');
            $table->unsignedInteger('fila_origen');
            // ORIGINAL sin normalizar (ver LectorArchivo: null = celda ausente, "" = texto vacio, espacios conservados).
            $table->longText('datos_originales');
            $table->longText('datos_normalizados')->nullable();
            // pendiente | vacia | rechazada (E2 agrega los estados de ejecucion)
            $table->string('estado_final', 20)->default('pendiente');
            $table->string('detalle', 255)->nullable();

            $table->unique(['carga_id', 'fila_origen'], 'bylcgd_carga_fila_unique');
            $table->index(['carga_id', 'estado_final'], 'bylcgd_carga_estado_idx');
            $table->foreign('carga_id', 'bylcgd_carga_fk')->references('id')->on('bylopez_cg_cargas')->cascadeOnDelete();
        });

        Schema::create('bylopez_cg_carga_incidencias', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('carga_id');
            $table->unsignedBigInteger('dato_id')->nullable();
            $table->unsignedInteger('fila_origen')->nullable();
            // lectura | validacion_motor | validacion_procedimiento | ejecucion
            $table->string('etapa', 30);
            $table->string('campo', 191)->default('');
            $table->string('codigo', 60);
            $table->string('motivo', 500);
            $table->string('severidad', 20)->default('error');
            $table->timestamp('created_at')->nullable();

            // Idempotente: repetir una etapa no duplica la incidencia.
            $table->unique(['carga_id', 'fila_origen', 'etapa', 'codigo', 'campo'], 'bylcgi_incidencia_unique');
            $table->index(['carga_id', 'codigo'], 'bylcgi_carga_codigo_idx');
            $table->foreign('carga_id', 'bylcgi_carga_fk')->references('id')->on('bylopez_cg_cargas')->cascadeOnDelete();
        });
    }

    /** Con cargas reales, revertir borra la auditoria de los cargues: no hacerlo en produccion. */
    public function down(): void
    {
        Schema::dropIfExists('bylopez_cg_carga_incidencias');
        Schema::dropIfExists('bylopez_cg_carga_datos');
        Schema::dropIfExists('bylopez_cg_cargas');
        Schema::dropIfExists('bylopez_cg_comparaciones');
        Schema::dropIfExists('bylopez_cg_tipo_versiones');
        Schema::dropIfExists('bylopez_cg_estructuras');
        Schema::dropIfExists('bylopez_cg_tipos');
    }
};
