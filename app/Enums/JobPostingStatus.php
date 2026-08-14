<?php

namespace App\Enums;

enum JobPostingStatus: string
{
    case Pending = 'pending';
    case NeedsReview = 'needs_review';
    case Confirmed = 'confirmed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendente',
            self::NeedsReview => 'Aguardando revisão',
            self::Confirmed => 'Confirmada',
            self::Failed => 'Falha na análise',
        };
    }
}
