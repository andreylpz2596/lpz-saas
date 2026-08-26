<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bylopez_cc_proveedor_tela_tipos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proveedor_tela_id')->constrained('bylopez_cc_proveedores_tela')->cascadeOnDelete();
            $table->unsignedInteger('tipo_tela_id');
            $table->timestamps();

            $table->foreign('tipo_tela_id')->references('id')->on('attribute_options')->restrictOnDelete();
            $table->unique(['proveedor_tela_id', 'tipo_tela_id'], 'bylopez_cc_proveedor_tela_tipos_unique');
        });

        Schema::create('bylopez_cc_proveedor_tela_referencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proveedor_tela_id')->constrained('bylopez_cc_proveedores_tela')->cascadeOnDelete();
            $table->unsignedInteger('tipo_tela_id');
            $table->string('color');
            $table->string('referencia');
            $table->string('gramaje');
            $table->decimal('valor_kilo_referencia', 12, 2)->nullable();
            $table->string('estado')->default('activo')->index();
            $table->timestamps();

            $table->foreign('tipo_tela_id')->references('id')->on('attribute_options')->restrictOnDelete();
            $table->index(['proveedor_tela_id', 'tipo_tela_id'], 'bylopez_cc_proveedor_tela_referencias_lookup');
        });

        Schema::table('bylopez_cc_rollos', function (Blueprint $table) {
            $table->foreignId('proveedor_tela_referencia_id')
                ->nullable()
                ->after('compra_tela_id')
                ->constrained('bylopez_cc_proveedor_tela_referencias')
                ->restrictOnDelete();

            $table->string('referencia')->nullable()->after('color');
        });

        $now = now();
        $tipoTelaAttributeId = DB::table('attributes')->where('code', 'tipo_tela')->value('id');

        if ($tipoTelaAttributeId) {
            DB::table('bylopez_cc_proveedores_tela')
                ->whereNotNull('tipo')
                ->orderBy('id')
                ->get(['id', 'tipo'])
                ->each(function ($proveedor) use ($now, $tipoTelaAttributeId) {
                    if (! is_numeric($proveedor->tipo)) {
                        return;
                    }

                    $tipoTelaId = (int) $proveedor->tipo;
                    $exists = DB::table('attribute_options')
                        ->where('id', $tipoTelaId)
                        ->where('attribute_id', $tipoTelaAttributeId)
                        ->exists();

                    if (! $exists) {
                        return;
                    }

                    DB::table('bylopez_cc_proveedor_tela_tipos')->updateOrInsert(
                        [
                            'proveedor_tela_id' => $proveedor->id,
                            'tipo_tela_id'      => $tipoTelaId,
                        ],
                        [
                            'created_at' => $now,
                            'updated_at' => $now,
                        ],
                    );
                });
        }
    }

    public function down(): void
    {
        Schema::table('bylopez_cc_rollos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('proveedor_tela_referencia_id');
            $table->dropColumn('referencia');
        });

        Schema::dropIfExists('bylopez_cc_proveedor_tela_referencias');
        Schema::dropIfExists('bylopez_cc_proveedor_tela_tipos');
    }
};
