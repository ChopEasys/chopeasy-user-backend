<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('slides')->update(['layout' => 'text_only']);
        DB::table('slides')
            ->whereNull('background_color')
            ->orWhere('background_color', '#FFFFFF')
            ->update(['background_color' => '#FFC107']);
    }

    public function down(): void
    {
        // Keep slide display preferences in place when rolling back this data migration.
    }
};
