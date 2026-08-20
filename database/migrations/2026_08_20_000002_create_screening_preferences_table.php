<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('screening_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('preferences');
            $table->json('rejections');
            $table->timestamps();

            $table->unique('user_id');
        });

        // Transição: os critérios pessoais que viviam em config/triage.php
        // (removido nesta mudança) passam a pertencer ao usuário. Preserva o
        // comportamento atual para usuários já existentes; instalações novas
        // não têm usuários neste ponto e começam sem critérios (a UI permite
        // cadastrá-los).
        $preferences = ['PHP', 'Laravel', 'remoto', 'pleno', 'home office'];
        $rejections = ['Tech Lead', 'posições de liderança', 'presencial', 'estágio', 'wordpress', 'sênior', 'híbrido', 'afirmativa'];

        foreach (DB::table('users')->cursor() as $user) {
            DB::table('screening_preferences')->insert([
                'user_id' => $user->id,
                'preferences' => json_encode($preferences),
                'rejections' => json_encode($rejections),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('screening_preferences');
    }
};
