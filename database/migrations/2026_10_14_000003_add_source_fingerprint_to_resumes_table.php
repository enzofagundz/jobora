<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A versão EN é derivada do PT-BR por tradução de IA. Guarda-se o
     * fingerprint do PT-BR de origem para detectar drift (o PT-BR mudou
     * depois da tradução). Nula no currículo canônico e em versões manuais.
     */
    public function up(): void
    {
        Schema::table('resumes', function (Blueprint $table) {
            $table->string('source_fingerprint')->nullable()->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('resumes', function (Blueprint $table) {
            $table->dropColumn('source_fingerprint');
        });
    }
};
