<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum JobCaptureTriageResult: string implements HasLabel
{
    case Relevant = 'relevant';
    case Rejected = 'rejected';
    case NotAJob = 'not_a_job';
    case Uncertain = 'uncertain';

    public function getLabel(): string
    {
        return match ($this) {
            self::Relevant => 'Relevante',
            self::Rejected => 'Rejeitada',
            self::NotAJob => 'Não é uma vaga',
            self::Uncertain => 'Indeterminado',
        };
    }
}
