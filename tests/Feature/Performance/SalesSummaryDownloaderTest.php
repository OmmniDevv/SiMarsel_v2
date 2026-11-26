<?php

namespace Tests\Feature\Performance;

use App\Models\Account;
use App\Models\DashboardSnapshot;
use App\Models\Downloader;
use App\Models\Platform;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FIX-05-BE-02: the seventh metric of the Ringkasan Sales, Downloader, read from the shared Downloader aggregation
 * (FIX-08) on the same platforms and resolved range as the Downloader chart and table.
 */
class SalesSummaryDownloaderTest extends TestCase
{
    use RefreshDatabase;

    /** Last 7 days on 11 Nov 2026: active 04 - 10 Nov, comparison 28 Okt - 03 Nov. */
    private const QUERY = 'platforms[]=app-a&preset=last_7_days';

    private Account $manager;

    private Platform $platform;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $this->manager = Account::where('username', 'manager')->first();
        $this->platform = Platform::factory()->create(['slug' => 'app-a', 'name' => 'App A']);
        $this->travelTo(CarbonImmutable::parse('2026-11-11T14:30:00+07:00'));

        $this->coverage('2026-11-04', '2026-11-10', '2026-11-11 00:00:00');
        $this->coverage('2026-10-28', '2026-11-03', '2026-11-04 00:00:00');
        $this->dailyRange('2026-11-04', [40, 30, 0, 50, 60, 20, 40]);
    }

    public function test_ac01_active_240_against_comparison_200_gives_plus_20_percent(): void
    {
        $this->dailyRange('2026-10-28', [20, 30, 40, 0, 50, 30, 30]);

        $summary = $this->summary();
        $downloaders = $this->downloaders($summary);

        $this->assertSame(
            ['revenue', 'success_payments', 'total_checkouts', 'conversion', 'average_order_value', 'unique_buyers', 'downloaders'],
            array_column($summary['metrics'], 'key'),
        );
        $this->assertSame('Downloader', $downloaders['label']);
        $this->assertSame('supporting', $downloaders['group']);
        $this->assertSame('count', $downloaders['unit']);

        $this->assertSame(240, $downloaders['active']['value']);
        $this->assertSame('complete', $downloaders['active']['availability']);
        $this->assertSame(200, $downloaders['comparison']['value']);
        $this->assertSame('complete', $downloaders['comparison']['availability']);
        $this->assertEquals(['value' => 20.0, 'unit' => 'percent', 'trend' => 'up', 'is_trimmed' => false, 'active_value' => 240, 'comparison_value' => 200], $downloaders['change']);
    }

    public function test_the_values_are_the_same_totals_as_the_downloader_trend(): void
    {
        $this->dailyRange('2026-10-28', [20, 30, 40, 0, 50, 30, 30]);

        $totals = $this->actingAs($this->manager)
            ->getJson('/api/performance/trend/downloaders?'.self::QUERY)
            ->assertOk()
            ->json('data.totals');
        $downloaders = $this->downloaders($this->summary());

        $this->assertSame($totals['active']['value'], $downloaders['active']['value']);
        $this->assertSame($totals['active']['as_of'], $downloaders['active']['as_of']);
        $this->assertSame($totals['comparison']['value'], $downloaders['comparison']['value']);
        $this->assertEquals($totals['change']['change_percentage'], $downloaders['change']['value']);
        $this->assertSame($totals['change']['trend'], $downloaders['change']['trend']);
    }

    public function test_ac02_a_comparison_of_zero_keeps_240_and_0_without_a_percentage(): void
    {
        $this->dailyRange('2026-10-28', [0, 0, 0, 0, 0, 0, 0]);

        $downloaders = $this->downloaders($this->summary());

        $this->assertSame(240, $downloaders['active']['value']);
        $this->assertSame(0, $downloaders['comparison']['value']);
        $this->assertSame('complete', $downloaders['comparison']['availability']);
        $this->assertNull($downloaders['change']['value']);
        $this->assertSame('up', $downloaders['change']['trend']);
    }

    public function test_ac02_an_unknown_comparison_keeps_240_without_a_change(): void
    {
        // The comparison range has no daily totals: its total is unknown, not 0.
        $downloaders = $this->downloaders($this->summary());

        $this->assertSame(240, $downloaders['active']['value']);
        $this->assertSame('complete', $downloaders['active']['availability']);
        $this->assertNull($downloaders['comparison']['value']);
        $this->assertSame('unavailable', $downloaders['comparison']['availability']);
        $this->assertNull($downloaders['change']['value']);
        $this->assertNull($downloaders['change']['trend']);
        $this->assertNull($downloaders['change']['comparison_value']);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(): array
    {
        return $this->actingAs($this->manager)
            ->getJson('/api/performance/summary?'.self::QUERY)
            ->assertOk()
            ->json('data.sales_summary');
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function downloaders(array $summary): array
    {
        return array_column($summary['metrics'], null, 'key')['downloaders'];
    }

    /**
     * @param  list<int>  $totals  One daily total per consecutive date from $from.
     */
    private function dailyRange(string $from, array $totals): void
    {
        foreach ($totals as $offset => $total) {
            Downloader::factory()->for($this->platform)->create([
                'date' => CarbonImmutable::parse($from)->addDays($offset)->toDateString(),
                'total' => $total,
            ]);
        }
    }

    /**
     * A complete downloaders record of the whole range, used by the resolver as the data limit.
     */
    private function coverage(string $from, string $to, string $cutoffAt): void
    {
        DashboardSnapshot::factory()->for($this->platform)->downloaders([])->create([
            'date_from' => $from,
            'date_to' => $to,
            'cutoff_at' => $cutoffAt,
            'is_complete' => true,
            'recorded_at' => $cutoffAt,
        ]);
    }
}
