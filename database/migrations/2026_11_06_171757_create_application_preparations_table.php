<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_preparations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('language', 5);
            $table->string('schema_version');
            $table->string('state');
            $table->text('snapshot');
            $table->text('pending');
            $table->text('source_versions');
            $table->char('source_hash', 64);
            $table->char('fingerprint', 64);
            $table->timestamps();
        });
        Schema::table('applications', function (Blueprint $table) {
            $table->uuid('current_preparation_id')->nullable();
            $table->unsignedInteger('preparation_version')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('applications', fn (Blueprint $table) => $table->dropColumn(['current_preparation_id', 'preparation_version']));
        Schema::dropIfExists('application_preparations');
    }
};
