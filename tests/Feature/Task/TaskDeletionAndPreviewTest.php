<?php

namespace Tests\Feature\Task;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Account;
use App\Models\CalendarEvent;
use App\Models\Division;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskActivity;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-12 (STEAM-241): deleting a Task Card and the Calendar task preview. TASK-15 (STEAM-253): what the preview shows.
 */
class TaskDeletionAndPreviewTest extends TestCase
{
    use RefreshDatabase;

    private Division $marketing;

    private Division $finance;

    /**
     * @var array<string, Account>
     */
    private array $accounts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->travelTo('2026-11-16 09:00:00');

        $this->marketing = Division::create(['code_division' => 'MKT', 'name' => 'Marketing']);
        $this->finance = Division::create(['code_division' => 'FIN', 'name' => 'Finance']);

        $this->accounts = [
            'admin' => $this->account(Role::ADMIN, 'admin', null),
            'manager' => $this->account(Role::MANAGER, 'manager', $this->finance),
            'supervisor' => $this->account(Role::SUPERVISOR, 'supervisor', $this->marketing),
            'staff' => $this->account(Role::EMPLOYEE, 'staff', $this->marketing),
            'freelance' => $this->account(Role::FREELANCE, 'freelance', null),
        ];
    }

    // Hapus Task Card

    public function test_guest_cannot_delete_or_preview_a_task(): void
    {
        $task = $this->task();

        $this->deleteJson("/api/tasks/{$task->id}", ['version' => 1])->assertUnauthorized();
        $this->getJson("/api/tasks/{$task->id}/preview")->assertUnauthorized();
    }

    public function test_a_deleted_task_is_soft_deleted_with_who_deleted_it_and_its_history_kept(): void
    {
        $task = $this->task(['status' => TaskStatus::InProgress], ['staff']);
        $supervisor = $this->accounts['supervisor'];

        $this->actingAs($supervisor)
            ->deleteJson("/api/tasks/{$task->id}", ['version' => 1])
            ->assertOk()
            ->assertJsonPath('message', 'Tugas berhasil dihapus.');

        $deleted = Task::withTrashed()->find($task->id);
        $this->assertSoftDeleted($task);
        $this->assertSame($supervisor->id, $deleted->deleted_by);
        $this->assertSame(2, $deleted->version);
        $this->assertSame(1, $deleted->assignments()->count());
        $this->assertDatabaseHas('task_activities', ['task_id' => $task->id, 'account_id' => $supervisor->id, 'action' => TaskActivity::DELETED]);
    }

    public function test_staff_and_freelance_cannot_delete_even_as_pic(): void
    {
        $task = $this->task(['status' => TaskStatus::Todo], ['staff', 'freelance']);

        foreach (['staff', 'freelance'] as $role) {
            $this->actingAs($this->accounts[$role])->deleteJson("/api/tasks/{$task->id}", ['version' => 1])->assertForbidden();
            $this->actingAs($this->accounts[$role])->getJson("/api/tasks/{$task->id}")->assertJsonPath('data.can_delete', false);
        }

        $this->assertNotSoftDeleted($task);
    }

    public function test_a_done_task_cannot_be_deleted(): void
    {
        $task = $this->task(['status' => TaskStatus::Done, 'completed_at' => now()], ['staff']);

        $this->actingAs($this->accounts['admin'])->getJson("/api/tasks/{$task->id}")->assertJsonPath('data.can_delete', false);
        $this->actingAs($this->accounts['admin'])
            ->deleteJson("/api/tasks/{$task->id}", ['version' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status' => 'Tugas yang sudah selesai tidak dapat dihapus.']);

        $this->assertNotSoftDeleted($task);
    }

    public function test_every_other_status_can_be_deleted(): void
    {
        foreach ([TaskStatus::Backlog, TaskStatus::Todo, TaskStatus::InProgress, TaskStatus::ReadyReview, TaskStatus::OnReview] as $status) {
            $task = $this->task(['status' => $status], ['staff']);

            $this->actingAs($this->accounts['manager'])->getJson("/api/tasks/{$task->id}")->assertJsonPath('data.can_delete', true);
            $this->actingAs($this->accounts['manager'])->deleteJson("/api/tasks/{$task->id}", ['version' => 1])->assertOk();
            $this->assertSoftDeleted($task);
        }
    }

    public function test_deleting_needs_the_current_version(): void
    {
        $task = $this->task();

        $this->actingAs($this->accounts['admin'])
            ->deleteJson("/api/tasks/{$task->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['version']);

        Task::whereKey($task->id)->update(['version' => 3]);

        $this->actingAs($this->accounts['admin'])
            ->deleteJson("/api/tasks/{$task->id}", ['version' => 2])
            ->assertConflict()
            ->assertJsonPath('current_version', 3);

        $this->assertNotSoftDeleted($task);
    }

    public function test_a_deleted_task_leaves_every_view_and_cannot_be_opened(): void
    {
        $kept = $this->task(['title' => 'Tetap', 'deadline' => '2026-11-20', 'start_date' => '2026-11-18'], ['staff']);
        $deleted = $this->task(['title' => 'Dihapus', 'deadline' => '2026-11-21', 'start_date' => '2026-11-18'], ['staff']);
        $agenda = CalendarEvent::factory()->create(['start_at' => '2026-11-19', 'end_at' => null, 'division_id' => null]);

        $this->actingAs($this->accounts['admin'])->deleteJson("/api/tasks/{$deleted->id}", ['version' => 1])->assertOk();
        $staff = $this->accounts['staff'];

        $this->actingAs($staff)->getJson('/api/tasks')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $kept->id)
            ->assertJsonPath('meta.status_counts.backlog', 1);
        $this->actingAs($staff)->getJson('/api/tasks?scope=my_tasks')->assertJsonCount(1, 'data');
        $this->actingAs($staff)->getJson('/api/tasks/calendar?month=2026-11')
            ->assertJsonCount(1, 'data.deadlines')
            ->assertJsonPath('data.events.0.id', $agenda->id)
            ->assertJsonPath('data.events.0.can_view_detail', false);
        $this->assertSame(['Tetap'], collect($this->actingAs($staff)->getJson('/api/tasks/schedule?from=2026-11-16&to=2026-11-29')->json('data'))
            ->where('type', 'task')->pluck('title')->all());
        $this->actingAs($staff)->getJson('/api/tasks/monitoring')->assertJsonPath('data.metrics.total_tasks', 1);

        $this->actingAs($staff)->getJson("/api/tasks/{$deleted->id}")->assertNotFound();
        $this->actingAs($staff)->getJson("/api/tasks/{$deleted->id}/preview")->assertNotFound();
        $this->actingAs($this->accounts['admin'])->deleteJson("/api/tasks/{$deleted->id}", ['version' => 2])->assertNotFound();
    }

    // Preview Calendar

    public function test_the_preview_summarises_the_task_with_what_the_account_may_do(): void
    {
        $task = $this->task([
            'title' => 'Banner promo',
            'description' => 'Ukuran 1080x1080',
            'priority' => TaskPriority::High,
            'status' => TaskStatus::InProgress,
            'start_date' => '2026-11-10',
            'deadline' => '2026-11-14',
        ], ['staff']);

        $this->actingAs($this->accounts['staff'])
            ->getJson("/api/tasks/{$task->id}/preview")
            ->assertOk()
            ->assertJsonPath('data.id', $task->id)
            ->assertJsonPath('data.title', 'Banner promo')
            ->assertJsonPath('data.description', 'Ukuran 1080x1080')
            ->assertJsonPath('data.division', ['code_division' => 'MKT', 'name' => 'Marketing'])
            ->assertJsonPath('data.pics', [['id' => $this->accounts['staff']->id, 'fullname' => 'staff']])
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.priority', 'high')
            ->assertJsonPath('data.start_date', '2026-11-10')
            ->assertJsonPath('data.deadline', '2026-11-14')
            ->assertJsonPath('data.is_overdue', true)
            ->assertJsonPath('data.can_view_detail', true)
            ->assertJsonPath('data.can_edit', true)
            ->assertJsonPath('data.can_assign', false)
            ->assertJsonPath('data.can_delete', false)
            ->assertJsonPath('data.can_review', false)
            ->assertJsonPath('data.version', 1)
            ->assertJsonMissingPath('data.history')
            ->assertJsonMissingPath('data.evidence');
    }

    public function test_every_role_previews_tasks_of_every_division_read_only_outside_its_authority(): void
    {
        $task = $this->task(['status' => TaskStatus::Todo, 'division_id' => $this->finance->id], ['staff']);
        $expected = [
            'admin' => [true, true],
            'manager' => [true, true],
            'supervisor' => [false, false],
            'staff' => [true, false],
            'freelance' => [false, false],
        ];

        foreach ($expected as $role => [$canEdit, $canDelete]) {
            $this->actingAs($this->accounts[$role])
                ->getJson("/api/tasks/{$task->id}/preview")
                ->assertOk()
                ->assertJsonPath('data.can_edit', $canEdit)
                ->assertJsonPath('data.can_delete', $canDelete);
        }
    }

    public function test_the_preview_reads_the_latest_data(): void
    {
        $task = $this->task(['status' => TaskStatus::Todo], ['staff']);
        $url = "/api/tasks/{$task->id}/preview";

        $this->actingAs($this->accounts['staff'])->getJson($url)->assertJsonPath('data.status', 'todo');
        $this->actingAs($this->accounts['staff'])
            ->patchJson("/api/tasks/{$task->id}/status", ['status' => 'in_progress', 'version' => 1])
            ->assertOk();

        $this->actingAs($this->accounts['admin'])->getJson($url)
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.version', 2);
    }

    public function test_the_preview_lists_every_active_pic_once_and_leaves_released_ones_out(): void
    {
        $task = $this->task(['status' => TaskStatus::Todo], ['supervisor', 'staff', 'freelance']);
        $task->activeAssignments()->where('account_id', $this->accounts['freelance']->id)
            ->update(['unassigned_at' => now(), 'unassigned_by' => $this->accounts['admin']->id]);

        $this->actingAs($this->accounts['freelance'])
            ->getJson("/api/tasks/{$task->id}/preview")
            ->assertOk()
            ->assertJsonPath('data.pics', [
                ['id' => $this->accounts['staff']->id, 'fullname' => 'staff'],
                ['id' => $this->accounts['supervisor']->id, 'fullname' => 'supervisor'],
            ]);
    }

    public function test_the_preview_of_a_task_without_dates_or_pics_reports_them_as_empty(): void
    {
        $task = $this->task(['status' => TaskStatus::Todo]);

        $this->actingAs($this->accounts['staff'])
            ->getJson("/api/tasks/{$task->id}/preview")
            ->assertOk()
            ->assertJsonPath('data.start_date', null)
            ->assertJsonPath('data.deadline', null)
            ->assertJsonPath('data.pics', [])
            ->assertJsonPath('data.is_overdue', false);
    }

    public function test_the_preview_shows_every_workflow_status(): void
    {
        foreach (TaskStatus::cases() as $status) {
            $task = $this->task(['status' => $status]);

            $this->actingAs($this->accounts['staff'])
                ->getJson("/api/tasks/{$task->id}/preview")
                ->assertOk()
                ->assertJsonPath('data.status', $status->value);
        }
    }

    public function test_opening_the_preview_changes_nothing(): void
    {
        $task = $this->task(['status' => TaskStatus::InProgress, 'deadline' => '2026-11-20'], ['staff']);
        $before = $task->fresh()->getAttributes();
        $this->travel(1)->hour();

        foreach (['admin', 'staff', 'freelance'] as $role) {
            $this->actingAs($this->accounts[$role])->getJson("/api/tasks/{$task->id}/preview")->assertOk();
        }

        $this->assertSame($before, $task->fresh()->getAttributes());
        $this->assertSame(0, $task->activities()->count());
    }

    public function test_an_inactive_account_cannot_preview_and_an_unknown_task_is_not_found(): void
    {
        $task = $this->task();
        $this->accounts['staff']->update(['is_active' => false]);

        $this->actingAs($this->accounts['staff'])->getJson("/api/tasks/{$task->id}/preview")->assertForbidden();
        $this->actingAs($this->accounts['admin'])->getJson('/api/tasks/999999/preview')->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>  $pics
     */
    private function task(array $attributes = [], array $pics = []): Task
    {
        return Task::factory()
            ->withPics(...array_map(fn (string $key) => $this->accounts[$key], $pics))
            ->create([
                'division_id' => $this->marketing->id,
                'creator_id' => $this->accounts['admin']->id,
                'status' => TaskStatus::Backlog,
                'requires_review' => true,
                'evidence_required' => false,
                'start_date' => null,
                'deadline' => null,
                ...$attributes,
            ]);
    }

    private function account(string $role, string $username, ?Division $division): Account
    {
        return Account::create([
            'role_id' => Role::where('code_role', $role)->value('id'),
            'division_id' => $division?->id,
            'fullname' => $username,
            'username' => "del.{$username}",
            'password' => 'Password123!',
        ]);
    }
}
