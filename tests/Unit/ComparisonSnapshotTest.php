<?php

namespace Tests\Unit;

use App\Enums\MarkazTransactionStatus;
use App\Enums\TransactionDateBasis;
use App\Services\ComparisonSnapshotService;
use App\Services\TransactionAggregationService;
use App\Support\TransactionAggregation\AggregationFilter;
use PHPUnit\Framework\TestCase;

/**
 * FIX-02-BE-02: comparison values read from stored dashboard_snapshots at an equivalent hour,
 * and from the latest stored state for full-day periods. Snapshots are never rebuilt.
 */
class ComparisonSnapshotTest extends TestCase
{
    private const YESTERDAY = '2026-11-15';

    private const CUTOFF = '2026-11-15T14:00:00+07:00';

    private const END_OF_DAY = '2026-11-16T00:00:00+07:00';

    private ComparisonSnapshotService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ComparisonSnapshotService(new TransactionAggregationService);
    }

    public function test_ac02_the_equivalent_hour_reads_the_snapshot_and_the_full_day_reads_the_latest_state(): void
    {
        $rows = $this->purchases(success: 75, pending: 25);
        $snapshots = [$this->snapshot(1, ['total_checkouts' => 80, 'purchase_attempts' => 80, 'success_purchases' => 50])];

        $hour = $this->service->compare($rows, $snapshots, $this->filter(self::CUTOFF));
        $fullDay = $this->service->compare($rows, $snapshots, $this->filter(self::END_OF_DAY));

        $this->assertSame('snapshot', $hour['source']);
        $this->assertSame('complete', $hour['availability']);
        $this->assertSame(self::CUTOFF, $hour['as_of']);
        $this->assertSame(50, $hour['values']['success_purchases']);
        $this->assertSame(62.5, $hour['values']['conversion_percentage']);

        $this->assertSame('latest', $fullDay['source']);
        $this->assertSame(75, $fullDay['values']['success_purchases']);
        $this->assertSame(100, $fullDay['values']['total_checkouts']);
    }

    public function test_ac02_a_missing_snapshot_is_unavailable_and_not_rebuilt_from_the_latest_state(): void
    {
        $rows = $this->purchases(success: 75, pending: 25);

        $hour = $this->service->compare($rows, [], $this->filter(self::CUTOFF));
        $fullDay = $this->service->compare($rows, [], $this->filter(self::END_OF_DAY));

        $this->assertSame('unavailable', $hour['availability']);
        $this->assertNull($hour['values']);
        $this->assertNull($hour['as_of']);
        $this->assertSame(self::CUTOFF, $hour['cutoff_at']);
        $this->assertSame(75, $fullDay['values']['success_purchases']);
    }

    public function test_ac10_a_status_change_after_the_snapshot_does_not_change_the_equivalent_hour(): void
    {
        $rows = $this->purchases(success: 6, pending: 4);
        $rows[0]['effective_status'] = MarkazTransactionStatus::Failed->value;
        $snapshots = [$this->snapshot(1, ['total_checkouts' => 10, 'purchase_attempts' => 10, 'success_purchases' => 6])];

        $hour = $this->service->compare($rows, $snapshots, $this->filter(self::CUTOFF));
        $fullDay = $this->service->compare($rows, $snapshots, $this->filter(self::END_OF_DAY));

        $this->assertSame(60.0, $hour['values']['conversion_percentage']);
        $this->assertSame(self::CUTOFF, $hour['as_of']);
        $this->assertSame(50.0, $fullDay['values']['conversion_percentage']);
        $this->assertSame(1, $fullDay['values']['status_breakdown']['failed']['count']);

        $removed = $this->service->compare($rows, [], $this->filter(self::CUTOFF));

        $this->assertSame('unavailable', $removed['availability']);
        $this->assertNull($removed['values']);
    }

    public function test_ac10_selected_platforms_are_summed_and_the_ratio_is_recomputed(): void
    {
        $snapshots = [
            $this->snapshot(1, ['total_checkouts' => 10, 'purchase_attempts' => 10, 'success_purchases' => 6]),
            $this->snapshot(2, ['total_checkouts' => 10, 'purchase_attempts' => 10, 'success_purchases' => 2, 'unknown_status_count' => 1]),
        ];

        $both = $this->service->read($snapshots, [1, 2], 'transactions', self::YESTERDAY, self::YESTERDAY, self::CUTOFF);
        $missingPlatform = $this->service->read($snapshots, [1, 3], 'transactions', self::YESTERDAY, self::YESTERDAY, self::CUTOFF);

        $this->assertSame(20, $both['values']['purchase_attempts']);
        $this->assertSame(8, $both['values']['success_purchases']);
        $this->assertSame(40.0, $both['values']['conversion_percentage']);
        $this->assertTrue($both['values']['is_provisional']);
        $this->assertSame('unavailable', $missingPlatform['availability']);
    }

    public function test_ac10_only_a_snapshot_at_the_requested_cutoff_is_read(): void
    {
        $snapshots = [
            $this->snapshot(1, ['success_purchases' => 4], ['cutoff_at' => '2026-11-15T13:00:00+07:00']),
            $this->snapshot(1, ['success_purchases' => 5], ['recorded_at' => '2026-11-15T14:05:00+07:00']),
            $this->snapshot(1, ['success_purchases' => 6], ['recorded_at' => '2026-11-15T14:09:00+07:00']),
            $this->snapshot(1, ['success_purchases' => 9], ['dataset' => 'downloaders']),
        ];

        $read = $this->service->read($snapshots, [1], 'transactions', self::YESTERDAY, self::YESTERDAY, self::CUTOFF);

        $this->assertSame(6, $read['values']['success_purchases']);
    }

    public function test_ac10_an_incomplete_snapshot_is_returned_with_a_partial_marker(): void
    {
        $snapshots = [$this->snapshot(1, ['success_purchases' => 6], ['is_complete' => false])];

        $read = $this->service->read($snapshots, [1], 'transactions', self::YESTERDAY, self::YESTERDAY, self::CUTOFF);

        $this->assertSame('partial', $read['availability']);
        $this->assertSame(6, $read['values']['success_purchases']);
    }

    public function test_unknown_coverage_makes_the_comparison_unavailable(): void
    {
        $comparison = $this->service->compare($this->purchases(success: 1, pending: 0), [], $this->filter(null));

        $this->assertSame('unavailable', $comparison['availability']);
        $this->assertNull($comparison['values']);
    }

    private function filter(?string $completeUntil): AggregationFilter
    {
        return new AggregationFilter([1], self::YESTERDAY, self::YESTERDAY, TransactionDateBasis::Created, $completeUntil);
    }

    private function snapshot(int $platformId, array $payload, array $overrides = []): array
    {
        return array_merge([
            'platform_id' => $platformId,
            'dataset' => 'transactions',
            'date_from' => self::YESTERDAY,
            'date_to' => self::YESTERDAY,
            'cutoff_at' => self::CUTOFF,
            'is_complete' => true,
            'payload' => array_merge([
                'total_checkouts' => 10,
                'purchase_attempts' => 10,
                'claim_checkouts' => 0,
                'success_purchases' => 0,
                'unknown_status_count' => 0,
                'conversion_percentage' => null,
                'is_provisional' => false,
            ], $payload),
            'recorded_at' => '2026-11-15T14:07:00+07:00',
        ], $overrides);
    }

    /**
     * Purchases created before the 14.00 cutoff, so rebuilding the snapshot from them would differ from the stored one.
     */
    private function purchases(int $success, int $pending): array
    {
        $rows = [];

        foreach (range(1, $success + $pending) as $i) {
            $isSuccess = $i <= $success;

            $rows[] = [
                'platform_id' => 1,
                'markaz_transaction_id' => "TRX-{$i}",
                'markaz_ref_id' => null,
                'markaz_customer_id' => 1000 + $i,
                'markaz_created_at' => '2026-11-15T09:00:00+07:00',
                'markaz_paid_at' => $isSuccess ? '2026-11-15T09:30:00+07:00' : null,
                'paid_date' => $isSuccess ? self::YESTERDAY : null,
                'paid_time' => $isSuccess ? '09:30:00' : null,
                'synced_at' => '2026-11-15T20:00:00+07:00',
                'source_status' => $isSuccess ? MarkazTransactionStatus::Success->value : MarkazTransactionStatus::Pending->value,
                'effective_status' => null,
                'is_claim_coupon' => false,
                'product_price' => 100000,
                'voucher_discount' => 0,
                'admin_fee' => 0,
                'nominal' => 100000,
                'net_nominal' => 100000,
            ];
        }

        return $rows;
    }
}
