<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * As capturas concluídas antes desta evolução foram decididas pelo LLM.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('job_captures', 'triage_origin')) {
            Schema::table('job_captures', function (Blueprint $table) {
                $table->string('triage_origin', 20)->nullable()->after('triage_reason');
            });
        }

        DB::table('job_captures')
            ->where('triage_status', 'completed')
            ->update(['triage_origin' => 'llm']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('job_captures', 'triage_origin')) {
            Schema::table('job_captures', function (Blueprint $table) {
                $table->dropColumn('triage_origin');
            });
        }
    }
};
