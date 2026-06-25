<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'is_delivery_agent')) {
                $table->boolean('is_delivery_agent')->default(false)->after('user_type');
            }

            if (!Schema::hasColumn('users', 'delivery_agent_application_status')) {
                // null | pending | rejected (no "approved" stored — approval flips is_delivery_agent)
                $table->string('delivery_agent_application_status')->nullable()->after('is_delivery_agent');
            }

            if (!Schema::hasColumn('users', 'delivery_tier')) {
                $table->unsignedTinyInteger('delivery_tier')->default(1)->after('delivery_agent_application_status');
            }

            if (!Schema::hasColumn('users', 'delivery_tier_name')) {
                $table->string('delivery_tier_name')->default('Tier 1')->after('delivery_tier');
            }

            if (!Schema::hasColumn('users', 'security_wallet_deposit')) {
                $table->decimal('security_wallet_deposit', 10, 2)->default(0)->after('delivery_tier_name');
            }

            // Tier 1 -> Tier 2 upgrade request tracking
            if (!Schema::hasColumn('users', 'tier_upgrade_status')) {
                // null | pending | rejected (approval bumps delivery_tier directly)
                $table->string('tier_upgrade_status')->nullable()->after('security_wallet_deposit');
            }

            if (!Schema::hasColumn('users', 'tier_upgrade_requested_at')) {
                $table->timestamp('tier_upgrade_requested_at')->nullable()->after('tier_upgrade_status');
            }

            if (!Schema::hasColumn('users', 'tier_upgrade_completed_deliveries_snapshot')) {
                $table->unsignedInteger('tier_upgrade_completed_deliveries_snapshot')->nullable()->after('tier_upgrade_requested_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $columns = [
                'is_delivery_agent',
                'delivery_agent_application_status',
                'delivery_tier',
                'delivery_tier_name',
                'security_wallet_deposit',
                'tier_upgrade_status',
                'tier_upgrade_requested_at',
                'tier_upgrade_completed_deliveries_snapshot',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};