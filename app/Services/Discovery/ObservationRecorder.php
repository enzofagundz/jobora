<?php

namespace App\Services\Discovery;

use App\Models\PageCapture;
use App\Models\Publication;
use App\Models\PublicationObservation;
use Carbon\CarbonInterface;

class ObservationRecorder
{
    public function record(PageCapture $pageCapture, Publication $publication, ?CarbonInterface $observedAt = null): PublicationObservation
    {
        if ($pageCapture->user_id !== $publication->user_id) {
            throw new \LogicException('Captura de página e publicação pertencem a usuários distintos.');
        }

        return PublicationObservation::firstOrCreate(
            [
                'page_capture_id' => $pageCapture->id,
                'publication_id' => $publication->id,
            ],
            [
                'observed_at' => $observedAt ?? $pageCapture->captured_at ?? now(),
            ],
        );
    }
}
