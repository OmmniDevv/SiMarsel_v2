<?php

namespace Tests\Feature\Database;

use App\Models\Account;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Services\TasklistMigrationValidator;
use Database\Seeders\LegacyTasklistSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * STEAM-272: the TASK-19 migrations applied to data shaped like the database before them. DatabaseMigrations, not
 * RefreshDatabase, because migrating inside a transaction is not possible on MySQL.
 */
class TasklistMigrationIntegrationTest extends TestCase
{
    use DatabaseMigrations;

    private const TASK19_MIGRATIONS = 15;

    /**
     * Rows that existed before TASK-19 and must all still be there afterwards.
     */
    private const LEGACY_TABLES = ['accounts', 'roles', 'divisions', 'tasks', 'task_activities', 'task_evidences', 'task_reviews', 'schedule_events'];

    /**
     * @var array<string, int>
     */
    private array $before;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-12 09:00:00');
        $this->artisan('migrate:rollback', ['--step' => self::TASK19_MIGRATIONS])->assertSuccessful();
        $this->seed(LegacyTasklistSeeder::class);
        $this->before = $this->counts();
    }

    public function test_migrating_legacy_data_keeps_every_row_and_passes_validation(): void
    {
        $this->migrateAndSeed();

        $this->assertSame([...$this->before, 'roles' => $this->before['roles'] + 2], $this->counts());
        $this->assertTrue(app(TasklistMigrationValidator::class)->passed(app(TasklistMigrationValidator::class)->validate()));
        $this->artisan('tasks:validate-migration')->assertSuccessful();
    }

    public function test_statuses_and_their_history_are_renamed_and_read_with_the_new_labels(): void
    {
        $this->migrateAndSeed();

        $this->assertSame(
            ['backlog' => 1, 'done' => 1, 'in_progress' => 1, 'on_review' => 1, 'ready_review' => 1, 'todo' => 1],
            DB::table('tasks')->groupBy('status')->orderBy('status')->selectRaw('status, count(*) as total')->pluck('total', 'status')->map(fn ($total) => (int) $total)->all(),
        );

        $onReview = $this->task('Rekap budget iklan');
        $descriptions = $onReview->activities->map(fn (TaskActivity $activity) => $activity->description())->all();
        $this->assertContains('Status: Ready Review → On Review', $descriptions);
        $this->assertContains('Status: On Review → On Process; Catatan: Lengkapi lampiran.', $descriptions);

        $renamedTitle = $this->task('Video teaser')->activities->firstWhere('action', TaskActivity::UPDATED);
        $this->assertSame('on_qa', $renamedTitle->changes['title']['old'], 'Only status values are renamed.');

        $deletedActor = $this->task('Laporan Oktober')->activities->firstWhere('account_id', null);
        $this->assertSame(['id' => null, 'fullname' => 'Mantan Staff'], $deletedActor->actorSummary());
    }

    public function test_every_legacy_pic_becomes_one_active_assignment_with_its_origin(): void
    {
        $this->migrateAndSeed();

        $withPic = DB::table('tasks')->whereNotNull('pic_id')->get(['id', 'pic_id']);
        $this->assertCount(5, $withPic);

        foreach ($withPic as $task) {
            $this->assertSame([(int) $task->pic_id], DB::table('task_assignments')->where('task_id', $task->id)->whereNull('unassigned_at')->pluck('account_id')->map(fn ($id) => (int) $id)->all());
        }

        $reassigned = DB::table('task_assignments')->where('task_id', $this->task('Copywriting landing page')->id)->first();
        $this->assertSame((int) $this->account('legacy.admin')->id, (int) $reassigned->assigned_by);
        $this->assertSame('2026-11-03 10:00:00', (string) $reassigned->assigned_at);

        $neverReassigned = DB::table('task_assignments')->where('task_id', $this->task('Desain banner promo')->id)->first();
        $this->assertSame((int) $this->account('legacy.manager')->id, (int) $neverReassigned->assigned_by);
        $this->assertSame(0, DB::table('task_assignments')->where('task_id', $this->task('Ide konten Desember')->id)->count());

        $this->artisan('tasks:backfill-assignments', ['--apply' => true])->expectsOutputToContain('0 assignment dibuat.')->assertSuccessful();
        $this->assertSame(5, DB::table('task_assignments')->count());
    }

    public function test_existing_accounts_keep_their_role_and_legacy_data_works_through_the_api(): void
    {
        $this->migrateAndSeed();

        $budi = $this->account('legacy.budi');
        $this->assertSame(Role::EMPLOYEE, $budi->role->code_role);
        $this->assertSame('Staff', $budi->role->name);
        $this->assertTrue($budi->is_active);

        $this->actingAs($budi)
            ->getJson('/api/tasks?scope=my_tasks')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.status_counts.ready_review', 1);

        $teaser = $this->task('Video teaser');
        $this->actingAs($budi)
            ->getJson("/api/tasks/{$teaser->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'ready_review')
            ->assertJsonPath('data.pics', [['id' => $budi->id, 'fullname' => 'Budi']])
            ->assertJsonPath('data.evidence.0.file_name', 'teaser.mp4')
            ->assertJsonPath('data.version', 4);

        $financeManager = $this->account('legacy.finmanager');
        $onReview = $this->task('Rekap budget iklan');
        $this->actingAs($financeManager)
            ->postJson("/api/tasks/{$onReview->id}/reviews", ['decision' => 'approve', 'version' => 6])
            ->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonCount(2, 'data.reviews')
            ->assertJsonPath('data.reviews.0.cycle', 2)
            ->assertJsonPath('data.reviews.1.decision', 'revision');

        $this->actingAs($this->account('legacy.admin'))
            ->getJson('/api/tasks/calendar?month=2026-11')
            ->assertOk()
            ->assertJsonCount(5, 'data.events')
            ->assertJsonCount(6, 'data.deadlines');
    }

    public function test_every_agenda_moves_to_calendar_events_and_each_account_still_sees_the_same_agendas(): void
    {
        $legacy = DB::table('schedule_events')->orderBy('id')->get()->keyBy('id');
        $legacyTargets = DB::table('schedule_event_divisions')->get()->groupBy('schedule_event_id')
            ->map(fn ($rows) => $rows->pluck('division_id')->sort()->values()->all());
        // The access rule before the move, computed on the old tables: shared for everyone, internal for Admin and
        // the target divisions.
        $visibleBefore = fn (Account $account) => $legacy
            ->filter(fn ($agenda) => $account->isAdmin() || $agenda->is_shared || in_array($account->division_id, $legacyTargets[$agenda->id] ?? []))
            ->pluck('title')->sort()->values()->all();

        $this->migrateAndSeed();

        $this->assertSame($legacy->count(), DB::table('calendar_events')->count());

        foreach ($legacy as $id => $agenda) {
            $copy = DB::table('calendar_events')->where('legacy_schedule_event_id', $id)->sole();
            $this->assertSame($id, $copy->id, 'Agenda keeps its id');
            $this->assertEquals(
                [$agenda->title, $agenda->description, $agenda->owner_division_id, $agenda->start_date, $agenda->end_date, (bool) $agenda->is_shared, $agenda->task_id, $agenda->creator_id, $agenda->created_at],
                [$copy->title, $copy->description, $copy->division_id, substr($copy->start_at, 0, 10), $copy->end_at === null ? null : substr($copy->end_at, 0, 10), (bool) $copy->is_shared, $copy->task_id, $copy->creator_id, $copy->created_at],
            );
            $this->assertEquals($legacyTargets[$id] ?? [], DB::table('calendar_event_divisions')->where('calendar_event_id', $id)->orderBy('division_id')->pluck('division_id')->all());
        }

        foreach (['legacy.admin', 'legacy.manager', 'legacy.finmanager', 'legacy.budi', 'legacy.dewi'] as $username) {
            $account = $this->account($username);
            $titles = fn (string $path, string $url) => collect($this->actingAs($account)->getJson($url)->assertOk()->json($path))
                ->where('type', '!=', 'task')->pluck('title')->sort()->values()->all();

            $this->assertSame($visibleBefore($account), $titles('data.events', '/api/tasks/calendar?month=2026-11'), "Calendar {$username}");
            $this->assertSame($visibleBefore($account), $titles('data', '/api/tasks/schedule?from=2026-11-01&to=2026-11-30'), "Timeline {$username}");
        }

        $teaser = $this->task('Video teaser');
        $this->actingAs($this->account('legacy.admin'))
            ->getJson('/api/tasks/calendar?month=2026-11')
            ->assertJsonPath('data.events.4.title', 'Shooting video teaser')
            ->assertJsonPath('data.events.4.task_id', $teaser->id)
            ->assertJsonPath('data.events.4.start_date', '2026-11-20')
            ->assertJsonPath('data.events.4.end_date', '2026-11-22');
    }

    public function test_rolling_back_restores_the_legacy_data_and_migrating_again_succeeds(): void
    {
        $legacyTasks = DB::table('tasks')->orderBy('id')->get(['id', 'status', 'pic_id', 'version'])->map(fn ($task) => (array) $task)->all();
        $legacyHistory = DB::table('task_activities')->orderBy('id')->pluck('changes')->map(fn ($changes) => json_decode((string) $changes, true))->all();

        $this->migrateAndSeed();
        $this->artisan('migrate:rollback', ['--step' => self::TASK19_MIGRATIONS])->assertSuccessful();

        $this->assertEquals($legacyTasks, DB::table('tasks')->orderBy('id')->get(['id', 'status', 'pic_id', 'version'])->map(fn ($task) => (array) $task)->all());
        $this->assertEquals($legacyHistory, DB::table('task_activities')->orderBy('id')->pluck('changes')->map(fn ($changes) => json_decode((string) $changes, true))->all());

        $this->migrateAndSeed();
        $this->artisan('tasks:validate-migration')->assertSuccessful();
        $this->assertSame(5, DB::table('task_assignments')->whereNull('unassigned_at')->count());
    }

    public function test_the_validator_reports_broken_data(): void
    {
        $this->migrateAndSeed();
        $todo = $this->task('Desain banner promo');
        DB::table('task_assignments')->where('task_id', $todo->id)->delete();
        DB::table('tasks')->where('id', $todo->id)->update(['status' => 'on_qa']);

        $results = collect(app(TasklistMigrationValidator::class)->validate())->keyBy('check');

        $this->assertSame(TasklistMigrationValidator::FAIL, $results['Tidak ada status QA lama di tasks']['status']);
        $this->assertSame(TasklistMigrationValidator::FAIL, $results['PIC lama (pic_id) sudah di-backfill']['status']);
        $this->assertSame(TasklistMigrationValidator::FAIL, $results['Task di luar Back Log punya PIC aktif']['status']);
        $this->artisan('tasks:validate-migration')->assertFailed();
    }

    /**
     * The deploy steps: migrate, then the role and permission seeders.
     */
    private function migrateAndSeed(): void
    {
        $this->artisan('migrate')->assertSuccessful();
        $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        return collect(self::LEGACY_TABLES)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all();
    }

    private function task(string $title): Task
    {
        return Task::withTrashed()->where('title', $title)->sole();
    }

    private function account(string $username): Account
    {
        return Account::where('username', $username)->sole();
    }
}
