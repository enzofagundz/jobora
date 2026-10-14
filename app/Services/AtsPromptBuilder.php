<?php

namespace App\Services;

use App\Enums\ResumeLanguage;
use App\Models\JobPosting;

class AtsPromptBuilder
{
    public const MAX_LENGTH = 300;

    public const MAX_KEYWORDS = 15;

    public function __construct(
        private readonly ResumeAnalyzer $analyzer,
        private readonly ConceptNormalizer $normalizer,
    ) {}

    public function build(JobPosting $posting, string $resumeContent, ResumeLanguage $language = ResumeLanguage::PtBr): string
    {
        $resumeContent = trim($resumeContent);

        if (blank($resumeContent)) {
            return '';
        }

        $items = ($posting->relationLoaded('items') ? $posting->items : $posting->items()->get())
            ->sortBy(fn ($item): string => mb_strtolower($item->category->value.'|'.$item->name));

        return $this->buildFromSnapshot(
            (string) $posting->role,
            $posting->company,
            $resumeContent,
            $items->map(fn ($item): array => [
                'name' => $item->name,
                'present_in_resume' => $this->analyzer->hasConcept($resumeContent, $item->name)
                    || $this->analyzer->contains($resumeContent, $item->name),
            ])->all(),
            $language,
        );
    }

    /**
     * Build the deterministic ATS block from a captured input snapshot.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    public function buildFromSnapshot(string $role, ?string $company, string $resumeContent, array $items, ResumeLanguage $language = ResumeLanguage::PtBr): string
    {
        if (blank(trim($resumeContent))) {
            return '';
        }

        $present = [];

        foreach ($items as $item) {
            if (! is_string($item['name'] ?? null) || ($item['present_in_resume'] ?? false) !== true) {
                continue;
            }

            $present[] = $item['name'];

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
        $role = trim($role);
        $company = trim((string) $company);

        $keywordsPart = implode(', ', array_slice($present, 0, self::MAX_KEYWORDS));

        $prompt = $this->template($language, $role, $company, $keywordsPart);

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

    /**
     * Templates por idioma e forma da cascata cargo/empresa. A variante é
     * escolhida uma única vez; trocar de idioma só troca o mapa.
     *
     * @var array<string, array<string, string>>
     */
    private const TEMPLATES = [
        'pt-BR' => [
            'role_company' => 'Perfil altamente compatível com %role% em %company%: experiência comprovada em %keywords%. Recomenda-se entrevista.',
            'role' => 'Perfil altamente compatível com %role%: experiência comprovada em %keywords%. Recomenda-se entrevista.',
            'company' => 'Perfil altamente compatível em %company%: experiência comprovada em %keywords%. Recomenda-se entrevista.',
            'default' => 'Perfil altamente compatível: experiência comprovada em %keywords%. Recomenda-se entrevista.',
        ],
        'en' => [
            'role_company' => 'Highly compatible profile for %role% at %company%: proven experience in %keywords%. Interview recommended.',
            'role' => 'Highly compatible profile for %role%: proven experience in %keywords%. Interview recommended.',
            'company' => 'Highly compatible profile at %company%: proven experience in %keywords%. Interview recommended.',
            'default' => 'Highly compatible profile: proven experience in %keywords%. Interview recommended.',
        ],
    ];

    private function template(ResumeLanguage $language, string $role, string $company, string $keywords): string
    {
        $shape = match (true) {
            $role !== '' && $company !== '' => 'role_company',
            $role !== '' => 'role',
            $company !== '' => 'company',
            default => 'default',
        };

        return strtr(self::TEMPLATES[$language->value][$shape], [
            '%role%' => $role,
            '%company%' => $company,
            '%keywords%' => $keywords,
        ]);
    }
}
