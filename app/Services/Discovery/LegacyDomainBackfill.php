<?php

namespace App\Services\Discovery;

use App\Models\JobCapture;
use App\Models\OpportunitySource;
use App\Models\PageCapture;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class LegacyDomainBackfill
{
    public function __construct(
        private readonly PublicationIdentityResolver $resolver,
        private readonly ObservationRecorder $observations,
    ) {}

    /**
     * @return array{page_captures: int, publications: int, observations: int, opportunity_sources: int, skipped: int, failed: array<int, array{job_capture_id: int, code: string}>}
     */
    public function run(int $chunkSize = 500): array
    {
        $counts = [
            'page_captures' => 0,
            'publications' => 0,
            'observations' => 0,
            'opportunity_sources' => 0,
            'skipped' => 0,
            'failed' => [],
        ];

        JobCapture::withTrashed()
            ->orderBy('id')
            ->chunkById($chunkSize, function ($captures) use (&$counts): void {
                foreach ($captures as $capture) {
                    if (PageCapture::withTrashed()->where('legacy_job_capture_id', $capture->id)->exists()) {
                        $counts['skipped']++;

                        continue;
                    }

                    try {
                        DB::transaction(function () use ($capture, &$counts): void {
                            $source = mb_strtolower(trim((string) $capture->source));

                            $publication = $this->resolver->resolve(
                                $capture->user,
                                $source,
                                $capture->external_id,
                                $capture->url,
                                $this->legacyFingerprint($capture),
                            );

                            $pageCapture = PageCapture::create([
                                'user_id' => $capture->user_id,
                                'source' => $source,
                                'surface' => null,
                                'url' => $capture->url,
                                'html_path' => null,
                                'captured_at' => $capture->captured_at ?? $capture->created_at,
                                'legacy_job_capture_id' => $capture->id,
                                'deleted_at' => $capture->deleted_at,
                            ]);

                            $this->observations->record(
                                $pageCapture,
                                $publication,
                                $capture->captured_at ?? $capture->created_at,
                            );

                            $counts['page_captures']++;
                            $counts['publications'] += $publication->wasRecentlyCreated ? 1 : 0;
                            $counts['observations']++;

                            if ($capture->job_posting_id !== null) {
                                $opportunitySource = OpportunitySource::firstOrCreate(
                                    [
                                        'job_posting_id' => $capture->job_posting_id,
                                        'publication_id' => $publication->id,
                                    ],
                                    ['is_legacy' => true],
                                );

                                $counts['opportunity_sources'] += $opportunitySource->wasRecentlyCreated ? 1 : 0;
                            }
                        });
                    } catch (\InvalidArgumentException) {
                        $counts['failed'][] = [
                            'job_capture_id' => $capture->id,
                            'code' => 'invalid_identity',
                        ];
                    } catch (UniqueConstraintViolationException $exception) {
                        if (! PageCapture::withTrashed()->where('legacy_job_capture_id', $capture->id)->exists()) {
                            throw $exception;
                        }

                        $counts['skipped']++;
                    }
                }
            });

        return $counts;
    }

    private function legacyFingerprint(JobCapture $capture): ?string
    {
        if ($capture->external_id !== null) {
            return null;
        }

        if ($this->resolver->canonicalUrl((string) $capture->url) !== null) {
            return null;
        }

        return $capture->dedup_key;
    }
}
