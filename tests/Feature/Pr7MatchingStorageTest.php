<?php

use App\Enums\AiOperationType;
use App\Models\AiOperation;
use App\Models\JobPosting;
use App\Models\MatchingEvaluation;
use App\Models\ProfessionalProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('round trips matching payloads near the accepted two million byte boundary', function () {
    $posting = JobPosting::factory()->create();
    $profile = ProfessionalProfile::factory()->create(['user_id' => $posting->user_id]);
    $operation = AiOperation::query()->create([
        'user_id' => $posting->user_id, 'operation_key' => 'matching:'.Str::uuid(),
        'operation_type' => AiOperationType::Matching, 'target_type' => 'job_posting', 'target_id' => $posting->id,
        'run_token' => (string) Str::uuid(), 'queued_at' => now(), 'expires_at' => now()->addMinutes(5),
        'input_fingerprint' => str_repeat('a', 64),
    ]);
    $payload = ['text' => str_repeat('x', 1999900)];
    expect(strlen(json_encode($payload, JSON_THROW_ON_ERROR)))->toBeLessThanOrEqual(2000000);
    $evaluation = MatchingEvaluation::query()->create([
        'user_id' => $posting->user_id, 'professional_profile_id' => $profile->id, 'job_posting_id' => $posting->id,
        'ai_operation_id' => $operation->id, 'run_token' => $operation->run_token, 'attempt_token' => (string) Str::uuid(),
        'confirmed_version' => 1, 'opportunity_version' => 1, 'profile_hash' => str_repeat('b', 64),
        'opportunity_hash' => str_repeat('c', 64), 'input_fingerprint' => str_repeat('a', 64),
        'criteria_version' => 'matching-v1', 'schema_version' => 'matching-result-v1', 'provider' => 'test', 'model' => 'test',
        'frozen' => $payload, 'result' => $payload, 'provenance_warnings' => $payload,
    ]);
    $reloaded = $evaluation->fresh();
    foreach (['frozen', 'result', 'provenance_warnings'] as $column) {
        expect(strlen($evaluation->getRawOriginal($column)))->toBeGreaterThan(2000000)
            ->and($reloaded->{$column})->toBe($payload);
    }
});
