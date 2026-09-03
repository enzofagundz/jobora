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
        Schema::create('job_ia_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_posting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('operation')->index();
            $table->string('provider');
            $table->string('model');
            $table->string('status')->index();
            $table->string('error_class')->nullable();
            $table->text('error_message')->nullable();
            $table->boolean('transient')->default(false);
            $table->integer('latency_ms')->nullable();
            $table->integer('input_chars')->nullable();
            $table->integer('prompt_tokens')->nullable();
            $table->integer('completion_tokens')->nullable();
            $table->integer('cache_write_input_tokens')->nullable();
            $table->integer('cache_read_input_tokens')->nullable();
            $table->integer('reasoning_tokens')->nullable();
            $table->integer('attempt_no');
            $table->string('trigger')->index();
            $table->timestamps();

            $table->index(['job_posting_id', 'created_at']);
            $table->index(['provider', 'status']);
            $table->index(['operation', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_ia_attempts');
    }
};
