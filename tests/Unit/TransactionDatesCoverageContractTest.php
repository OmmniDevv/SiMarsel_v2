<?php

namespace Tests\Unit;

use App\Enums\MarkazTransactionStatus;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Checks the transaction date, status, and data coverage contract examples in
 * tests/Fixtures/contracts/transaction-dates-coverage.json against the rules in
 * docs/transaction-dates-coverage.md. The private helpers are reference rules for
 * the example values, not the production resolver or aggregation.
 */
class TransactionDatesCoverageContractTest extends TestCase
{
    private const TIMEZONE = 'Asia/Jakarta';

    private static array $contract;

    public static function setUpBeforeClass(): void
    {
        self::$contract = json_decode(
            file_get_contents(__DIR__.'/../Fixtures/contracts/transaction-dates-coverage.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    public function test_every_example_row_matches_the_declared_type_and_nullability(): void
    {
        foreach (self::$contract['transactions'] as $row) {
            $this->assertRowMatchesFields($row, self::$contract['fields']['transactions'], $row['markaz_transaction_id']);
        }

        foreach (self::$contract['coverage'] as $index => $row) {
            $this->assertRowMatchesFields($row, self::$contract['fields']['coverage'], "coverage #{$index}");
        }

        $this->assertRowMatchesFields(self::$contract['checkpoint'], self::$contract['fields']['dashboard_snapshots'], 'checkpoint');
    }

    public function test_pending_example_reads_with_empty_payment_date(): void
    {
        $pending = $this->transaction('TRX-1001');

        $this->assertSame(MarkazTransactionStatus::Pending, $this->reportStatus($pending));
        $this->assertNull($pending['markaz_paid_at']);
        $this->assertNull($pending['paid_date']);
        $this->assertNull($pending['paid_time']);
        $this->assertNotNull($pending['markaz_created_at']);
        $this->assertNotNull($pending['synced_at']);
    }

    public function test_success_example_keeps_created_paid_and_synced_times_apart(): void
    {
        $success = $this->transaction('TRX-1002');

        $created = CarbonImmutable::parse($success['markaz_created_at']);
        $paid = CarbonImmutable::parse($success['markaz_paid_at']);
        $synced = CarbonImmutable::parse($success['synced_at']);

        $this->assertTrue($created->lt($paid));
        $this->assertTrue($synced->gt($paid), 'The example is synced after payment.');
        $this->assertSame('2026-11-10', $this->createdGroupDate($success));
        $this->assertSame('2026-11-11', $this->paidGroupDate($success));
        $this->assertSame($paid->setTimezone(self::TIMEZONE)->toDateString(), $success['paid_date']);
        $this->assertSame($paid->setTimezone(self::TIMEZONE)->format('H:i:s'), $success['paid_time']);
    }

    public function test_coverage_metadata_is_declared_per_platform_and_dataset(): void
    {
        $keys = array_map(fn (array $row) => $row['platform_id'].':'.$row['dataset'], self::$contract['coverage']);

        $this->assertSame(['1:transactions', '2:transactions', '1:downloaders', '2:downloaders'], $keys);
        $this->assertSame(count($keys), count(array_unique($keys)));

        foreach (self::$contract['coverage'] as $row) {
            $this->assertSame(
                $this->availability($row['complete_until'], $row['date_from'], $row['date_to']),
                $row['availability'],
                "{$row['platform_id']}:{$row['dataset']}",
            );
        }
    }

    public function test_sync_finish_time_does_not_prove_coverage(): void
    {
        $platformB = self::$contract['coverage'][1];
        $downloadersB = self::$contract['coverage'][3];

        $this->assertTrue(CarbonImmutable::parse($platformB['sync_finished_at'])->gt($platformB['complete_until']));
        $this->assertSame('2026-11-16T13:00:00+07:00', $platformB['complete_until']);

        $this->assertNotNull($downloadersB['sync_finished_at']);
        $this->assertNull($downloadersB['complete_until']);
        $this->assertSame('unavailable', $downloadersB['availability']);
    }

    public function test_combined_coverage_is_the_earliest_boundary_of_all_selected_platforms(): void
    {
        foreach (self::$contract['combined_coverage_examples'] as $example) {
            $this->assertSame($example['expected'], $this->combinedCoverage($example), $example['case']);
        }
    }

    public function test_checkpoint_separates_cutoff_from_recorded_time(): void
    {
        $checkpoint = self::$contract['checkpoint'];

        $this->assertTrue(CarbonImmutable::parse($checkpoint['recorded_at'])->gt($checkpoint['cutoff_at']));
        $this->assertSame($checkpoint['cutoff_at'], $checkpoint['expected_comparison']['as_of']);
        $this->assertSame($checkpoint['payload']['conversion_percentage'], $checkpoint['expected_comparison']['conversion_percentage']);
        $this->assertSame(
            $this->conversion($checkpoint['payload']['success_purchases'], $checkpoint['payload']['purchase_attempts']),
            $checkpoint['payload']['conversion_percentage'],
        );
    }

    public function test_equivalent_hour_comparison_uses_only_complete_snapshots(): void
    {
        foreach (self::$contract['comparison_examples'] as $example) {
            $snapshots = array_column($example['snapshots'], null, 'platform_id');
            $usable = array_filter(
                $example['platform_ids'],
                fn (int $platformId) => ($snapshots[$platformId]['is_complete'] ?? false) === true,
            );

            if (count($usable) !== count($example['platform_ids'])) {
                $this->assertSame(['availability' => 'unavailable', 'as_of' => null, 'values' => null], $example['expected'], $example['case']);

                continue;
            }

            $success = array_sum(array_map(fn (int $id) => $snapshots[$id]['payload']['success_purchases'], $usable));
            $attempts = array_sum(array_map(fn (int $id) => $snapshots[$id]['payload']['purchase_attempts'], $usable));

            $this->assertSame([
                'availability' => 'complete',
                'as_of' => $example['cutoff_at'],
                'values' => [
                    'success_purchases' => $success,
                    'purchase_attempts' => $attempts,
                    'conversion_percentage' => $this->conversion($success, $attempts),
                ],
            ], $example['expected'], $example['case']);
        }
    }

    public function test_incomplete_snapshot_examples_never_expect_numbers(): void
    {
        foreach (self::$contract['comparison_examples'] as $example) {
            $hasIncomplete = in_array(false, array_column($example['snapshots'], 'is_complete'), true);

            if ($hasIncomplete) {
                $this->assertSame('unavailable', $example['expected']['availability'], $example['case']);
                $this->assertNull($example['expected']['values'], $example['case']);
            }
        }

        $this->assertNotSame('partial', self::$contract['checkpoint']['expected_comparison']['availability']);
    }

    public function test_downloader_total_example_carries_its_coverage(): void
    {
        $total = self::$contract['downloader_total'];

        $this->assertSame('downloaders', $total['dataset']);
        $this->assertIsInt($total['total']);
        $this->assertSame(
            $this->availability($total['coverage']['complete_until'], $total['date'], $total['date']),
            $total['coverage']['availability'],
        );
    }

    public function test_report_status_prefers_effective_status_including_zero(): void
    {
        $this->assertSame(MarkazTransactionStatus::Expired, MarkazTransactionStatus::forReport(2, 0));
        $this->assertSame(MarkazTransactionStatus::Pending, MarkazTransactionStatus::forReport(0, 1));
        $this->assertSame(MarkazTransactionStatus::Pending, MarkazTransactionStatus::forReport(null, 0));
        $this->assertSame(MarkazTransactionStatus::Failed, MarkazTransactionStatus::forReport(null, 3));
        $this->assertSame(MarkazTransactionStatus::Canceled, MarkazTransactionStatus::forReport(null, 4));
        $this->assertNull(MarkazTransactionStatus::forReport(null, null));
        $this->assertSame('Status belum tersedia', MarkazTransactionStatus::labelFor(null));
    }

    public function test_unsuccessful_group_is_expired_failed_and_canceled(): void
    {
        $unsuccessful = array_values(array_filter(
            MarkazTransactionStatus::cases(),
            fn (MarkazTransactionStatus $status) => $status->isUnsuccessful(),
        ));

        $this->assertSame(
            [MarkazTransactionStatus::Expired, MarkazTransactionStatus::Failed, MarkazTransactionStatus::Canceled],
            $unsuccessful,
        );
    }

    public function test_every_example_row_reads_to_its_expected_values(): void
    {
        foreach (self::$contract['transactions'] as $row) {
            $status = $this->reportStatus($row);

            $this->assertSame($row['expected'], [
                'report_status' => $status?->value,
                'report_status_label' => MarkazTransactionStatus::labelFor($status),
                'created_group_date' => $this->createdGroupDate($row),
                'paid_group_date' => $this->paidGroupDate($row),
                'is_group_member' => $row['markaz_ref_id'] !== null,
            ], $row['markaz_transaction_id']);
        }
    }

    public function test_conversion_examples_mark_unknown_status_as_provisional(): void
    {
        foreach (self::$contract['conversion_examples'] as $example) {
            $total = $attempts = $claims = $success = $unknown = 0;

            foreach ($example['rows'] as $row) {
                $total += $row['count'];

                if ($row['is_claim_coupon']) {
                    $claims += $row['count'];

                    continue;
                }

                $attempts += $row['count'];
                $success += $row['report_status'] === MarkazTransactionStatus::Success->value ? $row['count'] : 0;
                $unknown += $row['report_status'] === null ? $row['count'] : 0;
            }

            $this->assertSame($example['expected'], [
                'total_checkouts' => $total,
                'purchase_attempts' => $attempts,
                'claim_checkouts' => $claims,
                'success_purchases' => $success,
                'unknown_status_count' => $unknown,
                'conversion_percentage' => $this->conversion($success, $attempts),
                'is_provisional' => $unknown > 0,
            ], $example['case']);
        }
    }

    public function test_financial_example_uses_net_nominal_for_revenue_and_nominal_for_the_breakdown(): void
    {
        $example = self::$contract['financial_example'];
        $rows = array_map(fn (string $id) => $this->transaction($id), $example['transaction_ids']);
        $payments = array_values(array_filter($rows, fn (array $row) => $this->paidGroupDate($row) === $example['paid_date']));
        $revenue = array_sum(array_column($payments, 'net_nominal'));

        $this->assertSame($example['expected'], [
            'success_payments' => count($payments),
            'revenue' => $revenue,
            'nominal_total' => array_sum(array_column($payments, 'nominal')),
            'average_order_value' => intdiv($revenue, count($payments)),
        ]);
    }

    public function test_group_example_counts_only_the_buyer_row(): void
    {
        $example = self::$contract['group_example'];
        $rows = array_map(fn (string $id) => $this->transaction($id), $example['transaction_ids']);
        $buyers = array_values(array_filter($rows, fn (array $row) => $row['markaz_ref_id'] === null));
        $paidBuyers = array_values(array_filter($buyers, fn (array $row) => $this->paidGroupDate($row) !== null));

        $members = [];
        foreach ($buyers as $buyer) {
            $memberIds = array_column(array_filter($rows, fn (array $row) => $row['platform_id'] === $buyer['platform_id']
                && $row['markaz_ref_id'] === $buyer['markaz_transaction_id']), 'markaz_transaction_id');

            if ($memberIds !== []) {
                $members[$buyer['markaz_transaction_id']] = array_values($memberIds);
            }
        }

        $buyerKeys = array_unique(array_map(fn (array $row) => $row['platform_id'].':'.$row['markaz_customer_id'], $paidBuyers));

        $this->assertSame($example['expected'], [
            'total_checkouts' => count($buyers),
            'success_purchases' => count($paidBuyers),
            'unique_buyers' => count($buyerKeys),
            'revenue' => array_sum(array_column($paidBuyers, 'net_nominal')),
            'conversion_percentage' => $this->conversion(count($paidBuyers), count($buyers)),
            'members' => $members,
        ]);
    }

    public function test_zero_is_reported_only_for_complete_coverage(): void
    {
        foreach (self::$contract['value_availability_examples'] as $example) {
            $known = $example['availability'] === 'complete';

            if ($example['transaction_ids'] === null) {
                $this->assertSame(['comparison' => null, 'comparison_availability' => 'unavailable'], $example['expected'], $example['case']);

                continue;
            }

            $this->assertSame([
                'total_checkouts' => $known ? count($example['transaction_ids']) : null,
                'revenue' => $known ? 0 : null,
                'conversion_percentage' => null,
            ], $example['expected'], $example['case']);
        }
    }

    private function assertRowMatchesFields(array $row, array $fields, string $label): void
    {
        foreach ($fields as $name => $field) {
            $this->assertArrayHasKey($name, $row, "{$label}: {$name}");

            $value = $row[$name];

            if ($value === null) {
                $this->assertTrue($field['nullable'], "{$label}: {$name} must not be null");

                continue;
            }

            $this->assertTrue($this->matchesType($value, $field['type']), "{$label}: {$name} is not a {$field['type']}");
        }
    }

    private function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'integer' => is_int($value),
            'string' => is_string($value) && $value !== '',
            'boolean' => is_bool($value),
            'object' => is_array($value),
            'money' => (is_int($value) || is_float($value)) && $value >= 0,
            'status' => MarkazTransactionStatus::tryFrom($value) !== null,
            'date' => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value),
            'time' => (bool) preg_match('/^\d{2}:\d{2}:\d{2}$/', $value),
            'datetime' => (bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+07:00$/', $value),
            'dataset' => in_array($value, ['transactions', 'downloaders'], true),
            'availability' => in_array($value, ['complete', 'partial', 'unavailable'], true),
        };
    }

    private function transaction(string $id): array
    {
        foreach (self::$contract['transactions'] as $row) {
            if ($row['markaz_transaction_id'] === $id) {
                return $row;
            }
        }

        $this->fail("Missing fixture transaction {$id}.");
    }

    private function reportStatus(array $row): ?MarkazTransactionStatus
    {
        return MarkazTransactionStatus::forReport($row['effective_status'], $row['source_status']);
    }

    private function createdGroupDate(array $row): string
    {
        return CarbonImmutable::parse($row['markaz_created_at'])->setTimezone(self::TIMEZONE)->toDateString();
    }

    private function paidGroupDate(array $row): ?string
    {
        return $this->reportStatus($row) === MarkazTransactionStatus::Success ? $row['paid_date'] : null;
    }

    private function availability(?string $completeUntil, string $dateFrom, string $dateTo): string
    {
        $start = CarbonImmutable::parse($dateFrom, self::TIMEZONE)->startOfDay();
        $end = CarbonImmutable::parse($dateTo, self::TIMEZONE)->addDay()->startOfDay();

        return match (true) {
            $completeUntil === null || CarbonImmutable::parse($completeUntil)->lte($start) => 'unavailable',
            CarbonImmutable::parse($completeUntil)->gte($end) => 'complete',
            default => 'partial',
        };
    }

    private function combinedCoverage(array $example): array
    {
        $boundaries = array_column($example['platforms'], 'complete_until');

        if (in_array(null, $boundaries, true)) {
            return ['complete_until' => null, 'availability' => 'unavailable'];
        }

        $shared = min(array_map(fn (string $boundary) => CarbonImmutable::parse($boundary), $boundaries));
        $periodEnd = CarbonImmutable::parse($example['date_to'], self::TIMEZONE)->addDay()->startOfDay();
        $availability = $this->availability($shared->toIso8601String(), $example['date_from'], $example['date_to']);

        return [
            'complete_until' => $availability === 'unavailable' ? null : min($shared, $periodEnd)->setTimezone(self::TIMEZONE)->toIso8601String(),
            'availability' => $availability,
        ];
    }

    private function conversion(int $success, int $attempts): ?float
    {
        return $attempts === 0 ? null : round($success / $attempts * 100, 2);
    }
}
