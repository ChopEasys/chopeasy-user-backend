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
        Schema::table('agent_withdrawals', function (Blueprint $table) {
            $table->string('recipient_code')->nullable()->after('account_name');
            $table->string('transfer_code')->nullable()->after('recipient_code');
            $table->string('transfer_reference')->nullable()->after('transfer_code');
            $table->text('failure_reason')->nullable()->after('transfer_reference');
            $table->timestamp('paid_at')->nullable()->after('failure_reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agent_withdrawals', function (Blueprint $table) {
            $table->dropColumn(['recipient_code', 'transfer_code', 'transfer_reference', 'failure_reason', 'paid_at']);
        });
    }
};
