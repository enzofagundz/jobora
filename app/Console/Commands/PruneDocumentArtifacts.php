<?php

namespace App\Console\Commands;

use App\Services\DocumentArtifactService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('document-artifacts:prune {--limit=500 : Maximum artifacts to process (1-1000)}')]
#[Description('Remove expired or revoked PDFs while preserving preparation audit metadata')]
class PruneDocumentArtifacts extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(DocumentArtifactService $artifacts): int
    {
        $count = $artifacts->pruneExpired((int) $this->option('limit'));
        $this->info("Processed {$count} document artifacts.");

        return self::SUCCESS;
    }
}
