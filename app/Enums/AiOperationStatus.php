<?php

namespace App\Enums;

enum AiOperationStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function isActive(): bool
    {
        return in_array($this, [self::Queued, self::Running], true);
    }
}
