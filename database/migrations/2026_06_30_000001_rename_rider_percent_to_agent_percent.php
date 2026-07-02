<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_commission_settings', function (Blueprint $table) {
            $table->renameColumn('rider_percent', 'agent_percent');
        });

        Schema::table('agent_referral_commission_counters', function (Blueprint $table) {
            $table->string('referral_kind', 16)->default('agent')->change();
        });
    }

    public function down(): void
    {
        Schema::table('agent_commission_settings', function (Blueprint $table) {
            $table->renameColumn('agent_percent', 'rider_percent');
        });

        Schema::table('agent_referral_commission_counters', function (Blueprint $table) {
            $table->string('referral_kind', 16)->default('vendor')->change();
        });
    }
};
