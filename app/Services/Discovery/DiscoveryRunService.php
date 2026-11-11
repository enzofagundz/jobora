<?php

namespace App\Services\Discovery;

use App\Enums\DiscoveryAccessOutcome;
use App\Enums\DiscoveryRunStatus;
use App\Models\AgentIntegration;
use App\Models\DiscoveryRun;
use App\Models\DiscoveryRunEvent;
use App\Models\DiscoverySource;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DiscoveryRunService
{
    public function acquire(
        User $user,
        ?AgentIntegration $integration,
        string $idempotencyKey,
        int $limit = 1,
        ?CarbonInterface $now = null,
    ): Collection {
        $now ??= now();
        $limit = max(1, min($limit, (int) config('discovery.acquire_limit_max', 10)));

        $batch = $this->findBatch($user, $idempotencyKey);

        if ($batch !== null) {
            return $batch;
        }

        try {
            return DB::transaction(function () use ($user, $integration, $idempotencyKey, $limit, $now): Collection {
                $acquisitionId = DB::table('discovery_acquisitions')->insertGetId([
                    'user_id' => $user->id,
                    'idempotency_key' => $idempotencyKey,
                    'run_ids' => json_encode([]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $this->expireDue($now);

                $sources = $this->eligibleSourcesQuery($user, $now)
                    ->orderBy('next_due_at')
                    ->orderBy('id')
                    ->limit($limit)
                    ->lockForUpdate()
                    ->get();

                $runs = collect();

                foreach ($sources as $source) {
                    try {
                        $runs->push(DB::transaction(fn (): DiscoveryRun => $this->reserve(
                            $user,
                            $integration,
                            $source,
                            $this->runKey($idempotencyKey, $source),
                            $now,
                        )));
                    } catch (UniqueConstraintViolationException) {
                        continue;
                    }
                }

                DB::table('discovery_acquisitions')->where('id', $acquisitionId)->update([
                    'run_ids' => json_encode($runs->pluck('id')->all()),
                    'updated_at' => $now,
                ]);

                return $runs;
            });
        } catch (UniqueConstraintViolationException) {
            $replayed = $this->findBatch($user, $idempotencyKey);

            if ($replayed !== null) {
                return $replayed;
            }

            throw new DiscoveryRunConflictException('Aquisição concorrente em conflito.');
        }
    }

    public function pending(User $user, int $limit = 20, int $offset = 0, ?CarbonInterface $now = null): LengthAwarePaginator
    {
        $now ??= now();
        $limit = max(1, min($limit, 100));

        $query = $this->eligibleSourcesQuery($user, $now)
            ->orderBy('next_due_at')
            ->orderBy('id');

        return $this->paginateQuery($query, $limit, max(0, $offset));
    }

    public function paginateRuns(User $user, array $filters = [], int $limit = 20, int $offset = 0): LengthAwarePaginator
    {
        $limit = max(1, min($limit, 100));

        $query = DiscoveryRun::query()
            ->where('user_id', $user->id)
            ->when(! empty($filters['status']), fn ($query) => $query->where('status', (string) $filters['status']))
            ->orderByDesc('id');

        return $this->paginateQuery($query, $limit, max(0, $offset));
    }

    public function get(User $user, int|string $id): DiscoveryRun
    {
        $query = DiscoveryRun::query()->where('user_id', $user->id);

        $run = is_int($id)
            ? $query->whereKey($id)->first()
            : $query->where('execution_id', (string) $id)->first();

        if ($run === null) {
            throw (new ModelNotFoundException)->setModel(DiscoveryRun::class, [$id]);
        }

        return $run;
    }

    public function reportAccess(User $user, string $executionId, array $data, ?CarbonInterface $now = null): DiscoveryRunEvent
    {
        $now ??= now();

        $validated = Validator::make($data, [
            'event_key' => ['required', 'string', 'max:200'],
            'outcome' => ['required', 'in:success,failure'],
            'final_url' => ['nullable', 'string', 'max:2000'],
            'http_status' => ['nullable', 'integer', 'min:100', 'max:599'],
            'redirects' => ['nullable', 'array', 'max:20'],
            'redirects.*' => ['string', 'max:2000'],
            'details' => ['nullable', 'array'],
            'error' => ['nullable', 'string', 'max:500'],
            'occurred_at' => ['nullable', 'date'],
        ])->validate();

        $outcome = DiscoveryAccessOutcome::from($validated['outcome']);
        $this->assertCoherentPayload($outcome, $validated);

        $expiredConflict = false;

        $event = DB::transaction(function () use ($user, $executionId, $validated, $outcome, $now, &$expiredConflict): ?DiscoveryRunEvent {
            $run = DiscoveryRun::query()
                ->where('user_id', $user->id)
                ->where('execution_id', $executionId)
                ->lockForUpdate()
                ->first();

            if ($run === null) {
                throw (new ModelNotFoundException)->setModel(DiscoveryRun::class, [$executionId]);
            }

            if ($run->status !== DiscoveryRunStatus::Active || $run->expires_at->lessThanOrEqualTo($now)) {
                if ($run->status === DiscoveryRunStatus::Active) {
                    $run->forceFill([
                        'status' => DiscoveryRunStatus::Expired,
                        'active_key' => null,
                    ])->save();
                }

                $expiredConflict = true;

                return null;
            }

            $existing = DiscoveryRunEvent::query()
                ->where('discovery_run_id', $run->id)
                ->where('event_key', $validated['event_key'])
                ->first();

            if ($existing !== null) {
                if (! $this->samePayload($existing, $validated, $outcome)) {
                    throw new DiscoveryRunConflictException('A chave do evento já foi usada com outro conteúdo.');
                }

                return $existing;
            }

            try {
                $event = DB::transaction(fn (): DiscoveryRunEvent => DiscoveryRunEvent::create([
                    'discovery_run_id' => $run->id,
                    'event_key' => $validated['event_key'],
                    'outcome' => $outcome,
                    'final_url' => $validated['final_url'] ?? null,
                    'http_status' => $validated['http_status'] ?? null,
                    'redirects' => $validated['redirects'] ?? [],
                    'details' => $validated['details'] ?? null,
                    'error' => $validated['error'] ?? null,
                    'occurred_at' => $validated['occurred_at'] ?? $now,
                ]));
            } catch (UniqueConstraintViolationException) {
                $existing = DiscoveryRunEvent::query()
                    ->where('discovery_run_id', $run->id)
                    ->where('event_key', $validated['event_key'])
                    ->first();

                if ($existing !== null && $this->samePayload($existing, $validated, $outcome)) {
                    return $existing;
                }

                throw new DiscoveryRunConflictException('A chave do evento já foi usada.');
            }

            $run->forceFill(['last_access_at' => $event->occurred_at])->save();

            return $event;
        });

        if ($expiredConflict || $event === null) {
            throw new DiscoveryRunConflictException('A execução expirou; um callback antigo não altera a execução atual.');
        }

        return $event;
    }

    public function resolveForExtension(User $user, string $url, ?CarbonInterface $now = null): DiscoveryRun
    {
        $now ??= now();

        $canonical = app(DiscoverySourceUrl::class)->canonical($url);
        $hash = hash('sha256', $canonical);

        $source = DiscoverySource::query()
            ->where('user_id', $user->id)
            ->where('canonical_url_hash', $hash)
            ->first();

        if ($source === null) {
            throw (new ModelNotFoundException)->setModel(DiscoverySource::class);
        }

        $runs = DiscoveryRun::query()
            ->where('user_id', $user->id)
            ->where('discovery_source_id', $source->id)
            ->where('status', DiscoveryRunStatus::Active)
            ->where('expires_at', '>', $now)
            ->orderBy('id')
            ->get();

        if ($runs->isEmpty()) {
            throw (new ModelNotFoundException)->setModel(DiscoveryRun::class);
        }

        if ($runs->count() > 1) {
            throw new DiscoveryRunConflictException('Mais de uma execução ativa para a fonte.');
        }

        return $runs->first();
    }

    public function expireDue(?CarbonInterface $now = null): int
    {
        return DiscoveryRun::query()
            ->where('status', DiscoveryRunStatus::Active)
            ->where('expires_at', '<=', $now ?? now())
            ->update([
                'status' => DiscoveryRunStatus::Expired,
                'active_key' => null,
            ]);
    }

    private function reserve(User $user, ?AgentIntegration $integration, DiscoverySource $source, string $idempotencyKey, CarbonInterface $now): DiscoveryRun
    {
        $run = DiscoveryRun::create([
            'user_id' => $user->id,
            'discovery_source_id' => $source->id,
            'agent_integration_id' => $integration?->id,
            'execution_id' => (string) Str::uuid(),
            'idempotency_key' => $idempotencyKey,
            'status' => DiscoveryRunStatus::Active,
            'active_key' => 'source:'.$source->id.':user:'.$user->id,
            'canonical_url_hash' => $source->canonical_url_hash,
            'initial_url' => $source->url,
            'surface' => $source->surface,
            'reserved_at' => $now,
            'expires_at' => $now->copy()->addMinutes((int) config('discovery.reservation_ttl_minutes', 30)),
        ]);

        $source->forceFill(['last_attempt_at' => $now])->save();

        return $run;
    }

    private function findBatch(User $user, string $key): ?Collection
    {
        $acquisition = DB::table('discovery_acquisitions')
            ->where('user_id', $user->id)
            ->where('idempotency_key', $key)
            ->first();

        if ($acquisition !== null) {
            $ids = json_decode((string) ($acquisition->run_ids ?? '[]'), true) ?: [];

            if ($ids === []) {
                return collect();
            }

            $runs = DiscoveryRun::query()->whereIn('id', $ids)->get()->keyBy('id');

            return collect($ids)->map(fn (int $id): ?DiscoveryRun => $runs->get($id))->filter()->values();
        }

        $legacy = DiscoveryRun::query()
            ->where('user_id', $user->id)
            ->where('idempotency_key', $key)
            ->first();

        return $legacy !== null ? collect([$legacy]) : null;
    }

    private function runKey(string $batchKey, DiscoverySource $source): string
    {
        return substr(hash('sha256', $batchKey), 0, 40).'#'.$source->id;
    }

    private function eligibleSourcesQuery(User $user, CarbonInterface $now): Builder
    {
        return DiscoverySource::query()
            ->where('user_id', $user->id)
            ->where('active', true)
            ->where('next_due_at', '<=', $now)
            ->whereNotExists(function ($query) use ($now, $user): void {
                $query->select(DB::raw(1))
                    ->from('discovery_runs')
                    ->whereColumn('discovery_runs.discovery_source_id', 'discovery_sources.id')
                    ->where('discovery_runs.user_id', $user->id)
                    ->where('discovery_runs.status', DiscoveryRunStatus::Active->value)
                    ->where('discovery_runs.expires_at', '>', $now);
            });
    }

    private function assertCoherentPayload(DiscoveryAccessOutcome $outcome, array $validated): void
    {
        if ($outcome === DiscoveryAccessOutcome::Success && empty($validated['final_url'])) {
            throw ValidationException::withMessages(['final_url' => 'Acesso com sucesso exige a URL final.']);
        }

        foreach ($validated['redirects'] ?? [] as $redirect) {
            $parts = parse_url($redirect);

            if ($parts === false || ! isset($parts['scheme'], $parts['host']) || ! in_array(mb_strtolower($parts['scheme']), ['http', 'https'], true)) {
                throw ValidationException::withMessages(['redirects' => 'Redirect inválido.']);
            }
        }
    }

    private function samePayload(DiscoveryRunEvent $event, array $validated, DiscoveryAccessOutcome $outcome): bool
    {
        $matches = $event->outcome === $outcome
            && $event->final_url === ($validated['final_url'] ?? null)
            && $event->http_status === ($validated['http_status'] ?? null)
            && ($event->redirects ?? []) === ($validated['redirects'] ?? [])
            && $event->details == ($validated['details'] ?? null)
            && $event->error === ($validated['error'] ?? null);

        if (! array_key_exists('occurred_at', $validated)) {
            return $matches;
        }

        return $matches && $event->occurred_at->equalTo(Carbon::parse((string) $validated['occurred_at']));
    }

    /**
     * @return LengthAwarePaginator<int, mixed>
     */
    private function paginateQuery(Builder $query, int $limit, int $offset): LengthAwarePaginator
    {
        $total = (clone $query)->count();
        $items = $query->offset($offset)->limit($limit)->get();

        return new Paginator($items, $total, $limit, intdiv($offset, $limit) + 1);
    }
}
