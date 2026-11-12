<?php

namespace App\Enums;

enum DiscoveryRunStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case Completed = 'completed';
}
