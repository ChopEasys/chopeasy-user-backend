<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'ambassador_badge_tier')) {
                $table->unsignedTinyInteger('ambassador_badge_tier')->nullable()->after('delivery_agent_tier');
            }

            if (!Schema::hasColumn('users', 'ambassador_promoted_at')) {
                $table->timestamp('ambassador_promoted_at')->nullable()->after('ambassador_badge_tier');
            }

            if (!Schema::hasColumn('users', 'ambassador_territory_id')) {
                $table->unsignedBigInteger('ambassador_territory_id')->nullable()->after('ambassador_promoted_at');
            }
        });

        // Add foreign key only if the ambassador_territories table exists
        if (Schema::hasTable('ambassador_territories') && Schema::hasColumn('users', 'ambassador_territory_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreign('ambassador_territory_id')
                    ->references('id')
                    ->on('ambassador_territories')
                    ->onDelete('set null');
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Drop foreign key if it exists
            if (Schema::hasTable('ambassador_territories')) {
                $table->dropForeign(['ambassador_territory_id']);
            }

            $columns = [
                'ambassador_badge_tier',
                'ambassador_promoted_at',
                'ambassador_territory_id',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
