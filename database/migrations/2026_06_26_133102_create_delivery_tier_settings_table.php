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
        Schema::create('delivery_tier_settings', function (Blueprint $table) {
            $table->id();
            $table->integer('tier')->unique(); // 1, 2, 3, etc.
            $table->string('tier_name'); // "Tier 1", "Tier 2", etc.
            $table->decimal('max_order_amount', 15, 2); // Max order value for this tier
            $table->integer('min_completed_deliveries'); // Min deliveries needed to request upgrade
            $table->decimal('min_security_deposit', 15, 2); // Min deposit for tier
            $table->decimal('max_security_deposit', 15, 2); // Max deposit for tier
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // Seed default tiers
        \DB::table('delivery_tier_settings')->insert([
            [
                'tier' => 1,
                'tier_name' => 'Tier 1',
                'max_order_amount' => 10000,
                'min_completed_deliveries' => 0,
                'min_security_deposit' => 0,
                'max_security_deposit' => 0,
                'description' => 'Entry level tier for new delivery agents',
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tier' => 2,
                'tier_name' => 'Tier 2',
                'max_order_amount' => 50000,
                'min_completed_deliveries' => 20,
                'min_security_deposit' => 5000,
                'max_security_deposit' => 20000,
                'description' => 'Intermediate tier for experienced agents',
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tier' => 3,
                'tier_name' => 'Tier 3',
                'max_order_amount' => 250000,
                'min_completed_deliveries' => 100,
                'min_security_deposit' => 20000,
                'max_security_deposit' => 50000,
                'description' => 'Premium tier for high-performing agents',
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivery_tier_settings');
    }
};