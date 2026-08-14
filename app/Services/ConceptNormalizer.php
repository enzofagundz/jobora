<?php

namespace App\Services;

use Illuminate\Support\Str;

class ConceptNormalizer
{
    /**
     * @var array<string, string>|null lowercase alias => canonical name
     */
    private ?array $aliasMap = null;

    /**
     * Normalize a concept name to its canonical form when a known alias
     * matches; otherwise return the cleaned name (trim + collapsed
     * whitespace, lowercased for stable aggregation).
     */
    public function normalize(string $name): string
    {
        $cleaned = $this->clean($name);

        if ($cleaned === '') {
            return '';
        }

        return $this->aliasMap()[$cleaned] ?? $cleaned;
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
        return config('concepts.'.$canonicalName)['keywords'] ?? [];
    }

    /**
     * Clean a raw name: trim, collapse inner whitespace, lowercase.
     */
    public function clean(string $name): string
    {
        return Str::lower(preg_replace('/\s+/u', ' ', trim($name)) ?? '');
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
