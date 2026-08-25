<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('applications')->cascadeOnDelete();
            $table->enum('status', [
                'planned', 'preparing', 'submitted', 'waiting', 'assessment',
                'interview', 'offer', 'completed', 'rejected', 'withdrawn',
            ]);
            $table->string('title', 255);
            $table->text('notes')->nullable();
            $table->string('contact', 255)->nullable();
            $table->dateTime('occurred_at');
            $table->timestamps();

            $table->index(['application_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_events');
    }
};
