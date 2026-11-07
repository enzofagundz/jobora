<?php

namespace App\Services\Discovery;

use App\Enums\DiscoveryRunStatus;
use App\Enums\DiscoverySourceSurface;
use App\Models\DiscoveryRun;
use App\Models\DiscoverySource;
use App\Models\PageCapture;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CaptureIngestionService
{
    public function __construct(
        private readonly CaptureValidator $validator,
        private readonly CaptureSanitizer $sanitizer,
        private readonly PublicationExtractionService $extractions,
    ) {}

    /**
     * @return array{0: PageCapture, 1: bool}
     */
    public function ingest(User $user, array $payload, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $this->assertPayloadSize($payload);
        $this->validator->validate($payload);

        $capture = $payload['capture'];
        $hash = $this->hash($payload);

        $existing = PageCapture::query()
            ->where('user_id', $user->id)
            ->where('idempotency_key', $capture['idempotency_key'])
            ->first();

        if ($existing !== null) {
            return $this->replay($existing, $hash);
        }

        $run = $this->resolveRun($user, $capture, $now);

        $sanitized = $this->sanitizer->sanitize((string) $capture['html'], $payload['blocks'], $capture['surface'], (int) $payload['version']);

        $path = $this->storeObject($user, $sanitized);

        try {
            $pageCapture = DB::transaction(function () use ($user, $payload, $capture, $hash, $path, $run, $now): PageCapture {
                User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                $pageCapture = PageCapture::create([
                    'user_id' => $user->id,
                    'source' => $capture['source'],
                    'surface' => $capture['surface'],
                    'url' => $capture['initial_url'],
                    'html_path' => $path,
                    'captured_at' => $capture['captured_at'],
                    'execution_id' => $capture['execution_id'],
                    'idempotency_key' => $capture['idempotency_key'],
                    'payload_hash' => $hash,
                    'format_version' => $payload['version'],
                    'ingest_status' => 'received',
                    'coverage_status' => $capture['coverage']['status'],
                    'coverage_reason' => $capture['coverage']['reason'],
                    'blocks_expected' => $capture['coverage']['blocks_expected'],
                    'blocks_captured' => $capture['coverage']['blocks_captured'],
                    'raw_expires_at' => $now->copy()->addDays((int) config('capture.ingestion.raw_retention_days', 30)),
                ]);

                if ($run !== null) {
                    $this->completeRun($run, $pageCapture, $capture['coverage'], $now);
                }

                return $pageCapture;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $this->deleteObject($path);
            $existing = PageCapture::query()
                ->where('user_id', $user->id)
                ->where('idempotency_key', $capture['idempotency_key'])
                ->first();

            if ($existing !== null) {
                return $this->replay($existing, $hash);
            }

            throw new CaptureConflictException('Captura concorrente em conflito.', 0, $exception);
        } catch (CaptureConflictException $exception) {
            $this->deleteObject($path);
            throw $exception;
        }

        $this->extractions->requestSafely($pageCapture);

        return [$pageCapture, true];
    }

    /**
     * @return array{0: PageCapture, 1: bool}
     */
    private function replay(PageCapture $existing, string $hash): array
    {
        if ($existing->payload_hash !== $hash) {
            throw new CaptureConflictException('A mesma chave idempotente foi usada com outro payload.');
        }

        return [$existing, false];
    }

    private function resolveRun(User $user, array $capture, CarbonInterface $now): ?DiscoveryRun
    {
        if (($capture['execution_id'] ?? null) === null) {
            return null;
        }

        $run = DiscoveryRun::query()
            ->where('user_id', $user->id)
            ->where('execution_id', $capture['execution_id'])
            ->first();

        if ($run === null
            || $run->status !== DiscoveryRunStatus::Active
            || $run->expires_at->lessThanOrEqualTo($now)
            || $run->page_capture_id !== null
            || $run->surface !== $capture['surface']
            || DiscoverySourceSurface::from($run->surface)->platform()->value !== $capture['source']
            || ($run->surface === DiscoverySourceSurface::LinkedInJobs->value
                ? ! app(DiscoverySourceUrl::class)->matchesObservation(DiscoverySourceSurface::LinkedInJobs, (string) $capture['initial_url'], $run->initial_url)
                : $run->canonical_url_hash !== app(DiscoverySourceUrl::class)->hash((string) $capture['initial_url']))) {
            throw new CaptureConflictException('Execução inexistente, expirada, ambígua ou de outro usuário.');
        }

        return $run;
    }

    /** @param array{status: string, reason: ?string, blocks_expected: ?int, blocks_captured: int} $coverage */
    private function completeRun(DiscoveryRun $run, PageCapture $pageCapture, array $coverage, CarbonInterface $now): void
    {
        User::query()->whereKey($run->user_id)->lockForUpdate()->firstOrFail();
        $source = DiscoverySource::query()->where('user_id', $run->user_id)->whereKey($run->discovery_source_id)->lockForUpdate()->first();
        $locked = DiscoveryRun::query()->whereKey($run->id)->lockForUpdate()->first();

        if ($locked === null
            || $locked->status !== DiscoveryRunStatus::Active
            || $locked->expires_at->lessThanOrEqualTo($now)
            || $locked->page_capture_id !== null) {
            throw new CaptureConflictException('Execução não está mais disponível para conclusão.');
        }

        $locked->forceFill([
            'status' => DiscoveryRunStatus::Completed,
            'completion_status' => $coverage['status'],
            'page_capture_id' => $pageCapture->id,
            'completed_at' => $now,
            'active_key' => null,
        ])->save();

        if ($source === null || ! $source->active || $source->next_due_at === null || $source->canonical_url_hash !== $locked->canonical_url_hash || $source->surface !== $locked->surface) {
            return;
        }

        $empty = $coverage['status'] === 'partial' && $coverage['reason'] === 'no_content' && $coverage['blocks_captured'] === 0;
        $nextDue = $empty ? $locked->expires_at : $now->copy()->addMinutes($source->frequency_minutes);
        $source->forceFill(['next_due_at' => $source->next_due_at->max($nextDue)]);

        if ($coverage['status'] === 'complete') {
            $source->forceFill(['last_success_at' => $now]);
        }

        $source->save();
    }

    private function storeObject(User $user, string $html): string
    {
        $disk = Storage::disk((string) config('capture.ingestion.disk', 'local'));
        $path = trim((string) config('capture.ingestion.path', 'capture-envelopes'), '/').'/'.$user->id.'/'.Str::uuid().'.html';

        try {
            $stored = $disk->put($path, $html);
        } catch (\Throwable $exception) {
            throw new CaptureStorageException('Falha ao gravar o HTML privado.', 0, $exception);
        }

        if (! $stored) {
            throw new CaptureStorageException('Falha ao gravar o HTML privado.');
        }

        return $path;
    }

    private function deleteObject(string $path): void
    {
        Storage::disk((string) config('capture.ingestion.disk', 'local'))->delete($path);
    }

    private function assertPayloadSize(array $payload): void
    {
        $encoded = json_encode($payload);

        if ($encoded === false || strlen($encoded) > (int) config('capture.ingestion.payload_max_bytes', 12_582_912)) {
            throw new CaptureTooLargeException('Payload excede o limite configurado.');
        }
    }

    private function hash(array $payload): string
    {
        return hash('sha256', json_encode($this->canonicalize($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
