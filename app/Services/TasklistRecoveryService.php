<?php

namespace App\Services;

use App\Support\LegacyAgendaMapper;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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
 * Both are dry runs unless $apply is true, and both are safe to run again. Agendas keep the same id in both
 * tables; LegacyAgendaMapper maps division_id to the old shared and internal agendas.
 */
class TasklistRecoveryService
{
    /**
     * The agendas toLegacy() last wrote to schedule_events, so fromLegacy() can tell an agenda the old application
     * deleted from one it never had.
     */
    public const LEGACY_AGENDAS_CACHE_KEY = 'tasklist.recovery.legacy_agenda_ids';

    public function __construct(private readonly LegacyAgendaMapper $agendas) {}

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
        $result = $this->run($apply, fn () => [
            'tasks.pic_id diisi PIC aktif terbaru' => $this->writeLegacyPicIds(),
            'Status task ke nama lama' => $this->renameStatuses(self::LEGACY_STATUSES),
            'Agenda disalin ke schedule_events' => $this->agendasToLegacy(),
            'Task terhapus yang akan terlihat lagi di aplikasi lama' => DB::table('tasks')->whereNotNull('deleted_at')->count(),
        ]);

        if ($apply) {
            Cache::forever(self::LEGACY_AGENDAS_CACHE_KEY, DB::table('calendar_events')->whereNull('deleted_at')->pluck('id')->map(fn ($id) => (int) $id)->all());
        }

        return $result;
    }

    /**
     * Take back what the pre-TASK-19 application changed, before running the TASK-19 application again.
     *
     * @return array<string, int>
     */
    public function fromLegacy(bool $apply): array
    {
        $result = $this->run($apply, fn () => [
            'Status task ke nama baru' => $this->renameStatuses(array_flip(self::LEGACY_STATUSES)),
            'PIC diambil dari tasks.pic_id' => $this->readLegacyPicIds(),
            'Agenda disalin dari schedule_events' => $this->agendasFromLegacy(),
        ]);

        if ($apply) {
            Cache::forget(self::LEGACY_AGENDAS_CACHE_KEY);
        }

        return $result;
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
        $originals = DB::table('schedule_events')->get(['id', 'created_at', 'updated_at'])->keyBy('id');

        return DB::table('calendar_events')->get(['id', 'created_at', 'updated_at', 'deleted_at'])
            ->filter(fn (object $event) => $event->deleted_at !== null
                || ! $originals->has($event->id)
                || (string) $originals[$event->id]->created_at !== (string) $event->created_at
                || (string) $originals[$event->id]->updated_at !== (string) $event->updated_at)
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
     * Write the latest agendas into schedule_events under the same id: new ones are added, edited ones updated, and
     * deleted ones removed, since the old table has no soft delete. An unchanged agenda keeps its original row,
     * including its related task and target divisions.
     */
    private function agendasToLegacy(): int
    {
        $synced = 0;

        foreach (DB::table('calendar_events')->orderBy('id')->get() as $event) {
            $legacy = DB::table('schedule_events')->where('id', $event->id)->first();

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

            $row = $this->agendas->toLegacyRow($event);
            $legacy !== null
                ? DB::table('schedule_events')->where('id', $event->id)->update($row)
                : DB::table('schedule_events')->insert(['id' => $event->id, ...$row]);

            DB::table('schedule_event_divisions')->where('schedule_event_id', $event->id)->delete();
            DB::table('schedule_event_divisions')->insert(array_map(
                fn (int $divisionId) => ['schedule_event_id' => $event->id, 'division_id' => $divisionId],
                $this->agendas->legacyTargetsFor($event),
            ));
            $synced++;
        }

        return $synced;
    }

    /**
     * Take back agendas from schedule_events: missing ones are added, ones older than their original updated, and
     * those the old application deleted after toLegacy() soft-deleted. An old agenda whose id already belongs to a
     * different agenda (another created_at) is added under a new id.
     */
    private function agendasFromLegacy(): int
    {
        $synced = 0;
        $legacyAgendas = DB::table('schedule_events')->orderBy('id')->get();
        $rows = $this->agendas->toCalendarRows($legacyAgendas);

        foreach ($legacyAgendas as $legacy) {
            $event = DB::table('calendar_events')->where('id', $legacy->id)->first();
            $row = $rows[$legacy->id];

            if ($event === null) {
                DB::table('calendar_events')->insert($row);
            } elseif ((string) $event->created_at !== (string) $legacy->created_at) {
                $copy = array_diff_key($row, ['id' => true]);

                if (DB::table('calendar_events')->where('title', $copy['title'])->where('created_at', $copy['created_at'])->exists()) {
                    continue;
                }

                DB::table('calendar_events')->insert($copy);
            } elseif ((string) $event->updated_at < (string) $legacy->updated_at) {
                DB::table('calendar_events')->where('id', $event->id)->update([...$row, 'deleted_at' => null]);
            } else {
                continue;
            }

            $synced++;
        }

        $synced += DB::table('calendar_events')
            ->whereNull('deleted_at')
            ->whereIn('id', Cache::get(self::LEGACY_AGENDAS_CACHE_KEY, []))
            ->whereNotIn('id', $legacyAgendas->pluck('id'))
            ->update(['deleted_at' => Carbon::now()]);

        return $synced;
    }
}
