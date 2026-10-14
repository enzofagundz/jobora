<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Uma versão de currículo por idioma: a unicidade passa de (user_id) para
     * (user_id, language). Linhas existentes são consideradas pt-BR, o
     * currículo canônico.
     */
    public function up(): void
    {
        Schema::table('resumes', function (Blueprint $table) {
            $table->string('language')->default('pt-BR');

            $table->dropUnique(['user_id']);
            $table->unique(['user_id', 'language']);
        });
    }

    public function down(): void
    {
        Schema::table('resumes', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'language']);
            $table->unique('user_id');
            $table->dropColumn('language');
        });
    }
};
