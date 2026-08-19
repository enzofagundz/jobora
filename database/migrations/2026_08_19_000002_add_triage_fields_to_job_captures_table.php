<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_captures', function (Blueprint $table) {
            $table->string('triage_status', 20)->default('pending')->after('job_posting_id');
            $table->string('triage_result', 20)->nullable()->after('triage_status');
            $table->text('triage_reason')->nullable()->after('triage_result');
            $table->text('triage_error')->nullable()->after('triage_reason');
        });
    }

    public function down(): void
    {
        Schema::table('job_captures', function (Blueprint $table) {
            $table->dropColumn(['triage_error', 'triage_reason', 'triage_result', 'triage_status']);
        });
    }
};
