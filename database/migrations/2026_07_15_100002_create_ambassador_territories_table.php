<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ambassador_territories')) {
            return;
        }

        Schema::create('ambassador_territories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->enum('scope', ['community', 'lga', 'state', 'national']);
            $table->string('state', 100)->nullable();
            $table->string('lga', 100)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ambassador_territories');
    }
};
