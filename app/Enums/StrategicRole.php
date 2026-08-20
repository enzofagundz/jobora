<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum StrategicRole: string implements HasLabel
{
    case Core = 'core';
    case Expansion = 'expansion';
    case Differentiation = 'differentiation';

    public function getLabel(): string
    {
        return match ($this) {
            self::Core => 'Núcleo',
            self::Expansion => 'Expansão',
            self::Differentiation => 'Diferenciação',
        };
    }
}
