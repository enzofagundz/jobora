<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum AnalysisPeriod: string implements HasLabel
{
    case Week = 'week';
    case Month = 'month';
    case Quarter = 'quarter';
    case Year = 'year';

    public function getLabel(): string
    {
        return match ($this) {
            self::Week => 'Semanal',
            self::Month => 'Mensal',
            self::Quarter => 'Trimestral',
            self::Year => 'Anual',
        };
    }

    /**
     * Buckets completos mínimos para exibir evolução.
     */
    public function minimumCompleteBuckets(): int
    {
        return match ($this) {
            self::Week => 4,
            self::Month => 2,
            self::Quarter => 2,
            self::Year => 2,
        };
    }

    /**
     * Quantidade de buckets por janela de comparação (por lado).
     */
    public function comparisonWindowBuckets(): int
    {
        return match ($this) {
            self::Week => 2,
            self::Month => 3,
            self::Quarter => 1,
            self::Year => 1,
        };
    }

    /**
     * @return array<int, self>
     */
    public static function all(): array
    {
        return [
            self::Week,
            self::Month,
            self::Quarter,
            self::Year,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Week->value => self::Week->getLabel(),
            self::Month->value => self::Month->getLabel(),
            self::Quarter->value => self::Quarter->getLabel(),
            self::Year->value => self::Year->getLabel(),
        ];
    }
}
