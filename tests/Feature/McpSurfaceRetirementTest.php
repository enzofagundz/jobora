<?php

use App\Models\JobPosting;
use App\Models\Resume;
use App\Models\ResumeAdaptation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\AgentIntegrations\AgentIntegrationTestHelpers as Helpers;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->client = Helpers::client();
    $this->integration = Helpers::integration($this->owner, $this->client);
    $this->token = Helpers::accessToken($this->owner, $this->client);
});

/** @param array<string, mixed> $params @return array<string, mixed> */
function retirementRpc(object $test, string $method, array $params = []): array
{
    app('auth')->forgetGuards();

    return $test->withToken($test->token)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1,
        'method' => $method, 'params' => $params])->assertOk()->json();
}

/** @return list<array<string, mixed>> */
function retirementCatalog(object $test, string $method, string $key): array
{
    $items = [];
    $cursor = null;
    do {
        $response = retirementRpc($test, $method, $cursor === null ? [] : ['cursor' => $cursor]);
        $items = [...$items, ...$response['result'][$key]];
        $cursor = $response['result']['nextCursor'] ?? null;
    } while ($cursor !== null);

    return $items;
}

it('offers only canonical V2 workflows and makes retired tool names unreachable over HTTP MCP', function () {
    $names = array_column(retirementCatalog($this, 'tools/list', 'tools'), 'name');
    $retired = ['capture_job', 'triage_job_captures', 'promote_job_capture', 'promote_eligible_job_captures',
        'get_market_frequencies', 'get_market_pairs', 'get_market_comparison', 'get_market_trends'];
    foreach ($retired as $name) {
        expect($names)->not->toContain($name);
        $called = retirementRpc($this, 'tools/call', ['name' => $name, 'arguments' => []]);
        expect($called['error']['code'])->toBe(-32602);
        expect($called['error']['message'])->toContain($name);
    }
    expect($names)->toContain('ingest_page_capture', 'list_publications', 'request_publication_triage', 'review_job_posting',
        'request_matching', 'get_matching', 'get_resume', 'get_resume_adaptation', 'reserve_application_submission');
    expect($names)->toHaveCount(count(array_unique($names)));
    $this->assertDatabaseCount('job_captures', 0);
    $this->assertDatabaseCount('page_captures', 0);
    $this->assertDatabaseCount('job_postings', 0);
    $this->assertDatabaseCount('ai_operations', 0);
});

it('removes market resources and templates without removing useful resume transports', function () {
    $resources = retirementCatalog($this, 'resources/list', 'resources');
    $templates = retirementCatalog($this, 'resources/templates/list', 'resourceTemplates');
    expect(array_column($resources, 'uri'))->not->toContain('jobora://market-snapshot');
    expect(array_column($resources, 'uri'))->toContain('jobora://resume');
    expect(array_column($templates, 'uriTemplate'))->toContain('jobora://resume-adaptations/{job_posting_id}');
    expect(json_encode($templates))->not->toContain('market');
    foreach (['jobora://market-snapshot', 'jobora://market-snapshot?language=en'] as $uri) {
        $response = retirementRpc($this, 'resources/read', ['uri' => $uri]);
        expect($response['error']['code'])->toBe(-32002);
        expect($response['error']['message'])->toContain($uri);
    }
});

it('publishes private V2 execution instructions and usable preparation schemas through discovery', function () {
    $initialized = retirementRpc($this, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => [],
        'clientInfo' => ['name' => 'Hermes V2', 'version' => '2.0.0']]);
    expect($initialized['result']['instructions'])->toContain('dados isolados por proprietário', 'token autentica', 'Hermes executa', 'unknown bloqueia');
    expect($initialized['result']['instructions'])->not->toContain('A identidade é fixa', 'sistema pessoal de análise do mercado');
    $tools = retirementCatalog($this, 'tools/list', 'tools');
    $preparation = collect($tools)->firstWhere('name', 'prepare_application');
    expect($preparation['inputSchema']['properties']['destination']['properties']['email']['type'])->toBe('string');
    expect($preparation['inputSchema']['properties']['questions']['items']['properties']['support']['items']['properties']['source']['enum'])->toBe(['profile', 'resume']);
});

it('uses ATS read for adaptation resources and keeps resume scope and tenant ownership independent', function () {
    $posting = JobPosting::factory()->for($this->owner, 'user')->confirmed()->create();
    ResumeAdaptation::query()->create(['job_posting_id' => $posting->id, 'adapted_content' => 'Adaptação privada do proprietário.']);
    Resume::factory()->for($this->owner)->create(['content' => 'Currículo privado original.']);
    $other = User::factory()->create();
    $otherPosting = JobPosting::factory()->for($other, 'user')->confirmed()->create();
    ResumeAdaptation::query()->create(['job_posting_id' => $otherPosting->id, 'adapted_content' => 'Segredo de outra conta.']);
    $this->integration->update(['scopes' => ['ats:read']]);
    $resource = retirementRpc($this, 'resources/read', ['uri' => 'jobora://resume-adaptations/'.$posting->id]);
    $data = json_decode($resource['result']['contents'][0]['text'], true);
    expect($data['ok'])->toBeTrue();
    expect($data['data']['adaptation']['adapted_content'])->toBe('Adaptação privada do proprietário.');
    foreach (['jobora://resume-adaptations/'.$otherPosting->id => 'not_found', 'jobora://resume' => 'forbidden'] as $uri => $code) {
        $denied = retirementRpc($this, 'resources/read', ['uri' => $uri]);
        expect(json_decode($denied['result']['contents'][0]['text'], true)['error']['code'])->toBe($code);
        expect(json_encode($denied))->not->toContain('Segredo de outra conta.', 'Currículo privado original.');
    }
    $this->integration->update(['scopes' => ['resume:read']]);
    $denied = retirementRpc($this, 'resources/read', ['uri' => 'jobora://resume-adaptations/'.$posting->id]);
    expect(json_decode($denied['result']['contents'][0]['text'], true)['error']['code'])->toBe('forbidden');
    $resume = retirementRpc($this, 'resources/read', ['uri' => 'jobora://resume']);
    expect(json_decode($resume['result']['contents'][0]['text'], true)['data']['content'])->toBe('Currículo privado original.');
});
