<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discovery_run_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('discovery_run_id')->constrained()->cascadeOnDelete();
            $table->string('event_key', 200)->unique();
            $table->string('outcome', 20);
            $table->text('final_url')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->json('redirects')->nullable();
            $table->json('details')->nullable();
            $table->string('error', 500)->nullable();
            $table->dateTime('occurred_at');
            $table->timestamps();

            $table->index('discovery_run_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discovery_run_events');
    }
};
