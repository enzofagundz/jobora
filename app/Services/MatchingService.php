<?php

namespace App\Services;

use App\Enums\AgentIntegrationScope;
use App\Models\AiOperation;
use App\Models\JobPosting;
use App\Models\MatchingEvaluation;
use App\Models\ProfessionalProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class MatchingService
{
    /** @param array{source?: string, integration_id?: int} $actor */
    public function authorize(User $user, array $actor): void
    {
        Validator::validate(['actor' => $actor], [
            'actor' => ['array:source,integration_id'], 'actor.source' => ['sometimes', 'required', 'in:mcp,filament,stdio,internal'],
            'actor.integration_id' => ['sometimes', 'required', 'integer:strict'],
        ]);
        $user->refresh();
        if ($user->disabled_at !== null) {
            throw new MatchingException('authorization_revoked');
        }
        if (isset($actor['integration_id']) && ! $user->agentIntegrations()->find($actor['integration_id'])?->can(AgentIntegrationScope::MatchingWrite)) {
            throw new MatchingException('authorization_revoked');
        }
    }

    /** @return array{frozen: array, provenance_warnings: list<string>} */
    public function snapshot(User $user, int $opportunityId, string $criteriaVersion = 'matching-v1'): array
    {
        Validator::validate(['criteria_version' => $criteriaVersion], ['criteria_version' => ['required', 'in:matching-v1']]);
        $posting = JobPosting::query()->where('user_id', $user->id)->findOrFail($opportunityId);
        $profile = ProfessionalProfile::query()->where('user_id', $user->id)->first();
        if ($profile?->confirmed_snapshot === null || $profile->confirmed_version < 1) {
            throw new MatchingException('profile_not_confirmed');
        }
        if ($posting->readiness !== 'ready') {
            throw new MatchingException('opportunity_not_ready');
        }
        $opportunity = $posting->only(['readiness', 'status', 'role', 'company', 'employment_type', 'requirements', 'technologies', 'description', 'seniority', 'location', 'work_mode', 'salary_min', 'salary_max', 'salary_currency', 'salary_period']);
        $opportunity['status'] = $posting->status->value;
        $opportunity['requirements'] ??= [];
        $opportunity['technologies'] ??= [];
        Validator::validate($opportunity, [
            'requirements' => ['array', 'list', 'max:100'], 'requirements.*' => ['string', 'max:10000'],
            'technologies' => ['array', 'list', 'max:100'], 'technologies.*' => ['string', 'max:10000'],
        ]);
        $frozen = [
            'user_id' => $user->id,
            'profile' => ['id' => $profile->id, 'confirmed_version' => $profile->confirmed_version, 'snapshot' => $profile->confirmed_snapshot, 'hash' => self::hash($profile->confirmed_snapshot)],
            'opportunity' => ['id' => $posting->id, 'opportunity_version' => $posting->opportunity_version, 'snapshot' => $opportunity, 'hash' => self::hash($opportunity)],
            'provenance' => [], 'anchors' => [], 'criteria_version' => $criteriaVersion,
            'schema_version' => (string) config('ai.matching.schema_version', 'matching-result-v1'),
            'provider' => (string) config('ai.matching.provider', 'opencode_go'), 'model' => (string) config('ai.matching.model', 'deepseek-v4.1-flash'),
        ];
        $warnings = [];
        foreach ($posting->sources()->with('triageResult.operation')->orderBy('id')->get() as $source) {
            $result = $source->triageResult;
            $operation = $result?->operation;
            $input = $operation?->input_snapshot ?? [];
            if ($operation?->user_id !== $user->id || $result?->publication_id !== $source->publication_id
                || $result?->content_version !== $source->publication_content_version
                || ($input['publication_id'] ?? null) !== $source->publication_id
                || ($input['content_version'] ?? null) !== $source->publication_content_version
                || ! is_string($input['content'] ?? null) || ! is_string($input['content_hash'] ?? null)
                || ! hash_equals($input['content_hash'], hash('sha256', $input['content']))) {
                $warnings[] = 'source_snapshot_unavailable:'.$source->id;

                continue;
            }
            $snapshot = ['content' => $input['content']];
            $publication = ['id' => $source->publication_id, 'version' => $source->publication_content_version, 'snapshot' => $snapshot, 'hash' => self::hash($snapshot)];
            if (! in_array($publication, $frozen['provenance'], true)) {
                $frozen['provenance'][] = $publication;
            }
        }
        foreach (['profile' => 'p', 'opportunity' => 'o'] as $source => $prefix) {
            $entity = $frozen[$source];
            $this->anchors($frozen['anchors'], $entity['snapshot'], '', $source, $prefix, $entity['id'], $entity[$source === 'profile' ? 'confirmed_version' : 'opportunity_version'], $entity['hash']);
        }
        foreach ($frozen['provenance'] as $publication) {
            $this->anchors($frozen['anchors'], $publication['snapshot'], '', 'publication', 'pub', $publication['id'], $publication['version'], $publication['hash']);
        }
        if (strlen(json_encode($frozen, JSON_THROW_ON_ERROR)) > 2000000) {
            throw new MatchingException('input_too_large');
        }

        return ['frozen' => $frozen, 'provenance_warnings' => $warnings];
    }

    private function anchors(array &$anchors, mixed $value, string $path, string $source, string $prefix, int $id, int $version, string $hash): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (in_array($key, ['readiness', 'status'], true)) {
                    continue;
                }
                $this->anchors($anchors, $item, $path.'/'.str_replace(['~', '/'], ['~0', '~1'], (string) $key), $source, $prefix, $id, $version, $hash);
            }
        } elseif (is_string($value) && $value !== '') {
            $anchors[] = ['id' => "{$prefix}:{$id}:v{$version}:{$path}", 'source' => $source, 'entity_id' => $id, 'version' => $version, 'snapshot_hash' => $hash, 'path' => $path, 'text' => $value];
        }
    }

    /** @param array<array-key, mixed> $value */
    public static function hash(array $value): string
    {
        return hash('sha256', json_encode(self::canonical($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::canonical(...), $value);
    }

    /** @return array<string, string> */
    public static function inventory(array $frozen): array
    {
        $inventory = [];
        foreach (['requirements' => 'requirement', 'technologies' => 'technology', 'preferences' => 'preference', 'restrictions' => 'restriction'] as $field => $kind) {
            $snapshot = in_array($field, ['requirements', 'technologies'], true) ? $frozen['opportunity']['snapshot'] : $frozen['profile']['snapshot'];
            foreach ($snapshot[$field] as $index => $value) {
                $inventory["{$kind}:{$index}"] = $kind;
            }
        }
        foreach (['experience', 'seniority', 'education', 'location_modality', 'compensation'] as $kind) {
            $inventory[$kind] = $kind;
        }

        return $inventory;
    }

    /** @return array<string, mixed> */
    public function get(User $user, int $id): array
    {
        $evaluation = MatchingEvaluation::query()->where('user_id', $user->id)->findOrFail($id);
        $current = $this->currentFingerprint($user, $evaluation->job_posting_id);

        return $this->present($evaluation, $current);
    }

    public function retryAllowed(User $user, AiOperation $operation): bool
    {
        if ($operation->user_id !== $user->id || $operation->status->value !== 'failed' || $operation->attempts >= 3) {
            return false;
        }
        try {
            $this->authorize($user, $operation->input_snapshot['requester_actor'] ?? []);

            return $this->currentFingerprint($user, $operation->target_id) === $operation->input_fingerprint;
        } catch (MatchingException|ModelNotFoundException|ValidationException) {
            return false;
        }
    }

    /** @return array{opportunity_id: int, current: ?array, history: array{items: array, total: int, limit: int, offset: int, next_offset: ?int}} */
    public function forOpportunity(User $user, int $opportunityId, int $limit = 20, int $offset = 0): array
    {
        JobPosting::query()->where('user_id', $user->id)->findOrFail($opportunityId);
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $fingerprint = $this->currentFingerprint($user, $opportunityId);
        $query = MatchingEvaluation::query()->where('user_id', $user->id)->where('job_posting_id', $opportunityId);
        $total = (clone $query)->count();
        $current = $fingerprint === null ? null : (clone $query)->where('input_fingerprint', $fingerprint)->first();
        $items = $query->latest('id')->offset($offset)->limit($limit)->get()->map(fn (MatchingEvaluation $evaluation): array => $this->present($evaluation, $fingerprint))->all();

        return ['opportunity_id' => $opportunityId, 'current' => $current === null ? null : $this->present($current, $fingerprint), 'history' => [
            'items' => $items, 'total' => $total, 'limit' => $limit, 'offset' => $offset, 'next_offset' => $offset + $limit < $total ? $offset + $limit : null,
        ]];
    }

    private function currentFingerprint(User $user, int $opportunityId): ?string
    {
        try {
            return self::hash($this->snapshot($user, $opportunityId, (string) config('ai.matching.criteria_version', 'matching-v1'))['frozen']);
        } catch (MatchingException|ValidationException) {
            return null;
        }
    }

    private function present(MatchingEvaluation $evaluation, ?string $currentFingerprint): array
    {
        $stale = $currentFingerprint === null || ! hash_equals($evaluation->input_fingerprint, $currentFingerprint);

        return [
            'id' => $evaluation->id, 'opportunity_id' => $evaluation->job_posting_id, 'operation_id' => $evaluation->ai_operation_id,
            'status' => $stale ? 'stale' : 'current', 'stale' => $stale, 'input_fingerprint' => $evaluation->input_fingerprint,
            'result' => $evaluation->result, 'anchors' => $evaluation->frozen['anchors'], 'provenance' => $evaluation->frozen['provenance'],
            'provenance_warnings' => $evaluation->provenance_warnings, 'provider' => $evaluation->provider, 'model' => $evaluation->model,
            'prompt_tokens' => $evaluation->prompt_tokens, 'completion_tokens' => $evaluation->completion_tokens,
            'created_at' => $evaluation->created_at->toISOString(),
        ];
    }
}
