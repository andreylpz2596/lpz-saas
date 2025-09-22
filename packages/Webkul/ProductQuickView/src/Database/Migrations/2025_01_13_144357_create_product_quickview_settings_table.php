<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('product_quickview_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('show_full_description')->default(true);
            $table->boolean('show_product_number')->default(true);
            $table->boolean('show_quantity')->default(true);
            $table->boolean('show_sku')->default(true);
            $table->timestamps();
        });
        
        // Insert default settings
        DB::table('product_quickview_settings')->insert([
            'show_full_description' => true,
            'show_product_number' => true,
            'show_quantity' => true,
            'show_sku' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_quickview_settings');
    }
};
