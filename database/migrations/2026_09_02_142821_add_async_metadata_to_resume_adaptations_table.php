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
        Schema::table('resume_adaptations', function (Blueprint $table) {
            $table->char('source_fingerprint', 64)->nullable()->after('alerts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('resume_adaptations', function (Blueprint $table) {
            $table->dropColumn('source_fingerprint');
        });
    }
};
