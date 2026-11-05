<?php

namespace App\Services\Discovery;

use App\Models\AiOperation;
use App\Models\PageCapture;
use App\Models\PublicationIdentityConflict;
use App\Services\AiOperationService;
use Illuminate\Support\Facades\Log;
use Throwable;

class PublicationExtractionService
{
    private const SUPPORTED_SURFACES = [
        'linkedin_publications',
        'linkedin_jobs',
        'indeed_jobs',
    ];

    private const SAFE_FAILURE_CODES = [
        PublicationExtractionException::RawUnavailable,
        PublicationExtractionException::ParseFailed,
        PublicationExtractionException::ProcessingFailed,
        'worker_unavailable',
    ];

    public function __construct(private readonly AiOperationService $operations) {}

    public function requestSafely(PageCapture $capture): ?AiOperation
    {
        if (! $this->supports($capture->surface)) {
            return null;
        }

        try {
            return $this->operations->enqueuePublicationExtraction($capture);
        } catch (Throwable $exception) {
            Log::channel('ai')->warning('Publication extraction could not be scheduled.', [
                'page_capture_id' => $capture->id,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    public function supports(string $surface): bool
    {
        return in_array($surface, self::SUPPORTED_SURFACES, true);
    }

    /**
     * @return list<string>
     */
    public function supportedSurfaces(): array
    {
        return self::SUPPORTED_SURFACES;
    }

    /**
     * @return array{status: string, error_code: ?string, empty: bool, counts: array{blocks: int, publications: int, conflicts: int}}
     */
    public function describe(PageCapture $capture): array
    {
        $operation = $capture->extractionOperation;
        $status = match ($operation?->status?->value) {
            'queued' => 'pending',
            'running' => 'running',
            'completed' => 'completed',
            'failed' => 'failed',
            default => 'pending',
        };

        $publicationIds = $capture->observations()->pluck('publication_id')->unique()->values();

        $conflicts = $publicationIds->isEmpty()
            ? 0
            : PublicationIdentityConflict::query()
                ->where('user_id', $capture->user_id)
                ->where(function ($query) use ($publicationIds): void {
                    $query->whereIn('publication_id', $publicationIds)
                        ->orWhereIn('other_publication_id', $publicationIds);
                })
                ->count();

        $publications = $publicationIds->count();

        return [
            'status' => $status,
            'error_code' => $status === 'failed'
                ? $this->safeFailureCode($operation?->failure_code)
                : null,
            'empty' => $status === 'completed' && $publications === 0,
            'counts' => [
                'blocks' => (int) ($capture->blocks_captured ?? 0),
                'publications' => $publications,
                'conflicts' => $conflicts,
            ],
        ];
    }

    private function safeFailureCode(?string $code): string
    {
        return in_array($code, self::SAFE_FAILURE_CODES, true)
            ? (string) $code
            : PublicationExtractionException::ProcessingFailed;
    }
}
