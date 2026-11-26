<?php

namespace App\Services;

use App\Enums\DataAvailability;
use App\Enums\PeriodStateBasis;
use App\Enums\TransactionDateBasis;
use App\Repositories\DashboardTransactionRepository;
use App\Services\Concerns\ComparesWithPreviousValue;
use App\Support\Period\PeriodWindow;
use App\Support\Period\ResolvedPeriod;
use App\Support\TransactionAggregation\AggregationFilter;
use App\Support\TransactionAggregation\Ratio;
use App\Support\TransactionAggregation\TransactionRecord;
use Carbon\CarbonImmutable;

/**
 * The seven metrics of the Ringkasan Sales: Omzet and Transaksi Berhasil (Hasil Penjualan, paid date),
 * Total Transaksi and Konversi Transaksi (Aktivitas Transaksi, created date), and the supporting AOV and
 * Pembeli Unik (paid date) and Downloader. Every transaction value comes from the shared transaction aggregation,
 * so claims, statuses, dates, and group members follow the same rules as the trends. Downloader is read as is
 * from the shared Downloader aggregation (the same totals as /trend/downloaders), never aggregated again here.
 *
 * The active value always covers the whole active period. When some active days have no equivalent date, the
 * change uses only the matched days on both sides and keeps those values next to the full active value.
 * A value that is not known yet is null, never 0.
 */
class SalesSummaryService
{
    use ComparesWithPreviousValue;

    public const UNIT_CURRENCY = 'currency';

    public const UNIT_COUNT = 'count';

    public const UNIT_PERCENTAGE = 'percentage';

    public const CHANGE_PERCENT = 'percent';

    public const CHANGE_PERCENTAGE_POINT = 'percentage_point';

    private const GROUPS = [
        ['key' => 'sales_result', 'label' => 'Hasil Penjualan', 'date_basis' => 'paid'],
        ['key' => 'transaction_activity', 'label' => 'Aktivitas Transaksi', 'date_basis' => 'created'],
        ['key' => 'supporting', 'label' => 'Metrik Pendukung', 'date_basis' => null],
    ];

    /**
     * Metric key => [label, group, date basis, unit, aggregation value].
     */
    private const METRICS = [
        'revenue' => ['Omzet', 'sales_result', 'paid', self::UNIT_CURRENCY, 'revenue'],
        'success_payments' => ['Transaksi Berhasil', 'sales_result', 'paid', self::UNIT_COUNT, 'success_payments'],
        'total_checkouts' => ['Total Transaksi', 'transaction_activity', 'created', self::UNIT_COUNT, 'total_checkouts'],
        'conversion' => ['Konversi Transaksi', 'transaction_activity', 'created', self::UNIT_PERCENTAGE, 'conversion_percentage'],
        'average_order_value' => ['Rata-rata Nilai Order', 'supporting', 'paid', self::UNIT_CURRENCY, 'average_order_value'],
        'unique_buyers' => ['Pembeli Unik', 'supporting', 'paid', self::UNIT_COUNT, 'unique_buyers'],
    ];

    private const DOWNLOADERS = 'downloaders';

    /** The Total Transaksi breakdown shown under its value. */
    private const CHECKOUT_BREAKDOWN = ['purchase_attempts', 'success_purchases', 'unsuccessful_purchases', 'unknown_status_count', 'claim_checkouts'];

    private const DATE_BASIS_LABELS = [
        'paid' => 'Berdasarkan tanggal pembayaran',
        'created' => 'Berdasarkan tanggal transaksi dibuat',
    ];

    public function __construct(
        private DashboardTransactionRepository $dashboardTransactions,
        private TransactionAggregationService $aggregations,
        private ComparisonSnapshotService $comparisonSnapshots,
    ) {}

    /**
     * $downloaderPeriod is the same selection resolved on the downloaders metadata and $downloaderTotals its
     * DownloaderAggregationService::periodTotals(). Without them, the Downloader metric stays unavailable.
     *
     * @param  array<string, mixed>|null  $downloaderTotals
     * @return array<string, mixed>
     */
    public function summarize(ResolvedPeriod $period, ?ResolvedPeriod $downloaderPeriod = null, ?array $downloaderTotals = null): array
    {
        $platformIds = $this->dashboardTransactions->platformIds($period->platforms);
        $activeRecords = $this->records($platformIds, $period->active);
        $active = $this->latestSide($activeRecords, $platformIds, $period->active);

        $comparisonRecords = [];
        $comparison = null;

        if ($period->comparison !== null) {
            $comparisonRecords = $this->records($platformIds, $period->comparison);
            $comparison = $period->comparison->stateBasis === PeriodStateBasis::AtCutoff
                ? $this->recordedSide($platformIds, $period->comparison)
                : $this->latestSide($comparisonRecords, $platformIds, $period->comparison);
        }

        [$activeBasis, $comparisonBasis] = $this->changeBasisValues($period, $platformIds, $activeRecords, $comparisonRecords, $active, $comparison);
        $isTrimmed = $period->changeBasis?->isTrimmed ?? false;

        return [
            'groups' => array_map(fn (array $group) => [
                ...$group,
                'date_basis_label' => self::DATE_BASIS_LABELS[$group['date_basis']] ?? null,
            ], self::GROUPS),
            'metrics' => [
                ...array_map(
                    fn (string $key) => $this->metric($key, $active, $comparison, $activeBasis, $comparisonBasis, $isTrimmed),
                    array_keys(self::METRICS),
                ),
                $this->downloaderMetric($downloaderPeriod, $downloaderTotals),
            ],
        ];
    }

    /**
     * The seventh metric. The active total stays visible when the comparison is 0 or unknown; the relative change
     * is then null. The change uses the matched days only when the Downloader change basis is trimmed.
     *
     * @param  array<string, mixed>|null  $totals
     * @return array<string, mixed>
     */
    private function downloaderMetric(?ResolvedPeriod $period, ?array $totals): array
    {
        $side = fn (?array $value) => [
            'value' => $value['value'] ?? null,
            'availability' => $value['availability'] ?? DataAvailability::Unavailable->value,
            'as_of' => $value['as_of'] ?? null,
            'source' => $value['source'] ?? null,
        ];
        $hasComparison = $totals === null ? $period?->comparison !== null : $totals['comparison'] !== null;

        return [
            'key' => self::DOWNLOADERS,
            'label' => 'Downloader',
            'group' => 'supporting',
            'date_basis' => null,
            'date_basis_label' => null,
            'unit' => self::UNIT_COUNT,
            'active' => $side($totals['active'] ?? null),
            'comparison' => $hasComparison ? $side($totals['comparison'] ?? null) : null,
            'change' => [
                'value' => $totals['change']['change_percentage'] ?? null,
                'unit' => self::CHANGE_PERCENT,
                'trend' => $totals['change']['trend'] ?? null,
                'is_trimmed' => $period?->changeBasis?->isTrimmed ?? false,
                'active_value' => $totals['change_basis']['active'] ?? null,
                'comparison_value' => $totals['change_basis']['comparison'] ?? null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $active
     * @param  array<string, mixed>|null  $comparison
     * @param  array<string, mixed>|null  $activeBasis
     * @param  array<string, mixed>|null  $comparisonBasis
     * @return array<string, mixed>
     */
    private function metric(string $key, array $active, ?array $comparison, ?array $activeBasis, ?array $comparisonBasis, bool $isTrimmed): array
    {
        [$label, $group, $dateBasis, $unit, $value] = self::METRICS[$key];
        $changeUnit = $unit === self::UNIT_PERCENTAGE ? self::CHANGE_PERCENTAGE_POINT : self::CHANGE_PERCENT;
        $activeChangeValue = $this->value($activeBasis, $value);
        $comparisonChangeValue = $this->value($comparisonBasis, $value);

        return [
            'key' => $key,
            'label' => $label,
            'group' => $group,
            'date_basis' => $dateBasis,
            'date_basis_label' => self::DATE_BASIS_LABELS[$dateBasis],
            'unit' => $unit,
            'active' => $this->metricSide($key, $active),
            'comparison' => $comparison === null ? null : $this->metricSide($key, $comparison),
            'change' => [
                'value' => $this->change($changeUnit, $activeChangeValue, $comparisonChangeValue),
                'unit' => $changeUnit,
                'trend' => $activeChangeValue === null || $comparisonChangeValue === null
                    ? null
                    : $this->changeFromPrevious($activeChangeValue, $comparisonChangeValue)['trend'],
                'is_trimmed' => $isTrimmed,
                'active_value' => $activeChangeValue,
                'comparison_value' => $comparisonChangeValue,
            ],
        ];
    }

    /**
     * One side of a metric with the details its card shows next to the value.
     *
     * @param  array<string, mixed>  $side
     * @return array<string, mixed>
     */
    private function metricSide(string $key, array $side): array
    {
        $values = $side['values'];

        return [
            'value' => $this->value($values, self::METRICS[$key][4]),
            'availability' => $side['availability'],
            'as_of' => $side['as_of'],
            'source' => $side['source'],
            ...match ($key) {
                'revenue' => ['nominal_total' => $this->value($values, 'nominal_total')],
                'total_checkouts' => ['breakdown' => $values === null ? null : array_combine(
                    self::CHECKOUT_BREAKDOWN,
                    array_map(fn (string $item) => $this->value($values, $item), self::CHECKOUT_BREAKDOWN),
                )],
                'conversion' => [
                    'success_purchases' => $this->value($values, 'success_purchases'),
                    'purchase_attempts' => $this->value($values, 'purchase_attempts'),
                    'unknown_status_count' => $this->value($values, 'unknown_status_count'),
                    'is_provisional' => $values === null ? null : ($values['unknown_status_count'] ?? 0) > 0,
                    'provisional_label' => ($values['unknown_status_count'] ?? 0) > 0 ? 'Sementara' : null,
                ],
                default => [],
            },
        ];
    }

    /**
     * Konversi changes in percentage points; every other metric changes relative to its comparison value,
     * which is null when the comparison is 0 or not known.
     */
    private function change(string $unit, int|float|null $active, int|float|null $comparison): ?float
    {
        if ($unit === self::CHANGE_PERCENTAGE_POINT) {
            return $active === null || $comparison === null ? null : round($active - $comparison, 2);
        }

        return Ratio::change($active, $comparison);
    }

    /**
     * The values both sides of the change are calculated from: the whole periods, or only the matched days when
     * the change basis is trimmed. A recorded comparison can only give its whole period, so a trimmed recorded
     * side has no change values.
     *
     * @param  list<int>  $platformIds
     * @param  list<TransactionRecord>  $activeRecords
     * @param  list<TransactionRecord>  $comparisonRecords
     * @param  array<string, mixed>  $active
     * @param  array<string, mixed>|null  $comparison
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null}
     */
    private function changeBasisValues(
        ResolvedPeriod $period,
        array $platformIds,
        array $activeRecords,
        array $comparisonRecords,
        array $active,
        ?array $comparison,
    ): array {
        $basis = $period->changeBasis;

        if ($basis === null || $comparison === null || $platformIds === []) {
            return [null, null];
        }

        if (! $basis->isTrimmed) {
            return [$active['values'], $comparison['values']];
        }

        $comparisonValues = match (true) {
            $period->comparison->stateBasis !== PeriodStateBasis::AtCutoff => $this->rangeValues($comparisonRecords, $platformIds, $basis->comparisonRanges, $period->comparison),
            $this->coversWindow($basis->comparisonRanges, $period->comparison) => $comparison['values'],
            default => null,
        };

        return [$this->rangeValues($activeRecords, $platformIds, $basis->activeRanges, $period->active), $comparisonValues];
    }

    /**
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $ranges
     */
    private function coversWindow(array $ranges, PeriodWindow $window): bool
    {
        return count($ranges) === 1 && $ranges[0][0]->eq($window->startAt) && $ranges[0][1]->eq($window->endAt);
    }

    /**
     * Both date bases of the latest stored state over the whole window, limited by its data cutoff.
     *
     * @param  list<TransactionRecord>  $records
     * @param  list<int>  $platformIds
     * @return array{availability: string, as_of: string|null, source: string|null, values: array<string, mixed>|null}
     */
    private function latestSide(array $records, array $platformIds, PeriodWindow $window): array
    {
        if ($platformIds === [] || $window->availability === DataAvailability::Unavailable) {
            return $this->unavailable(null);
        }

        $aggregate = fn (TransactionDateBasis $basis) => $this->aggregations->aggregate(
            $records,
            AggregationFilter::forWindow($platformIds, $window->dateFrom, $window->dateTo->addDay(), $basis, $window->cutoffAt),
        );
        $paid = $aggregate(TransactionDateBasis::Paid);

        if ($paid['availability'] === DataAvailability::Unavailable->value) {
            return $this->unavailable(null);
        }

        return [
            'availability' => $paid['availability'],
            'as_of' => $paid['as_of'],
            'source' => 'latest',
            'values' => $this->rounded([...$aggregate(TransactionDateBasis::Created)['metrics'], ...$paid['metrics']]),
        ];
    }

    /**
     * An equivalent-hour comparison reads the transactions snapshot recorded at its cutoff, never the latest state.
     * A metric missing from the recorded payload stays null.
     *
     * @param  list<int>  $platformIds
     * @return array{availability: string, as_of: string|null, source: string|null, values: array<string, mixed>|null}
     */
    private function recordedSide(array $platformIds, PeriodWindow $window): array
    {
        if ($platformIds === [] || $window->availability === DataAvailability::Unavailable || $window->cutoffAt === null) {
            return $this->unavailable('snapshot');
        }

        $recorded = $this->comparisonSnapshots->read(
            $this->dashboardTransactions->periodSnapshots($platformIds, $window->dateFrom, $window->dateTo),
            $platformIds,
            ComparisonSnapshotService::DATASET_TRANSACTIONS,
            $window->dateFrom,
            $window->dateTo,
            $window->cutoffAt,
        );

        return [
            'availability' => $recorded['availability'],
            'as_of' => $recorded['as_of'],
            'source' => 'snapshot',
            'values' => $recorded['values'] === null ? null : $this->rounded($recorded['values']),
        ];
    }

    /**
     * Both date bases of the latest stored state over the given ranges of one window, counted as one scope.
     *
     * @param  list<TransactionRecord>  $records
     * @param  list<int>  $platformIds
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $ranges
     * @return array<string, mixed>|null
     */
    private function rangeValues(array $records, array $platformIds, array $ranges, PeriodWindow $window): ?array
    {
        $aggregate = fn (TransactionDateBasis $basis) => $this->aggregations->aggregateWindows($records, array_map(
            fn (array $range) => AggregationFilter::forWindow($platformIds, $range[0], $range[1], $basis, $window->cutoffAt),
            $ranges,
        ));

        $created = $aggregate(TransactionDateBasis::Created);
        $paid = $aggregate(TransactionDateBasis::Paid);

        return $created === null || $paid === null ? null : $this->rounded([...$created, ...$paid]);
    }

    /**
     * @param  list<int>  $platformIds
     * @return list<TransactionRecord>
     */
    private function records(array $platformIds, PeriodWindow $window): array
    {
        if ($platformIds === [] || $window->availability === DataAvailability::Unavailable) {
            return [];
        }

        return array_map(
            TransactionRecord::fromArray(...),
            $this->dashboardTransactions->contractRows($platformIds, $window->dateFrom, $window->dateTo->addDay()),
        );
    }

    /**
     * @param  array<string, mixed>|null  $values
     */
    private function value(?array $values, string $key): int|float|null
    {
        $value = $values[$key] ?? null;

        return is_int($value) || is_float($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function rounded(array $values): array
    {
        foreach (['revenue', 'nominal_total', 'unknown_status_nominal'] as $key) {
            if (is_int($values[$key] ?? null) || is_float($values[$key] ?? null)) {
                $values[$key] = round((float) $values[$key], 2);
            }
        }

        return $values;
    }

    /**
     * @return array{availability: string, as_of: null, source: string|null, values: null}
     */
    private function unavailable(?string $source): array
    {
        return ['availability' => DataAvailability::Unavailable->value, 'as_of' => null, 'source' => $source, 'values' => null];
    }
}
