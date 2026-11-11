<?php

use App\Agents\PublicationTriageAgent;
use App\DTO\PublicationTriageOutput;
use App\Enums\AiOperationType;
use App\Enums\ApplicationMethodType;
use App\Models\AiOperation;
use App\Models\Publication;
use App\Models\PublicationTriageResult;
use App\Models\User;
use App\Services\PublicationTriageProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function prReviewTriage(array $opportunities, string $content, string $provider = 'opencode_go'): AiOperation
{
    Http::preventStrayRequests();
    config(["ai.providers.{$provider}.key" => 'test-key']);
    $user = User::factory()->create();
    $publication = Publication::factory()->for($user)->create(['content' => $content]);
    $snapshot = [
        'publication_id' => $publication->id,
        'content_version' => 1,
        'content' => $content,
        'url' => 'https://example.com/publication',
        'evidence_block_ids' => ['block-1'],
        'provider' => $provider,
        'model' => $provider === 'opencode_go' ? 'deepseek-v4.1-flash' : 'deepseek-v4.1-flash-free',
        'schema_version' => 'publication-triage-v1',
    ];
    $operation = AiOperation::query()->create([
        'user_id' => $user->id,
        'operation_key' => (string) Str::uuid(),
        'operation_type' => AiOperationType::PublicationTriage,
        'target_type' => 'publication',
        'target_id' => $publication->id,
        'run_token' => (string) Str::uuid(),
        'attempt_token' => (string) Str::uuid(),
        'status' => 'running',
        'attempts' => 1,
        'queued_at' => now(),
        'expires_at' => now()->addMinutes(3),
        'input_snapshot' => $snapshot,
        'input_fingerprint' => hash('sha256', json_encode($snapshot)),
    ]);
    Http::fake(['*' => Http::response([
        'choices' => [['message' => ['content' => json_encode(['opportunities' => $opportunities])], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 6, 'completion_tokens' => 2],
    ])]);
    app(PublicationTriageProcessor::class)->process($user, $operation, $snapshot, $operation->run_token, $operation->attempt_token, function (Closure $persist): void {
        $persist();
    });

    return $operation->fresh();
}

it('constrains triage schema strings to the DTO contract', function () {
    $agent = new PublicationTriageAgent;
    $schema = $agent->schema(new JsonSchemaTypeFactory)['opportunities']->toArray()['items']['properties'];
    $method = $schema['application_methods']['items']['properties'];
    $types = array_column(ApplicationMethodType::cases(), 'value');

    expect($method['type']['enum'] ?? null)->toBe($types);
    foreach ($types as $type) {
        expect($agent->instructions())->toContain($type);
    }
    foreach (['role' => 255, 'company' => 255, 'description' => 100000, 'location' => 255, 'work_mode' => 100, 'employment_type' => 100, 'salary_currency' => 3, 'salary_period' => 50, 'seniority' => 100] as $field => $length) {
        expect($schema[$field]['maxLength'] ?? null)->toBe($length);
    }
    foreach (['platform' => 100, 'url' => 2048, 'email' => 320, 'phone' => 50, 'instructions' => 10000] as $field => $length) {
        expect($method[$field]['maxLength'] ?? null)->toBe($length);
    }
    expect($schema['requirements']['items']['maxLength'] ?? null)->toBe(100)
        ->and($schema['technologies']['items']['maxLength'] ?? null)->toBe(100)
        ->and($schema['evidence']['items']['properties']['quote']['maxLength'] ?? null)->toBe(2000)
        ->and($schema['evidence']['items']['properties']['block_ids']['items']['maxLength'] ?? null)->toBe(128)
        ->and($schema['evidence']['items']['properties']['fields']['items']['maxLength'] ?? null)->toBe(64);
});

it('recognizes grouped salary evidence without accepting a different amount', function (string $salary, float $amount) {
    $content = "Developer salary {$salary}";
    $opportunity = [
        'role' => 'Developer',
        'salary_min' => $amount,
        'salary_max' => $amount,
        'evidence' => [['quote' => $content, 'block_ids' => ['block-1'], 'fields' => ['role', 'salary_min', 'salary_max']]],
    ];
    $output = PublicationTriageOutput::fromStructured(['opportunities' => [$opportunity]], $content, ['block-1'], null);
    expect($output->opportunities[0]['readiness'])->toBe('ready');
    $opportunity['salary_min'] = $amount + 1;
    expect(PublicationTriageOutput::unsupportedFields($opportunity))->toContain('salary_min');
})->with([
    ['R$ 5.000', 5000.0],
    ['1,500', 1500.0],
    ['1.234.567', 1234567.0],
    ['5.000,50', 5000.5],
    ['1,500.50', 1500.5],
]);

it('deduplicates triage items and normalized application methods before insertion', function (bool $duplicateItems) {
    $content = 'Developer PHP php SQL apply jobs@example.com JOBS@example.com https://example.com/apply/ https://example.com/apply';
    $operation = prReviewTriage([[
        'role' => 'Developer',
        'requirements' => $duplicateItems ? ['PHP', 'PHP', 'php'] : ['PHP', 'php'],
        'technologies' => $duplicateItems ? ['SQL', 'SQL'] : ['SQL'],
        'application_methods' => [
            ['type' => 'email', 'email' => 'jobs@example.com'],
            ['type' => 'email', 'email' => 'JOBS@example.com'],
            ['type' => 'external_url', 'url' => 'https://example.com/apply/'],
            ['type' => 'external_url', 'url' => 'https://example.com/apply'],
        ],
        'evidence' => [['quote' => $content, 'block_ids' => ['block-1'], 'fields' => ['role', 'requirements', 'technologies', 'application_methods']]],
    ]], $content);
    $posting = $operation->user->jobPostings()->sole();
    expect($operation->status->value)->toBe('completed')
        ->and($posting->items()->count())->toBe(3)
        ->and($posting->applicationMethods()->count())->toBe(2)
        ->and($posting->readiness)->toBe('ready')
        ->and(PublicationTriageResult::query()->count())->toBe(1);
})->with([true, false]);

it('retains valid opportunities and skips unsafe application methods', function (array $method, string $reason) {
    $content = 'Developer apply www.example.com/apply javascript:alert(1) https:// jobs@invalid jobs@example.com';
    $opportunity = [
        'role' => 'Developer',
        'evidence' => [['quote' => $content, 'block_ids' => ['block-1'], 'fields' => ['role', 'application_methods']]],
    ];
    $operation = prReviewTriage([
        [...$opportunity, 'application_methods' => [['type' => 'email', 'email' => 'jobs@example.com']]],
        [...$opportunity, 'application_methods' => [$method]],
    ], $content);
    $postings = $operation->user->jobPostings()->orderBy('id')->get();
    expect($operation->status->value)->toBe('completed')
        ->and($postings)->toHaveCount(2)
        ->and($postings[0]->applicationMethods()->count())->toBe(1)
        ->and($postings[1]->readiness)->toBe('needs_review')
        ->and($postings[1]->review_reasons)->toContain($reason)
        ->and($postings[1]->applicationMethods()->count())->toBe(0)
        ->and(PublicationTriageResult::query()->sole()->status)->toBe('needs_review');
})->with([
    [['type' => 'external_url', 'url' => 'www.example.com/apply'], 'application_url_inconsistent'],
    [['type' => 'external_url', 'url' => 'javascript:alert(1)'], 'application_url_inconsistent'],
    [['type' => 'external_url', 'url' => 'https://'], 'application_url_inconsistent'],
    [['type' => 'email', 'email' => 'jobs@invalid'], 'unsupported_field_evidence'],
]);

it('accepts other application methods only with useful grounded data', function (array $method, bool $ready) {
    $content = 'Developer: bring your resume to reception. https://example.com/apply jobs@example.com +5511999999999';
    $opportunity = [
        'role' => 'Developer',
        'application_methods' => [['type' => 'other', ...$method]],
        'evidence' => [['quote' => $content, 'block_ids' => ['block-1'], 'fields' => ['role', 'application_methods']]],
    ];
    $output = PublicationTriageOutput::fromStructured(['opportunities' => [$opportunity]], $content, ['block-1'], null);
    expect($output->opportunities[0]['readiness'])->toBe($ready ? 'ready' : 'needs_review');
})->with([
    [['instructions' => 'bring your resume to reception'], true],
    [['url' => 'https://example.com/apply'], true],
    [['email' => 'jobs@example.com'], true],
    [['phone' => '+5511999999999'], true],
    [[], false],
    [['platform' => 'Developer'], false],
    [['instructions' => 'send a private message'], false],
    [['instructions' => 'bring your resume to reception', 'email' => 'invented@example.com'], false],
]);
