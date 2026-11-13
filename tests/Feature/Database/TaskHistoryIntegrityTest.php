<?php

namespace Tests\Feature\Database;

use App\Enums\TaskReviewDecision;
use App\Enums\TaskStatus;
use App\Exceptions\CommentReplyOutsideTaskException;
use App\Models\Account;
use App\Models\Division;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskComment;
use App\Models\TaskEvidence;
use App\Models\TaskReview;
use App\Repositories\AccountRepository;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * STEAM-271: task history survives task, comment, and account deletes; replies stay within their task.
 */
class TaskHistoryIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Division $division;

    private Account $manager;

    private Account $staff;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->division = Division::create(['code_division' => 'MKT', 'name' => 'Marketing']);
        $this->manager = $this->account(Role::MANAGER, 'manager');
        $this->staff = $this->account(Role::EMPLOYEE, 'staff');
        $this->task = Task::factory()->withPics($this->staff)->create([
            'division_id' => $this->division->id,
            'creator_id' => $this->manager->id,
            'status' => TaskStatus::InProgress,
        ]);
    }

    public function test_deleting_a_task_keeps_its_whole_history_and_a_physical_delete_is_refused(): void
    {
        $this->fillHistory($this->task);

        $this->task->delete();

        foreach (['task_activities', 'task_evidences', 'task_reviews', 'task_assignments', 'task_comments'] as $table) {
            $this->assertSame(1, DB::table($table)->where('task_id', $this->task->id)->count(), $table);
        }

        $this->expectException(QueryException::class);
        $this->task->forceDelete();
    }

    public function test_an_account_in_task_history_cannot_be_removed_physically(): void
    {
        TaskComment::create(['task_id' => $this->task->id, 'author_id' => $this->manager->id, 'content' => 'Cek ulang.']);

        foreach ([$this->staff, $this->manager] as $account) {
            try {
                DB::table('accounts')->where('id', $account->id)->delete();
                $this->fail("Account {$account->username} should not be deletable.");
            } catch (QueryException) {
                $this->assertModelExists($account);
            }
        }
    }

    public function test_task_history_detection_covers_every_role_an_account_can_play(): void
    {
        $repository = app(AccountRepository::class);
        $outsider = $this->account(Role::EMPLOYEE, 'outsider');

        $this->assertTrue($repository->hasTaskHistory($this->staff), 'PIC');
        $this->assertTrue($repository->hasTaskHistory($this->manager), 'creator');
        $this->assertFalse($repository->hasTaskHistory($outsider));

        TaskReview::create(['task_id' => $this->task->id, 'reviewer_id' => $outsider->id, 'cycle' => 1, 'decision' => TaskReviewDecision::Approve]);
        $this->assertTrue($repository->hasTaskHistory($outsider), 'reviewer');
    }

    public function test_replies_stay_within_their_task_and_survive_status_and_pic_changes(): void
    {
        $comment = TaskComment::create(['task_id' => $this->task->id, 'author_id' => $this->manager->id, 'content' => 'Mohon dicek.']);
        $reply = TaskComment::create(['task_id' => $this->task->id, 'author_id' => $this->staff->id, 'parent_comment_id' => $comment->id, 'content' => 'Siap.']);

        $this->task->update(['status' => TaskStatus::ReadyReview]);
        $this->task->activeAssignments()->update(['unassigned_at' => now(), 'unassigned_by' => $this->manager->id]);

        $this->assertSame(TaskStatus::ReadyReview, $this->task->fresh()->status);
        $this->assertSame(2, $this->task->comments()->count());
        $this->assertTrue($reply->fresh()->parent->is($comment));

        $other = Task::factory()->create(['division_id' => $this->division->id]);

        $this->expectException(CommentReplyOutsideTaskException::class);
        TaskComment::create(['task_id' => $other->id, 'author_id' => $this->staff->id, 'parent_comment_id' => $comment->id, 'content' => 'Salah tugas.']);
    }

    public function test_a_comment_with_replies_cannot_be_removed_physically(): void
    {
        $comment = TaskComment::create(['task_id' => $this->task->id, 'author_id' => $this->manager->id, 'content' => 'Induk.']);
        TaskComment::create(['task_id' => $this->task->id, 'author_id' => $this->staff->id, 'parent_comment_id' => $comment->id, 'content' => 'Balasan.']);

        $comment->delete();
        $this->assertSoftDeleted($comment);

        $this->expectException(QueryException::class);
        $comment->forceDelete();
    }

    public function test_every_review_decision_is_kept_and_a_pic_may_review_their_own_task(): void
    {
        TaskReview::create(['task_id' => $this->task->id, 'reviewer_id' => $this->manager->id, 'cycle' => 1, 'decision' => TaskReviewDecision::Revision, 'note' => 'Perbaiki warna.']);
        TaskReview::create(['task_id' => $this->task->id, 'reviewer_id' => $this->staff->id, 'cycle' => 2, 'decision' => TaskReviewDecision::Approve]);

        $this->assertSame(
            [['cycle' => 2, 'decision' => 'approve', 'reviewer_id' => $this->staff->id], ['cycle' => 1, 'decision' => 'revision', 'reviewer_id' => $this->manager->id]],
            $this->task->reviews()->get()->map(fn (TaskReview $review) => [
                'cycle' => $review->cycle, 'decision' => $review->decision->value, 'reviewer_id' => $review->reviewer_id,
            ])->all(),
        );
    }

    public function test_deleted_evidence_keeps_its_task_and_submitter(): void
    {
        $evidence = TaskEvidence::create(['task_id' => $this->task->id, 'submitter_id' => $this->staff->id, 'type' => TaskEvidence::LINK, 'url' => 'https://example.com']);

        $evidence->update(['deleted_by' => $this->manager->id]);
        $evidence->delete();

        $trashed = TaskEvidence::withTrashed()->find($evidence->id);
        $this->assertSoftDeleted($evidence);
        $this->assertSame($this->staff->id, $trashed->submitter_id);
        $this->assertSame($this->manager->id, $trashed->deleted_by);
        $this->assertSame(0, $this->task->evidences()->count());
    }

    public function test_activity_types_required_by_the_audit_trail_have_labels(): void
    {
        foreach ([
            TaskActivity::CREATED, TaskActivity::UPDATED, TaskActivity::DELETED, TaskActivity::ASSIGNED,
            TaskActivity::UNASSIGNED, TaskActivity::STATUS_CHANGED, TaskActivity::APPROVED, TaskActivity::REVISION_REQUESTED,
            TaskActivity::EVIDENCE_SUBMITTED, TaskActivity::EVIDENCE_DELETED, TaskActivity::COMMENT_ADDED,
        ] as $action) {
            $this->assertArrayHasKey($action, TaskActivity::ACTION_LABELS);
        }

        $this->assertSame('Komentar ditambahkan', (new TaskActivity(['action' => TaskActivity::COMMENT_ADDED]))->actionLabel());
    }

    public function test_the_migration_rolls_back_and_reapplies(): void
    {
        $migration = require collect(glob(database_path('migrations/*_protect_task_history_from_physical_deletes.php')))->sole();

        $migration->down();
        $this->fillHistory($this->task);
        $this->task->forceDelete();
        $this->assertSame(0, DB::table('task_activities')->count(), 'cascade restored by down()');

        $migration->up();
        $task = Task::factory()->create(['division_id' => $this->division->id]);
        $task->activities()->create(['account_id' => $this->manager->id, 'action' => TaskActivity::CREATED]);

        $this->expectException(QueryException::class);
        $task->forceDelete();
    }

    private function fillHistory(Task $task): void
    {
        $task->activities()->create(['account_id' => $this->manager->id, 'action' => TaskActivity::CREATED]);
        $task->evidences()->create(['submitter_id' => $this->staff->id, 'type' => TaskEvidence::LINK, 'url' => 'https://example.com']);
        TaskReview::create(['task_id' => $task->id, 'reviewer_id' => $this->manager->id, 'cycle' => 1, 'decision' => TaskReviewDecision::Approve]);
        TaskComment::create(['task_id' => $task->id, 'author_id' => $this->manager->id, 'content' => 'Catatan.']);

        if (! $task->assignments()->exists()) {
            $task->assignments()->create(['account_id' => $this->staff->id, 'assigned_by' => $this->manager->id, 'assigned_at' => now()]);
        }
    }

    private function account(string $role, string $username): Account
    {
        return Account::create([
            'role_id' => Role::where('code_role', $role)->value('id'),
            'division_id' => $this->division->id,
            'fullname' => ucfirst($username),
            'username' => $username,
            'password' => 'secret123',
        ]);
    }
}
