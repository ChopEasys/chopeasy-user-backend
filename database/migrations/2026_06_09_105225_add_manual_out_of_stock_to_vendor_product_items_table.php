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
        Schema::table('vendor_product_items', function (Blueprint $table) {
            $table->boolean('manual_out_of_stock')->default(false)->after('quantity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vendor_product_items', function (Blueprint $table) {
            $table->dropColumn(['manual_out_of_stock']);
        });
    }
};
