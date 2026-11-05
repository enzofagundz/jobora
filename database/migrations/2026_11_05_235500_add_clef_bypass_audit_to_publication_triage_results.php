<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publication_triage_results', function (Blueprint $table): void {
            $table->foreignId('clef_result_id')
                ->nullable()
                ->constrained('publication_clef_results')
                ->nullOnDelete();
            $table->string('clef_status', 20)->nullable();
            $table->boolean('clef_bypassed')->default(false);
            $table->text('clef_bypass_reason')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('publication_triage_results', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('clef_result_id');
            $table->dropConstrainedForeignId('requested_by');
            $table->dropColumn(['clef_status', 'clef_bypassed', 'clef_bypass_reason']);
        });
    }
};
