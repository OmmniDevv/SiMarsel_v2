<?php

namespace Tests\Feature\Account;

use App\Enums\TaskStatus;
use App\Models\Account;
use App\Models\AccountRoleChange;
use App\Models\Division;
use App\Models\Role;
use App\Models\Task;
use App\Services\AccountService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TASK-12: every role an account gets is recorded, and a role change updates its permissions without touching task
 * history.
 */
class AccountRoleAuditTest extends TestCase
{
    use RefreshDatabase;

    private Account $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        $this->travelTo('2026-11-16 10:00:00');
        $this->manager = Account::where('username', 'manager')->first();
    }

    public function test_creating_an_account_records_its_first_role(): void
    {
        $this->actingAs($this->manager)->postJson('/api/accounts', [
            'fullname' => 'Freelancer Baru',
            'username' => 'freelancer.baru',
            'password' => 'Secret123!',
            'role_id' => $this->roleId(Role::FREELANCE),
            'division_id' => null,
        ])->assertCreated();

        $account = Account::where('username', 'freelancer.baru')->sole();

        $this->assertSame(
            [[null, $this->roleId(Role::FREELANCE), $this->manager->id, '2026-11-16 10:00:00']],
            $this->changes($account),
        );
    }

    public function test_changing_the_role_records_old_and_new_role_and_who_changed_it(): void
    {
        $account = $this->staff();

        $this->actingAs($this->manager)
            ->patchJson("/api/accounts/{$account->id}", ['role_id' => $this->roleId(Role::SUPERVISOR)])
            ->assertOk();
        $this->travelTo('2026-11-16 11:00:00');
        $this->actingAs($this->manager)
            ->patchJson("/api/accounts/{$account->id}", ['role_id' => $this->roleId(Role::EMPLOYEE)])
            ->assertOk();

        $this->assertSame([
            [$this->roleId(Role::EMPLOYEE), $this->roleId(Role::SUPERVISOR), $this->manager->id, '2026-11-16 10:00:00'],
            [$this->roleId(Role::SUPERVISOR), $this->roleId(Role::EMPLOYEE), $this->manager->id, '2026-11-16 11:00:00'],
        ], $this->changes($account));
    }

    public function test_an_update_that_keeps_the_role_records_nothing(): void
    {
        $account = $this->staff();

        $this->actingAs($this->manager)
            ->patchJson("/api/accounts/{$account->id}", ['fullname' => 'Nama Baru', 'role_id' => $this->roleId(Role::EMPLOYEE)])
            ->assertOk();

        $this->assertSame([], $this->changes($account));
    }

    public function test_a_rejected_role_change_records_nothing(): void
    {
        $this->actingAs($this->manager)
            ->patchJson("/api/accounts/{$this->manager->id}", ['role_id' => $this->roleId(Role::EMPLOYEE)])
            ->assertUnprocessable();

        $this->assertSame(0, AccountRoleChange::count());
    }

    public function test_a_role_change_updates_the_permissions_at_once_and_leaves_task_history_untouched(): void
    {
        $division = Division::create(['code_division' => 'MKT', 'name' => 'Marketing']);
        $account = $this->staff($division);
        $task = Task::factory()->withPics($account)->create([
            'division_id' => $division->id,
            'creator_id' => $this->manager->id,
            'status' => TaskStatus::Todo,
            'evidence_required' => false,
        ]);
        $this->actingAs($account)
            ->patchJson("/api/tasks/{$task->id}/status", ['status' => 'in_progress', 'version' => 1])
            ->assertOk();
        $history = DB::table('task_activities')->where('task_id', $task->id)->get();

        $this->actingAs($account)->getJson("/api/tasks/{$task->id}")
            ->assertJsonPath('data.can_assign', false)
            ->assertJsonPath('data.can_delete', false);

        $this->actingAs($this->manager)
            ->patchJson("/api/accounts/{$account->id}", ['role_id' => $this->roleId(Role::SUPERVISOR)])
            ->assertOk();

        $this->actingAs($account->fresh())->getJson("/api/tasks/{$task->id}")
            ->assertJsonPath('data.can_assign', true)
            ->assertJsonPath('data.can_delete', true);
        $this->assertEquals($history, DB::table('task_activities')->where('task_id', $task->id)->get());
    }

    public function test_the_role_audit_stays_with_both_identities_after_the_changed_account_is_deleted(): void
    {
        $account = $this->staff();
        $this->actingAs($this->manager)
            ->patchJson("/api/accounts/{$account->id}", ['role_id' => $this->roleId(Role::FREELANCE)])
            ->assertOk();

        $this->actingAs($this->manager)->deleteJson("/api/accounts/{$account->id}")->assertOk();
        $this->assertModelMissing($account);

        $audit = AccountRoleChange::sole();
        $this->assertNull($audit->account_id);
        $this->assertSame(['Staff Audit', 'staff.audit'], [$audit->account_fullname, $audit->account_username]);
        $this->assertSame([$this->roleId(Role::EMPLOYEE), $this->roleId(Role::FREELANCE)], [$audit->old_role_id, $audit->new_role_id]);
        $this->assertSame($this->manager->id, $audit->changed_by);
        $this->assertSame([$this->manager->fullname, 'manager'], [$audit->changed_by_fullname, $audit->changed_by_username]);
        $this->assertSame('2026-11-16 10:00:00', $audit->changed_at->toDateTimeString());
    }

    public function test_the_role_audit_still_names_who_changed_it_after_that_account_is_deleted(): void
    {
        $changer = Account::create([
            'role_id' => $this->roleId(Role::MANAGER),
            'fullname' => 'Manajer Sementara',
            'username' => 'manajer.sementara',
            'password' => 'Secret123!',
        ]);
        $account = $this->staff();

        $this->actingAs($changer)
            ->patchJson("/api/accounts/{$account->id}", ['role_id' => $this->roleId(Role::SUPERVISOR)])
            ->assertOk();
        $this->actingAs($this->manager)->deleteJson("/api/accounts/{$changer->id}")->assertOk();

        $audit = $account->roleChanges()->sole();
        $this->assertSame($account->id, $audit->account_id);
        $this->assertNull($audit->changed_by);
        $this->assertSame(['Manajer Sementara', 'manajer.sementara'], [$audit->changed_by_fullname, $audit->changed_by_username]);
    }

    public function test_an_account_whose_only_history_is_the_role_audit_can_still_be_deleted(): void
    {
        $this->actingAs($this->manager)->postJson('/api/accounts', [
            'fullname' => 'Akun Baru',
            'username' => 'akun.baru',
            'password' => 'Secret123!',
            'role_id' => $this->roleId(Role::EMPLOYEE),
            'division_id' => null,
        ])->assertCreated();
        $account = Account::where('username', 'akun.baru')->sole();

        $this->actingAs($this->manager)->deleteJson("/api/accounts/{$account->id}")->assertOk();

        $this->assertSame(1, AccountRoleChange::where('account_username', 'akun.baru')->whereNull('account_id')->count());
    }

    /**
     * Two admins change the same account moments apart: the second request was built from the account as it was
     * before the first change was saved. The audit must still read Staff -> Supervisor, then Supervisor -> Manager.
     */
    public function test_role_changes_made_at_nearly_the_same_time_are_audited_in_order(): void
    {
        $account = $this->staff();
        $loadedBySecondAdmin = Account::findOrFail($account->id);
        $secondAdmin = Account::create([
            'role_id' => $this->roleId(Role::ADMIN),
            'fullname' => 'Admin Kedua',
            'username' => 'admin.kedua',
            'password' => 'Secret123!',
        ]);

        $this->actingAs($this->manager)
            ->patchJson("/api/accounts/{$account->id}", ['role_id' => $this->roleId(Role::SUPERVISOR)])
            ->assertOk();
        $this->travelTo('2026-11-16 10:00:01');

        $updated = app(AccountService::class)->update($loadedBySecondAdmin, ['role_id' => $this->roleId(Role::MANAGER)], $secondAdmin);

        $this->assertSame($this->roleId(Role::MANAGER), $updated->role_id);
        $this->assertSame($this->roleId(Role::MANAGER), $account->fresh()->role_id);
        $this->assertSame([
            [$this->roleId(Role::EMPLOYEE), $this->roleId(Role::SUPERVISOR), $this->manager->id, '2026-11-16 10:00:00'],
            [$this->roleId(Role::SUPERVISOR), $this->roleId(Role::MANAGER), $secondAdmin->id, '2026-11-16 10:00:01'],
        ], $this->changes($account));
    }

    public function test_a_change_that_keeps_the_role_saved_moments_earlier_records_nothing(): void
    {
        $account = $this->staff();
        $stale = Account::findOrFail($account->id);

        $this->actingAs($this->manager)
            ->patchJson("/api/accounts/{$account->id}", ['role_id' => $this->roleId(Role::SUPERVISOR)])
            ->assertOk();

        app(AccountService::class)->update($stale, ['role_id' => $this->roleId(Role::SUPERVISOR)], $this->manager);

        $this->assertCount(1, $this->changes($account));
    }

    private function staff(?Division $division = null): Account
    {
        return Account::create([
            'role_id' => $this->roleId(Role::EMPLOYEE),
            'division_id' => $division?->id,
            'fullname' => 'Staff Audit',
            'username' => 'staff.audit',
            'password' => 'Secret123!',
        ]);
    }

    private function roleId(string $code): int
    {
        return Role::where('code_role', $code)->value('id');
    }

    /**
     * @return array<int, array{0: ?int, 1: ?int, 2: ?int, 3: string}>
     */
    private function changes(Account $account): array
    {
        return $account->roleChanges()->get()
            ->map(fn (AccountRoleChange $change) => [$change->old_role_id, $change->new_role_id, $change->changed_by, $change->changed_at->toDateTimeString()])
            ->all();
    }
}
