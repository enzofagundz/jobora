<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SearchSeniority: string implements HasLabel
{
    case Internship = 'internship';
    case Junior = 'junior';
    case MidLevel = 'mid_level';
    case Senior = 'senior';

    public function getLabel(): string
    {
        return match ($this) {
            self::Internship => 'Estágio',
            self::Junior => 'Júnior',
            self::MidLevel => 'Pleno',
            self::Senior => 'Sênior',
        };
    }
}
