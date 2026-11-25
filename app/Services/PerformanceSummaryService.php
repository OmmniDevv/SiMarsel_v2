<?php

namespace App\Services;

use App\Repositories\PerformanceRepository;
use App\Services\Concerns\ComparesWithPreviousValue;
use App\Support\PerformanceFilter;

class PerformanceSummaryService
{
    use ComparesWithPreviousValue;

    public function __construct(
        private PerformanceRepository $performanceRepository,
        private DownloaderAggregationService $downloaderAggregation,
        private SalesSummaryService $salesSummaryService,
    ) {}

    /**
     * Build the KPI summary for the filter and compare it with the filter's previous period.
     * The value is always the whole active period. When the comparison cannot be shown, the previous value,
     * change, and trend are null. When some active days have no equivalent date, the change only uses the
     * days that exist on both sides.
     *
     * With $downloaderFilter (the same preset resolved on the downloaders metadata), Total Downloader and the
     * Konversi denominator come from the shared Downloader aggregation, with the same totals, availability, and
     * change as /trend/downloaders. An unknown total is null, never 0. Without it, the older daily sum is kept.
     *
     * @return array<string, mixed>
     */
    public function summarize(PerformanceFilter $filter, ?PerformanceFilter $downloaderFilter = null): array
    {
        $shared = $downloaderFilter?->period === null ? null : $this->downloaderAggregation->periodTotals($downloaderFilter);
        $downloaders = fn (PerformanceFilter $scope, ?int $sharedValue) => $shared === null
            ? $this->performanceRepository->totalDownloaders($scope)
            : $sharedValue;

        $current = $this->metrics($filter, $downloaders($filter, $shared['active']['value'] ?? null));
        $previous = $filter->canCompare()
            ? $this->metrics($filter->previous(), $downloaders($filter->previous(), $shared['comparison']['value'] ?? null))
            : null;
        [$changeCurrent, $changePrevious] = $previous === null ? [null, null] : $this->changeBasisMetrics($filter, $current, $previous, $downloaders, $shared);

        $isEmpty = $current['total_transactions'] === 0 && in_array($current['total_downloaders'], [0, null], true);
        $kpis = collect($current)
            ->map(fn (int|float|null $value, string $key) => $previous === null
                ? ['value' => $value, 'previous_value' => null, 'change_percentage' => null, 'trend' => null]
                : [
                    'value' => $value,
                    'previous_value' => $previous[$key],
                    ...$this->changeFromPrevious($changeCurrent[$key], $changePrevious[$key]),
                ])
            ->all();

        if ($shared !== null) {
            $kpis['total_downloaders'] = [
                'value' => $shared['active']['value'],
                'previous_value' => $shared['comparison']['value'] ?? null,
                'change_percentage' => $shared['change']['change_percentage'],
                'trend' => $shared['change']['trend'],
            ];
        }

        return [
            'filter' => $filter->toArray(),
            'previous_period' => $filter->previousToArray(),
            'is_empty' => $isEmpty,
            'message' => $isEmpty ? 'Data tidak tersedia untuk filter yang dipilih.' : null,
            'kpis' => $kpis,
            'sales_summary' => $filter->period === null ? null : $this->salesSummaryService->summarize($filter->period),
        ];
    }

    /**
     * @param  array<string, int|float|null>  $current
     * @param  array<string, int|float|null>  $previous
     * @param  callable(PerformanceFilter, ?int): ?int  $downloaders
     * @param  array<string, mixed>|null  $shared
     * @return array{0: array<string, int|float|null>, 1: array<string, int|float|null>}
     */
    private function changeBasisMetrics(PerformanceFilter $filter, array $current, array $previous, callable $downloaders, ?array $shared): array
    {
        $basis = $filter->changeBasisFilters();

        return $basis === null
            ? [$current, $previous]
            : [
                $this->metrics($basis[0], $downloaders($basis[0], $shared['change_basis']['active'] ?? null)),
                $this->metrics($basis[1], $downloaders($basis[1], $shared['change_basis']['comparison'] ?? null)),
            ];
    }

    /**
     * @return array<string, int|float|null>
     */
    private function metrics(PerformanceFilter $filter, ?int $downloaders): array
    {
        $totals = $this->performanceRepository->transactionTotals($filter);

        return [
            'total_revenue' => round($totals['revenue'], 2),
            'total_transactions' => $totals['transactions'],
            'total_downloaders' => $downloaders,
            'unique_users' => $this->performanceRepository->countUniqueCustomers($filter),
            'new_users' => $totals['new_users'],
            'repeat_orders' => $totals['repeat_orders'],
            'average_order_value' => $totals['transactions'] > 0
                ? round($totals['revenue'] / $totals['transactions'], 2)
                : null,
            'conversion_rate' => $downloaders > 0
                ? round($totals['transactions'] / $downloaders * 100, 2)
                : null,
        ];
    }
}
