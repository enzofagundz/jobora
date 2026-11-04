<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_captures', function (Blueprint $table) {
            $table->string('execution_id', 64)->nullable()->index();
            $table->string('idempotency_key', 200)->nullable();
            $table->char('payload_hash', 64)->nullable();
            $table->unsignedSmallInteger('format_version')->nullable();
            $table->string('ingest_status', 20)->default('received');
            $table->string('coverage_status', 20)->nullable();
            $table->string('coverage_reason', 50)->nullable();
            $table->unsignedInteger('blocks_expected')->nullable();
            $table->unsignedInteger('blocks_captured')->nullable();
            $table->dateTime('raw_expires_at')->nullable();
            $table->dateTime('raw_deleted_at')->nullable();

            $table->unique(['user_id', 'idempotency_key']);
        });

        Schema::table('discovery_runs', function (Blueprint $table) {
            $table->string('completion_status', 20)->nullable();
            $table->unique('page_capture_id');
        });
    }

    public function down(): void
    {
        Schema::table('discovery_runs', function (Blueprint $table) {
            $table->dropUnique(['page_capture_id']);
            $table->dropColumn('completion_status');
        });

        Schema::table('page_captures', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'idempotency_key']);
            $table->dropColumn([
                'execution_id', 'idempotency_key', 'payload_hash', 'format_version',
                'ingest_status', 'coverage_status', 'coverage_reason', 'blocks_expected',
                'blocks_captured', 'raw_expires_at', 'raw_deleted_at',
            ]);
        });
    }
};
