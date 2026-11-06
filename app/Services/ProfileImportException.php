<?php

namespace App\Services;

use RuntimeException;

class ProfileImportException extends RuntimeException
{
    public function __construct(public readonly string $failureCode = 'invalid_output')
    {
        parent::__construct('A importação não produziu uma proposta válida e autorizada.');
    }
}
