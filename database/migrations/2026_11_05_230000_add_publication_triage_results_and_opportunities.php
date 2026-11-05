<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publication_triage_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_operation_id')->constrained('ai_operations')->cascadeOnDelete();
            $table->foreignId('publication_id')->constrained('publications')->cascadeOnDelete();
            $table->unsignedInteger('content_version');
            $table->uuid('run_token');
            $table->uuid('attempt_token');
            $table->unsignedSmallInteger('attempt');
            $table->string('status', 32);
            $table->string('provider', 100);
            $table->string('model', 255);
            $table->string('schema_version', 100);
            $table->char('input_fingerprint', 64);
            $table->json('output')->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamps();

            $table->unique(['ai_operation_id', 'run_token', 'attempt_token']);
            $table->index(['publication_id', 'content_version', 'created_at']);
        });

        Schema::table('job_postings', function (Blueprint $table) {
            $table->text('description')->nullable()->change();
            $table->string('readiness', 32)->nullable()->index();
            $table->unsignedInteger('opportunity_version')->default(1);
            $table->string('location', 255)->nullable();
            $table->string('work_mode', 100)->nullable();
            $table->string('employment_type', 100)->nullable();
            $table->decimal('salary_min', 14, 2)->nullable();
            $table->decimal('salary_max', 14, 2)->nullable();
            $table->string('salary_currency', 3)->nullable();
            $table->string('salary_period', 50)->nullable();
            $table->string('seniority', 100)->nullable();
            $table->json('requirements')->nullable();
            $table->json('technologies')->nullable();
            $table->json('evidence')->nullable();
            $table->json('review_reasons')->nullable();
        });

        Schema::table('opportunity_sources', function (Blueprint $table) {
            $table->unsignedInteger('publication_content_version')->nullable();
            $table->foreignId('publication_triage_result_id')
                ->nullable()
                ->constrained('publication_triage_results')
                ->nullOnDelete();
            $table->json('evidence_block_ids')->nullable();
            $table->json('evidence')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('opportunity_sources', function (Blueprint $table) {
            $table->dropConstrainedForeignId('publication_triage_result_id');
            $table->dropColumn(['publication_content_version', 'evidence_block_ids', 'evidence']);
        });

        Schema::table('job_postings', function (Blueprint $table) {
            $table->dropIndex(['readiness']);
            $table->dropColumn([
                'readiness', 'opportunity_version', 'location', 'work_mode', 'employment_type',
                'salary_min', 'salary_max', 'salary_currency', 'salary_period', 'seniority',
                'requirements', 'technologies', 'evidence',
                'review_reasons',
            ]);
            $table->text('description')->nullable(false)->change();
        });

        Schema::dropIfExists('publication_triage_results');
    }
};
