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
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TASK-16 (STEAM-257): comments and replies on a Task Card.
 */
class TaskCommentTest extends TestCase
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
        $this->travelTo('2026-11-20 09:00:00');

        $this->marketing = Division::create(['code_division' => 'MKT', 'name' => 'Marketing']);
        $this->finance = Division::create(['code_division' => 'FIN', 'name' => 'Finance']);

        $this->accounts = [
            'admin' => $this->account(Role::ADMIN, 'admin', null),
            'manager' => $this->account(Role::MANAGER, 'manager', $this->finance),
            'supervisor' => $this->account(Role::SUPERVISOR, 'supervisor', $this->marketing),
            'other_supervisor' => $this->account(Role::SUPERVISOR, 'other_supervisor', $this->finance),
            'staff' => $this->account(Role::EMPLOYEE, 'staff', $this->marketing),
            'other_staff' => $this->account(Role::EMPLOYEE, 'other_staff', $this->marketing),
            'freelance' => $this->account(Role::FREELANCE, 'freelance', null),
        ];
    }

    // Hak berkomentar

    public function test_guest_cannot_read_or_send_comments(): void
    {
        $task = $this->task();

        $this->getJson("/api/tasks/{$task->id}/comments")->assertUnauthorized();
        $this->postJson("/api/tasks/{$task->id}/comments", ['content' => 'Halo'])->assertUnauthorized();
    }

    public function test_managers_the_division_supervisor_and_active_pics_may_comment(): void
    {
        $task = $this->task(['status' => TaskStatus::InProgress], ['staff', 'freelance']);

        foreach (['admin', 'manager', 'supervisor', 'staff', 'freelance'] as $role) {
            $this->comment($role, $task, "Komentar {$role}")
                ->assertCreated()
                ->assertJsonPath('message', 'Komentar berhasil dikirim.')
                ->assertJsonPath('data.author.id', $this->accounts[$role]->id);
        }

        $this->assertSame(5, $task->comments()->count());
    }

    public function test_accounts_outside_the_task_only_read_the_discussion(): void
    {
        $task = $this->task(['status' => TaskStatus::InProgress], ['staff']);
        $this->comment('staff', $task, 'Sudah mulai dikerjakan.')->assertCreated();

        foreach (['other_supervisor', 'other_staff', 'freelance'] as $role) {
            $this->comment($role, $task, 'Ikut komentar.')->assertForbidden();

            $this->actingAs($this->accounts[$role])
                ->getJson("/api/tasks/{$task->id}/comments")
                ->assertOk()
                ->assertJsonCount(1, 'data');
        }

        $this->assertSame(1, $task->comments()->count());
    }

    public function test_a_released_pic_keeps_reading_but_can_no_longer_comment(): void
    {
        $task = $this->task(['status' => TaskStatus::InProgress], ['staff', 'freelance']);
        $this->comment('freelance', $task, 'Catatan sebelum dilepas.')->assertCreated();
        $this->release($task, 'freelance');

        $this->comment('freelance', $task, 'Catatan setelah dilepas.')->assertForbidden();
        $this->actingAs($this->accounts['freelance'])
            ->getJson("/api/tasks/{$task->id}/comments")
            ->assertOk()
            ->assertJsonPath('data.0.content', 'Catatan sebelum dilepas.');
    }

    public function test_an_inactive_account_cannot_read_or_comment(): void
    {
        $task = $this->task(['status' => TaskStatus::InProgress], ['staff']);
        $this->accounts['staff']->update(['is_active' => false]);

        $this->actingAs($this->accounts['staff']->fresh())->getJson("/api/tasks/{$task->id}/comments")->assertForbidden();
        $this->comment('staff', $task, 'Halo')->assertForbidden();
    }

    public function test_every_pic_of_a_multi_pic_task_discusses_in_the_same_thread(): void
    {
        $task = $this->task(['status' => TaskStatus::InProgress], ['staff', 'other_staff', 'freelance']);

        $root = $this->comment('staff', $task, 'Saya ambil bagian desain.')->json('data.id');
        $this->comment('other_staff', $task, 'Saya bagian copywriting.', $root)->assertCreated();
        $this->comment('freelance', $task, 'Saya bagian foto.', $root)->assertCreated();

        $this->assertSame(
            [null, $root, $root],
            $task->comments()->pluck('parent_comment_id')->all(),
        );
    }

    // Status tugas

    public static function activeStatuses(): array
    {
        return [
            'Back Log' => [TaskStatus::Backlog],
            'To Do' => [TaskStatus::Todo],
            'On Process' => [TaskStatus::InProgress],
            'Ready Review' => [TaskStatus::ReadyReview],
            'On Review' => [TaskStatus::OnReview],
        ];
    }

    #[DataProvider('activeStatuses')]
    public function test_comments_are_accepted_in_every_active_status(TaskStatus $status): void
    {
        $task = $this->task(['status' => $status], ['staff']);

        $this->comment('staff', $task, 'Komentar PIC.')->assertCreated();
        $this->comment('supervisor', $task, 'Komentar reviewer.')->assertCreated();
        $this->assertSame($status, $task->fresh()->status);
    }

    public function test_a_done_task_keeps_its_comments_read_only(): void
    {
        $task = $this->task(['status' => TaskStatus::OnReview], ['staff']);
        $this->comment('supervisor', $task, 'Sudah sesuai.')->assertCreated();
        $task->forceFill(['status' => TaskStatus::Done, 'completed_at' => now()])->save();

        foreach (['admin', 'supervisor', 'staff'] as $role) {
            $this->comment($role, $task, 'Tambahan setelah selesai.')
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['content' => 'Tugas yang sudah selesai tidak dapat dikomentari.']);
        }

        $this->actingAs($this->accounts['staff'])
            ->getJson("/api/tasks/{$task->id}/comments")
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->assertSame(1, $task->comments()->count());
    }

    public function test_the_detail_says_who_may_comment_now(): void
    {
        $task = $this->task(['status' => TaskStatus::InProgress], ['staff']);
        $expected = ['admin' => true, 'manager' => true, 'supervisor' => true, 'staff' => true, 'other_supervisor' => false, 'other_staff' => false];

        foreach ($expected as $role => $canComment) {
            $this->actingAs($this->accounts[$role])
                ->getJson("/api/tasks/{$task->id}")
                ->assertOk()
                ->assertJsonPath('data.can_comment', $canComment);
        }

        $task->forceFill(['status' => TaskStatus::Done, 'completed_at' => now()])->save();

        $this->actingAs($this->accounts['staff'])->getJson("/api/tasks/{$task->id}")->assertJsonPath('data.can_comment', false);
    }

    // Validasi

    public static function invalidContents(): array
    {
        return [
            'kosong' => [['content' => ''], 'content', 'Komentar tidak boleh kosong.'],
            'tidak dikirim' => [[], 'content', 'Komentar tidak boleh kosong.'],
            'hanya spasi' => [['content' => "   \n\t "], 'content', 'Komentar tidak boleh kosong.'],
            'bukan teks' => [['content' => ['a']], 'content', 'Komentar tidak valid.'],
            'terlalu panjang' => [['content' => str_repeat('a', 2001)], 'content', 'Komentar maksimal 2000 karakter.'],
            'parent bukan angka' => [['content' => 'Balas', 'parent_comment_id' => 'abc'], 'parent_comment_id', 'Komentar yang dibalas tidak valid.'],
            'parent nol' => [['content' => 'Balas', 'parent_comment_id' => 0], 'parent_comment_id', 'Komentar yang dibalas tidak valid.'],
        ];
    }

    #[DataProvider('invalidContents')]
    public function test_invalid_comments_are_rejected(array $payload, string $field, string $message): void
    {
        $task = $this->task(['status' => TaskStatus::InProgress], ['staff']);

        $this->actingAs($this->accounts['staff'])
            ->postJson("/api/tasks/{$task->id}/comments", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field => $message]);

        $this->assertSame(0, $task->comments()->count());
        $this->assertSame(0, $task->activities()->count());
    }

    public function test_a_comment_of_2000_characters_is_accepted_and_surrounding_spaces_are_trimmed(): void
    {
        $task = $this->task(['status' => TaskStatus::InProgress], ['staff']);

        $this->comment('staff', $task, str_repeat('a', 2000))->assertCreated();
        $this->comment('staff', $task, '  Sudah dicek.  ')->assertCreated()->assertJsonPath('data.content', 'Sudah dicek.');
    }

    public function test_the_author_is_always_the_signed_in_account(): void
    {
        $task = $this->task(['status' => TaskStatus::InProgress], ['staff']);

        $this->actingAs($this->accounts['staff'])
            ->postJson("/api/tasks/{$task->id}/comments", ['content' => 'Halo', 'author_id' => $this->accounts['admin']->id])
            ->assertCreated();

        $this->assertSame($this->accounts['staff']->id, $task->comments()->sole()->author_id);
    }

    public function test_html_is_stored_and_returned_as_plain_text(): void
    {
        $task = $this->task(['status' => TaskStatus::InProgress], ['staff']);
        $content = '<script>alert(1)</script> <b>tebal</b>';

        $this->comment('staff', $task, $content)->assertCreated()->assertJsonPath('data.content', $content);
    }

    // Balasan

    public function test_a_reply_answers_a_comment_of_the_same_task(): void
    {
        $task = $this->task(['status' => TaskStatus::OnReview], ['staff']);
        $root = $this->comment('supervisor', $task, 'Tolong tambahkan sumber data.')->json('data.id');

        $reply = $this->comment('staff', $task, 'Sudah saya tambahkan.', $root)
            ->assertCreated()
            ->assertJsonPath('data.parent_comment_id', $root)
            ->assertJsonPath('data.reply_to', [
                'id' => $root,
                'author' => ['id' => $this->accounts['supervisor']->id, 'fullname' => 'supervisor'],
            ])
            ->json('data.id');

        $this->comment('supervisor', $task, 'Terima kasih, sudah sesuai.', $reply)
            ->assertCreated()
            ->assertJsonPath('data.reply_to.author.fullname', 'staff');
    }

    public function test_a_reply_to_a_comment_of_another_task_or_to_no_comment_is_rejected(): void
    {
        $task = $this->task(['status' => TaskStatus::InProgress], ['staff']);
        $other = $this->task(['status' => TaskStatus::InProgress], ['staff']);
        $foreign = $this->comment('staff', $other, 'Komentar tugas lain.')->json('data.id');

        foreach ([$foreign, 999999] as $parentId) {
            $this->comment('staff', $task, 'Balasan.', $parentId)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['parent_comment_id' => 'Balasan hanya dapat ditujukan ke komentar pada tugas yang sama.']);
        }

        $this->assertSame(0, $task->comments()->count());
        $this->assertSame(0, $task->activities()->count());
    }

    // Pengiriman ganda

    public function test_the_same_comment_sent_again_within_ten_seconds_is_stored_once(): void
    {
        $task = $this->task(['status' => TaskStatus::InProgress], ['staff']);

        $first = $this->comment('staff', $task, 'Sudah upload.')->assertCreated()->json('data.id');
        $this->travel(9)->seconds();
        $this->comment('staff', $task, 'Sudah upload.')
            ->assertOk()
            ->assertJsonPath('data.id', $first)
            ->assertJsonPath('message', 'Komentar berhasil dikirim.');

        $this->assertSame(1, $task->comments()->count());
        $this->assertSame(1, $task->activities()->where('action', TaskActivity::COMMENT_ADDED)->count());
    }

    public function test_a_repeated_comment_counts_as_new_after_the_window_or_with_another_author_parent_or_text(): void
    {
        $task = $this->task(['status' => TaskStatus::InProgress], ['staff', 'freelance']);
        $root = $this->comment('staff', $task, 'Oke.')->json('data.id');

        $this->comment('freelance', $task, 'Oke.')->assertCreated();
        $this->comment('staff', $task, 'Oke.', $root)->assertCreated();
        $this->comment('staff', $task, 'OKE.')->assertCreated();
        $this->travel(11)->seconds();
        $this->comment('staff', $task, 'Oke.')->assertCreated();

        $this->assertSame(5, $task->comments()->count());
        $this->assertSame(5, $task->activities()->where('action', TaskActivity::COMMENT_ADDED)->count());
    }

    // Histori, urutan, dan efek samping

    public function test_comments_are_listed_oldest_first_with_author_role_and_reply(): void
    {
        $task = $this->task(['status' => TaskStatus::OnReview], ['staff']);
        $root = $this->comment('supervisor', $task, 'Perlu revisi kecil.')->json('data.id');
        $this->travel(1)->minute();
        $this->comment('staff', $task, 'Siap, saya perbaiki.', $root);
        $this->travel(1)->minute();
        $this->comment('manager', $task, 'Catatan tambahan.');

        $data = $this->actingAs($this->accounts['freelance'])
            ->getJson("/api/tasks/{$task->id}/comments")
            ->assertOk()
            ->json('data');

        $this->assertSame(['Perlu revisi kecil.', 'Siap, saya perbaiki.', 'Catatan tambahan.'], array_column($data, 'content'));
        $this->assertSame(['SPV', 'EMP', 'MGR'], array_map(fn (array $item) => $item['author']['role']['code_role'], $data));
        $this->assertSame([null, $root, null], array_column($data, 'parent_comment_id'));
        $this->assertSame(['2026-11-20T02:00:00Z', '2026-11-20T02:01:00Z', '2026-11-20T02:02:00Z'], array_column($data, 'created_at'));
    }

    public function test_a_comment_is_recorded_once_in_the_history(): void
    {
        $task = $this->task(['status' => TaskStatus::InProgress], ['staff']);
        $root = $this->comment('supervisor', $task, 'Cek ulang angka.')->json('data.id');
        $reply = $this->comment('staff', $task, 'Sudah dicek.', $root)->json('data.id');

        $history = $this->actingAs($this->accounts['staff'])->getJson("/api/tasks/{$task->id}/activities")->json('data');
        $comments = array_values(array_filter($history, fn (array $entry) => $entry['action'] === TaskActivity::COMMENT_ADDED));

        $this->assertCount(2, $comments);
        $this->assertEqualsCanonicalizing(
            [['id' => $root, 'parent_comment_id' => null], ['id' => $reply, 'parent_comment_id' => $root]],
            $task->activities()->where('action', TaskActivity::COMMENT_ADDED)->get()->map(fn (TaskActivity $activity) => $activity->changes['comment']['new'])->all(),
        );
        $this->assertEqualsCanonicalizing(['Komentar ditambahkan.', 'Membalas komentar'], array_column($comments, 'description'));
    }

    public function test_a_comment_changes_neither_status_review_nor_pics(): void
    {
        $task = $this->task(['status' => TaskStatus::OnReview], ['staff']);
        $before = $task->fresh()->getAttributes();
        $this->travel(1)->hour();

        $this->comment('supervisor', $task, 'Approve setelah tabel dirapikan.')->assertCreated();
        $this->comment('staff', $task, 'Request revision?')->assertCreated();

        $this->assertSame($before, $task->fresh()->getAttributes());
        $this->assertSame(0, $task->reviews()->count());
        $this->assertSame([$this->accounts['staff']->id], $task->activeAssignments()->pluck('account_id')->all());
    }

    public function test_comments_stay_after_status_pic_and_revision_changes(): void
    {
        $task = $this->task(['status' => TaskStatus::OnReview, 'version' => 1], ['staff', 'freelance']);
        $this->comment('freelance', $task, 'Bagian saya selesai.')->assertCreated();
        $this->comment('supervisor', $task, 'Data bulan lalu belum masuk.')->assertCreated();

        $this->actingAs($this->accounts['supervisor'])
            ->postJson("/api/tasks/{$task->id}/reviews", ['version' => 1, 'decision' => 'revision', 'note' => 'Tambahkan data bulan lalu.'])
            ->assertOk();
        $this->release($task, 'freelance');

        $this->assertSame(TaskStatus::InProgress, $task->fresh()->status);
        $this->actingAs($this->accounts['staff'])
            ->getJson("/api/tasks/{$task->id}/comments")
            ->assertOk()
            ->assertJsonPath('data.*.content', ['Bagian saya selesai.', 'Data bulan lalu belum masuk.'])
            ->assertJsonPath('data.0.author.fullname', 'freelance');
        $this->comment('staff', $task, 'Saya lanjutkan revisinya.')->assertCreated();
    }

    public function test_the_number_of_queries_does_not_grow_with_the_comments(): void
    {
        $task = $this->task(['status' => TaskStatus::InProgress], ['staff', 'freelance']);
        $this->comment('freelance', $task, 'Balasan awal.', $this->comment('staff', $task, 'Awal.')->json('data.id'));

        $queries = function () use ($task): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs(Account::findOrFail($this->accounts['freelance']->id))->getJson("/api/tasks/{$task->id}/comments")->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $before = $queries();

        foreach (['admin', 'manager', 'supervisor', 'freelance'] as $role) {
            $root = $this->comment($role, $task, "Komentar {$role}.")->json('data.id');
            $this->comment('staff', $task, "Balasan untuk {$role}.", $root);
        }

        $this->assertSame(10, $task->comments()->count());
        $this->assertSame($before, $queries());
    }

    private function comment(string $role, Task $task, string $content, ?int $parentId = null): TestResponse
    {
        return $this->actingAs($this->accounts[$role])->postJson(
            "/api/tasks/{$task->id}/comments",
            ['content' => $content] + ($parentId === null ? [] : ['parent_comment_id' => $parentId]),
        );
    }

    private function release(Task $task, string $role): void
    {
        $task->activeAssignments()->where('account_id', $this->accounts[$role]->id)
            ->update(['unassigned_at' => now(), 'unassigned_by' => $this->accounts['admin']->id]);
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
            'username' => "cmt.{$username}",
            'password' => 'Password123!',
        ]);
    }
}
