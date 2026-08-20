<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum JobCaptureTriageStatus: string implements HasLabel
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Aguardando triagem',
            self::Processing => 'Triagem em andamento',
            self::Completed => 'Triagem concluída',
            self::Failed => 'Triagem com erro',
        };
    }
}
