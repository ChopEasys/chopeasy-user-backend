<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ambassador_territory_assignments')) {
            return;
        }

        Schema::create('ambassador_territory_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ambassador_id');
            $table->unsignedBigInteger('territory_id');
            $table->timestamp('assigned_at');
            $table->timestamp('unassigned_at')->nullable();
            $table->boolean('is_primary')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('ambassador_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->foreign('territory_id')
                ->references('id')
                ->on('ambassador_territories')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ambassador_territory_assignments');
    }
};
