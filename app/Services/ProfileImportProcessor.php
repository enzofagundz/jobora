<?php

namespace App\Services;

use App\Agents\ProfileImportAgent;
use App\Agents\PublicationTriageGoGateway;
use App\DTO\ProfileImportOutput;
use App\Enums\AgentIntegrationScope;
use App\Models\AiOperation;
use App\Models\ProfileImportProposal;
use App\Models\User;
use App\Support\Ai\AiRequestGate;
use Closure;
use Laravel\Ai\Ai;

class ProfileImportProcessor
{
    /** @param array{source?: string, integration_id?: int} $actor */
    public function authorize(User $user, array $actor): void
    {
        $user->refresh();
        if ($user->disabled_at !== null) {
            throw new ProfileImportException('authorization_revoked');
        }
        if (isset($actor['integration_id'])) {
            $integration = $user->agentIntegrations()->find($actor['integration_id']);
            if ($integration === null || ! $integration->can(AgentIntegrationScope::ProfileWrite)) {
                throw new ProfileImportException('authorization_revoked');
            }
        }
    }

    /** @param Closure(Closure): mixed $persist */
    public function process(AiOperation $operation, string $runToken, string $attemptToken, Closure $persist): void
    {
        $snapshot = $operation->input_snapshot;
        $this->authorize($operation->user, $snapshot['requester_actor']);
        $provider = $snapshot['provider'];
        $model = $snapshot['model'];
        $gateway = new PublicationTriageGoGateway(app('events'));
        Ai::textProvider($provider)->useTextGateway($gateway);
        $response = app(AiRequestGate::class)->run($provider, $model, fn () => app(ProfileImportAgent::class)->prompt(
            json_encode(['blocks' => $snapshot['blocks']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            provider: $provider, model: $model, timeout: 45,
        ));
        $output = ProfileImportOutput::parse($response->text, $snapshot['blocks']);

        $persist(function () use ($operation, $snapshot, $output, $runToken, $attemptToken, $gateway): void {
            $this->authorize($operation->user, $snapshot['requester_actor']);
            ProfileImportProposal::query()->firstOrCreate([
                'ai_operation_id' => $operation->id, 'run_token' => $runToken, 'attempt_token' => $attemptToken,
            ], [
                'user_id' => $operation->user_id, 'professional_profile_id' => $snapshot['profile_id'],
                'base_version' => $snapshot['base_version'], 'base_confirmed_version' => $snapshot['base_confirmed_version'],
                'source' => $snapshot['source'], 'base_document' => $snapshot['base_document'],
                'candidate' => $output['candidate'], 'evidence' => $output['evidence'],
                'requester_actor' => [...$snapshot['requester_actor'], 'user_id' => $operation->user_id], 'status' => 'pending',
                'prompt_tokens' => $gateway->promptTokens, 'completion_tokens' => $gateway->completionTokens,
            ]);
            $completed = AiOperation::query()->whereKey($operation->id)->where('run_token', $runToken)
                ->where('attempt_token', $attemptToken)->where('status', 'running')->update([
                    'status' => 'completed', 'completed_at' => now(), 'failure_code' => null,
                ]);
            if ($completed !== 1) {
                throw new ProfileImportException('attempt_lost');
            }
        });
    }
}
