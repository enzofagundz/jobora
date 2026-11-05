<?php

namespace App\Services;

use App\DTO\ApplicationMethodData;
use App\DTO\PublicationTriageOutput;
use App\Enums\ApplicationMethodType;
use App\Enums\ConceptCategory;
use App\Enums\ItemSource;
use App\Models\ApplicationMethod;
use App\Models\JobPosting;
use App\Models\JobPostingItem;
use App\Models\OpportunityReviewRevision;
use App\Models\Publication;
use App\Models\PublicationObservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

final class OpportunityReviewService
{
    private const EDITABLE_FIELDS = [
        'role', 'company', 'description', 'location', 'work_mode', 'employment_type',
        'salary_min', 'salary_max', 'salary_currency', 'salary_period', 'seniority',
        'requirements', 'technologies', 'application_methods', 'source_url', 'evidence',
    ];

    private const ASSESSMENT_REASONS = [
        'missing_role', 'missing_publication_url', 'invalid_publication_url',
        'missing_evidence', 'missing_evidence_block', 'evidence_not_in_publication',
        'unknown_evidence_block', 'salary_range_inconsistent',
        'application_url_inconsistent', 'unsupported_field_evidence', 'publication_content_unavailable',
        'missing_publication_source',
    ];

    public function __construct(private readonly ConceptNormalizer $normalizer) {}

    /**
     * @param  array<string, mixed>  $corrections
     */
    public function review(
        User $actor,
        JobPosting $posting,
        int $expectedVersion,
        array $corrections,
        ?string $idempotencyKey = null,
    ): OpportunityReviewRevision {
        $validated = $this->validateCorrections($corrections);
        $fingerprint = hash('sha256', json_encode([
            'expected_version' => $expectedVersion,
            'corrections' => $validated,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $revisionKey = hash('sha256', $idempotencyKey ?? implode(':', [
            $actor->id,
            $posting->id,
            $expectedVersion,
            $fingerprint,
        ]));

        return DB::transaction(function () use ($actor, $posting, $expectedVersion, $validated, $fingerprint, $revisionKey): OpportunityReviewRevision {
            $current = JobPosting::query()->whereKey($posting->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('update', $current);

            if ($current->user_id !== $actor->id) {
                throw new LogicException('Oportunidade não pertence ao usuário.');
            }

            $existingRevision = OpportunityReviewRevision::query()
                ->where('job_posting_id', $current->id)
                ->where('idempotency_key', $revisionKey)
                ->first();

            if ($existingRevision !== null) {
                if ($existingRevision->actor_id !== $actor->id
                    || ! hash_equals($existingRevision->input_fingerprint, $fingerprint)) {
                    throw new OpportunityReviewConflict('Idempotency key was already used for another review.');
                }

                return $existingRevision->load('jobPosting');
            }

            if ($current->opportunity_version !== $expectedVersion) {
                throw new OpportunityReviewConflict(sprintf(
                    'Opportunity version conflict: expected %d, current %d.',
                    $expectedVersion,
                    $current->opportunity_version,
                ));
            }

            if (! $current->isStructuredOpportunity() || $current->readiness !== 'needs_review') {
                throw new LogicException('Somente oportunidades estruturadas em needs_review aceitam correção.');
            }

            $current->load(['items', 'applicationMethods', 'sources.publication']);
            $this->validateProvenanceCorrections($current, $validated);
            $before = $this->snapshot($current);
            $candidate = $this->candidate($current, $validated);
            $methods = $this->applicationMethodData($validated['application_methods'] ?? null);
            $reviewReasons = $this->assessReadiness($current, $candidate, $methods);
            $nextVersion = $current->opportunity_version + 1;

            $postingChanges = array_intersect_key($candidate, array_flip(self::EDITABLE_FIELDS));
            unset($postingChanges['application_methods']);
            $current->forceFill([
                ...$postingChanges,
                'readiness' => $reviewReasons === [] ? 'ready' : 'needs_review',
                'review_reasons' => $reviewReasons,
                'opportunity_version' => $nextVersion,
            ])->save();

            if (array_key_exists('requirements', $validated)) {
                $this->syncItems($current, ConceptCategory::Requirement, $candidate['requirements']);
            }

            if (array_key_exists('technologies', $validated)) {
                $this->syncItems($current, ConceptCategory::Skill, $candidate['technologies']);
            }

            if ($methods !== null) {
                $this->syncApplicationMethods($current, $methods);
            }

            $current->load(['items', 'applicationMethods', 'sources.publication']);
            $after = $this->snapshot($current);

            return OpportunityReviewRevision::query()->create([
                'job_posting_id' => $current->id,
                'actor_id' => $actor->id,
                'from_version' => $expectedVersion,
                'to_version' => $nextVersion,
                'idempotency_key' => $revisionKey,
                'input_fingerprint' => $fingerprint,
                'changes' => $validated,
                'before_snapshot' => $before,
                'after_snapshot' => $after,
            ])->load('jobPosting');
        });
    }

    /**
     * @param  array<string, mixed>  $corrections
     * @return array<string, mixed>
     */
    private function validateCorrections(array $corrections): array
    {
        $methodTypes = implode(',', array_map(static fn (ApplicationMethodType $type): string => $type->value, ApplicationMethodType::cases()));
        $evidenceFields = implode(',', PublicationTriageOutput::evidenceFields());
        $validated = Validator::make(['corrections' => $corrections], [
            'corrections' => ['required', 'array:'.implode(',', self::EDITABLE_FIELDS), 'min:1'],
            'corrections.role' => ['sometimes', 'nullable', 'string', 'max:255'],
            'corrections.company' => ['sometimes', 'nullable', 'string', 'max:255'],
            'corrections.description' => ['sometimes', 'nullable', 'string', 'max:100000'],
            'corrections.location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'corrections.work_mode' => ['sometimes', 'nullable', 'string', 'max:100'],
            'corrections.employment_type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'corrections.salary_min' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999999.99'],
            'corrections.salary_max' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999999.99'],
            'corrections.salary_currency' => ['sometimes', 'nullable', 'regex:/^[A-Z]{3}$/'],
            'corrections.salary_period' => ['sometimes', 'nullable', 'string', 'max:50'],
            'corrections.seniority' => ['sometimes', 'nullable', 'string', 'max:100'],
            'corrections.requirements' => ['sometimes', 'array', 'max:100'],
            'corrections.requirements.*' => ['required', 'string', 'max:100'],
            'corrections.technologies' => ['sometimes', 'array', 'max:100'],
            'corrections.technologies.*' => ['required', 'string', 'max:100'],
            'corrections.application_methods' => ['sometimes', 'array', 'max:20'],
            'corrections.source_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'corrections.evidence' => ['sometimes', 'array', 'min:1', 'max:50'],
            'corrections.evidence.*' => ['required', 'array:quote,block_ids,fields'],
            'corrections.evidence.*.quote' => ['required', 'string', 'max:2000'],
            'corrections.evidence.*.block_ids' => ['required', 'array', 'min:1', 'max:128'],
            'corrections.evidence.*.block_ids.*' => ['required', 'string', 'max:128'],
            'corrections.evidence.*.fields' => ['required', 'array', 'min:1', 'max:'.count(PublicationTriageOutput::evidenceFields())],
            'corrections.evidence.*.fields.*' => ['required', 'string', 'in:'.$evidenceFields],
            'corrections.application_methods.*' => ['required', 'array:type,platform,url,email,phone,instructions'],
            'corrections.application_methods.*.type' => ['required', 'string', 'in:'.$methodTypes],
            'corrections.application_methods.*.platform' => ['sometimes', 'nullable', 'string', 'max:100'],
            'corrections.application_methods.*.url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'corrections.application_methods.*.email' => ['sometimes', 'nullable', 'email', 'max:320'],
            'corrections.application_methods.*.phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'corrections.application_methods.*.instructions' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ])->validate();

        $validated = $validated['corrections'];

        if (array_key_exists('application_methods', $validated)) {
            foreach ($validated['application_methods'] as $index => $method) {
                try {
                    if (ApplicationMethodData::fromArray($method) === null) {
                        throw new InvalidArgumentException('Forma de candidatura precisa conter dados úteis.');
                    }
                } catch (InvalidArgumentException $exception) {
                    throw ValidationException::withMessages([
                        "corrections.application_methods.{$index}" => $exception->getMessage(),
                    ]);
                }
            }
        }

        ksort($validated);

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $corrections
     */
    private function validateProvenanceCorrections(JobPosting $posting, array $corrections): void
    {
        if (! array_key_exists('source_url', $corrections) && ! array_key_exists('evidence', $corrections)) {
            return;
        }

        $source = $posting->sources->first();

        if ($source === null || $source->publication === null) {
            $errors = [];

            foreach (['source_url', 'evidence'] as $field) {
                if (array_key_exists($field, $corrections)) {
                    $errors["corrections.{$field}"] = 'Não existe publicação persistida para verificar proveniência.';
                }
            }

            throw ValidationException::withMessages($errors);
        }

        $snapshot = $this->publicationSnapshot($source->publication, $source->publication_content_version);

        if (array_key_exists('source_url', $corrections) && $corrections['source_url'] !== null) {
            $url = $corrections['source_url'];
            $validUrl = filter_var($url, FILTER_VALIDATE_URL) !== false
                && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
            $matchesSnapshot = $url === $source->publication->url
                || ($snapshot['content'] !== null && str_contains($snapshot['content'], $url));

            if (! $validUrl || ! $matchesSnapshot) {
                throw ValidationException::withMessages([
                    'corrections.source_url' => 'A URL precisa ser válida e constar na publicação persistida.',
                ]);
            }
        }

        if (! array_key_exists('evidence', $corrections)) {
            return;
        }

        $content = $snapshot['content'];
        $blockIds = $snapshot['block_ids'];

        if ($content === null || $blockIds === []) {
            throw ValidationException::withMessages([
                'corrections.evidence' => 'O snapshot persistido não contém conteúdo e blocos verificáveis.',
            ]);
        }

        foreach ($corrections['evidence'] as $index => $evidence) {
            if (! str_contains(mb_strtolower($content), mb_strtolower($evidence['quote']))
                || array_diff($evidence['block_ids'], $blockIds) !== []) {
                throw ValidationException::withMessages([
                    "corrections.evidence.{$index}" => 'A citação e os blocos precisam existir no snapshot persistido.',
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $corrections
     * @return array<string, mixed>
     */
    private function candidate(JobPosting $posting, array $corrections): array
    {
        return [
            ...array_intersect_key($posting->only(self::EDITABLE_FIELDS), array_flip(self::EDITABLE_FIELDS)),
            ...$corrections,
            'application_methods' => $corrections['application_methods']
                ?? $posting->applicationMethods->map(fn (ApplicationMethod $method): array => [
                    'type' => $method->type->value,
                    'platform' => $method->platform,
                    'url' => $method->url,
                    'email' => $method->email,
                    'phone' => $method->phone,
                    'instructions' => $method->instructions,
                ])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<int, ApplicationMethodData>|null  $methods
     * @return array<int, string>
     */
    private function assessReadiness(JobPosting $posting, array $candidate, ?array $methods): array
    {
        $reasons = array_values(array_filter(
            $posting->review_reasons ?? [],
            static fn (string $reason): bool => ! in_array($reason, self::ASSESSMENT_REASONS, true),
        ));

        if (blank($candidate['role'] ?? null)) {
            $reasons[] = 'missing_role';
        }

        $sourceUrl = $candidate['source_url'] ?? null;

        if ($sourceUrl !== null && (filter_var($sourceUrl, FILTER_VALIDATE_URL) === false
            || ! in_array(strtolower((string) parse_url($sourceUrl, PHP_URL_SCHEME)), ['http', 'https'], true))) {
            $reasons[] = 'invalid_publication_url';
        }

        if (($candidate['salary_min'] ?? null) !== null && ($candidate['salary_max'] ?? null) !== null
            && $candidate['salary_min'] > $candidate['salary_max']) {
            $reasons[] = 'salary_range_inconsistent';
        }

        $methods ??= $posting->applicationMethods
            ->map(fn (ApplicationMethod $method): ?ApplicationMethodData => ApplicationMethodData::fromArray([
                'type' => $method->type->value,
                'platform' => $method->platform,
                'url' => $method->url,
                'email' => $method->email,
                'phone' => $method->phone,
                'instructions' => $method->instructions,
            ]))
            ->filter()
            ->values()
            ->all();

        foreach ($methods as $method) {
            if ($method->url !== null && (filter_var($method->url, FILTER_VALIDATE_URL) === false
                || ! in_array(strtolower((string) parse_url($method->url, PHP_URL_SCHEME)), ['http', 'https'], true))) {
                $reasons[] = 'application_url_inconsistent';
            }
        }

        if (PublicationTriageOutput::unsupportedFields($candidate) !== []) {
            $reasons[] = 'unsupported_field_evidence';
        }

        $source = $posting->sources->first();

        if ($source === null || $source->publication === null) {
            $reasons[] = 'missing_publication_source';
            $content = null;
            $blockIds = [];
        } else {
            $snapshot = $this->publicationSnapshot($source->publication, $source->publication_content_version);
            $content = $snapshot['content'];
            $blockIds = $snapshot['block_ids'];
        }

        $evidence = $candidate['evidence'] ?? [];

        if ($evidence === []) {
            $reasons[] = 'missing_evidence';
        }

        if ($content === null) {
            $reasons[] = 'publication_content_unavailable';
        } else {
            foreach ($evidence as $item) {
                $quote = $item['quote'] ?? '';
                $evidenceBlockIds = $item['block_ids'] ?? [];

                if ($quote === '' || ! str_contains(mb_strtolower($content), mb_strtolower($quote))) {
                    $reasons[] = 'evidence_not_in_publication';
                }

                if ($evidenceBlockIds === []) {
                    $reasons[] = 'missing_evidence_block';
                } elseif (array_diff($evidenceBlockIds, $blockIds) !== []) {
                    $reasons[] = 'unknown_evidence_block';
                }
            }
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @return array{content: string|null, block_ids: list<string>}
     */
    private function publicationSnapshot(Publication $publication, ?int $contentVersion): array
    {
        if ($contentVersion === null) {
            return ['content' => null, 'block_ids' => []];
        }

        $observation = $publication->observations()
            ->where('content_version', $contentVersion)
            ->latest('observed_at')
            ->first();

        if ($observation instanceof PublicationObservation
            && is_string($observation->content)
            && trim($observation->content) !== ''
            && is_string($observation->content_hash)
            && hash_equals($observation->content_hash, hash('sha256', $observation->content))) {
            return [
                'content' => $observation->content,
                'block_ids' => array_values(array_unique(array_filter(
                    $observation->evidence_block_ids ?? [],
                    'is_string',
                ))),
            ];
        }

        if ($publication->content_version === $contentVersion
            && is_string($publication->content)
            && trim($publication->content) !== ''
            && is_string($publication->content_hash)
            && hash_equals($publication->content_hash, hash('sha256', $publication->content))) {
            return ['content' => $publication->content, 'block_ids' => []];
        }

        return ['content' => null, 'block_ids' => []];
    }

    private function syncItems(JobPosting $posting, ConceptCategory $category, array $names): void
    {
        $existingSources = $posting->items()
            ->where('category', $category->value)
            ->get()
            ->mapWithKeys(fn (JobPostingItem $item): array => [
                $this->normalizeItem($category, $item->name) => $item->source,
            ]);

        $posting->items()->where('category', $category->value)->delete();

        foreach (array_unique($names) as $name) {
            $normalizedName = $this->normalizeItem($category, $name);

            if ($normalizedName === '') {
                continue;
            }

            $posting->items()->create([
                'category' => $category,
                'name' => $normalizedName,
                'source' => $existingSources[$normalizedName] ?? ItemSource::User,
            ]);
        }
    }

    private function normalizeItem(ConceptCategory $category, string $name): string
    {
        return $category->isFreeText()
            ? $this->normalizer->clean($name)
            : $this->normalizer->normalize($name);
    }

    /** @param array<int, ApplicationMethodData> $methods */
    private function syncApplicationMethods(JobPosting $posting, array $methods): void
    {
        $existingSources = $posting->applicationMethods
            ->mapWithKeys(function (ApplicationMethod $method): array {
                $data = ApplicationMethodData::fromArray([
                    'type' => $method->type->value,
                    'platform' => $method->platform,
                    'url' => $method->url,
                    'email' => $method->email,
                    'phone' => $method->phone,
                    'instructions' => $method->instructions,
                ]);

                return [$data?->dedupKey() ?? $method->dedup_key => $method->source];
            });
        $posting->applicationMethods()->delete();

        foreach ($methods as $method) {
            $posting->applicationMethods()->create([
                ...$method->toArray(),
                'source' => $existingSources[$method->dedupKey()] ?? ItemSource::User,
                'dedup_key' => $method->dedupKey(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(JobPosting $posting): array
    {
        return [
            'role' => $posting->role,
            'company' => $posting->company,
            'description' => $posting->description,
            'location' => $posting->location,
            'work_mode' => $posting->work_mode,
            'employment_type' => $posting->employment_type,
            'salary_min' => $posting->salary_min,
            'salary_max' => $posting->salary_max,
            'salary_currency' => $posting->salary_currency,
            'salary_period' => $posting->salary_period,
            'seniority' => $posting->seniority,
            'requirements' => $posting->requirements,
            'technologies' => $posting->technologies,
            'application_methods' => $posting->applicationMethods
                ->map(fn (ApplicationMethod $method): array => [
                    'type' => $method->type->value,
                    'platform' => $method->platform,
                    'url' => $method->url,
                    'email' => $method->email,
                    'phone' => $method->phone,
                    'instructions' => $method->instructions,
                    'source' => $method->source->value,
                ])->values()->all(),
            'evidence' => $posting->evidence,
            'source_url' => $posting->source_url,
            'readiness' => $posting->readiness,
            'review_reasons' => $posting->review_reasons,
            'opportunity_version' => $posting->opportunity_version,
        ];
    }

    /** @param array<string, mixed> $candidate */
    private function applicationMethodData(mixed $candidate): ?array
    {
        if ($candidate === null) {
            return null;
        }

        return array_map(static function (array $method): ApplicationMethodData {
            return ApplicationMethodData::fromArray($method)
                ?? throw new InvalidArgumentException('Forma de candidatura precisa conter dados úteis.');
        }, $candidate);
    }
}
