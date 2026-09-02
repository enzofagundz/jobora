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
        Schema::create('ai_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('operation_key', 191)->unique();
            $table->string('operation_type', 64);
            $table->string('target_type', 64);
            $table->unsignedBigInteger('target_id');
            $table->foreignId('job_posting_id')->nullable()->constrained()->cascadeOnDelete();
            $table->uuid('run_token');
            $table->string('status', 32)->default('queued');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('queued_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_attempt_started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('expires_at');
            $table->longText('input_snapshot')->nullable();
            $table->char('input_fingerprint', 64);
            $table->string('failure_code', 64)->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'queued_at']);
            $table->index(['target_type', 'target_id', 'status']);
            $table->index(['job_posting_id', 'status']);
            $table->index(['status', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_operations');
    }
};
