<?php

namespace App\Services\Discovery;

use RuntimeException;

class DiscoveryRunConflictException extends RuntimeException
{
    public function __construct(string $message = 'Execução de descoberta em conflito.')
    {
        parent::__construct($message);
    }
}
