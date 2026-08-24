<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SearchMode: string implements HasLabel
{
    case Publications = 'publications';

    case Jobs = 'jobs';

    public function getLabel(): string
    {
        return match ($this) {
            self::Publications => 'Publicações',
            self::Jobs => 'Vagas',
        };
    }
}
