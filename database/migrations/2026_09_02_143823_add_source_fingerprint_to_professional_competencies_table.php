<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('professional_competencies', function (Blueprint $table) {
            $table->char('source_fingerprint', 64)->nullable()->after('source');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('professional_competencies', function (Blueprint $table) {
            $table->dropColumn('source_fingerprint');
        });
    }
};
