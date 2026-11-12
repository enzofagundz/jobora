<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opportunity_review_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('job_posting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('from_version');
            $table->unsignedInteger('to_version');
            $table->char('idempotency_key', 64);
            $table->char('input_fingerprint', 64);
            $table->json('changes');
            $table->json('before_snapshot');
            $table->json('after_snapshot');
            $table->timestamps();

            $table->unique(['job_posting_id', 'idempotency_key']);
            $table->unique(['job_posting_id', 'to_version']);
            $table->index(['job_posting_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunity_review_revisions');
    }
};
