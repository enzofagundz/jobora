<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_artifacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('preparation_id')->nullable()->constrained('application_preparations')->cascadeOnDelete();
            $table->string('type');
            $table->string('language', 5);
            $table->text('path');
            $table->char('sha256', 64);
            $table->string('mime_type');
            $table->unsignedBigInteger('bytes');
            $table->boolean('requires_ats_authorization')->default(false);
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_artifacts');
    }
};
