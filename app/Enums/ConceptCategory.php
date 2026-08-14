<?php

namespace App\Enums;

enum ConceptCategory: string
{
    case Technology = 'technology';
    case Language = 'language';
    case Framework = 'framework';
    case Database = 'database';
    case Tool = 'tool';
    case Cloud = 'cloud';
    case Methodology = 'methodology';
    case Skill = 'skill';
    case Requirement = 'requirement';
    case Experience = 'experience';

    public function label(): string
    {
        return match ($this) {
            self::Technology => 'Tecnologias',
            self::Language => 'Linguagens',
            self::Framework => 'Frameworks',
            self::Database => 'Bancos de dados',
            self::Tool => 'Ferramentas',
            self::Cloud => 'Cloud',
            self::Methodology => 'Metodologias',
            self::Skill => 'Habilidades',
            self::Requirement => 'Requisitos',
            self::Experience => 'Experiência',
        };
    }

    /**
     * Map a structured-output schema key (e.g. "technologies") to a category.
     */
    public static function fromSchemaKey(string $key): ?self
    {
        return match ($key) {
            'technologies' => self::Technology,
            'languages' => self::Language,
            'frameworks' => self::Framework,
            'databases' => self::Database,
            'tools' => self::Tool,
            'cloud' => self::Cloud,
            'methodologies' => self::Methodology,
            'skills' => self::Skill,
            'requirements' => self::Requirement,
            'experience' => self::Experience,
            default => null,
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
            self::Technology,
            self::Language,
            self::Framework,
            self::Database,
            self::Tool,
            self::Cloud,
            self::Methodology,
            self::Skill,
            self::Requirement,
            self::Experience,
        ];
    }
}
