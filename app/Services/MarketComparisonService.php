<?php

namespace App\Services;

use App\Enums\ConceptCategory;
use App\Enums\ResumeLanguage;
use App\Models\User;

class MarketComparisonService
{
    public function __construct(
        private readonly MarketAnalysisService $analysis,
        private readonly ResumeAnalyzer $analyzer,
        private readonly ResumeService $resumes,
        private readonly StrategicCompetencyService $competencies,
        private readonly ConceptNormalizer $normalizer,
    ) {}

    /**
     * @return array<int, array{...}>
     */
    public function table(User $user, ?ConceptCategory $category = null): array
    {
        $content = $this->resumes->content($user, ResumeLanguage::PtBr);
        $roleMap = $this->competencies->roleMap($user);
        $rows = $this->analysis->relativeFrequencies($user, $category);

        return array_map(function (array $row) use ($content, $roleMap): array {
            $isPresent = $this->analyzer->contains($content, $row['name']);

            $classification = $roleMap[$this->normalizer->normalize($row['name'])] ?? null;

            return [
                'name' => $row['name'],
                'category' => $row['category'],
                'total' => $row['total'],
                'percent' => $row['percent'],
                'present' => $isPresent,
                'is_gap' => ! $isPresent,
                'strategic_role' => $classification?->strategic_role->value,
                'strategic_source' => $classification?->source->value,
            ];
        }, $rows);
    }

    public function gaps(User $user, int $limit = 10): array
    {
        $rows = array_filter($this->table($user), fn (array $row): bool => $row['is_gap']);

        return array_slice(array_values($rows), 0, $limit);
    }
}
