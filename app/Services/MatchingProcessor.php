<?php

namespace App\Services;

use App\Agents\MatchingAgent;
use App\Agents\PublicationTriageGoGateway;
use App\DTO\MatchingOutput;
use App\Models\AiOperation;
use App\Models\MatchingEvaluation;
use App\Support\Ai\AiRequestGate;
use Closure;
use Laravel\Ai\Ai;

class MatchingProcessor
{
    public function __construct(private readonly MatchingService $matching) {}

    /** @param Closure(Closure): mixed $persist */
    public function process(AiOperation $operation, string $runToken, string $attemptToken, Closure $persist): void
    {
        $input = $operation->input_snapshot;
        $frozen = $input['frozen'];
        $this->matching->authorize($operation->user, $input['requester_actor']);
        $provider = $frozen['provider'];
        $model = $frozen['model'];
        $gateway = new PublicationTriageGoGateway(app('events'));
        Ai::textProvider($provider)->useTextGateway($gateway);
        $prompt = json_encode(['frozen' => $frozen, 'input_fingerprint' => $operation->input_fingerprint, 'basis' => MatchingOutput::basis($frozen), 'inventory' => MatchingService::inventory($frozen)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $response = app(AiRequestGate::class)->run($provider, $model, fn () => app(MatchingAgent::class)->prompt($prompt, provider: $provider, model: $model, timeout: 45));
        $result = MatchingOutput::parse($response->text, $frozen, $operation->input_fingerprint);
        $persist(function () use ($operation, $input, $frozen, $result, $runToken, $attemptToken, $gateway): void {
            $this->matching->authorize($operation->user, $input['requester_actor']);
            $completed = AiOperation::query()->whereKey($operation->id)->where('run_token', $runToken)->where('attempt_token', $attemptToken)
                ->where('status', 'running')->where('expires_at', '>', now())->update(['status' => 'completed', 'completed_at' => now(), 'failure_code' => null]);
            if ($completed !== 1) {
                throw new MatchingException('worker_unavailable');
            }
            MatchingEvaluation::query()->firstOrCreate(['user_id' => $operation->user_id, 'input_fingerprint' => $operation->input_fingerprint], [
                'professional_profile_id' => $frozen['profile']['id'], 'job_posting_id' => $frozen['opportunity']['id'],
                'ai_operation_id' => $operation->id, 'run_token' => $runToken, 'attempt_token' => $attemptToken,
                'confirmed_version' => $frozen['profile']['confirmed_version'], 'opportunity_version' => $frozen['opportunity']['opportunity_version'],
                'profile_hash' => $frozen['profile']['hash'], 'opportunity_hash' => $frozen['opportunity']['hash'],
                'criteria_version' => $frozen['criteria_version'], 'schema_version' => $frozen['schema_version'],
                'provider' => $frozen['provider'], 'model' => $frozen['model'], 'frozen' => $frozen, 'result' => $result,
                'provenance_warnings' => $input['provenance_warnings'], 'prompt_tokens' => $gateway->promptTokens, 'completion_tokens' => $gateway->completionTokens,
            ]);
        });
    }
}
