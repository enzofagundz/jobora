<?php

namespace App\Services;

use App\Enums\AgentIntegrationScope;
use App\Mcp\Support\AgentIntegrationContext;
use App\Models\JobPosting;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use LogicException;

class ApplicationDocumentAccess
{
    /** @param array{source?: string, integration_id?: int} $actor */
    public function authorize(User $user, array $actor = [], ?AgentIntegrationScope $scope = null): void
    {
        Validator::validate(['actor' => $actor], [
            'actor' => ['array:source,integration_id'],
            'actor.source' => ['sometimes', 'required', 'in:filament,mcp,stdio,internal'],
            'actor.integration_id' => ['sometimes', 'required', 'integer:strict'],
        ]);
        $currentUser = $user->fresh();
        if ($currentUser === null || $currentUser->disabled_at !== null) {
            throw new AuthorizationException('Conta desabilitada.');
        }
        $integrationId = $actor['integration_id'] ?? null;
        if (app()->bound(AgentIntegrationContext::class)) {
            $context = app(AgentIntegrationContext::class);
            if ($context->user->id !== $user->id || ($integrationId !== null && $integrationId !== $context->integration->id)) {
                throw new AuthorizationException;
            }
            $integrationId = $context->integration->id;
        }
        if ($integrationId !== null) {
            $integration = $user->agentIntegrations()->find($integrationId);
            if ($integration === null || $integration->isRevoked() || ($scope !== null && ! $integration->can($scope))) {
                throw new AuthorizationException('A integração não possui permissão para esta operação.');
            }
        }
    }

    public function posting(User $user, JobPosting $posting): void
    {
        $this->authorize($user);
        if ($posting->user_id !== $user->id || ! $posting->isEligibleForApplicationDocuments()) {
            throw new LogicException('Os documentos exigem uma vaga confirmada legada ou uma oportunidade V2 pronta para candidatura.');
        }
    }

    public function sourceHash(User $user, ?JobPosting $posting = null): string
    {
        $profile = $user->professionalProfile()->first();

        return hash('sha256', json_encode([
            $profile?->confirmed_version, $profile?->confirmed_snapshot,
            $user->resumes()->orderBy('id')->get()->map(fn ($resume): array => $resume->only(['id', 'language', 'content', 'source_fingerprint']))->all(),
            $posting?->getAttributes(), $posting?->items()->orderBy('id')->get()->toArray(),
            $posting?->resumeAdaptations()->orderBy('id')->get()->toArray(),
        ], JSON_THROW_ON_ERROR));
    }

    public function versionHash(User $user, ?JobPosting $posting): string
    {
        $profile = $user->professionalProfile()->first();

        return hash('sha256', json_encode([
            $profile?->confirmed_version, $profile?->confirmed_snapshot,
            $posting?->opportunity_version, $posting?->readiness,
        ], JSON_THROW_ON_ERROR));
    }
}
