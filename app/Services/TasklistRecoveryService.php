<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Reports data that would be lost by rolling Tasklist back to its former schema. */
class TasklistRecoveryService
{
    public const FIRST_MIGRATION = '2026_11_13_083909_widen_name_columns_on_roles_and_divisions_tables';

    /**
     * @return array<string, int>
     */
    public function newDataReport(): array
    {
        return [
            'Riwayat penugasan PIC' => DB::table('task_assignments')->count(),
            'Komentar tugas' => DB::table('task_comments')->count(),
            'Divisi kolaborator' => DB::table('task_divisions')->count(),
            'Agenda kalender' => DB::table('calendar_events')->count(),
            'Task yang dihapus (soft delete)' => DB::table('tasks')->whereNotNull('deleted_at')->count(),
            'Evidence yang dihapus (soft delete)' => DB::table('task_evidences')->whereNotNull('deleted_at')->count(),
            'Akun nonaktif' => DB::table('accounts')->where('is_active', false)->count(),
        ];
    }

    public function rollbackSteps(): int
    {
        return DB::table('migrations')->where('migration', '>=', self::FIRST_MIGRATION)->count();
    }

    public function hasNewData(): bool
    {
        return array_sum($this->newDataReport()) > 0;
    }
}
