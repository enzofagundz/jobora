<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discovery_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('discovery_source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_integration_id')->nullable()->constrained('agent_integrations')->nullOnDelete();
            $table->string('execution_id', 64)->unique();
            $table->string('idempotency_key', 200);
            $table->string('status', 20)->default('active');
            $table->string('active_key', 120)->nullable()->unique();
            $table->char('canonical_url_hash', 64);
            $table->text('initial_url');
            $table->string('surface', 50);
            $table->dateTime('reserved_at');
            $table->dateTime('expires_at');
            $table->dateTime('last_access_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('page_capture_id')->nullable()->constrained('page_captures')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['user_id', 'status']);
            $table->index(['discovery_source_id', 'status']);
            $table->index('canonical_url_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discovery_runs');
    }
};
