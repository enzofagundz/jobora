<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_posting_id')->unique()->constrained()->cascadeOnDelete();
            $table->enum('status', [
                'planned', 'preparing', 'submitted', 'waiting', 'assessment',
                'interview', 'offer', 'completed', 'rejected', 'withdrawn',
            ])->default('planned');
            $table->timestamps();

            $table->index('job_posting_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
