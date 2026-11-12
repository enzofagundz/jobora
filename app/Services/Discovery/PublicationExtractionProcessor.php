<?php

namespace App\Services\Discovery;

use App\Jobs\ClassifyPublicationWithClef;
use App\Models\DiscoveryRun;
use App\Models\PageCapture;
use App\Models\Publication;
use App\Models\PublicationClefResult;
use App\Models\PublicationObservation;
use App\Models\User;
use App\Services\Triage\JobPrefilter;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Facades\Storage;

class PublicationExtractionProcessor
{
    public function __construct(
        private readonly CaptureBlockReader $reader,
        private readonly LinkedInPublicationsDecoder $publications,
        private readonly LinkedInJobsDecoder $jobs,
        private readonly IndeedJobsDecoder $indeedJobs,
        private readonly PublicationIdentityResolver $identities,
        private readonly ObservationRecorder $observations,
        private readonly JobPrefilter $prefilter,
    ) {}

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  Closure(Closure(): mixed): mixed  $persist
     */
    public function extractFromSnapshot(User $user, array $snapshot, Closure $persist): void
    {
        $captureId = (int) ($snapshot['page_capture_id'] ?? 0);
        $htmlPath = $snapshot['html_path'] ?? null;
        $surface = $snapshot['surface'] ?? null;
        $version = (int) ($snapshot['format_version'] ?? 0);

        if ($captureId <= 0 || ! is_string($htmlPath) || $htmlPath === '') {
            throw PublicationExtractionException::processingFailed('Snapshot de captura incompleto.');
        }

        $capture = PageCapture::query()
            ->where('user_id', $user->id)
            ->whereKey($captureId)
            ->first();

        if ($capture === null || $capture->deleted_at !== null) {
            throw PublicationExtractionException::rawUnavailable();
        }

        if ($surface !== null && ! is_string($surface)) {
            throw PublicationExtractionException::parseFailed('Superfície da captura inválida.');
        }

        $surface = (string) $surface;
        $decoder = $this->decoderFor($surface);

        if ($version !== 1) {
            throw PublicationExtractionException::parseFailed('Versão da captura não suportada.');
        }

        $disk = Storage::disk((string) config('capture.ingestion.disk', 'local'));

        if (! $disk->exists($htmlPath)) {
            throw PublicationExtractionException::rawUnavailable();
        }

        $html = $disk->get($htmlPath);

        if (! is_string($html)) {
            throw PublicationExtractionException::rawUnavailable();
        }

        $blocks = $this->reader->read(
            $html,
            $surface,
            $version,
            $capture->blocks_captured === null ? null : (int) $capture->blocks_captured,
        );
        $items = $decoder->decode($blocks);
        $observedAt = $capture->captured_at ?? $capture->created_at ?? now();
        $anonymousContentCounts = [];

        foreach ($items as $item) {
            if ($item['external_id'] === null && $item['url'] === null) {
                $anonymousContentCounts[$item['content_hash']] = ($anonymousContentCounts[$item['content_hash']] ?? 0) + 1;
            }
        }

        $sourceScope = $anonymousContentCounts === [] ? null : $this->sourceScope($user, $capture);

        $persist(function () use ($user, $capture, $items, $observedAt, $anonymousContentCounts, $sourceScope): void {
            $this->observeAll($user, $capture, $items, $observedAt, $sourceScope, $anonymousContentCounts);
        });
    }

    private function decoderFor(string $surface): LinkedInPublicationsDecoder|LinkedInJobsDecoder|IndeedJobsDecoder
    {
        return match ($surface) {
            $this->publications->surface() => $this->publications,
            $this->jobs->surface() => $this->jobs,
            $this->indeedJobs->surface() => $this->indeedJobs,
            default => throw PublicationExtractionException::parseFailed('Superfície sem decodificador de publicações.'),
        };
    }

    private function observeAll(User $user, PageCapture $capture, array $items, CarbonInterface $observedAt, ?string $sourceScope, array $anonymousContentCounts): void
    {
        $resolved = [];

        foreach ($items as $item) {
            $fingerprint = $item['external_id'] === null && $item['url'] === null
                ? $this->fingerprintForAnonymousPublication($capture, $item, $sourceScope, $anonymousContentCounts[$item['content_hash']] ?? 0)
                : null;
            $resolved[] = [
                'item' => $item,
                'publication' => $this->identities->resolve(
                    $user,
                    $capture->source,
                    $item['external_id'],
                    $item['url'],
                    $fingerprint,
                ),
            ];
        }

        $ids = array_values(array_unique(array_map(
            static fn (array $entry): int => $entry['publication']->id,
            $resolved,
        )));

        $locked = $ids === []
            ? collect()
            : Publication::query()
                ->whereKey($ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

        foreach ($resolved as $entry) {
            $publication = $locked->get($entry['publication']->id);

            if (! $publication instanceof Publication) {
                throw PublicationExtractionException::processingFailed('Publicação desapareceu durante a extração.');
            }

            $this->observe($capture, $publication, $entry['item'], $observedAt);
        }
    }

    /**
     * @param  array{external_id: ?string, url: ?string, content: string, content_hash: string, evidence_block_ids: list<string>}  $item
     */
    private function observe(PageCapture $capture, Publication $publication, array $item, CarbonInterface $observedAt): void
    {
        $previousContentHash = $publication->content_hash;
        $version = $this->applyContent($publication, $item, $observedAt, $capture->id);

        $observation = $this->observations->record($capture, $publication, $observedAt);

        $observation->forceFill([
            'content' => $item['content'],
            'content_hash' => $item['content_hash'],
            'content_version' => $version,
            'evidence_block_ids' => $item['evidence_block_ids'],
        ])->save();

        if ($version !== null && $previousContentHash !== $item['content_hash']) {
            $this->queueClef($publication, $version);
        }
    }

    private function queueClef(Publication $publication, int $contentVersion): void
    {
        if (! config('triage.prefilter.enabled')) {
            return;
        }

        $result = PublicationClefResult::query()->firstOrCreate(
            [
                'publication_id' => $publication->id,
                'content_version' => $contentVersion,
                'attempt' => 1,
            ],
            [
                'status' => 'pending',
                'model' => $this->prefilter->configuredModel(),
                'classifier_identifier' => $this->prefilter->classifierIdentifier(),
            ],
        );

        if ($result->wasRecentlyCreated) {
            ClassifyPublicationWithClef::dispatch($result->id);
        }
    }

    private function sourceScope(User $user, PageCapture $capture): ?string
    {
        if (is_string($capture->execution_id) && $capture->execution_id !== '') {
            $run = DiscoveryRun::query()
                ->where('user_id', $user->id)
                ->where('execution_id', $capture->execution_id)
                ->first(['discovery_source_id', 'canonical_url_hash']);

            if ($run !== null && $run->discovery_source_id !== null) {
                return 'discovery_source:'.$run->discovery_source_id.'|'.$run->canonical_url_hash;
            }
        }

        $canonicalUrl = $this->identities->canonicalUrl((string) $capture->url);

        return $canonicalUrl === null ? null : 'source_url:'.$canonicalUrl;
    }

    private function fingerprintForAnonymousPublication(
        PageCapture $capture,
        array $item,
        ?string $sourceScope,
        int $sameContentCount,
    ): string {
        if ($sourceScope !== null && $sameContentCount === 1) {
            return hash('sha256', implode('|', [
                'anonymous-publication:v2',
                $capture->source,
                (string) $capture->surface,
                $sourceScope,
                $item['content_hash'],
            ]));
        }

        $blockId = $item['evidence_block_ids'][0] ?? null;

        if (! is_string($blockId) || $blockId === '') {
            throw PublicationExtractionException::parseFailed('Publicação anônima sem bloco de evidência.');
        }

        return hash('sha256', 'capture-block:v1|'.$capture->id.'|'.$blockId);
    }

    /**
     * @param  array{content: string, content_hash: string}  $item
     */
    private function applyContent(Publication $publication, array $item, CarbonInterface $observedAt, int $captureId): ?int
    {
        $currentHash = $publication->content_hash;
        $currentVersion = $publication->content_version;
        $currentCaptureId = $this->currentCaptureId($publication);
        $sameContent = is_string($currentHash) && hash_equals($currentHash, $item['content_hash']);
        $strictlyLater = $publication->last_observed_at === null
            || $observedAt->greaterThan($publication->last_observed_at);
        $sameSecondWinner = ! $strictlyLater
            && $publication->last_observed_at !== null
            && $observedAt->equalTo($publication->last_observed_at)
            && ($currentCaptureId === null || $captureId > $currentCaptureId);
        $incomingWins = $strictlyLater || $sameSecondWinner;

        if ($sameContent) {
            if ($currentVersion === null) {
                $publication->forceFill(['content_version' => 1])->save();
            }

            if ($incomingWins) {
                $publication->forceFill(['last_observed_at' => $observedAt])->save();
            }

            return $publication->content_version ?? 1;
        }

        if (! $incomingWins) {
            return null;
        }

        if ($currentHash === null) {
            $version = $currentVersion ?? 1;
        } else {
            $version = ($currentVersion ?? 1) + 1;
        }

        $publication->forceFill([
            'content' => $item['content'],
            'content_hash' => $item['content_hash'],
            'content_version' => $version,
            'last_observed_at' => $observedAt,
        ])->save();

        return $version;
    }

    private function currentCaptureId(Publication $publication): ?int
    {
        $observedAt = $publication->last_observed_at;

        if ($publication->content_version === null || $observedAt === null) {
            return null;
        }

        // Só a captura que estabeleceu a versão no instante corrente conta:
        // recapturas antigas do mesmo conteúdo também carregam essa versão.
        $captureId = PublicationObservation::query()
            ->where('publication_id', $publication->id)
            ->where('content_version', $publication->content_version)
            ->where('observed_at', $observedAt)
            ->max('page_capture_id');

        return $captureId === null ? null : (int) $captureId;
    }
}
