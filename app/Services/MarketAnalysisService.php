<?php

namespace App\Services;

use App\Enums\ConceptCategory;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * All market metrics are computed on demand from confirmed postings.
 * Nothing is materialized.
 */
class MarketAnalysisService
{
    /**
     * Absolute frequencies: COUNT(DISTINCT job_posting_id) per (category, name).
     *
     * @return array<int, array{category: string, name: string, total: int}>
     */
    public function frequencies(User $user, ?ConceptCategory $category = null, ?string $from = null, ?string $to = null): array
    {
        return DB::table('job_posting_items as i')
            ->join('job_postings as jp', 'jp.id', '=', 'i.job_posting_id')
            ->where('jp.status', 'confirmed')
            ->where('jp.user_id', $user->id)
            ->when($category, fn ($q) => $q->where('i.category', $category->value))
            ->when($from, fn ($q) => $q->where('jp.collected_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('jp.collected_at', '<', $to))
            ->selectRaw('i.category, i.name, COUNT(DISTINCT i.job_posting_id) AS total')
            ->groupBy('i.category', 'i.name')
            ->orderByDesc('total')
            ->orderBy('i.name')
            ->get()
            ->map(fn ($row) => [
                'category' => $row->category,
                'name' => $row->name,
                'total' => (int) $row->total,
            ])
            ->all();
    }

    /**
     * Total distinct confirmed postings in the same scope.
     */
    public function totalConfirmed(User $user, ?string $from = null, ?string $to = null): int
    {
        return DB::table('job_postings')
            ->where('status', 'confirmed')
            ->where('user_id', $user->id)
            ->when($from, fn ($q) => $q->where('collected_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('collected_at', '<', $to))
            ->distinct()
            ->count('id');
    }

    /**
     * Relative frequencies (percentage of confirmed postings in scope).
     *
     * @return array<int, array{category: string, name: string, total: int, percent: float}>
     */
    public function relativeFrequencies(User $user, ?ConceptCategory $category = null, ?string $from = null, ?string $to = null): array
    {
        $total = $this->totalConfirmed($user, $from, $to);

        if ($total === 0) {
            return [];
        }

        return array_map(
            fn (array $row) => $row + ['percent' => round($row['total'] / $total * 100, 1)],
            $this->frequencies($user, $category, $from, $to),
        );
    }

    /**
     * Top N items globally or within a category.
     *
     * @return array<int, array{category: string, name: string, total: int}>
     */
    public function top(User $user, int $limit = 10, ?ConceptCategory $category = null): array
    {
        return array_slice($this->frequencies($user, $category), 0, $limit);
    }

    /**
     * Recurring same-category pairs, ordered by number of distinct postings.
     *
     * @return array<int, array{category: string, name_a: string, name_b: string, total: int}>
     */
    public function pairsByCategory(User $user, ?ConceptCategory $category = null, int $limit = 20): array
    {
        return DB::table('job_posting_items as a')
            ->join('job_posting_items as b', function ($join) {
                $join->on('a.job_posting_id', '=', 'b.job_posting_id')
                    ->on('a.category', '=', 'b.category')
                    ->on('a.name', '<', 'b.name');
            })
            ->join('job_postings as jp', 'jp.id', '=', 'a.job_posting_id')
            ->where('jp.status', 'confirmed')
            ->where('jp.user_id', $user->id)
            ->when($category, fn ($q) => $q->where('a.category', $category->value))
            ->selectRaw('a.category, a.name AS name_a, b.name AS name_b, COUNT(DISTINCT a.job_posting_id) AS total')
            ->groupBy('a.category', 'a.name', 'b.name')
            ->orderByDesc('total')
            ->orderBy('a.name')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'category' => $row->category,
                'name_a' => $row->name_a,
                'name_b' => $row->name_b,
                'total' => (int) $row->total,
            ])
            ->all();
    }

    /**
     * Monthly frequency series per item (top-N of the category selected by
     * the caller via $topNames), used for the temporal analysis.
     *
     * @param  array<int, string>  $topNames
     * @return array<int, array{month: string, name: string, total: int}>
     */
    public function monthlySeries(User $user, ?ConceptCategory $category = null, ?string $from = null, ?string $to = null, array $topNames = []): array
    {
        return DB::table('job_posting_items as i')
            ->join('job_postings as jp', 'jp.id', '=', 'i.job_posting_id')
            ->where('jp.status', 'confirmed')
            ->where('jp.user_id', $user->id)
            ->when($category, fn ($q) => $q->where('i.category', $category->value))
            ->when($from, fn ($q) => $q->where('jp.collected_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('jp.collected_at', '<', $to))
            ->when($topNames !== [], fn ($q) => $q->whereIn('i.name', $topNames))
            ->selectRaw("strftime('%Y-%m', jp.collected_at) AS month, i.name, COUNT(DISTINCT jp.id) AS total")
            ->groupBy('month', 'i.name')
            ->orderBy('month')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'month' => $row->month,
                'name' => $row->name,
                'total' => (int) $row->total,
            ])
            ->all();
    }

    /**
     * Distinct months (YYYY-MM) with confirmed postings in scope.
     *
     * @return array<int, string>
     */
    public function months(User $user, ?string $from = null, ?string $to = null): array
    {
        return DB::table('job_postings')
            ->where('status', 'confirmed')
            ->where('user_id', $user->id)
            ->when($from, fn ($q) => $q->where('collected_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('collected_at', '<', $to))
            ->selectRaw("strftime('%Y-%m', collected_at) AS month")
            ->distinct()
            ->orderBy('month')
            ->pluck('month')
            ->all();
    }

    /**
     * Parse a "YYYY-MM" month boundary: start of month, or null when blank.
     */
    public static function monthStart(?string $month): ?Carbon
    {
        if (blank($month)) {
            return null;
        }

        return Carbon::createFromFormat('Y-m', $month)?->startOfMonth();
    }

    /**
     * Human label for a "YYYY-MM" month.
     */
    public static function monthLabel(string $month): string
    {
        return Carbon::createFromFormat('Y-m', $month)?->translatedFormat('M/Y') ?? $month;
    }
}
