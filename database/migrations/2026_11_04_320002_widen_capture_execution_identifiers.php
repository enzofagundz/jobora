<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discovery_runs', function (Blueprint $table): void {
            $table->string('execution_id', 200)->change();
        });

        Schema::table('page_captures', function (Blueprint $table): void {
            $table->string('execution_id', 200)->nullable()->change();
        });
    }

    public function down(): void
    {
        $tooLong = DB::table('discovery_runs')->whereRaw('LENGTH(execution_id) > 64')->exists()
            || DB::table('page_captures')->whereNotNull('execution_id')->whereRaw('LENGTH(execution_id) > 64')->exists();

        if ($tooLong) {
            throw new RuntimeException(
                'Existem execution_ids com mais de 64 caracteres; a reversão foi interrompida para não truncar dados V2.',
            );
        }

        Schema::table('page_captures', function (Blueprint $table): void {
            $table->string('execution_id', 64)->nullable()->change();
        });

        Schema::table('discovery_runs', function (Blueprint $table): void {
            $table->string('execution_id', 64)->change();
        });
    }
};
