<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add vendor_product_item_id to make cart lines store-specific.
        Schema::table('carts', function (Blueprint $table) {
            $table->unsignedBigInteger('vendor_product_item_id')->nullable()->after('product_variant_id');
        });

        // Backfill for existing rows from product_snapshot JSON.
        // product_snapshot is stored as JSON array/object in the carts table.
        DB::table('carts')->update([
            'vendor_product_item_id' => DB::raw(
                "JSON_UNQUOTE(JSON_EXTRACT(product_snapshot, '$.vendor_product_item_id'))"
            ),
        ]);

        // Replace old uniqueness constraints (product_id based) with vendor_product_item_id based.
        Schema::table('carts', function (Blueprint $table) {
            $table->dropUnique('carts_user_id_product_id_product_variant_id_unique');
            $table->dropUnique('carts_session_id_product_id_product_variant_id_unique');

            // A cart line should be unique per vendor_product_item (store) and variant.
            $table->unique(
                ['user_id', 'vendor_product_item_id', 'product_id', 'product_variant_id'],
                'carts_user_id_vendor_product_item_id_product_id_product_variant_id_unique'
            );
            $table->unique(
                ['session_id', 'vendor_product_item_id', 'product_id', 'product_variant_id'],
                'carts_session_id_vendor_product_item_id_product_id_product_variant_id_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->dropUnique('carts_user_id_vendor_product_item_id_product_id_product_variant_id_unique');
            $table->dropUnique('carts_session_id_vendor_product_item_id_product_id_product_variant_id_unique');
            $table->dropColumn('vendor_product_item_id');

            // Restore old uniqueness constraints.
            $table->unique(['user_id', 'product_id', 'product_variant_id'], 'carts_user_id_product_id_product_variant_id_unique');
            $table->unique(['session_id', 'product_id', 'product_variant_id'], 'carts_session_id_product_id_product_variant_id_unique');
        });
    }
};

