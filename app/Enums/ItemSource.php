<?php

namespace App\Enums;

enum ItemSource: string
{
    case Ai = 'ai';
    case User = 'user';

    public function label(): string
    {
        return match ($this) {
            self::Ai => 'IA',
            self::User => 'Usuário',
        };
    }
}
