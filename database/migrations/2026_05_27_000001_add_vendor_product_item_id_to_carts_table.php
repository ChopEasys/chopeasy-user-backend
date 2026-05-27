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
        if (!Schema::hasColumn('carts', 'vendor_product_item_id')) {
            Schema::table('carts', function (Blueprint $table) {
                $table->unsignedBigInteger('vendor_product_item_id')
                    ->nullable()
                    ->after('product_variant_id');
            });
        }

        // 2. Backfill safely (only if column exists)
        if (Schema::hasColumn('carts', 'vendor_product_item_id')) {
            DB::table('carts')->update([
                'vendor_product_item_id' => DB::raw(
                    "JSON_UNQUOTE(JSON_EXTRACT(product_snapshot, '$.vendor_product_item_id'))"
                ),
            ]);
        }

        // 3. Drop and recreate indexes WITHOUT touching FK
        Schema::table('carts', function (Blueprint $table) {

            // ignore failure if index is FK-backed (prevents crash)
            try {
                DB::statement('DROP INDEX carts_user_id_product_id_product_variant_id_unique ON carts');
            } catch (\Throwable $e) {}

            try {
                DB::statement('DROP INDEX carts_session_id_product_id_product_variant_id_unique ON carts');
            } catch (\Throwable $e) {}

            // new indexes
            $table->unique(
                ['user_id', 'vendor_product_item_id', 'product_id', 'product_variant_id'],
                'carts_user_vendor_product_variant_unique'
            );

            $table->unique(
                ['session_id', 'vendor_product_item_id', 'product_id', 'product_variant_id'],
                'carts_session_vendor_product_variant_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {

            $table->dropUnique('carts_user_vendor_product_variant_unique');
            $table->dropUnique('carts_session_vendor_product_variant_unique');

            if (Schema::hasColumn('carts', 'vendor_product_item_id')) {
                $table->dropColumn('vendor_product_item_id');
            }

            $table->unique(
                ['user_id', 'product_id', 'product_variant_id'],
                'carts_user_id_product_id_product_variant_id_unique'
            );

            $table->unique(
                ['session_id', 'product_id', 'product_variant_id'],
                'carts_session_id_product_id_product_variant_id_unique'
            );
        });
    }
};