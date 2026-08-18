<?php

namespace App\Support;

/**
 * Formatação numérica compartilhada entre superfícies Filament
 * (widgets e páginas), evitando duplicação.
 */
final class Number
{
    public static function percent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1), '0'), '.').'%';
    }
}
