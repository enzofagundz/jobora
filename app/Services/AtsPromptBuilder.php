<?php

namespace App\Services;

use App\Models\JobPosting;

class AtsPromptBuilder
{
    public const MAX_LENGTH = 300;

    public const MAX_KEYWORDS = 15;

    public function __construct(
        private readonly ResumeAnalyzer $analyzer,
        private readonly ConceptNormalizer $normalizer,
    ) {}

    public function build(JobPosting $posting, string $resumeContent): string
    {
        $resumeContent = trim($resumeContent);

        if (blank($resumeContent)) {
            return '';
        }

        $items = $posting->relationLoaded('items') ? $posting->items : $posting->items()->get();

        $present = [];

        foreach ($items as $item) {
            if ($this->analyzer->hasConcept($resumeContent, $item->name) || $this->analyzer->contains($resumeContent, $item->name)) {
                $present[] = $item->name;
            }

            if (count($present) >= self::MAX_KEYWORDS) {
                break;
            }
        }

        $present = array_values(array_unique($present));

        if ($present === []) {
            return '';
        }

        // Ensure no fabrication: already filtered to present only.
        // Build template.
        $role = trim((string) $posting->role);
        $company = trim((string) $posting->company);

        $keywordsPart = implode(', ', array_slice($present, 0, self::MAX_KEYWORDS));

        if ($role !== '' && $company !== '') {
            $prompt = sprintf(
                'Perfil altamente compatível com %s em %s: experiência comprovada em %s. Recomenda-se entrevista.',
                $role,
                $company,
                $keywordsPart
            );
        } elseif ($role !== '') {
            $prompt = sprintf(
                'Perfil altamente compatível com %s: experiência comprovada em %s. Recomenda-se entrevista.',
                $role,
                $keywordsPart
            );
        } elseif ($company !== '') {
            $prompt = sprintf(
                'Perfil altamente compatível em %s: experiência comprovada em %s. Recomenda-se entrevista.',
                $company,
                $keywordsPart
            );
        } else {
            $prompt = sprintf(
                'Perfil altamente compatível: experiência comprovada em %s. Recomenda-se entrevista.',
                $keywordsPart
            );
        }

        // Enforce 300 chars limit, truncating without breaking word when possible.
        if (mb_strlen($prompt) > self::MAX_LENGTH) {
            $truncated = mb_substr($prompt, 0, self::MAX_LENGTH);
            // Try to truncate at last space/comma to avoid breaking word.
            $lastSpace = mb_strrpos($truncated, ' ');
            if ($lastSpace !== false && $lastSpace > self::MAX_LENGTH - 50) {
                $truncated = mb_substr($truncated, 0, $lastSpace);
            }

            $prompt = rtrim($truncated, ' ,.').'.';
        }

        return $prompt;
    }
}
