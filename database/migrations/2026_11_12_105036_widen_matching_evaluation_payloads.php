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
        Schema::table('matching_evaluations', function (Blueprint $table): void {
            $table->longText('frozen')->change();
            $table->longText('result')->change();
            $table->longText('provenance_warnings')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Keep widened columns: shrinking could truncate encrypted snapshots.
    }
};
