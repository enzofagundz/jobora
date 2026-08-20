<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum JobPostingStatus: string implements HasLabel
{
    case Pending = 'pending';
    case NeedsReview = 'needs_review';
    case Confirmed = 'confirmed';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pendente',
            self::NeedsReview => 'Aguardando revisão',
            self::Confirmed => 'Confirmada',
            self::Failed => 'Falha na análise',
        };
    }
}
