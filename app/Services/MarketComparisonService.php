<?php

namespace App\Services;

use App\Enums\ConceptCategory;

class MarketComparisonService
{
    public function __construct(
        private readonly MarketAnalysisService $analysis,
        private readonly ResumeAnalyzer $analyzer,
        private readonly ResumeService $resumes,
    ) {}

    /**
     * Market × resume table rows: item, category, absolute/relative
     * frequency among confirmed postings, presence in the resume and
     * whether the item is a gap (frequent in postings, absent in resume).
     *
     * @return array<int, array{
     *     name: string,
     *     category: string,
     *     total: int,
     *     percent: float,
     *     present: bool,
     *     is_gap: bool,
     * }>
     */
    public function table(?ConceptCategory $category = null): array
    {
        $present = $this->analyzer->conceptsIn($this->resumes->content());
        $rows = $this->analysis->relativeFrequencies($category);

        return array_map(function (array $row) use ($present): array {
            $isPresent = in_array($row['name'], $present, true);

            return [
                'name' => $row['name'],
                'category' => $row['category'],
                'total' => $row['total'],
                'percent' => $row['percent'],
                'present' => $isPresent,
                'is_gap' => ! $isPresent,
            ];
        }, $rows);
    }

    /**
     * Top gaps: frequent in postings, absent in the resume, by frequency.
     *
     * @return array<int, array{name: string, category: string, total: int, percent: float}>
     */
    public function gaps(int $limit = 10): array
    {
        $rows = array_filter($this->table(), fn (array $row): bool => $row['is_gap']);

        return array_slice(array_values($rows), 0, $limit);
    }
}
