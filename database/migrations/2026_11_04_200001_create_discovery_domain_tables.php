<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_captures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('source', 50);
            $table->string('surface', 50)->nullable();
            $table->text('url')->nullable();
            $table->string('html_path', 255)->nullable();
            $table->dateTime('captured_at')->nullable();
            $table->foreignId('legacy_job_capture_id')->nullable()->unique()->constrained('job_captures')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'captured_at']);
        });

        Schema::create('publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('source', 50);
            $table->string('external_id', 255)->nullable();
            $table->text('url')->nullable();
            $table->text('canonical_url')->nullable();
            $table->char('canonical_url_hash', 64)->nullable();
            $table->string('fingerprint', 255)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'source', 'external_id']);
            $table->unique(['user_id', 'source', 'canonical_url_hash']);
            $table->unique(['user_id', 'source', 'fingerprint']);
        });

        Schema::create('publication_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_capture_id')->constrained('page_captures')->cascadeOnDelete();
            $table->foreignId('publication_id')->constrained('publications')->cascadeOnDelete();
            $table->dateTime('observed_at');
            $table->timestamps();

            $table->unique(['page_capture_id', 'publication_id']);
            $table->index('publication_id');
        });

        Schema::create('opportunity_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_posting_id')->nullable()->constrained('job_postings')->nullOnDelete();
            $table->foreignId('publication_id')->constrained('publications')->cascadeOnDelete();
            $table->boolean('is_legacy')->default(false);
            $table->timestamps();

            $table->unique(['job_posting_id', 'publication_id']);
            $table->index('publication_id');
        });

        Schema::create('publication_identity_conflicts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('source', 50);
            $table->string('external_id', 255)->nullable();
            $table->text('canonical_url')->nullable();
            $table->char('identity_hash', 64)->unique();
            $table->foreignId('publication_id')->nullable()->constrained('publications')->nullOnDelete();
            $table->foreignId('other_publication_id')->nullable()->constrained('publications')->nullOnDelete();
            $table->string('reason', 50);
            $table->timestamps();

            $table->index(['user_id', 'source']);
        });
    }

    public function down(): void
    {
        $populated = collect([
            'page_captures',
            'publications',
            'publication_observations',
            'opportunity_sources',
            'publication_identity_conflicts',
        ])->filter(fn (string $table): bool => Schema::hasTable($table) && DB::table($table)->exists());

        if ($populated->isNotEmpty()) {
            throw new RuntimeException('Down protegido: dados V2 presentes em '.$populated->implode(', ').'.');
        }

        Schema::dropIfExists('publication_identity_conflicts');
        Schema::dropIfExists('opportunity_sources');
        Schema::dropIfExists('publication_observations');
        Schema::dropIfExists('publications');
        Schema::dropIfExists('page_captures');
    }
};
