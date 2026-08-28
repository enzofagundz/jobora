<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        try {
            Schema::table('job_postings', function (Blueprint $table) {
                $table->index(['user_id', 'status', 'collected_at'], 'job_postings_user_status_collected_index');
            });
        } catch (Throwable $e) {
            // Index may already exist
        }
    }

    public function down(): void
    {
        try {
            Schema::table('job_postings', function (Blueprint $table) {
                $table->dropIndex('job_postings_user_status_collected_index');
            });
        } catch (Throwable $e) {
        }
    }
};
