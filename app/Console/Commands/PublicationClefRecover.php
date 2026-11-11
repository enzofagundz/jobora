<?php

namespace App\Console\Commands;

use App\Jobs\ClassifyPublicationWithClef;
use App\Models\PublicationClefResult;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

#[Signature('publication-clef:recover')]
#[Description('Recovers queued publication Clef classifications')]
class PublicationClefRecover extends Command
{
    public function handle(): int
    {
        $scheduled = 0;
        $staleBefore = now()->subSeconds(130);

        PublicationClefResult::query()
            ->where('status', 'pending')
            ->where('created_at', '<=', now()->subSeconds(660))
            ->orderBy('id')
            ->chunkById(100, function ($results) use (&$scheduled): void {
                foreach ($results as $result) {
                    $scheduled += $this->dispatch($result->id) ? 1 : 0;
                }
            });

        PublicationClefResult::query()
            ->where('status', 'running')
            ->where('started_at', '<=', $staleBefore)
            ->orderBy('id')
            ->chunkById(100, function ($results) use (&$scheduled, $staleBefore): void {
                foreach ($results as $result) {
                    $recovered = DB::transaction(function () use ($result, $staleBefore): bool {
                        $current = PublicationClefResult::query()->whereKey($result->id)->lockForUpdate()->first();

                        if ($current === null
                            || $current->status !== 'running'
                            || $current->started_at === null
                            || $current->started_at->greaterThan($staleBefore)) {
                            return false;
                        }

                        $current->forceFill([
                            'status' => 'pending',
                            'lease_token' => null,
                            'started_at' => null,
                        ])->save();

                        return true;
                    });

                    if ($recovered) {
                        $scheduled += $this->dispatch($result->id) ? 1 : 0;
                    }
                }
            });

        $this->info("Classificações Clef recuperadas: {$scheduled}.");

        return self::SUCCESS;
    }

    private function dispatch(int $resultId): bool
    {
        try {
            ClassifyPublicationWithClef::dispatch($resultId, recoveryToken: (string) Str::uuid());

            return true;
        } catch (Throwable $exception) {
            Log::channel('ai')->warning('Publication Clef recovery dispatch failed.', [
                'result_id' => $resultId,
                'exception' => $exception::class,
            ]);

            return false;
        }
    }
}
