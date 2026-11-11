<?php

namespace App\Services;

use App\Models\ProfileImportProposal;
use App\Models\Resume;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ProfileImportService
{
    public function __construct(private readonly ProfessionalProfileService $profiles) {}

    /** @param array{source?: string, integration_id?: int} $actorMetadata */
    public function validateActor(User $user, array $actorMetadata): void
    {
        Validator::validate(['actor' => $actorMetadata], [
            'actor' => ['array:source,integration_id'],
            'actor.source' => ['sometimes', 'required', 'in:filament,mcp,stdio,internal'],
            'actor.integration_id' => ['sometimes', 'required', 'integer:strict'],
        ]);
        app(ProfileImportProcessor::class)->authorize($user, $actorMetadata);
    }

    /** @return array{id: string, status: string, base_version: int, base_confirmed_version: int, current_version: int, stale: bool, source: array, candidate: array, changes: array, evidence: array, allowed_actions: list<string>, result_version: ?int, decided_at: ?string} */
    public function get(User $user, string $id): array
    {
        $proposal = $this->owned($user, $id);
        $state = $this->profiles->get($user);
        $changes = [];
        foreach ($proposal->candidate as $section => $value) {
            $changes[$section] = ['before' => $proposal->base_document[$section], 'after' => $value];
        }
        $source = $proposal->source;
        $currentContent = Resume::query()->where('user_id', $user->id)->where('language', $source['language'])->value('content');
        unset($source['content']);
        $source['source_stale'] = ! is_string($currentContent) || ! hash_equals($source['fingerprint'], hash('sha256', $currentContent));
        $stale = $proposal->status === 'pending' && $state['version'] !== $proposal->base_version;

        return [
            'id' => (string) $proposal->id, 'status' => $proposal->status,
            'base_version' => $proposal->base_version, 'base_confirmed_version' => $proposal->base_confirmed_version,
            'current_version' => $state['version'], 'stale' => $stale, 'source' => $source,
            'candidate' => $proposal->candidate, 'changes' => $changes, 'evidence' => $proposal->evidence,
            'allowed_actions' => $proposal->status !== 'pending' ? [] : ($stale ? ['reject'] : ['confirm', 'reject']),
            'result_version' => $proposal->result_version,
            'decided_at' => $proposal->decided_at?->toISOString(),
        ];
    }

    /**
     * @param  array{source?: string, integration_id?: int}  $actorMetadata
     * @return array<string, mixed>
     */
    public function confirm(User $user, string $id, int $expectedVersion, array $actorMetadata = []): array
    {
        $this->validateActor($user, $actorMetadata);

        return DB::transaction(function () use ($user, $id, $expectedVersion, $actorMetadata): array {
            $proposal = $this->owned($user, $id, lock: true);
            if ($expectedVersion !== $proposal->base_version || $proposal->status === 'rejected') {
                throw new ProfessionalProfileConflictException;
            }
            if ($proposal->status === 'confirmed') {
                return [...$this->get($user, $id), 'profile' => $proposal->decision_result];
            }
            if ($this->profiles->get($user)['version'] !== $proposal->base_version) {
                throw new ProfessionalProfileConflictException;
            }
            $this->claim($proposal, 'confirmed');
            $document = array_replace($proposal->base_document, $proposal->candidate);
            $this->profiles->saveDraft($user, $document, $expectedVersion);
            $result = $this->profiles->confirm($user, $expectedVersion + 1, $actorMetadata);
            $proposal->forceFill([
                'decision_actor' => [...$actorMetadata, 'user_id' => $user->id], 'decided_at' => now(),
                'result_version' => $result['version'], 'decision_result' => $result,
            ])->save();

            return [...$this->get($user, $id), 'profile' => $result];
        }, 3);
    }

    /**
     * @param  array{source?: string, integration_id?: int}  $actorMetadata
     * @return array<string, mixed>
     */
    public function reject(User $user, string $id, array $actorMetadata = []): array
    {
        $this->validateActor($user, $actorMetadata);

        return DB::transaction(function () use ($user, $id, $actorMetadata): array {
            $proposal = $this->owned($user, $id, lock: true);
            if ($proposal->status === 'confirmed') {
                throw new ProfessionalProfileConflictException;
            }
            if ($proposal->status === 'pending') {
                $this->claim($proposal, 'rejected');
                $proposal->forceFill(['decision_actor' => [...$actorMetadata, 'user_id' => $user->id], 'decided_at' => now()])->save();
            }

            return $this->get($user, $id);
        }, 3);
    }

    private function claim(ProfileImportProposal $proposal, string $status): void
    {
        if (ProfileImportProposal::query()->whereKey($proposal->id)->where('status', 'pending')->update(['status' => $status]) !== 1) {
            throw new ProfessionalProfileConflictException;
        }
        $proposal->status = $status;
    }

    private function owned(User $user, string $id, bool $lock = false): ProfileImportProposal
    {
        return ProfileImportProposal::query()->where('user_id', $user->id)->whereKey($id)
            ->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
    }
}
