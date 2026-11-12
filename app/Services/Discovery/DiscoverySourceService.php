<?php

namespace App\Services\Discovery;

use App\DTO\SearchCriteria;
use App\Enums\DiscoverySourceSurface;
use App\Enums\SearchOperator;
use App\Enums\SearchPlatform;
use App\Enums\SearchSeniority;
use App\Enums\SearchWorkMode;
use App\Models\DiscoverySource;
use App\Models\User;
use App\Services\Search\SearchUrlGenerator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class DiscoverySourceService
{
    private const CRITERIA_RULES = [
        'criteria' => ['nullable', 'array'],
        'criteria.keywords' => ['nullable', 'array', 'min:1', 'max:20'],
        'criteria.keywords.*' => ['string', 'max:100'],
        'criteria.excluded_terms' => ['nullable', 'array', 'max:20'],
        'criteria.excluded_terms.*' => ['string', 'max:100'],
        'criteria.operator' => ['nullable', 'in:all,any,and,or'],
        'criteria.seniority' => ['nullable', 'in:internship,junior,mid_level,senior'],
        'criteria.location' => ['nullable', 'string', 'max:255'],
        'criteria.work_mode' => ['nullable', 'in:any,remote,hybrid,onsite'],
        'criteria.published_within_days' => ['nullable', 'integer', 'in:1,3,7,14,30'],
        'criteria.easy_apply' => ['nullable', 'boolean'],
    ];

    public function __construct(private readonly SearchUrlGenerator $generator) {}

    public function paginate(User $user, array $filters = [], int $limit = 20, int $offset = 0): LengthAwarePaginator
    {
        $limit = max(1, min($limit, 100));
        $offset = max(0, $offset);

        $query = DiscoverySource::query()
            ->where('user_id', $user->id)
            ->when(array_key_exists('active', $filters) && $filters['active'] !== null, function ($query) use ($filters): void {
                $query->where('active', (bool) $filters['active']);
            })
            ->when(! empty($filters['source']), function ($query) use ($filters): void {
                $query->where('source', (string) $filters['source']);
            })
            ->orderByDesc('id');

        return $this->paginateQuery($query, $limit, $offset);
    }

    public function get(User $user, int $id): DiscoverySource
    {
        $source = DiscoverySource::query()
            ->where('user_id', $user->id)
            ->whereKey($id)
            ->first();

        if ($source === null) {
            throw (new ModelNotFoundException)->setModel(DiscoverySource::class, [$id]);
        }

        return $source;
    }

    public function create(User $user, array $data): DiscoverySource
    {
        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:120'],
            'source' => ['required', 'in:linkedin,indeed'],
            'surface' => ['required', 'in:linkedin_jobs,linkedin_publications,indeed_jobs'],
            'purpose' => ['nullable', 'string', 'max:500'],
            'frequency_minutes' => ['required', 'integer', 'min:5', 'max:10080'],
            'active' => ['nullable', 'boolean'],
            'next_due_at' => ['nullable', 'date'],
            'url' => ['nullable', 'string', 'max:2000'],
            ...self::CRITERIA_RULES,
        ])->validate();

        $platform = SearchPlatform::from($validated['source']);
        $surface = DiscoverySourceSurface::from($validated['surface']);
        $this->assertSurface($platform, $surface);

        $criteria = $this->criteria($validated['criteria'] ?? null);
        $url = $this->resolveUrl($platform, $surface, $criteria, $validated['url'] ?? null);
        $hash = app(DiscoverySourceUrl::class)->hash($url);

        $unique = DiscoverySource::query()
            ->where('user_id', $user->id)
            ->where('canonical_url_hash', $hash)
            ->exists();

        if ($unique) {
            throw ValidationException::withMessages([
                'url' => 'Já existe uma fonte com esta URL para o usuário.',
            ]);
        }

        return $this->guardUnique(fn (): DiscoverySource => DB::transaction(fn (): DiscoverySource => DiscoverySource::create([
            'user_id' => $user->id,
            'name' => $validated['name'],
            'source' => $platform->value,
            'surface' => $surface->value,
            'url' => $url,
            'canonical_url_hash' => $hash,
            'purpose' => $validated['purpose'] ?? null,
            'criteria' => $criteria,
            'frequency_minutes' => $validated['frequency_minutes'],
            'active' => (bool) ($validated['active'] ?? true),
            'version' => 1,
            'next_due_at' => $validated['next_due_at'] ?? now(),
        ])));
    }

    public function update(User $user, int $id, int $expectedVersion, array $data): DiscoverySource
    {
        $validated = Validator::make($data, [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'source' => ['sometimes', 'required', 'in:linkedin,indeed'],
            'surface' => ['sometimes', 'required', 'in:linkedin_jobs,linkedin_publications,indeed_jobs'],
            'purpose' => ['sometimes', 'nullable', 'string', 'max:500'],
            'frequency_minutes' => ['sometimes', 'required', 'integer', 'min:5', 'max:10080'],
            'active' => ['sometimes', 'boolean'],
            'next_due_at' => ['sometimes', 'nullable', 'date'],
            'url' => ['sometimes', 'nullable', 'string', 'max:2000'],
            ...self::CRITERIA_RULES,
        ])->validate();

        return $this->guardUnique(fn (): DiscoverySource => DB::transaction(function () use ($user, $id, $expectedVersion, $validated): DiscoverySource {
            $source = DiscoverySource::query()
                ->where('user_id', $user->id)
                ->whereKey($id)
                ->lockForUpdate()
                ->first();

            if ($source === null) {
                throw (new ModelNotFoundException)->setModel(DiscoverySource::class, [$id]);
            }

            if ($source->version !== $expectedVersion) {
                throw new DiscoverySourceConflictException;
            }

            $platform = SearchPlatform::from($validated['source'] ?? $source->source);
            $surface = DiscoverySourceSurface::from($validated['surface'] ?? $source->surface);
            $this->assertSurface($platform, $surface);

            $criteria = array_key_exists('criteria', $validated)
                ? $this->criteria($this->mergeCriteria($source->criteria, $validated['criteria']))
                : $source->criteria;

            $url = $this->resolveUrl(
                $platform,
                $surface,
                $criteria,
                $validated['url'] ?? null,
                $source->url,
            );

            $hash = app(DiscoverySourceUrl::class)->hash($url);

            $unique = DiscoverySource::query()
                ->where('user_id', $user->id)
                ->where('canonical_url_hash', $hash)
                ->whereKeyNot($source->id)
                ->exists();

            if ($unique) {
                throw ValidationException::withMessages([
                    'url' => 'Já existe uma fonte com esta URL para o usuário.',
                ]);
            }

            $active = array_key_exists('active', $validated) ? (bool) $validated['active'] : $source->active;
            $nextDueAt = array_key_exists('next_due_at', $validated)
                ? $validated['next_due_at']
                : ($active && ! $source->active ? now() : $source->next_due_at);

            $source->fill([
                'name' => $validated['name'] ?? $source->name,
                'source' => $platform->value,
                'surface' => $surface->value,
                'url' => $url,
                'canonical_url_hash' => $hash,
                'purpose' => array_key_exists('purpose', $validated) ? $validated['purpose'] : $source->purpose,
                'criteria' => $criteria,
                'frequency_minutes' => $validated['frequency_minutes'] ?? $source->frequency_minutes,
                'active' => $active,
                'next_due_at' => $nextDueAt,
            ]);
            $source->version = $source->version + 1;
            $source->save();

            return $source->refresh();
        }));
    }

    public function deactivate(User $user, int $id, int $expectedVersion): DiscoverySource
    {
        return $this->update($user, $id, $expectedVersion, ['active' => false, 'next_due_at' => null]);
    }

    public function delete(User $user, int $id, int $expectedVersion): DiscoverySource
    {
        return DB::transaction(function () use ($user, $id, $expectedVersion): DiscoverySource {
            $source = DiscoverySource::query()
                ->where('user_id', $user->id)
                ->whereKey($id)
                ->lockForUpdate()
                ->first();

            if ($source === null) {
                throw (new ModelNotFoundException)->setModel(DiscoverySource::class, [$id]);
            }

            if ($source->version !== $expectedVersion) {
                throw new DiscoverySourceConflictException;
            }

            $source->forceFill([
                'active' => false,
                'next_due_at' => null,
                'canonical_url_hash' => null,
                'version' => $source->version + 1,
            ])->save();
            $source->delete();

            return $source;
        });
    }

    private function assertSurface(SearchPlatform $platform, DiscoverySourceSurface $surface): void
    {
        if ($surface->platform() !== $platform) {
            throw new InvalidArgumentException('A superfície informada não pertence à plataforma.');
        }
    }

    private function resolveUrl(
        SearchPlatform $platform,
        DiscoverySourceSurface $surface,
        ?array $criteria,
        ?string $url,
        ?string $existingUrl = null,
    ): string {
        $urls = app(DiscoverySourceUrl::class);

        if ($criteria !== null) {
            try {
                $generated = $this->generator->build($platform, $this->toCriteria($criteria), $surface->mode());
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['criteria' => $exception->getMessage()]);
            }

            if ($url !== null && $urls->hash($url) !== $urls->hash($generated)) {
                throw new InvalidArgumentException('A URL informada não corresponde aos critérios da fonte.');
            }

            $urls->validate($platform, $surface, $generated);

            return $generated;
        }

        $resolved = $url ?? $existingUrl;

        if ($resolved === null) {
            throw new InvalidArgumentException('Informe a URL ou os critérios da busca.');
        }

        $urls->validate($platform, $surface, $resolved);

        return $resolved;
    }

    /**
     * @param  array<string, mixed>|null  $input
     * @return array<string, mixed>|null
     */
    private function criteria(?array $input): ?array
    {
        if ($input === null) {
            return null;
        }

        try {
            $criteria = SearchCriteria::fromInput(
                keywords: $input['keywords'] ?? [],
                excludedTerms: $input['excluded_terms'] ?? [],
                operator: SearchOperator::fromInput($input['operator'] ?? null),
                seniority: isset($input['seniority']) ? SearchSeniority::tryFrom((string) $input['seniority']) : null,
                location: $input['location'] ?? null,
                workMode: SearchWorkMode::tryFrom((string) ($input['work_mode'] ?? 'any')) ?? SearchWorkMode::Any,
                publishedWithinDays: isset($input['published_within_days']) ? (int) $input['published_within_days'] : null,
                easyApply: (bool) ($input['easy_apply'] ?? false),
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['criteria' => $exception->getMessage()]);
        }

        return [
            'keywords' => $criteria->keywords,
            'excluded_terms' => $criteria->excludedTerms,
            'operator' => $criteria->operator->value,
            'seniority' => $criteria->seniority?->value,
            'location' => $criteria->location,
            'work_mode' => $criteria->workMode->value,
            'published_within_days' => $criteria->publishedWithinDays,
            'easy_apply' => $criteria->easyApply,
        ];
    }

    /**
     * @param  array<string, mixed>  $criteria
     */
    private function toCriteria(array $criteria): SearchCriteria
    {
        return SearchCriteria::fromInput(
            keywords: $criteria['keywords'] ?? [],
            excludedTerms: $criteria['excluded_terms'] ?? [],
            operator: SearchOperator::fromInput($criteria['operator'] ?? null),
            seniority: isset($criteria['seniority']) ? SearchSeniority::tryFrom((string) $criteria['seniority']) : null,
            location: $criteria['location'] ?? null,
            workMode: SearchWorkMode::tryFrom((string) ($criteria['work_mode'] ?? 'any')) ?? SearchWorkMode::Any,
            publishedWithinDays: isset($criteria['published_within_days']) ? (int) $criteria['published_within_days'] : null,
            easyApply: (bool) ($criteria['easy_apply'] ?? false),
        );
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function guardUnique(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages([
                'url' => 'Já existe uma fonte com esta URL para o usuário.',
            ])->setPrevious($exception);
        }
    }

    /**
     * @param  array<string, mixed>|null  $existing
     * @param  array<string, mixed>|null  $input
     * @return array<string, mixed>|null
     */
    private function mergeCriteria(?array $existing, ?array $input): ?array
    {
        if ($input === null) {
            return null;
        }

        return array_merge($existing ?? [], $input);
    }

    /**
     * @return LengthAwarePaginator<int, DiscoverySource>
     */
    private function paginateQuery(Builder $query, int $limit, int $offset): LengthAwarePaginator
    {
        $total = (clone $query)->count();
        $items = $query->offset($offset)->limit($limit)->get();

        return new Paginator($items, $total, $limit, intdiv($offset, $limit) + 1);
    }
}
