<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Limpeza one-shot de duplicatas existentes por (source, external_id).
        // Preserva o registro com job_posting_id preenchido (promovido); senão o de menor id.
        // Linhas com external_id NULL (dedup por url/conteúdo) nunca são tocadas.
        DB::statement(<<<'SQL'
            DELETE FROM job_captures
            WHERE external_id IS NOT NULL
              AND id NOT IN (
                SELECT COALESCE(MAX(CASE WHEN job_posting_id IS NOT NULL THEN id END), MIN(id))
                FROM job_captures
                WHERE external_id IS NOT NULL
                GROUP BY source, external_id
              )
        SQL);

        Schema::table('job_captures', function (Blueprint $table) {
            $table->unique(['source', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('job_captures', function (Blueprint $table) {
            $table->dropUnique(['source', 'external_id']);
        });
    }
};
