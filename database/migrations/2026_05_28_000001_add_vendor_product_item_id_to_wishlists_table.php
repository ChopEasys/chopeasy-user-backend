<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add column safely (ignore if already exists)
        if (!Schema::hasColumn('wishlists', 'vendor_product_item_id')) {
            Schema::table('wishlists', function (Blueprint $table) {
                $table->unsignedBigInteger('vendor_product_item_id')
                    ->nullable()
                    ->after('product_id');
            });
        }

        // 2. Add nullable-safe unique indexes for the specific-item identity.
        //    Existing (user_id, product_id) / (session_id, product_id) unique
        //    constraints are left intact so legacy/NULL-item rows behave as today.
        //    Nullable columns allow multiple NULLs in MySQL, so legacy rows are unaffected.
        if (Schema::hasColumn('wishlists', 'vendor_product_item_id')) {
            Schema::table('wishlists', function (Blueprint $table) {
                // ignore failure if the index already exists (idempotent re-run)
                try {
                    $table->unique(
                        ['user_id', 'vendor_product_item_id'],
                        'wishlists_user_vendor_product_item_unique'
                    );
                } catch (\Throwable $e) {}

                try {
                    $table->unique(
                        ['session_id', 'vendor_product_item_id'],
                        'wishlists_session_vendor_product_item_unique'
                    );
                } catch (\Throwable $e) {}
            });
        }
    }

    public function down(): void
    {
        Schema::table('wishlists', function (Blueprint $table) {

            // Drop the new unique indexes (guarded to avoid crash if missing)
            try {
                $table->dropUnique('wishlists_user_vendor_product_item_unique');
            } catch (\Throwable $e) {}

            try {
                $table->dropUnique('wishlists_session_vendor_product_item_unique');
            } catch (\Throwable $e) {}

            // Drop the column only if it exists
            if (Schema::hasColumn('wishlists', 'vendor_product_item_id')) {
                $table->dropColumn('vendor_product_item_id');
            }
        });
    }
};
