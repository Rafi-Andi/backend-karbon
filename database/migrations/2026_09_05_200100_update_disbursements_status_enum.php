<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('disbursements', function (Blueprint $table) {
            // Expand the enum to include new statuses from Payout API v3
            $table->enum('status', [
                'pending',
                'completed',
                'failed',
                'reversed',
                'rejected',
            ])->default('pending')->change();
        });
    }

    public function down(): void
    {
        // Revert to original enum values
        Schema::table('disbursements', function (Blueprint $table) {
            $table->enum('status', ['pending', 'completed', 'failed'])->default('pending')->change();
        });
    }
};
