<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ambassador_promotion_requests')) {
            return;
        }

        Schema::create('ambassador_promotion_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agent_id');
            $table->unsignedTinyInteger('current_tier');
            $table->unsignedTinyInteger('requested_tier');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->integer('active_agents_snapshot');
            $table->integer('subordinate_count_snapshot');
            $table->integer('delivery_count_snapshot');
            $table->text('rejection_reason')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->foreign('agent_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->foreign('reviewed_by')
                ->references('id')
                ->on('users')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ambassador_promotion_requests');
    }
};
