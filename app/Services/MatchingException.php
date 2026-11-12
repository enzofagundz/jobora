<?php

namespace App\Services;

use RuntimeException;

class MatchingException extends RuntimeException
{
    public function __construct(public readonly string $failureCode = 'invalid_output')
    {
        parent::__construct('O matching não produziu um resultado válido para a entrada autorizada.');
    }
}
