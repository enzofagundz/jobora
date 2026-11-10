<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SearchOperator: string implements HasLabel
{
    case All = 'and';
    case Any = 'or';

    /**
     * Operador a partir do valor de entrada; aceita os apelidos históricos
     * all/any usados por integrações antigas e cai em AND quando ausente.
     */
    public static function fromInput(mixed $value): self
    {
        return match ((string) $value) {
            self::Any->value, 'any' => self::Any,
            default => self::All,
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::All => 'Todos os termos',
            self::Any => 'Qualquer termo',
        };
    }
}
