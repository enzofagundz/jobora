<?php

use App\Enums\JobCaptureTriageResult;
use App\Enums\JobCaptureTriageStatus;
use App\Enums\JobPostingStatus;
use App\Filament\Resources\JobCaptures\Pages\ListJobCaptures;
use App\Jobs\AnalyzeJobPosting;
use App\Models\JobCapture;
use App\Models\JobPosting;
use App\Models\User;
use App\Services\CaptureTokenService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// As páginas do painel exigem usuário autenticado; a API exige o token por usuário.
beforeEach(function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    $token = app(CaptureTokenService::class)->issueToken($user);
    $GLOBALS['captureToken'] = $token;
});

function captureHeaders(): array
{
    $token = $GLOBALS['captureToken'] ?? '';

    return [
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
    ];
}

function captureHeadersWithToken(string $token): array
{
    return [
        'Authorization' => 'Bearer '.$token,
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

it('rejeita quando a credencial não está configurada', function () {
    $user = auth()->user();
    app(CaptureTokenService::class)->revokeToken($user);

    $this->postJson('/api/job-captures', capturePayload(), captureHeaders())
        ->assertStatus(401)
        ->assertJson(['message' => 'Token inválido.']);

    $this->postJson('/api/job-captures', capturePayload())->assertStatus(401);
});

it('armazena payload completo com campos mapeados e responde 201', function () {
    $response = $this->postJson('/api/job-captures', capturePayload(), captureHeaders());

    $response->assertStatus(201)
        ->assertJson(['duplicate' => false])
        ->assertJsonPath('capture.dedup_key', 'ext:urn:li:activity:111')
        ->assertJsonPath('capture.source', 'linkedin')
        ->assertJsonPath('capture.author_name', 'Maria Silva')
        ->assertJsonPath('capture.author_profile_url', 'https://www.linkedin.com/in/maria');

    expect($response->json('capture.deleted_at'))->toBeNull()
        ->and(array_key_exists('deleted_at', $response->json('capture') ?? []))->toBeFalse();

    $capture = JobCapture::query()->sole();

    expect($capture->external_id)->toBe('urn:li:activity:111')
        ->and($capture->url)->toBe('https://www.linkedin.com/feed/update/urn:li:activity:111?lipi=abc')
        ->and($capture->search_url)->toBe('https://www.linkedin.com/search/results/content/?keywords=vaga%20php')
        ->and($capture->description)->toBe('Vaga para desenvolvedor PHP com Laravel e PostgreSQL.')
        ->and($capture->author_name)->toBe('Maria Silva')
        ->and($capture->author_profile_url)->toBe('https://www.linkedin.com/in/maria')
        ->and($capture->captured_at->toIso8601String())->toBe('2026-08-18T10:00:00+00:00')
        ->and($capture->job_posting_id)->toBeNull()
        ->and($capture->deleted_at)->toBeNull();

    expect(JobCapture::withTrashed()->count())->toBe(1)
        ->and(JobCapture::onlyTrashed()->count())->toBe(0);
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

it('deduplica pelo fingerprint composto quando não há external_id nem url', function () {
    $payload = capturePayload(['external_id' => null, 'url' => null]);
    $this->postJson('/api/job-captures', $payload, captureHeaders())->assertStatus(201);

    // Mesmo post com whitespace/caixa diferentes e captured_at/search_url novos.
    $this->postJson('/api/job-captures', capturePayload([
        'external_id' => null,
        'url' => null,
        'description' => '  VAGA   para Desenvolvedor PHP com Laravel e PostgreSQL.  ',
        'captured_at' => '2026-08-19T18:30:00Z',
        'search_url' => 'https://www.linkedin.com/search/results/content/?keywords=outro',
    ]), captureHeaders())
        ->assertStatus(200)
        ->assertJson(['duplicate' => true]);

    expect(JobCapture::query()->count())->toBe(1)
        ->and(JobCapture::query()->sole()->dedup_key)->toStartWith('fingerprint:');
});

it('nao deduplica por descricao isolada: campos estaveis diferentes criam duas linhas', function () {
    $payload = capturePayload(['external_id' => null, 'url' => null]);
    $this->postJson('/api/job-captures', $payload, captureHeaders())->assertStatus(201);

    $this->postJson('/api/job-captures', capturePayload([
        'external_id' => null,
        'url' => null,
        'company' => 'Acme',
    ]), captureHeaders())->assertStatus(201);

    expect(JobCapture::query()->count())->toBe(2);
});

it('tres posts identicos produzem uma linha e respostas 201/200/200', function () {
    foreach ([201, 200, 200] as $expectedStatus) {
        $this->postJson('/api/job-captures', capturePayload(), captureHeaders())
            ->assertStatus($expectedStatus);
    }

    expect(JobCapture::query()->count())->toBe(1);
});

it('mesmo external_id com descricao e url diferentes deixa uma linha', function () {
    $this->postJson('/api/job-captures', capturePayload(), captureHeaders())->assertStatus(201);

    $this->postJson('/api/job-captures', capturePayload([
        'url' => 'https://www.linkedin.com/feed/update/outro',
        'description' => 'Texto completamente diferente.',
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

    $capture = JobCapture::factory()->for(auth()->user(), 'user')->create(['description' => str_repeat('Vaga PHP sênior com Laravel. ', 5)]);

    Livewire::test(ListJobCaptures::class)
        ->callTableAction('promote', $capture);

    $posting = JobPosting::query()->sole();

    expect($posting->status)->toBe(JobPostingStatus::Pending)
        ->and($posting->description)->toBe($capture->description)
        ->and($posting->source_url)->toBe($capture->url)
        ->and($posting->collected_at->equalTo($capture->captured_at))->toBeTrue();

    Queue::assertPushed(AnalyzeJobPosting::class, fn (AnalyzeJobPosting $job) => $job->posting->is($posting) && $job->userId === auth()->id());

    expect($capture->refresh()->job_posting_id)->toBe($posting->id);
});

it('não promove captura com descrição curta demais', function () {
    Queue::fake();

    $capture = JobCapture::factory()->for(auth()->user(), 'user')->create(['description' => 'Texto curto.']);

    Livewire::test(ListJobCaptures::class)
        ->callTableAction('promote', $capture);

    expect(JobPosting::query()->count())->toBe(0)
        ->and($capture->refresh()->job_posting_id)->toBeNull();

    Queue::assertNothingPushed();
});

it('esconde a ação de promoção para captura já promovida', function () {
    Queue::fake();

    $posting = JobPosting::factory()->create();
    $capture = JobCapture::factory()->for(auth()->user(), 'user')->create(['job_posting_id' => $posting->id]);

    Livewire::test(ListJobCaptures::class)
        ->assertTableActionHidden('promote', $capture);

    expect(JobPosting::query()->count())->toBe(1);
});

it('renderiza o modal da ação view com o conteúdo da captura', function () {
    $capture = JobCapture::factory()->for(auth()->user(), 'user')->create([
        'description' => 'Vaga para desenvolvedor PHP com Laravel e PostgreSQL.',
        'company' => 'Acme Ltda',
        'location' => 'Remoto',
        'triage_status' => JobCaptureTriageStatus::Completed,
        'triage_result' => JobCaptureTriageResult::Relevant,
        'triage_reason' => 'Vaga de Laravel; atende às preferências.',
    ]);

    $modalHtml = Livewire::test(ListJobCaptures::class)
        ->mountTableAction('view', $capture)
        ->assertActionMounted()
        ->getMountedActionModalHtml();

    expect($modalHtml)
        ->toContain('Vaga para desenvolvedor PHP com Laravel e PostgreSQL.')
        ->toContain('Acme Ltda')
        ->toContain('Remoto')
        ->toContain('Triagem concluída')
        ->toContain('Relevante')
        ->toContain('Vaga de Laravel; atende às preferências.');
});

it('abre o modal da ação view da tabela com os detalhes da captura', function () {
    $capture = JobCapture::factory()->for(auth()->user(), 'user')->create();

    $modalHtml = Livewire::test(ListJobCaptures::class)
        ->mountTableAction('view', $capture)
        ->assertActionMounted()
        ->getMountedActionModalHtml();

    expect($modalHtml)
        ->toContain('Fechar')
        ->not->toContain('Salvar');
});

it('exclui captura pela ação da tabela mantendo a vaga promovida', function () {
    $posting = JobPosting::factory()->create();
    $capture = JobCapture::factory()->for(auth()->user(), 'user')->create(['job_posting_id' => $posting->id]);

    Livewire::test(ListJobCaptures::class)
        ->callTableAction('delete', $capture);

    expect(JobCapture::query()->count())->toBe(0)
        ->and(JobCapture::withTrashed()->count())->toBe(1)
        ->and(JobCapture::onlyTrashed()->count())->toBe(1)
        ->and(JobCapture::onlyTrashed()->sole()->deleted_at)->not->toBeNull()
        ->and(JobCapture::withTrashed()->sole()->id)->toBe($capture->id)
        ->and(JobPosting::query()->count())->toBe(1);
});

it('exclui capturas selecionadas em lote', function () {
    $c1 = JobCapture::factory()->for(auth()->user(), 'user')->create();
    $c2 = JobCapture::factory()->for(auth()->user(), 'user')->create();

    Livewire::test(ListJobCaptures::class)
        ->callTableBulkAction('delete', [$c1, $c2]);

    expect(JobCapture::query()->count())->toBe(0)
        ->and(JobCapture::withTrashed()->count())->toBe(2)
        ->and(JobCapture::onlyTrashed()->count())->toBe(2);

    $trashed = JobCapture::onlyTrashed()->get();

    expect($trashed->pluck('deleted_at')->filter()->count())->toBe(2)
        ->and($trashed->pluck('id')->sort()->values()->all())->toEqualCanonicalizing([$c1->id, $c2->id]);
});

it('filtra capturas pela situação promovida/pendente', function () {
    $posting = JobPosting::factory()->create();
    JobCapture::factory()->for(auth()->user(), 'user')->create(['job_posting_id' => $posting->id, 'description' => 'Vaga promovida única.']);
    JobCapture::factory()->for(auth()->user(), 'user')->create(['description' => 'Vaga pendente única.']);

    Livewire::test(ListJobCaptures::class)
        ->filterTable('situacao', 'promoted')
        ->assertSee('Vaga promovida única.')
        ->assertDontSee('Vaga pendente única.')
        ->filterTable('situacao', 'pending')
        ->assertSee('Vaga pendente única.')
        ->assertDontSee('Vaga promovida única.');
});

it('impede no banco dois registros com mesmo source e external_id', function () {
    JobCapture::factory()->for(auth()->user(), 'user')->create(['source' => 'linkedin', 'external_id' => 'urn:li:activity:777', 'dedup_key' => 'ext:urn:li:activity:777']);

    expect(fn () => auth()->user()->jobCaptures()->create([
        'source' => 'linkedin',
        'external_id' => 'urn:li:activity:777',
        'dedup_key' => 'content:'.hash('sha256', 'outra chave'), // burla o service de propósito
        'description' => 'duplicata',
        'captured_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(JobCapture::where('external_id', 'urn:li:activity:777')->count())->toBe(1);
});
it('deduplica POST idêntico: 201 depois 200 com mesmo id e uma linha', function () {
    $payload = capturePayload(['external_id' => 'urn:li:activity:9001', 'url' => null]);

    $first = $this->postJson('/api/job-captures', $payload, captureHeaders())
        ->assertStatus(201)
        ->assertJson(['duplicate' => false])
        ->json('capture.id');

    $second = $this->postJson('/api/job-captures', $payload, captureHeaders())
        ->assertStatus(200)
        ->assertJson(['duplicate' => true])
        ->json('capture.id');

    expect($second)->toBe($first)
        ->and(JobCapture::query()->count())->toBe(1);
});

it('deduplica cinco envios sequenciais do mesmo payload (e dez)', function () {
    $payload = capturePayload(['external_id' => null, 'url' => null, 'description' => 'Vaga para desenvolvedor PHP com Laravel — stress sequencial.']);

    $ids = [];
    $statuses = [];
    $duplicates = [];

    for ($i = 0; $i < 5; $i++) {
        $response = $this->postJson('/api/job-captures', $payload, captureHeaders());
        $ids[] = $response->json('capture.id');
        $statuses[] = $response->status();
        $duplicates[] = $response->json('duplicate');
    }

    expect($statuses[0])->toBe(201)
        ->and($duplicates[0])->toBeFalse()
        ->and(array_slice($statuses, 1))->each->toBe(200)
        ->and(array_slice($duplicates, 1))->each->toBeTrue()
        ->and(array_unique($ids))->toHaveCount(1)
        ->and(JobCapture::query()->count())->toBe(1);

    // dez envios do mesmo payload em outro cenário (ext) — prova que 10 também escala
    $payloadExt = capturePayload(['external_id' => 'urn:li:activity:9002', 'url' => null]);
    // reseta banco isolado por RefreshDatabase: este segundo lote começa do zero? Na verdade já temos 1 linha; usar payload distinto para dez
    // Para provar dez, criamos novo payloadExt e enviamos 10 vezes esperando 1 criação
    JobCapture::query()->delete();
    $ids10 = [];
    for ($i = 0; $i < 10; $i++) {
        $ids10[] = $this->postJson('/api/job-captures', $payloadExt, captureHeaders())->json('capture.id');
    }
    expect(array_unique($ids10))->toHaveCount(1)
        ->and(JobCapture::query()->count())->toBe(1);
});

it('não deduplica payloads com identidade fingerprint diferente', function () {
    $a = capturePayload(['external_id' => null, 'url' => null, 'description' => 'Vaga A — desenvolvedor PHP Laravel Senior.']);
    $b = capturePayload(['external_id' => null, 'url' => null, 'description' => 'Vaga B — desenvolvedor Python FastAPI Senior, stack totalmente distinta.']);

    $idA = $this->postJson('/api/job-captures', $a, captureHeaders())->assertStatus(201)->json('capture.id');
    $idB = $this->postJson('/api/job-captures', $b, captureHeaders())->assertStatus(201)->json('capture.id');

    expect($idA)->not->toBe($idB)
        ->and(JobCapture::query()->count())->toBe(2);
});

it('não colapsa quando author_profile_url difere mesmo com descrição igual', function () {
    $base = ['external_id' => null, 'url' => null, 'description' => 'Vaga idêntica — só muda o autor.'];

    $a = capturePayload(array_merge($base, ['author' => ['name' => null, 'profile_url' => 'https://www.linkedin.com/in/autor-a']]));
    $b = capturePayload(array_merge($base, ['author' => ['name' => null, 'profile_url' => 'https://www.linkedin.com/in/autor-b']]));

    $this->postJson('/api/job-captures', $a, captureHeaders())->assertStatus(201);
    $this->postJson('/api/job-captures', $b, captureHeaders())->assertStatus(201);

    expect(JobCapture::query()->count())->toBe(2);
});

it('deduplica captura linkedin sem identificadores ignorando author_name volátil e sufixo … mais (220/223)', function () {
    $descricao = "🚨 Estamos contratando 🚨\n\n🚀Venha fazer parte do time da ANS4 CONSULTORIA!!!\n\n📌 Vaga: Desenvolvedor Full Stack Sênior\nModelo de contratação: PJ\nModalidade: Remoto\n\nRequisitos:\nExperiência sólida como Desenvolvedor(a) Full Stack Sênior;";
    $perfil = 'https://www.linkedin.com/in/joana-luisa-ara%C3%BAjo-9338842a8/';

    $primeiro = capturePayload([
        'external_id' => null,
        'url' => null,
        'title' => null,
        'company' => null,
        'location' => null,
        'description' => $descricao,
        'author' => ['name' => null, 'profile_url' => $perfil],
    ]);

    $segundo = capturePayload([
        'external_id' => null,
        'url' => null,
        'title' => null,
        'company' => null,
        'location' => null,
        'description' => $descricao.'… mais',
        'author' => ['name' => 'Ver perfil de Joana Luisa Araújo', 'profile_url' => $perfil],
    ]);

    $idPrimeiro = $this->postJson('/api/job-captures', $primeiro, captureHeaders())
        ->assertStatus(201)
        ->assertJson(['duplicate' => false])
        ->json('capture.id');

    $respostaSegundo = $this->postJson('/api/job-captures', $segundo, captureHeaders())
        ->assertStatus(200)
        ->assertJson(['duplicate' => true]);

    expect($respostaSegundo->json('capture.id'))->toBe($idPrimeiro)
        ->and(JobCapture::query()->count())->toBe(1)
        ->and(JobCapture::query()->sole()->author_profile_url)->toBe($perfil);
});

it('deduplica fingerprint sufixo "... mais" ascii também', function () {
    $payloadA = capturePayload(['external_id' => null, 'url' => null, 'description' => 'Vaga Python Sênior — stack idêntica']);
    $payloadB = capturePayload(['external_id' => null, 'url' => null, 'description' => 'Vaga Python Sênior — stack idêntica... mais']);

    $idA = $this->postJson('/api/job-captures', $payloadA, captureHeaders())->assertStatus(201)->json('capture.id');
    $idB = $this->postJson('/api/job-captures', $payloadB, captureHeaders())->assertStatus(200)->json('capture.id');

    expect($idB)->toBe($idA)
        ->and(JobCapture::query()->count())->toBe(1);
});

it('compatibilidade histórica: reenvio reconhece linha content: legada sem criar nova', function () {
    $descricao = 'Descrição histórica legada — vaga PHP Laravel com PostgreSQL.';
    $canonica = mb_strtolower(preg_replace('/\s+/u', ' ', trim($descricao)) ?? $descricao);

    $legada = auth()->user()->jobCaptures()->create([
        'source' => 'linkedin',
        'external_id' => null,
        'dedup_key' => 'content:'.hash('sha256', $canonica),
        'url' => null,
        'search_url' => null,
        'title' => null,
        'description' => $descricao,
        'company' => null,
        'location' => null,
        'author_name' => null,
        'author_profile_url' => null,
        'captured_at' => now(),
    ]);

    $payload = capturePayload([
        'external_id' => null,
        'url' => null,
        'title' => null,
        'company' => null,
        'location' => null,
        'description' => $descricao,
        'author' => ['name' => null, 'profile_url' => null],
    ]);

    $response = $this->postJson('/api/job-captures', $payload, captureHeaders())
        ->assertStatus(200)
        ->assertJson(['duplicate' => true]);

    expect($response->json('capture.id'))->toBe($legada->id)
        ->and(JobCapture::query()->count())->toBe(1);
});

it('prioriza linha promovida na compatibilidade histórica fingerprint', function () {
    // Cria duas linhas históricas fingerprint: antigas com author_name no hash, sem dedup canônico atual
    // Simulamos ao inserir manualmente com dedup_key fingerprint antigo (incluía author_name) mas com mesma identidade canônica nova
    $descricao = 'Vaga promovida vs pendente — mesmo conteúdo.';
    $perfil = 'https://www.linkedin.com/in/autor-promovido';

    // Para forçar colisão canônica, inserimos duas linhas legadas com dedup fingerprint divergente do novo,
    // mas que colidem quando normalizadas pelo novo algoritmo.
    $antigaPendente = auth()->user()->jobCaptures()->create([
        'source' => 'linkedin',
        'external_id' => null,
        'dedup_key' => 'fingerprint:legado-pendente-'.uniqid(),
        'url' => null,
        'description' => $descricao,
        'author_name' => 'Autor Pendente',
        'author_profile_url' => $perfil,
        'captured_at' => now(),
    ]);

    $posting = JobPosting::factory()->create();
    $antigaPromovida = auth()->user()->jobCaptures()->create([
        'source' => 'linkedin',
        'external_id' => null,
        'dedup_key' => 'fingerprint:legado-promovido-'.uniqid(),
        'url' => null,
        'description' => $descricao,
        'author_name' => null,
        'author_profile_url' => $perfil,
        'job_posting_id' => $posting->id,
        'captured_at' => now(),
    ]);

    $payload = capturePayload([
        'external_id' => null,
        'url' => null,
        'description' => $descricao,
        'author' => ['name' => null, 'profile_url' => $perfil],
    ]);

    $response = $this->postJson('/api/job-captures', $payload, captureHeaders())
        ->assertStatus(200)
        ->assertJson(['duplicate' => true]);

    // Deve retornar a promovida, não a pendente, e não criar nova
    expect($response->json('capture.id'))->toBe($antigaPromovida->id)
        ->and(JobCapture::query()->count())->toBe(2);
});

// --- Soft Deletes adicionais conforme plano ---

it('deduplica após exclusão por identidade (external_id, url e fingerprint)', function (array $overrides) {
    $payload = capturePayload($overrides);

    $first = $this->postJson('/api/job-captures', $payload, captureHeaders())
        ->assertStatus(201)
        ->assertJson(['duplicate' => false]);

    $firstId = $first->json('capture.id');

    expect($first->json('capture.deleted_at'))->toBeNull()
        ->and(array_key_exists('deleted_at', $first->json('capture') ?? []))->toBeFalse();

    expect(JobCapture::query()->count())->toBe(1)
        ->and(JobCapture::withTrashed()->count())->toBe(1)
        ->and(JobCapture::onlyTrashed()->count())->toBe(0);

    $capture = JobCapture::query()->sole();

    Livewire::test(ListJobCaptures::class)
        ->callTableAction('delete', $capture);

    expect(JobCapture::query()->count())->toBe(0)
        ->and(JobCapture::withTrashed()->count())->toBe(1)
        ->and(JobCapture::onlyTrashed()->count())->toBe(1)
        ->and(JobCapture::onlyTrashed()->sole()->deleted_at)->not->toBeNull()
        ->and(JobCapture::withTrashed()->sole()->id)->toBe($firstId);

    $second = $this->postJson('/api/job-captures', $payload, captureHeaders())
        ->assertStatus(200)
        ->assertJson(['duplicate' => true]);

    expect($second->json('capture.id'))->toBe($firstId)
        ->and($second->json('capture.deleted_at'))->toBeNull()
        ->and(array_key_exists('deleted_at', $second->json('capture') ?? []))->toBeFalse();

    expect(JobCapture::query()->count())->toBe(0)
        ->and(JobCapture::withTrashed()->count())->toBe(1)
        ->and(JobCapture::onlyTrashed()->count())->toBe(1)
        ->and(JobCapture::withTrashed()->sole()->id)->toBe($firstId);
})->with([
    'external_id' => [['external_id' => 'urn:li:activity:soft-external-1', 'url' => 'https://www.linkedin.com/feed/update/urn:li:activity:soft-external-1', 'description' => 'Vaga soft delete external id.']],
    'url canonica' => [['external_id' => null, 'url' => 'https://www.linkedin.com/feed/update/urn:li:activity:soft-url-1?lipi=tracking', 'description' => 'Vaga soft delete url canônica.']],
    'fingerprint' => [['external_id' => null, 'url' => null, 'description' => 'Vaga soft delete fingerprint PHP Laravel PostgreSQL.', 'company' => null, 'location' => null, 'author' => ['name' => null, 'profile_url' => 'https://www.linkedin.com/in/soft-test']]],
]);

it('compatibilidade histórica soft-deleted content: reenvio reconhece linha legada excluída', function () {
    $descricao = 'Descrição histórica soft-deleted — vaga PHP Laravel com PostgreSQL.';
    $canonica = mb_strtolower(preg_replace('/\s+/u', ' ', trim($descricao)) ?? $descricao);

    $legada = auth()->user()->jobCaptures()->create([
        'source' => 'linkedin',
        'external_id' => null,
        'dedup_key' => 'content:'.hash('sha256', $canonica),
        'url' => null,
        'search_url' => null,
        'title' => null,
        'description' => $descricao,
        'company' => null,
        'location' => null,
        'author_name' => null,
        'author_profile_url' => null,
        'captured_at' => now(),
    ]);

    $legada->delete();

    expect(JobCapture::query()->count())->toBe(0)
        ->and(JobCapture::withTrashed()->count())->toBe(1)
        ->and(JobCapture::onlyTrashed()->sole()->deleted_at)->not->toBeNull();

    $payload = capturePayload([
        'external_id' => null,
        'url' => null,
        'title' => null,
        'company' => null,
        'location' => null,
        'description' => $descricao,
        'author' => ['name' => null, 'profile_url' => null],
    ]);

    $response = $this->postJson('/api/job-captures', $payload, captureHeaders())
        ->assertStatus(200)
        ->assertJson(['duplicate' => true]);

    expect($response->json('capture.id'))->toBe($legada->id)
        ->and($response->json('capture.deleted_at'))->toBeNull()
        ->and(array_key_exists('deleted_at', $response->json('capture') ?? []))->toBeFalse()
        ->and(JobCapture::query()->count())->toBe(0)
        ->and(JobCapture::withTrashed()->count())->toBe(1)
        ->and(JobCapture::onlyTrashed()->count())->toBe(1);
});

it('captura B com identidade diferente após exclusão de A cria nova linha', function () {
    $payloadA = capturePayload(['external_id' => 'urn:li:activity:soft-diff-A', 'url' => 'https://www.linkedin.com/feed/update/urn:li:activity:soft-diff-A', 'description' => 'Vaga A soft delete distinta.']);
    $payloadB = capturePayload(['external_id' => 'urn:li:activity:soft-diff-B', 'url' => 'https://www.linkedin.com/feed/update/urn:li:activity:soft-diff-B', 'description' => 'Vaga B soft delete distinta.']);

    $first = $this->postJson('/api/job-captures', $payloadA, captureHeaders())
        ->assertStatus(201)
        ->assertJson(['duplicate' => false]);

    $idA = $first->json('capture.id');

    $captureA = JobCapture::query()->sole();

    Livewire::test(ListJobCaptures::class)
        ->callTableAction('delete', $captureA);

    expect(JobCapture::query()->count())->toBe(0)
        ->and(JobCapture::withTrashed()->count())->toBe(1)
        ->and(JobCapture::onlyTrashed()->count())->toBe(1);

    $second = $this->postJson('/api/job-captures', $payloadB, captureHeaders())
        ->assertStatus(201)
        ->assertJson(['duplicate' => false]);

    $idB = $second->json('capture.id');

    expect($idB)->not->toBe($idA)
        ->and(JobCapture::query()->count())->toBe(1)
        ->and(JobCapture::withTrashed()->count())->toBe(2)
        ->and(JobCapture::onlyTrashed()->count())->toBe(1)
        ->and(JobCapture::query()->sole()->id)->toBe($idB)
        ->and(JobCapture::onlyTrashed()->sole()->id)->toBe($idA);
});

it('constraint UNIQUE (source, external_id) permanece ocupada após soft delete', function () {
    $original = JobCapture::factory()->for(auth()->user(), 'user')->create([
        'source' => 'linkedin',
        'external_id' => 'urn:li:activity:constraint-soft',
        'dedup_key' => 'ext:urn:li:activity:constraint-soft',
    ]);

    $original->delete();

    expect(JobCapture::query()->count())->toBe(0)
        ->and(JobCapture::withTrashed()->count())->toBe(1)
        ->and(JobCapture::onlyTrashed()->sole()->deleted_at)->not->toBeNull();

    expect(fn () => auth()->user()->jobCaptures()->create([
        'source' => 'linkedin',
        'external_id' => 'urn:li:activity:constraint-soft',
        'dedup_key' => 'content:'.hash('sha256', 'outra chave'),
        'description' => 'duplicata após soft delete',
        'captured_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(JobCapture::withTrashed()->count())->toBe(1)
        ->and(JobCapture::onlyTrashed()->count())->toBe(1)
        ->and(JobCapture::withTrashed()->sole()->id)->toBe($original->id);
});

it('filament oculta captura soft-deleted da listagem normal', function () {
    $capture = JobCapture::factory()->for(auth()->user(), 'user')->create(['description' => 'Vaga oculta soft delete para filament — única.']);

    Livewire::test(ListJobCaptures::class)
        ->assertSee('Vaga oculta soft delete para filament — única.');

    Livewire::test(ListJobCaptures::class)
        ->callTableAction('delete', $capture);

    expect(JobCapture::query()->count())->toBe(0)
        ->and(JobCapture::withTrashed()->count())->toBe(1);

    Livewire::test(ListJobCaptures::class)
        ->assertDontSee('Vaga oculta soft delete para filament — única.')
        ->assertCanNotSeeTableRecords([$capture]);
});
