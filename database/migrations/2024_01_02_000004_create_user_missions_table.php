<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_missions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mission_id')->constrained()->cascadeOnDelete();
            $table->string('proof_image_url')->nullable();
            $table->string('proof_image_hash')->nullable();
            $table->json('ai_gemini_response')->nullable();
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->enum('status', ['pending', 'verified', 'rejected'])->default('pending');
            $table->boolean('anti_fraud_flagged')->default(false);
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'mission_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_missions');
    }
};
