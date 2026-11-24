<?php

namespace Tests\Feature\Performance;

use App\Enums\MarkazTransactionStatus;
use App\Models\Account;
use App\Models\DashboardSnapshot;
use App\Models\Platform;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FIX-05-BE-01: the six transaction metrics of the Ringkasan Sales, with the active value, the comparison value,
 * the change and its unit, and the date basis of every metric.
 */
class SalesSummaryTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/performance/summary';

    /** Ringkasan Sales on 11 Nov 2026: active 04 - 10 Nov, comparison 28 Okt - 03 Nov. */
    private const NOW = '2026-11-11T14:30:00+07:00';

    private Account $manager;

    private Platform $platformA;

    private Platform $platformB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $this->manager = Account::where('username', 'manager')->first();
        $this->platformA = Platform::factory()->create(['slug' => 'app-a', 'name' => 'App A']);
        $this->platformB = Platform::factory()->create(['slug' => 'app-b', 'name' => 'App B']);

        $this->travelTo(CarbonImmutable::parse(self::NOW));
    }

    public function test_ac01_ten_created_six_successful_and_eight_paid_give_total_10_conversion_60_and_8_successful(): void
    {
        $this->lastSevenDaysCoverage($this->platformA);

        for ($i = 0; $i < 6; $i++) {
            $this->transaction($this->platformA, '2026-11-05 09:00:00', '2026-11-05 09:10:00', 100000);
        }

        $this->transaction($this->platformA, '2026-11-06 09:00:00', null, 100000, MarkazTransactionStatus::Pending);
        $this->transaction($this->platformA, '2026-11-06 10:00:00', null, 100000, MarkazTransactionStatus::Expired);
        $this->transaction($this->platformA, '2026-11-07 10:00:00', null, 100000, MarkazTransactionStatus::Failed);
        $this->transaction($this->platformA, '2026-11-07 11:00:00', null, 100000, MarkazTransactionStatus::Canceled);

        // Created in the comparison period, paid in the active period.
        $this->transaction($this->platformA, '2026-11-02 22:00:00', '2026-11-04 08:00:00', 100000);
        $this->transaction($this->platformA, '2026-11-03 23:00:00', '2026-11-04 01:00:00', 100000);

        $summary = $this->summary('platforms[]=app-a&preset=last_7_days');
        $metrics = $this->metrics($summary);

        $this->assertSame(
            ['revenue', 'success_payments', 'total_checkouts', 'conversion', 'average_order_value', 'unique_buyers'],
            array_column($summary['metrics'], 'key'),
        );
        $this->assertSame(['sales_result', 'transaction_activity', 'supporting'], array_column($summary['groups'], 'key'));
        $this->assertSame('Berdasarkan tanggal pembayaran', $summary['groups'][0]['date_basis_label']);
        $this->assertSame('Berdasarkan tanggal transaksi dibuat', $summary['groups'][1]['date_basis_label']);

        $this->assertSame(10, $metrics['total_checkouts']['active']['value']);
        $this->assertSame(60.0, (float) $metrics['conversion']['active']['value']);
        $this->assertSame(8, $metrics['success_payments']['active']['value']);
        $this->assertSame('created', $metrics['total_checkouts']['date_basis']);
        $this->assertSame('created', $metrics['conversion']['date_basis']);
        $this->assertSame('paid', $metrics['success_payments']['date_basis']);
        $this->assertEquals([
            'purchase_attempts' => 10,
            'success_purchases' => 6,
            'unsuccessful_purchases' => 3,
            'unknown_status_count' => 0,
            'claim_checkouts' => 0,
        ], $metrics['total_checkouts']['active']['breakdown']);
        $this->assertFalse($metrics['conversion']['active']['is_provisional']);

        // The comparison uses the same date basis: both checkouts created before the active period count there.
        $this->assertSame(2, $metrics['total_checkouts']['comparison']['value']);
        $this->assertSame(100.0, (float) $metrics['conversion']['comparison']['value']);
        $this->assertSame(0, $metrics['success_payments']['comparison']['value']);
        $this->assertSame('complete', $metrics['success_payments']['comparison']['availability']);

        $this->assertSame(400.0, (float) $metrics['total_checkouts']['change']['value']);
        $this->assertSame('percent', $metrics['total_checkouts']['change']['unit']);
        $this->assertSame(-40.0, (float) $metrics['conversion']['change']['value']);
        $this->assertSame('percentage_point', $metrics['conversion']['change']['unit']);
        $this->assertSame('down', $metrics['conversion']['change']['trend']);

        // A comparison of 0 keeps the active value and its direction but has no relative change.
        $this->assertNull($metrics['success_payments']['change']['value']);
        $this->assertSame('up', $metrics['success_payments']['change']['trend']);
        $this->assertNull($metrics['average_order_value']['comparison']['value']);
        $this->assertNull($metrics['average_order_value']['change']['value']);
    }

    public function test_ac02_aov_divides_revenue_by_payments_and_one_identity_is_one_buyer(): void
    {
        $this->lastSevenDaysCoverage($this->platformA);
        $this->transaction($this->platformA, '2026-11-03 20:00:00', '2026-11-05 10:00:00', 80000, customerId: 7001);
        $this->transaction($this->platformA, '2026-11-06 20:00:00', '2026-11-08 10:00:00', 120000, customerId: 7001);

        $metrics = $this->metrics($this->summary('platforms[]=app-a&preset=last_7_days'));

        $this->assertSame(200000.0, (float) $metrics['revenue']['active']['value']);
        $this->assertSame(2, $metrics['success_payments']['active']['value']);
        $this->assertSame(100000.0, (float) $metrics['average_order_value']['active']['value']);
        $this->assertSame(1, $metrics['unique_buyers']['active']['value']);
        $this->assertSame('currency', $metrics['average_order_value']['unit']);
        $this->assertSame('supporting', $metrics['unique_buyers']['group']);
    }

    public function test_ac03_a_trimmed_change_keeps_the_full_active_value_and_the_matched_values(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-03-31T10:00:00+07:00'));
        $this->coverage($this->platformA, '2026-03-01', '2026-03-31', '2026-03-31 09:00:00');
        $this->coverage($this->platformA, '2026-02-01', '2026-02-28', '2026-03-01 00:00:00');

        $this->transaction($this->platformA, '2026-03-10 08:00:00', '2026-03-10 08:05:00', 100000);
        $this->transaction($this->platformA, '2026-03-30 08:00:00', '2026-03-30 08:05:00', 50000);
        $this->transaction($this->platformA, '2026-03-31 08:00:00', '2026-03-31 08:05:00', 25000);
        $this->transaction($this->platformA, '2026-03-31 09:30:00', '2026-03-31 09:35:00', 999000);
        $this->transaction($this->platformA, '2026-02-10 08:00:00', '2026-02-10 08:05:00', 80000);

        $summary = $this->summary('platforms[]=app-a&preset=this_month');
        $revenue = $this->metrics($summary)['revenue'];

        $this->assertSame(175000.0, (float) $revenue['active']['value']);
        $this->assertSame('partial', $revenue['active']['availability']);
        $this->assertSame('2026-03-31T09:00:00+07:00', $revenue['active']['as_of']);
        $this->assertSame(80000.0, (float) $revenue['comparison']['value']);

        $this->assertTrue($revenue['change']['is_trimmed']);
        $this->assertSame(100000.0, (float) $revenue['change']['active_value']);
        $this->assertSame(80000.0, (float) $revenue['change']['comparison_value']);
        $this->assertSame(25.0, (float) $revenue['change']['value']);

        $this->assertSame(3, $this->metrics($summary)['total_checkouts']['active']['value']);
        $this->assertSame(1, $this->metrics($summary)['total_checkouts']['change']['active_value']);
    }

    public function test_ac04_a_buyer_counts_once_per_platform_over_the_range_and_group_members_never_add(): void
    {
        $this->lastSevenDaysCoverage($this->platformA);
        $this->lastSevenDaysCoverage($this->platformB);

        $buyer = $this->transaction($this->platformA, '2026-11-05 09:00:00', '2026-11-05 09:10:00', 300000, customerId: 5001, name: 'Rina', email: 'rina@example.com');
        $this->transaction($this->platformA, '2026-11-07 09:00:00', '2026-11-07 09:10:00', 100000, customerId: 5001, name: 'Rina Putri', email: 'rina.putri@example.com');
        $this->transaction($this->platformA, '2026-11-05 09:00:00', '2026-11-05 09:10:00', 0, customerId: 6001, refId: $buyer->markaz_transaction_id);
        $this->transaction($this->platformA, '2026-11-05 09:00:00', '2026-11-05 09:10:00', 0, customerId: 6002, refId: $buyer->markaz_transaction_id);
        $this->transaction($this->platformB, '2026-11-06 09:00:00', '2026-11-06 09:10:00', 150000, customerId: 5001);

        $buyers = fn (string $query) => $this->metrics($this->summary($query.'&preset=last_7_days'))['unique_buyers']['active']['value'];

        $this->assertSame(1, $buyers('platforms[]=app-a'));
        $this->assertSame(1, $buyers('platforms[]=app-b'));
        $this->assertSame(2, $buyers('platforms[]=app-a&platforms[]=app-b'));

        $metrics = $this->metrics($this->summary('platforms[]=app-a&preset=last_7_days'));

        $this->assertSame(2, $metrics['success_payments']['active']['value']);
        $this->assertSame(2, $metrics['total_checkouts']['active']['value']);
        $this->assertSame(400000.0, (float) $metrics['revenue']['active']['value']);
    }

    public function test_ac05_claims_statuses_and_failed_payments_follow_the_shared_aggregation(): void
    {
        $this->lastSevenDaysCoverage($this->platformA);

        $first = $this->transaction($this->platformA, '2026-11-05 09:00:00', '2026-11-05 09:10:00', 180000, customerId: 8001, nominal: 182000, adminFee: 2000);
        $second = $this->transaction($this->platformA, '2026-11-06 09:00:00', '2026-11-06 09:10:00', 180000, customerId: 8001, nominal: 185000, adminFee: 5000);
        $this->transaction($this->platformA, '2026-11-06 10:00:00', '2026-11-06 10:00:00', 0, claim: true);

        $metrics = $this->metrics($this->summary('platforms[]=app-a&preset=last_7_days'));

        $this->assertSame(360000.0, (float) $metrics['revenue']['active']['value']);
        $this->assertSame(367000.0, (float) $metrics['revenue']['active']['nominal_total']);
        $this->assertSame(180000.0, (float) $metrics['average_order_value']['active']['value']);
        $this->assertSame(3, $metrics['total_checkouts']['active']['value']);
        $this->assertSame(1, $metrics['total_checkouts']['active']['breakdown']['claim_checkouts']);
        $this->assertSame(2, $metrics['total_checkouts']['active']['breakdown']['purchase_attempts']);
        $this->assertSame(100.0, (float) $metrics['conversion']['active']['value']);
        $this->assertSame(1, $metrics['unique_buyers']['active']['value']);

        $first->update(['effective_status' => MarkazTransactionStatus::Failed->value]);
        $metrics = $this->metrics($this->summary('platforms[]=app-a&preset=last_7_days'));

        $this->assertSame(180000.0, (float) $metrics['revenue']['active']['value']);
        $this->assertSame(1, $metrics['success_payments']['active']['value']);
        $this->assertSame(3, $metrics['total_checkouts']['active']['value']);
        $this->assertSame(1, $metrics['total_checkouts']['active']['breakdown']['unsuccessful_purchases']);
        $this->assertSame(50.0, (float) $metrics['conversion']['active']['value']);
        $this->assertSame(1, $metrics['unique_buyers']['active']['value']);

        // Reading again never subtracts twice.
        $this->assertSame(180000.0, (float) $this->metrics($this->summary('platforms[]=app-a&preset=last_7_days'))['revenue']['active']['value']);

        $second->update(['effective_status' => MarkazTransactionStatus::Failed->value]);
        $metrics = $this->metrics($this->summary('platforms[]=app-a&preset=last_7_days'));

        $this->assertSame(0.0, (float) $metrics['revenue']['active']['value']);
        $this->assertSame(0, $metrics['success_payments']['active']['value']);
        $this->assertSame(0, $metrics['unique_buyers']['active']['value']);
        $this->assertNull($metrics['average_order_value']['active']['value']);
        $this->assertSame(3, $metrics['total_checkouts']['active']['value']);
        $this->assertSame(0.0, (float) $metrics['conversion']['active']['value']);
    }

    public function test_an_unknown_status_stays_in_the_conversion_denominator_and_marks_it_provisional(): void
    {
        $this->lastSevenDaysCoverage($this->platformA);
        $this->transaction($this->platformA, '2026-11-05 09:00:00', '2026-11-05 09:10:00', 100000);
        $this->transaction($this->platformA, '2026-11-05 10:00:00', null, 100000, null);

        $conversion = $this->metrics($this->summary('platforms[]=app-a&preset=last_7_days'))['conversion']['active'];

        $this->assertSame(50.0, (float) $conversion['value']);
        $this->assertTrue($conversion['is_provisional']);
        $this->assertSame('Sementara', $conversion['provisional_label']);
        $this->assertSame(1, $conversion['unknown_status_count']);
        $this->assertSame(2, $conversion['purchase_attempts']);
    }

    public function test_a_moved_payment_date_moves_the_payment_metrics_and_a_success_without_a_paid_date_only_counts_in_conversion(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-14T10:00:00+07:00'));
        $this->coverage($this->platformA, '2026-11-10', '2026-11-10', '2026-11-11 00:00:00');
        $this->coverage($this->platformA, '2026-11-12', '2026-11-12', '2026-11-13 00:00:00');

        $payment = $this->transaction($this->platformA, '2026-11-10 08:00:00', '2026-11-10 08:30:00', 200000);
        $this->transaction($this->platformA, '2026-11-10 09:00:00', null, 150000);

        $day = fn (string $date) => $this->metrics($this->summary("platforms[]=app-a&preset=date_range&date_from={$date}&date_to={$date}"));

        $this->assertSame(200000.0, (float) $day('2026-11-10')['revenue']['active']['value']);
        $this->assertSame(1, $day('2026-11-10')['success_payments']['active']['value']);
        $this->assertSame(2, $day('2026-11-10')['total_checkouts']['active']['value']);
        $this->assertSame(100.0, (float) $day('2026-11-10')['conversion']['active']['value']);

        $payment->update(['paid_date' => '2026-11-12', 'paid_time' => '07:00:00']);

        $this->assertSame(0.0, (float) $day('2026-11-10')['revenue']['active']['value']);
        $this->assertSame(0, $day('2026-11-10')['success_payments']['active']['value']);
        $this->assertSame(2, $day('2026-11-10')['total_checkouts']['active']['value']);
        $this->assertSame(200000.0, (float) $day('2026-11-12')['revenue']['active']['value']);
        $this->assertSame(1, $day('2026-11-12')['unique_buyers']['active']['value']);
    }

    public function test_a_missing_comparison_keeps_the_active_value_and_is_not_zero(): void
    {
        $this->coverage($this->platformA, '2026-11-04', '2026-11-10', '2026-11-11 00:00:00');
        $this->transaction($this->platformA, '2026-11-05 09:00:00', '2026-11-05 09:10:00', 100000);

        $revenue = $this->metrics($this->summary('platforms[]=app-a&preset=last_7_days'))['revenue'];

        $this->assertSame(100000.0, (float) $revenue['active']['value']);
        $this->assertSame('unavailable', $revenue['comparison']['availability']);
        $this->assertNull($revenue['comparison']['value']);
        $this->assertNull($revenue['change']['value']);
        $this->assertNull($revenue['change']['trend']);
    }

    public function test_unknown_active_coverage_gives_null_values_and_a_complete_empty_period_gives_zero(): void
    {
        $unknown = $this->metrics($this->summary('platforms[]=app-a&preset=last_7_days'));

        $this->assertSame('unavailable', $unknown['revenue']['active']['availability']);
        $this->assertNull($unknown['revenue']['active']['value']);
        $this->assertNull($unknown['total_checkouts']['active']['breakdown']);
        $this->assertNull($unknown['conversion']['active']['is_provisional']);

        $this->lastSevenDaysCoverage($this->platformA);
        $empty = $this->metrics($this->summary('platforms[]=app-a&preset=last_7_days'));

        $this->assertSame(0.0, (float) $empty['revenue']['active']['value']);
        $this->assertSame(0, $empty['total_checkouts']['active']['value']);
        $this->assertSame(0, $empty['unique_buyers']['active']['value']);
        $this->assertNull($empty['conversion']['active']['value']);
        $this->assertNull($empty['average_order_value']['active']['value']);
    }

    public function test_an_equivalent_hour_comparison_reads_the_recorded_snapshot(): void
    {
        $this->coverage($this->platformA, '2026-11-11', '2026-11-11', '2026-11-11 14:00:00');
        $this->coverage($this->platformA, '2026-11-10', '2026-11-10', '2026-11-10 14:00:00', [
            'revenue' => 100000, 'success_payments' => 1, 'total_checkouts' => 2,
            'purchase_attempts' => 2, 'success_purchases' => 1, 'claim_checkouts' => 0,
        ]);

        $this->transaction($this->platformA, '2026-11-11 09:00:00', '2026-11-11 09:10:00', 150000);
        // Paid later on the comparison day: the recorded snapshot is not rebuilt from the latest state.
        $this->transaction($this->platformA, '2026-11-10 09:00:00', '2026-11-10 13:00:00', 999000);

        $metrics = $this->metrics($this->summary('platforms[]=app-a&preset=today'));

        $this->assertSame('snapshot', $metrics['revenue']['comparison']['source']);
        $this->assertSame(100000.0, (float) $metrics['revenue']['comparison']['value']);
        $this->assertSame(50.0, (float) $metrics['revenue']['change']['value']);
        $this->assertSame(50.0, (float) $metrics['conversion']['comparison']['value']);
        $this->assertSame(100000.0, (float) $metrics['average_order_value']['comparison']['value']);
        $this->assertSame('latest', $metrics['revenue']['active']['source']);

        // The recorded payload has no Pembeli Unik, so it stays empty instead of 0.
        $this->assertNull($metrics['unique_buyers']['comparison']['value']);
        $this->assertSame(1, $metrics['unique_buyers']['active']['value']);
    }

    public function test_the_legacy_filter_has_no_sales_summary(): void
    {
        $this->actingAs($this->manager)
            ->getJson(self::URL.'?date_from=2026-11-04&date_to=2026-11-10')
            ->assertOk()
            ->assertJsonPath('data.sales_summary', null)
            ->assertJsonStructure(['data' => ['kpis' => ['total_revenue']]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(string $query): array
    {
        return $this->actingAs($this->manager)->getJson(self::URL.'?'.$query)->assertOk()->json('data.sales_summary');
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, array<string, mixed>>
     */
    private function metrics(array $summary): array
    {
        return array_column($summary['metrics'], null, 'key');
    }

    private function lastSevenDaysCoverage(Platform $platform): void
    {
        $this->coverage($platform, '2026-11-04', '2026-11-10', '2026-11-11 00:00:00');
        $this->coverage($platform, '2026-10-28', '2026-11-03', '2026-11-04 00:00:00');
    }

    private function transaction(
        Platform $platform,
        string $createdAt,
        ?string $paidAt,
        float $netNominal,
        ?MarkazTransactionStatus $status = MarkazTransactionStatus::Success,
        bool $claim = false,
        ?string $refId = null,
        ?int $customerId = null,
        ?float $nominal = null,
        float $adminFee = 2000,
        ?string $name = null,
        ?string $email = null,
    ): Transaction {
        $paid = $paidAt === null ? null : CarbonImmutable::parse($paidAt);

        return Transaction::factory()->forPlatform($platform)->create([
            'markaz_customer_id' => $customerId ?? fake()->unique()->numberBetween(1, 999999),
            'markaz_ref_id' => $refId,
            'markaz_created_at' => $createdAt,
            'markaz_paid_at' => $paidAt,
            'paid_date' => $paid?->toDateString(),
            'paid_time' => $paid?->format('H:i:s'),
            'source_status' => $status?->value,
            'effective_status' => null,
            'is_claim_coupon' => $claim,
            'nominal' => $nominal ?? $netNominal + $adminFee,
            'admin_fee' => $adminFee,
            'net_nominal' => $netNominal,
            'customer_name' => $name,
            'customer_email' => $email,
        ]);
    }

    /**
     * A complete transactions snapshot, which also gives the period resolver the complete-data cutoff.
     *
     * @param  array<string, int|float>  $payload
     */
    private function coverage(Platform $platform, string $from, string $to, string $cutoffAt, array $payload = []): void
    {
        DashboardSnapshot::factory()->for($platform)->create([
            'date_from' => $from,
            'date_to' => $to,
            'cutoff_at' => $cutoffAt,
            'is_complete' => true,
            'payload' => $payload,
            'recorded_at' => $cutoffAt,
        ]);
    }
}
