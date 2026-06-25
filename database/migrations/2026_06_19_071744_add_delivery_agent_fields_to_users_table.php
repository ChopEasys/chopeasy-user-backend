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
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_delivery_agent')->default(false)->after('onboarding_completed');
            $table->string('delivery_agent_application_status')->default('pending')->after('is_delivery_agent');
            $table->string('delivery_agent_tier')->default('tier_1')->after('delivery_agent_application_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_delivery_agent', 'delivery_agent_application_status', 'delivery_agent_tier']);
        });
    }
};
