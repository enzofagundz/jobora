<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_resume_adaptations', function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('legacy_job_posting_id');
            $table->text('adapted_content');
            $table->json('changes')->nullable();
            $table->json('covered_keywords')->nullable();
            $table->json('missing_requirements')->nullable();
            $table->json('alerts')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->dateTime('archived_at');

            $table->index('user_id');
            $table->index('legacy_job_posting_id');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Down protegido: a tabela legacy_resume_adaptations é arquivo interno de preservação e não deve ser removida automaticamente.');
    }
};
