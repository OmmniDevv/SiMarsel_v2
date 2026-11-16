<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Recovery of the TASK-19 Tasklist changes once the application has written data the old schema cannot hold.
 *
 * Before any new data exists, `migrate:rollback` is safe: everything it removes can be rebuilt from tasks.pic_id and
 * schedule_events. After users add PICs, comments, collaborating divisions, or agendas, a schema rollback would
 * lose them, so recovery keeps every table and moves the latest data between the new and the old structure instead:
 *
 * - toLegacy(): before running the pre-TASK-19 application again. Writes the latest PIC into tasks.pic_id, the old
 *   status names, and the latest agendas into schedule_events. The new tables stay as they are.
 * - fromLegacy(): before running the TASK-19 application again. Takes back what the old application changed:
 *   status names, a PIC changed through tasks.pic_id, and agendas in schedule_events.
 *
 * Both are dry runs unless $apply is true, and both are safe to run again.
 */
class TasklistRecoveryService
{
    public const FIRST_MIGRATION = '2026_11_13_083909_widen_name_columns_on_roles_and_divisions_tables';

    public const LEGACY_STATUSES = [
        'ready_review' => 'ready_for_qa',
        'on_review' => 'on_qa',
    ];

    /**
     * Data a schema rollback would lose or change, per kind. All zero means rolling back is safe.
     *
     * @return array<string, int>
     */
    public function newDataReport(): array
    {
        return [
            'PIC yang tidak tercatat di tasks.pic_id (multi-PIC, PIC dilepas, task baru)' => $this->assignmentsBeyondPicId(),
            'Komentar tugas' => DB::table('task_comments')->count(),
            'Divisi kolaborator' => DB::table('task_divisions')->count(),
            'Agenda baru, diubah, atau dihapus setelah dipindah' => $this->agendasChangedSinceMove(),
            'Task yang dihapus (soft delete)' => DB::table('tasks')->whereNotNull('deleted_at')->count(),
            'Evidence yang dihapus (soft delete)' => DB::table('task_evidences')->whereNotNull('deleted_at')->count(),
            'Akun nonaktif' => DB::table('accounts')->where('is_active', false)->count(),
        ];
    }

    /**
     * Number of migrations `migrate:rollback --step` must undo to return to the schema before TASK-19, including
     * any migration applied after it.
     */
    public function rollbackSteps(): int
    {
        return DB::table('migrations')->where('migration', '>=', self::FIRST_MIGRATION)->count();
    }

    public function hasNewData(): bool
    {
        return array_sum($this->newDataReport()) > 0;
    }

    /**
     * Prepare the database for the pre-TASK-19 application without dropping anything.
     *
     * @return array<string, int>
     */
    public function toLegacy(bool $apply): array
    {
        return $this->run($apply, fn () => [
            'tasks.pic_id diisi PIC aktif terbaru' => $this->writeLegacyPicIds(),
            'Status task ke nama lama' => $this->renameStatuses(self::LEGACY_STATUSES),
            'Agenda disalin ke schedule_events' => $this->agendasToLegacy(),
            'Task terhapus yang akan terlihat lagi di aplikasi lama' => DB::table('tasks')->whereNotNull('deleted_at')->count(),
        ]);
    }

    /**
     * Take back what the pre-TASK-19 application changed, before running the TASK-19 application again.
     *
     * @return array<string, int>
     */
    public function fromLegacy(bool $apply): array
    {
        return $this->run($apply, fn () => [
            'Status task ke nama baru' => $this->renameStatuses(array_flip(self::LEGACY_STATUSES)),
            'PIC diambil dari tasks.pic_id' => $this->readLegacyPicIds(),
            'Agenda disalin dari schedule_events' => $this->agendasFromLegacy(),
        ]);
    }

    /**
     * Run the steps in one transaction and roll it back on a dry run, so the counts are exact either way.
     *
     * @param  callable(): array<string, int>  $steps
     * @return array<string, int>
     */
    private function run(bool $apply, callable $steps): array
    {
        DB::beginTransaction();

        try {
            $result = $steps();
        } catch (\Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        $apply ? DB::commit() : DB::rollBack();

        return $result;
    }

    private function assignmentsBeyondPicId(): int
    {
        return DB::table('task_assignments')
            ->join('tasks', 'tasks.id', '=', 'task_assignments.task_id')
            ->where(fn ($query) => $query
                ->whereNotNull('task_assignments.unassigned_at')
                ->orWhereNull('tasks.pic_id')
                ->orWhereColumn('task_assignments.account_id', '!=', 'tasks.pic_id'))
            ->count();
    }

    private function agendasChangedSinceMove(): int
    {
        $originals = DB::table('schedule_events')->pluck('updated_at', 'id');

        return DB::table('calendar_events')->get(['legacy_schedule_event_id', 'updated_at', 'deleted_at'])
            ->filter(fn (object $event) => $event->legacy_schedule_event_id === null
                || $event->deleted_at !== null
                || ! $originals->has($event->legacy_schedule_event_id)
                || (string) $originals[$event->legacy_schedule_event_id] !== (string) $event->updated_at)
            ->count();
    }

    /**
     * The old application has one PIC per task: the earliest active one, or none.
     */
    private function writeLegacyPicIds(): int
    {
        $firstPics = DB::table('task_assignments')
            ->whereNull('unassigned_at')
            ->orderBy('assigned_at')
            ->orderBy('id')
            ->get(['task_id', 'account_id'])
            ->unique('task_id')
            ->pluck('account_id', 'task_id');

        $changed = 0;

        DB::table('tasks')->orderBy('id')->chunkById(500, function ($tasks) use ($firstPics, &$changed) {
            foreach ($tasks as $task) {
                $picId = $firstPics[$task->id] ?? null;

                if ($task->pic_id !== $picId && (int) $task->pic_id !== (int) $picId) {
                    DB::table('tasks')->where('id', $task->id)->update(['pic_id' => $picId]);
                    $changed++;
                }
            }
        });

        return $changed;
    }

    /**
     * A PIC set through tasks.pic_id by the old application wins: the task's other active PICs are released and
     * that account becomes its PIC. Tasks whose pic_id is already an active PIC are left alone.
     */
    private function readLegacyPicIds(): int
    {
        $now = Carbon::now();
        $changed = 0;

        DB::table('tasks')->whereNotNull('pic_id')->orderBy('id')->chunkById(500, function ($tasks) use ($now, &$changed) {
            foreach ($tasks as $task) {
                $active = DB::table('task_assignments')->where('task_id', $task->id)->whereNull('unassigned_at');

                if ((clone $active)->where('account_id', $task->pic_id)->exists()) {
                    continue;
                }

                $active->update(['unassigned_at' => $now, 'note' => 'Dilepas saat pemulihan: PIC diganti di aplikasi lama.']);
                DB::table('task_assignments')->insert([
                    'task_id' => $task->id,
                    'account_id' => $task->pic_id,
                    'assigned_by' => null,
                    'assigned_at' => $now,
                    'note' => 'Diambil dari tasks.pic_id saat pemulihan.',
                ]);
                $changed++;
            }
        });

        return $changed;
    }

    /**
     * Rename statuses in tasks and in the "status" change of task history; returns the number of tasks renamed.
     *
     * @param  array<string, string>  $map
     */
    private function renameStatuses(array $map): int
    {
        $renamed = 0;

        foreach ($map as $from => $to) {
            $renamed += DB::table('tasks')->where('status', $from)->update(['status' => $to]);
        }

        DB::table('task_activities')
            ->whereNotNull('changes')
            ->where(function ($query) use ($map) {
                foreach (array_keys($map) as $from) {
                    $query->orWhere('changes', 'like', '%"'.$from.'"%');
                }
            })
            ->orderBy('id')
            ->chunkById(500, function ($activities) use ($map) {
                foreach ($activities as $activity) {
                    $changes = json_decode($activity->changes, true);

                    if (! is_array($changes) || ! isset($changes['status'])) {
                        continue;
                    }

                    $status = $this->replaceValues($changes['status'], $map);

                    if ($status !== $changes['status']) {
                        DB::table('task_activities')->where('id', $activity->id)->update(['changes' => json_encode([...$changes, 'status' => $status])]);
                    }
                }
            });

        return $renamed;
    }

    private function replaceValues(mixed $value, array $map): mixed
    {
        if (is_array($value)) {
            return array_map(fn (mixed $item) => $this->replaceValues($item, $map), $value);
        }

        return is_string($value) && isset($map[$value]) ? $map[$value] : $value;
    }

    /**
     * Write the latest agendas into schedule_events: new ones are added and linked, edited ones updated, and deleted
     * ones removed, since the old table has no soft delete.
     */
    private function agendasToLegacy(): int
    {
        $synced = 0;

        foreach (DB::table('calendar_events')->orderBy('id')->get() as $event) {
            $legacy = $event->legacy_schedule_event_id === null ? null
                : DB::table('schedule_events')->where('id', $event->legacy_schedule_event_id)->first();

            if ($event->deleted_at !== null) {
                if ($legacy !== null) {
                    DB::table('schedule_events')->where('id', $legacy->id)->delete();
                    $synced++;
                }

                continue;
            }

            if ($legacy !== null && (string) $legacy->updated_at === (string) $event->updated_at) {
                continue;
            }

            $row = [
                'title' => $event->title,
                'description' => $event->description,
                'owner_division_id' => $event->division_id,
                'start_date' => substr((string) $event->start_at, 0, 10),
                'end_date' => $event->end_at === null ? null : substr((string) $event->end_at, 0, 10),
                'is_shared' => (bool) $event->is_shared,
                'task_id' => $event->task_id,
                'creator_id' => $event->creator_id,
                'created_at' => $event->created_at,
                'updated_at' => $event->updated_at,
            ];

            if ($legacy !== null) {
                DB::table('schedule_events')->where('id', $legacy->id)->update($row);
                $legacyId = $legacy->id;
            } else {
                $legacyId = DB::table('schedule_events')->insertGetId($row);
                DB::table('calendar_events')->where('id', $event->id)->update(['legacy_schedule_event_id' => $legacyId]);
            }

            $this->copyTargets('calendar_event_divisions', 'calendar_event_id', $event->id, 'schedule_event_divisions', 'schedule_event_id', $legacyId);
            $synced++;
        }

        return $synced;
    }

    /**
     * Take back agendas from schedule_events: missing copies are added, copies older than their original are
     * updated, and copies whose original was deleted are soft-deleted.
     */
    private function agendasFromLegacy(): int
    {
        $synced = 0;

        foreach (DB::table('schedule_events')->orderBy('id')->get() as $legacy) {
            $event = DB::table('calendar_events')->where('legacy_schedule_event_id', $legacy->id)->first();

            if ($event !== null && (string) $event->updated_at >= (string) $legacy->updated_at) {
                continue;
            }

            $row = [
                'division_id' => $legacy->owner_division_id,
                'creator_id' => $legacy->creator_id,
                'title' => $legacy->title,
                'description' => $legacy->description,
                'is_shared' => (bool) $legacy->is_shared,
                'task_id' => $legacy->task_id,
                'start_at' => substr((string) $legacy->start_date, 0, 10).' 00:00:00',
                'end_at' => $legacy->end_date === null ? null : substr((string) $legacy->end_date, 0, 10).' 00:00:00',
                'created_at' => $legacy->created_at,
                'updated_at' => $legacy->updated_at,
            ];

            if ($event !== null) {
                DB::table('calendar_events')->where('id', $event->id)->update([...$row, 'deleted_at' => null]);
                $eventId = $event->id;
            } else {
                $eventId = DB::table('calendar_events')->insertGetId([...$row, 'legacy_schedule_event_id' => $legacy->id]);
            }

            $this->copyTargets('schedule_event_divisions', 'schedule_event_id', $legacy->id, 'calendar_event_divisions', 'calendar_event_id', $eventId);
            $synced++;
        }

        $synced += DB::table('calendar_events')
            ->whereNull('deleted_at')
            ->whereNotNull('legacy_schedule_event_id')
            ->whereNotExists(fn ($query) => $query->from('schedule_events')->whereColumn('schedule_events.id', 'calendar_events.legacy_schedule_event_id'))
            ->update(['deleted_at' => Carbon::now()]);

        return $synced;
    }

    private function copyTargets(string $fromTable, string $fromKey, int $fromId, string $toTable, string $toKey, int $toId): void
    {
        DB::table($toTable)->where($toKey, $toId)->delete();
        DB::table($toTable)->insert(
            DB::table($fromTable)->where($fromKey, $fromId)->pluck('division_id')
                ->map(fn ($divisionId) => [$toKey => $toId, 'division_id' => $divisionId])
                ->all(),
        );
    }
}
