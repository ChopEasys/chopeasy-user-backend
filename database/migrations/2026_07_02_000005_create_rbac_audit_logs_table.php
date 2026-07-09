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
        Schema::create('rbac_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_id');
            $table->string('action', 50);
            $table->string('target_type', 50);
            $table->unsignedBigInteger('target_id');
            $table->json('previous_state')->nullable();
            $table->json('new_state')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            // Foreign key to users table
            $table->foreign('actor_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            // Indexes for query performance
            $table->index('actor_id', 'idx_audit_actor');
            $table->index(['target_type', 'target_id'], 'idx_audit_target');
            $table->index('created_at', 'idx_audit_created');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rbac_audit_logs');
    }
};
