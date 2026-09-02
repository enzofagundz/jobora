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
            $table->unsignedTinyInteger('ats_score')->nullable()->after('is_ats_optimized');
            $table->text('ats_rationale')->nullable()->after('ats_score');
            $table->char('ats_source_fingerprint', 64)->nullable()->after('ats_rationale');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('resume_adaptations', function (Blueprint $table) {
            $table->dropColumn(['ats_score', 'ats_rationale', 'ats_source_fingerprint']);
        });
    }
};
