<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;

/**
 * Read-only checks that the TASK-19 Tasklist migrations were applied and left the data consistent. Meant to run on
 * development, staging, and production right after migrating; it never writes.
 */
class TasklistMigrationValidator
{
    public const PASS = 'PASS';

    public const WARN = 'WARN';

    public const FAIL = 'FAIL';

    public const SKIP = 'SKIP';

    /**
     * Migration file prefix of the TASK-19 changes.
     */
    private const MIGRATION_PREFIX = '2026_11_13_';

    private const LEGACY_STATUSES = ['ready_for_qa', 'on_qa'];

    /**
     * Foreign keys that must not cascade physical deletes into task history (STEAM-271).
     *
     * @var array<string, array<int, string>>
     */
    private const RESTRICTED_FOREIGN_KEYS = [
        'task_activities' => ['task_id'],
        'task_evidences' => ['task_id'],
        'task_reviews' => ['task_id'],
        'task_assignments' => ['task_id', 'account_id'],
        'task_comments' => ['task_id', 'author_id', 'parent_comment_id'],
    ];

    /**
     * @return array<int, array{check: string, status: string, detail: string}>
     */
    public function validate(): array
    {
        return [
            $this->migrationsApplied(),
            $this->roles(),
            $this->permissions(),
            $this->noLegacyStatuses(),
            $this->noUnknownStatuses(),
            $this->noLegacyStatusesInHistory(),
            $this->picIdBackfilled(),
            $this->noDuplicateActivePics(),
            $this->activeTasksHavePic(),
            $this->activePicsAreEligible(),
            $this->noOrphanedHistory(),
            $this->repliesStayInTheirTask(),
            $this->ownerIsNotCollaborator(),
            $this->historyForeignKeysRestrict(),
        ];
    }

    /**
     * @param  array<int, array{status: string}>  $results
     */
    public function passed(array $results): bool
    {
        return collect($results)->doesntContain('status', self::FAIL);
    }

    private function migrationsApplied(): array
    {
        $files = collect(glob(database_path('migrations/'.self::MIGRATION_PREFIX.'*.php')))
            ->map(fn (string $path) => basename($path, '.php'));
        $missing = $files->diff(DB::table('migrations')->pluck('migration'))->values();

        return $missing->isEmpty()
            ? $this->result('Migration TASK-19 sudah dijalankan', self::PASS, "{$files->count()} migration")
            : $this->result('Migration TASK-19 sudah dijalankan', self::FAIL, 'Belum jalan: '.$missing->implode(', '));
    }

    private function roles(): array
    {
        $expected = [Role::ADMIN, Role::MANAGER, Role::SUPERVISOR, Role::EMPLOYEE, Role::FREELANCE];
        $missing = array_values(array_diff($expected, DB::table('roles')->pluck('code_role')->all()));

        return $missing === []
            ? $this->result('Lima role tersedia', self::PASS, implode(', ', $expected))
            : $this->result('Lima role tersedia', self::FAIL, 'Tidak ada: '.implode(', ', $missing).'. Jalankan RoleSeeder.');
    }

    private function permissions(): array
    {
        $codes = [
            Permission::TASK_VIEW_ALL, Permission::TASK_CREATE, Permission::TASK_UPDATE, Permission::TASK_DELETE,
            Permission::TASK_ASSIGN, Permission::TASK_REVIEW, Permission::TASK_CHANGE_STATUS,
        ];
        $missing = array_values(array_diff($codes, DB::table('permissions')->pluck('code')->all()));

        if ($missing !== []) {
            return $this->result('Permission Tasklist tersedia', self::FAIL, 'Tidak ada: '.implode(', ', $missing).'. Jalankan PermissionSeeder.');
        }

        $rolesWithout = DB::table('roles')
            ->whereNotExists(fn ($query) => $query->from('role_permissions')->whereColumn('role_permissions.role_id', 'roles.id'))
            ->pluck('code_role');

        return $rolesWithout->isEmpty()
            ? $this->result('Permission Tasklist tersedia', self::PASS, count($codes).' permission, semua role punya permission')
            : $this->result('Permission Tasklist tersedia', self::FAIL, 'Role tanpa permission: '.$rolesWithout->implode(', ').'. Jalankan PermissionSeeder.');
    }

    private function noLegacyStatuses(): array
    {
        $count = DB::table('tasks')->whereIn('status', self::LEGACY_STATUSES)->count();

        return $this->countResult('Tidak ada status QA lama di tasks', $count, 'task masih berstatus ready_for_qa/on_qa');
    }

    private function noUnknownStatuses(): array
    {
        $count = DB::table('tasks')->whereNotIn('status', array_column(TaskStatus::cases(), 'value'))->count();

        return $this->countResult('Semua status task dikenali', $count, 'task dengan status di luar enam status');
    }

    private function noLegacyStatusesInHistory(): array
    {
        $count = 0;

        DB::table('task_activities')
            ->where(function ($query) {
                foreach (self::LEGACY_STATUSES as $status) {
                    $query->orWhere('changes', 'like', '%"'.$status.'"%');
                }
            })
            ->orderBy('id')
            ->chunkById(500, function ($activities) use (&$count) {
                foreach ($activities as $activity) {
                    $status = json_decode($activity->changes, true)['status'] ?? null;

                    if (is_array($status) && array_intersect(self::LEGACY_STATUSES, [$status['old'] ?? null, $status['new'] ?? null]) !== []) {
                        $count++;
                    }
                }
            });

        return $this->countResult('Histori status memakai nama baru', $count, 'histori status masih memakai ready_for_qa/on_qa');
    }

    private function picIdBackfilled(): array
    {
        $count = DB::table('tasks')
            ->whereNotNull('pic_id')
            ->whereNotExists(fn ($query) => $query->from('task_assignments')->whereColumn('task_assignments.task_id', 'tasks.id'))
            ->count();

        return $this->countResult('PIC lama (pic_id) sudah di-backfill', $count, 'task punya pic_id tanpa assignment. Jalankan tasks:backfill-assignments --apply.');
    }

    private function noDuplicateActivePics(): array
    {
        $count = DB::table('task_assignments')
            ->whereNull('unassigned_at')
            ->select('task_id', 'account_id')
            ->groupBy('task_id', 'account_id')
            ->havingRaw('count(*) > 1')
            ->get()
            ->count();

        return $this->countResult('Tidak ada PIC aktif ganda', $count, 'pasangan task/akun dengan lebih dari satu assignment aktif');
    }

    private function activeTasksHavePic(): array
    {
        $count = DB::table('tasks')
            ->whereNull('deleted_at')
            ->where('status', '<>', TaskStatus::Backlog->value)
            ->whereNotExists(fn ($query) => $query->from('task_assignments')
                ->whereColumn('task_assignments.task_id', 'tasks.id')
                ->whereNull('task_assignments.unassigned_at'))
            ->count();

        return $this->countResult('Task di luar Back Log punya PIC aktif', $count, 'task di luar Back Log tanpa PIC aktif');
    }

    /**
     * Older data may have PICs that no longer meet today's rules (inactive, or a role that cannot be PIC). They stay
     * valid history, so this only warns.
     */
    private function activePicsAreEligible(): array
    {
        $count = DB::table('task_assignments')
            ->join('accounts', 'accounts.id', '=', 'task_assignments.account_id')
            ->join('roles', 'roles.id', '=', 'accounts.role_id')
            ->whereNull('task_assignments.unassigned_at')
            ->where(fn ($query) => $query->where('accounts.is_active', false)->orWhereNotIn('roles.code_role', Role::PIC_ROLES))
            ->count();

        return $count === 0
            ? $this->result('PIC aktif memenuhi syarat', self::PASS, 'Semua PIC aktif berstatus aktif dengan role PIC')
            : $this->result('PIC aktif memenuhi syarat', self::WARN, "{$count} PIC aktif nonaktif atau bukan role Staff/Freelance/Supervisor");
    }

    private function noOrphanedHistory(): array
    {
        $orphans = collect(['task_activities', 'task_evidences', 'task_reviews', 'task_assignments', 'task_comments', 'task_divisions'])
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)
                ->whereNotExists(fn ($query) => $query->from('tasks')->whereColumn('tasks.id', "{$table}.task_id"))
                ->count()])
            ->filter();

        return $orphans->isEmpty()
            ? $this->result('Tidak ada data riwayat yatim', self::PASS, 'Semua baris terhubung ke task')
            : $this->result('Tidak ada data riwayat yatim', self::FAIL, $orphans->map(fn (int $count, string $table) => "{$table}: {$count}")->implode(', '));
    }

    private function repliesStayInTheirTask(): array
    {
        $count = DB::table('task_comments as reply')
            ->join('task_comments as parent', 'parent.id', '=', 'reply.parent_comment_id')
            ->whereColumn('parent.task_id', '<>', 'reply.task_id')
            ->count();

        return $this->countResult('Balasan komentar berada di task yang sama', $count, 'balasan yang induknya berasal dari task lain');
    }

    private function ownerIsNotCollaborator(): array
    {
        $count = DB::table('task_divisions')
            ->join('tasks', 'tasks.id', '=', 'task_divisions.task_id')
            ->whereColumn('tasks.division_id', 'task_divisions.division_id')
            ->count();

        return $this->countResult('Divisi utama tidak tercatat sebagai kolaborator', $count, 'task yang divisi utamanya juga tercatat di task_divisions');
    }

    private function historyForeignKeysRestrict(): array
    {
        $check = 'Foreign key riwayat memakai RESTRICT';

        if (DB::getDriverName() !== 'mysql') {
            return $this->result($check, self::SKIP, 'Hanya diperiksa di MySQL');
        }

        $rules = DB::table('information_schema.REFERENTIAL_CONSTRAINTS as rc')
            ->join('information_schema.KEY_COLUMN_USAGE as k', function ($join) {
                $join->on('k.CONSTRAINT_SCHEMA', '=', 'rc.CONSTRAINT_SCHEMA')
                    ->on('k.CONSTRAINT_NAME', '=', 'rc.CONSTRAINT_NAME')
                    ->on('k.TABLE_NAME', '=', 'rc.TABLE_NAME');
            })
            ->where('rc.CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->whereIn('rc.TABLE_NAME', array_keys(self::RESTRICTED_FOREIGN_KEYS))
            ->get(['rc.TABLE_NAME as table_name', 'k.COLUMN_NAME as column_name', 'rc.DELETE_RULE as delete_rule'])
            ->mapWithKeys(fn (object $row) => ["{$row->table_name}.{$row->column_name}" => $row->delete_rule]);

        $wrong = collect(self::RESTRICTED_FOREIGN_KEYS)
            ->flatMap(fn (array $columns, string $table) => array_map(fn (string $column) => "{$table}.{$column}", $columns))
            ->reject(fn (string $key) => in_array($rules[$key] ?? null, ['RESTRICT', 'NO ACTION'], true))
            ->map(fn (string $key) => "{$key} (".($rules[$key] ?? 'tidak ada').')');

        return $wrong->isEmpty()
            ? $this->result($check, self::PASS, 'task_id, account_id, author_id, parent_comment_id')
            : $this->result($check, self::FAIL, $wrong->implode(', '));
    }

    private function countResult(string $check, int $count, string $problem): array
    {
        return $count === 0
            ? $this->result($check, self::PASS, '0')
            : $this->result($check, self::FAIL, "{$count} {$problem}");
    }

    /**
     * @return array{check: string, status: string, detail: string}
     */
    private function result(string $check, string $status, string $detail): array
    {
        return ['check' => $check, 'status' => $status, 'detail' => $detail];
    }
}
