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
            $table->text('ats_prompt')->nullable()->after('alerts');
            $table->boolean('is_ats_optimized')->default(false)->after('ats_prompt');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('resume_adaptations', function (Blueprint $table) {
            $table->dropColumn(['ats_prompt', 'is_ats_optimized']);
        });
    }
};
