<?php

namespace App\Support;

use App\Exceptions\UnmappableAgendaException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Maps agendas between schedule_events (before TASK-19) and calendar_events. Both tables share the agenda id, so an
 * agenda keeps its id in either direction, and nothing is lost on the way: is_shared, the organising division, and
 * every target division are kept as they are.
 *
 * calendar_events requires a creator. An agenda whose creator is unknown is refused rather than credited to
 * someone else.
 */
class LegacyAgendaMapper
{
    /**
     * calendar_events rows for the given schedule_events rows, keyed by agenda id.
     *
     * @param  iterable<object>  $agendas
     * @return Collection<int, array<string, mixed>>
     *
     * @throws UnmappableAgendaException
     */
    public function toCalendarRows(iterable $agendas): Collection
    {
        $rows = collect();
        $problems = [];

        foreach ($agendas as $agenda) {
            if ($agenda->creator_id === null) {
                $problems[$agenda->id] = "\"{$agenda->title}\" tanpa pembuat; isi schedule_events.creator_id dengan pembuat aslinya";

                continue;
            }

            $rows[$agenda->id] = [
                'id' => (int) $agenda->id,
                'division_id' => $agenda->owner_division_id === null ? null : (int) $agenda->owner_division_id,
                'creator_id' => (int) $agenda->creator_id,
                'title' => $agenda->title,
                'description' => $agenda->description,
                'is_shared' => (bool) $agenda->is_shared,
                'start_at' => substr((string) $agenda->start_date, 0, 10).' 00:00:00',
                'end_at' => $agenda->end_date === null ? null : substr((string) $agenda->end_date, 0, 10).' 00:00:00',
                'created_at' => $agenda->created_at,
                'updated_at' => $agenda->updated_at,
            ];
        }

        if ($problems !== []) {
            throw UnmappableAgendaException::forAgendas($problems);
        }

        return $rows;
    }

    /**
     * The target divisions of an old agenda.
     *
     * @return array<int, int>
     */
    public function legacyTargets(int $scheduleEventId): array
    {
        return DB::table('schedule_event_divisions')->where('schedule_event_id', $scheduleEventId)
            ->orderBy('division_id')->pluck('division_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * The target divisions of an agenda.
     *
     * @return array<int, int>
     */
    public function targets(int $calendarEventId): array
    {
        return DB::table('calendar_event_divisions')->where('calendar_event_id', $calendarEventId)
            ->orderBy('division_id')->pluck('division_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Replace the target divisions of an agenda.
     *
     * @param  array<int, int>  $divisionIds
     */
    public function syncTargets(int $calendarEventId, array $divisionIds): void
    {
        DB::table('calendar_event_divisions')->where('calendar_event_id', $calendarEventId)->delete();
        DB::table('calendar_event_divisions')->insert(array_map(
            fn (int $divisionId) => ['calendar_event_id' => $calendarEventId, 'division_id' => $divisionId],
            $divisionIds,
        ));
    }

    /**
     * Replace the target divisions of an old agenda.
     *
     * @param  array<int, int>  $divisionIds
     */
    public function syncLegacyTargets(int $scheduleEventId, array $divisionIds): void
    {
        DB::table('schedule_event_divisions')->where('schedule_event_id', $scheduleEventId)->delete();
        DB::table('schedule_event_divisions')->insert(array_map(
            fn (int $divisionId) => ['schedule_event_id' => $scheduleEventId, 'division_id' => $divisionId],
            $divisionIds,
        ));
    }

    /**
     * The schedule_events row for an agenda. calendar_events has no related task, so an existing row keeps its own.
     *
     * @return array<string, mixed>
     */
    public function toLegacyRow(object $event): array
    {
        return [
            'title' => $event->title,
            'description' => $event->description,
            'owner_division_id' => $event->division_id,
            'start_date' => substr((string) $event->start_at, 0, 10),
            'end_date' => $event->end_at === null ? null : substr((string) $event->end_at, 0, 10),
            'is_shared' => (bool) $event->is_shared,
            'creator_id' => $event->creator_id,
            'created_at' => $event->created_at,
            'updated_at' => $event->updated_at,
        ];
    }
}
