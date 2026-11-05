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
        Schema::create('publication_clef_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('publication_id')->constrained('publications')->cascadeOnDelete();
            $table->unsignedInteger('content_version');
            $table->unsignedInteger('attempt');
            $table->string('status', 20);
            $table->decimal('probability', 10, 9)->nullable();
            $table->string('category', 100)->nullable();
            $table->decimal('confidence', 10, 9)->nullable();
            $table->string('model', 255)->nullable();
            $table->string('classifier_identifier', 100)->nullable();
            $table->text('error')->nullable();
            $table->uuid('lease_token')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('classified_at')->nullable();
            $table->timestamps();

            $table->unique(['publication_id', 'content_version', 'attempt']);
            $table->index(['status', 'created_at']);
            $table->index(['status', 'started_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('publication_clef_results');
    }
};
