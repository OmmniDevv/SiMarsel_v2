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
 * FIX-08-BE-01: matched active and comparison Downloader points, period totals, and platform shares.
 * Whole days read downloaders.total; part of a day reads the downloaders snapshot recorded at that cutoff.
 */
class DownloaderTrendTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/performance/trend/downloaders';

    private Account $manager;

    private Platform $platform;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $this->manager = Account::where('username', 'manager')->first();
        $this->platform = Platform::factory()->create(['slug' => 'app-a', 'name' => 'App A']);
        $this->travelTo(CarbonImmutable::parse('2026-11-11T14:30:00+07:00'));
    }

    public function test_ac01_an_hourly_point_is_the_cumulative_total_recorded_at_its_cutoff_not_a_sum_of_snapshots(): void
    {
        $this->snapshot('2026-11-11', '2026-11-11', '2026-11-11 13:00:00', ['2026-11-11' => 150]);
        $this->snapshot('2026-11-11', '2026-11-11', '2026-11-11 14:00:00', ['2026-11-11' => 180]);
        $this->daily('2026-11-11', 180);

        $this->snapshot('2026-11-10', '2026-11-10', '2026-11-10 13:00:00', ['2026-11-10' => 210]);
        $this->snapshot('2026-11-10', '2026-11-10', '2026-11-10 14:00:00', ['2026-11-10' => 240]);
        $this->daily('2026-11-10', 300);

        $trend = $this->trend('preset=today');
        $buckets = $trend['buckets'];

        $this->assertSame('hourly', $trend['granularity']);
        $this->assertSame('downloaders', $trend['filter']['period']['dataset']);
        $this->assertCount(24, $buckets);
        $this->assertSame(['Downloader', 'Downloader Pembanding'], array_column($trend['series'], 'label'));

        $this->assertSame('11 Nov 2026 13.00', $buckets[13]['active']['label']);
        $this->assertSame('2026-11-11T14:00:00+07:00', $buckets[13]['active']['as_of']);
        $this->assertSame(180, $buckets[13]['active']['value']);
        $this->assertSame('10 Nov 2026 13.00', $buckets[13]['comparison']['label']);
        $this->assertSame('2026-11-10T14:00:00+07:00', $buckets[13]['comparison']['as_of']);
        $this->assertSame(240, $buckets[13]['comparison']['value']);
        $this->assertSame('snapshot', $buckets[13]['comparison']['source']);
        $this->assertEquals(['change_percentage' => -25.0, 'trend' => 'down', 'is_comparable' => true], $buckets[13]['change']);

        $this->assertSame(150, $buckets[12]['active']['value']);
        $this->assertSame(210, $buckets[12]['comparison']['value']);

        // No checkpoint at 12.00: the point stays unknown instead of being estimated from the daily total.
        $this->assertSame('unavailable', $buckets[11]['comparison']['availability']);
        $this->assertNull($buckets[11]['comparison']['value']);

        // Hours after the data limit are unknown on both sides.
        $this->assertSame('unavailable', $buckets[14]['active']['availability']);
        $this->assertNull($buckets[14]['active']['value']);
        $this->assertSame('unavailable', $buckets[14]['comparison']['availability']);

        $this->assertSame(['availability' => 'partial', 'as_of' => '2026-11-11T14:00:00+07:00', 'source' => 'latest', 'value' => 180], $trend['totals']['active']);
        $this->assertSame(['availability' => 'partial', 'as_of' => '2026-11-10T14:00:00+07:00', 'source' => 'snapshot', 'value' => 240], $trend['totals']['comparison']);
        $this->assertEquals(-25.0, $trend['totals']['change']['change_percentage']);

        $daily = $this->trend('preset=today&granularity=daily')['buckets'];

        $this->assertCount(1, $daily);
        $this->assertSame(180, $daily[0]['active']['value']);
        $this->assertSame(240, $daily[0]['comparison']['value']);
    }

    public function test_ac02_a_two_day_range_adds_the_daily_totals(): void
    {
        $this->coverage('2026-11-09', '2026-11-10', '2026-11-11 00:00:00');
        $this->coverage('2026-11-07', '2026-11-08', '2026-11-09 00:00:00');
        $this->daily('2026-11-09', 300);
        $this->daily('2026-11-10', 350);
        $this->daily('2026-11-07', 200);
        $this->daily('2026-11-08', 400);

        $trend = $this->trend('preset=date_range&date_from=2026-11-09&date_to=2026-11-10');

        $this->assertSame('daily', $trend['granularity']);
        $this->assertSame([300, 350], array_column(array_column($trend['buckets'], 'active'), 'value'));
        $this->assertSame([200, 400], array_column(array_column($trend['buckets'], 'comparison'), 'value'));
        $this->assertSame(['09 Nov 2026', '10 Nov 2026'], array_column(array_column($trend['buckets'], 'active'), 'label'));
        $this->assertSame(['07 Nov 2026', '08 Nov 2026'], array_column(array_column($trend['buckets'], 'comparison'), 'label'));
        $this->assertEquals(50.0, $trend['buckets'][0]['change']['change_percentage']);
        $this->assertEquals(-12.5, $trend['buckets'][1]['change']['change_percentage']);

        $this->assertSame(['availability' => 'complete', 'as_of' => '2026-11-11T00:00:00+07:00', 'source' => 'latest', 'value' => 650], $trend['totals']['active']);
        $this->assertSame(600, $trend['totals']['comparison']['value']);
        $this->assertEquals(8.33, $trend['totals']['change']['change_percentage']);

        $weekly = $this->trend('preset=date_range&date_from=2026-11-09&date_to=2026-11-10&granularity=weekly');

        $this->assertCount(1, $weekly['buckets']);
        $this->assertSame(650, $weekly['buckets'][0]['active']['value']);
        $this->assertSame(600, $weekly['buckets'][0]['comparison']['value']);
    }

    public function test_ac03_an_empty_comparison_point_is_kept_apart_from_a_comparison_of_zero(): void
    {
        $this->snapshot('2026-11-11', '2026-11-11', '2026-11-11 12:00:00', ['2026-11-11' => 90]);
        $this->snapshot('2026-11-11', '2026-11-11', '2026-11-11 13:00:00', ['2026-11-11' => 150]);
        $this->snapshot('2026-11-11', '2026-11-11', '2026-11-11 14:00:00', ['2026-11-11' => 180]);
        $this->daily('2026-11-11', 180);

        $this->snapshot('2026-11-10', '2026-11-10', '2026-11-10 12:00:00', ['2026-11-10' => 0]);
        $this->snapshot('2026-11-10', '2026-11-10', '2026-11-10 13:00:00', ['2026-11-10' => 210], complete: false);
        $this->snapshot('2026-11-10', '2026-11-10', '2026-11-10 14:00:00', ['2026-11-10' => 240]);

        $buckets = $this->trend('preset=today')['buckets'];

        // Zero from the source: known, shown as 0, compared without a percentage.
        $this->assertSame('complete', $buckets[11]['comparison']['availability']);
        $this->assertSame(0, $buckets[11]['comparison']['value']);
        $this->assertSame(['change_percentage' => null, 'trend' => 'up', 'is_comparable' => true], $buckets[11]['change']);

        // Only an incomplete checkpoint: the comparison is unknown while the active value is kept.
        $this->assertSame(150, $buckets[12]['active']['value']);
        $this->assertSame('unavailable', $buckets[12]['comparison']['availability']);
        $this->assertNull($buckets[12]['comparison']['value']);
        $this->assertSame(['change_percentage' => null, 'trend' => null, 'is_comparable' => false], $buckets[12]['change']);

        $this->assertSame(240, $buckets[13]['comparison']['value']);
    }

    public function test_ac03_a_missing_checkpoint_at_the_comparison_cutoff_leaves_the_whole_comparison_unknown(): void
    {
        $this->snapshot('2026-11-11', '2026-11-11', '2026-11-11 14:00:00', ['2026-11-11' => 180]);
        $this->daily('2026-11-11', 180);
        $this->snapshot('2026-11-10', '2026-11-10', '2026-11-10 13:00:00', ['2026-11-10' => 210]);
        $this->daily('2026-11-10', 300);

        $trend = $this->trend('preset=today');

        $this->assertSame('unavailable', $trend['filter']['period']['comparison']['availability']);
        $this->assertSame(180, $trend['buckets'][13]['active']['value']);
        $this->assertNull($trend['buckets'][13]['comparison']['value']);
        $this->assertNull($trend['buckets'][12]['comparison']['value']);
        $this->assertFalse($trend['buckets'][13]['change']['is_comparable']);
        $this->assertSame(180, $trend['totals']['active']['value']);
        $this->assertNull($trend['totals']['comparison']['value']);
        $this->assertSame('unavailable', $trend['totals']['comparison']['availability']);
        $this->assertNull($trend['totals']['change']['change_percentage']);
    }

    public function test_ac04_an_updated_daily_total_replaces_the_full_period_while_a_recorded_hour_keeps_its_value(): void
    {
        $this->coverage('2026-11-10', '2026-11-10', '2026-11-11 00:00:00');
        $this->coverage('2026-11-09', '2026-11-09', '2026-11-10 00:00:00');
        $this->snapshot('2026-11-10', '2026-11-10', '2026-11-10 14:00:00', ['2026-11-10' => 60]);
        $this->snapshot('2026-11-11', '2026-11-11', '2026-11-11 14:00:00', ['2026-11-11' => 70]);
        $this->daily('2026-11-11', 70);
        $this->daily('2026-11-09', 80);

        $daily = $this->daily('2026-11-10', 100);
        $daily->update(['total' => 99]);

        $full = $this->trend('preset=yesterday&granularity=daily');

        $this->assertSame(99, $full['buckets'][0]['active']['value']);
        $this->assertSame(99, $full['totals']['active']['value']);
        $this->assertSame('latest', $full['totals']['active']['source']);

        $hourly = $this->trend('preset=yesterday')['buckets'];

        $this->assertSame(60, $hourly[13]['active']['value']);
        $this->assertSame(99, $hourly[23]['active']['value']);
        $this->assertSame('2026-11-11T00:00:00+07:00', $hourly[23]['active']['as_of']);

        $today = $this->trend('preset=today&granularity=daily');

        $this->assertSame(60, $today['buckets'][0]['comparison']['value']);
        $this->assertSame(60, $today['totals']['comparison']['value']);
        $this->assertSame('snapshot', $today['totals']['comparison']['source']);
    }

    public function test_ac04_summary_platform_share_and_export_use_the_same_downloader_total(): void
    {
        $this->coverage('2026-11-04', '2026-11-10', '2026-11-11 00:00:00');
        $this->coverage('2026-10-28', '2026-11-03', '2026-11-04 00:00:00');

        $this->dailyRange('2026-11-04', [10, 0, 0, 0, 35, 0, 55]);
        $this->dailyRange('2026-10-28', [0, 0, 40, 0, 0, 0, 0]);

        $trend = $this->trend('preset=last_7_days');

        $this->assertSame(100, $trend['totals']['active']['value']);
        $this->assertSame(100, array_sum(array_column(array_column($trend['buckets'], 'active'), 'value')));
        $this->assertSame(0, $trend['buckets'][2]['active']['value']);
        $this->assertEquals([['slug' => 'app-a', 'name' => 'App A', 'availability' => 'complete', 'as_of' => '2026-11-11T00:00:00+07:00', 'value' => 100, 'share_percentage' => 100.0]], $trend['platforms']);

        $summary = $this->summaryDownloaders('preset=last_7_days');

        $this->assertSame(100, $summary['value']);
        $this->assertSame(40, $summary['previous_value']);
        $this->assertEquals($trend['totals']['change']['change_percentage'], $summary['change_percentage']);
        $this->assertSame('up', $summary['trend']);

        $sheet = $this->exportedSection('platforms[]=app-a&preset=last_7_days', 'Downloader', 'Metode Pembayaran');

        $this->assertSame(['Periode Aktif', 'Downloader', 'Periode Pembanding', 'Downloader Pembanding', 'Perubahan (%)'], $sheet[0]);
        $this->assertSame(['04 Nov 2026', '10', '28 Okt 2026', '0', ''], $sheet[1]);
        $this->assertSame(['Total', '100', '', '40', '150'], $sheet[9]);
        $this->assertSame(['App A', '100', '100'], $sheet[12]);
    }

    public function test_a_date_without_a_daily_total_is_unavailable_while_a_stored_zero_stays_zero(): void
    {
        $this->coverage('2026-11-04', '2026-11-10', '2026-11-11 00:00:00');
        $this->coverage('2026-10-28', '2026-11-03', '2026-11-04 00:00:00');
        $this->dailyRange('2026-10-28', [5, 5, 5, 5, 5, 5, 5]);

        foreach (['2026-11-04' => 10, '2026-11-05' => 0, '2026-11-06' => 20, '2026-11-08' => 30, '2026-11-09' => 40, '2026-11-10' => 50] as $date => $total) {
            $this->daily($date, $total);
        }

        $trend = $this->trend('preset=last_7_days');
        $buckets = $trend['buckets'];

        $this->assertSame('complete', $buckets[1]['active']['availability']);
        $this->assertSame(0, $buckets[1]['active']['value']);

        // 7 Nov has no daily total: unknown, not 0, and not compared.
        $this->assertSame('unavailable', $buckets[3]['active']['availability']);
        $this->assertNull($buckets[3]['active']['value']);
        $this->assertSame(['change_percentage' => null, 'trend' => null, 'is_comparable' => false], $buckets[3]['change']);
        $this->assertSame(5, $buckets[3]['comparison']['value']);

        // A total over an unknown date is unknown too, instead of an undercount of 150.
        $this->assertSame('unavailable', $trend['totals']['active']['availability']);
        $this->assertNull($trend['totals']['active']['value']);
        $this->assertSame(35, $trend['totals']['comparison']['value']);
        $this->assertNull($trend['totals']['change']['trend']);
        $this->assertNull($trend['platforms'][0]['value']);
        $this->assertNull($trend['platforms'][0]['share_percentage']);

        $weekly = $this->trend('preset=last_7_days&granularity=weekly')['buckets'];

        $this->assertSame('unavailable', $weekly[0]['active']['availability']);
        $this->assertNull($weekly[0]['active']['value']);
        $this->assertSame(90, $weekly[1]['active']['value']);
    }

    public function test_a_platform_without_a_daily_total_leaves_the_combined_value_unavailable(): void
    {
        $other = Platform::factory()->create(['slug' => 'app-b', 'name' => 'App B']);

        foreach ([$this->platform, $other] as $platform) {
            $this->coverage('2026-11-09', '2026-11-10', '2026-11-11 00:00:00', $platform);
            $this->coverage('2026-11-07', '2026-11-08', '2026-11-09 00:00:00', $platform);
            $this->daily('2026-11-07', 10, $platform);
            $this->daily('2026-11-08', 10, $platform);
            $this->daily('2026-11-09', 30, $platform);
        }

        $this->daily('2026-11-10', 40);

        $trend = $this->actingAs($this->manager)
            ->getJson(self::URL.'?platforms[]=app-a&platforms[]=app-b&preset=date_range&date_from=2026-11-09&date_to=2026-11-10')
            ->assertOk()
            ->json('data');

        $this->assertSame(60, $trend['buckets'][0]['active']['value']);
        $this->assertSame('unavailable', $trend['buckets'][1]['active']['availability']);
        $this->assertNull($trend['buckets'][1]['active']['value']);
        $this->assertNull($trend['totals']['active']['value']);

        $this->assertSame([70, null], array_column($trend['platforms'], 'value'));
        $this->assertSame([null, null], array_column($trend['platforms'], 'share_percentage'));

        $summary = $this->actingAs($this->manager)
            ->getJson('/api/performance/summary?platforms[]=app-a&platforms[]=app-b&preset=date_range&date_from=2026-11-09&date_to=2026-11-10')
            ->assertOk()
            ->json('data.kpis');

        $this->assertNull($summary['total_downloaders']['value']);
        $this->assertSame(40, $summary['total_downloaders']['previous_value']);
        $this->assertNull($summary['total_downloaders']['trend']);
        $this->assertNull($summary['conversion_rate']['value']);
    }

    public function test_summary_and_trend_report_the_same_unavailable_downloaders_without_data(): void
    {
        $this->coverage('2026-11-04', '2026-11-10', '2026-11-11 00:00:00');
        $this->coverage('2026-10-28', '2026-11-03', '2026-11-04 00:00:00');

        $trend = $this->trend('preset=last_7_days');
        $summary = $this->actingAs($this->manager)
            ->getJson('/api/performance/summary?platforms[]=app-a&preset=last_7_days')
            ->assertOk()
            ->json('data');

        $this->assertSame('unavailable', $trend['totals']['active']['availability']);
        $this->assertNull($trend['totals']['active']['value']);
        $this->assertNull($trend['totals']['comparison']['value']);

        $this->assertSame(
            ['value' => null, 'previous_value' => null, 'change_percentage' => null, 'trend' => null],
            $summary['kpis']['total_downloaders'],
        );
        $this->assertNull($summary['kpis']['conversion_rate']['value']);
        $this->assertTrue($summary['is_empty']);

        // Without completeness metadata the shared aggregation is unavailable as well.
        $this->assertNull($this->summaryDownloaders('preset=last_30_days')['value']);
        $this->assertNull($this->trend('preset=last_30_days')['totals']['active']['value']);
    }

    public function test_summary_reads_the_recorded_equivalent_hour_like_the_trend(): void
    {
        $this->snapshot('2026-11-11', '2026-11-11', '2026-11-11 14:00:00', ['2026-11-11' => 180]);
        $this->daily('2026-11-11', 180);
        $this->snapshot('2026-11-10', '2026-11-10', '2026-11-10 14:00:00', ['2026-11-10' => 240]);
        $this->daily('2026-11-10', 300);

        $trend = $this->trend('preset=today')['totals'];
        $summary = $this->summaryDownloaders('preset=today');

        $this->assertSame($trend['active']['value'], $summary['value']);
        $this->assertSame(240, $summary['previous_value']);
        $this->assertEquals(-25.0, $summary['change_percentage']);
        $this->assertSame('down', $summary['trend']);
    }

    public function test_only_daily_totals_keep_hourly_points_unknown_until_the_end_of_the_day(): void
    {
        $this->coverage('2026-11-10', '2026-11-10', '2026-11-11 00:00:00');
        $this->coverage('2026-11-09', '2026-11-09', '2026-11-10 00:00:00');
        $this->daily('2026-11-10', 120);
        $this->daily('2026-11-09', 0);

        $buckets = $this->trend('preset=yesterday')['buckets'];

        $this->assertCount(24, $buckets);
        $this->assertSame([null], array_unique(array_column(array_column(array_slice($buckets, 0, 23), 'active'), 'value')));
        $this->assertSame('unavailable', $buckets[22]['active']['availability']);
        $this->assertSame(120, $buckets[23]['active']['value']);
        $this->assertSame(0, $buckets[23]['comparison']['value']);
        $this->assertSame(['change_percentage' => null, 'trend' => 'up', 'is_comparable' => true], $buckets[23]['change']);
    }

    public function test_a_platform_without_a_checkpoint_makes_the_combined_point_unknown(): void
    {
        $other = Platform::factory()->create(['slug' => 'app-b', 'name' => 'App B']);

        foreach ([$this->platform, $other] as $platform) {
            $this->snapshot('2026-11-11', '2026-11-11', '2026-11-11 14:00:00', ['2026-11-11' => 50], platform: $platform);
            $this->snapshot('2026-11-10', '2026-11-10', '2026-11-10 14:00:00', ['2026-11-10' => 40], platform: $platform);
            $this->daily('2026-11-11', $platform->is($other) ? 150 : 50, $platform);
        }

        $this->snapshot('2026-11-10', '2026-11-10', '2026-11-10 13:00:00', ['2026-11-10' => 30]);

        $trend = $this->actingAs($this->manager)
            ->getJson(self::URL.'?platforms[]=app-a&platforms[]=app-b&preset=today')
            ->assertOk()
            ->json('data');

        $this->assertSame(80, $trend['buckets'][13]['comparison']['value']);
        $this->assertNull($trend['buckets'][12]['comparison']['value']);
        $this->assertSame(200, $trend['totals']['active']['value']);
        $this->assertEquals([25.0, 75.0], array_column($trend['platforms'], 'share_percentage'));
    }

    public function test_the_period_always_uses_the_downloaders_metadata_and_validates_the_granularity(): void
    {
        $this->coverage('2026-11-10', '2026-11-10', '2026-11-11 00:00:00');

        $trend = $this->trend('preset=yesterday&dataset=transactions');

        $this->assertSame('downloaders', $trend['filter']['period']['dataset']);
        $this->assertSame('complete', $trend['filter']['period']['active']['availability']);
        $this->assertTrue($trend['is_empty']);

        $this->actingAs($this->manager)
            ->getJson(self::URL.'?preset=last_90_days&granularity=hourly')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['granularity']);

        $this->actingAs($this->manager)->getJson(self::URL)->assertUnprocessable()->assertJsonValidationErrors(['preset']);
    }

    public function test_guests_cannot_read_the_downloader_trend(): void
    {
        $this->getJson(self::URL.'?preset=today')->assertUnauthorized();
    }

    /**
     * @return array<string, mixed>
     */
    private function trend(string $query): array
    {
        return $this->actingAs($this->manager)->getJson(self::URL.'?platforms[]=app-a&'.$query)->assertOk()->json('data');
    }

    private function daily(string $date, int $total, ?Platform $platform = null): Downloader
    {
        return Downloader::factory()->for($platform ?? $this->platform)->create(['date' => $date, 'total' => $total]);
    }

    /**
     * @param  list<int>  $totals  One daily total per consecutive date from $from.
     */
    private function dailyRange(string $from, array $totals): void
    {
        foreach ($totals as $offset => $total) {
            $this->daily(CarbonImmutable::parse($from)->addDays($offset)->toDateString(), $total);
        }
    }

    /**
     * The Total Downloader KPI of /summary, called without a dataset like the dashboard does.
     *
     * @return array<string, mixed>
     */
    private function summaryDownloaders(string $query): array
    {
        return $this->actingAs($this->manager)
            ->getJson('/api/performance/summary?platforms[]=app-a&'.$query)
            ->assertOk()
            ->json('data.kpis.total_downloaders');
    }

    /**
     * A complete downloaders record of the whole range, used by the resolver as the data limit.
     */
    private function coverage(string $from, string $to, string $cutoffAt, ?Platform $platform = null): void
    {
        $this->snapshot($from, $to, $cutoffAt, [], platform: $platform);
    }

    /**
     * @param  array<string, int>  $daily  Cumulative total per covered date at the cutoff.
     */
    private function snapshot(string $from, string $to, string $cutoffAt, array $daily, bool $complete = true, ?Platform $platform = null): void
    {
        DashboardSnapshot::factory()->for($platform ?? $this->platform)->downloaders($daily)->create([
            'date_from' => $from,
            'date_to' => $to,
            'cutoff_at' => $cutoffAt,
            'is_complete' => $complete,
            'recorded_at' => $cutoffAt,
        ]);
    }

    /**
     * The rows of one section of the CSV export, up to the title of the next section.
     *
     * @return list<list<string>>
     */
    private function exportedSection(string $query, string $title, string $nextTitle): array
    {
        $response = $this->actingAs($this->manager)->get('/api/performance/export?format=csv&'.$query)->assertOk();
        $handle = fopen($response->baseResponse->getFile()->getPathname(), 'r');
        $rows = [];

        while (($row = fgetcsv($handle, escape: '\\')) !== false) {
            $rows[] = array_map(fn (?string $cell) => (string) $cell, $row);
        }

        fclose($handle);

        $start = array_search([$title], $rows, true) + 1;
        $section = array_slice($rows, $start, array_search([$nextTitle], $rows, true) - $start - 1);

        return array_map(fn (array $row) => $row === [''] ? [] : $row, $section);
    }
}
