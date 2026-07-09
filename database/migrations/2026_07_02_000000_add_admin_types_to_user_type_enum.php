<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add admin and super_admin to user_type enum for RBAC support.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE users MODIFY COLUMN user_type ENUM('customer', 'vendor', 'rider', 'agent', 'admin', 'super_admin') NOT NULL");
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE users MODIFY COLUMN user_type ENUM('customer', 'vendor', 'rider', 'agent') NOT NULL");
    }
};
