<?php

namespace Tests\Feature\Performance;

use App\Enums\MarkazTransactionStatus;
use App\Enums\PeriodComparison;
use App\Enums\PeriodPreset;
use App\Models\DashboardSnapshot;
use App\Models\Platform;
use App\Models\Transaction;
use App\Services\PeriodResolverService;
use App\Services\PlatformContributionService;
use App\Support\PerformanceFilter;
use App\Support\Period\PeriodSelection;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * STEAM-299 (BE-01): per-platform created-cohort conversion and status breakdown, paid-date success payments,
 * revenue, the comparison revenue, and the contribution share of the selected platforms.
 */
class PlatformTransactionMetricsTest extends TestCase
{
    use RefreshDatabase;

    private Platform $platformA;

    private Platform $platformB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-11T14:30:00+07:00'));

        $this->platformA = Platform::factory()->create(['slug' => 'app-a', 'name' => 'App A']);
        $this->platformB = Platform::factory()->create(['slug' => 'app-b', 'name' => 'App B']);
    }

    /**
     * A complete transactions snapshot for one exact date range; the period resolver matches ranges exactly.
     */
    private function coverage(string $from, string $to): void
    {
        foreach ([$this->platformA, $this->platformB] as $platform) {
            DashboardSnapshot::factory()->for($platform)->create([
                'date_from' => $from,
                'date_to' => $to,
                'cutoff_at' => '2026-11-11 00:00:00',
                'is_complete' => true,
                'payload' => [],
                'recorded_at' => '2026-11-11 00:00:00',
            ]);
        }
    }

    public function test_ac01_conversion_is_60_percent_and_the_status_breakdown_adds_up_to_100(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->transaction($this->platformA, '2026-11-08 09:00:00', '2026-11-08 09:10:00', 1000);
        }

        for ($i = 0; $i < 40; $i++) {
            $this->transaction($this->platformA, '2026-11-08 10:00:00', null, 0, MarkazTransactionStatus::Pending);
        }

        $row = $this->metrics(['app-a'], '2026-11-08', '2026-11-10')['platforms'][0];

        $this->assertSame(100, $row['total_transactions']);
        $this->assertSame(60, $row['created_success_transactions']);
        $this->assertSame(60.0, $row['conversion_percentage']);
        $this->assertFalse($row['conversion_is_provisional']);
        $this->assertSame(100, array_sum(array_column($row['status_breakdown'], 'count')));
        $this->assertSame(
            ['Success', 'Pending', 'Tidak Berhasil', 'Klaim Kupon', 'Status belum tersedia'],
            array_column($row['status_breakdown'], 'label'),
        );
    }

    public function test_ac02_contribution_uses_every_selected_platform_as_the_divisor(): void
    {
        for ($i = 0; $i < 65; $i++) {
            $this->transaction($this->platformA, '2026-11-08 09:00:00', '2026-11-08 09:10:00', 1000);
        }

        for ($i = 0; $i < 195; $i++) {
            $this->transaction($this->platformB, '2026-11-08 09:00:00', '2026-11-08 09:10:00', 1000);
        }

        $metrics = $this->metrics(['app-a', 'app-b'], '2026-11-08', '2026-11-10');

        $this->assertSame(260, $metrics['totals']['success_payments']);
        $this->assertSame(['app-b', 'app-a'], array_column($metrics['platforms'], 'slug'));
        $this->assertSame(75.0, $metrics['platforms'][0]['contribution_percentage']);
        $this->assertSame(25.0, $metrics['platforms'][1]['contribution_percentage']);
        $this->assertSame([1, 2], array_column($metrics['platforms'], 'rank'));
    }

    public function test_ac03_group_members_count_once_and_claims_stay_out_of_contribution(): void
    {
        $buyer = $this->transaction($this->platformA, '2026-11-08 09:00:00', '2026-11-08 09:10:00', 300000);
        $this->transaction($this->platformA, '2026-11-08 09:00:00', '2026-11-08 09:10:00', 0, MarkazTransactionStatus::Success, refId: $buyer->markaz_transaction_id);
        $this->transaction($this->platformA, '2026-11-08 09:30:00', '2026-11-08 09:30:00', 0, MarkazTransactionStatus::Success, claim: true);
        $this->transaction($this->platformB, '2026-11-08 09:00:00', '2026-11-08 09:10:00', 100000);
        $this->transaction($this->platformB, '2026-11-08 10:00:00', null, 0, MarkazTransactionStatus::Pending);

        $metrics = $this->metrics(['app-a', 'app-b'], '2026-11-08', '2026-11-10');
        $a = $metrics['platforms'][0];
        $b = $metrics['platforms'][1];

        $this->assertSame('app-a', $a['slug']);
        $this->assertSame(50.0, $a['contribution_percentage']);
        $this->assertSame(50.0, $b['contribution_percentage']);
        $this->assertSame(300000.0, $a['revenue']);
        $this->assertSame(2, $a['total_transactions']);
        $this->assertSame(2, $b['total_transactions']);
        $this->assertSame(100.0, $a['conversion_percentage']);
        $this->assertSame(50.0, $b['conversion_percentage']);
        $this->assertFalse($b['conversion_is_provisional']);
    }

    public function test_zero_divisor_gives_null_contribution_and_conversion_not_zero(): void
    {
        $metrics = $this->metrics(['app-a', 'app-b'], '2026-11-08', '2026-11-10');

        $this->assertSame(0, $metrics['totals']['success_payments']);
        $this->assertTrue($metrics['is_empty']);
        $this->assertNull($metrics['platforms'][0]['contribution_percentage']);
        $this->assertNull($metrics['platforms'][0]['conversion_percentage']);
    }

    public function test_unknown_status_is_counted_in_conversion_and_marked_provisional(): void
    {
        $this->transaction($this->platformA, '2026-11-08 09:00:00', '2026-11-08 09:10:00', 1000);
        $this->transaction($this->platformA, '2026-11-08 09:00:00', null, 0, null);

        $row = $this->metrics(['app-a'], '2026-11-08', '2026-11-10')['platforms'][0];

        $this->assertSame(50.0, $row['conversion_percentage']);
        $this->assertTrue($row['conversion_is_provisional']);
        $this->assertSame(1, $row['unknown_status_count']);
    }

    public function test_revenue_comparison_uses_the_previous_period_range(): void
    {
        $this->transaction($this->platformA, '2026-11-08 09:00:00', '2026-11-08 09:10:00', 500000);
        $this->transaction($this->platformA, '2026-11-06 09:00:00', '2026-11-06 09:10:00', 200000);

        $row = $this->metrics(['app-a'], '2026-11-08', '2026-11-10', PeriodComparison::PreviousPeriod)['platforms'][0];

        $this->assertSame(500000.0, $row['revenue']);
        $this->assertSame(200000.0, $row['revenue_comparison']);
    }

    private function metrics(array $slugs, string $from, string $to, PeriodComparison $comparison = PeriodComparison::Auto): array
    {
        $this->coverage($from, $to);
        $this->coverage('2026-11-05', '2026-11-07');

        $period = app(PeriodResolverService::class)->resolve(new PeriodSelection(
            preset: PeriodPreset::DateRange,
            comparison: $comparison,
            platforms: $slugs,
            dateFrom: CarbonImmutable::parse($from, 'Asia/Jakarta'),
            dateTo: CarbonImmutable::parse($to, 'Asia/Jakarta'),
        ));

        return app(PlatformContributionService::class)->transactionMetrics(PerformanceFilter::fromResolvedPeriod($period));
    }

    private function transaction(
        Platform $platform,
        string $createdAt,
        ?string $paidAt,
        float $netNominal,
        ?MarkazTransactionStatus $status = MarkazTransactionStatus::Success,
        bool $claim = false,
        ?string $refId = null,
    ): Transaction {
        $paid = $paidAt === null ? null : CarbonImmutable::parse($paidAt);

        return Transaction::factory()->for($platform)->create([
            'markaz_customer_id' => fake()->numberBetween(1, 999999),
            'markaz_ref_id' => $refId,
            'markaz_created_at' => $createdAt,
            'markaz_paid_at' => $paidAt,
            'paid_date' => $paid?->toDateString(),
            'paid_time' => $paid?->format('H:i:s'),
            'source_status' => $status?->value,
            'effective_status' => null,
            'is_claim_coupon' => $claim,
            'nominal' => $netNominal + 2000,
            'admin_fee' => 2000,
            'net_nominal' => $netNominal,
        ]);
    }
}
