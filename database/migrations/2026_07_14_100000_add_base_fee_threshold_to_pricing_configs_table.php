<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pricing_configs', function (Blueprint $table) {
            if (!Schema::hasColumn('pricing_configs', 'base_fee_threshold')) {
                $table->decimal('base_fee_threshold', 12, 2)
                    ->default(20000)
                    ->after('base_charge')
                    ->comment('Minimum customer subtotal for base fee to apply. Below this, no base fee is charged.');
            }
            if (!Schema::hasColumn('pricing_configs', 'no_base_fee_platform_percentage')) {
                $table->decimal('no_base_fee_platform_percentage', 5, 2)
                    ->default(60)
                    ->after('base_fee_threshold')
                    ->comment('Platform % of weight fee when base fee is NOT applied (order below threshold)');
            }
        });

        // Update existing configs: set threshold to 20000 and no-base-fee platform % to 60
        DB::table('pricing_configs')
            ->whereNull('base_fee_threshold')
            ->orWhere('base_fee_threshold', 0)
            ->update([
                'base_fee_threshold' => 20000,
                'no_base_fee_platform_percentage' => 60,
            ]);
    }

    public function down(): void
    {
        Schema::table('pricing_configs', function (Blueprint $table) {
            if (Schema::hasColumn('pricing_configs', 'base_fee_threshold')) {
                $table->dropColumn('base_fee_threshold');
            }
            if (Schema::hasColumn('pricing_configs', 'no_base_fee_platform_percentage')) {
                $table->dropColumn('no_base_fee_platform_percentage');
            }
        });
    }
};
