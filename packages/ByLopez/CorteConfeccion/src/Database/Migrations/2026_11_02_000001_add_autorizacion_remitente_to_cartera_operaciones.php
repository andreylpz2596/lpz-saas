<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Lectura local de comprobantes: algunos comprobantes traen dos codigos distintos (ID de la transaccion y codigo de autorizacion
| o aprobacion) y un remitente. Se guardan por separado; son columnas nuevas, anulables, sin tocar datos existentes.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bylopez_cc_cartera_operaciones', function (Blueprint $table) {
            $table->string('autorizacion', 120)->nullable()->after('referencia');
            $table->string('remitente')->nullable()->after('contraparte');
        });
    }

    public function down(): void
    {
        Schema::table('bylopez_cc_cartera_operaciones', function (Blueprint $table) {
            $table->dropColumn(['autorizacion', 'remitente']);
        });
    }
};
