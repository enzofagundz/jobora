<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profile_import_proposals', function (Blueprint $table): void {
            $table->longText('source')->change();
            $table->longText('base_document')->change();
            $table->longText('candidate')->change();
            $table->longText('evidence')->change();
            $table->longText('decision_result')->nullable()->change();
        });
    }

    public function down(): void {}
};
