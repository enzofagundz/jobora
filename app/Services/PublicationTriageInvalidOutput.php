<?php

namespace App\Services;

use RuntimeException;
use Throwable;

final class PublicationTriageInvalidOutput extends RuntimeException
{
    public function __construct(
        public readonly int $promptTokens,
        public readonly int $completionTokens,
        ?Throwable $previous = null,
    ) {
        parent::__construct('Publication triage returned invalid structured output.', previous: $previous);
    }
}
