<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('professional_competencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('concept', 100);
            $table->enum('strategic_role', ['core', 'expansion', 'differentiation']);
            $table->enum('source', ['ai', 'manual'])->default('manual');
            $table->timestamps();

            $table->unique(['user_id', 'concept']);
            $table->index(['user_id', 'strategic_role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('professional_competencies');
    }
};
