<?php

namespace Tests\Feature\Task;

use App\Enums\TaskStatus;
use App\Models\Account;
use App\Models\Division;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskAssignment;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * STEAM-272: the five roles against the current Tasklist rules after TASK-19. Global read access and the permission
 * checks of TASK-12 are not applied yet; these tests pin today's behaviour so TASK-12 can change it deliberately.
 */
class TaskRoleCompatibilityTest extends TestCase
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
        $this->travelTo('2026-11-12 09:00:00');

        $this->marketing = Division::create(['code_division' => 'MKT', 'name' => 'Marketing']);
        $this->finance = Division::create(['code_division' => 'FIN', 'name' => 'Finance']);

        $this->accounts = [
            'admin' => $this->account(Role::ADMIN, 'admin', null),
            'manager' => $this->account(Role::MANAGER, 'manager', $this->marketing),
            'supervisor' => $this->account(Role::SUPERVISOR, 'supervisor', $this->marketing),
            'staff' => $this->account(Role::EMPLOYEE, 'staff', $this->marketing),
            'freelance' => $this->account(Role::FREELANCE, 'freelance', null),
        ];
    }

    public function test_admin_manager_and_supervisor_may_create_tasks(): void
    {
        foreach (['admin' => 201, 'manager' => 201, 'supervisor' => 201, 'staff' => 403, 'freelance' => 403] as $role => $expected) {
            $this->actingAs($this->accounts[$role])
                ->postJson('/api/tasks', ['title' => "Tugas {$role}", 'division_id' => $this->marketing->id])
                ->assertStatus($expected);

            $this->getJson('/api/tasks/create-options')->assertStatus($expected === 201 ? 200 : 403);
        }
    }

    public function test_only_admin_manager_and_the_division_supervisor_delete_task_cards(): void
    {
        foreach (['staff' => 403, 'freelance' => 403, 'supervisor' => 200] as $role => $expected) {
            $task = $this->task(['status' => TaskStatus::Todo], ['staff', 'freelance']);

            $this->actingAs($this->accounts[$role])
                ->deleteJson("/api/tasks/{$task->id}", ['version' => 1])
                ->assertStatus($expected);
        }

        foreach (['admin', 'manager'] as $role) {
            $task = $this->task(['division_id' => $this->finance->id]);
            $this->actingAs($this->accounts[$role])->deleteJson("/api/tasks/{$task->id}", ['version' => 1])->assertOk();
            $this->assertSoftDeleted($task);
        }

        $financeTask = $this->task(['division_id' => $this->finance->id]);
        $this->actingAs($this->accounts['supervisor'])->deleteJson("/api/tasks/{$financeTask->id}", ['version' => 1])->assertForbidden();
        $this->assertNotSoftDeleted($financeTask);
    }

    public function test_staff_and_freelance_never_give_review_decisions_even_as_pic(): void
    {
        $task = $this->task(['status' => TaskStatus::OnReview], ['staff', 'freelance']);

        foreach (['staff', 'freelance'] as $role) {
            $this->actingAs($this->accounts[$role])
                ->postJson("/api/tasks/{$task->id}/reviews", ['decision' => 'approve', 'version' => 1])
                ->assertForbidden();
            $this->actingAs($this->accounts[$role])->getJson("/api/tasks/{$task->id}")->assertJsonPath('data.can_review', false);
        }

        $this->actingAs($this->accounts['manager'])
            ->postJson("/api/tasks/{$task->id}/reviews", ['decision' => 'approve', 'version' => 1])
            ->assertOk()
            ->assertJsonPath('data.status', 'done');
    }

    public function test_the_board_list_tells_a_manager_or_supervisor_without_the_review_permission_that_they_cannot_decide(): void
    {
        $task = $this->task(['status' => TaskStatus::OnReview], ['staff']);

        foreach (['manager', 'supervisor'] as $role) {
            $this->actingAs($this->accounts[$role])->getJson('/api/tasks')
                ->assertJsonPath('data.0.id', $task->id)
                ->assertJsonPath('data.0.can_review', true);
        }

        foreach ([Role::MANAGER, Role::SUPERVISOR] as $code) {
            Role::where('code_role', $code)->first()->permissions()
                ->detach(Permission::where('code', Permission::TASK_REVIEW)->value('id'));
        }

        foreach (['manager', 'supervisor'] as $role) {
            $account = $this->accounts[$role]->fresh();

            $this->actingAs($account)->getJson('/api/tasks')
                ->assertJsonPath('data.0.id', $task->id)
                ->assertJsonPath('data.0.can_review', false);
            $this->actingAs($account)->getJson("/api/tasks/{$task->id}")->assertOk()->assertJsonPath('data.can_review', false);
            $this->actingAs($account)
                ->postJson("/api/tasks/{$task->id}/reviews", ['decision' => 'approve', 'version' => 1])
                ->assertForbidden();
        }

        $this->assertSame(TaskStatus::OnReview, $task->fresh()->status);
        $this->assertDatabaseMissing('task_reviews', ['task_id' => $task->id]);
    }

    public function test_a_supervisor_who_is_pic_approves_their_own_work_in_their_division(): void
    {
        $task = $this->task(['status' => TaskStatus::ReadyReview], ['supervisor']);

        $this->move($task, 'supervisor', 'on_review')->assertOk();
        $this->actingAs($this->accounts['supervisor'])->getJson("/api/tasks/{$task->id}")->assertJsonPath('data.can_review', true);
        $this->actingAs($this->accounts['supervisor'])
            ->postJson("/api/tasks/{$task->id}/reviews", ['decision' => 'approve', 'version' => $task->fresh()->version])
            ->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.reviews.0.reviewer.id', $this->accounts['supervisor']->id);

        $this->assertDatabaseHas('task_activities', ['task_id' => $task->id, 'account_id' => $this->accounts['supervisor']->id, 'action' => 'approved']);
    }

    public function test_a_manager_who_is_pic_approves_their_own_work_in_any_division(): void
    {
        $manager = $this->accounts['manager'];
        $task = $this->task(['status' => TaskStatus::OnReview, 'division_id' => $this->finance->id]);
        TaskAssignment::create(['task_id' => $task->id, 'account_id' => $manager->id, 'assigned_by' => $manager->id, 'assigned_at' => now()]);

        $this->actingAs($manager)
            ->postJson("/api/tasks/{$task->id}/reviews", ['decision' => 'approve', 'version' => $task->fresh()->version])
            ->assertOk()
            ->assertJsonPath('data.status', 'done');
    }

    public function test_a_supervisor_manages_only_tasks_of_their_division(): void
    {
        $supervisor = $this->accounts['supervisor'];
        $finance = $this->task(['status' => TaskStatus::OnReview, 'division_id' => $this->finance->id], ['staff']);
        $own = $this->task(['status' => TaskStatus::Todo], ['staff']);

        $this->actingAs($supervisor)->getJson("/api/tasks/{$finance->id}")->assertOk()
            ->assertJsonPath('data.can_edit', false)
            ->assertJsonPath('data.can_assign', false)
            ->assertJsonPath('data.can_review', false);
        $this->actingAs($supervisor)->patchJson("/api/tasks/{$finance->id}", ['title' => 'X', 'version' => 1])->assertForbidden();
        $this->actingAs($supervisor)->patchJson("/api/tasks/{$finance->id}/assignment", ['pic_ids' => [$supervisor->id], 'version' => 1])->assertForbidden();
        $this->actingAs($supervisor)->postJson("/api/tasks/{$finance->id}/reviews", ['decision' => 'approve', 'version' => 1])->assertForbidden();
        $this->actingAs($supervisor)->patchJson("/api/tasks/{$finance->id}/status", ['status' => 'done', 'version' => 1])->assertForbidden();

        $this->actingAs($supervisor)->patchJson("/api/tasks/{$own->id}", ['title' => 'Judul baru', 'version' => 1])->assertOk();
        $this->actingAs($supervisor)
            ->patchJson("/api/tasks/{$own->id}/assignment", ['pic_ids' => [$supervisor->id], 'version' => 2])
            ->assertOk();
    }

    /**
     * The access matrix of TASK-19/TASK-12 on one task of the marketing division whose PIC is the staff account:
     * everyone reads it, only the managing roles change it, the PIC only moves its status.
     */
    public function test_every_role_reads_every_task_but_only_changes_it_within_its_authority(): void
    {
        $financeTask = $this->task(['status' => TaskStatus::Todo, 'division_id' => $this->finance->id], ['staff']);
        $expected = [
            //               view, edit,  assign, review
            'admin' => [true, true, true, true],
            'manager' => [true, true, true, true],
            'supervisor' => [true, false, false, false],
            'staff' => [true, false, false, false],
            'freelance' => [true, false, false, false],
        ];

        foreach ($expected as $role => [$view, $edit, $assign, $review]) {
            $this->actingAs($this->accounts[$role])
                ->getJson('/api/tasks?scope=all_tasks')
                ->assertOk()
                ->assertJsonCount(1, 'data');
            $detail = $this->actingAs($this->accounts[$role])->getJson("/api/tasks/{$financeTask->id}")->assertOk();

            $this->assertSame($view, $detail->json('data.can_view_detail'), $role);
            $this->assertSame($edit, $detail->json('data.editable_fields') !== [] && $detail->json('data.editable_fields') !== ['status'], "{$role} edit");
            $this->assertSame($assign, $detail->json('data.can_assign'), "{$role} assign");
            $this->assertSame($review, $financeTask->reviewableBy($this->accounts[$role]->fresh()), "{$role} review");
        }

        // The PIC may only start the work; staff and freelance accounts that are not its PIC get 403.
        $this->move($financeTask, 'freelance', 'in_progress')->assertForbidden();
        $this->move($financeTask, 'staff', 'in_progress')->assertOk();
    }

    public function test_a_role_change_updates_the_permissions_without_touching_history(): void
    {
        $staff = $this->accounts['staff'];
        $task = $this->task(['status' => TaskStatus::Todo], ['staff']);
        $this->move($task, 'staff', 'in_progress')->assertOk();
        $history = DB::table('task_activities')->where('task_id', $task->id)->get();

        $this->actingAs($staff)->getJson("/api/tasks/{$task->id}")->assertJsonPath('data.can_assign', false);

        $staff->update(['role_id' => Role::where('code_role', Role::SUPERVISOR)->value('id')]);
        $staff = $staff->fresh();

        $this->actingAs($staff)->getJson("/api/tasks/{$task->id}")->assertJsonPath('data.can_assign', true);
        $this->actingAs($staff)->getJson('/api/tasks/meta')->assertJsonPath('data.can_create', true);
        $this->assertEquals($history, DB::table('task_activities')->where('task_id', $task->id)->get());
    }

    public function test_assignment_rights_follow_the_role(): void
    {
        $task = $this->task([], ['staff']);
        $financeTask = $this->task(['division_id' => $this->finance->id], ['staff']);
        $payload = fn (Task $task, array $pics) => ['version' => $task->fresh()->version, 'pic_ids' => $pics];

        $this->actingAs($this->accounts['staff'])->patchJson("/api/tasks/{$task->id}/assignment", $payload($task, [$this->accounts['freelance']->id]))->assertForbidden();
        $this->actingAs($this->accounts['freelance'])->patchJson("/api/tasks/{$task->id}/assignment", $payload($task, [$this->accounts['freelance']->id]))->assertForbidden();
        $this->actingAs($this->accounts['supervisor'])->patchJson("/api/tasks/{$financeTask->id}/assignment", $payload($financeTask, [$this->accounts['freelance']->id]))->assertForbidden();

        // A Supervisor adds PICs only from their own division; the freelancer has none.
        $this->actingAs($this->accounts['supervisor'])->patchJson("/api/tasks/{$task->id}/assignment", $payload($task, [$this->accounts['staff']->id, $this->accounts['freelance']->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pic_ids.1' => 'Supervisor hanya dapat menugaskan PIC dari divisinya sendiri.']);
        $this->actingAs($this->accounts['supervisor'])->patchJson("/api/tasks/{$task->id}/assignment", $payload($task, [$this->accounts['staff']->id, $this->accounts['supervisor']->id]))->assertOk();
        $this->actingAs($this->accounts['manager'])->patchJson("/api/tasks/{$financeTask->id}/assignment", $payload($financeTask, [$this->accounts['supervisor']->id]))->assertOk();
        $this->actingAs($this->accounts['admin'])->patchJson("/api/tasks/{$task->id}/assignment", $payload($task, [$this->accounts['freelance']->id]))->assertOk();
    }

    public function test_pics_of_every_pic_role_share_the_workflow_up_to_ready_review_without_a_lead(): void
    {
        $task = $this->task(['status' => TaskStatus::Todo, 'evidence_required' => true], ['staff', 'supervisor', 'freelance']);

        $this->move($task, 'freelance', 'in_progress')->assertOk();
        $this->actingAs($this->accounts['supervisor'])->postJson("/api/tasks/{$task->id}/evidences", ['url' => 'https://example.com/hasil'])->assertCreated();
        $this->move($task, 'staff', 'ready_review')->assertOk();
        $this->move($task, 'freelance', 'on_review')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status' => 'Hanya Admin, Manajer, atau Supervisor divisi yang dapat mengubah status dari Ready Review ke On Review.']);

        $this->assertSame(TaskStatus::ReadyReview, $task->fresh()->status);
    }

    public function test_history_stays_complete_through_revision_pic_change_and_approval(): void
    {
        $task = $this->task(['status' => TaskStatus::Todo], ['staff']);

        $this->move($task, 'staff', 'in_progress')->assertOk();
        $this->move($task, 'staff', 'ready_review')->assertOk();
        $this->move($task, 'manager', 'on_review')->assertOk();
        $this->actingAs($this->accounts['manager'])->postJson("/api/tasks/{$task->id}/reviews", ['decision' => 'revision', 'note' => 'Perbaiki.', 'version' => $task->fresh()->version])->assertOk();
        $this->actingAs($this->accounts['manager'])->patchJson("/api/tasks/{$task->id}/assignment", ['version' => $task->fresh()->version, 'pic_ids' => [$this->accounts['staff']->id, $this->accounts['freelance']->id]])->assertOk();
        $this->move($task, 'freelance', 'ready_review')->assertOk();
        $this->move($task, 'manager', 'on_review')->assertOk();
        $this->actingAs($this->accounts['manager'])->postJson("/api/tasks/{$task->id}/reviews", ['decision' => 'approve', 'version' => $task->fresh()->version])->assertOk();

        $this->assertSame([
            TaskActivity::APPROVED, TaskActivity::STATUS_CHANGED, TaskActivity::RESUBMITTED, TaskActivity::ASSIGNED,
            TaskActivity::REVISION_REQUESTED, TaskActivity::STATUS_CHANGED, TaskActivity::STATUS_CHANGED, TaskActivity::STATUS_CHANGED,
        ], $task->activities()->pluck('action')->all());
        $this->assertSame(['approve', 'revision'], $task->reviews()->get()->map(fn ($review) => $review->decision->value)->all());
        $this->assertSame(2, $task->assignments()->count());
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>  $pics  Keys of $this->accounts.
     */
    private function task(array $attributes = [], array $pics = []): Task
    {
        return Task::factory()
            ->withPics(...array_map(fn (string $key) => $this->accounts[$key], $pics))
            ->create([
                'division_id' => $this->marketing->id,
                'creator_id' => $this->accounts['manager']->id,
                'status' => TaskStatus::Backlog,
                'requires_review' => true,
                'evidence_required' => false,
                ...$attributes,
            ]);
    }

    private function move(Task $task, string $role, string $status): TestResponse
    {
        return $this->actingAs($this->accounts[$role])
            ->patchJson("/api/tasks/{$task->id}/status", ['version' => $task->fresh()->version, 'status' => $status]);
    }

    private function account(string $role, string $username, ?Division $division): Account
    {
        return Account::create([
            'role_id' => Role::where('code_role', $role)->value('id'),
            'division_id' => $division?->id,
            'fullname' => $username,
            'username' => "role.{$username}",
            'password' => 'Password123!',
        ]);
    }
}
