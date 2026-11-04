<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $column = $table->json('data')->change();

            if (DB::connection()->getDriverName() === 'pgsql') {
                $column->using('data::json');
            }
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $column = $table->text('data')->change();

            if (DB::connection()->getDriverName() === 'pgsql') {
                $column->using('data::text');
            }
        });
    }
};
