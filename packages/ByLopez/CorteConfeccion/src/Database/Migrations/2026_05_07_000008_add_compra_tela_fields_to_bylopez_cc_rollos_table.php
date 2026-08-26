<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bylopez_cc_rollos', function (Blueprint $table) {
            $table->foreignId('compra_tela_id')->nullable()->after('id')->constrained('bylopez_cc_compras_tela')->nullOnDelete();
            $table->string('gramaje')->nullable()->after('tipo_tela');
            $table->decimal('valor_kilo', 12, 2)->default(0)->after('peso_disponible');
        });
    }

    public function down(): void
    {
        Schema::table('bylopez_cc_rollos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('compra_tela_id');
            $table->dropColumn(['gramaje', 'valor_kilo']);
        });
    }
};
