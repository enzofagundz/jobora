<?php

namespace App\Services;

use App\Models\ProfessionalProfile;
use App\Models\ScreeningPreference;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ProfessionalProfileService
{
    /**
     * @param  array{source?: string, integration_id?: int}  $actorMetadata
     * @return array<string, mixed>
     */
    public function confirm(User $user, int $expectedVersion, array $actorMetadata = []): array
    {
        $this->validateVersion($expectedVersion);
        Validator::validate(['actor' => $actorMetadata], [
            'actor' => ['array:source,integration_id'],
            'actor.source' => ['sometimes', 'required', 'string', 'in:filament,mcp,stdio,internal'],
            'actor.integration_id' => ['sometimes', 'required', 'integer:strict'],
        ]);
        $actor = ['user_id' => $user->id, 'channel' => $actorMetadata['source'] ?? 'internal'];

        if (isset($actorMetadata['integration_id'])) {
            $integration = $user->agentIntegrations()->findOrFail($actorMetadata['integration_id']);
            $actor['integration_id'] = $integration->id;
        }

        return DB::transaction(function () use ($user, $expectedVersion, $actor): array {
            $profile = $this->initialize($user);
            $snapshot = $this->get($user)['draft'];
            $this->compareAndSwap($profile, $expectedVersion, [
                'confirmed_snapshot' => $snapshot,
                'confirmed_version' => $expectedVersion + 1,
                'confirmed_at' => now(),
                'confirmed_actor' => $actor,
            ]);

            return $this->get($user);
        }, 3);
    }

    /**
     * @param  array{experiences: list<array{company: ?string, role: ?string, period: ?string, description: ?string}>, education: list<array{institution: ?string, qualification: ?string, period: ?string}>, competencies: list<string>, preferences: list<string>, restrictions: list<string>, compensation_expectation: ?string}  $document
     * @return array<string, mixed>
     */
    public function saveDraft(User $user, array $document, int $expectedVersion): array
    {
        $this->validateVersion($expectedVersion);
        $rules = [
            'profile' => ['required', 'array:experiences,education,competencies,preferences,restrictions,compensation_expectation'],
            'profile.experiences' => ['present', 'array', 'list', 'max:100'],
            'profile.experiences.*' => ['required', 'array:company,role,period,description'],
            'profile.education' => ['present', 'array', 'list', 'max:100'],
            'profile.education.*' => ['required', 'array:institution,qualification,period'],
            'profile.competencies' => ['present', 'array', 'list', 'max:100'],
            'profile.competencies.*' => ['required', 'string', 'max:100'],
            'profile.preferences' => ['present', 'array', 'list', 'max:100'],
            'profile.preferences.*' => ['required', 'string', 'max:100'],
            'profile.restrictions' => ['present', 'array', 'list', 'max:100'],
            'profile.restrictions.*' => ['required', 'string', 'max:100'],
            'profile.compensation_expectation' => ['present', 'nullable', 'string', 'max:500'],
        ];

        foreach (['company' => 255, 'role' => 255, 'period' => 100, 'description' => 5000] as $field => $limit) {
            $rules["profile.experiences.*.{$field}"] = ['present', 'nullable', 'string', "max:{$limit}"];
        }

        foreach (['institution' => 255, 'qualification' => 255, 'period' => 100] as $field => $limit) {
            $rules["profile.education.*.{$field}"] = ['present', 'nullable', 'string', "max:{$limit}"];
        }

        Validator::validate(['profile' => $document], $rules);

        return DB::transaction(function () use ($user, $document, $expectedVersion): array {
            $profile = $this->initialize($user);
            $draft = $document;
            unset($draft['preferences'], $draft['restrictions']);
            $this->compareAndSwap($profile, $expectedVersion, ['draft' => $draft]);
            $this->writePreferences($user, $document['preferences'], $document['restrictions']);

            return $this->get($user);
        }, 3);
    }

    /**
     * @param  list<string>  $preferences
     * @param  list<string>  $rejections
     * @return array<string, mixed>
     */
    public function savePreferences(User $user, array $preferences, array $rejections, ?int $expectedVersion = null): array
    {
        if ($expectedVersion !== null) {
            $this->validateVersion($expectedVersion);
        }

        Validator::validate(compact('preferences', 'rejections'), [
            'preferences' => ['present', 'array', 'list', 'max:100'],
            'preferences.*' => ['string', 'max:100'],
            'rejections' => ['present', 'array', 'list', 'max:100'],
            'rejections.*' => ['string', 'max:100'],
        ]);

        return DB::transaction(function () use ($user, $preferences, $rejections, $expectedVersion): array {
            $profile = $this->initialize($user);
            $this->compareAndSwap($profile, $expectedVersion ?? $profile->version, []);
            $this->writePreferences($user, $preferences, $rejections);

            return $this->get($user);
        }, 3);
    }

    private function initialize(User $user): ProfessionalProfile
    {
        return ProfessionalProfile::query()->firstOrCreate(['user_id' => $user->id], [
            'version' => 0,
            'draft' => $this->emptyDraft(),
            'confirmed_version' => 0,
        ]);
    }

    private function validateVersion(int $version): void
    {
        Validator::validate(['expected_version' => $version], ['expected_version' => ['integer', 'min:0', 'max:2147483646']]);
    }

    /** @param array<string, mixed> $attributes */
    private function compareAndSwap(ProfessionalProfile $profile, int $expectedVersion, array $attributes): void
    {
        $profile->fill($attributes)->forceFill(['version' => $expectedVersion + 1, 'updated_at' => now()]);
        $attributes = array_intersect_key($profile->getAttributes(), array_flip([...array_keys($attributes), 'version', 'updated_at']));

        if (ProfessionalProfile::query()->whereKey($profile->id)->where('version', $expectedVersion)->update($attributes) !== 1) {
            throw new ProfessionalProfileConflictException;
        }
    }

    /**
     * @param  list<string>  $preferences
     * @param  list<string>  $rejections
     */
    private function writePreferences(User $user, array $preferences, array $rejections): void
    {
        $normalize = static fn (array $values): array => array_values(array_unique(array_filter(array_map(trim(...), $values), static fn (string $value): bool => $value !== '')));
        ScreeningPreference::query()->updateOrCreate(['user_id' => $user->id], [
            'preferences' => $normalize($preferences),
            'rejections' => $normalize($rejections),
        ]);
    }

    /** @return array{version: int, draft: array<string, mixed>, confirmed_version: int, confirmed_snapshot: ?array, confirmed_at: ?string, has_unconfirmed_changes: bool} */
    public function get(User $user): array
    {
        $profile = ProfessionalProfile::query()->where('user_id', $user->id)->first();
        $preferences = ScreeningPreference::query()->where('user_id', $user->id)->first();

        return [
            'version' => $profile?->version ?? 0,
            'draft' => array_merge($profile?->draft ?? $this->emptyDraft(), [
                'preferences' => $preferences?->preferences ?? [],
                'restrictions' => $preferences?->rejections ?? [],
            ]),
            'confirmed_version' => $profile?->confirmed_version ?? 0,
            'confirmed_snapshot' => $profile?->confirmed_snapshot,
            'confirmed_at' => $profile?->confirmed_at?->toISOString(),
            'has_unconfirmed_changes' => ($profile?->version ?? 0) !== ($profile?->confirmed_version ?? 0),
        ];
    }

    /** @return array<string, mixed>|null */
    public function confirmed(User $user): ?array
    {
        return ProfessionalProfile::query()->where('user_id', $user->id)->first()?->confirmed_snapshot;
    }

    /** @return array{experiences: array, education: array, competencies: array, compensation_expectation: null} */
    private function emptyDraft(): array
    {
        return ['experiences' => [], 'education' => [], 'competencies' => [], 'compensation_expectation' => null];
    }
}
