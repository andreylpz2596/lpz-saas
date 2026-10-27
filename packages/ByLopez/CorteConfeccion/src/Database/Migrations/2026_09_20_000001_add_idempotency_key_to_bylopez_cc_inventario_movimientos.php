<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bylopez_cc_inventario_movimientos', function (Blueprint $table) {
            $table->string('idempotency_key')->nullable()->after('observacion');
            $table->unique('idempotency_key', 'bylopez_cc_inv_mov_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('bylopez_cc_inventario_movimientos', function (Blueprint $table) {
            $table->dropUnique('bylopez_cc_inv_mov_idempotency_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
