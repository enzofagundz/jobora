<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_identity_bindings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_id')->constrained('agent_integrations');
            $table->foreignUuid('challenge_id')->unique()->constrained('telegram_link_challenges');
            $table->text('telegram_identity');
            $table->foreignId('active_user_id')->nullable()->unique()->constrained('users');
            $table->char('active_identity_key', 64)->nullable()->unique();
            $table->timestamp('confirmed_at', 6);
            $table->timestamp('revoked_at', 6)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_identity_bindings');
    }
};
