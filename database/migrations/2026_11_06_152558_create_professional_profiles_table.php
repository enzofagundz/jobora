<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('professional_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version')->default(0);
            $table->text('draft');
            $table->unsignedInteger('confirmed_version')->default(0);
            $table->text('confirmed_snapshot')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->text('confirmed_actor')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('professional_profiles');
    }
};
