<?php

namespace App\Mcp\Resources;

use App\Mcp\Support\McpUserResolver;
use App\Services\MarketAnalysisService;
use App\Services\MarketComparisonService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Uri;

#[Uri('jobora://market-snapshot')]
#[Description('Snapshot do mercado do usuário configurado: total de vagas confirmadas, top conceitos e principais lacunas.')]
class MarketSnapshotResource extends JoboraResource
{
    public function __construct(
        McpUserResolver $users,
        private readonly MarketAnalysisService $market,
        private readonly MarketComparisonService $comparison,
    ) {
        parent::__construct($users);
    }

    protected function read(Request $request): Response
    {
        $user = $this->user();

        return $this->ok([
            'total_confirmed' => $this->market->totalConfirmed($user),
            'top_concepts' => array_slice($this->market->relativeFrequencies($user), 0, 10),
            'top_gaps' => $this->comparison->gaps($user, 10),
        ]);
    }
}
