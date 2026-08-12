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
        if (Schema::hasColumn('delivery_tier_settings', 'commission_percent')) {
            return;
        }

        Schema::table('delivery_tier_settings', function (Blueprint $table) {
            $table->decimal('commission_percent', 5, 2)->default(10.00)->after('active');
            $table->decimal('annual_reward_amount', 12, 2)->default(0)->after('commission_percent');
            $table->integer('min_active_agents')->default(0)->after('annual_reward_amount');
            $table->integer('min_subordinate_tier')->default(0)->after('min_active_agents');
            $table->integer('subordinate_tier_level')->nullable()->after('min_subordinate_tier');
            $table->integer('min_deliveries')->default(0)->after('subordinate_tier_level');
            $table->integer('delivery_window_months')->default(12)->after('min_deliveries');
            $table->enum('territory_scope', ['none', 'community', 'lga', 'state', 'national'])->default('none')->after('delivery_window_months');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('delivery_tier_settings', function (Blueprint $table) {
            $table->dropColumn([
                'commission_percent',
                'annual_reward_amount',
                'min_active_agents',
                'min_subordinate_tier',
                'subordinate_tier_level',
                'min_deliveries',
                'delivery_window_months',
                'territory_scope',
            ]);
        });
    }
};
