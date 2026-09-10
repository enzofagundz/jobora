<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('job_postings')->whereNull('user_id')->exists()
            || DB::table('job_captures')->whereNull('user_id')->exists()
            || DB::table('resumes')->whereNull('user_id')->exists()) {
            throw new RuntimeException('Enforce abortado: ainda existem registros com user_id IS NULL. Execute o backfill antes.');
        }

        Schema::withoutForeignKeyConstraints(function () {
            DB::transaction(function () {
                $this->rebuildJobPostings();
                $this->rebuildResumes();
                $this->rebuildJobCaptures();
            });
        });
    }

    public function down(): void
    {
        $hasCollisionDedup = DB::table('job_captures')
            ->select('source', 'dedup_key')
            ->selectRaw('COUNT(*) as c')
            ->groupBy('source', 'dedup_key')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        $hasCollisionExternal = DB::table('job_captures')
            ->whereNotNull('external_id')
            ->select('source', 'external_id')
            ->selectRaw('COUNT(*) as c')
            ->groupBy('source', 'external_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasCollisionDedup || $hasCollisionExternal) {
            throw new RuntimeException('Down protegido: colisões entre usuários impedem restaurar índices globais antigos sem perda de linhas.');
        }

        throw new RuntimeException('Down protegido: restrições de ownership (NOT NULL, UNIQUE por usuário) são irreversíveis sem perda de distinção original. Restaure manualmente se necessário.');
    }

    /**
     * Executa uma operação de schema tolerante a falha sem envenenar a
     * transação externa. Cada operação roda em uma transação aninhada, o que
     * gera um SAVEPOINT no PostgreSQL; em caso de erro, o rollback ao savepoint
     * restaura o estado da transação e permite continuar.
     */
    private function safely(Closure $operation): void
    {
        try {
            DB::transaction($operation);
        } catch (Throwable) {
            // Silenciado intencionalmente: índice/constraint opcional já ausente.
        }
    }

    private function rebuildJobPostings(): void
    {
        // Drop FKs que referenciam job_postings para permitir rename sem deixar FK quebrado
        foreach (['job_posting_items', 'application_methods', 'applications', 'resume_adaptations', 'job_captures'] as $table) {
            $this->safely(function () use ($table) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropForeign(['job_posting_id']);
                });
            });
        }

        $this->safely(function () {
            Schema::table('job_postings', function (Blueprint $table) {
                $table->dropIndex(['collected_at']);
            });
        });

        $this->safely(function () {
            Schema::table('job_postings', function (Blueprint $table) {
                $table->dropIndex(['status']);
            });
        });

        $this->safely(function () {
            Schema::table('job_postings', function (Blueprint $table) {
                $table->dropIndex(['user_id']);
            });
        });

        Schema::rename('job_postings', 'job_postings_legacy');

        Schema::create('job_postings', function (Blueprint $table) {
            $table->id();
            $table->text('description');
            $table->text('source_url')->nullable();
            $table->dateTime('collected_at');
            $table->enum('status', ['pending', 'needs_review', 'confirmed', 'failed'])->default('pending');
            $table->string('role', 255)->nullable();
            $table->string('company', 255)->nullable();
            $table->json('suggested_data')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->index('collected_at');
            $table->index('status');
            $table->index('user_id');
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'collected_at']);
        });

        DB::statement(<<<'SQL'
            INSERT INTO job_postings (id, description, source_url, collected_at, status, role, company, suggested_data, error_message, created_at, updated_at, user_id)
            SELECT id, description, source_url, collected_at, status, role, company, suggested_data, error_message, created_at, updated_at, user_id FROM job_postings_legacy
        SQL);

        Schema::drop('job_postings_legacy');

        // Recriar FKs apontando para a nova tabela
        Schema::table('job_posting_items', function (Blueprint $table) {
            $table->foreign('job_posting_id')->references('id')->on('job_postings')->cascadeOnDelete();
        });
        Schema::table('application_methods', function (Blueprint $table) {
            $table->foreign('job_posting_id')->references('id')->on('job_postings')->cascadeOnDelete();
        });
        Schema::table('applications', function (Blueprint $table) {
            $table->foreign('job_posting_id')->references('id')->on('job_postings')->cascadeOnDelete();
        });
        Schema::table('resume_adaptations', function (Blueprint $table) {
            $table->foreign('job_posting_id')->references('id')->on('job_postings')->cascadeOnDelete();
        });
        Schema::table('job_captures', function (Blueprint $table) {
            $table->foreign('job_posting_id')->references('id')->on('job_postings')->nullOnDelete();
        });
    }

    private function rebuildResumes(): void
    {
        $this->safely(function () {
            Schema::table('resumes', function (Blueprint $table) {
                $table->dropIndex(['user_id']);
            });
        });

        Schema::rename('resumes', 'resumes_legacy');

        Schema::create('resumes', function (Blueprint $table) {
            $table->id();
            $table->text('content');
            $table->timestamps();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->unique('user_id');
            $table->index('user_id');
        });

        DB::statement(<<<'SQL'
            INSERT INTO resumes (id, content, created_at, updated_at, user_id)
            SELECT id, content, created_at, updated_at, user_id FROM resumes_legacy
        SQL);

        Schema::drop('resumes_legacy');
    }

    private function rebuildJobCaptures(): void
    {
        foreach (['job_captures_source_dedup_key_unique', 'job_captures_source_external_id_unique'] as $index) {
            $this->safely(function () use ($index) {
                Schema::table('job_captures', function (Blueprint $table) use ($index) {
                    $table->dropUnique($index);
                });
            });
        }

        $this->safely(function () {
            Schema::table('job_captures', function (Blueprint $table) {
                $table->dropIndex(['captured_at']);
            });
        });

        $this->safely(function () {
            Schema::table('job_captures', function (Blueprint $table) {
                $table->dropIndex(['user_id']);
            });
        });

        // job_captures tem FK para job_postings já tratada no rebuildJobPostings (recriada), mas precisa ser removida antes de renomear
        $this->safely(function () {
            Schema::table('job_captures', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });
        });
        $this->safely(function () {
            Schema::table('job_captures', function (Blueprint $table) {
                $table->dropForeign(['job_posting_id']);
            });
        });

        Schema::rename('job_captures', 'job_captures_legacy');

        Schema::create('job_captures', function (Blueprint $table) {
            $table->id();
            $table->string('source', 50);
            $table->string('external_id', 255)->nullable();
            $table->string('dedup_key', 512);
            $table->text('url')->nullable();
            $table->text('search_url')->nullable();
            $table->string('title', 255)->nullable();
            $table->text('description')->nullable();
            $table->string('company', 255)->nullable();
            $table->string('location', 255)->nullable();
            $table->string('author_name', 255)->nullable();
            $table->text('author_profile_url')->nullable();
            $table->dateTime('captured_at')->nullable();
            $table->foreignId('job_posting_id')->nullable()->constrained()->nullOnDelete();
            $table->string('triage_status', 20)->default('pending');
            $table->string('triage_result', 20)->nullable();
            $table->text('triage_reason')->nullable();
            $table->text('triage_error')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->unique(['user_id', 'source', 'dedup_key']);
            $table->unique(['user_id', 'source', 'external_id']);
            $table->index('captured_at');
            $table->index('user_id');
            $table->index(['user_id', 'captured_at']);
        });

        DB::statement(<<<'SQL'
            INSERT INTO job_captures (id, source, external_id, dedup_key, url, search_url, title, description, company, location, author_name, author_profile_url, captured_at, job_posting_id, triage_status, triage_result, triage_reason, triage_error, created_at, updated_at, deleted_at, user_id)
            SELECT id, source, external_id, dedup_key, url, search_url, title, description, company, location, author_name, author_profile_url, captured_at, job_posting_id, triage_status, triage_result, triage_reason, triage_error, created_at, updated_at, deleted_at, user_id FROM job_captures_legacy
        SQL);

        Schema::drop('job_captures_legacy');
    }
};
