<?php

use App\Enums\JobPostingStatus;
use App\Filament\Resources\JobCaptures\Pages\ViewJobCapture;
use App\Jobs\AnalyzeJobPosting;
use App\Models\JobCapture;
use App\Models\JobPosting;
use App\Models\User;
use App\Services\JobAnalysisService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// As páginas do painel exigem usuário autenticado; a API exige o token.
beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
    config(['capture.api_token' => 'test-token']);
});

function captureHeaders(): array
{
    return [
        'Authorization' => 'Bearer test-token',
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
    ];
}

function capturePayload(array $overrides = []): array
{
    return array_merge([
        'source' => 'linkedin',
        'external_id' => 'urn:li:activity:111',
        'url' => 'https://www.linkedin.com/feed/update/urn:li:activity:111?lipi=abc',
        'search_url' => 'https://www.linkedin.com/search/results/content/?keywords=vaga%20php',
        'title' => null,
        'description' => 'Vaga para desenvolvedor PHP com Laravel e PostgreSQL.',
        'company' => null,
        'location' => null,
        'author' => ['name' => 'Maria Silva', 'profile_url' => 'https://www.linkedin.com/in/maria'],
        'captured_at' => '2026-08-18T10:00:00Z',
    ], $overrides);
}

it('rejeita sem token e com token errado', function () {
    $this->postJson('/api/job-captures', capturePayload())->assertStatus(401);

    $this->postJson('/api/job-captures', capturePayload(), [
        'Authorization' => 'Bearer token-errado',
        'Accept' => 'application/json',
    ])->assertStatus(401);
});

it('responde 503 quando o token não está configurado', function () {
    config(['capture.api_token' => '']);

    $this->postJson('/api/job-captures', capturePayload(), captureHeaders())
        ->assertStatus(503)
        ->assertJson(['message' => 'API de captura não configurada (VAGAS_API_TOKEN).']);
});

it('armazena payload completo com campos mapeados e responde 201', function () {
    $response = $this->postJson('/api/job-captures', capturePayload(), captureHeaders());

    $response->assertStatus(201)
        ->assertJson(['duplicate' => false])
        ->assertJsonPath('capture.dedup_key', 'ext:urn:li:activity:111')
        ->assertJsonPath('capture.source', 'linkedin')
        ->assertJsonPath('capture.author_name', 'Maria Silva')
        ->assertJsonPath('capture.author_profile_url', 'https://www.linkedin.com/in/maria');

    $capture = JobCapture::query()->sole();

    expect($capture->external_id)->toBe('urn:li:activity:111')
        ->and($capture->url)->toBe('https://www.linkedin.com/feed/update/urn:li:activity:111?lipi=abc')
        ->and($capture->search_url)->toBe('https://www.linkedin.com/search/results/content/?keywords=vaga%20php')
        ->and($capture->description)->toBe('Vaga para desenvolvedor PHP com Laravel e PostgreSQL.')
        ->and($capture->author_name)->toBe('Maria Silva')
        ->and($capture->author_profile_url)->toBe('https://www.linkedin.com/in/maria')
        ->and($capture->captured_at->toIso8601String())->toBe('2026-08-18T10:00:00+00:00')
        ->and($capture->job_posting_id)->toBeNull();
});

it('deduplica pelo external_id e responde 200 duplicate:true', function () {
    $this->postJson('/api/job-captures', capturePayload(), captureHeaders())->assertStatus(201);

    $this->postJson('/api/job-captures', capturePayload(), captureHeaders())
        ->assertStatus(200)
        ->assertJson(['duplicate' => true]);

    expect(JobCapture::query()->count())->toBe(1);
});

it('deduplica pela url normalizada ignorando query de tracking', function () {
    $payload = capturePayload(['external_id' => null]);
    $this->postJson('/api/job-captures', $payload, captureHeaders())->assertStatus(201);

    $this->postJson('/api/job-captures', capturePayload([
        'external_id' => null,
        'url' => 'https://www.linkedin.com/feed/update/urn:li:activity:111?lipi=outra&trk=tracking',
    ]), captureHeaders())
        ->assertStatus(200)
        ->assertJson(['duplicate' => true]);

    expect(JobCapture::query()->count())->toBe(1);
});

it('deduplica pela descrição quando não há external_id nem url', function () {
    $payload = capturePayload(['external_id' => null, 'url' => null]);
    $this->postJson('/api/job-captures', $payload, captureHeaders())->assertStatus(201);

    $this->postJson('/api/job-captures', capturePayload([
        'external_id' => null,
        'url' => null,
        'description' => 'Vaga para desenvolvedor PHP com Laravel e PostgreSQL. ',
    ]), captureHeaders())
        ->assertStatus(200)
        ->assertJson(['duplicate' => true]);

    expect(JobCapture::query()->count())->toBe(1);
});

it('rejeita payload sem source e sem external_id/url/description', function () {
    $this->postJson('/api/job-captures', [
        'source' => null,
        'external_id' => null,
        'url' => null,
        'description' => null,
    ], captureHeaders())->assertStatus(422);
});

it('responde ao preflight CORS do LinkedIn', function () {
    $this->options('/api/job-captures', [], [
        'Origin' => 'https://www.linkedin.com',
        'Access-Control-Request-Method' => 'POST',
        'Access-Control-Request-Headers' => 'authorization,content-type',
    ])->assertStatus(204)
        ->assertHeader('Access-Control-Allow-Origin', '*');
});

it('promove a captura a vaga e despacha a análise de IA', function () {
    Queue::fake();

    $capture = JobCapture::factory()->create(['description' => str_repeat('Vaga PHP sênior com Laravel. ', 5)]);

    Livewire::test(ViewJobCapture::class, ['record' => $capture->id])
        ->callAction('promote');

    $posting = JobPosting::query()->sole();

    expect($posting->status)->toBe(JobPostingStatus::Pending)
        ->and($posting->description)->toBe($capture->description)
        ->and($posting->source_url)->toBe($capture->url)
        ->and($posting->collected_at->equalTo($capture->captured_at))->toBeTrue();

    Queue::assertPushed(AnalyzeJobPosting::class, fn (AnalyzeJobPosting $job) => $job->posting->is($posting));

    expect($capture->refresh()->job_posting_id)->toBe($posting->id);
});

it('não promove captura com descrição curta demais', function () {
    Queue::fake();

    $capture = JobCapture::factory()->create(['description' => 'Texto curto.']);

    Livewire::test(ViewJobCapture::class, ['record' => $capture->id])
        ->callAction('promote');

    expect(JobPosting::query()->count())->toBe(0)
        ->and($capture->refresh()->job_posting_id)->toBeNull();

    Queue::assertNothingPushed();
});

it('esconde a ação de promoção para captura já promovida', function () {
    Queue::fake();

    $posting = JobPosting::factory()->create();
    $capture = JobCapture::factory()->create(['job_posting_id' => $posting->id]);

    Livewire::test(ViewJobCapture::class, ['record' => $capture->id])
        ->assertActionHidden('promote');

    expect(JobPosting::query()->count())->toBe(1);
});

it('impede no banco dois registros com mesmo source e external_id', function () {
    JobCapture::factory()->create(['source' => 'linkedin', 'external_id' => 'urn:li:activity:777', 'dedup_key' => 'ext:urn:li:activity:777']);

    expect(fn () => JobCapture::create([
        'source' => 'linkedin',
        'external_id' => 'urn:li:activity:777',
        'dedup_key' => 'content:'.hash('sha256', 'outra chave'), // burla o service de propósito
        'description' => 'duplicata',
        'captured_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(JobCapture::where('external_id', 'urn:li:activity:777')->count())->toBe(1);
});
