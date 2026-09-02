<?php

namespace App\Services;

use App\Enums\AnalysisPeriod;
use App\Enums\ConceptCategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MarketAnalysisService
{
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
            ->orderBy('i.category')
            ->orderBy('i.name')
            ->get()
            ->map(fn ($row) => [
                'category' => $row->category,
                'name' => $row->name,
                'total' => (int) $row->total,
            ])
            ->all();
    }

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

    public function top(User $user, int $limit = 10, ?ConceptCategory $category = null): array
    {
        return array_slice($this->frequencies($user, $category), 0, $limit);
    }

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

    // ---- Period-aware API ----

    /**
     * Buckets de calendário no escopo do usuário/categoria.
     * Gera sequência contínua do primeiro ao último bucket observado,
     * preenchendo gaps com observed_count = 0.
     *
     * @return array<int, array{key: string, label: string, start: CarbonImmutable, end: CarbonImmutable, observed_count: int, is_complete: bool}>
     */
    public function periodBuckets(User $user, AnalysisPeriod $period, ?ConceptCategory $category = null): array
    {
        $bucketExpr = $this->bucketExpression($category ? 'jp.collected_at' : 'collected_at', $period);

        if ($category === null) {
            $rows = DB::table('job_postings')
                ->where('status', 'confirmed')
                ->where('user_id', $user->id)
                ->selectRaw("{$bucketExpr} as bucket, COUNT(DISTINCT id) as observed")
                ->groupBy('bucket')
                ->orderBy('bucket')
                ->get();
        } else {
            $rows = DB::table('job_postings as jp')
                ->join('job_posting_items as i', 'i.job_posting_id', '=', 'jp.id')
                ->where('jp.status', 'confirmed')
                ->where('jp.user_id', $user->id)
                ->where('i.category', $category->value)
                ->selectRaw("{$bucketExpr} as bucket, COUNT(DISTINCT jp.id) as observed")
                ->groupBy('bucket')
                ->orderBy('bucket')
                ->get();
        }

        if ($rows->isEmpty()) {
            return [];
        }

        $map = $rows->pluck('observed', 'bucket')->all();

        // Determine first/last start via keys
        $keys = array_keys($map);
        // For chronological order, sort via start date rather than string, for week/quarter safety
        usort($keys, function (string $a, string $b) use ($period): int {
            $sa = $this->bucketKeyToStart($a, $period);
            $sb = $this->bucketKeyToStart($b, $period);

            return $sa <=> $sb;
        });

        $firstKey = $keys[0];
        $lastKey = $keys[count($keys) - 1];
        $firstStart = $this->bucketKeyToStart($firstKey, $period);
        $lastStart = $this->bucketKeyToStart($lastKey, $period);
        $currentStart = $this->currentBucketStart($period);

        $buckets = [];
        $cursor = $firstStart;
        // safety guard: max 500 buckets
        $iterations = 0;
        while ($cursor->lessThanOrEqualTo($lastStart) && $iterations < 500) {
            $key = $this->bucketStartToKey($cursor, $period);
            $label = $this->bucketStartToLabel($cursor, $period);
            $observed = (int) ($map[$key] ?? 0);
            $isComplete = $cursor->lessThan($currentStart);
            $end = $this->nextBucketStart($cursor, $period);

            $buckets[] = [
                'key' => $key,
                'label' => $label,
                'start' => $cursor,
                'end' => $end,
                'observed_count' => $observed,
                'is_complete' => $isComplete,
            ];

            $cursor = $end;
            $iterations++;
        }

        return $buckets;
    }

    /**
     * Série por bucket e item: um registro por (bucket, category, name).
     *
     * @return array<int, array{bucket: string, category: string, name: string, total: int}>
     */
    public function periodSeries(User $user, AnalysisPeriod $period, ?ConceptCategory $category = null, ?string $from = null, ?string $to = null): array
    {
        $bucketExpr = $this->bucketExpression('jp.collected_at', $period);

        return DB::table('job_posting_items as i')
            ->join('job_postings as jp', 'jp.id', '=', 'i.job_posting_id')
            ->where('jp.status', 'confirmed')
            ->where('jp.user_id', $user->id)
            ->when($category, fn ($q) => $q->where('i.category', $category->value))
            ->when($from, fn ($q) => $q->where('jp.collected_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('jp.collected_at', '<', $to))
            ->selectRaw("{$bucketExpr} as bucket, i.category, i.name, COUNT(DISTINCT jp.id) as total")
            ->groupBy('bucket', 'i.category', 'i.name')
            ->orderBy('bucket')
            ->orderByDesc('total')
            ->orderBy('i.category')
            ->orderBy('i.name')
            ->get()
            ->map(fn ($row) => [
                'bucket' => $row->bucket,
                'category' => $row->category,
                'name' => $row->name,
                'total' => (int) $row->total,
            ])
            ->all();
    }

    /**
     * Compat: mensal como caso particular - mantém agrupamento legado por month,name para retrocompatibilidade.
     */
    public function monthlySeries(User $user, ?ConceptCategory $category = null, ?string $from = null, ?string $to = null, array $topNames = []): array
    {
        // Preserve legacy grouping by month + name only (merge same name across categories) for BC with existing tests.
        $bucketExpr = $this->bucketExpression('jp.collected_at', AnalysisPeriod::Month);

        $rows = DB::table('job_posting_items as i')
            ->join('job_postings as jp', 'jp.id', '=', 'i.job_posting_id')
            ->where('jp.status', 'confirmed')
            ->where('jp.user_id', $user->id)
            ->when($category, fn ($q) => $q->where('i.category', $category->value))
            ->when($from, fn ($q) => $q->where('jp.collected_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('jp.collected_at', '<', $to))
            ->when($topNames !== [], fn ($q) => $q->whereIn('i.name', $topNames))
            ->selectRaw("{$bucketExpr} as month, i.name, COUNT(DISTINCT jp.id) as total")
            ->groupBy('month', 'i.name')
            ->orderBy('month')
            ->orderByDesc('total')
            ->orderBy('i.name')
            ->get()
            ->map(fn ($row) => [
                'month' => $row->month,
                'name' => $row->name,
                'total' => (int) $row->total,
            ])
            ->all();

        return $rows;
    }

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

    public static function monthStart(?string $month): ?Carbon
    {
        if (blank($month)) {
            return null;
        }

        return Carbon::createFromFormat('Y-m', $month)?->startOfMonth();
    }

    public static function monthLabel(string $month): string
    {
        return Carbon::createFromFormat('Y-m', $month)?->translatedFormat('M/Y') ?? $month;
    }

    public function periodHasEnoughData(User $user, AnalysisPeriod $period, ?ConceptCategory $category = null): bool
    {
        $buckets = $this->periodBuckets($user, $period, $category);
        if ($buckets === []) {
            return false;
        }

        if ($period === AnalysisPeriod::Month) {
            $observed = 0;
            foreach ($buckets as $b) {
                if ($b['observed_count'] > 0) {
                    $observed++;
                }
            }

            return $observed >= $period->minimumCompleteBuckets();
        }

        $completeWithData = 0;
        foreach ($buckets as $b) {
            if ($b['is_complete'] && $b['observed_count'] > 0) {
                $completeWithData++;
            }
        }

        return $completeWithData >= $period->minimumCompleteBuckets();
    }

    public function periodInsufficientReason(User $user, AnalysisPeriod $period, ?ConceptCategory $category = null): ?string
    {
        $buckets = $this->periodBuckets($user, $period, $category);
        if ($buckets === []) {
            return 'empty';
        }
        if ($this->periodHasEnoughData($user, $period, $category)) {
            return null;
        }

        return 'insufficient';
    }

    /**
     * Comparação por período: janelas de calendário completas.
     * Mantém compatibilidade de forma com MarketTrendsPage::comparison().
     *
     * @return array<int, array{name: string, category: string, previous: int, current: int, delta_abs: int, delta_rel: float|null, trend: string}>
     */
    public function periodComparison(User $user, AnalysisPeriod $period, ?ConceptCategory $category = null): array
    {
        [$periodAFrom, $periodBFrom, $periodBTo] = $this->comparisonWindows($period);

        $aFrom = $periodAFrom->toDateTimeString();
        $bFrom = $periodBFrom->toDateTimeString();
        $bTo = $periodBTo->toDateTimeString();

        // Current window must have at least one posting in scope; otherwise unavailable (empty table state is different)
        $currentTotal = $this->totalConfirmedForWindow($user, $category, $bFrom, $bTo);
        if ($currentTotal === 0) {
            return [];
        }

        // For non-month, previous window must also have data, otherwise comparison is not meaningful
        if ($period !== AnalysisPeriod::Month) {
            $prevTotal = $this->totalConfirmedForWindow($user, $category, $aFrom, $bFrom);
            if ($prevTotal === 0) {
                return [];
            }
        }

        $previous = collect($this->frequencies($user, $category, $aFrom, $bFrom))
            ->mapWithKeys(fn (array $row): array => [$row['category'].'|'.$row['name'] => $row['total']]);
        $current = collect($this->frequencies($user, $category, $bFrom, $bTo))
            ->mapWithKeys(fn (array $row): array => [$row['category'].'|'.$row['name'] => $row['total']]);

        // Also keep map for label? But frequencies already grouped by category+name
        $currentRows = $this->frequencies($user, $category, $bFrom, $bTo);

        // Build comparison keyed by category|name but return name + category
        $result = [];
        foreach ($currentRows as $row) {
            $key = $row['category'].'|'.$row['name'];
            $currentTotalItem = $row['total'];
            $previousTotalItem = (int) ($previous[$key] ?? 0);
            $deltaAbs = $currentTotalItem - $previousTotalItem;
            $deltaRel = $previousTotalItem > 0 ? round($deltaAbs / $previousTotalItem * 100, 1) : null;

            $result[] = [
                'name' => $row['name'],
                'category' => $row['category'],
                'previous' => $previousTotalItem,
                'current' => $currentTotalItem,
                'delta_abs' => $deltaAbs,
                'delta_rel' => $deltaRel,
                'trend' => $this->classifyTrend($deltaRel, $deltaAbs),
            ];
        }

        usort($result, fn (array $a, array $b): int => $b['current'] <=> $a['current']);

        return $result;
    }

    /**
     * @return array{previous: string, current: string}
     */
    public function periodComparisonLabels(AnalysisPeriod $period): array
    {
        [$periodAFrom, $periodBFrom, $periodBTo] = $this->comparisonWindows($period);

        // For month, mimic existing translatedFormat behavior
        if ($period === AnalysisPeriod::Month) {
            $fmt = fn (CarbonImmutable $date): string => mb_strtolower($date->setTimezone($this->appTimezone())->translatedFormat('M/y'), 'UTF-8');

            return [
                'previous' => $fmt($periodAFrom).' – '.$fmt($periodBFrom->subDays(1)),
                'current' => $fmt($periodBFrom).' – '.$fmt($periodBTo->subDays(1)),
            ];
        }

        if ($period === AnalysisPeriod::Week) {
            $prevLabel = $this->windowLabel($periodAFrom, $periodBFrom, $period);
            $currLabel = $this->windowLabel($periodBFrom, $periodBTo, $period);

            return ['previous' => $prevLabel, 'current' => $currLabel];
        }

        if ($period === AnalysisPeriod::Quarter) {
            $prevLabel = $this->windowLabel($periodAFrom, $periodBFrom, $period);
            $currLabel = $this->windowLabel($periodBFrom, $periodBTo, $period);

            return ['previous' => $prevLabel, 'current' => $currLabel];
        }

        // Year
        $prevLabel = $this->windowLabel($periodAFrom, $periodBFrom, $period);
        $currLabel = $this->windowLabel($periodBFrom, $periodBTo, $period);

        return ['previous' => $prevLabel, 'current' => $currLabel];
    }

    // ---- Helpers ----

    private function bucketExpression(string $column, AnalysisPeriod $period): string
    {
        $driver = DB::connection()->getDriverName();

        return match ($period) {
            AnalysisPeriod::Week => $driver === 'mysql'
                ? "DATE(DATE_SUB({$column}, INTERVAL WEEKDAY({$column}) DAY))"
                : "date({$column}, 'weekday 0', '-6 days')",
            AnalysisPeriod::Month => $driver === 'mysql'
                ? "DATE_FORMAT({$column}, '%Y-%m')"
                : "strftime('%Y-%m', {$column})",
            AnalysisPeriod::Quarter => $driver === 'mysql'
                ? "CONCAT(YEAR({$column}), '-Q', QUARTER({$column}))"
                : "strftime('%Y', {$column}) || '-Q' || case when cast(strftime('%m', {$column}) as integer) between 1 and 3 then '1' when cast(strftime('%m', {$column}) as integer) between 4 and 6 then '2' when cast(strftime('%m', {$column}) as integer) between 7 and 9 then '3' else '4' end",
            AnalysisPeriod::Year => $driver === 'mysql'
                ? "DATE_FORMAT({$column}, '%Y')"
                : "strftime('%Y', {$column})",
        };
    }

    private function bucketKeyToStart(string $key, AnalysisPeriod $period): CarbonImmutable
    {
        $tz = $this->appTimezone();

        return match ($period) {
            AnalysisPeriod::Week => CarbonImmutable::createFromFormat('Y-m-d', $key, $tz)?->startOfDay() ?? CarbonImmutable::parse($key, $tz)->startOfDay(),
            AnalysisPeriod::Month => CarbonImmutable::createFromFormat('Y-m', $key, $tz)?->startOfMonth()->startOfDay() ?? CarbonImmutable::parse($key, $tz)->startOfMonth()->startOfDay(),
            AnalysisPeriod::Quarter => $this->quarterKeyToStart($key, $tz),
            AnalysisPeriod::Year => CarbonImmutable::createFromFormat('Y', $key, $tz)?->startOfYear()->startOfDay() ?? CarbonImmutable::parse($key, $tz)->startOfYear()->startOfDay(),
        };
    }

    private function quarterKeyToStart(string $key, string $tz): CarbonImmutable
    {
        // Format YYYY-Qn
        if (preg_match('/^(\d{4})-Q([1-4])$/', $key, $m)) {
            $year = (int) $m[1];
            $q = (int) $m[2];
            $month = ($q - 1) * 3 + 1;

            return CarbonImmutable::create($year, $month, 1, 0, 0, 0, $tz)->startOfDay();
        }

        return CarbonImmutable::parse($key, $tz)->startOfDay();
    }

    private function bucketStartToKey(CarbonImmutable $start, AnalysisPeriod $period): string
    {
        return match ($period) {
            AnalysisPeriod::Week => $start->format('Y-m-d'),
            AnalysisPeriod::Month => $start->format('Y-m'),
            AnalysisPeriod::Quarter => $start->format('Y').'-Q'.((int) ceil($start->month / 3)),
            AnalysisPeriod::Year => $start->format('Y'),
        };
    }

    private function bucketStartToLabel(CarbonImmutable $start, AnalysisPeriod $period): string
    {
        return match ($period) {
            AnalysisPeriod::Week => 'sem '.ltrim($start->format('W'), '0').'/'.$start->format('y'),
            AnalysisPeriod::Month => $start->translatedFormat('M/Y'),
            AnalysisPeriod::Quarter => 'T'.((int) ceil($start->month / 3)).'/'.$start->format('y'),
            AnalysisPeriod::Year => $start->format('Y'),
        };
    }

    private function nextBucketStart(CarbonImmutable $start, AnalysisPeriod $period): CarbonImmutable
    {
        return match ($period) {
            AnalysisPeriod::Week => $start->addWeeks(1),
            AnalysisPeriod::Month => $start->addMonthsNoOverflow(1)->startOfMonth(),
            AnalysisPeriod::Quarter => $start->addMonthsNoOverflow(3)->startOfMonth(),
            AnalysisPeriod::Year => $start->addYearsNoOverflow(1)->startOfYear(),
        };
    }

    private function currentBucketStart(AnalysisPeriod $period): CarbonImmutable
    {
        $tz = $this->appTimezone();
        $now = CarbonImmutable::now($tz);

        return match ($period) {
            AnalysisPeriod::Week => $now->startOfWeek(Carbon::MONDAY)->startOfDay(),
            AnalysisPeriod::Month => $now->startOfMonth()->startOfDay(),
            AnalysisPeriod::Quarter => $now->startOfQuarter()->startOfDay(),
            AnalysisPeriod::Year => $now->startOfYear()->startOfDay(),
        };
    }

    private function appTimezone(): string
    {
        return config('app.timezone', 'UTC');
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: CarbonImmutable}
     */
    private function comparisonWindows(AnalysisPeriod $period): array
    {
        $tz = $this->appTimezone();
        $now = CarbonImmutable::now($tz);

        return match ($period) {
            AnalysisPeriod::Week => $this->weekComparisonWindows($now),
            AnalysisPeriod::Month => $this->monthComparisonWindows($now),
            AnalysisPeriod::Quarter => $this->quarterComparisonWindows($now),
            AnalysisPeriod::Year => $this->yearComparisonWindows($now),
        };
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: CarbonImmutable}
     */
    private function weekComparisonWindows(CarbonImmutable $now): array
    {
        $currentWeekStart = $now->startOfWeek(Carbon::MONDAY)->startOfDay();
        $bTo = $currentWeekStart;
        $bFrom = $bTo->subWeeks(2);
        $aFrom = $bFrom->subWeeks(2);

        return [$aFrom, $bFrom, $bTo];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: CarbonImmutable}
     */
    private function monthComparisonWindows(CarbonImmutable $now): array
    {
        $bTo = $now->startOfMonth()->startOfDay();
        $bFrom = $bTo->subMonths(3);
        $aFrom = $bFrom->subMonths(3);

        return [$aFrom, $bFrom, $bTo];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: CarbonImmutable}
     */
    private function quarterComparisonWindows(CarbonImmutable $now): array
    {
        $currentQuarterStart = $now->startOfQuarter()->startOfDay();
        $bTo = $currentQuarterStart;
        $bFrom = $bTo->subMonths(3);
        $aFrom = $bFrom->subMonths(3);

        return [$aFrom, $bFrom, $bTo];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: CarbonImmutable}
     */
    private function yearComparisonWindows(CarbonImmutable $now): array
    {
        $bTo = $now->startOfYear()->startOfDay();
        $bFrom = $bTo->subYears(1);
        $aFrom = $bFrom->subYears(1);

        return [$aFrom, $bFrom, $bTo];
    }

    private function windowLabel(CarbonImmutable $from, CarbonImmutable $toExclusive, AnalysisPeriod $period): string
    {
        $tz = $this->appTimezone();
        $from = $from->setTimezone($tz);
        $toLast = $toExclusive->subDays(1)->setTimezone($tz);

        return match ($period) {
            AnalysisPeriod::Week => $this->weekWindowLabel($from, $toLast),
            AnalysisPeriod::Month => mb_strtolower($from->translatedFormat('M/y'), 'UTF-8').' – '.mb_strtolower($toLast->translatedFormat('M/y'), 'UTF-8'),
            AnalysisPeriod::Quarter => 'T'.((int) ceil($from->month / 3)).'/'.$from->format('y'),
            AnalysisPeriod::Year => $from->format('Y'),
        };
    }

    private function weekWindowLabel(CarbonImmutable $from, CarbonImmutable $toLast): string
    {
        $wFrom = ltrim($from->format('W'), '0');
        $wTo = ltrim($toLast->format('W'), '0');
        $yFrom = $from->format('y');
        $yTo = $toLast->format('y');

        if ($yFrom === $yTo) {
            if ($wFrom === $wTo) {
                return 'sem '.$wFrom.'/'.$yFrom;
            }

            return 'sem '.$wFrom.'-'.$wTo.'/'.$yFrom;
        }

        return 'sem '.$wFrom.'/'.$yFrom.' – sem '.$wTo.'/'.$yTo;
    }

    private function classifyTrend(?float $deltaRel, int $deltaAbs): string
    {
        if ($deltaRel === null) {
            return $deltaAbs > 0 ? 'nova' : 'estável';
        }

        return match (true) {
            $deltaRel >= 20 => 'subiu',
            $deltaRel <= -20 => 'desceu',
            default => 'estável',
        };
    }

    private function totalConfirmedForWindow(User $user, ?ConceptCategory $category, string $from, string $to): int
    {
        if ($category === null) {
            return $this->totalConfirmed($user, $from, $to);
        }

        return DB::table('job_postings as jp')
            ->join('job_posting_items as i', 'i.job_posting_id', '=', 'jp.id')
            ->where('jp.status', 'confirmed')
            ->where('jp.user_id', $user->id)
            ->where('i.category', $category->value)
            ->where('jp.collected_at', '>=', $from)
            ->where('jp.collected_at', '<', $to)
            ->distinct()
            ->count('jp.id');
    }

    /**
     * Public label helper for a bucket key (for table headers).
     */
    public function periodLabel(string $key, AnalysisPeriod $period): string
    {
        $start = $this->bucketKeyToStart($key, $period);

        return $this->bucketStartToLabel($start, $period);
    }

    /**
     * @return array<string, string>
     */
    public function periodComparisonLabelsFor(AnalysisPeriod $period): array
    {
        return $this->periodComparisonLabels($period);
    }
}
