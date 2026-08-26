<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Converte o token global legado (VAGAS_API_TOKEN) no hash do owner
     * indicado por VAGAS_API_USER_ID. Executada uma única vez; após isso
     * o middleware não consulta mais as chaves globais.
     */
    public function up(): void
    {
        $plain = env('VAGAS_API_TOKEN', '');
        $ownerId = env('VAGAS_API_USER_ID', null);

        // Fallback para config quando env não está carregado diretamente na migration
        if ($plain === '' || $plain === null) {
            $plain = config('capture.api_token', '');
        }

        if ($ownerId === null || $ownerId === '') {
            $ownerId = config('capture.owner_user_id');
        }

        $plain = is_string($plain) ? trim($plain) : '';
        $ownerId = $ownerId !== null && $ownerId !== '' ? (int) $ownerId : null;

        if ($plain === '' || $ownerId === null) {
            return;
        }

        $hash = hash('sha256', $plain);
        $lastFour = substr($plain, -4);

        $owner = DB::table('users')->where('id', $ownerId)->first();

        if (! $owner) {
            throw new RuntimeException("Backfill do token legado abortado: usuário owner {$ownerId} não existe. Defina VAGAS_API_USER_ID corretamente ou crie o usuário antes de migrar.");
        }

        $existingHash = $owner->capture_token_hash ?? null;

        if ($existingHash !== null && $existingHash !== '' && ! hash_equals($existingHash, $hash)) {
            throw new RuntimeException('Backfill abortado: owner já possui capture_token_hash diferente do VAGAS_API_TOKEN legado. Revise manualmente antes de migrar.');
        }

        if ($existingHash === $hash) {
            return;
        }

        DB::table('users')->where('id', $ownerId)->update([
            'capture_token_hash' => $hash,
            'capture_token_last_four' => $lastFour,
        ]);
    }

    public function down(): void
    {
        // Backfill irreversível sem perda de distinção — manter hash convertido.
        // Para reverter manualmente, limpe capture_token_hash do owner.
    }
};
