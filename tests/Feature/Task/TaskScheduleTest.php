<?php

namespace Tests\Feature\Task;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Account;
use App\Models\CalendarEvent;
use App\Models\Division;
use App\Models\Role;
use App\Models\Task;
use Database\Seeders\CalendarEventSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class TaskScheduleTest extends TestCase
{
    use RefreshDatabase;

    private const PERIOD = '/api/tasks/schedule?from=2026-10-12&to=2026-10-18';

    private Division $marketing;

    private Division $finance;

    private Account $admin;

    private Account $manager;

    private Account $staff;

    private Task $staffTask;

    private Task $financeTask;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $this->marketing = Division::create(['code_division' => 'MKT', 'name' => 'Marketing']);
        $this->finance = Division::create(['code_division' => 'FIN', 'name' => 'Finance']);

        $this->admin = $this->account(Role::ADMIN, 'admin', null);
        $this->manager = $this->account(Role::MANAGER, 'mkt.manager', $this->marketing);
        $this->staff = $this->account(Role::EMPLOYEE, 'mkt.staff', $this->marketing);

        $this->staffTask = $this->task('Update landing page', $this->marketing, $this->staff, '2026-10-13', '2026-10-15');
        $this->financeTask = $this->task('Laporan bulanan', $this->finance, null, '2026-10-10', '2026-10-20');
    }

    // Akses

    public function test_guest_cannot_load_the_schedule(): void
    {
        $this->getJson(self::PERIOD)->assertUnauthorized();
    }

    public function test_every_role_sees_the_tasks_of_every_division(): void
    {
        foreach ([$this->admin, $this->manager, $this->staff] as $account) {
            $this->assertSame(
                [$this->financeTask->id, $this->staffTask->id],
                $this->schedule($account)->assertOk()->json('data.*.id'),
            );
        }
    }

    public function test_every_item_opens_its_detail_for_every_active_account(): void
    {
        $this->assertSame([true, true], $this->detailAccess($this->admin));
        $this->assertSame([true, true], $this->detailAccess($this->manager));
        $this->assertSame([true, true], $this->detailAccess($this->staff));

        $this->actingAs($this->staff)->getJson("/api/tasks/{$this->financeTask->id}")->assertOk()->assertJsonPath('data.can_edit', false);
    }

    public function test_an_item_carries_only_its_schedule_and_not_the_task_internals(): void
    {
        $item = $this->schedule($this->staff)->json('data.1');

        $this->assertSame([
            'id' => $this->staffTask->id,
            'type' => 'task',
            'title' => 'Update landing page',
            'division' => ['code_division' => 'MKT', 'name' => 'Marketing'],
            'target_divisions' => [],
            'start_date' => '2026-10-13',
            'end_date' => '2026-10-15',
            'status' => 'in_progress',
            'priority' => 'high',
            'task_id' => $this->staffTask->id,
            'can_view_detail' => true,
        ], $item);
    }

    public function test_the_schedule_is_read_only(): void
    {
        foreach (['postJson', 'putJson', 'patchJson', 'deleteJson'] as $method) {
            $this->actingAs($this->admin)->{$method}(self::PERIOD)->assertMethodNotAllowed();
        }
    }

    // Periode

    public function test_tasks_overlapping_the_period_edges_are_included_and_the_rest_left_out(): void
    {
        $endsOnFirstDay = $this->task('Selesai di hari pertama', $this->marketing, null, '2026-10-01', '2026-10-12');
        $startsOnLastDay = $this->task('Mulai di hari terakhir', $this->marketing, null, '2026-10-18', '2026-10-30');
        $this->task('Sebelum periode', $this->marketing, null, '2026-10-01', '2026-10-11');
        $this->task('Sesudah periode', $this->marketing, null, '2026-10-19', '2026-10-25');

        $ids = $this->schedule($this->admin)->json('data.*.id');

        $this->assertSame([$endsOnFirstDay->id, $this->financeTask->id, $this->staffTask->id, $startsOnLastDay->id], $ids);
    }

    public function test_a_task_with_one_date_is_placed_on_that_day_and_one_without_dates_is_left_out(): void
    {
        $deadlineOnly = $this->task('Hanya deadline', $this->marketing, null, null, '2026-10-16');
        $startOnly = $this->task('Hanya mulai', $this->marketing, null, '2026-10-14', null);
        $this->task('Hanya mulai di luar periode', $this->marketing, null, '2026-10-20', null);
        $this->task('Tanpa tanggal', $this->marketing, null, null, null);

        $data = collect($this->schedule($this->admin)->json('data'))->keyBy('id');

        $this->assertEqualsCanonicalizing(
            [$this->financeTask->id, $this->staffTask->id, $deadlineOnly->id, $startOnly->id],
            $data->keys()->all(),
        );
        $this->assertSame([null, '2026-10-16'], [$data[$deadlineOnly->id]['start_date'], $data[$deadlineOnly->id]['end_date']]);
        $this->assertSame(['2026-10-14', null], [$data[$startOnly->id]['start_date'], $data[$startOnly->id]['end_date']]);
    }

    public function test_items_are_ordered_by_the_day_they_start(): void
    {
        $deadlineOnly = $this->task('Deadline tanggal 12', $this->marketing, null, null, '2026-10-12');
        $event = CalendarEvent::factory()->create(['start_at' => '2026-10-11', 'end_at' => '2026-10-13']);

        $this->assertSame(
            [['task', $this->financeTask->id], ['event', $event->id], ['task', $deadlineOnly->id], ['task', $this->staffTask->id]],
            collect($this->schedule($this->admin)->json('data'))->map(fn (array $item) => [$item['type'], $item['id']])->all(),
        );
    }

    // Filter divisi

    public function test_a_division_focuses_the_tasks_its_own_agendas_and_the_company_wide_ones(): void
    {
        $general = CalendarEvent::factory()->create(['start_at' => '2026-10-14', 'division_id' => null]);
        $forMarketing = CalendarEvent::factory()->internalTo($this->marketing)->create(['start_at' => '2026-10-15']);
        CalendarEvent::factory()->internalTo($this->finance)->create(['start_at' => '2026-10-15']);

        $data = $this->actingAs($this->admin)->getJson(self::PERIOD.'&division=MKT')->assertOk()->json('data');

        $this->assertSame(
            [['task', $this->staffTask->id], ['event', $general->id], ['event', $forMarketing->id]],
            collect($data)->map(fn (array $item) => [$item['type'], $item['id']])->all(),
        );
    }

    public function test_a_division_focus_never_widens_the_agendas_an_account_may_see(): void
    {
        $forFinance = CalendarEvent::factory()->internalTo($this->finance)->create(['start_at' => '2026-10-15']);

        $this->assertNotContains($forFinance->id, $this->eventIds($this->staff, '&division=MKT'));
        $this->assertNotContains($forFinance->id, $this->eventIds($this->staff, '&division=FIN'));
        $this->assertContains($forFinance->id, $this->eventIds($this->admin, '&division=FIN'));
    }

    public function test_without_a_division_the_schedule_covers_every_division(): void
    {
        $this->schedule($this->admin)->assertJsonCount(2, 'data');
    }

    // Akses agenda (division_id)

    public function test_a_company_wide_agenda_is_visible_to_every_role(): void
    {
        $general = CalendarEvent::factory()->create(['start_at' => '2026-10-14', 'division_id' => null]);
        $supervisor = $this->account(Role::SUPERVISOR, 'fin.spv', $this->finance);
        $freelance = $this->account(Role::FREELANCE, 'mkt.freelance', $this->marketing);

        foreach ([$this->admin, $this->manager, $supervisor, $this->staff, $freelance, $this->financeStaff()] as $account) {
            $this->assertSame([$general->id], $this->eventIds($account));
        }
    }

    public function test_an_agenda_with_a_division_is_visible_to_that_division_only(): void
    {
        $forMarketing = CalendarEvent::factory()->internalTo($this->marketing)->create(['start_at' => '2026-10-14']);
        $marketingFreelance = $this->account(Role::FREELANCE, 'mkt.freelance', $this->marketing);
        $financeSupervisor = $this->account(Role::SUPERVISOR, 'fin.spv', $this->finance);
        $noDivision = $this->account(Role::EMPLOYEE, 'no.division', null);

        $this->assertSame([$forMarketing->id], $this->eventIds($this->staff));
        $this->assertSame([$forMarketing->id], $this->eventIds($marketingFreelance));
        $this->assertSame([], $this->eventIds($this->financeStaff()));
        $this->assertSame([], $this->eventIds($financeSupervisor));
        $this->assertSame([], $this->eventIds($noDivision));
    }

    public function test_admin_and_manager_see_the_agendas_of_every_division(): void
    {
        $forFinance = CalendarEvent::factory()->internalTo($this->finance)->create(['start_at' => '2026-10-14']);
        $forMarketing = CalendarEvent::factory()->internalTo($this->marketing)->create(['start_at' => '2026-10-15']);
        $financeManager = $this->account(Role::MANAGER, 'fin.manager', $this->finance);

        foreach ([$this->admin, $this->manager, $financeManager] as $account) {
            $this->assertSame([$forFinance->id, $forMarketing->id], $this->eventIds($account));
        }
    }

    public function test_an_inactive_account_sees_no_agenda(): void
    {
        CalendarEvent::factory()->create(['start_at' => '2026-10-14', 'division_id' => null]);
        $this->staff->update(['is_active' => false]);

        $this->assertSame(0, CalendarEvent::visibleTo($this->staff->fresh())->count());
    }

    public function test_an_agenda_names_its_division_and_is_not_linked_to_a_task(): void
    {
        $general = CalendarEvent::factory()->create(['title' => 'Rapat koordinasi', 'start_at' => '2026-10-14', 'end_at' => null]);
        CalendarEvent::factory()->internalTo($this->marketing)->create(['start_at' => '2026-10-15']);

        $events = collect($this->schedule($this->staff)->json('data'))->where('type', 'event')->values();

        $this->assertSame([
            'id' => $general->id,
            'type' => 'event',
            'title' => 'Rapat koordinasi',
            'division' => null,
            'target_divisions' => [],
            'start_date' => '2026-10-14',
            'end_date' => null,
            'status' => null,
            'priority' => null,
            'task_id' => null,
            'can_view_detail' => false,
        ], $events[0]);
        $this->assertSame(['code_division' => 'MKT', 'name' => 'Marketing'], $events[1]['division']);
        $this->assertSame([['code_division' => 'MKT', 'name' => 'Marketing']], $events[1]['target_divisions']);
        $this->assertNull($events[1]['task_id']);
    }

    public function test_agendas_overlapping_the_period_edges_are_included_and_the_rest_left_out(): void
    {
        $endsOnFirstDay = CalendarEvent::factory()->create(['start_at' => '2026-10-10', 'end_at' => '2026-10-12']);
        $oneDayOnLastDay = CalendarEvent::factory()->create(['start_at' => '2026-10-18', 'end_at' => null]);
        CalendarEvent::factory()->create(['start_at' => '2026-10-11', 'end_at' => null]);
        CalendarEvent::factory()->create(['start_at' => '2026-10-19', 'end_at' => '2026-10-20']);

        $this->assertEqualsCanonicalizing([$endsOnFirstDay->id, $oneDayOnLastDay->id], $this->eventIds($this->admin));
    }

    public function test_the_seeder_places_demo_agendas_around_the_current_week(): void
    {
        Carbon::setTestNow('2026-10-14 09:00:00');
        $this->seed(CalendarEventSeeder::class);

        // Company-wide agendas and a briefing per division, all credited to Admin.
        $total = count(CalendarEventSeeder::SHARED_AGENDAS) + 2;

        $this->assertSame($total, CalendarEvent::count());
        $this->assertTrue(CalendarEvent::with('creator.role')->get()->every(fn (CalendarEvent $event) => $event->creator->isAdmin()));
        $this->assertSame(4, count($this->eventIds($this->admin)));
        $this->assertSame(3, count($this->eventIds($this->staff)));
        $this->assertSame(3, count($this->eventIds($this->financeStaff())));

        $this->seed(CalendarEventSeeder::class);
        $this->assertSame($total, CalendarEvent::count());
    }

    // Validasi

    public function test_the_period_is_required_and_validated(): void
    {
        $this->actingAs($this->admin)->getJson('/api/tasks/schedule')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['from' => 'Tanggal mulai wajib diisi.', 'to' => 'Tanggal akhir wajib diisi.']);

        $this->actingAs($this->admin)->getJson('/api/tasks/schedule?from=14-10-2026&to=2026-10-20')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['from' => 'Tanggal mulai harus berformat YYYY-MM-DD.']);

        $this->actingAs($this->admin)->getJson('/api/tasks/schedule?from=2026-10-20&to=2026-10-14')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to' => 'Tanggal akhir tidak boleh sebelum Tanggal mulai.']);

        $this->actingAs($this->admin)->getJson('/api/tasks/schedule?from=2026-10-12&to=2026-10-18&division=XXX')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['division' => 'Divisi tidak ditemukan.']);
    }

    public function test_the_period_is_limited_to_a_maximum_number_of_days(): void
    {
        $this->actingAs($this->admin)->getJson('/api/tasks/schedule?from=2026-10-01&to=2026-12-01')->assertOk();

        $this->actingAs($this->admin)->getJson('/api/tasks/schedule?from=2026-10-01&to=2026-12-02')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to' => 'Periode maksimal 62 hari.']);
    }

    private function schedule(Account $account): TestResponse
    {
        return $this->actingAs($account)->getJson(self::PERIOD);
    }

    /**
     * can_view_detail of the Finance task and of the Marketing task, in schedule order.
     *
     * @return array<int, bool>
     */
    private function detailAccess(Account $account): array
    {
        return $this->schedule($account)->json('data.*.can_view_detail');
    }

    /**
     * @return array<int, int>
     */
    private function eventIds(Account $account, string $query = ''): array
    {
        return collect($this->actingAs($account)->getJson(self::PERIOD.$query)->assertOk()->json('data'))
            ->where('type', 'event')
            ->pluck('id')
            ->values()
            ->all();
    }

    private function financeStaff(): Account
    {
        return Account::where('username', 'fin.staff')->first() ?? $this->account(Role::EMPLOYEE, 'fin.staff', $this->finance);
    }

    private function account(string $role, string $username, ?Division $division): Account
    {
        return Account::create([
            'role_id' => Role::where('code_role', $role)->value('id'),
            'division_id' => $division?->id,
            'fullname' => ucwords(str_replace('.', ' ', $username)),
            'username' => $username,
            'password' => 'Password123!',
        ]);
    }

    private function task(string $title, Division $division, ?Account $pic, ?string $startDate, ?string $deadline): Task
    {
        return Task::factory()->create([
            'title' => $title,
            'description' => 'Rincian internal',
            'division_id' => $division->id,
            'pic_id' => $pic?->id,
            'priority' => TaskPriority::High,
            'status' => $pic ? TaskStatus::InProgress : TaskStatus::Backlog,
            'start_date' => $startDate,
            'deadline' => $deadline,
        ]);
    }
}
