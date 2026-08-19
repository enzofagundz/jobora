<?php

namespace App\Enums;

enum JobCaptureTriageStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Aguardando triagem',
            self::Processing => 'Triagem em andamento',
            self::Completed => 'Triagem concluída',
            self::Failed => 'Triagem com erro',
        };
    }
}
