<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ambassador_annual_rewards')) {
            return;
        }

        Schema::create('ambassador_annual_rewards', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ambassador_id');
            $table->unsignedTinyInteger('tier_at_evaluation');
            $table->date('evaluation_start');
            $table->date('evaluation_end');
            $table->enum('status', ['pending', 'earned', 'withheld'])->default('pending');
            $table->decimal('reward_amount', 12, 2);
            $table->integer('active_agents_avg')->nullable();
            $table->integer('delivery_count')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('ambassador_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ambassador_annual_rewards');
    }
};
