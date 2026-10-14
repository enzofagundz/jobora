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
     * Conceitos presentes no texto candidato mas ausentes do original — a
     * definição de invenção compartilhada pela adequação e pela tradução.
     *
     * @return array<int, string>
     */
    public function inventedConcepts(?string $original, ?string $candidate): array
    {
        return array_values(array_diff(
            $this->conceptsIn($candidate),
            $this->conceptsIn($original),
        ));
    }

    /**
     * Whether the resume contains a specific canonical concept.
     */
    public function hasConcept(?string $content, string $canonicalName): bool
    {
        return $this->contains($content, $canonicalName);
    }

    /**
     * O currículo contém o item (canônico ou nome cru, ex.: 'github', 'ci/cd')?
     * Canônicos casam pelas keywords configuradas; qualquer outro nome casa
     * como texto livre, case-insensitive, delimitado por não-alfanuméricos.
     */
    public function contains(?string $content, string $name): bool
    {
        if (blank($content) || blank($name)) {
            return false;
        }

        $text = $this->normalizer->clean($content);
        $canonical = $this->normalizer->normalize($name);

        foreach ($this->normalizer->keywordsFor($canonical) as $keyword) {
            if ($this->containsText($text, $this->normalizer->clean($keyword))) {
                return true;
            }
        }

        return $this->containsText($text, $this->normalizer->clean($name));
    }

    private function containsText(string $text, string $needle): bool
    {
        if ($needle === '') {
            return false;
        }

        return preg_match('/(?:^|[^\p{L}\p{N}])'.preg_quote($needle, '/').'(?:$|[^\p{L}\p{N}])/u', $text) === 1;
    }
}
