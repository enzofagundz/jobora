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
        Schema::table('application_submission_attempts', function (Blueprint $table): void {
            $table->dropForeign(['application_id']);
            $table->dropForeign(['active_application_id']);
            $table->foreign('application_id')->references('id')->on('applications')->cascadeOnDelete();
            $table->foreign('active_application_id')->references('id')->on('applications')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('application_submission_attempts', function (Blueprint $table): void {
            $table->dropForeign(['application_id']);
            $table->dropForeign(['active_application_id']);
            $table->foreign('application_id')->references('id')->on('applications');
            $table->foreign('active_application_id')->references('id')->on('applications');
        });
    }
};
