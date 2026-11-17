<?php

namespace Tests\Feature\Database;

use App\Exceptions\UnsafeTasklistRollbackException;
use App\Models\Account;
use App\Models\Task;
use App\Services\TasklistMigrationValidator;
use App\Services\TasklistRecoveryService;
use Database\Seeders\LegacyTasklistSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Recovery of the TASK-19 changes: a schema rollback before any new data, and recovery that keeps the latest data
 * once PICs, comments, and agendas have been added.
 */
class TasklistRecoveryTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * The first TASK-19 migration; rolling back to before it also rolls back every later migration.
     */
    private const FIRST_TASK19_MIGRATION = '2026_11_13_083909_widen_name_columns_on_roles_and_divisions_tables';

    protected function setUp(): void
    {
        parent::setUp();

        // The trait rolls the last batch back after each test, whatever data the test left behind.
        config(['tasklist.allow_destructive_rollback' => true]);

        $this->travelTo('2026-11-12 09:00:00');
        $this->artisan('migrate:rollback', ['--step' => $this->stepsBackToBeforeTask19()])->assertSuccessful();
        $this->seed(LegacyTasklistSeeder::class);
        $this->artisan('migrate')->assertSuccessful();
        $this->seed([RoleSeeder::class, PermissionSeeder::class]);
        $this->travelTo('2026-11-14 10:00:00');
    }

    protected function tearDown(): void
    {
        config(['tasklist.allow_destructive_rollback' => true]);

        parent::tearDown();
    }

    public function test_right_after_the_deploy_there_is_no_new_data_and_rolling_back_is_allowed(): void
    {
        config(['tasklist.allow_destructive_rollback' => false]);

        $this->assertSame(0, array_sum(app(TasklistRecoveryService::class)->newDataReport()));
        $this->artisan('tasks:recovery')->expectsOutputToContain('Rollback aman')->assertSuccessful();
        $this->artisan('migrate:rollback', ['--step' => $this->stepsBackToBeforeTask19()])->assertSuccessful();

        $this->assertFalse(Schema::hasTable('task_assignments'));
        $this->assertSame(5, DB::table('schedule_events')->count());
    }

    public function test_rolling_back_is_refused_once_new_data_exists_and_nothing_is_lost(): void
    {
        config(['tasklist.allow_destructive_rollback' => false]);
        $this->useTheNewFeatures();

        $this->artisan('tasks:recovery')->expectsOutputToContain('Jangan rollback skema')->assertSuccessful();

        try {
            $this->artisan('migrate:rollback', ['--step' => $this->stepsBackToBeforeTask19()])->run();
            $this->fail('The rollback should have been refused.');
        } catch (UnsafeTasklistRollbackException $exception) {
            $this->assertStringContainsString('Komentar tugas: 1', $exception->getMessage());
        }

        $this->assertSame(1, DB::table('task_comments')->count());
        $this->assertSame(2, DB::table('task_assignments')->where('task_id', $this->task('Desain banner promo')->id)->whereNull('unassigned_at')->count());
        $this->assertSame(6, DB::table('calendar_events')->count());
    }

    public function test_recovery_keeps_the_latest_data_through_the_old_application_and_back(): void
    {
        $this->useTheNewFeatures();
        $banner = $this->task('Desain banner promo');
        $budi = $this->account('legacy.budi');
        $sari = $this->account('legacy.sari');
        $before = [DB::table('tasks')->get(), DB::table('schedule_events')->get()];

        // A dry run changes nothing.
        $this->artisan('tasks:recovery', ['action' => 'to-legacy'])->expectsOutputToContain('Mode laporan')->assertSuccessful();
        $this->assertEquals($before, [DB::table('tasks')->get(), DB::table('schedule_events')->get()]);

        $this->artisan('tasks:recovery', ['action' => 'to-legacy', '--apply' => true])->assertSuccessful();

        // The old application reads one PIC per task, the old status names, and schedule_events.
        $this->assertSame($budi->id, (int) DB::table('tasks')->where('id', $banner->id)->value('pic_id'));
        $this->assertSame('ready_for_qa', DB::table('tasks')->where('id', $banner->id)->value('status'));
        $this->assertSame($sari->id, (int) DB::table('tasks')->where('title', 'Konten baru multi-PIC')->value('pic_id'));
        $this->assertSame(0, DB::table('tasks')->whereIn('status', ['ready_review', 'on_review'])->count());
        $this->assertSame(['Rapat evaluasi bulanan (dimajukan)', 'Briefing internal Marketing', 'Sinkronisasi Marketing dan Finance', 'Shooting video teaser', 'Workshop konten'],
            DB::table('schedule_events')->orderBy('start_date')->pluck('title')->all());
        // A division agenda becomes an internal agenda for that division, under the same id.
        $workshop = DB::table('schedule_events')->where('title', 'Workshop konten')->sole();
        $this->assertSame((int) DB::table('calendar_events')->where('title', 'Workshop konten')->value('id'), (int) $workshop->id);
        $this->assertFalse((bool) $workshop->is_shared);
        $this->assertSame([$banner->division_id], DB::table('schedule_event_divisions')->where('schedule_event_id', $workshop->id)->pluck('division_id')->map(fn ($id) => (int) $id)->all());
        // An agenda that was not changed keeps its original row, including its related task.
        $this->assertNotNull(DB::table('schedule_events')->where('title', 'Shooting video teaser')->value('task_id'));
        // Nothing written by the new application is dropped.
        $this->assertSame(1, DB::table('task_comments')->count());
        $this->assertSame(2, DB::table('task_assignments')->where('task_id', $banner->id)->whereNull('unassigned_at')->count());

        // Meanwhile the old application changes a PIC and a status, adds agendas, and deletes one.
        $this->travelTo('2026-11-15 10:00:00');
        $inProgress = $this->task('Copywriting landing page');
        DB::table('tasks')->where('id', $inProgress->id)->update(['pic_id' => $budi->id, 'status' => 'ready_for_qa']);
        DB::table('schedule_events')->insert([
            'title' => 'Agenda dari aplikasi lama', 'owner_division_id' => null, 'start_date' => '2026-11-25', 'end_date' => null,
            'is_shared' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $finance = (int) DB::table('divisions')->where('code_division', 'FIN')->value('id');
        $forFinance = DB::table('schedule_events')->insertGetId([
            'title' => 'Agenda internal Finance', 'owner_division_id' => null, 'start_date' => '2026-11-26', 'end_date' => null,
            'is_shared' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('schedule_event_divisions')->insert(['schedule_event_id' => $forFinance, 'division_id' => $finance]);
        DB::table('schedule_events')->where('title', 'Briefing internal Marketing')->delete();

        $this->artisan('tasks:recovery', ['action' => 'from-legacy', '--apply' => true])->assertSuccessful();

        $this->assertSame(0, DB::table('tasks')->whereIn('status', ['ready_for_qa', 'on_qa'])->count());
        $this->assertSame([$budi->id], $this->activePics($inProgress));
        $this->assertSame([$budi->id, $sari->id], $this->activePics($banner), 'Both PICs of the multi-PIC task are kept');
        $this->assertTrue(DB::table('calendar_events')->where('title', 'Agenda dari aplikasi lama')->whereNull('deleted_at')->whereNull('division_id')->exists());
        $this->assertSame($finance, (int) DB::table('calendar_events')->where('id', $forFinance)->whereNull('deleted_at')->value('division_id'));
        $this->assertTrue(DB::table('calendar_events')->where('title', 'Workshop konten')->whereNull('deleted_at')->exists());
        $this->assertNotNull(DB::table('calendar_events')->where('title', 'Briefing internal Marketing')->value('deleted_at'), 'Deleted by the old application');
        $this->assertNotNull(DB::table('calendar_events')->where('title', 'Rapat pimpinan')->value('deleted_at'), 'Deleted by the new application');
        $this->assertSame(1, DB::table('task_comments')->count());
        $this->assertTrue(app(TasklistMigrationValidator::class)->passed(app(TasklistMigrationValidator::class)->validate()));

        $this->actingAs($this->account('legacy.manager'))
            ->getJson("/api/tasks/{$banner->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'ready_review')
            ->assertJsonCount(2, 'data.pics');

        // Running it again changes nothing.
        $this->assertSame(0, array_sum(app(TasklistRecoveryService::class)->fromLegacy(true)));
    }

    public function test_taking_back_agendas_never_deletes_one_the_old_application_never_had(): void
    {
        $this->useTheNewFeatures();

        // from-legacy without a to-legacy first: Workshop konten exists only in calendar_events.
        $this->artisan('tasks:recovery', ['action' => 'from-legacy', '--apply' => true])->assertSuccessful();

        $this->assertTrue(DB::table('calendar_events')->where('title', 'Workshop konten')->whereNull('deleted_at')->exists());
    }

    /**
     * What users do after the deploy: a second PIC, a new multi-PIC task, a status move, a comment, and agendas
     * added, edited, and deleted.
     */
    private function useTheNewFeatures(): void
    {
        $manager = $this->account('legacy.manager');
        $budi = $this->account('legacy.budi');
        $sari = $this->account('legacy.sari');
        $banner = $this->task('Desain banner promo');

        $this->actingAs($manager)
            ->patchJson("/api/tasks/{$banner->id}/assignment", ['version' => $banner->version, 'pic_ids' => [$budi->id, $sari->id]])
            ->assertOk();
        DB::table('tasks')->where('id', $banner->id)->update(['status' => 'ready_review']);

        $this->actingAs($manager)->postJson('/api/tasks', [
            'title' => 'Konten baru multi-PIC', 'division_id' => $banner->division_id, 'priority' => 'medium', 'status' => 'todo',
            'pic_ids' => [$sari->id, $budi->id], 'deadline' => '2026-11-28',
        ])->assertCreated();

        DB::table('task_comments')->insert([
            'task_id' => $banner->id, 'author_id' => $sari->id, 'content' => 'Draft pertama sudah diunggah.', 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('calendar_events')->insert([
            'title' => 'Workshop konten', 'division_id' => $banner->division_id, 'creator_id' => $manager->id,
            'start_at' => '2026-11-26 00:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('calendar_events')->where('title', 'Rapat evaluasi bulanan')->update(['title' => 'Rapat evaluasi bulanan (dimajukan)', 'start_at' => '2026-11-14 00:00:00', 'updated_at' => now()]);
        DB::table('calendar_events')->where('title', 'Rapat pimpinan')->update(['deleted_at' => now(), 'updated_at' => now()]);
    }

    /**
     * @return array<int, int>
     */
    private function activePics(Task $task): array
    {
        return DB::table('task_assignments')->where('task_id', $task->id)->whereNull('unassigned_at')
            ->orderBy('account_id')->pluck('account_id')->map(fn ($id) => (int) $id)->all();
    }

    private function task(string $title): Task
    {
        return Task::withTrashed()->where('title', $title)->sole();
    }

    private function account(string $username): Account
    {
        return Account::where('username', $username)->sole();
    }

    private function stepsBackToBeforeTask19(): int
    {
        return DB::table('migrations')->where('migration', '>=', self::FIRST_TASK19_MIGRATION)->count();
    }
}
