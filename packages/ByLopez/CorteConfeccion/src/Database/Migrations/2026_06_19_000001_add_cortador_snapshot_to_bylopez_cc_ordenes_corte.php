<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('bylopez_cc_ordenes_corte', 'cortador_nombre_snapshot')) {
            Schema::table('bylopez_cc_ordenes_corte', function (Blueprint $table) {
                $table->string('cortador_nombre_snapshot')->nullable()->after('cortador_nombre');
            });
        }

        DB::table('bylopez_cc_ordenes_corte')
            ->whereNull('cortador_nombre_snapshot')
            ->whereNotNull('cortador_nombre')
            ->update([
                'cortador_nombre_snapshot' => DB::raw('cortador_nombre'),
            ]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('bylopez_cc_ordenes_corte', 'cortador_nombre_snapshot')) {
            Schema::table('bylopez_cc_ordenes_corte', function (Blueprint $table) {
                $table->dropColumn('cortador_nombre_snapshot');
            });
        }
    }
};
