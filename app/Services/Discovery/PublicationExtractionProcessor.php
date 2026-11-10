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
            $this->observeAll($user, $capture, $items, $observedAt);
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
     * Duas capturas com as mesmas publicações em ordens diferentes não podem
     * travar uma a outra: todas são resolvidas antes e travadas em ordem de id.
     *
     * @param  list<array{external_id: ?string, url: ?string, content: string, content_hash: string, evidence_block_ids: list<string>}>  $items
     */
    private function observeAll(User $user, PageCapture $capture, array $items, CarbonInterface $observedAt): void
    {
        $resolved = [];

        foreach ($items as $item) {
            $resolved[] = [
                'item' => $item,
                'publication' => $this->identities->resolve(
                    $user,
                    'linkedin',
                    $item['external_id'],
                    $item['url'],
                    $this->anonymousFingerprint($capture, $item),
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
    private function anonymousFingerprint(PageCapture $capture, array $item): ?string
    {
        if ($item['external_id'] !== null || $item['url'] !== null) {
            return null;
        }

        $blockId = $item['evidence_block_ids'][0] ?? null;

        if (! is_string($blockId) || $blockId === '') {
            throw PublicationExtractionException::parseFailed('Publicação anônima sem bloco de evidência.');
        }

        return hash('sha256', 'capture-block:v1|'.$capture->id.'|'.$blockId);
    }

    /**
     * @param  array{external_id: ?string, url: ?string, content: string, content_hash: string, evidence_block_ids: list<string>}  $item
     */
    private function observe(PageCapture $capture, Publication $publication, array $item, CarbonInterface $observedAt): void
    {
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
