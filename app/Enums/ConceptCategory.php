<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ConceptCategory: string implements HasLabel
{
    case Skill = 'skill';
    case Tool = 'tool';
    case Methodology = 'methodology';
    case Requirement = 'requirement';
    case Experience = 'experience';

    public function getLabel(): string
    {
        return match ($this) {
            self::Skill => 'Habilidades',
            self::Tool => 'Ferramentas',
            self::Methodology => 'Metodologias',
            self::Requirement => 'Requisitos',
            self::Experience => 'Experiência',
        };
    }

    /**
     * All categories, in stable order.
     *
     * @return array<int, self>
     */
    public static function all(): array
    {
        return [
            self::Skill,
            self::Tool,
            self::Methodology,
            self::Requirement,
            self::Experience,
        ];
    }

    /**
     * Whether the category holds pt-BR free text instead of normalized
     * market concepts (no Title Case, no owner remap).
     */
    public function isFreeText(): bool
    {
        return $this === self::Requirement || $this === self::Experience;
    }
}
