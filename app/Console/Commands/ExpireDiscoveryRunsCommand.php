<?php

namespace App\Console\Commands;

use App\Services\Discovery\DiscoveryRunService;
use Illuminate\Console\Command;

class ExpireDiscoveryRunsCommand extends Command
{
    protected $signature = 'discovery-runs:expire';

    protected $description = 'Expira reservas de descoberta vencidas, sem executar descoberta.';

    public function handle(DiscoveryRunService $runs): int
    {
        $expired = $runs->expireDue();

        $this->info("Reservas expiradas: {$expired}");

        return self::SUCCESS;
    }
}
