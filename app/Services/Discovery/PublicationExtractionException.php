<?php

namespace App\Services\Discovery;

use RuntimeException;

final class PublicationExtractionException extends RuntimeException
{
    public const RawUnavailable = 'raw_unavailable';

    public const ParseFailed = 'parse_failed';

    public const ProcessingFailed = 'processing_failed';

    private function __construct(string $message, public readonly string $failureCode)
    {
        parent::__construct($message);
    }

    public static function rawUnavailable(): self
    {
        return new self('O HTML privado da captura não está disponível.', self::RawUnavailable);
    }

    public static function parseFailed(string $message = 'A captura não segue o contrato de publicações.'): self
    {
        return new self($message, self::ParseFailed);
    }

    public static function processingFailed(string $message = 'Não foi possível processar a captura.'): self
    {
        return new self($message, self::ProcessingFailed);
    }
}
