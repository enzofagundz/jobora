<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('application_events', function (Blueprint $table) {
            $table->string('event_key', 128)->nullable();
            $table->char('request_hash', 64)->nullable();
            $table->unique(['application_id', 'event_key']);
        });
    }

    public function down(): void
    {
        Schema::table('application_events', function (Blueprint $table) {
            $table->dropUnique(['application_id', 'event_key']);
            $table->dropColumn(['event_key', 'request_hash']);
        });
    }
};
