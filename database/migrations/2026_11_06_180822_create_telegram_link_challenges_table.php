<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_link_challenges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_id')->constrained('agent_integrations');
            $table->char('challenge_hash', 64)->unique();
            $table->text('telegram_identity')->nullable();
            $table->timestamp('expires_at', 6);
            $table->timestamp('consumed_at', 6)->nullable();
            $table->timestamp('confirmed_at', 6)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_link_challenges');
    }
};
