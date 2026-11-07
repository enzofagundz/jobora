<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_submission_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('application_id')->index()->constrained();
            $table->foreignId('integration_id')->index()->constrained('agent_integrations');
            $table->foreignUuid('approval_id')->index()->constrained('application_approvals');
            $table->foreignUuid('preparation_id')->index()->constrained('application_preparations');
            $table->char('fingerprint', 64);
            $table->string('idempotency_key', 128);
            $table->char('request_hash', 64);
            $table->string('state', 20);
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('active_application_id')->nullable()->unique()->constrained('applications');
            $table->foreignUuid('consumed_approval_id')->nullable()->unique()->constrained('application_approvals');
            $table->timestamp('reserved_at', 6);
            $table->timestamp('reservation_expires_at', 6);
            $table->timestamp('consumed_at', 6)->nullable();
            $table->timestamp('execute_before', 6)->nullable();
            $table->timestamp('result_due_at', 6)->nullable();
            $table->timestamp('resolved_at', 6)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'integration_id', 'idempotency_key'], 'submission_idempotency_unique');
        });
        Schema::create('application_submission_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('attempt_id')->index()->constrained('application_submission_attempts')->cascadeOnDelete();
            $table->string('event_key', 128);
            $table->string('kind', 20);
            $table->char('request_hash', 64);
            $table->text('evidence');
            $table->text('actor');
            $table->timestamp('occurred_at', 6);
            $table->timestamp('received_at', 6);
            $table->unique(['attempt_id', 'event_key'], 'submission_event_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_submission_events');
        Schema::dropIfExists('application_submission_attempts');
    }
};
