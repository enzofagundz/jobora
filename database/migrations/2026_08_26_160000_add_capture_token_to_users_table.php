<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Credencial individual da extensão por usuário.
     * Armazena apenas o hash SHA-256 do segredo; o segredo em texto
     * puro é exibido uma única vez na geração/regeneração.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('capture_token_hash', 64)->nullable()->unique()->after('password');
            $table->string('capture_token_last_four', 4)->nullable()->after('capture_token_hash');
            $table->timestamp('disabled_at')->nullable()->after('capture_token_last_four');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['capture_token_hash']);
            $table->dropColumn(['capture_token_hash', 'capture_token_last_four', 'disabled_at']);
        });
    }
};
