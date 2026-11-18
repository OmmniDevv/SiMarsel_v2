<?php

namespace Tests\Feature\Performance;

use App\Enums\PeriodPreset;
use App\Models\Account;
use App\Models\Platform;
use App\Repositories\DashboardSnapshotCoverageRepository;
use App\Services\PerformanceExportService;
use App\Services\PeriodResolverService;
use App\Support\PerformanceFilter;
use App\Support\Period\PeriodCoverageSource;
use App\Support\Period\PeriodSelection;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InMemoryPeriodCoverage;
use Tests\TestCase;

class PerformancePeriodContractTest extends TestCase
{
    use RefreshDatabase;

    private const SUMMARY = '/api/performance/summary';

    private Account $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-11T14:00:00+07:00'));
        $this->seed(DatabaseSeeder::class);

        $this->manager = Account::where('username', 'manager')->first();
        Platform::factory()->create(['slug' => 'app-a', 'name' => 'App A']);

        $this->app->instance(PeriodCoverageSource::class, (new InMemoryPeriodCoverage)
            ->record('2026-11-11', '2026-11-11', '2026-11-11T14:00:00+07:00')
            ->record('2026-11-10', '2026-11-10', '2026-11-10T14:00:00+07:00'));
    }

    public function test_summary_trend_and_export_receive_the_same_period(): void
    {
        $this->actingAs($this->manager);
        $query = '?preset=today&platforms[]=app-a';

        $summary = $this->getJson(self::SUMMARY.$query)->assertOk()->json('data');
        $trend = $this->getJson('/api/performance/trend'.$query.'&metric=revenue')->assertOk()->json('data');

        $export = app(PerformanceExportService::class)->sections(PerformanceFilter::fromResolvedPeriod(
            app(PeriodResolverService::class)->resolve(new PeriodSelection(PeriodPreset::Today, platforms: ['app-a'])),
        ))['Ringkasan KPI'];

        $this->assertSame('2026-11-11T14:00:00+07:00', $summary['filter']['period']['active']['cutoff_at']);
        $this->assertSame('2026-11-10T14:00:00+07:00', $summary['filter']['period']['comparison']['cutoff_at']);
        $this->assertSame($summary['filter']['period'], $trend['filter']['period']);
        $this->assertSame(['2026-11-10', '2026-11-10'], [$summary['previous_period']['date_from'], $summary['previous_period']['date_to']]);

        $this->assertSame(['Periode', '2026-11-11 s/d 2026-11-11'], $export[0]);
        $this->assertSame(['Periode Sebelumnya', '2026-11-10 s/d 2026-11-10'], $export[1]);
        $this->assertSame(['Batas Data Aktif', '2026-11-11T14:00:00+07:00'], $export[3]);
        $this->assertSame(['Batas Data Pembanding', '2026-11-10T14:00:00+07:00'], $export[4]);
    }

    public function test_export_endpoint_accepts_the_period_contract(): void
    {
        $this->actingAs($this->manager)
            ->get('/api/performance/export?format=csv&preset=today')
            ->assertOk()
            ->assertDownload('performa_2026-11-11_2026-11-11.csv');
    }

    public function test_ac02_server_rejects_a_reversed_range_on_the_end_field(): void
    {
        $this->actingAs($this->manager);

        $this->getJson(self::SUMMARY.'?preset=date_range&date_from=2026-11-11&date_to=2026-11-05')
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['date_to' => 'Tanggal akhir tidak boleh sebelum tanggal mulai.']);

        $this->getJson(self::SUMMARY.'?preset=today&comparison=custom&comparison_from=2026-11-05&comparison_to=2026-11-01')
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['comparison_to' => 'Tanggal akhir pembanding tidak boleh sebelum tanggal mulai.']);
    }

    public function test_parameter_combinations_are_validated(): void
    {
        $this->actingAs($this->manager);

        $this->getJson(self::SUMMARY.'?preset=unknown')->assertJsonValidationErrors(['preset' => 'Preset periode tidak valid.']);
        $this->getJson(self::SUMMARY.'?preset=today&comparison=unknown')->assertJsonValidationErrors(['comparison' => 'Pembanding tidak valid.']);
        $this->getJson(self::SUMMARY.'?comparison=auto')->assertJsonValidationErrors(['preset' => 'Preset periode wajib diisi.']);
        $this->getJson(self::SUMMARY.'?preset=date_range')->assertJsonValidationErrors(['date_from', 'date_to']);
        $this->getJson(self::SUMMARY.'?preset=today&comparison=custom')->assertJsonValidationErrors(['comparison_from', 'comparison_to']);
        $this->getJson(self::SUMMARY.'?preset=today&comparison=auto&comparison_from=2026-11-01&comparison_to=2026-11-02')
            ->assertJsonValidationErrors(['comparison_from' => 'Tanggal mulai pembanding hanya dipakai untuk rentang pembanding khusus.']);
        $this->getJson(self::SUMMARY.'?preset=today&date_from=2026-11-01&date_to=2026-11-02')
            ->assertJsonValidationErrors(['filter' => 'Preset periode tidak dapat digabung dengan filter periode lain.']);
        $this->getJson(self::SUMMARY.'?preset=today&year=2026&month=11')->assertJsonValidationErrors(['filter']);
        $this->getJson(self::SUMMARY.'?preset=today&dataset=unknown')->assertJsonValidationErrors(['dataset' => 'Dataset tidak valid.']);
        $this->getJson('/api/performance/export?format=csv&preset=today&dataset=unknown')->assertJsonValidationErrors(['dataset' => 'Dataset tidak valid.']);
    }

    public function test_older_period_parameters_keep_their_contract(): void
    {
        $summary = $this->actingAs($this->manager)
            ->getJson(self::SUMMARY.'?date_from=2026-11-01&date_to=2026-11-07')
            ->assertOk()
            ->json('data');

        $this->assertSame('date_range', $summary['filter']['mode']);
        $this->assertArrayNotHasKey('period', $summary['filter']);
        $this->assertSame(['2026-10-25', '2026-10-31'], [$summary['previous_period']['date_from'], $summary['previous_period']['date_to']]);
    }

    public function test_summary_without_an_equivalent_date_has_no_previous_values(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-04-15T10:00:00+07:00'));

        $summary = $this->actingAs($this->manager)
            ->getJson(self::SUMMARY.'?preset=date_range&date_from=2026-03-31&date_to=2026-03-31&comparison=same_period_last_month')
            ->assertOk()
            ->json('data');

        $this->assertNull($summary['filter']['period']['comparison']);
        $this->assertSame(['date_from' => null, 'date_to' => null, 'ranges' => []], $summary['previous_period']);
        $this->assertNull($summary['kpis']['total_revenue']['previous_value']);
        $this->assertNull($summary['kpis']['total_revenue']['change_percentage']);
    }

    public function test_repository_reads_complete_snapshots_of_the_requested_scope(): void
    {
        $platform = Platform::where('slug', 'app-a')->first();
        $other = Platform::factory()->create(['slug' => 'app-b']);

        Schema::create('dashboard_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_id');
            $table->string('dataset');
            $table->date('date_from');
            $table->date('date_to');
            $table->dateTime('cutoff_at');
            $table->boolean('is_complete');
        });

        $row = ['platform_id' => $platform->id, 'dataset' => 'transactions', 'date_from' => '2026-11-11', 'date_to' => '2026-11-11', 'cutoff_at' => '2026-11-11 14:00:00', 'is_complete' => true];

        DB::table('dashboard_snapshots')->insert([
            $row,
            [...$row, 'cutoff_at' => '2026-11-11 15:00:00', 'is_complete' => false],
            [...$row, 'cutoff_at' => '2026-11-11 16:00:00', 'dataset' => 'downloaders'],
            [...$row, 'cutoff_at' => '2026-11-11 17:00:00', 'date_from' => '2026-11-10'],
        ]);

        $day = CarbonImmutable::parse('2026-11-11', 'Asia/Jakarta');
        $cutoffs = (new DashboardSnapshotCoverageRepository)->completeCutoffs(['app-a', 'app-b'], 'transactions', $day, $day);

        $this->assertSame([], $cutoffs[$other->slug]);
        $this->assertCount(1, $cutoffs['app-a']);
        $this->assertSame('2026-11-11T14:00:00+07:00', $cutoffs['app-a'][0]->toIso8601String());
    }

    public function test_repository_reports_unknown_coverage_until_the_table_exists(): void
    {
        $day = CarbonImmutable::parse('2026-11-11', 'Asia/Jakarta');

        $this->assertSame(['app-a' => []], (new DashboardSnapshotCoverageRepository)->completeCutoffs(['app-a'], 'transactions', $day, $day));
    }
}
