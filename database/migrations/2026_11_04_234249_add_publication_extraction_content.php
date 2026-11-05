<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publications', function (Blueprint $table): void {
            $table->longText('content')->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->unsignedInteger('content_version')->nullable();
            $table->dateTime('last_observed_at')->nullable();

            $table->index('last_observed_at');
        });

        Schema::table('publication_observations', function (Blueprint $table): void {
            $table->longText('content')->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->unsignedInteger('content_version')->nullable();
            $table->json('evidence_block_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('publication_observations', function (Blueprint $table): void {
            $table->dropColumn(['content', 'content_hash', 'content_version', 'evidence_block_ids']);
        });

        Schema::table('publications', function (Blueprint $table): void {
            $table->dropIndex(['last_observed_at']);
            $table->dropColumn(['content', 'content_hash', 'content_version', 'last_observed_at']);
        });
    }
};
