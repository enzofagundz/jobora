<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matching_evaluations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('professional_profile_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('job_posting_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('ai_operation_id')->constrained()->cascadeOnDelete();
            $table->uuid('run_token');
            $table->uuid('attempt_token');
            $table->unsignedInteger('confirmed_version');
            $table->unsignedInteger('opportunity_version');
            $table->char('profile_hash', 64);
            $table->char('opportunity_hash', 64);
            $table->char('input_fingerprint', 64);
            $table->string('criteria_version', 100);
            $table->string('schema_version', 100);
            $table->string('provider', 100);
            $table->string('model', 100);
            $table->longText('frozen');
            $table->longText('result');
            $table->longText('provenance_warnings');
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'input_fingerprint']);
            $table->unique(['ai_operation_id', 'run_token', 'attempt_token'], 'matching_attempt_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matching_evaluations');
    }
};
