<?php

namespace App\DTO;

use App\Enums\JobCaptureTriageResult;
use InvalidArgumentException;

/**
 * Resultado estruturado e validado da triagem da IA para uma captura.
 *
 * Falhas estruturais (tipos errados) lançam InvalidArgumentException —
 * o JobTriageService ignora a entrada e marca a captura como failed.
 */
final class JobTriageResult
{
    public function __construct(
        public readonly int $captureId,
        public readonly bool $isJob,
        public readonly JobCaptureTriageResult $result,
        public readonly ?string $reason,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromStructured(array $data): self
    {
        if (! isset($data['capture_id']) || ! is_int($data['capture_id'])) {
            throw new InvalidArgumentException('Resultado de triagem sem "capture_id" válido.');
        }

        if (! isset($data['result']) || ! is_string($data['result']) || ! JobCaptureTriageResult::tryFrom($data['result'])) {
            throw new InvalidArgumentException('Resultado de triagem inválido: '.($data['result'] ?? 'null'));
        }

        if (! isset($data['is_job']) || ! is_bool($data['is_job'])) {
            throw new InvalidArgumentException('Resultado de triagem sem "is_job" válido.');
        }

        $reason = $data['reason'] ?? null;

        if ($reason !== null && ! is_string($reason)) {
            throw new InvalidArgumentException('"reason" deve ser string ou null.');
        }

        return new self($data['capture_id'], $data['is_job'], JobCaptureTriageResult::from($data['result']), $reason);
    }
}
