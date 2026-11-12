<?php

namespace App\Services\Discovery;

use RuntimeException;

class DiscoverySourceConflictException extends RuntimeException
{
    public function __construct(string $message = 'A fonte foi alterada por outra operação.')
    {
        parent::__construct($message);
    }
}
