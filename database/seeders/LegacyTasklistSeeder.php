<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Dummy Tasklist data shaped like the database before TASK-19: one PIC per task in tasks.pic_id, the old QA status
 * names, three roles, and history, evidence, reviews, and an agenda written the old way. Only columns that existed
 * before TASK-19 are filled, so it can be seeded before the TASK-19 migrations run to validate them.
 *
 * Not part of DatabaseSeeder. Run it on a scratch database only:
 *   php artisan migrate:rollback --step=13   (back to the pre-TASK-19 schema)
 *   php artisan db:seed --class=LegacyTasklistSeeder
 *   php artisan migrate
 */
class LegacyTasklistSeeder extends Seeder
{
    public function run(): void
    {
        $now = '2026-11-10 09:00:00';
        $roles = $this->roles($now);
        $marketing = $this->division('MKT', 'Marketing', $now);
        $finance = $this->division('FIN', 'Finance', $now);

        $admin = $this->account($roles['ADM'], null, 'Legacy Admin', 'legacy.admin', $now);
        $manager = $this->account($roles['MGR'], $marketing, 'Legacy Manager', 'legacy.manager', $now);
        $financeManager = $this->account($roles['MGR'], $finance, 'Legacy Finance Manager', 'legacy.finmanager', $now);
        $budi = $this->account($roles['EMP'], $marketing, 'Budi', 'legacy.budi', $now);
        $sari = $this->account($roles['EMP'], $marketing, 'Sari', 'legacy.sari', $now);
        $dewi = $this->account($roles['EMP'], $finance, 'Dewi', 'legacy.dewi', $now);

        $category = DB::table('task_categories')->insertGetId([
            'name' => 'Legacy Konten', 'requires_review' => true, 'evidence_required' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $task = fn (string $title, int $division, ?int $pic, string $status, array $extra = []) => DB::table('tasks')->insertGetId([
            'title' => $title,
            'description' => "Data lama: {$title}",
            'category_id' => $category,
            'division_id' => $division,
            'pic_id' => $pic,
            'creator_id' => $division === $finance ? $financeManager : $manager,
            'priority' => 'medium',
            'status' => $status,
            'start_date' => '2026-11-01',
            'deadline' => '2026-11-20',
            'requires_review' => true,
            'evidence_required' => true,
            'version' => 1,
            'created_at' => '2026-11-01 08:00:00',
            'updated_at' => $now,
            ...$extra,
        ]);

        $backlog = $task('Ide konten Desember', $marketing, null, 'backlog');
        $todo = $task('Desain banner promo', $marketing, $budi, 'todo');
        $inProgress = $task('Copywriting landing page', $marketing, $sari, 'in_progress', ['version' => 3]);
        $readyForQa = $task('Video teaser', $marketing, $budi, 'ready_for_qa', ['version' => 4]);
        $onQa = $task('Rekap budget iklan', $finance, $dewi, 'on_qa', ['version' => 6]);
        $done = $task('Laporan Oktober', $finance, $dewi, 'done', ['version' => 9, 'completed_at' => '2026-11-08 16:00:00']);

        $activity = fn (int $taskId, ?int $actor, string $action, ?array $changes, string $at, ?string $name = null) => DB::table('task_activities')->insert([
            'task_id' => $taskId, 'account_id' => $actor, 'actor_name_snapshot' => $name, 'action' => $action,
            'changes' => $changes === null ? null : json_encode($changes), 'created_at' => $at,
        ]);
        $status = fn (string $old, string $new) => ['status' => ['old' => $old, 'new' => $new]];
        $pic = fn (?int $id, ?string $name) => $id === null ? null : ['id' => $id, 'name' => $name];

        foreach ([$backlog, $todo, $inProgress, $readyForQa, $onQa, $done] as $taskId) {
            $activity($taskId, $taskId === $onQa || $taskId === $done ? $financeManager : $manager, 'created', [], '2026-11-01 08:00:00');
        }

        // Reassigned once: the backfill must take assigned_by and assigned_at from the latest (re)assignment.
        $activity($inProgress, $manager, 'assigned', ['pic' => ['old' => null, 'new' => $pic($budi, 'Budi')]], '2026-11-02 09:00:00');
        $activity($inProgress, $admin, 'reassigned', ['pic' => ['old' => $pic($budi, 'Budi'), 'new' => $pic($sari, 'Sari')]], '2026-11-03 10:00:00');
        $activity($inProgress, $sari, 'status_changed', $status('todo', 'in_progress'), '2026-11-04 09:00:00');

        $activity($readyForQa, $budi, 'status_changed', $status('todo', 'in_progress'), '2026-11-04 09:00:00');
        $activity($readyForQa, $budi, 'evidence_submitted', ['evidence' => ['old' => null, 'new' => ['name' => 'teaser.mp4']]], '2026-11-05 09:00:00');
        $activity($readyForQa, $budi, 'status_changed', $status('in_progress', 'ready_for_qa'), '2026-11-05 09:05:00');
        // A title that happens to read like a status is not a status and must not be renamed.
        $activity($readyForQa, $manager, 'updated', ['title' => ['old' => 'on_qa', 'new' => 'Video teaser']], '2026-11-05 10:00:00');

        $activity($onQa, $dewi, 'status_changed', $status('in_progress', 'ready_for_qa'), '2026-11-05 09:00:00');
        $activity($onQa, $financeManager, 'status_changed', $status('ready_for_qa', 'on_qa'), '2026-11-05 11:00:00');
        $activity($onQa, $financeManager, 'revision_requested', $status('on_qa', 'in_progress') + ['review' => ['old' => null, 'new' => ['decision' => 'revision', 'note' => 'Lengkapi lampiran.']]], '2026-11-06 09:00:00');
        $activity($onQa, $dewi, 'resubmitted', $status('in_progress', 'ready_for_qa'), '2026-11-07 09:00:00');
        $activity($onQa, $financeManager, 'status_changed', $status('ready_for_qa', 'on_qa'), '2026-11-07 10:00:00');

        $activity($done, $financeManager, 'approved', $status('on_qa', 'done'), '2026-11-08 16:00:00');
        // Written by an account that was deleted before deletion was blocked: only the snapshot name is left.
        $activity($done, null, 'updated', ['priority' => ['old' => 'low', 'new' => 'medium']], '2026-11-02 08:00:00', 'Mantan Staff');

        DB::table('task_evidences')->insert([
            ['task_id' => $readyForQa, 'submitter_id' => $budi, 'type' => 'file', 'file_path' => 'task-evidences/legacy/teaser.mp4', 'file_name' => 'teaser.mp4', 'url' => null, 'note' => null, 'created_at' => '2026-11-05 09:00:00', 'updated_at' => '2026-11-05 09:00:00'],
            ['task_id' => $onQa, 'submitter_id' => $dewi, 'type' => 'link', 'file_path' => null, 'file_name' => null, 'url' => 'https://drive.example.com/budget', 'note' => 'Versi 2', 'created_at' => '2026-11-07 08:00:00', 'updated_at' => '2026-11-07 08:00:00'],
            ['task_id' => $done, 'submitter_id' => $dewi, 'type' => 'link', 'file_path' => null, 'file_name' => null, 'url' => 'https://drive.example.com/oktober', 'note' => null, 'created_at' => '2026-11-07 08:00:00', 'updated_at' => '2026-11-07 08:00:00'],
        ]);

        DB::table('task_reviews')->insert([
            ['task_id' => $onQa, 'reviewer_id' => $financeManager, 'cycle' => 1, 'decision' => 'revision', 'note' => 'Lengkapi lampiran.', 'created_at' => '2026-11-06 09:00:00'],
            ['task_id' => $done, 'reviewer_id' => $financeManager, 'cycle' => 1, 'decision' => 'approve', 'note' => null, 'created_at' => '2026-11-08 16:00:00'],
        ]);

        $agenda = DB::table('schedule_events')->insertGetId([
            'title' => 'Rapat evaluasi bulanan', 'description' => 'Agenda bersama', 'owner_division_id' => $marketing,
            'start_date' => '2026-11-15', 'end_date' => null, 'is_shared' => true, 'task_id' => null, 'creator_id' => $admin,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('schedule_event_divisions')->insert(['schedule_event_id' => $agenda, 'division_id' => $finance]);
    }

    /**
     * The three roles that existed before TASK-19, kept if they are already there.
     *
     * @return array<string, int>
     */
    private function roles(string $now): array
    {
        foreach (['ADM' => 'Admin', 'MGR' => 'Manager', 'EMP' => 'Employee'] as $code => $name) {
            if (! DB::table('roles')->where('code_role', $code)->exists()) {
                DB::table('roles')->insert(['code_role' => $code, 'name' => $name, 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        return DB::table('roles')->pluck('id', 'code_role')->all();
    }

    private function division(string $code, string $name, string $now): int
    {
        return DB::table('divisions')->insertGetId(['code_division' => $code, 'name' => $name, 'created_at' => $now, 'updated_at' => $now]);
    }

    private function account(int $roleId, ?int $divisionId, string $fullname, string $username, string $now): int
    {
        return DB::table('accounts')->insertGetId([
            'role_id' => $roleId, 'division_id' => $divisionId, 'fullname' => $fullname, 'username' => $username,
            'password' => Hash::make('Password123!'), 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}
