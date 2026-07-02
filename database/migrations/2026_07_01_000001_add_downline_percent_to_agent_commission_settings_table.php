<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_commission_settings', function (Blueprint $table) {
            $table->decimal('downline_percent', 5, 2)->default(15.00)->after('agent_percent');
        });

        // Update existing rows to have the default value
        DB::table('agent_commission_settings')
            ->whereNull('downline_percent')
            ->update(['downline_percent' => 15.00]);
    }

    public function down(): void
    {
        Schema::table('agent_commission_settings', function (Blueprint $table) {
            $table->dropColumn('downline_percent');
        });
    }
};
