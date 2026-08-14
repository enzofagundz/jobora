<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_posting_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_posting_id')->constrained()->cascadeOnDelete();
            $table->enum('category', [
                'technology', 'language', 'framework', 'database', 'tool', 'cloud',
                'methodology', 'skill', 'requirement', 'experience',
            ]);
            $table->string('name', 100);
            $table->enum('source', ['ai', 'user'])->default('ai');
            $table->timestamps();

            $table->unique(['job_posting_id', 'category', 'name']);
            $table->index(['category', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_posting_items');
    }
};
