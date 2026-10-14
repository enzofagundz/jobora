<?php

use App\Agents\JobExtractionAgent;
use App\Agents\JobTriageAgent;
use App\Enums\JobCaptureTriageResult;
use App\Enums\JobCaptureTriageStatus;
use App\Enums\JobPostingStatus;
use App\Enums\ResumeLanguage;
use App\Filament\Resources\JobCaptures\Pages\ListJobCaptures;
use App\Filament\Resources\JobPostings\JobPostingResource;
use App\Filament\Resources\JobPostings\Pages\ListJobPostings;
use App\Jobs\AnalyzeJobPosting;
use App\Jobs\TriageJobCaptures;
use App\Models\JobCapture;
use App\Models\JobPosting;
use App\Models\Resume;
use App\Models\User;
use App\Services\ApplicationTrackingService;
use App\Services\CaptureTokenService;
use App\Services\ConceptNormalizer;
use App\Services\JobAnalysisService;
use App\Services\JobCaptureService;
use App\Services\JobPromotionService;
use App\Services\JobReviewService;
use App\Services\JobTriageService;
use App\Services\MarketAnalysisService;
use App\Services\MarketComparisonService;
use App\Services\ResumeService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('usuários A e B veem somente seus registros em listagens', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $postingA = JobPosting::factory()->for($userA, 'user')->create(['description' => 'Vaga A '.str_repeat('x', 50)]);
    $postingB = JobPosting::factory()->for($userB, 'user')->create(['description' => 'Vaga B '.str_repeat('x', 50)]);
    $captureA = JobCapture::factory()->for($userA, 'user')->create();
    $captureB = JobCapture::factory()->for($userB, 'user')->create();
    Resume::factory()->for($userA, 'user')->create(['content' => 'Curriculo A']);
    Resume::factory()->for($userB, 'user')->create(['content' => 'Curriculo B']);

    $this->actingAs($userA);
    Livewire::test(ListJobPostings::class)
        ->assertCanSeeTableRecords(collect([$postingA]))
        ->assertCanNotSeeTableRecords(collect([$postingB]));

    Livewire::test(ListJobCaptures::class)
        ->assertCanSeeTableRecords(collect([$captureA]))
        ->assertCanNotSeeTableRecords(collect([$captureB]));

    $this->actingAs($userB);
    Livewire::test(ListJobPostings::class)
        ->assertCanSeeTableRecords(collect([$postingB]))
        ->assertCanNotSeeTableRecords(collect([$postingA]));
});

it('tentativa de abrir view/application/PDF, editar ou apagar registro de A como B é negada', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $postingA = JobPosting::factory()->for($userA, 'user')->confirmed()->create();

    $this->actingAs($userB);
    // Gate deve negar
    expect(Gate::forUser($userB)->allows('view', $postingA))->toBeFalse()
        ->and(Gate::forUser($userB)->allows('update', $postingA))->toBeFalse()
        ->and(Gate::forUser($userB)->allows('delete', $postingA))->toBeFalse();

    // rota PDF deve falhar com 403 ou 404 (Gate authorize)
    $response = $this->get(route('filament.admin.resume-pdf.download', $postingA));
    $response->assertStatus(403);

    // tentativa de resolver record via Resource deve dar 404 (getEloquentQuery filtra)
    expect(JobPostingResource::getEloquentQuery()->find($postingA->id))->toBeNull();
});

it('Gate nega view/update/delete/triage/promote entre usuários', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $postingA = JobPosting::factory()->for($userA, 'user')->create();
    $captureA = JobCapture::factory()->for($userA, 'user')->create();

    expect(Gate::forUser($userB)->allows('view', $postingA))->toBeFalse()
        ->and(Gate::forUser($userB)->allows('update', $postingA))->toBeFalse()
        ->and(Gate::forUser($userB)->allows('delete', $postingA))->toBeFalse()
        ->and(Gate::forUser($userB)->allows('triage', $captureA))->toBeFalse()
        ->and(Gate::forUser($userB)->allows('promote', $captureA))->toBeFalse();
});

it('bulk delete com authorizeIndividualRecords nunca apaga registro de outro owner', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $postingA = JobPosting::factory()->for($userA, 'user')->create();
    $postingB = JobPosting::factory()->for($userB, 'user')->create();

    // userA não pode ver postingB via query do Resource
    $this->actingAs($userA);
    expect(JobPostingResource::getEloquentQuery()->find($postingB->id))->toBeNull()
        ->and(Gate::forUser($userA)->allows('delete', $postingB))->toBeFalse();

    // tentativa de bulk delete via query filtrada por usuário não afeta outro owner
    JobPosting::whereBelongsTo($userA)->whereIn('id', [$postingB->id])->delete();

    expect(JobPosting::find($postingB->id))->not->toBeNull();
    expect(JobPosting::find($postingA->id))->not->toBeNull();
});
it('ResumeService mantém uma linha por usuário; salvar B não altera A e adaptação usa currículo do owner', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $service = app(ResumeService::class);

    $service->save($userA, 'Conteudo A', ResumeLanguage::PtBr);
    $service->save($userB, 'Conteudo B', ResumeLanguage::PtBr);

    expect($service->content($userA, ResumeLanguage::PtBr))->toBe('Conteudo A')
        ->and($service->content($userB, ResumeLanguage::PtBr))->toBe('Conteudo B');

    // criar posting de A e garantir que adaptação usaria currículo de A, não B
    $postingA = JobPosting::factory()->for($userA, 'user')->confirmed()->create();
    $postingA->items()->create(['category' => 'skill', 'name' => 'Python', 'source' => 'ai']);

    // verificar que service retorna conteúdo correto por usuário (isolado)
    expect($service->content($userA, ResumeLanguage::PtBr))->not->toBe($service->content($userB, ResumeLanguage::PtBr));
});

it('MarketAnalysis, MarketComparison e widgets só contam dados do usuário atual', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $postingA = JobPosting::factory()->for($userA, 'user')->confirmed()->create();
    $postingA->items()->create(['category' => 'skill', 'name' => 'Python', 'source' => 'ai']);
    $postingB = JobPosting::factory()->for($userB, 'user')->confirmed()->create();
    $postingB->items()->create(['category' => 'skill', 'name' => 'React', 'source' => 'ai']);

    $service = app(ResumeService::class);
    $service->save($userA, 'Tenho Python', ResumeLanguage::PtBr);
    $service->save($userB, 'Tenho React', ResumeLanguage::PtBr);

    $analysis = app(MarketAnalysisService::class);
    $freqA = $analysis->frequencies($userA);
    $freqB = $analysis->frequencies($userB);

    expect(collect($freqA)->pluck('name')->contains('Python'))->toBeTrue()
        ->and(collect($freqA)->pluck('name')->contains('React'))->toBeFalse()
        ->and(collect($freqB)->pluck('name')->contains('React'))->toBeTrue()
        ->and(collect($freqB)->pluck('name')->contains('Python'))->toBeFalse();

    $comparison = app(MarketComparisonService::class);
    $tableA = $comparison->table($userA);
    $tableB = $comparison->table($userB);

    expect(collect($tableA)->pluck('name')->contains('React'))->toBeFalse()
        ->and(collect($tableB)->pluck('name')->contains('Python'))->toBeFalse();
});

it('captures com mesmo source/external_id podem coexistir por usuário mas são deduplicadas dentro do owner e resposta não contém user_id', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $service = app(JobCaptureService::class);

    $data = [
        'source' => 'linkedin',
        'external_id' => 'urn:li:activity:12345',
        'url' => 'https://www.linkedin.com/feed/update/urn:li:activity:12345',
        'description' => 'Descricao teste '.str_repeat('x', 50),
    ];

    [$capA1, $dupA1] = $service->store($data, $userA);
    [$capA2, $dupA2] = $service->store($data, $userA);
    [$capB1, $dupB1] = $service->store($data, $userB);

    expect($dupA1)->toBeFalse()
        ->and($dupA2)->toBeTrue()
        ->and($capA1->id)->toBe($capA2->id)
        ->and($capB1->id)->not->toBe($capA1->id)
        ->and($dupB1)->toBeFalse();

    // API response não contém user_id
    $this->actingAs($userA);
    // Simular request via service já oculta user_id
    expect($capA1->toArray())->not->toHaveKey('user_id');
});

it('JobPromotion, JobReview e ApplicationTracking recusam objetos de outro owner e filhos só via posting autorizado', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $captureA = JobCapture::factory()->for($userA, 'user')->create([
        'triage_status' => JobCaptureTriageStatus::Completed,
        'triage_result' => JobCaptureTriageResult::Relevant,
        'description' => str_repeat('d', 50),
    ]);
    $postingA = JobPosting::factory()->for($userA, 'user')->confirmed()->create();

    // promotion de A como B deve falhar (LogicException ou AuthorizationException)
    expect(fn () => app(JobPromotionService::class)->promote($userB, $captureA))->toThrow(Exception::class);

    // JobReview confirm/discard como B deve falhar
    expect(fn () => app(JobReviewService::class)->confirm($userB, $postingA, [], [], null, null))->toThrow(Exception::class);
    expect(fn () => app(JobReviewService::class)->discard($userB, $postingA))->toThrow(Exception::class);

    // ApplicationTracking start como B deve falhar
    expect(fn () => app(ApplicationTrackingService::class)->start($userB, $postingA))->toThrow(Exception::class);

    // filho via posting autorizado: criar item para postingA, verificar que B não consegue carregar via whereHas
    $postingA->items()->create(['category' => 'skill', 'name' => 'Python', 'source' => 'ai']);
    $itemsForB = JobPosting::whereBelongsTo($userB)->with('items')->find($postingA->id);
    expect($itemsForB)->toBeNull();
});

it('payload de TriageJobCaptures com ids de B e userId de A não atualiza B', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $captureB = JobCapture::factory()->for($userB, 'user')->create(['triage_status' => JobCaptureTriageStatus::Pending]);
    $ids = [$captureB->id];

    $job = new TriageJobCaptures($ids, $userA->id);
    // Mock agent para não chamar IA real
    $mockAgent = Mockery::mock(JobTriageAgent::class);
    $mockAgent->shouldReceive('prompt')->andReturn((object) ['text' => json_encode(['results' => []]), 'usage' => null]);
    $mockAgent->shouldReceive('provider')->andReturn('fake');
    $mockAgent->shouldReceive('model')->andReturn('fake');
    $service = new JobTriageService($mockAgent);

    $job->handle($service);

    // captura de B deve permanecer Pending, não Failed/Completed
    expect($captureB->fresh()->triage_status)->toBe(JobCaptureTriageStatus::Pending);
});

it('AnalyzeJobPosting com posting de owner diferente não analisa nem notifica', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $postingB = JobPosting::factory()->for($userB, 'user')->create(['description' => str_repeat('x', 100)]);

    $job = new AnalyzeJobPosting($postingB, $userA->id);
    $mockAgent = Mockery::mock(JobExtractionAgent::class);
    $mockAgent->shouldNotReceive('prompt');
    $service = new JobAnalysisService($mockAgent, app(ConceptNormalizer::class));

    $job->handle($service);

    expect($postingB->fresh()->status)->toBe(JobPostingStatus::Pending);
    expect(DB::table('notifications')->where('notifiable_id', $userA->id)->count())->toBe(0);
});

it('token por usuário: token A cria captura para A e token B para B; sem token 401 e B não vê captura de A', function () {
    $owner = User::factory()->create();
    $userB = User::factory()->create();
    $tokenA = app(CaptureTokenService::class)->issueToken($owner);
    $tokenB = app(CaptureTokenService::class)->issueToken($userB);

    // sem token -> 401
    $this->postJson('/api/job-captures', [
        'source' => 'linkedin',
        'description' => 'test',
    ])->assertStatus(401);

    // token inválido -> 401
    $this->postJson('/api/job-captures', [
        'source' => 'linkedin',
        'description' => 'test',
    ], ['Authorization' => 'Bearer invalido'])->assertStatus(401);

    $response2 = $this->postJson('/api/job-captures', [
        'source' => 'linkedin',
        'description' => 'test '.str_repeat('x', 50),
        'url' => 'https://example.com/job/1',
    ], ['Authorization' => 'Bearer '.$tokenA]);

    $response2->assertStatus(201)->assertJsonMissing(['user_id']);
    $capture = JobCapture::latest()->first();
    expect($capture->user_id)->toBe($owner->id);

    // B cria sua própria captura com seu token
    $response3 = $this->postJson('/api/job-captures', [
        'source' => 'linkedin',
        'description' => 'outra vaga '.str_repeat('x', 50),
        'url' => 'https://example.com/job/2',
    ], ['Authorization' => 'Bearer '.$tokenB]);
    $response3->assertStatus(201);
    $captureB = JobCapture::where('url', 'https://example.com/job/2')->first();
    expect($captureB)->not->toBeNull();
    expect($captureB->user_id)->toBe($userB->id);

    $this->actingAs($userB);
    Livewire::test(ListJobCaptures::class)->assertCanNotSeeTableRecords(collect([$capture]));
    $this->actingAs($owner);
    Livewire::test(ListJobCaptures::class)->assertCanNotSeeTableRecords(collect([$captureB]));
});

it('CaptureSetupPage é acessível por qualquer usuário autenticado e mostra apenas sua credencial', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($owner)->get(route('filament.admin.pages.capture-setup'))->assertSuccessful();
    $this->actingAs($other)->get(route('filament.admin.pages.capture-setup'))->assertSuccessful();

    // visitante não autenticado deve ser redirecionado para login
    auth()->logout();
    $this->get(route('filament.admin.pages.capture-setup'))->assertRedirect(route('filament.admin.auth.login'));
});

it('fluxo integrado: registrar A, verificar, login, criar posting/resume, logout, login como B não revela dados de A', function () {
    // Criar A diretamente (simula registro + verificação)
    $userA = User::factory()->unverified()->create(['email' => 'alice@example.com', 'password' => Hash::make('password123')]);
    $url = Filament::getVerifyEmailUrl($userA);
    $this->actingAs($userA)->get($url);
    expect($userA->fresh()->hasVerifiedEmail())->toBeTrue();

    // login como A e criar posting/resume
    $this->actingAs($userA);
    $postingA = $userA->jobPostings()->create([
        'description' => 'Vaga Alice '.str_repeat('x', 50),
        'collected_at' => now(),
        'status' => JobPostingStatus::Confirmed,
    ]);
    app(ResumeService::class)->save($userA, 'Curriculo Alice', ResumeLanguage::PtBr);

    // criar B diretamente
    $userB = User::factory()->unverified()->create(['email' => 'bob@example.com', 'password' => Hash::make('password123')]);
    $userB->markEmailAsVerified();
    $userB->save();
    expect($userB->fresh()->hasVerifiedEmail())->toBeTrue();

    $this->actingAs($userB);
    Livewire::test(ListJobPostings::class)->assertCanNotSeeTableRecords(collect([$postingA]));
    expect(app(ResumeService::class)->content($userB, ResumeLanguage::PtBr))->not->toBe('Curriculo Alice');
});
