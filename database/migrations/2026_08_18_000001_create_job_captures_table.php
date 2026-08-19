<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_captures', function (Blueprint $table) {
            $table->id();
            $table->string('source', 50);                 // 'linkedin', futuro 'indeed'
            $table->string('external_id', 255)->nullable(); // ex.: 'urn:li:activity:123'
            $table->string('dedup_key', 255);             // 'ext:...' | 'url:...' | 'content:...'
            $table->text('url')->nullable();
            $table->text('search_url')->nullable();
            $table->string('title', 255)->nullable();
            $table->text('description')->nullable();
            $table->string('company', 255)->nullable();
            $table->string('location', 255)->nullable();
            $table->string('author_name', 255)->nullable();
            $table->text('author_profile_url')->nullable();
            $table->dateTime('captured_at')->nullable();
            $table->foreignId('job_posting_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['source', 'dedup_key']);
            $table->index('captured_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_captures');
    }
};
