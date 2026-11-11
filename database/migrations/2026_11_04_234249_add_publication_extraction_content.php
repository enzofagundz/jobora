<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const PROTECTED_TABLES = ['publications', 'publication_observations'];

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
        $populated = collect(self::PROTECTED_TABLES)
            ->filter(fn (string $table): bool => Schema::hasTable($table) && DB::table($table)->exists());

        if ($populated->isNotEmpty()) {
            throw new RuntimeException('Down protegido: conteúdo extraído presente em '.$populated->implode(', ').'.');
        }

        Schema::table('publication_observations', function (Blueprint $table): void {
            $table->dropColumn(['content', 'content_hash', 'content_version', 'evidence_block_ids']);
        });

        Schema::table('publications', function (Blueprint $table): void {
            $table->dropIndex(['last_observed_at']);
            $table->dropColumn(['content', 'content_hash', 'content_version', 'last_observed_at']);
        });
    }
};
