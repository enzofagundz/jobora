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
        Schema::table('ai_operations', function (Blueprint $table) {
            $table->uuid('attempt_token')->nullable()->after('run_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_operations', function (Blueprint $table) {
            $table->dropColumn('attempt_token');
        });
    }
};
