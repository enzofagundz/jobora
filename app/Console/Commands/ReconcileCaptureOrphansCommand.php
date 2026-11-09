<?php

namespace App\Console\Commands;

use App\Services\Discovery\CaptureRetentionService;
use Illuminate\Console\Command;

class ReconcileCaptureOrphansCommand extends Command
{
    protected $signature = 'captures:reconcile-orphans';

    protected $description = 'Reconcilia objetos privados órfãos e metadados sem objeto.';

    public function handle(CaptureRetentionService $retention): int
    {
        $result = $retention->reconcileOrphans();

        $this->info("Objetos removidos: {$result['objects']}; metadados limpos: {$result['metadata']}");

        return self::SUCCESS;
    }
}
