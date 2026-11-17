<?php

namespace Tests\Unit;

use App\Enums\MarkazTransactionStatus;
use App\Enums\TransactionDateBasis;
use App\Services\TransactionAggregationService;
use App\Support\TransactionAggregation\AggregationFilter;
use App\Support\TransactionAggregation\Ratio;
use App\Support\TransactionAggregation\TransactionRecord;
use PHPUnit\Framework\TestCase;

/**
 * FIX-02-BE-02: shared aggregations on stored rows shaped like the FIX-02-BE-01 contract.
 * Every row is an explicit fixture value; nothing calls Markaz or rebuilds stored state.
 */
class TransactionAggregationTest extends TestCase
{
    private TransactionAggregationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new TransactionAggregationService;
    }

    public function test_ac01_a_cross_midnight_transaction_lands_on_its_created_day_and_its_paid_day(): void
    {
        $rows = [$this->success('TRX-1', '2026-11-11 00:10:00', [
            'markaz_created_at' => '2026-11-10T23:50:00+07:00',
            'synced_at' => '2026-11-13T08:00:00+07:00',
            'net_nominal' => 180000,
        ])];

        $created = $this->service->aggregateDaily($rows, $this->filter('2026-11-10', '2026-11-13', TransactionDateBasis::Created));
        $paid = $this->service->aggregateDaily($rows, $this->filter('2026-11-10', '2026-11-13', TransactionDateBasis::Paid));

        $this->assertSame([1, 0, 0, 0], array_map(fn (array $day) => $day['metrics']['total_checkouts'], $created));
        $this->assertSame([0, 1, 0, 0], array_map(fn (array $day) => $day['metrics']['success_payments'], $paid));
        $this->assertSame([0.0, 180000.0, 0.0, 0.0], array_map(fn (array $day) => $day['metrics']['revenue'], $paid));
        $this->assertSame(['2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13'], array_column($created, 'date_from'));
    }

    public function test_ac03_a_complete_period_without_transactions_returns_zero_and_an_empty_ratio(): void
    {
        $rows = [$this->success('TRX-OTHER-DAY', '2026-11-09 09:00:00', ['markaz_created_at' => '2026-11-09T08:00:00+07:00'])];

        $created = $this->service->aggregate($rows, $this->filter('2026-11-10', '2026-11-10', TransactionDateBasis::Created));
        $paid = $this->service->aggregate($rows, $this->filter('2026-11-10', '2026-11-10', TransactionDateBasis::Paid));

        $this->assertSame('complete', $created['availability']);
        $this->assertSame(0, $created['metrics']['total_checkouts']);
        $this->assertSame(0, $created['metrics']['purchase_attempts']);
        $this->assertNull($created['metrics']['conversion_percentage']);
        $this->assertFalse($created['metrics']['is_provisional']);
        $this->assertSame(0, $paid['metrics']['success_payments']);
        $this->assertSame(0.0, $paid['metrics']['revenue']);
        $this->assertNull($paid['metrics']['average_order_value']);
    }

    public function test_ac03_unknown_coverage_returns_null_instead_of_zero(): void
    {
        $rows = [$this->success('TRX-1', '2026-11-10 09:00:00')];

        $created = $this->service->aggregate($rows, $this->filter('2026-11-10', '2026-11-10', TransactionDateBasis::Created, null));
        $paid = $this->service->aggregate($rows, $this->filter('2026-11-10', '2026-11-10', TransactionDateBasis::Paid, '2026-11-10T00:00:00+07:00'));

        $this->assertSame('unavailable', $created['availability']);
        $this->assertNull($created['as_of']);
        $this->assertNull($created['metrics']['total_checkouts']);
        $this->assertNull($created['metrics']['conversion_percentage']);
        $this->assertNull($created['metrics']['status_breakdown']['success']['count']);
        $this->assertSame('unavailable', $paid['availability']);
        $this->assertNull($paid['metrics']['revenue']);
    }

    public function test_ac03_a_partial_cutoff_only_counts_events_before_it(): void
    {
        $rows = [
            $this->success('TRX-BEFORE', '2026-11-16 12:30:00', ['markaz_created_at' => '2026-11-16T12:00:00+07:00']),
            $this->success('TRX-AFTER', '2026-11-16 13:30:00', ['markaz_created_at' => '2026-11-16T13:10:00+07:00']),
        ];

        $created = $this->service->aggregate($rows, $this->filter('2026-11-16', '2026-11-16', TransactionDateBasis::Created, '2026-11-16T13:00:00+07:00'));
        $paid = $this->service->aggregate($rows, $this->filter('2026-11-16', '2026-11-16', TransactionDateBasis::Paid, '2026-11-16T13:00:00+07:00'));

        $this->assertSame('partial', $created['availability']);
        $this->assertSame('2026-11-16T13:00:00+07:00', $created['as_of']);
        $this->assertSame(1, $created['metrics']['total_checkouts']);
        $this->assertSame(1, $paid['metrics']['success_payments']);
    }

    public function test_ac03_ratios_with_a_zero_divisor_are_empty(): void
    {
        $this->assertNull(Ratio::percentage(0, 0));
        $this->assertNull(Ratio::change(10, 0));
        $this->assertNull(Ratio::change(10, null));
        $this->assertNull(Ratio::average(0, 0));
        $this->assertSame(50.0, Ratio::change(15, 10));
    }

    public function test_ac04_report_status_prefers_a_set_effective_status_including_zero(): void
    {
        $cases = [
            [0, 2, MarkazTransactionStatus::Pending],
            [2, 0, MarkazTransactionStatus::Expired],
            [null, 3, MarkazTransactionStatus::Failed],
            [null, 4, MarkazTransactionStatus::Canceled],
            ['', 3, MarkazTransactionStatus::Failed],
            [null, null, null],
            ['', '', null],
        ];

        foreach ($cases as [$effective, $source, $expected]) {
            $record = TransactionRecord::fromArray($this->row('TRX', ['effective_status' => $effective, 'source_status' => $source]));

            $this->assertSame($expected, $record->reportStatus, var_export([$effective, $source], true));
        }

        $missing = $this->row('TRX-MISSING');
        unset($missing['effective_status'], $missing['source_status']);

        $this->assertNull(TransactionRecord::fromArray($missing)->reportStatus);
        $this->assertSame('Status belum tersedia', MarkazTransactionStatus::labelFor(TransactionRecord::fromArray($missing)->reportStatus));
    }

    public function test_ac04_the_breakdown_counts_each_normalized_status_once(): void
    {
        $rows = [
            $this->row('TRX-1', ['effective_status' => 0, 'source_status' => 2]),
            $this->row('TRX-2', ['effective_status' => 2, 'source_status' => 0]),
            $this->row('TRX-3', ['source_status' => 3]),
            $this->row('TRX-4', ['source_status' => 4]),
            $this->row('TRX-5', ['source_status' => null]),
        ];

        $metrics = $this->service->aggregate($rows, $this->filter('2026-11-10', '2026-11-10', TransactionDateBasis::Created))['metrics'];

        $this->assertSame(
            ['pending' => 1, 'success' => 0, 'expired' => 1, 'failed' => 1, 'canceled' => 1],
            array_map(fn (array $status) => $status['count'], $metrics['status_breakdown']),
        );
        $this->assertSame(3, $metrics['unsuccessful_purchases']);
        $this->assertSame(1, $metrics['unknown_status_count']);
        $this->assertSame(5, $metrics['total_checkouts']);
    }

    public function test_ac05_revenue_uses_net_nominal_and_the_breakdown_uses_nominal(): void
    {
        $rows = [
            $this->success('TRX-A', '2026-11-10 09:00:00', ['nominal' => 182000, 'admin_fee' => 2000, 'net_nominal' => 180000]),
            $this->success('TRX-B', '2026-11-10 10:00:00', ['nominal' => 185000, 'admin_fee' => 5000, 'net_nominal' => 180000]),
        ];

        $paid = $this->service->aggregate($rows, $this->filter('2026-11-10', '2026-11-10', TransactionDateBasis::Paid))['metrics'];
        $created = $this->service->aggregate($rows, $this->filter('2026-11-10', '2026-11-10', TransactionDateBasis::Created))['metrics'];

        $this->assertSame(2, $paid['success_payments']);
        $this->assertSame(360000.0, $paid['revenue']);
        $this->assertSame(367000.0, $paid['nominal_total']);
        $this->assertSame(180000.0, $paid['average_order_value']);
        $this->assertSame(367000.0, $created['status_breakdown']['success']['nominal']);
    }

    public function test_ac06_claims_count_in_total_but_stay_out_of_conversion(): void
    {
        $filter = $this->filter('2026-11-10', '2026-11-10', TransactionDateBasis::Created);

        $mixed = $this->service->aggregate([
            $this->success('TRX-1', '2026-11-10 09:00:00'),
            $this->row('TRX-2', ['source_status' => 0]),
            $this->success('TRX-CLAIM', '2026-11-10 09:00:00', ['is_claim_coupon' => true]),
        ], $filter)['metrics'];

        $this->assertSame(3, $mixed['total_checkouts']);
        $this->assertSame(2, $mixed['purchase_attempts']);
        $this->assertSame(1, $mixed['claim_checkouts']);
        $this->assertSame(1, $mixed['success_purchases']);
        $this->assertSame(50.0, $mixed['conversion_percentage']);

        $claimOnly = $this->service->aggregate([
            $this->success('TRX-CLAIM', '2026-11-10 09:00:00', ['is_claim_coupon' => true]),
        ], $filter)['metrics'];

        $this->assertSame(1, $claimOnly['total_checkouts']);
        $this->assertSame(0, $claimOnly['purchase_attempts']);
        $this->assertNull($claimOnly['conversion_percentage']);
    }

    public function test_ac06_an_unknown_status_stays_in_the_total_without_becoming_success(): void
    {
        $metrics = $this->service->aggregate([
            $this->row('TRX-UNKNOWN', ['source_status' => null, 'nominal' => 150000]),
            $this->row('TRX-PENDING', ['source_status' => 0]),
        ], $this->filter('2026-11-10', '2026-11-10', TransactionDateBasis::Created))['metrics'];

        $this->assertSame(2, $metrics['total_checkouts']);
        $this->assertSame(0, $metrics['success_purchases']);
        $this->assertSame(0, $metrics['status_breakdown']['success']['count']);
        $this->assertSame(1, $metrics['unknown_status_count']);
        $this->assertSame(150000.0, $metrics['unknown_status_nominal']);
        $this->assertSame(0.0, $metrics['conversion_percentage']);
        $this->assertTrue($metrics['is_provisional']);
    }

    public function test_ac07_only_the_group_buyer_feeds_the_aggregation_per_platform(): void
    {
        $rows = [
            $this->success('GRP-1', '2026-11-10 09:00:00', ['markaz_customer_id' => 900, 'net_nominal' => 300000, 'nominal' => 300000]),
            $this->row('GRP-1-M1', ['markaz_ref_id' => 'GRP-1', 'markaz_customer_id' => 901, 'source_status' => 1, 'paid_date' => '2026-11-10', 'paid_time' => '09:00:00']),
            $this->row('GRP-1-M2', ['markaz_ref_id' => 'GRP-1', 'markaz_customer_id' => 902, 'source_status' => 1, 'paid_date' => '2026-11-10', 'paid_time' => '09:00:00']),
            $this->success('GRP-1', '2026-11-10 09:00:00', ['platform_id' => 2, 'markaz_customer_id' => 900, 'net_nominal' => 300000]),
            $this->row('GRP-1-M1', ['platform_id' => 2, 'markaz_ref_id' => 'GRP-1', 'markaz_customer_id' => 903, 'source_status' => 1]),
        ];

        $buyers = $this->service->buyerRows($rows);
        $members = $this->service->membersOf($buyers[0], $rows);

        $this->assertSame([[1, 'GRP-1'], [2, 'GRP-1']], array_map(fn (TransactionRecord $r) => [$r->platformId, $r->markazTransactionId], $buyers));
        $this->assertSame(['GRP-1-M1', 'GRP-1-M2'], array_map(fn (TransactionRecord $r) => $r->markazTransactionId, $members));
        $this->assertSame([2], array_unique(array_map(fn (TransactionRecord $r) => $r->platformId, $this->service->membersOf($buyers[1], $rows))));

        $paid = $this->service->aggregate($rows, $this->filter('2026-11-10', '2026-11-10', TransactionDateBasis::Paid, platforms: [1]))['metrics'];

        $this->assertSame(1, $paid['success_payments']);
        $this->assertSame(1, $paid['unique_buyers']);
        $this->assertSame(300000.0, $paid['revenue']);

        $rows[] = $this->row('TRX-PENDING', ['source_status' => 0]);
        $created = $this->service->aggregate($rows, $this->filter('2026-11-10', '2026-11-10', TransactionDateBasis::Created, platforms: [1]))['metrics'];
        $combined = $this->service->aggregate($rows, $this->filter('2026-11-10', '2026-11-10', TransactionDateBasis::Created, platforms: [1, 2]))['metrics'];

        $this->assertSame(2, $created['total_checkouts']);
        $this->assertSame(50.0, $created['conversion_percentage']);
        $this->assertSame(3, $combined['total_checkouts']);
    }

    public function test_ac08_revenue_follows_the_last_valid_paid_date_stored_for_the_transaction(): void
    {
        $states = [
            'paid on the 10th' => $this->success('TRX-1', '2026-11-10 09:00:00'),
            'moved to the 12th' => $this->success('TRX-1', '2026-11-12 11:00:00'),
            'latest paid date empty' => array_merge($this->success('TRX-1', '2026-11-12 11:00:00'), ['markaz_paid_at' => null]),
        ];
        $expected = [
            'paid on the 10th' => [200000.0, 0.0, 0.0],
            'moved to the 12th' => [0.0, 0.0, 200000.0],
            'latest paid date empty' => [0.0, 0.0, 200000.0],
        ];

        foreach ($states as $state => $row) {
            $daily = $this->service->aggregateDaily([$row], $this->filter('2026-11-10', '2026-11-12', TransactionDateBasis::Paid));

            $this->assertSame($expected[$state], array_map(fn (array $day) => $day['metrics']['revenue'], $daily), $state);
            $this->assertSame($expected[$state] === [200000.0, 0.0, 0.0] ? '2026-11-10' : '2026-11-12', TransactionRecord::fromArray($row)->paymentDate(), $state);
        }
    }

    public function test_ac08_a_success_without_a_valid_paid_date_counts_in_conversion_but_not_in_payments(): void
    {
        $rows = [$this->row('TRX-NO-DATE', ['source_status' => 1, 'markaz_paid_at' => null, 'paid_date' => null, 'paid_time' => null])];

        $created = $this->service->aggregate($rows, $this->filter('2026-11-10', '2026-11-10', TransactionDateBasis::Created))['metrics'];
        $paid = $this->service->aggregate($rows, $this->filter('2026-11-01', '2026-11-30', TransactionDateBasis::Paid))['metrics'];

        $this->assertSame(1, $created['success_purchases']);
        $this->assertSame(100.0, $created['conversion_percentage']);
        $this->assertSame(0, $paid['success_payments']);
        $this->assertSame(0.0, $paid['revenue']);
        $this->assertSame(0, $paid['unique_buyers']);
        $this->assertNull(TransactionRecord::fromArray($rows[0])->paymentDate());
    }

    public function test_ac09_a_success_turned_failed_leaves_payments_once_and_keeps_the_total(): void
    {
        $other = $this->success('TRX-OTHER', '2026-11-12 08:00:00', ['markaz_customer_id' => 777, 'net_nominal' => 100000]);
        $before = [$this->success('TRX-1', '2026-11-12 11:00:00', ['markaz_customer_id' => 501]), $other];
        $after = [array_merge($before[0], ['effective_status' => MarkazTransactionStatus::Failed->value]), $other];

        $paidFilter = $this->filter('2026-11-12', '2026-11-12', TransactionDateBasis::Paid);
        $createdFilter = $this->filter('2026-11-10', '2026-11-10', TransactionDateBasis::Created);

        $paidBefore = $this->service->aggregate($before, $paidFilter)['metrics'];
        $createdBefore = $this->service->aggregate($before, $createdFilter)['metrics'];

        foreach ([1, 2] as $read) {
            $paidAfter = $this->service->aggregate($after, $paidFilter)['metrics'];
            $createdAfter = $this->service->aggregate($after, $createdFilter)['metrics'];

            $this->assertSame(300000.0, $paidBefore['revenue']);
            $this->assertSame(100000.0, $paidAfter['revenue'], "read {$read}");
            $this->assertSame($paidBefore['success_payments'] - 1, $paidAfter['success_payments'], "read {$read}");
            $this->assertSame(1, $paidAfter['unique_buyers'], "read {$read}");
            $this->assertSame(100000.0, $paidAfter['average_order_value'], "read {$read}");
            $this->assertSame($createdBefore['total_checkouts'], $createdAfter['total_checkouts'], "read {$read}");
            $this->assertSame($createdBefore['status_breakdown']['failed']['count'] + 1, $createdAfter['status_breakdown']['failed']['count'], "read {$read}");
            $this->assertSame($createdBefore['success_purchases'] - 1, $createdAfter['success_purchases'], "read {$read}");
        }
    }

    public function test_ac09_unique_buyers_drop_only_when_the_account_has_no_other_success(): void
    {
        $failed = array_merge($this->success('TRX-1', '2026-11-12 11:00:00', ['markaz_customer_id' => 501]), ['source_status' => 3]);
        $sameAccount = $this->success('TRX-2', '2026-11-12 12:00:00', ['markaz_customer_id' => 501]);
        $filter = $this->filter('2026-11-12', '2026-11-12', TransactionDateBasis::Paid);

        $this->assertSame(0, $this->service->aggregate([$failed], $filter)['metrics']['unique_buyers']);
        $this->assertSame(1, $this->service->aggregate([$failed, $sameAccount], $filter)['metrics']['unique_buyers']);
    }

    public function test_ac11_filling_an_unknown_status_with_success_removes_the_provisional_label(): void
    {
        $rows = [];
        foreach (range(1, 6) as $i) {
            $rows[] = $this->success("TRX-S{$i}", '2026-11-10 12:00:00');
        }
        foreach (range(1, 3) as $i) {
            $rows[] = $this->row("TRX-P{$i}", ['source_status' => 0]);
        }
        $rows[] = $this->row('TRX-UNKNOWN', ['source_status' => null]);
        $filter = $this->filter('2026-11-10', '2026-11-10', TransactionDateBasis::Created);

        $before = $this->service->aggregate($rows, $filter)['metrics'];

        $this->assertSame(10, $before['total_checkouts']);
        $this->assertSame(60.0, $before['conversion_percentage']);
        $this->assertTrue($before['is_provisional']);
        $this->assertSame(1, $before['unknown_status_count']);

        $rows[9] = array_merge($rows[9], ['source_status' => 1]);
        $after = $this->service->aggregate($rows, $filter)['metrics'];

        $this->assertSame(10, $after['total_checkouts']);
        $this->assertSame(70.0, $after['conversion_percentage']);
        $this->assertFalse($after['is_provisional']);
        $this->assertSame(0, $after['unknown_status_count']);
    }

    /**
     * @param  list<int>  $platforms
     */
    private function filter(string $from, string $to, TransactionDateBasis $basis, ?string $completeUntil = 'complete', array $platforms = [1]): AggregationFilter
    {
        if ($completeUntil === 'complete') {
            $completeUntil = '2026-12-01T00:00:00+07:00';
        }

        return new AggregationFilter($platforms, $from, $to, $basis, $completeUntil);
    }

    private function success(string $id, string $paidAt, array $overrides = []): array
    {
        [$date, $time] = explode(' ', $paidAt);

        return $this->row($id, array_merge([
            'source_status' => MarkazTransactionStatus::Success->value,
            'markaz_paid_at' => "{$date}T{$time}+07:00",
            'paid_date' => $date,
            'paid_time' => $time,
        ], $overrides));
    }

    private function row(string $id, array $overrides = []): array
    {
        return array_merge([
            'platform_id' => 1,
            'markaz_transaction_id' => $id,
            'markaz_ref_id' => null,
            'markaz_customer_id' => 501,
            'markaz_created_at' => '2026-11-10T08:00:00+07:00',
            'markaz_paid_at' => null,
            'paid_date' => null,
            'paid_time' => null,
            'synced_at' => '2026-11-10T08:05:00+07:00',
            'source_status' => MarkazTransactionStatus::Pending->value,
            'effective_status' => null,
            'is_claim_coupon' => false,
            'product_price' => 200000,
            'voucher_discount' => 0,
            'admin_fee' => 0,
            'nominal' => 200000,
            'net_nominal' => 200000,
        ], $overrides);
    }
}
