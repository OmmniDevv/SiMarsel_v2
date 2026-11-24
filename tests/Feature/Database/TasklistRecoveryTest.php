<?php

namespace Tests\Feature\Database;

use App\Exceptions\UnsafeTasklistRollbackException;
use App\Services\TasklistRecoveryService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TasklistRecoveryTest extends TestCase
{
    use DatabaseMigrations;

    private const ROLLBACK_GUARD = 'database/migrations/2026_11_16_090100_guard_tasklist_rollback.php';

    protected function setUp(): void
    {
        parent::setUp();

        config(['tasklist.allow_destructive_rollback' => false]);
    }

    protected function tearDown(): void
    {
        config(['tasklist.allow_destructive_rollback' => true]);

        parent::tearDown();
    }

    public function test_recovery_reports_an_empty_database_as_safe_to_roll_back(): void
    {
        $this->assertSame(0, array_sum(app(TasklistRecoveryService::class)->newDataReport()));
        $this->artisan('tasks:recovery')->expectsOutputToContain('Rollback aman')->assertSuccessful();
    }

    public function test_assignment_history_blocks_a_destructive_tasklist_rollback(): void
    {
        $taskId = $this->task();
        $accountId = $this->account();

        DB::table('task_assignments')->insert([
            'task_id' => $taskId,
            'account_id' => $accountId,
            'assigned_at' => now(),
        ]);

        $this->assertSame(1, app(TasklistRecoveryService::class)->newDataReport()['Riwayat penugasan PIC']);
        $this->artisan('tasks:recovery')->expectsOutputToContain('Rollback schema akan menghilangkan data')->assertSuccessful();

        $this->expectException(UnsafeTasklistRollbackException::class);
        $this->expectExceptionMessage('Riwayat penugasan PIC: 1');
        $this->artisan('migrate:rollback', ['--path' => self::ROLLBACK_GUARD])->run();
    }

    private function task(): int
    {
        $divisionId = DB::table('divisions')->insertGetId([
            'code_division' => 'MKT',
            'name' => 'Marketing',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('tasks')->insertGetId([
            'title' => 'Task recovery',
            'division_id' => $divisionId,
            'priority' => 'medium',
            'status' => 'backlog',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function account(): int
    {
        $roleId = DB::table('roles')->insertGetId([
            'code_role' => 'EMP',
            'name' => 'Staff',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('accounts')->insertGetId([
            'role_id' => $roleId,
            'fullname' => 'PIC Recovery',
            'username' => 'pic.recovery',
            'password' => 'not-used',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
