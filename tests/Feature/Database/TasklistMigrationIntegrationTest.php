<?php

namespace Tests\Feature\Database;

use App\Exceptions\UnmappableAgendaException;
use App\Models\Account;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskReview;
use App\Services\TasklistMigrationValidator;
use Database\Seeders\LegacyTasklistSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * STEAM-272: the TASK-19 migrations applied to data shaped like the database before them. DatabaseMigrations, not
 * RefreshDatabase, because migrating inside a transaction is not possible on MySQL.
 */
class TasklistMigrationIntegrationTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * The first TASK-19 migration; rolling back to before it also rolls back every later migration.
     */
    private const FIRST_TASK19_MIGRATION = '2026_11_13_083909_widen_name_columns_on_roles_and_divisions_tables';

    /**
     * Rows that existed before TASK-19 and must all still be there afterwards.
     */
    private const AGENDA_CHECK = 'Salinan agenda sama dengan aslinya (is_shared, divisi tujuan, divisi penyelenggara, pembuat, tanggal)';

    private const LEGACY_TABLES = ['accounts', 'roles', 'divisions', 'tasks', 'task_activities', 'task_evidences', 'task_reviews', 'schedule_events'];

    /**
     * @var array<string, int>
     */
    private array $before;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-12 09:00:00');
        $this->artisan('migrate:rollback', ['--step' => $this->stepsBackToBeforeTask19()])->assertSuccessful();
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

    public function test_every_agenda_moves_to_calendar_events_with_its_id_access_and_target_divisions(): void
    {
        $legacy = DB::table('schedule_events')->orderBy('id')->get()->keyBy('title');

        $this->migrateAndSeed();

        $marketing = (int) DB::table('divisions')->where('code_division', 'MKT')->value('id');
        $finance = (int) DB::table('divisions')->where('code_division', 'FIN')->value('id');
        $admin = $this->account('legacy.admin');
        $manager = $this->account('legacy.manager')->id;
        $financeManager = $this->account('legacy.finmanager')->id;
        $expected = [
            // title => [division_id (organiser), is_shared, target divisions, creator]
            'Rapat evaluasi bulanan' => [$marketing, true, [$finance], $manager],
            'Briefing internal Marketing' => [$marketing, false, [$marketing], $manager],
            'Sinkronisasi Marketing dan Finance' => [$finance, false, [$marketing, $finance], $financeManager],
            'Rapat pimpinan' => [null, false, [], $financeManager],
            'Shooting video teaser' => [$marketing, true, [], $manager],     // task link dropped
        ];

        $this->assertSame($legacy->count(), DB::table('calendar_events')->count());

        foreach ($expected as $title => [$divisionId, $isShared, $targets, $creatorId]) {
            $agenda = $legacy[$title];
            $copy = DB::table('calendar_events')->where('id', $agenda->id)->sole();

            $this->assertEquals(
                [$agenda->title, $agenda->description, $divisionId, $isShared, $targets, $creatorId, $agenda->start_date, $agenda->end_date, $agenda->created_at, $agenda->updated_at],
                [
                    $copy->title, $copy->description, $copy->division_id, (bool) $copy->is_shared,
                    DB::table('calendar_event_divisions')->where('calendar_event_id', $copy->id)->orderBy('division_id')->pluck('division_id')->map(fn ($id) => (int) $id)->all(),
                    $copy->creator_id, substr($copy->start_at, 0, 10), $copy->end_at === null ? null : substr($copy->end_at, 0, 10), $copy->created_at, $copy->updated_at,
                ],
                $title,
            );
            $this->assertEquals(
                DB::table('schedule_event_divisions')->where('schedule_event_id', $agenda->id)->orderBy('division_id')->pluck('division_id')->all(),
                $targets,
                "{$title}: every target division is kept",
            );
        }

        $this->assertSame(0, DB::table('calendar_events')->whereNotIn('creator_id', DB::table('accounts')->select('id'))->count());

        // As in the old application: shared agendas for everyone, internal ones for Admin and their target divisions.
        $visible = [
            'legacy.admin' => array_keys($expected),
            'legacy.manager' => ['Briefing internal Marketing', 'Rapat evaluasi bulanan', 'Shooting video teaser', 'Sinkronisasi Marketing dan Finance'],
            'legacy.finmanager' => ['Rapat evaluasi bulanan', 'Shooting video teaser', 'Sinkronisasi Marketing dan Finance'],
            'legacy.budi' => ['Briefing internal Marketing', 'Rapat evaluasi bulanan', 'Shooting video teaser', 'Sinkronisasi Marketing dan Finance'],
            'legacy.dewi' => ['Rapat evaluasi bulanan', 'Shooting video teaser', 'Sinkronisasi Marketing dan Finance'],
        ];

        foreach ($visible as $username => $titles) {
            $account = $this->account($username);
            $seen = fn (string $path, string $url) => collect($this->actingAs($account)->getJson($url)->assertOk()->json($path))
                ->where('type', '!=', 'task')->pluck('title')->sort()->values()->all();
            sort($titles);

            $this->assertSame($titles, $seen('data.events', '/api/tasks/calendar?month=2026-11'), "Calendar {$username}");
            $this->assertSame($titles, $seen('data', '/api/tasks/schedule?from=2026-11-01&to=2026-11-30'), "Timeline {$username}");
        }

        $this->actingAs($admin)
            ->getJson('/api/tasks/calendar?month=2026-11')
            ->assertJsonPath('data.events.2.title', 'Sinkronisasi Marketing dan Finance')
            ->assertJsonPath('data.events.2.is_shared', false)
            ->assertJsonPath('data.events.2.target_divisions', [['code_division' => 'MKT', 'name' => 'Marketing'], ['code_division' => 'FIN', 'name' => 'Finance']])
            ->assertJsonPath('data.events.4.title', 'Shooting video teaser')
            ->assertJsonPath('data.events.4.task_id', null)
            ->assertJsonPath('data.events.4.start_date', '2026-11-20')
            ->assertJsonPath('data.events.4.end_date', '2026-11-22');

        $match = collect(app(TasklistMigrationValidator::class)->validate())->firstWhere('check', self::AGENDA_CHECK);
        $this->assertSame([TasklistMigrationValidator::PASS, '5 sama'], [$match['status'], $match['detail']]);
    }

    public function test_an_agenda_without_a_creator_stops_the_move_and_copies_nothing(): void
    {
        $id = DB::table('schedule_events')->insertGetId([
            'title' => 'Agenda tanpa pembuat', 'owner_division_id' => null, 'start_date' => '2026-11-21', 'is_shared' => true,
            'creator_id' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            $this->artisan('migrate')->run();
            $this->fail('The move should have been refused.');
        } catch (UnmappableAgendaException $exception) {
            $this->assertStringContainsString("#{$id} \"Agenda tanpa pembuat\" tanpa pembuat", $exception->getMessage());
        }

        // Nobody is credited instead, even with Admin accounts around.
        $this->assertTrue(DB::table('accounts')->join('roles', 'roles.id', '=', 'accounts.role_id')->where('roles.code_role', Role::ADMIN)->exists());
        $this->assertSame(0, DB::table('calendar_events')->count());
        $this->assertSame(6, DB::table('schedule_events')->count());
    }

    public function test_the_validator_fails_when_an_agenda_loses_a_target_division_or_its_sharing(): void
    {
        $this->migrateAndSeed();
        $crossDivision = DB::table('schedule_events')->where('title', 'Sinkronisasi Marketing dan Finance')->value('id');
        $shared = DB::table('schedule_events')->where('title', 'Rapat evaluasi bulanan')->value('id');
        $validate = fn () => collect(app(TasklistMigrationValidator::class)->validate())->firstWhere('check', self::AGENDA_CHECK);

        DB::table('calendar_event_divisions')->where('calendar_event_id', $crossDivision)
            ->where('division_id', DB::table('divisions')->where('code_division', 'MKT')->value('id'))->delete();

        $this->assertSame([TasklistMigrationValidator::FAIL, '1 salinan berbeda dari agenda aslinya, 1 di antaranya kehilangan divisi tujuan'], array_values(array_slice($validate(), 1)));

        DB::table('calendar_events')->where('id', $shared)->update(['is_shared' => false]);

        $this->assertSame([TasklistMigrationValidator::FAIL, '2 salinan berbeda dari agenda aslinya, 1 di antaranya kehilangan divisi tujuan'], array_values(array_slice($validate(), 1)));
        $this->artisan('tasks:validate-migration')->assertFailed();

        // Put the copies back, so the rollback after the test is not refused as new data.
        DB::table('calendar_events')->where('id', $shared)->update(['is_shared' => true]);
        DB::table('calendar_event_divisions')->insert(['calendar_event_id' => $crossDivision, 'division_id' => DB::table('divisions')->where('code_division', 'MKT')->value('id')]);
        $this->assertSame(TasklistMigrationValidator::PASS, $validate()['status']);
    }

    public function test_reviews_comments_and_history_follow_the_erd_and_keep_their_data(): void
    {
        $task = $this->task('Video teaser');
        $undated = DB::table('task_activities')->insertGetId(['task_id' => $task->id, 'account_id' => null, 'action' => 'updated', 'created_at' => null]);
        $cycles = DB::table('task_reviews')->orderBy('id')->pluck('cycle', 'id')->map(fn ($cycle) => (int) $cycle)->all();

        $this->migrateAndSeed();

        $columns = fn (string $table) => collect(Schema::getColumns($table))->keyBy('name');
        $reviews = $columns('task_reviews');
        $this->assertFalse($reviews->has('cycle'));
        $this->assertTrue($reviews->has('updated_at'));
        if (DB::getDriverName() === 'mysql') {
            $this->assertSame('varchar(30)', $reviews['decision']['type']);
        }
        $this->assertFalse($columns('task_activities')['created_at']['nullable']);
        $this->assertFalse($columns('task_comments')['created_at']['nullable']);

        // An entry without a time takes its task's creation time; reviews keep their order and get updated_at.
        $this->assertSame('2026-11-01 08:00:00', (string) DB::table('task_activities')->where('id', $undated)->value('created_at'));
        $this->assertSame(0, DB::table('task_reviews')->whereNull('updated_at')->count());
        $this->assertSame($cycles, TaskReview::withCycle()->orderBy('id')->pluck('cycle', 'id')->map(fn ($cycle) => (int) $cycle)->all());

        // Rolling back stores the same cycles again.
        $this->artisan('migrate:rollback', ['--step' => $this->stepsBackToBeforeTask19()])->assertSuccessful();
        $this->assertSame($cycles, DB::table('task_reviews')->orderBy('id')->pluck('cycle', 'id')->map(fn ($cycle) => (int) $cycle)->all());
    }

    public function test_rolling_back_restores_the_legacy_data_and_migrating_again_succeeds(): void
    {
        $legacyTasks = DB::table('tasks')->orderBy('id')->get(['id', 'status', 'pic_id', 'version'])->map(fn ($task) => (array) $task)->all();
        $legacyHistory = DB::table('task_activities')->orderBy('id')->pluck('changes')->map(fn ($changes) => json_decode((string) $changes, true))->all();

        $this->migrateAndSeed();
        $this->artisan('migrate:rollback', ['--step' => $this->stepsBackToBeforeTask19()])->assertSuccessful();

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

    private function stepsBackToBeforeTask19(): int
    {
        return DB::table('migrations')->where('migration', '>=', self::FIRST_TASK19_MIGRATION)->count();
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
