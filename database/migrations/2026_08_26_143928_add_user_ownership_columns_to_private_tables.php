<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_postings', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->index('user_id');
        });

        Schema::table('job_captures', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->index('user_id');
        });

        Schema::table('resumes', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('resumes', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropIndex(['user_id']);
            $table->dropColumn('user_id');
        });

        Schema::table('job_captures', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropIndex(['user_id']);
            $table->dropColumn('user_id');
        });

        Schema::table('job_postings', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropIndex(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
