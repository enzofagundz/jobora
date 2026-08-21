<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_captures', function (Blueprint $table) {
            $table->string('dedup_key', 512)->change();
        });
    }

    public function down(): void
    {
        if (DB::table('job_captures')->whereRaw('LENGTH(dedup_key) > 255')->exists()) {
            throw new RuntimeException('Cannot revert dedup_key to 255: existing values exceed 255 characters. Down would truncate data.');
        }

        Schema::table('job_captures', function (Blueprint $table) {
            $table->string('dedup_key', 255)->change();
        });
    }
};
