<?php

use App\Enums\ResumeLanguage;
use App\Mcp\Resources\MarketSnapshotResource;
use App\Mcp\Servers\JoboraServer;
use App\Mcp\Tools\Market\GetMarketComparisonTool;
use App\Mcp\Tools\Market\GetMarketFrequenciesTool;
use App\Mcp\Tools\Market\GetMarketPairsTool;
use App\Mcp\Tools\Market\GetMarketTrendsTool;
use App\Models\JobPosting;
use App\Services\ResumeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Mcp\McpTestHelpers;

uses(RefreshDatabase::class);

it('devolve a frequência dos conceitos das vagas confirmadas', function () {
    $user = McpTestHelpers::user();

    $pythonA = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    McpTestHelpers::item($pythonA, 'Python');
    $pythonB = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    McpTestHelpers::item($pythonB, 'Python');
    $react = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    McpTestHelpers::item($react, 'React');

    JoboraServer::tool(GetMarketFrequenciesTool::class, [])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.total_confirmed', 3)
            ->where('data.items.0.name', 'Python')
            ->where('data.items.0.total', 2)
            ->where('data.items.0.percent', 66.7)
            ->where('data.items.1.name', 'React')
            ->where('data.items.1.total', 1)
            ->etc());
});

it('devolve combinações recorrentes na mesma categoria', function () {
    $user = McpTestHelpers::user();

    $first = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    McpTestHelpers::item($first, 'PHP');
    McpTestHelpers::item($first, 'Laravel');

    $second = JobPosting::factory()->for($user, 'user')->confirmed()->create();
    McpTestHelpers::item($second, 'PHP');
    McpTestHelpers::item($second, 'Laravel');

    JoboraServer::tool(GetMarketPairsTool::class, [])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.items.0.name_a', 'Laravel')
            ->where('data.items.0.name_b', 'PHP')
            ->where('data.items.0.total', 2)
            ->etc());
});

it('compara o mercado com o currículo e aponta lacunas', function () {
    $user = McpTestHelpers::user();
    app(ResumeService::class)->save($user, 'Currículo com Python e automação.', ResumeLanguage::PtBr);

    foreach (['Python', 'Python', 'React'] as $name) {
        $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
        McpTestHelpers::item($posting, $name);
    }

    JoboraServer::tool(GetMarketComparisonTool::class, [])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.table.0.name', 'Python')
            ->where('data.table.0.present', true)
            ->where('data.table.0.is_gap', false)
            ->where('data.gaps.0.name', 'React')
            ->where('data.gaps.0.total', 1)
            ->etc());
});

it('avisa dados insuficientes quando há menos de dois meses', function () {
    $user = McpTestHelpers::user();
    $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create(['collected_at' => now()]);
    McpTestHelpers::item($posting, 'PHP');

    JoboraServer::tool(GetMarketTrendsTool::class, ['period' => 'month'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.insufficient_reason', 'insufficient')
            ->etc());
});

it('devolve a série temporal com dois meses de dados', function () {
    $user = McpTestHelpers::user();

    $thisMonth = JobPosting::factory()->for($user, 'user')->confirmed()->create(['collected_at' => now()]);
    McpTestHelpers::item($thisMonth, 'PHP');
    $lastMonth = JobPosting::factory()->for($user, 'user')->confirmed()->create(['collected_at' => now()->subMonthNoOverflow()]);
    McpTestHelpers::item($lastMonth, 'PHP');

    JoboraServer::tool(GetMarketTrendsTool::class, ['period' => 'month'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('ok', true)
            ->where('data.insufficient_reason', null)
            ->where('data.series.0.name', 'PHP')
            ->etc());
});

it('expõe um snapshot de mercado como resource', function () {
    $user = McpTestHelpers::user();
    app(ResumeService::class)->save($user, 'Currículo com Python.', ResumeLanguage::PtBr);

    foreach (['Python', 'Python', 'React'] as $name) {
        $posting = JobPosting::factory()->for($user, 'user')->confirmed()->create();
        McpTestHelpers::item($posting, $name);
    }

    JoboraServer::resource(MarketSnapshotResource::class)
        ->assertOk()
        ->assertSee(['total_confirmed', 'React']);
});
