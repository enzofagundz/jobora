<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_posting_id')->constrained()->cascadeOnDelete();
            $table->enum('type', [
                'email', 'external_url', 'linkedin_apply', 'indeed_apply',
                'whatsapp', 'phone', 'form', 'other',
            ]);
            $table->string('platform', 100)->nullable();
            $table->text('url')->nullable();
            $table->string('email', 320)->nullable();
            $table->string('phone', 50)->nullable();
            $table->text('instructions')->nullable();
            $table->enum('source', ['ai', 'user'])->default('ai');
            $table->string('dedup_key', 64);
            $table->timestamps();

            $table->unique(['job_posting_id', 'dedup_key']);
            $table->index(['job_posting_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_methods');
    }
};
