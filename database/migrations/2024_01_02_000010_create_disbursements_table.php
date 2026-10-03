<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disbursements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mitra_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('voucher_claim_id')->constrained()->cascadeOnDelete();
            $table->string('xendit_disbursement_id')->unique();
            $table->decimal('amount', 15, 2);
            $table->string('bank_name');
            $table->string('bank_account_number');
            $table->string('bank_account_name');
            $table->enum('status', ['pending', 'completed', 'failed'])->default('pending');
            $table->json('response_log')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index('mitra_profile_id');
            $table->index('xendit_disbursement_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disbursements');
    }
};
