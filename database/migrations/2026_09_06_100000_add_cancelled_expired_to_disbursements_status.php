<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('disbursements', function (Blueprint $table) {
            // Extend enum to cover full Payout v3 lifecycle.
            // Previous: pending, completed, failed, reversed, rejected
            // Adding: cancelled, expired (+ keep existing)
            $table->enum('status', [
                'pending',
                'completed',
                'failed',
                'reversed',
                'rejected',
                'cancelled',
                'expired',
            ])->default('pending')->change();
        });
    }

    public function down(): void
    {
        Schema::table('disbursements', function (Blueprint $table) {
            $table->enum('status', [
                'pending',
                'completed',
                'failed',
                'reversed',
                'rejected',
            ])->default('pending')->change();
        });
    }
};
