<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('oauth_client_id');
            $table->string('name');
            $table->json('scopes');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'oauth_client_id']);
            $table->index('oauth_client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_integrations');
    }
};
