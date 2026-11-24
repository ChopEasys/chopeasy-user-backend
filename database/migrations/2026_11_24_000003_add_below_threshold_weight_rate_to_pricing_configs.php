<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pricing_configs', function (Blueprint $table) {
            if (!Schema::hasColumn('pricing_configs', 'no_base_fee_weight_price_per_kg')) {
                $table->decimal('no_base_fee_weight_price_per_kg', 10, 2)->nullable()->after('no_base_fee_platform_percentage');
            }
        });

        // The previous unreleased change incorrectly replaced this existing setting with 5%.
        if (Schema::hasColumn('pricing_configs', 'no_base_fee_platform_percentage')) {
            DB::table('pricing_configs')->update(['no_base_fee_platform_percentage' => 60]);
        }
    }

    public function down(): void
    {
        Schema::table('pricing_configs', function (Blueprint $table) {
            if (Schema::hasColumn('pricing_configs', 'no_base_fee_weight_price_per_kg')) {
                $table->dropColumn('no_base_fee_weight_price_per_kg');
            }
        });
    }
};
