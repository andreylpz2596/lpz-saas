<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Domicilios locales F1 — ZONAS y TARIFAS SUGERIDAS (ADITIVA, tablas propias).
 *
 * - Zona = conjunto de municipios DIVIPOLA (Bogota = 11001, Soacha = 25754). `municipio_codigo` es CHAR(5) (conserva el
 *   cero inicial) y es UNIQUE: un municipio pertenece a una sola zona, asi la zona de un pedido nunca es ambigua. Sin FK
 *   hacia el catalogo geografico de VentasAsesor: Logistica no depende de sus tablas (la lectura va por contrato).
 * - Tarifa = valor SUGERIDO por transportadora y zona, con `vigente_desde`. No se edita: un cambio es una fila nueva, y
 *   la vigente es la de `vigente_desde` mas reciente <= hoy. El costo real de cada despacho queda en la guia (snapshot),
 *   asi que cambiar una tarifa nunca altera despachos historicos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_lg_zonas', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 40);
            $table->string('nombre', 120);
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique('codigo', 'bylgz_codigo_unique');
        });

        Schema::create('bylopez_lg_zona_municipios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('zona_id');
            $table->char('municipio_codigo', 5);
            $table->timestamps();

            $table->unique('municipio_codigo', 'bylgzm_municipio_unique');
            $table->foreign('zona_id', 'bylgzm_zona_fk')->references('id')->on('bylopez_lg_zonas')->restrictOnDelete();
        });

        Schema::create('bylopez_lg_tarifas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transportadora_id');
            $table->foreignId('zona_id');
            $table->decimal('valor', 12, 2);
            $table->date('vigente_desde');
            $table->unsignedInteger('creado_por_admin_id')->nullable();
            $table->string('creado_por_name_snapshot', 191)->nullable();
            $table->timestamps();

            $table->unique(['transportadora_id', 'zona_id', 'vigente_desde'], 'bylgtf_vigencia_unique');
            $table->foreign('transportadora_id', 'bylgtf_transportadora_fk')->references('id')->on('bylopez_lg_transportadoras')->restrictOnDelete();
            $table->foreign('zona_id', 'bylgtf_zona_fk')->references('id')->on('bylopez_lg_zonas')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE bylopez_lg_tarifas ADD CONSTRAINT bylgtf_valor_chk CHECK (valor >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('bylopez_lg_tarifas');
        Schema::dropIfExists('bylopez_lg_zona_municipios');
        Schema::dropIfExists('bylopez_lg_zonas');
    }
};
