<?php

namespace App\Services;

class ResumeAnalyzer
{
    public function __construct(private readonly ConceptNormalizer $normalizer) {}

    /**
     * Set of canonical concept names present in the resume text.
     * Not persisted — computed per request.
     *
     * @return array<int, string>
     */
    public function conceptsIn(?string $content): array
    {
        if (blank($content)) {
            return [];
        }

        $text = $this->normalizer->clean($content);
        $present = [];

        foreach ($this->normalizer->canonicalNames() as $canonical) {
            foreach ($this->normalizer->keywordsFor($canonical) as $keyword) {
                if (str_contains($text, $this->normalizer->clean($keyword))) {
                    $present[] = $canonical;
                    break;
                }
            }
        }

        return $present;
    }

    /**
     * Whether the resume contains a specific canonical concept.
     */
    public function hasConcept(?string $content, string $canonicalName): bool
    {
        return in_array($canonicalName, $this->conceptsIn($content), true);
    }
}
