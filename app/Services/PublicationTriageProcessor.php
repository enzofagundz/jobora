<?php

namespace App\Services;

use App\Agents\PublicationTriageAgent;
use App\Agents\PublicationTriageGoGateway;
use App\DTO\ApplicationMethodData;
use App\DTO\PublicationTriageOutput;
use App\Enums\ConceptCategory;
use App\Enums\ItemSource;
use App\Enums\JobPostingStatus;
use App\Models\AiOperation;
use App\Models\ApplicationMethod;
use App\Models\JobPostingItem;
use App\Models\OpportunitySource;
use App\Models\PublicationTriageResult;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Ai;
use LogicException;

final class PublicationTriageProcessor
{
    public function __construct(private readonly PublicationTriageAgent $agent) {}

    public function process(
        User $user,
        AiOperation $operation,
        array $snapshot,
        string $runToken,
        string $attemptToken,
        Closure $persist,
    ): void {
        $prompt = json_encode([
            'publication' => [
                'url' => $snapshot['url'] ?? null,
                'content' => $snapshot['content'] ?? null,
                'evidence_block_ids' => $snapshot['evidence_block_ids'] ?? [],
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $gateway = null;
        if ($snapshot['provider'] === 'opencode_go') {
            $gateway = new PublicationTriageGoGateway(app('events'));
            Ai::textProvider('opencode_go')->useTextGateway($gateway);
        }
        $response = $this->agent->prompt(
            $prompt,
            provider: (string) $snapshot['provider'],
            model: (string) $snapshot['model'],
            timeout: $gateway === null ? null : 45,
        );
        $promptTokens = $gateway === null ? $response->usage->promptTokens : $gateway->promptTokens;
        $completionTokens = $gateway === null ? $response->usage->completionTokens : $gateway->completionTokens;

        try {
            if ($gateway !== null) {
                $shape = json_decode($response->text, false, 64, JSON_THROW_ON_ERROR);
                if (! $shape instanceof \stdClass || ! is_array($shape->opportunities ?? null)) {
                    throw new \InvalidArgumentException('Structured output envelope is invalid.');
                }
            }
            $output = PublicationTriageOutput::fromStructured(
                $response->structured,
                (string) $snapshot['content'],
                (array) $snapshot['evidence_block_ids'],
                $snapshot['url'] === null ? null : (string) $snapshot['url'],
            );
        } catch (\InvalidArgumentException|\JsonException $exception) {
            throw new PublicationTriageInvalidOutput(
                $promptTokens,
                $completionTokens,
                $exception,
            );
        }

        $persist(function () use ($user, $operation, $snapshot, $runToken, $attemptToken, $promptTokens, $completionTokens, $output): void {
            DB::transaction(function () use ($user, $operation, $snapshot, $runToken, $attemptToken, $promptTokens, $completionTokens, $output): void {
                $result = PublicationTriageResult::query()->create([
                    'ai_operation_id' => $operation->id,
                    'publication_id' => $snapshot['publication_id'],
                    'content_version' => $snapshot['content_version'],
                    'run_token' => $runToken,
                    'attempt_token' => $attemptToken,
                    'attempt' => $operation->attempts,
                    'status' => collect($output->opportunities)->contains(fn (array $opportunity): bool => $opportunity['readiness'] === 'needs_review')
                        ? 'needs_review'
                        : 'completed',
                    'provider' => $snapshot['provider'],
                    'model' => $snapshot['model'],
                    'schema_version' => $snapshot['schema_version'],
                    'input_fingerprint' => $operation->input_fingerprint,
                    'output' => [
                        'outcome' => $output->opportunities === [] ? 'not_a_job' : 'opportunities',
                        'opportunities' => $output->opportunities,
                    ],
                    'prompt_tokens' => $promptTokens,
                    'completion_tokens' => $completionTokens,
                    'clef_result_id' => $snapshot['clef_result_id'] ?? null,
                    'clef_status' => $snapshot['clef_status'] ?? null,
                    'clef_bypassed' => $snapshot['clef_bypassed'] ?? false,
                    'clef_bypass_reason' => $snapshot['clef_bypass_reason'] ?? null,
                    'requested_by' => $snapshot['requested_by'] ?? null,
                ]);

                foreach ($output->opportunities as $opportunity) {
                    $posting = $user->jobPostings()->create([
                        'description' => $opportunity['description'],
                        'source_url' => $snapshot['url'],
                        'collected_at' => now(),
                        'status' => JobPostingStatus::Pending,
                        'role' => $opportunity['role'],
                        'company' => $opportunity['company'],
                        'readiness' => $opportunity['readiness'],
                        'opportunity_version' => 1,
                        'location' => $opportunity['location'],
                        'work_mode' => $opportunity['work_mode'],
                        'employment_type' => $opportunity['employment_type'],
                        'salary_min' => $opportunity['salary_min'],
                        'salary_max' => $opportunity['salary_max'],
                        'salary_currency' => $opportunity['salary_currency'],
                        'salary_period' => $opportunity['salary_period'],
                        'seniority' => $opportunity['seniority'],
                        'requirements' => $opportunity['requirements'],
                        'technologies' => $opportunity['technologies'],
                        'evidence' => $opportunity['evidence'],
                        'review_reasons' => $opportunity['review_reasons'],
                    ]);

                    foreach (array_unique($opportunity['requirements']) as $requirement) {
                        JobPostingItem::query()->create([
                            'job_posting_id' => $posting->id,
                            'category' => ConceptCategory::Requirement,
                            'name' => $requirement,
                            'source' => ItemSource::Ai,
                        ]);
                    }

                    foreach (array_unique($opportunity['technologies']) as $technology) {
                        JobPostingItem::query()->create([
                            'job_posting_id' => $posting->id,
                            'category' => ConceptCategory::Skill,
                            'name' => $technology,
                            'source' => ItemSource::Ai,
                        ]);
                    }

                    $seenMethods = [];

                    foreach ($opportunity['application_methods'] as $method) {
                        $methodData = ApplicationMethodData::fromArray($method);

                        if ($methodData === null) {
                            continue;
                        }

                        $dedupKey = $methodData->dedupKey();

                        if (isset($seenMethods[$dedupKey])) {
                            continue;
                        }

                        $seenMethods[$dedupKey] = true;

                        ApplicationMethod::query()->create([
                            'job_posting_id' => $posting->id,
                            ...$methodData->toArray(),
                            'source' => ItemSource::Ai,
                            'dedup_key' => $dedupKey,
                        ]);
                    }

                    OpportunitySource::query()->create([
                        'job_posting_id' => $posting->id,
                        'publication_id' => $snapshot['publication_id'],
                        'is_legacy' => false,
                        'publication_content_version' => $snapshot['content_version'],
                        'publication_triage_result_id' => $result->id,
                        'evidence_block_ids' => collect($opportunity['evidence'])->pluck('block_ids')->flatten()->unique()->values()->all(),
                        'evidence' => $opportunity['evidence'],
                    ]);
                }

                $completed = AiOperation::query()
                    ->whereKey($operation->id)
                    ->where('run_token', $runToken)
                    ->where('attempt_token', $attemptToken)
                    ->where('status', 'running')
                    ->update([
                        'status' => 'completed',
                        'completed_at' => now(),
                        'failure_code' => null,
                    ]);

                if ($completed !== 1) {
                    throw new LogicException('The publication triage lease was lost before commit.');
                }
            });
        });
    }
}
