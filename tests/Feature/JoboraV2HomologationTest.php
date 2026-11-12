<?php

use App\Enums\AgentIntegrationScope;
use App\Enums\AiOperationType;
use App\Filament\Pages\TelegramIdentityPage;
use App\Filament\Resources\AgentIntegrations\Pages\ListAgentIntegrations;
use App\Filament\Resources\JobPostings\Pages\ApplicationPage;
use App\Mcp\Support\AgentIntegrationContext;
use App\Models\AgentIntegration;
use App\Models\AiOperation;
use App\Models\PageCapture;
use App\Models\User;
use App\Services\CaptureTokenService;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ProviderRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Livewire\Livewire;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\FakePdfBuilder;
use Tests\DisposableHomologationDatabase;
use Tests\Feature\AgentIntegrations\AgentIntegrationTestHelpers as Helpers;

trait JoboraV2FlowDatabase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        DisposableHomologationDatabase::assertSafe();
        $previousStoragePath = app()->storagePath();
        $previousKeyPath = Passport::$keyPath;
        $this->beforeApplicationDestroyed(function () use ($previousStoragePath, $previousKeyPath): void {
            try {
                app()->useStoragePath($previousStoragePath);
            } finally {
                Passport::$keyPath = $previousKeyPath;
            }
        });
        app()->useStoragePath('/tmp/opencode/jobora38-flow-storage');
        foreach (['app/private', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $directory) {
            File::ensureDirectoryExists(storage_path($directory));
        }
        Passport::loadKeysFrom(storage_path());
        config(['filesystems.disks.local.root' => storage_path('app/private'), 'passport.private_key' => null, 'passport.public_key' => null,
            'queue.connections.database.connection' => 'sqlite']);
    }
}

uses(JoboraV2FlowDatabase::class);

beforeEach(function (): void {
    expect(DB::connection()->getDatabaseName())->toStartWith('/tmp/opencode/');
    expect(Storage::disk('local')->path(''))->toStartWith('/tmp/opencode/jobora38-flow-storage/');
    Http::preventStrayRequests();
    config(['queue.default' => 'database', 'queue.connections.database.after_commit' => false, 'broadcasting.default' => 'null',
        'ai.default' => 'opencode_go', 'ai.providers.opencode_go.key' => 'controlled-provider-key',
        'triage.prefilter.account_id' => 'controlled-account', 'triage.prefilter.api_token' => 'controlled-clef-key']);
    Pdf::swap(new class extends FakePdfBuilder
    {
        public function save(string $path): self
        {
            parent::save($path);
            file_put_contents($path, '%PDF-controlled-contract '.$this->getHtml());

            return $this;
        }
    });
});

/** @param array{user: User, integration: AgentIntegration, token: string, capture_token: string} $actor @param array<string, mixed> $input @return array{ok: bool, data?: array<string, mixed>, error?: array{code: string, message: string}} */
function flowResult(object $test, array $actor, string $tool, array $input = []): array
{
    app('auth')->forgetGuards();

    return Helpers::decodeToolResult($test->withToken($actor['token'])->postJson('/mcp', Helpers::toolCall($tool, $input))->assertOk()->getContent());
}

/** @param array{user: User, integration: AgentIntegration, token: string, capture_token: string} $actor @param array<string, mixed> $input @return array<string, mixed> */
function flowData(object $test, array $actor, string $tool, array $input = []): array
{
    $result = flowResult($test, $actor, $tool, $input);
    $test->assertTrue($result['ok'] ?? false, $tool.': '.json_encode($result));

    return $result['data'];
}

function flowPanel(object $test, User $user): void
{
    app()->forgetInstance(AgentIntegrationContext::class);
    app('auth')->forgetGuards();
    $test->actingAs($user, 'web');
}

function flowWork(object $test): void
{
    app()->forgetInstance(AgentIntegrationContext::class);
    app('auth')->forgetGuards();
    expect(config('queue.default'))->toBe('database');
    expect(config('queue.connections.database.connection'))->toBe('sqlite');
    $memoryLimit = (int) ceil(memory_get_usage(true) / 1024 / 1024) + 64;
    $test->artisan('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--max-jobs' => 30,
        '--sleep' => 0, '--tries' => 1, '--timeout' => 45, '--memory' => $memoryLimit])->assertSuccessful();
    expect(DB::table('jobs')->count())->toBe(0);
    expect(DB::table('failed_jobs')->count())->toBe(0);
}

/** @param array{frozen: array<string, mixed>, basis: array<string, mixed>, input_fingerprint: string} $payload @return array{schema_version: string, criteria_version: string, input_fingerprint: string, basis: array<string, mixed>, criteria: list<array<string, mixed>>, summary: array<string, mixed>} */
function flowMatchingReply(array $payload): array
{
    $case = json_decode(file_get_contents(base_path('tests/Fixtures/matching-v1.json')), true, flags: JSON_THROW_ON_ERROR)['cases'][0];
    $originalAnchors = array_column($case['frozen']['anchors'], null, 'id');
    $criteria = $case['expected']['criteria'];
    foreach ($criteria as &$criterion) {
        $criterion['explanation'] = 'Fronteira de provider controlada para homologação.';
        foreach ($criterion['evidence'] as &$evidence) {
            $original = $originalAnchors[$evidence['anchor_id']];
            $current = collect($payload['frozen']['anchors'])->first(fn (array $anchor): bool => $anchor['source'] === $original['source'] && $anchor['path'] === $original['path']);
            $evidence['anchor_id'] = $current['id'];
        }
        unset($evidence);
    }
    unset($criterion);

    return ['schema_version' => 'matching-result-v1', 'criteria_version' => 'matching-v1', 'input_fingerprint' => $payload['input_fingerprint'],
        'basis' => $payload['basis'], 'criteria' => $criteria, 'summary' => $case['expected']['summary']];
}

it('homologates two private accounts from three capture surfaces through human approval and controlled submission reconciliation', function () {
    $fixtures = json_decode(file_get_contents(base_path('extension/tests/fixtures/v2-homologation.json')), true, flags: JSON_THROW_ON_ERROR);
    $stage = 'idle';
    $triageReply = [];
    $adaptedResume = '';
    $clefCalls = 0;
    $triageCalls = 0;
    Http::fake(function (ProviderRequest $request) use (&$stage, &$triageReply, &$adaptedResume, &$clefCalls, &$triageCalls) {
        if (str_starts_with($request->url(), 'https://api.cloudflare.com/')) {
            $clefCalls++;

            return Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => ['model' => 'clef-flash', 'answers' => [
                'is_job' => ['type' => 'noul', 'noul' => 0.9],
                'non_job_kind' => ['type' => 'choice', 'choice' => 'other', 'probabilities' => ['candidate_offering' => 0.1,
                    'job_curation' => 0.1, 'editorial_content' => 0.1, 'promotion' => 0.1, 'other' => 0.6], 'confidence' => 0.9],
            ]]]);
        }
        if ($request->url() !== 'https://opencode.ai/inference/go/openai/v1/chat/completions' || $stage === 'idle') {
            throw new RuntimeException('Chamada externa inesperada ou triagem iniciada sem solicitação explícita: '.$request->url());
        }
        if ($stage === 'triage') {
            $triageCalls++;
            $output = $triageReply;
        } elseif ($stage === 'matching') {
            $messages = $request['messages'];
            $output = flowMatchingReply(json_decode($messages[array_key_last($messages)]['content'], true, flags: JSON_THROW_ON_ERROR));
        } else {
            $output = ['adapted_resume' => $adaptedResume, 'changes' => [], 'covered_keywords' => ['PHP'], 'missing_requirements' => [], 'alerts' => []];
        }

        return Http::response(['id' => 'controlled-provider-reply', 'object' => 'chat.completion', 'created' => time(), 'model' => 'deepseek-v4.1-flash',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => json_encode($output)], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10, 'total_tokens' => 20]]);
    });
    $actors = [];
    foreach (['ANA', 'BRUNO'] as $name) {
        $user = User::factory()->create(['name' => $name, 'password' => 'flow-password']);
        $client = Helpers::client('Hermes homologação '.$name);
        $actors[] = ['user' => $user, 'integration' => Helpers::integration($user, $client, AgentIntegrationScope::values()), 'token' => Helpers::accessToken($user, $client),
            'capture_token' => app(CaptureTokenService::class)->issueToken($user)];
    }
    $finished = [];
    foreach ($actors as $account => $actor) {
        $captures = [];
        foreach ($fixtures as $fixture) {
            $envelope = $fixture['envelope'];
            $source = flowData($this, $actor, 'create_discovery_source', ['name' => $fixture['surface'], 'source' => $fixture['source'],
                'surface' => $fixture['surface'], 'frequency_minutes' => 60, 'active' => true, 'url' => $envelope['capture']['initial_url']]);
            $acquired = flowData($this, $actor, 'acquire_discovery_runs', ['limit' => 1, 'idempotency_key' => $fixture['surface'].'-'.$account]);
            $run = $acquired['runs'][0];
            expect($run['source_id'])->toBe($source['source']['id']);
            $this->withToken($actor['capture_token'])->postJson('/api/discovery-runs/resolve', ['url' => $envelope['capture']['initial_url']])
                ->assertOk()->assertJsonPath('execution_id', $run['execution_id']);
            $envelope['capture']['idempotency_key'] .= '-'.$account;
            $envelope['capture']['execution_id'] = $run['execution_id'];
            $envelope['capture']['captured_at'] = now()->toISOString();
            $upload = $this->withToken($actor['capture_token'])->postJson('/api/page-captures', $envelope)->assertCreated()->assertJsonPath('replay', false);
            $this->withToken($actor['capture_token'])->postJson('/api/page-captures', $envelope)->assertOk()->assertJsonPath('id', $upload->json('id'))->assertJsonPath('replay', true);
            $captureId = $upload->json('id');
            expect(PageCapture::query()->findOrFail($captureId)->user_id)->toBe($actor['user']->id);
            expect(flowData($this, $actor, 'get_page_capture', ['id' => $captureId])['capture']['extraction']['status'])->toBe('pending');
            flowWork($this);
            $read = flowData($this, $actor, 'list_publications', ['page_capture_id' => $captureId, 'limit' => 1]);
            expect($read['extraction']['status'])->toBe('completed');
            expect($read['total'])->toBe(1);
            expect($read['items'][0]['clef']['status'])->toBe('eligible');
            $captures[] = ['fixture' => $fixture, 'id' => $captureId, 'publication' => $read['items'][0]];
        }
        expect(AiOperation::query()->where('user_id', $actor['user']->id)->where('operation_type', AiOperationType::PublicationTriage)->count())->toBe(0);
        $page = flowData($this, $actor, 'list_publications', ['clef_status' => 'eligible', 'limit' => 1]);
        expect($page['total'])->toBe(3);
        expect($page['next_offset'])->toBe(1);
        expect(flowData($this, $actor, 'list_publications', ['clef_status' => 'eligible', 'limit' => 1, 'offset' => 1])['items'][0]['publication']['id'])->not->toBe($page['items'][0]['publication']['id']);
        foreach ($captures as $capture) {
            $item = $capture['publication'];
            $quote = $item['publication']['content'];
            $triageReply = ['opportunities' => [[
                'role' => 'PHP', 'company' => null, 'description' => $quote, 'location' => null, 'work_mode' => null,
                'employment_type' => null, 'salary_min' => null, 'salary_max' => null, 'salary_currency' => null, 'salary_period' => null,
                'seniority' => null, 'requirements' => ['PHP'], 'technologies' => [], 'application_methods' => [],
                'evidence' => [['quote' => $quote, 'block_ids' => $item['observation']['evidence_block_ids'], 'fields' => ['role', 'description', 'requirements']]],
            ]]];
            $stage = 'triage';
            $requested = flowData($this, $actor, 'request_publication_triage', ['publication_id' => $item['publication']['id'], 'content_version' => $item['publication']['content_version']]);
            flowWork($this);
            expect(flowData($this, $actor, 'get_ai_operation', ['id' => $requested['operation_id']])['status'])->toBe('completed');
            $stage = 'idle';
        }
        $ready = flowData($this, $actor, 'list_job_postings', ['readiness' => 'ready']);
        expect($ready['total'])->toBe(3);
        $finished[] = ['actor' => $actor, 'captures' => $captures, 'posting' => $ready['items'][0]];
    }
    expect($clefCalls)->toBe(6);
    expect($triageCalls)->toBe(6);
    expect(flowResult($this, $actors[1], 'get_page_capture', ['id' => $finished[0]['captures'][0]['id']])['error']['code'])->toBe('not_found');
    foreach ($finished as $account => $flow) {
        $actor = $flow['actor'];
        $posting = $flow['posting'];
        $profile = ['experiences' => [], 'education' => [], 'competencies' => ['PHP'], 'preferences' => [], 'restrictions' => [], 'compensation_expectation' => null];
        flowData($this, $actor, 'save_professional_profile', ['profile' => $profile, 'expected_version' => 0, 'confirm' => true]);
        flowData($this, $actor, 'confirm_professional_profile', ['expected_version' => 1, 'confirm' => true]);
        $adaptedResume = $actor['user']->name."\nPHP";
        flowData($this, $actor, 'save_resume', ['content' => $adaptedResume, 'language' => 'pt-BR', 'confirm' => true]);
        $stage = 'matching';
        $matching = flowData($this, $actor, 'request_matching', ['opportunity_id' => $posting['id'], 'expected_opportunity_version' => 1, 'expected_profile_confirmed_version' => 2]);
        flowWork($this);
        $operation = flowData($this, $actor, 'get_ai_operation', ['id' => $matching['operation_id']]);
        expect($operation['status'])->toBe('completed');
        expect(flowData($this, $actor, 'get_matching', ['id' => $operation['matching_evaluation_id']])['stale'])->toBeFalse();
        expect(flowResult($this, $actors[1 - $account], 'get_matching', ['id' => $operation['matching_evaluation_id']])['error']['code'])->toBe('not_found');
        $input = ['job_posting_id' => $posting['id'], 'expected_opportunity_version' => 1, 'expected_profile_confirmed_version' => 2,
            'language' => 'pt-BR', 'destination' => ['type' => 'email', 'email' => 'jobs@example.test']];
        $stage = 'adaptation';
        $waiting = flowData($this, $actor, 'prepare_application', $input);
        expect($waiting['operation_ids'])->not->toBeEmpty();
        flowWork($this);
        $prepared = flowData($this, $actor, 'prepare_application', $input);
        expect($prepared['state'])->toBe('awaiting_approval');
        $tracking = flowData($this, $actor, 'get_application', ['job_posting_id' => $posting['id']])['application']['status'];
        expect(flowResult($this, $actors[1 - $account], 'get_application', ['job_posting_id' => $posting['id']])['error']['code'])->toBe('not_found');
        $stage = 'idle';
        $delivery = flowData($this, $actor, 'export_adapted_resume_pdf', ['preparation_id' => $prepared['preparation_id']]);
        app('auth')->forgetGuards();
        $bytes = $this->withToken($actor['token'])->get($delivery['download_url'])->assertOk()->streamedContent();
        expect(hash('sha256', $bytes))->toBe($delivery['sha256']);
        app('auth')->forgetGuards();
        $this->withToken($actors[1 - $account]['token'])->get($delivery['download_url'])->assertNotFound();
        flowPanel($this, $actor['user']);
        Livewire::test(TelegramIdentityPage::class)->set('integrationId', $actor['integration']->id)->call('startChallenge');
        $notifications = new Notifications;
        $notifications->mount();
        $notice = $notifications->notifications->first(fn (Notification $notification): bool => $notification->getTitle() === 'Desafio temporário criado');
        expect(preg_match('/Código: (\S+)/u', $notice->getBody(), $code))->toBe(1);
        $telegramId = (string) (380000 + $account);
        $submitted = flowData($this, $actor, 'submit_telegram_link', ['challenge' => $code[1], 'telegram_user_id' => $telegramId,
            'chat_id' => $telegramId, 'message_id' => '1', 'chat_type' => 'private']);
        flowPanel($this, $actor['user']);
        Livewire::test(TelegramIdentityPage::class)->set('confirmationChallengeId', $submitted['challenge_id'])
            ->set('confirmationPassword', 'flow-password')->call('confirmChallenge')->assertNotified('Vínculo confirmado');
        $approvalInput = ['preparation_id' => $prepared['preparation_id'], 'expected_fingerprint' => $prepared['fingerprint'],
            'telegram_user_id' => $telegramId, 'chat_id' => $telegramId, 'message_id' => '2',
            'approved_at' => now()->toISOString(), 'expires_at' => now()->addMinutes(30)->toISOString()];
        $approval = flowData($this, $actor, 'record_application_approval', $approvalInput);
        $reserveInput = ['approval_id' => $approval['id'], 'expected_fingerprint' => $prepared['fingerprint'], 'idempotency_key' => 'controlled-submit-'.$account];
        $reserved = flowData($this, $actor, 'reserve_application_submission', $reserveInput);
        expect($reserved['authorizes_submission'])->toBeFalse();
        expect(flowData($this, $actor, 'reserve_application_submission', $reserveInput)['attempt_id'])->toBe($reserved['attempt_id']);
        $consumeInput = ['attempt_id' => $reserved['attempt_id'], 'expected_revision' => 1, 'expected_fingerprint' => $prepared['fingerprint'], 'event_key' => 'controlled-consume'];
        expect(flowData($this, $actor, 'consume_application_submission', $consumeInput)['authorizes_submission'])->toBeTrue();
        expect(flowData($this, $actor, 'consume_application_submission', $consumeInput)['authorizes_submission'])->toBeFalse();
        $outcome = $account === 0 ? 'succeeded' : 'unknown';
        $reported = flowData($this, $actor, 'report_application_submission', ['attempt_id' => $reserved['attempt_id'], 'event_key' => 'controlled-result',
            'outcome' => $outcome, 'occurred_at' => now()->toISOString(),
            'evidence' => $account === 0 ? ['kind' => 'submission_receipt', 'reference' => 'controlled-receipt'] : ['kind' => 'ambiguous', 'details' => 'Controlled transport timeout; no external application occurred.']]);
        expect($reported['state'])->toBe($outcome);
        expect(flowData($this, $actor, 'get_application', ['job_posting_id' => $posting['id']])['application']['status'])->toBe($tracking);
        if ($account === 1) {
            $newApproval = flowData($this, $actor, 'record_application_approval', [...$approvalInput, 'message_id' => '3']);
            expect(flowResult($this, $actor, 'reserve_application_submission', [...$reserveInput, 'approval_id' => $newApproval['id'], 'idempotency_key' => 'blocked-new-approval'])['ok'])->toBeFalse();
            flowPanel($this, $actor['user']);
            Livewire::test(ListAgentIntegrations::class)->callAction(TestAction::make('revoke')->table($actor['integration']));
            expect($actor['integration']->refresh()->revoked_at)->not->toBeNull();
            app('auth')->forgetGuards();
            $this->withToken($actor['token'])->postJson('/mcp', Helpers::toolCall('get_application', ['job_posting_id' => $posting['id']]))->assertUnauthorized();
            flowPanel($this, $actor['user']);
            Livewire::test(ApplicationPage::class, ['record' => $posting['id']])
                ->assertSet('submissionSummary.state', 'unknown')->set('reconciliationData.outcome', 'succeeded')
                ->set('reconciliationData.evidence_kind', 'submission_receipt')->set('reconciliationData.reference', 'controlled-owner-receipt')
                ->call('reconcileSubmission')->assertSet('submissionSummary.state', 'succeeded');
            expect(DB::table('applications')->where('id', $reserved['application_id'])->value('status'))->toBe($tracking);
        }
    }
});
