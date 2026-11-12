<?php

namespace App\Services\Discovery;

use App\Models\PageCapture;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CaptureRetentionService
{
    public function pruneRaw(?CarbonInterface $now = null): int
    {
        $now ??= now();
        $disk = Storage::disk((string) config('capture.ingestion.disk', 'local'));
        $pruned = 0;

        PageCapture::query()
            ->whereNotNull('html_path')
            ->where('raw_expires_at', '<=', $now)
            ->orderBy('id')
            ->chunkById(100, function ($captures) use ($disk, $now, &$pruned): void {
                foreach ($captures as $capture) {
                    try {
                        if (! $disk->delete((string) $capture->html_path)) {
                            $this->reportPruneFailure($capture, 'delete_failed');

                            continue;
                        }
                    } catch (\Throwable $exception) {
                        $this->reportPruneFailure($capture, 'delete_exception', $exception::class);

                        continue;
                    }

                    $capture->forceFill([
                        'html_path' => null,
                        'raw_deleted_at' => $now,
                    ])->save();
                    $pruned++;
                }
            });

        return $pruned;
    }

    private function reportPruneFailure(PageCapture $capture, string $code, ?string $exception = null): void
    {
        Log::warning('Falha ao remover HTML bruto de captura expirada.', [
            'page_capture_id' => $capture->id,
            'code' => $code,
            'exception' => $exception,
        ]);
    }

    private function reportObjectFailure(string $file, string $code, ?string $exception = null): void
    {
        Log::warning('Falha ao remover objeto órfão de captura.', [
            'file' => $file,
            'code' => $code,
            'exception' => $exception,
        ]);
    }

    public function reconcileOrphans(?CarbonInterface $now = null): array
    {
        $now ??= now();
        $disk = Storage::disk((string) config('capture.ingestion.disk', 'local'));
        $root = trim((string) config('capture.ingestion.path', 'capture-envelopes'), '/');
        $referenced = PageCapture::query()->whereNotNull('html_path')->pluck('html_path')->flip();
        $deletedObjects = 0;

        foreach ($disk->allFiles($root) as $file) {
            if ($referenced->has($file)) {
                continue;
            }

            if ($disk->lastModified($file) <= $now->copy()->subDay()->getTimestamp()) {
                try {
                    if (! $disk->delete($file)) {
                        $this->reportObjectFailure($file, 'delete_failed');

                        continue;
                    }
                } catch (\Throwable $exception) {
                    $this->reportObjectFailure($file, 'delete_exception', $exception::class);

                    continue;
                }

                $deletedObjects++;
            }
        }

        $clearedMetadata = 0;

        PageCapture::query()
            ->whereNotNull('html_path')
            ->orderBy('id')
            ->chunkById(100, function ($captures) use ($disk, $now, &$clearedMetadata): void {
                foreach ($captures as $capture) {
                    if ($disk->exists((string) $capture->html_path)) {
                        continue;
                    }

                    if ($capture->raw_expires_at !== null && $capture->raw_expires_at->lessThanOrEqualTo($now)) {
                        $capture->forceFill(['html_path' => null, 'raw_deleted_at' => $now])->save();
                        $clearedMetadata++;
                    }
                }
            });

        return ['objects' => $deletedObjects, 'metadata' => $clearedMetadata];
    }
}
