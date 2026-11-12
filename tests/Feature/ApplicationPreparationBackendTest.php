<?php

use App\Enums\ResumeLanguage;
use App\Mcp\Support\AgentIntegrationContext;
use App\Models\AiOperation;
use App\Models\Application;
use App\Models\ApplicationPreparation;
use App\Models\DocumentArtifact;
use App\Models\JobPosting;
use App\Models\ProfessionalProfile;
use App\Models\Resume;
use App\Models\ResumeAdaptation;
use App\Models\User;
use App\Services\AiInputSnapshotService;
use App\Services\ApplicationPreparationService;
use App\Services\AtsScorerService;
use App\Services\DocumentArtifactService;
use App\Services\ResumeAdaptationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Passport\Client;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\FakePdfBuilder;
use Tests\Feature\AgentIntegrations\AgentIntegrationTestHelpers as Helpers;

uses(RefreshDatabase::class);

it('rejects cropped technical facts against the original confirmed field', function (string $field, string $quote) {
    $this->owner->professionalProfile()->first()->update(['confirmed_snapshot' => ['competencies' => [$field]]]);
    $result = preparationCall($this, 'prepare_application', [...$this->input, 'questions' => [
        ['id' => 'skill', 'question' => 'Skill?', 'answer' => $quote, 'support' => [['source' => 'profile', 'path' => 'competencies.0', 'quote' => $quote]]],
    ]])['data'];
    expect($result['state'])->toBe('pending_information')
        ->and($result['pending'])->toContain(['code' => 'unsupported_answer', 'question_id' => 'skill', 'missing_fields' => ['answer', 'support']]);
    $get = preparationCall($this, 'get_application_preparation', ['id' => $result['preparation_id']])['data'];
    expect($get['snapshot']['questions'][0]['answer'])->toBeNull();
})->with([
    ['C++', 'C'], ['C#', 'C'], ['Node.js', 'Node'], ['.NET', 'NET'], ['JavaScript', 'Java'],
    ['préJava', 'Java'], ['Javação', 'Java'], ["Java\u{0301}", 'Java'],
]);

it('accepts complete technical terms with sentence and list punctuation', function (string $field, string $quote) {
    $this->owner->professionalProfile()->first()->update(['confirmed_snapshot' => ['competencies' => [$field]]]);
    $result = preparationCall($this, 'prepare_application', [...$this->input, 'questions' => [
        ['id' => 'skill', 'question' => 'Skill?', 'answer' => $quote, 'support' => [['source' => 'profile', 'path' => 'competencies.0', 'quote' => $quote]]],
    ]])['data'];
    expect($result['state'])->toBe('awaiting_approval')->and($result['pending'])->toBe([]);
})->with([
    ['C++', 'C++'], ['C#', 'C#'], ['.NET', '.NET'], ['Node.js', 'Node.js'],
    ['C++, C#; .NET / Node.js.', 'Node.js'], ['C++, C#; .NET / Node.js.', '.NET'], ['Java.', 'Java'], ['JavaScript.', 'JavaScript'],
]);

it('holds an explicitly mandatory letter until generation is requested', function (string $instructions) {
    $method = $this->posting->applicationMethods()->create([
        'type' => 'email', 'email' => 'jobs@example.com', 'source' => 'user', 'dedup_key' => 'required-letter', 'instructions' => $instructions,
    ]);
    $input = [...$this->input, 'destination' => ['application_method_id' => $method->id]];
    $result = preparationCall($this, 'prepare_application', $input)['data'];
    expect($result['state'])->toBe('pending_information')
        ->and($result['pending'])->toContain(['code' => 'cover_letter_required', 'missing_fields' => ['cover_letter_requested']])
        ->and($result['artifacts'])->toHaveCount(1);
    $get = preparationCall($this, 'get_application_preparation', ['id' => $result['preparation_id']])['data'];
    expect($get['snapshot']['cover_letter'])->toBeNull();
    $override = preparationCall($this, 'prepare_application', [...$input, 'cover_letter_required' => false]);
    expect($override['data']['state'])->toBe('pending_information');
    $requested = preparationCall($this, 'prepare_application', [...$input, 'cover_letter_requested' => true])['data'];
    expect($requested['state'])->toBe('awaiting_approval')->and($requested['pending'])->toBe([])
        ->and($requested['artifacts'])->toHaveCount(2);
})->with([
    'Envie currículo e carta de apresentação obrigatória.', 'Carta de apresentação requerida.',
    'Obrigatória a carta de apresentação.', 'É requerida uma carta de apresentação.',
    'Cover letter required.', 'Cover letter mandatory.', 'Required cover letter.', 'Mandatory: cover letter.',
    'Cover letter: required.', 'Cover letter - required.', 'Cover letter (required).',
    'Carta de apresentação: obrigatória.', 'Carta de apresentação - obrigatória.', 'Carta de apresentação (obrigatória).',
]);

it('accepts the structured letter requirement and requires explicit generation consent', function () {
    $withoutRequirement = preparationCall($this, 'prepare_application', $this->input)['data'];
    $result = preparationCall($this, 'prepare_application', [...$this->input, 'cover_letter_required' => true]);
    expect($result['ok'])->toBeTrue()->and($result['data']['state'])->toBe('pending_information')
        ->and($result['data']['pending'])->toContain(['code' => 'cover_letter_required', 'missing_fields' => ['cover_letter_requested']]);
    expect($result['data']['fingerprint'])->not->toBe($withoutRequirement['fingerprint']);
    $requested = preparationCall($this, 'prepare_application', [...$this->input, 'cover_letter_required' => true, 'cover_letter_requested' => true]);
    expect($requested['data']['state'])->toBe('awaiting_approval')->and($requested['data']['artifacts'])->toHaveCount(2);
});

it('does not infer mandatory letters from negation or unknown instructions', function (string $instructions) {
    $result = preparationCall($this, 'prepare_application', [...$this->input,
        'destination' => [...$this->input['destination'], 'instructions' => $instructions],
    ])['data'];
    expect($result['state'])->toBe('awaiting_approval')->and($result['pending'])->toBe([])->and($result['artifacts'])->toHaveCount(1);
})->with([
    'Carta de apresentação não obrigatória.', 'Não é obrigatória a carta de apresentação.',
    'Cover letter not required.', 'Cover letter is not mandatory.', 'No cover letter required.',
    'Not required: cover letter.', 'Not a mandatory cover letter.',
    'Cover letter: not required.', 'Cover letter (not mandatory).', 'Carta de apresentação: não obrigatória.',
    'A cover letter would be nice.', 'Confira as regras no site.',
]);

it('retains mandatory letter pending information while existing generation is delegated', function () {
    $this->adaptation->delete();
    $result = preparationCall($this, 'prepare_application', [...$this->input, 'cover_letter_required' => true])['data'];
    expect($result['state'])->toBe('pending_information')->and($result['operation_ids'])->toHaveCount(1)
        ->and($result['pending'])->toContain(['code' => 'cover_letter_required', 'missing_fields' => ['cover_letter_requested']]);
});

it('validates the structured letter requirement as a strict boolean', function () {
    expect(preparationCall($this, 'prepare_application', [...$this->input, 'cover_letter_required' => 'false'])['error']['code'])->toBe('validation');
});

beforeEach(function () {
    Http::preventStrayRequests();
    Pdf::swap(new class extends FakePdfBuilder
    {
        public ?Closure $beforeSave = null;

        public function save(string $path): self
        {
            parent::save($path);
            file_put_contents($path, '%PDF-test '.$this->getHtml());
            $callback = $this->beforeSave;
            $this->beforeSave = null;
            $callback?->__invoke();

            return $this;
        }
    });
    Storage::fake('local');
    config(['app.url' => 'https://localhost', 'queue.default' => 'database']);
    $this->owner = User::factory()->create(['can_use_ats_optimization' => true]);
    $client = Helpers::client();
    $this->integration = Helpers::integration($this->owner, $client);
    $this->token = Helpers::accessToken($this->owner, $client);
    ProfessionalProfile::query()->create([
        'user_id' => $this->owner->id, 'version' => 3, 'confirmed_version' => 2,
        'confirmed_snapshot' => ['competencies' => ['PHP']], 'draft' => ['competencies' => ['Invented']],
    ]);
    $this->posting = JobPosting::factory()->for($this->owner, 'user')->create([
        'readiness' => 'ready', 'opportunity_version' => 1, 'status' => 'pending',
        'role' => 'Developer', 'company' => 'Acme',
    ]);
    $this->resume = Resume::factory()->for($this->owner)->create(['language' => 'pt-BR', 'content' => "ANA\nPHP"]);
    $snapshot = app(AiInputSnapshotService::class)->resumeAdaptation($this->owner, $this->posting, ResumeLanguage::PtBr);
    $this->adaptation = ResumeAdaptation::query()->create([
        'job_posting_id' => $this->posting->id, 'adapted_content' => "ANA\nPHP", 'source_fingerprint' => $snapshot['fingerprint'],
        'changes' => [], 'covered_keywords' => [], 'missing_requirements' => [], 'alerts' => [],
    ]);
    $this->input = [
        'job_posting_id' => $this->posting->id, 'expected_opportunity_version' => 1,
        'expected_profile_confirmed_version' => 2, 'destination' => ['type' => 'email', 'email' => 'jobs@example.com'],
    ];
});

it('accepts legacy confirmed opportunities and does not require legacy version', function () {
    $this->posting->update(['readiness' => null, 'status' => 'confirmed']);
    unset($this->input['expected_opportunity_version']);
    expect(preparationCall($this, 'prepare_application', $this->input)['data']['state'])->toBe('awaiting_approval');
});

it('rejects stale versions and destinations owned by another opportunity', function () {
    foreach ([['expected_profile_confirmed_version' => 1], ['expected_opportunity_version' => 2]] as $change) {
        expect(preparationCall($this, 'prepare_application', [...$this->input, ...$change])['error']['code'])->toBe('precondition');
    }
    $other = JobPosting::factory()->create();
    $method = $other->applicationMethods()->create(['type' => 'email', 'email' => 'elsewhere@example.com', 'source' => 'user', 'dedup_key' => 'other-email']);
    expect(preparationCall($this, 'prepare_application', [...$this->input, 'destination' => ['application_method_id' => $method->id]])['error']['code'])->toBe('not_found');
    expect(preparationCall($this, 'prepare_application', [...$this->input, 'user_id' => $other->user_id])['error']['code'])->toBe('validation');
    $this->assertDatabaseCount('document_artifacts', 0);
});

it('prepares English without replacing manual content or scheduling translation', function () {
    $resume = Resume::factory()->for($this->owner)->create(['language' => 'en', 'content' => "ANA\nPHP engineer"]);
    $fingerprint = app(AiInputSnapshotService::class)->resumeAdaptation($this->owner, $this->posting, ResumeLanguage::En)['fingerprint'];
    $this->adaptation->replicate()->fill(['language' => 'en', 'adapted_content' => $resume->content, 'source_fingerprint' => $fingerprint])->save();
    $result = preparationCall($this, 'prepare_application', [...$this->input, 'language' => 'en', 'translate_missing' => true]);
    expect($result['data']['state'])->toBe('awaiting_approval')
        ->and($result['data']['artifacts'][0]['language'])->toBe('en')
        ->and($resume->fresh()->content)->toBe("ANA\nPHP engineer");
    $this->assertDatabaseCount('ai_operations', 0);
});

it('delegates only explicitly requested translation with resume write capability', function () {
    $result = preparationCall($this, 'prepare_application', [...$this->input, 'language' => 'en']);
    expect($result['data']['state'])->toBe('pending_information');
    $this->assertDatabaseCount('ai_operations', 0);
    $this->integration->update(['scopes' => ['applications:write']]);
    expect(preparationCall($this, 'prepare_application', [...$this->input, 'language' => 'en', 'translate_missing' => true])['error']['code'])->toBe('forbidden');
    $this->integration->update(['scopes' => ['applications:write', 'resume:write']]);
    $first = preparationCall($this, 'prepare_application', [...$this->input, 'language' => 'en', 'translate_missing' => true]);
    $second = preparationCall($this, 'prepare_application', [...$this->input, 'language' => 'en', 'translate_missing' => true]);
    expect($first['data']['state'])->toBe('preparing')
        ->and($first['data']['operation_ids'])->toBe($second['data']['operation_ids']);
    $this->assertDatabaseCount('ai_operations', 1);
});

it('does not escalate application write into adaptation capability', function () {
    $this->adaptation->delete();
    $this->integration->update(['scopes' => ['applications:write']]);
    expect(preparationCall($this, 'prepare_application', $this->input)['error']['code'])->toBe('forbidden');
    $this->assertDatabaseCount('ai_operations', 0);
    $this->integration->update(['scopes' => ['applications:write', 'ats:write']]);
    $result = preparationCall($this, 'prepare_application', $this->input);
    expect($result['data']['state'])->toBe('preparing')->and($result['data']['operation_ids'])->toHaveCount(1);
});

it('rejects changed sources during PDF rendering without publishing mixed material', function () {
    Pdf::getFacadeRoot()->beforeSave = function (): void {
        $this->owner->professionalProfile()->first()->update(['confirmed_version' => 4, 'confirmed_snapshot' => ['competencies' => ['Rust']]]);
    };
    expect(fn () => app(ApplicationPreparationService::class)->prepare($this->owner, $this->input))->toThrow(LogicException::class);
    $this->assertDatabaseCount('application_preparations', 0);
    $this->assertDatabaseCount('document_artifacts', 0);
    expect(Storage::disk('local')->allFiles('documents'))->toBe([]);
});

it('rejects publication after a concurrent preparation pointer update', function () {
    Pdf::getFacadeRoot()->beforeSave = function (): void {
        Application::query()->where('job_posting_id', $this->posting->id)->update(['preparation_version' => 1]);
    };
    expect(fn () => app(ApplicationPreparationService::class)->prepare($this->owner, $this->input))->toThrow(LogicException::class);
    $this->assertDatabaseCount('application_preparations', 0);
});

it('keeps fingerprints stable across replay and rejects profile content drift without a version bump', function () {
    $first = app(ApplicationPreparationService::class)->prepare($this->owner, $this->input);
    $second = app(ApplicationPreparationService::class)->prepare($this->owner, $this->input);
    expect($first['fingerprint'])->toBe($second['fingerprint']);
    $this->owner->professionalProfile()->first()->update(['confirmed_snapshot' => ['competencies' => ['Rust']]]);
    expect(app(ApplicationPreparationService::class)->get($this->owner, $second['preparation_id'])['stale'])->toBeTrue();
});

it('isolates preparation reads and denies missing application capabilities', function () {
    $result = preparationCall($this, 'prepare_application', $this->input)['data'];
    $this->integration->update(['scopes' => ['ats:read']]);
    expect(preparationCall($this, 'prepare_application', $this->input)['error']['code'])->toBe('forbidden')
        ->and(preparationCall($this, 'get_application_preparation', ['id' => $result['preparation_id']])['error']['code'])->toBe('forbidden');
    $other = User::factory()->create();
    $client = Helpers::client();
    Helpers::integration($other, $client);
    $this->token = Helpers::accessToken($other, $client);
    expect(preparationCall($this, 'get_application_preparation', ['id' => $result['preparation_id']])['error']['code'])->toBe('not_found')
        ->and(preparationCall($this, 'prepare_application', $this->input)['error']['code'])->toBe('not_found');
});

it('denies expired links revoked integrations inactive users and expired OAuth tokens', function () {
    $prepared = preparationCall($this, 'prepare_application', $this->input)['data'];
    $delivery = preparationCall($this, 'export_adapted_resume_pdf', ['preparation_id' => $prepared['preparation_id']])['data'];
    $this->travel(16)->minutes();
    app('auth')->forgetGuards();
    $this->withToken($this->token)->getJson($delivery['download_url'])->assertForbidden();
    $this->travelBack();
    $this->integration->update(['revoked_at' => now()]);
    app('auth')->forgetGuards();
    $this->withToken($this->token)->getJson($delivery['download_url'])->assertForbidden();
    $this->integration->update(['revoked_at' => null]);
    $this->owner->forceFill(['disabled_at' => now()])->save();
    app('auth')->forgetGuards();
    $this->withToken($this->token)->getJson($delivery['download_url'])->assertUnauthorized();
    $this->owner->forceFill(['disabled_at' => null])->save();
    $client = Client::find($this->integration->oauth_client_id);
    $expired = Helpers::accessToken($this->owner, $client, expiresAt: new DateTimeImmutable('-10 seconds'));
    app('auth')->forgetGuards();
    $this->withToken($expired)->getJson($delivery['download_url'])->assertUnauthorized();
    $artifact = DocumentArtifact::query()->findOrFail($delivery['artifact_id']);
    $this->travel(25)->hours();
    $url = URL::temporarySignedRoute('documents.api.download', now()->addMinutes(1), ['artifact' => $artifact->id]);
    $this->travelBack();
    DB::table('document_artifacts')->where('id', $artifact->id)->update(['expires_at' => now()->subMinute()]);
    app('auth')->forgetGuards();
    $this->withToken($this->token)->getJson($url)->assertGone();
});

it('keeps downloaded bytes frozen after resume adaptation and opportunity edits', function () {
    $prepared = preparationCall($this, 'prepare_application', $this->input)['data'];
    $delivery = preparationCall($this, 'export_adapted_resume_pdf', ['preparation_id' => $prepared['preparation_id']])['data'];
    app('auth')->forgetGuards();
    $before = $this->withToken($this->token)->get($delivery['download_url'])->assertOk()->streamedContent();
    $this->resume->update(['content' => 'Different resume']);
    $this->adaptation->update(['adapted_content' => 'Different adaptation']);
    $this->posting->update(['role' => 'Different role']);
    app('auth')->forgetGuards();
    $after = $this->withToken($this->token)->get($delivery['download_url'])->assertOk()->streamedContent();
    expect($after)->toBe($before)->and(hash('sha256', $after))->toBe($delivery['sha256']);
});

it('runs delegated adaptation through the real worker and provider HTTP boundary', function () {
    config(['ai.default' => 'opencode_go', 'ai.providers.opencode_go.key' => 'test-only-key']);
    $this->adaptation->delete();
    $request = preparationCall($this, 'prepare_application', $this->input)['data'];
    Http::fake(['*' => Http::response([
        'id' => 'test-response', 'object' => 'chat.completion', 'created' => time(), 'model' => 'deepseek-v4.1-flash',
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => json_encode([
            'adapted_resume' => "ANA\nPHP", 'changes' => [], 'covered_keywords' => ['PHP'], 'missing_requirements' => [], 'alerts' => [],
        ])], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10, 'total_tokens' => 20],
    ])]);
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0, '--tries' => 1]);
    expect(AiOperation::findOrFail($request['operation_ids'][0])->status->value)->toBe('completed');
    expect(preparationCall($this, 'prepare_application', $this->input)['data']['state'])->toBe('awaiting_approval');
    Http::assertSentCount(1);
});

it('revalidates delegated authorization before calling the provider', function (string $change) {
    $this->adaptation->delete();
    $request = preparationCall($this, 'prepare_application', $this->input)['data'];
    match ($change) {
        'integration' => $this->integration->update(['revoked_at' => now()]),
        'capability' => $this->integration->update(['scopes' => ['applications:write']]),
        'account' => $this->owner->forceFill(['disabled_at' => now()])->save(),
        'opportunity' => $this->posting->update(['readiness' => 'archived']),
    };
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0, '--tries' => 1]);
    expect(AiOperation::findOrFail($request['operation_ids'][0])->status->value)->not->toBe('completed');
    $this->assertDatabaseCount('resume_adaptations', 0);
    Http::assertNothingSent();
})->with(['integration', 'capability', 'account', 'opportunity']);

it('guards adaptation ATS and export entry points for non-ready opportunities', function () {
    $this->posting->update(['readiness' => 'needs_review', 'status' => 'confirmed']);
    $user = $this->owner;
    $posting = $this->posting;
    expect(fn () => app(ResumeAdaptationService::class)->adapt($user, $posting))->toThrow(LogicException::class)
        ->and(fn () => app(AtsScorerService::class)->score($user, $posting, ResumeLanguage::PtBr))->toThrow(LogicException::class);
    foreach (['adapt_resume', 'score_ats', 'set_ats_optimization', 'export_adapted_resume_pdf'] as $tool) {
        $arguments = ['job_posting_id' => $posting->id];
        if ($tool === 'set_ats_optimization') {
            $arguments['enabled'] = true;
        }
        expect(preparationCall($this, $tool, $arguments)['error']['code'])->toBeIn(['precondition', 'forbidden']);
    }
    Http::assertNothingSent();
});

it('revalidates delegated authorization at persistence after the provider returns', function () {
    config(['ai.default' => 'opencode_go', 'ai.providers.opencode_go.key' => 'test-only-key']);
    $this->adaptation->delete();
    preparationCall($this, 'prepare_application', $this->input);
    Http::fake(function () {
        $this->integration->update(['revoked_at' => now()]);

        return Http::response([
            'id' => 'response', 'object' => 'chat.completion', 'created' => time(), 'model' => 'deepseek-v4.1-flash',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => json_encode([
                'adapted_resume' => "ANA\nPHP", 'changes' => [], 'covered_keywords' => ['PHP'], 'missing_requirements' => [], 'alerts' => [],
            ])], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ]);
    });
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0, '--tries' => 1]);
    $this->assertDatabaseCount('resume_adaptations', 0);
    Http::assertSentCount(1);
});

it('revalidates opportunity readiness in the ATS provider callback', function () {
    config(['ai.default' => 'opencode_go', 'ai.providers.opencode_go.key' => 'test-only-key']);
    Http::fake(function () {
        $this->posting->update(['readiness' => 'archived']);

        return Http::response([
            'id' => 'response', 'object' => 'chat.completion', 'created' => time(), 'model' => 'deepseek-v4.1-flash',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => '{"score":8,"rationale":"PHP"}'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ]);
    });
    expect(fn () => app(AtsScorerService::class)->score($this->owner, $this->posting, ResumeLanguage::PtBr))->toThrow(RuntimeException::class);
    expect($this->adaptation->fresh()->ats_score)->toBeNull();
    Http::assertSentCount(1);
});

it('preserves manual English entered while delegated translation waits', function () {
    $request = preparationCall($this, 'prepare_application', [...$this->input, 'language' => 'en', 'translate_missing' => true]);
    Resume::factory()->for($this->owner)->create(['language' => 'en', 'content' => 'Manual English']);
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0, '--tries' => 1]);
    expect($this->owner->resumes()->where('language', 'en')->value('content'))->toBe('Manual English')
        ->and(AiOperation::findOrFail($request['data']['operation_ids'][0])->status->value)->not->toBe('completed');
    Http::assertNothingSent();
});

it('delivers independent current artifacts and authenticated session links', function () {
    $first = preparationCall($this, 'export_adapted_resume_pdf', ['job_posting_id' => $this->posting->id])['data'];
    $second = preparationCall($this, 'export_adapted_resume_pdf', ['job_posting_id' => $this->posting->id])['data'];
    expect($first['artifact_id'])->not->toBe($second['artifact_id'])
        ->and(DocumentArtifact::findOrFail($first['artifact_id'])->preparation_id)->toBeNull();
    app()->forgetInstance(AgentIntegrationContext::class);
    $delivery = app(DocumentArtifactService::class)->deliver($this->owner, DocumentArtifact::findOrFail($first['artifact_id']), session: true);
    app('auth')->forgetGuards();
    $this->withHeader('Authorization', '')->actingAs($this->owner)->get($delivery['download_url'])->assertOk();
});

it('never exports an ATS block without explicit consent and rechecks ATS authorization on download', function () {
    $snapshot = app(AiInputSnapshotService::class)->atsOptimization($this->owner, $this->posting, true, ResumeLanguage::PtBr);
    $this->adaptation->update(['is_ats_optimized' => true, 'ats_prompt' => $snapshot['payload']['ats_prompt'], 'ats_prompt_fingerprint' => $snapshot['fingerprint']]);
    if (blank($snapshot['payload']['ats_prompt'])) {
        $this->posting->items()->create(['category' => 'skill', 'name' => 'PHP', 'source' => 'user']);
        $source = app(AiInputSnapshotService::class)->resumeAdaptation($this->owner, $this->posting, ResumeLanguage::PtBr);
        $snapshot = app(AiInputSnapshotService::class)->atsOptimization($this->owner, $this->posting, true, ResumeLanguage::PtBr);
        $this->adaptation->update(['source_fingerprint' => $source['fingerprint'], 'ats_prompt' => $snapshot['payload']['ats_prompt'], 'ats_prompt_fingerprint' => $snapshot['fingerprint']]);
    }
    $plain = preparationCall($this, 'prepare_application', $this->input)['data'];
    $plainGet = preparationCall($this, 'get_application_preparation', ['id' => $plain['preparation_id']])['data'];
    expect($plainGet['snapshot']['adaptation']['is_ats_optimized'])->toBeFalse()
        ->and($plainGet['snapshot']['adaptation']['ats_prompt'])->toBeNull();
    $optimized = preparationCall($this, 'prepare_application', [...$this->input, 'ats_consent' => true])['data'];
    expect($optimized['state'])->toBe('awaiting_approval');
    expect(preparationCall($this, 'export_adapted_resume_pdf', ['preparation_id' => $optimized['preparation_id']])['error']['code'])->toBe('precondition');
    $delivery = preparationCall($this, 'export_adapted_resume_pdf', ['preparation_id' => $optimized['preparation_id'], 'ats_consent' => true])['data'];
    $this->owner->update(['can_use_ats_optimization' => false]);
    app('auth')->forgetGuards();
    $this->withToken($this->token)->getJson($delivery['download_url'])->assertForbidden();
    $this->assertDatabaseCount('ai_operations', 0);
});

function preparationCall(object $test, string $tool, array $arguments): array
{
    app('auth')->forgetGuards();

    return Helpers::decodeToolResult($test->withToken($test->token)->postJson('/mcp', Helpers::toolCall($tool, $arguments))->assertOk()->getContent());
}

it('prepares V2 ready material without matching or ATS score using only confirmed profile', function () {
    $result = preparationCall($this, 'prepare_application', [...$this->input, 'cover_letter_requested' => true]);
    expect($result['ok'])->toBeTrue()
        ->and($result['data']['state'])->toBe('awaiting_approval')
        ->and($result['data']['operation_ids'])->toBe([])
        ->and($result['data']['artifacts'])->toHaveCount(2);
    $get = preparationCall($this, 'get_application_preparation', ['id' => $result['data']['preparation_id']]);
    expect($get['data']['snapshot']['profile'])->toBe(['competencies' => ['PHP']])
        ->and(json_encode($get))->not->toContain('Invented', 'documents/', 'path')
        ->and($get['data']['stale'])->toBeFalse();
    $letter = collect($get['data']['artifacts'])->firstWhere('type', 'cover_letter');
    $delivery = preparationCall($this, 'export_adapted_resume_pdf', ['artifact_id' => $letter['artifact_id']]);
    expect($delivery['ok'])->toBeTrue()->and($delivery['data']['type'])->toBe('cover_letter');
    $this->assertDatabaseCount('applications', 1);
    $this->assertDatabaseCount('ai_operations', 0);
    Http::assertNothingSent();
});

it('denies document material for non-ready V2 even with legacy confirmed status', function (string $readiness) {
    $this->posting->update(['readiness' => $readiness, 'status' => 'confirmed']);
    expect(preparationCall($this, 'prepare_application', $this->input)['error']['code'])->toBe('precondition');
    $this->assertDatabaseCount('application_preparations', 0);
})->with(['needs_review', 'archived']);

it('publishes immutable snapshots and reports exact source drift and superseded history', function () {
    $first = app(ApplicationPreparationService::class)->prepare($this->owner, $this->input);
    $artifact = DocumentArtifact::query()->firstOrFail();
    $bytes = Storage::disk('local')->get($artifact->path);
    $this->posting->update(['salary_min' => 123]);
    expect(app(ApplicationPreparationService::class)->get($this->owner, $first['preparation_id'])['stale'])->toBeTrue();
    $second = app(ApplicationPreparationService::class)->prepare($this->owner, [...$this->input, 'destination' => ['type' => 'email', 'email' => 'other@example.com']]);
    expect($second['preparation_id'])->not->toBe($first['preparation_id'])
        ->and(app(ApplicationPreparationService::class)->get($this->owner, $first['preparation_id'])['current'])->toBeFalse()
        ->and(Storage::disk('local')->get($artifact->path))->toBe($bytes);
    expect(fn () => ApplicationPreparation::query()->firstOrFail()->update(['fingerprint' => 'changed']))->toThrow(LogicException::class);
});

it('holds unsupported factual answers instead of inventing qualifications', function () {
    $result = app(ApplicationPreparationService::class)->prepare($this->owner, [...$this->input, 'questions' => [
        ['id' => 'visa', 'question' => 'Work permission?', 'answer' => 'Yes', 'support' => []],
        ['id' => 'skill', 'question' => 'Skill?', 'answer' => 'PHP', 'support' => [['source' => 'profile', 'path' => 'competencies.0', 'quote' => 'PHP']]],
    ]]);
    expect($result['state'])->toBe('pending_information')
        ->and($result['pending'][0]['question_id'])->toBe('visa');
    $snapshot = app(ApplicationPreparationService::class)->get($this->owner, $result['preparation_id'])['snapshot'];
    expect($snapshot['questions'][1]['answer'])->toBeNull();
});

it('requires authentication ownership scope expiry and revocation on signed downloads', function () {
    $result = preparationCall($this, 'prepare_application', $this->input)['data'];
    $export = preparationCall($this, 'export_adapted_resume_pdf', ['preparation_id' => $result['preparation_id']]);
    expect($export['ok'])->toBeTrue(json_encode($export));
    $delivery = $export['data'];
    expect($delivery['download_url'])->toStartWith('https://')->not->toContain('Bearer', 'token=')
        ->and($delivery)->toHaveKeys(['artifact_id', 'sha256', 'bytes', 'expires_at', 'artifact_expires_at'])
        ->and($delivery)->not->toHaveKey('path');
    app('auth')->forgetGuards();
    $this->withToken($this->token)->get($delivery['download_url'])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    app('auth')->forgetGuards();
    $this->withHeader('Authorization', '')->getJson($delivery['download_url'])->assertUnauthorized();
    $other = User::factory()->create();
    $client = Helpers::client();
    Helpers::integration($other, $client);
    app('auth')->forgetGuards();
    $this->withToken(Helpers::accessToken($other, $client))->getJson($delivery['download_url'])->assertNotFound();
    $this->integration->update(['scopes' => ['applications:read']]);
    app('auth')->forgetGuards();
    $this->withToken($this->token)->getJson($delivery['download_url'])->assertForbidden();
    $this->integration->update(['scopes' => ['ats:read']]);
    app(DocumentArtifactService::class)->revoke($this->owner, $delivery['artifact_id']);
    app('auth')->forgetGuards();
    $this->withToken($this->token)->getJson($delivery['download_url'])->assertGone();
});
