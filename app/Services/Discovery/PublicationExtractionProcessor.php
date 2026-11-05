<?php

namespace App\Services\Discovery;

use App\Models\PageCapture;
use App\Models\Publication;
use App\Models\PublicationObservation;
use App\Models\User;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Facades\Storage;

class PublicationExtractionProcessor
{
    public function __construct(
        private readonly CaptureBlockReader $reader,
        private readonly LinkedInPublicationsDecoder $publications,
        private readonly LinkedInJobsDecoder $jobs,
        private readonly PublicationIdentityResolver $identities,
        private readonly ObservationRecorder $observations,
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

        $persist(function () use ($user, $capture, $items, $observedAt): void {
            foreach ($items as $item) {
                $this->observe($user, $capture, $item, $observedAt);
            }
        });
    }

    private function decoderFor(string $surface): LinkedInPublicationsDecoder|LinkedInJobsDecoder
    {
        return match ($surface) {
            $this->publications->surface() => $this->publications,
            $this->jobs->surface() => $this->jobs,
            default => throw PublicationExtractionException::parseFailed('Superfície sem decodificador de publicações.'),
        };
    }

    /**
     * @param  array{external_id: string, url: string, content: string, content_hash: string, evidence_block_ids: list<string>}  $item
     */
    private function observe(User $user, PageCapture $capture, array $item, CarbonInterface $observedAt): void
    {
        $publication = $this->identities->resolve($user, 'linkedin', $item['external_id'], $item['url']);

        $publication = Publication::query()->whereKey($publication->id)->lockForUpdate()->firstOrFail();

        $version = $this->applyContent($publication, $item, $observedAt, $capture->id);

        $observation = $this->observations->record($capture, $publication, $observedAt);

        $observation->forceFill([
            'content' => $item['content'],
            'content_hash' => $item['content_hash'],
            'content_version' => $version,
            'evidence_block_ids' => $item['evidence_block_ids'],
        ])->save();
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
        if ($publication->content_version === null) {
            return null;
        }

        $captureId = PublicationObservation::query()
            ->where('publication_id', $publication->id)
            ->where('content_version', $publication->content_version)
            ->max('page_capture_id');

        return $captureId === null ? null : (int) $captureId;
    }
}
