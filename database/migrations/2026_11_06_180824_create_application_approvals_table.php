<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_id')->constrained('agent_integrations');
            $table->foreignUuid('preparation_id')->constrained('application_preparations')->cascadeOnDelete();
            $table->foreignUuid('binding_id')->constrained('telegram_identity_bindings');
            $table->char('fingerprint', 64);
            $table->char('message_key', 64)->unique();
            $table->char('request_hash', 64);
            $table->text('telegram_identity');
            $table->text('recorded_actor');
            $table->text('revoked_actor')->nullable();
            $table->timestamp('approved_at', 6);
            $table->timestamp('received_at', 6);
            $table->timestamp('expires_at', 6);
            $table->timestamp('revoked_at', 6)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_approvals');
    }
};
