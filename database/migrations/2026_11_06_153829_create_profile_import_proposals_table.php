<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_import_proposals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('professional_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_operation_id')->constrained()->cascadeOnDelete();
            $table->uuid('run_token');
            $table->uuid('attempt_token');
            $table->unsignedInteger('base_version');
            $table->unsignedInteger('base_confirmed_version');
            $table->text('source');
            $table->text('base_document');
            $table->text('candidate');
            $table->text('evidence');
            $table->string('status')->default('pending');
            $table->text('requester_actor');
            $table->text('decision_actor')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->unsignedInteger('result_version')->nullable();
            $table->text('decision_result')->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->timestamps();
            $table->unique(['ai_operation_id', 'run_token', 'attempt_token'], 'profile_import_attempt_unique');
            $table->index('professional_profile_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_import_proposals');
    }
};
