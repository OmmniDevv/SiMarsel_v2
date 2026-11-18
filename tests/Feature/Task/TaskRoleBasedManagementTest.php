<?php

namespace Tests\Feature\Task;

use App\Enums\TaskStatus;
use App\Models\Account;
use App\Models\Division;
use App\Models\Role;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskAssignment;
use App\Models\TaskEvidence;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * TASK-13 (STEAM-245): the PIC's own work note, deleting one's own evidence, PICs a Supervisor may add, and who
 * moves a task to another division.
 */
class TaskRoleBasedManagementTest extends TestCase
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
        $this->travelTo('2026-11-18 09:00:00');
        Storage::fake('local');

        $this->marketing = Division::create(['code_division' => 'MKT', 'name' => 'Marketing']);
        $this->finance = Division::create(['code_division' => 'FIN', 'name' => 'Finance']);

        $this->accounts = [
            'admin' => $this->account(Role::ADMIN, 'admin', null),
            'manager' => $this->account(Role::MANAGER, 'manager', $this->finance),
            'supervisor' => $this->account(Role::SUPERVISOR, 'supervisor', $this->marketing),
            'staff' => $this->account(Role::EMPLOYEE, 'staff', $this->marketing),
            'other' => $this->account(Role::EMPLOYEE, 'other', $this->marketing),
            'freelance' => $this->account(Role::FREELANCE, 'freelance', null),
            'finstaff' => $this->account(Role::EMPLOYEE, 'finstaff', $this->finance),
        ];
    }

    // Catatan kerja PIC

    public function test_a_pic_writes_their_own_work_note_without_touching_the_instruction_or_the_version(): void
    {
        $task = $this->task(['status' => TaskStatus::InProgress, 'description' => 'Instruksi utama.'], ['staff', 'other']);

        $this->note($task, 'staff', '  Draft banner selesai 80%.  ')
            ->assertOk()
            ->assertJsonPath('message', 'Catatan kerja berhasil disimpan.')
            ->assertJsonPath('data.description', 'Instruksi utama.')
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.can_edit_work_note', true)
            ->assertJsonPath('data.work_notes', [
                ['account' => ['id' => $this->accounts['other']->id, 'fullname' => 'other'], 'work_note' => null, 'updated_at' => null],
                ['account' => ['id' => $this->accounts['staff']->id, 'fullname' => 'staff'], 'work_note' => 'Draft banner selesai 80%.', 'updated_at' => '2026-11-18T02:00:00Z'],
            ]);

        $fresh = $task->fresh();
        $this->assertSame('Instruksi utama.', $fresh->description);
        $this->assertSame(TaskStatus::InProgress, $fresh->status);

        $activity = $task->activities()->first();
        $this->assertSame(TaskActivity::WORK_NOTE_UPDATED, $activity->action);
        $this->assertSame($this->accounts['staff']->id, $activity->account_id);
        $this->assertSame(['work_note' => ['old' => null, 'new' => 'Draft banner selesai 80%.']], $activity->orderedChanges());
        $this->assertSame('Catatan kerja diperbarui', $activity->description());
    }

    public function test_one_pic_never_overwrites_another_pics_note(): void
    {
        $task = $this->task(['status' => TaskStatus::InProgress], ['staff', 'other']);

        $this->note($task, 'staff', 'Catatan staff.')->assertOk();
        $this->note($task, 'other', 'Catatan other.')->assertOk();
        $this->note($task, 'staff', 'Catatan staff revisi.')->assertOk();

        $this->assertSame('Catatan staff revisi.', $this->assignmentOf($task, 'staff')->work_note);
        $this->assertSame('Catatan other.', $this->assignmentOf($task, 'other')->work_note);
    }

    public function test_an_empty_note_removes_it_and_resending_the_same_note_records_nothing(): void
    {
        $task = $this->task(['status' => TaskStatus::Todo], ['staff']);

        $this->note($task, 'staff', 'Catatan.')->assertOk();
        $this->note($task, 'staff', 'Catatan.')->assertOk();
        $this->assertSame(1, $task->activities()->count());

        $this->note($task, 'staff', '')->assertOk()->assertJsonPath('data.work_notes.0.work_note', null);

        $this->assertNull($this->assignmentOf($task, 'staff')->work_note);
        $activity = $task->activities()->first();
        $this->assertSame(TaskActivity::WORK_NOTE_DELETED, $activity->action);
        $this->assertSame(['work_note' => ['old' => 'Catatan.', 'new' => null]], $activity->orderedChanges());
    }

    public function test_only_active_pics_write_a_work_note(): void
    {
        $task = $this->task(['status' => TaskStatus::InProgress], ['staff']);

        foreach (['admin', 'manager', 'supervisor', 'other', 'freelance'] as $role) {
            $this->note($task, $role, 'Bukan PIC.')->assertForbidden();
            $this->actingAs($this->accounts[$role])->getJson("/api/tasks/{$task->id}")->assertJsonPath('data.can_edit_work_note', false);
        }

        $this->assertNull($this->assignmentOf($task, 'staff')->work_note);
        $this->assertSame(0, $task->activities()->count());
    }

    public function test_a_supervisor_who_is_pic_writes_their_note_like_any_pic(): void
    {
        $task = $this->task(['status' => TaskStatus::InProgress, 'division_id' => $this->finance->id], ['supervisor']);

        $this->note($task, 'supervisor', 'Catatan supervisor sebagai PIC.')->assertOk();
    }

    public function test_a_released_pic_loses_the_note_rights_but_their_note_stays_on_the_ended_assignment(): void
    {
        $task = $this->task(['status' => TaskStatus::InProgress], ['staff', 'other']);
        $this->note($task, 'staff', 'Catatan sebelum dilepas.')->assertOk();

        $this->actingAs($this->accounts['admin'])
            ->patchJson("/api/tasks/{$task->id}/assignment", ['pic_ids' => [$this->accounts['other']->id], 'version' => 1])
            ->assertOk();

        $this->note($task, 'staff', 'Masih boleh?')->assertForbidden();
        $this->actingAs($this->accounts['staff'])->getJson("/api/tasks/{$task->id}")
            ->assertOk()
            ->assertJsonPath('data.can_edit_work_note', false)
            ->assertJsonCount(1, 'data.work_notes');

        $ended = TaskAssignment::where('task_id', $task->id)->where('account_id', $this->accounts['staff']->id)->sole();
        $this->assertNotNull($ended->unassigned_at);
        $this->assertSame('Catatan sebelum dilepas.', $ended->work_note);
    }

    public function test_the_work_note_cannot_change_once_the_task_is_done(): void
    {
        $task = $this->task(['status' => TaskStatus::Done], ['staff']);

        $this->note($task, 'staff', 'Terlambat.')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['work_note' => 'Catatan kerja tidak dapat diubah setelah tugas selesai.']);
        $this->actingAs($this->accounts['staff'])->getJson("/api/tasks/{$task->id}")->assertJsonPath('data.can_edit_work_note', false);
    }

    public function test_the_work_note_is_validated_and_an_inactive_pic_is_refused(): void
    {
        $task = $this->task(['status' => TaskStatus::Todo], ['staff']);

        $this->actingAs($this->accounts['staff'])->putJson("/api/tasks/{$task->id}/work-note", [])
            ->assertUnprocessable()->assertJsonValidationErrors(['work_note']);
        $this->note($task, 'staff', str_repeat('a', 5001))
            ->assertUnprocessable()->assertJsonValidationErrors(['work_note' => 'Catatan kerja maksimal 5000 karakter.']);

        $this->accounts['staff']->update(['is_active' => false]);
        $this->note($task, 'staff', 'Akun nonaktif.')->assertForbidden();
        $this->putJson("/api/tasks/{$task->id}/work-note", ['work_note' => 'x'])->assertForbidden();
    }

    public function test_writing_a_note_on_a_deleted_or_unknown_task_is_not_found(): void
    {
        $task = $this->task(['status' => TaskStatus::Todo], ['staff']);
        $task->delete();

        $this->note($task, 'staff', 'Tugas terhapus.')->assertNotFound();
        $this->actingAs($this->accounts['staff'])->putJson('/api/tasks/999999/work-note', ['work_note' => 'x'])->assertNotFound();
    }

    // Hapus evidence milik sendiri

    public function test_the_uploader_deletes_their_own_evidence_without_touching_the_task(): void
    {
        $task = $this->task(['status' => TaskStatus::InProgress, 'evidence_required' => true], ['staff', 'other']);
        $file = $this->upload($task, 'staff');
        $kept = $this->link($task, 'other');

        $this->actingAs($this->accounts['staff'])
            ->getJson("/api/tasks/{$task->id}/evidences")
            ->assertJsonPath('data.0.id', $kept->id)
            ->assertJsonPath('data.0.can_delete', false)
            ->assertJsonPath('data.1.can_delete', true);

        $this->deleteEvidence($task, $file, 'staff')
            ->assertOk()
            ->assertJsonPath('message', 'Evidence berhasil dihapus.');

        $deleted = TaskEvidence::withTrashed()->find($file->id);
        $this->assertSoftDeleted($deleted);
        $this->assertSame($this->accounts['staff']->id, $deleted->deleted_by);
        Storage::disk('local')->assertExists($deleted->file_path);

        $this->actingAs($this->accounts['staff'])->getJson("/api/tasks/{$task->id}/evidences")
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $kept->id);
        $this->actingAs($this->accounts['staff'])->getJson("/api/tasks/{$task->id}")
            ->assertJsonCount(1, 'data.evidence')
            ->assertJsonPath('data.evidence_count', 1)
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.version', 1);
        $this->actingAs($this->accounts['staff'])->get("/api/tasks/{$task->id}/evidences/{$file->id}/download")->assertNotFound();
        $this->assertNotSoftDeleted($task);

        $activity = $task->activities()->first();
        $this->assertSame(TaskActivity::EVIDENCE_DELETED, $activity->action);
        $this->assertSame($this->accounts['staff']->id, $activity->account_id);
        $this->assertEquals(['id' => $file->id, 'type' => 'file', 'name' => 'hasil.pdf'], $activity->changes['evidence']['old']);
        $this->assertSame('Evidence: hasil.pdf', $activity->description());
    }

    public function test_nobody_deletes_evidence_uploaded_by_someone_else(): void
    {
        $task = $this->task(['status' => TaskStatus::InProgress], ['staff', 'other']);
        $evidence = $this->link($task, 'staff');
        $legacy = TaskEvidence::create(['task_id' => $task->id, 'submitter_id' => null, 'type' => 'link', 'url' => 'https://example.com/lama']);

        foreach (['other', 'admin', 'manager', 'supervisor', 'freelance', 'finstaff'] as $role) {
            $this->deleteEvidence($task, $evidence, $role)->assertForbidden();
        }

        $this->deleteEvidence($task, $legacy, 'staff')->assertForbidden();
        $this->deleteEvidence($task, $legacy, 'other')->assertForbidden();

        $this->assertNotSoftDeleted($evidence);
        $this->assertNotSoftDeleted($legacy);
        $this->assertSame(0, $task->activities()->where('action', TaskActivity::EVIDENCE_DELETED)->count());
    }

    public function test_a_released_uploader_can_no_longer_delete_their_evidence(): void
    {
        $task = $this->task(['status' => TaskStatus::InProgress], ['staff', 'other']);
        $evidence = $this->link($task, 'staff');

        $this->actingAs($this->accounts['admin'])
            ->patchJson("/api/tasks/{$task->id}/assignment", ['pic_ids' => [$this->accounts['other']->id], 'version' => 1])
            ->assertOk();

        $this->deleteEvidence($task, $evidence, 'staff')->assertForbidden();
        $this->assertNotSoftDeleted($evidence);
    }

    public function test_evidence_under_review_or_of_a_finished_task_cannot_be_deleted(): void
    {
        foreach ([TaskStatus::ReadyReview, TaskStatus::OnReview, TaskStatus::Done] as $status) {
            $task = $this->task(['status' => TaskStatus::InProgress], ['staff']);
            $evidence = $this->link($task, 'staff');
            $task->update(['status' => $status]);

            $this->deleteEvidence($task, $evidence, 'staff')
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['evidence' => "Evidence hanya dapat dihapus saat tugas berstatus To Do atau On Process, bukan {$status->label()}."]);
            $this->assertNotSoftDeleted($evidence);
        }

        $todo = $this->task(['status' => TaskStatus::Todo], ['staff']);
        $this->deleteEvidence($todo, $this->link($todo, 'staff'), 'staff')->assertOk();
    }

    public function test_evidence_of_another_task_or_already_deleted_is_not_found(): void
    {
        $task = $this->task(['status' => TaskStatus::InProgress], ['staff']);
        $other = $this->task(['status' => TaskStatus::InProgress], ['staff']);
        $evidence = $this->link($other, 'staff');

        $this->deleteEvidence($task, $evidence, 'staff')->assertNotFound();
        $this->assertNotSoftDeleted($evidence);

        $this->deleteEvidence($other, $evidence, 'staff')->assertOk();
        $this->deleteEvidence($other, $evidence, 'staff')->assertNotFound();
    }

    // Cakupan PIC Supervisor

    public function test_a_supervisor_picks_pics_only_from_their_division_while_admin_and_managers_pick_from_every_division(): void
    {
        $names = fn (string $role) => collect($this->actingAs($this->accounts[$role])->getJson('/api/tasks/create-options')->assertOk()->json('data.pics'))
            ->pluck('fullname')->sort()->values()->all();

        $everyone = ['finstaff', 'freelance', 'other', 'staff', 'supervisor'];
        $this->assertSame($everyone, $names('admin'));
        $this->assertSame($everyone, $names('manager'));
        $this->assertSame(['other', 'staff', 'supervisor'], $names('supervisor'));

        $this->actingAs($this->accounts['supervisor'])
            ->postJson('/api/tasks', ['title' => 'Lintas divisi', 'division_id' => $this->marketing->id, 'pic_ids' => [$this->accounts['staff']->id, $this->accounts['finstaff']->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pic_ids.1' => 'Supervisor hanya dapat menugaskan PIC dari divisinya sendiri.']);

        $this->actingAs($this->accounts['supervisor'])
            ->postJson('/api/tasks', ['title' => 'Divisi sendiri', 'division_id' => $this->marketing->id, 'status' => 'todo', 'pic_ids' => [$this->accounts['staff']->id, $this->accounts['supervisor']->id]])
            ->assertCreated();

        $this->actingAs($this->accounts['manager'])
            ->postJson('/api/tasks', ['title' => 'Manajer lintas divisi', 'division_id' => $this->marketing->id, 'status' => 'todo', 'pic_ids' => [$this->accounts['staff']->id, $this->accounts['finstaff']->id, $this->accounts['freelance']->id]])
            ->assertCreated();
    }

    public function test_a_supervisor_keeps_or_releases_a_pic_of_another_division_but_cannot_add_one(): void
    {
        $task = $this->task(['status' => TaskStatus::Todo], ['staff', 'finstaff']);
        $staff = $this->accounts['staff']->id;
        $finstaff = $this->accounts['finstaff']->id;
        $other = $this->accounts['other']->id;
        $freelance = $this->accounts['freelance']->id;

        $this->assign($task, 'supervisor', [$staff, $finstaff, $other])->assertOk();
        $this->assign($task, 'supervisor', [$staff, $other, $freelance])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pic_ids.2' => 'Supervisor hanya dapat menugaskan PIC dari divisinya sendiri.']);
        $this->assign($task, 'supervisor', [$staff, $other])->assertOk();
        $this->assign($task, 'supervisor', [$staff, $other, $finstaff])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pic_ids.2']);

        $this->assertEqualsCanonicalizing([$staff, $other], $task->fresh()->activeAssignments()->pluck('account_id')->all());

        $options = $this->actingAs($this->accounts['supervisor'])->getJson("/api/tasks/{$task->id}/assignment-options")->assertOk();
        $options->assertJsonPath('data.can_change_division', false)
            ->assertJsonPath('data.divisions', [['id' => $this->marketing->id, 'code_division' => 'MKT', 'name' => 'Marketing']]);
        $this->assertSame(['other', 'staff', 'supervisor'], collect($options->json('data.pics'))->pluck('fullname')->sort()->values()->all());

        $this->actingAs($this->accounts['manager'])->getJson("/api/tasks/{$task->id}/assignment-options")
            ->assertJsonPath('data.can_change_division', true)
            ->assertJsonCount(2, 'data.divisions')
            ->assertJsonCount(5, 'data.pics');
    }

    public function test_only_admin_and_managers_move_a_task_to_another_division_through_the_edit_form(): void
    {
        $task = $this->task(['status' => TaskStatus::Todo], ['staff']);

        $this->actingAs($this->accounts['supervisor'])->getJson("/api/tasks/{$task->id}")
            ->assertJsonPath('data.can_edit', true)
            ->assertJsonPath('data.editable_fields', fn (array $fields) => ! in_array('division_id', $fields, true) && in_array('title', $fields, true));
        $this->actingAs($this->accounts['supervisor'])
            ->patchJson("/api/tasks/{$task->id}", ['division_id' => $this->finance->id, 'version' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['division_id' => 'Hanya Admin dan Manajer yang dapat memindahkan tugas ke divisi lain.']);
        $this->assertSame($this->marketing->id, $task->fresh()->division_id);

        foreach (['manager', 'admin'] as $role) {
            $this->actingAs($this->accounts[$role])->getJson("/api/tasks/{$task->id}")
                ->assertJsonPath('data.editable_fields', fn (array $fields) => in_array('division_id', $fields, true));
        }

        $this->actingAs($this->accounts['manager'])
            ->patchJson("/api/tasks/{$task->id}", ['division_id' => $this->finance->id, 'version' => 1])
            ->assertOk()
            ->assertJsonPath('data.division.code_division', 'FIN');
    }

    public function test_changing_pics_does_not_change_the_status_or_the_other_pics(): void
    {
        $task = $this->task(['status' => TaskStatus::InProgress], ['staff', 'other']);
        $kept = $this->assignmentOf($task, 'other');

        $this->assign($task, 'supervisor', [$this->accounts['other']->id, $this->accounts['supervisor']->id])->assertOk();

        $fresh = $task->fresh();
        $this->assertSame(TaskStatus::InProgress, $fresh->status);
        $this->assertTrue($kept->is($this->assignmentOf($fresh, 'other')));
        $this->assertSame($kept->assigned_at->toIso8601String(), $this->assignmentOf($fresh, 'other')->assigned_at->toIso8601String());

        $activity = $fresh->activities()->first();
        $this->assertSame(TaskActivity::REASSIGNED, $activity->action);
        $this->assertSame($this->accounts['supervisor']->id, $activity->account_id);
        $this->assertSame(['other', 'staff'], array_column($activity->changes['pics']['old'], 'name'));
        $this->assertSame(['other', 'supervisor'], array_column($activity->changes['pics']['new'], 'name'));
    }

    /**
     * @param  array<int, int>  $picIds
     */
    private function assign(Task $task, string $role, array $picIds): TestResponse
    {
        return $this->actingAs($this->accounts[$role])
            ->patchJson("/api/tasks/{$task->id}/assignment", ['pic_ids' => $picIds, 'version' => $task->fresh()->version]);
    }

    private function note(Task $task, string $role, ?string $note): TestResponse
    {
        return $this->actingAs($this->accounts[$role])->putJson("/api/tasks/{$task->id}/work-note", ['work_note' => $note]);
    }

    private function deleteEvidence(Task $task, TaskEvidence $evidence, string $role): TestResponse
    {
        return $this->actingAs($this->accounts[$role])->deleteJson("/api/tasks/{$task->id}/evidences/{$evidence->id}");
    }

    private function upload(Task $task, string $role): TaskEvidence
    {
        $this->actingAs($this->accounts[$role])
            ->post("/api/tasks/{$task->id}/evidences", ['file' => UploadedFile::fake()->create('hasil.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertCreated();

        return TaskEvidence::where('task_id', $task->id)->latest('id')->firstOrFail();
    }

    private function link(Task $task, string $role): TaskEvidence
    {
        $this->travel(1)->seconds();

        return TaskEvidence::create([
            'task_id' => $task->id,
            'submitter_id' => $this->accounts[$role]->id,
            'type' => 'link',
            'url' => "https://example.com/{$role}",
        ]);
    }

    private function assignmentOf(Task $task, string $role): TaskAssignment
    {
        return $task->activeAssignments()->where('account_id', $this->accounts[$role]->id)->sole();
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
            'username' => "t13.{$username}",
            'password' => 'Password123!',
        ]);
    }
}
