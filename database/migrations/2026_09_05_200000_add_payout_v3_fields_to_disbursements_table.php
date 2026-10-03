<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('disbursements', function (Blueprint $table) {
            $table->string('payout_id')->nullable()->after('xendit_disbursement_id');
            $table->string('reference_id')->nullable()->after('payout_id');
            $table->string('raw_status')->nullable()->after('status');
            $table->string('currency', 8)->nullable()->default('IDR')->after('raw_status');
            $table->decimal('destination_amount', 15, 2)->nullable()->after('currency');
            $table->string('destination_currency', 8)->nullable()->default('IDR')->after('destination_amount');
            $table->string('failure_code')->nullable()->after('failure_reason');
            $table->timestamp('estimated_arrival_time')->nullable()->after('failure_code');

            $table->index('payout_id');
            $table->index('reference_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('disbursements', function (Blueprint $table) {
            $table->dropIndex(['payout_id']);
            $table->dropIndex(['reference_id']);
            $table->dropIndex(['status']);

            $table->dropColumn([
                'payout_id',
                'reference_id',
                'raw_status',
                'currency',
                'destination_amount',
                'destination_currency',
                'failure_code',
                'estimated_arrival_time',
            ]);
        });
    }
};
