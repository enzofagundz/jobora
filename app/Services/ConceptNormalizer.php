<?php

namespace App\Services;

use App\Enums\ConceptCategory;
use Illuminate\Support\Str;

class ConceptNormalizer
{
    /**
     * @var array<string, string>|null lowercase alias => canonical name
     */
    private ?array $aliasMap = null;

    /**
     * Normalize a concept name to its canonical form when a known alias
     * matches; otherwise return the cleaned name in Title Case for stable
     * display (canonical official when it exists).
     */
    public function normalize(string $name): string
    {
        $cleaned = $this->clean($name);

        if ($cleaned === '') {
            return '';
        }

        return $this->aliasMap()[$cleaned] ?? $this->titleCase($cleaned);
    }

    /**
     * All canonical concept names defined in config/concepts.php.
     *
     * @return array<int, string>
     */
    public function canonicalNames(): array
    {
        return array_keys(config('concepts'));
    }

    /**
     * Keywords (lowercased) used to detect a canonical concept in free text.
     *
     * @return array<int, string>
     */
    public function keywordsFor(string $canonicalName): array
    {
        return config('concepts')[$canonicalName]['keywords'] ?? [];
    }

    /**
     * Owner category (skill|tool|methodology) for a canonical name, if mapped.
     * Unknown names and invalid config values resolve to null.
     */
    public function ownerCategory(string $canonicalName): ?string
    {
        $category = config('concepts')[$canonicalName]['category'] ?? null;

        if (! is_string($category)) {
            return null;
        }

        return ConceptCategory::tryFrom($category)?->value;
    }

    /**
     * Clean a raw name: trim, collapse inner whitespace, lowercase.
     */
    public function clean(string $name): string
    {
        return Str::lower(preg_replace('/\s+/u', ' ', trim($name)) ?? '');
    }

    /**
     * Title Case a cleaned (lowercased) name for display.
     */
    public function titleCase(string $cleaned): string
    {
        return mb_convert_case($cleaned, MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * Build the lookup map: lowercased alias => canonical name.
     *
     * @return array<string, string>
     */
    private function aliasMap(): array
    {
        if ($this->aliasMap !== null) {
            return $this->aliasMap;
        }

        $map = [];

        foreach (config('concepts') as $canonical => $definition) {
            foreach ($definition['aliases'] ?? [] as $alias) {
                $map[$this->clean($alias)] = $canonical;
            }
        }

        return $this->aliasMap = $map;
    }
}
