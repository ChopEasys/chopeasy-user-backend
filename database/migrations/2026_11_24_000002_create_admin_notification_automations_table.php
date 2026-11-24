<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_notification_automations', function (Blueprint $table) {
            $table->id();
            $table->string('audience')->unique();
            $table->string('title')->nullable();
            $table->text('message')->nullable();
            $table->boolean('enabled')->default(false);
            $table->string('frequency')->default('daily');
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_notification_automations');
    }
};
