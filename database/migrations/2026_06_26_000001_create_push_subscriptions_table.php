<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('endpoint', 500);
            $table->string('p256dh_key', 255);
            $table->string('auth_secret', 255);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            // Index on user_id for fast lookups
            $table->index('user_id', 'idx_push_sub_user_id');

            // Foreign key with cascade delete
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');
        });

        // Add composite unique index with prefix length on endpoint for InnoDB compatibility
        DB::statement('CREATE UNIQUE INDEX idx_push_sub_endpoint ON push_subscriptions (user_id, endpoint(191))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
