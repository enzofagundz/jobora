<?php

namespace App\Services;

use App\Enums\ConceptCategory;

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
     * Market × resume table rows: item, category, absolute/relative
     * frequency among confirmed postings, presence in the resume and
     * whether the item is a gap (frequent in postings, absent in resume).
     * Rows classified in the authenticated user's profile also carry the
     * strategic role and its source; the role never filters the rows.
     *
     * @return array<int, array{
     *     name: string,
     *     category: string,
     *     total: int,
     *     percent: float,
     *     present: bool,
     *     is_gap: bool,
     *     strategic_role: string|null,
     *     strategic_source: string|null,
     * }>
     */
    public function table(?ConceptCategory $category = null): array
    {
        $content = $this->resumes->content();
        $roleMap = $this->competencies->roleMap(auth()->user());
        $rows = $this->analysis->relativeFrequencies($category);

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

    /**
     * Top gaps: frequent in postings, absent in the resume, by frequency.
     *
     * @return array<int, array{name: string, category: string, total: int, percent: float, present: bool, is_gap: bool, strategic_role: string|null, strategic_source: string|null}>
     */
    public function gaps(int $limit = 10): array
    {
        $rows = array_filter($this->table(), fn (array $row): bool => $row['is_gap']);

        return array_slice(array_values($rows), 0, $limit);
    }
}
