<?php

namespace Tests\Feature\Task;

use App\Enums\TaskStatus;
use App\Models\Account;
use App\Models\Division;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskActivity;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_only_admin_and_manager_may_create_tasks(): void
    {
        foreach (['admin' => 201, 'manager' => 201, 'supervisor' => 403, 'staff' => 403, 'freelance' => 403] as $role => $expected) {
            $this->actingAs($this->accounts[$role])
                ->postJson('/api/tasks', ['title' => "Tugas {$role}", 'division_id' => $this->marketing->id])
                ->assertStatus($expected);

            $this->getJson('/api/tasks/create-options')->assertStatus($expected === 201 ? 200 : 403);
        }
    }

    public function test_task_cards_have_no_delete_endpoint_for_any_role(): void
    {
        $task = $this->task();

        foreach ($this->accounts as $account) {
            $this->actingAs($account)->deleteJson("/api/tasks/{$task->id}")->assertMethodNotAllowed();
        }

        $this->assertNotSoftDeleted($task);
    }

    public function test_only_admin_and_the_division_manager_give_review_decisions(): void
    {
        $task = $this->task(['status' => TaskStatus::OnReview], ['staff', 'supervisor', 'freelance']);

        foreach (['supervisor', 'staff', 'freelance'] as $role) {
            $this->actingAs($this->accounts[$role])
                ->postJson("/api/tasks/{$task->id}/reviews", ['decision' => 'approve', 'version' => 1])
                ->assertForbidden();
        }

        $this->actingAs($this->accounts['manager'])
            ->postJson("/api/tasks/{$task->id}/reviews", ['decision' => 'approve', 'version' => 1])
            ->assertOk()
            ->assertJsonPath('data.status', 'done');
    }

    public function test_assignment_rights_follow_the_role(): void
    {
        $task = $this->task([], ['staff']);
        $financeTask = $this->task(['division_id' => $this->finance->id], ['staff']);
        $payload = fn (Task $task, array $pics) => ['version' => $task->fresh()->version, 'pic_ids' => $pics];

        $this->actingAs($this->accounts['staff'])->patchJson("/api/tasks/{$task->id}/assignment", $payload($task, [$this->accounts['freelance']->id]))->assertForbidden();
        $this->actingAs($this->accounts['freelance'])->patchJson("/api/tasks/{$task->id}/assignment", $payload($task, [$this->accounts['freelance']->id]))->assertForbidden();
        $this->actingAs($this->accounts['supervisor'])->patchJson("/api/tasks/{$financeTask->id}/assignment", $payload($financeTask, [$this->accounts['freelance']->id]))->assertForbidden();

        $this->actingAs($this->accounts['supervisor'])->patchJson("/api/tasks/{$task->id}/assignment", $payload($task, [$this->accounts['staff']->id, $this->accounts['freelance']->id]))->assertOk();
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
            ->assertJsonValidationErrors(['status' => 'Hanya Admin atau Manajer divisi yang dapat mengubah status dari Ready Review ke On Review.']);

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
