<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ItemSource: string implements HasLabel
{
    case Ai = 'ai';
    case User = 'user';

    public function getLabel(): string
    {
        return match ($this) {
            self::Ai => 'IA',
            self::User => 'Usuário',
        };
    }
}
