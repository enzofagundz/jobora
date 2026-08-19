<?php

namespace App\Enums;

enum JobCaptureTriageResult: string
{
    case Relevant = 'relevant';
    case Rejected = 'rejected';
    case NotAJob = 'not_a_job';
    case Uncertain = 'uncertain';

    public function label(): string
    {
        return match ($this) {
            self::Relevant => 'Relevante',
            self::Rejected => 'Rejeitada',
            self::NotAJob => 'Não é uma vaga',
            self::Uncertain => 'Indeterminado',
        };
    }
}
