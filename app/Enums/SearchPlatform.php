<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SearchPlatform: string implements HasLabel
{
    case LinkedIn = 'linkedin';
    case Indeed = 'indeed';

    public function getLabel(): string
    {
        return match ($this) {
            self::LinkedIn => 'LinkedIn',
            self::Indeed => 'Indeed',
        };
    }
}
