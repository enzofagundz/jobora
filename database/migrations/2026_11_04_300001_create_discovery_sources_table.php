<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discovery_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('source', 50);
            $table->string('surface', 50);
            $table->text('url');
            $table->char('canonical_url_hash', 64)->nullable();
            $table->text('purpose')->nullable();
            $table->json('criteria')->nullable();
            $table->unsignedInteger('frequency_minutes');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->dateTime('last_attempt_at')->nullable();
            $table->dateTime('last_success_at')->nullable();
            $table->dateTime('next_due_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_id', 'canonical_url_hash']);
            $table->index(['user_id', 'active']);
            $table->index('next_due_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discovery_sources');
    }
};
