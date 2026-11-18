<?php

namespace App\Services;

use App\Enums\DataAvailability;
use App\Support\TransactionAggregation\AggregationFilter;
use App\Support\TransactionAggregation\Ratio;
use App\Support\TransactionAggregation\TransactionRecord;
use Carbon\CarbonImmutable;

/**
 * Reads comparison values. An equivalent-hour comparison (a partial period) reads the stored
 * dashboard_snapshots payload at that cutoff; a full-day period uses the latest stored state.
 * A missing snapshot is never rebuilt from the latest status.
 */
class ComparisonSnapshotService
{
    public const DATASET_TRANSACTIONS = 'transactions';

    private const DERIVED_KEYS = ['conversion_percentage', 'is_provisional', 'average_order_value'];

    public function __construct(private readonly TransactionAggregationService $aggregations) {}

    /**
     * @param  iterable<TransactionRecord|array<string, mixed>>  $rows  The latest stored transactions.
     * @param  iterable<array<string, mixed>>  $snapshots  dashboard_snapshots rows.
     * @return array<string, mixed>
     */
    public function compare(iterable $rows, iterable $snapshots, AggregationFilter $filter): array
    {
        return match ($filter->availability()) {
            DataAvailability::Partial => $this->read(
                $snapshots,
                $filter->platformIds,
                self::DATASET_TRANSACTIONS,
                $filter->dateFrom,
                $filter->dateTo,
                $filter->cutoff(),
            ),
            DataAvailability::Complete => $this->latest($rows, $filter),
            DataAvailability::Unavailable => $this->unavailable(null, null),
        };
    }

    /**
     * Combines the snapshot of every selected platform at exactly the requested cutoff.
     * Only complete snapshots may be compared: a missing or incomplete snapshot on any selected
     * platform makes the comparison unavailable, without values.
     *
     * @param  iterable<array<string, mixed>>  $snapshots
     * @param  list<int>  $platformIds
     * @return array<string, mixed>
     */
    public function read(
        iterable $snapshots,
        array $platformIds,
        string $dataset,
        CarbonImmutable|string $dateFrom,
        CarbonImmutable|string $dateTo,
        CarbonImmutable|string $cutoffAt,
    ): array {
        $dateFrom = $this->wib($dateFrom)->toDateString();
        $dateTo = $this->wib($dateTo)->toDateString();
        $cutoffAt = $this->wib($cutoffAt);
        $latest = [];

        foreach ($snapshots as $snapshot) {
            if (! in_array((int) $snapshot['platform_id'], $platformIds, true)
                || $snapshot['dataset'] !== $dataset
                || $this->wib($snapshot['date_from'])->toDateString() !== $dateFrom
                || $this->wib($snapshot['date_to'])->toDateString() !== $dateTo
                || ! $this->wib($snapshot['cutoff_at'])->eq($cutoffAt)) {
                continue;
            }

            $current = $latest[(int) $snapshot['platform_id']] ?? null;

            if ($current === null || $this->wib($snapshot['recorded_at'])->gt($this->wib($current['recorded_at']))) {
                $latest[(int) $snapshot['platform_id']] = $snapshot;
            }
        }

        $incomplete = array_filter($latest, fn (array $snapshot) => ! $snapshot['is_complete']);

        if ($platformIds === [] || count($latest) !== count(array_unique($platformIds)) || $incomplete !== []) {
            return $this->unavailable($cutoffAt, 'snapshot');
        }

        return [
            'source' => 'snapshot',
            'availability' => DataAvailability::Complete->value,
            'cutoff_at' => $cutoffAt->toIso8601String(),
            'as_of' => $cutoffAt->toIso8601String(),
            'values' => $this->withDerivedValues(array_reduce(
                $latest,
                fn (?array $carry, array $snapshot) => $carry === null ? $snapshot['payload'] : $this->sum($carry, $snapshot['payload']),
            )),
        ];
    }

    /**
     * @param  iterable<TransactionRecord|array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function latest(iterable $rows, AggregationFilter $filter): array
    {
        $aggregate = $this->aggregations->aggregate($rows, $filter);

        return [
            'source' => 'latest',
            'availability' => $aggregate['availability'],
            'cutoff_at' => $aggregate['as_of'],
            'as_of' => $aggregate['as_of'],
            'values' => $aggregate['metrics'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function unavailable(?CarbonImmutable $cutoffAt, ?string $source): array
    {
        return [
            'source' => $source,
            'availability' => DataAvailability::Unavailable->value,
            'cutoff_at' => $cutoffAt?->toIso8601String(),
            'as_of' => null,
            'values' => null,
        ];
    }

    /**
     * Adds per-platform counts and amounts; ratios are recomputed afterwards, never summed.
     *
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     * @return array<string, mixed>
     */
    private function sum(array $left, array $right): array
    {
        foreach ($right as $key => $value) {
            if (in_array($key, self::DERIVED_KEYS, true)) {
                continue;
            }

            $left[$key] = match (true) {
                is_array($value) => $this->sum($left[$key] ?? [], $value),
                is_int($value) || is_float($value) => ($left[$key] ?? 0) + $value,
                default => $left[$key] ?? $value,
            };
        }

        return $left;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function withDerivedValues(array $values): array
    {
        if (array_key_exists('purchase_attempts', $values)) {
            $values['conversion_percentage'] = Ratio::percentage($values['success_purchases'] ?? 0, $values['purchase_attempts']);
            $values['is_provisional'] = ($values['unknown_status_count'] ?? 0) > 0;
        }

        if (array_key_exists('success_payments', $values)) {
            $values['average_order_value'] = Ratio::average($values['revenue'] ?? 0, $values['success_payments']);
        }

        return $values;
    }

    private function wib(CarbonImmutable|string $value): CarbonImmutable
    {
        $value = is_string($value) ? CarbonImmutable::parse($value, TransactionRecord::TIMEZONE) : $value;

        return $value->setTimezone(TransactionRecord::TIMEZONE);
    }
}
