<?php

namespace App\Console\Commands;

use App\Models\JobIaAttempt;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('job-ia-attempts:prune {--dry-run : Simula sem remover registros}')]
#[Description('Remove tentativas de IA com mais de 90 dias, sem apagar a vaga.')]
class PruneJobIaAttempts extends Command
{
    public function handle(): int
    {
        $query = JobIaAttempt::query()->where('created_at', '<', now()->subDays(90));
        $count = $query->count();

        if ($this->option('dry-run')) {
            $this->info("{$count} tentativa(s) seriam removidas (dry-run).");

            return self::SUCCESS;
        }

        $deleted = 0;

        $query->orderBy('id')->chunkById(500, function ($attempts) use (&$deleted): void {
            $deleted += JobIaAttempt::query()->whereIn('id', $attempts->pluck('id'))->delete();
        });

        $this->info("{$deleted} tentativa(s) removida(s).");

        return self::SUCCESS;
    }
}
