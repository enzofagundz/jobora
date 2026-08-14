<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_postings', function (Blueprint $table) {
            $table->id();
            $table->text('description');
            $table->text('source_url')->nullable();
            $table->dateTime('collected_at');
            $table->enum('status', ['pending', 'needs_review', 'confirmed', 'failed'])->default('pending');
            $table->string('role', 255)->nullable();
            $table->string('company', 255)->nullable();
            $table->json('suggested_data')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('collected_at');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_postings');
    }
};
