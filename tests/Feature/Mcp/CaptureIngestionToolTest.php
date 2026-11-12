<?php

use App\Mcp\Servers\JoboraServer;
use App\Mcp\Tools\Captures\GetPageCaptureTool;
use App\Mcp\Tools\Captures\IngestPageCaptureTool;
use App\Models\DiscoverySource;
use App\Models\PageCapture;
use App\Services\Discovery\DiscoveryRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Mcp\McpTestHelpers;

uses(RefreshDatabase::class);

function mcpIngestPayload(string $executionId, string $url): array
{
    return [
        'format' => 'jobora.capture-envelope',
        'version' => 1,
        'capture' => [
            'idempotency_key' => 'mcp-capture-'.uniqid(),
            'execution_id' => $executionId,
            'source' => 'linkedin',
            'surface' => 'linkedin_jobs',
            'initial_url' => $url,
            'final_url' => $url,
            'captured_at' => now()->toIso8601String(),
            'coverage' => ['status' => 'complete', 'reason' => null, 'blocks_expected' => 1, 'blocks_captured' => 1],
            'html' => '<main data-capture-surface="linkedin_jobs" data-capture-version="1"><article data-block-id="card-1" data-kind="card" data-platform-id="p1" data-permalink="https://www.linkedin.com/jobs/view/1">Vaga</article></main>',
        ],
        'blocks' => [[
            'block_id' => 'card-1',
            'kind' => 'card',
            'platform_id' => 'p1',
            'permalink' => 'https://www.linkedin.com/jobs/view/1',
            'card_block_id' => null,
            'detail_block_id' => null,
        ]],
    ];
}

it('ingests a capture through mcp and reads it back by execution id', function () {
    Storage::fake('local');
    $user = McpTestHelpers::user();
    $source = DiscoverySource::factory()->for($user, 'user')->create([
        'active' => true,
        'next_due_at' => now()->subMinute(),
    ]);
    $run = app(DiscoveryRunService::class)->acquire($user, null, 'idem')->first();

    JoboraServer::tool(IngestPageCaptureTool::class, mcpIngestPayload($run->execution_id, $source->url))
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.replay', false)
            ->where('data.capture.execution_id', $run->execution_id)
            ->etc());

    expect($run->refresh()->page_capture_id)->not->toBeNull();

    JoboraServer::tool(GetPageCaptureTool::class, ['execution_id' => $run->execution_id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.capture.execution_id', $run->execution_id)
            ->where('data.capture.coverage', 'complete')
            ->etc());
});

it('reads a capture by a 200 character execution id', function () {
    $user = McpTestHelpers::user();
    $executionId = str_repeat('e', 200);
    PageCapture::factory()->for($user, 'user')->create([
        'execution_id' => $executionId,
        'coverage_status' => 'complete',
    ]);

    JoboraServer::tool(GetPageCaptureTool::class, ['execution_id' => $executionId])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.capture.execution_id', $executionId)
            ->etc());
});
