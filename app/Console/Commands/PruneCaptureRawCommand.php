<?php

namespace App\Console\Commands;

use App\Services\Discovery\CaptureRetentionService;
use Illuminate\Console\Command;

class PruneCaptureRawCommand extends Command
{
    protected $signature = 'captures:prune-raw';

    protected $description = 'Remove o HTML bruto de capturas vencidas, preservando metadados e resultados.';

    public function handle(CaptureRetentionService $retention): int
    {
        $this->info('Capturas expiradas: '.$retention->pruneRaw());

        return self::SUCCESS;
    }
}
