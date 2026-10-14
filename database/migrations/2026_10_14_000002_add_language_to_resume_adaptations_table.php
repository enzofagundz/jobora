<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Uma adequação por (vaga, idioma): a unicidade passa de (job_posting_id)
     * para (job_posting_id, language). Linhas existentes são consideradas
     * pt-BR — a adaptação canônica.
     */
    public function up(): void
    {
        Schema::table('resume_adaptations', function (Blueprint $table) {
            $table->string('language')->default('pt-BR');

            $table->dropUnique(['job_posting_id']);
            $table->unique(['job_posting_id', 'language']);
        });
    }

    public function down(): void
    {
        Schema::table('resume_adaptations', function (Blueprint $table) {
            $table->dropUnique(['job_posting_id', 'language']);
            $table->unique('job_posting_id');
            $table->dropColumn('language');
        });
    }
};
