<?php

namespace App\Support;

use App\Exceptions\UnmappableAgendaException;
use App\Models\Role;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Maps agendas between schedule_events (before TASK-19) and calendar_events (as in the ERD). Both tables share
 * the agenda id, so an agenda keeps its id in either direction.
 *
 * calendar_events has one division_id: NULL is a company-wide agenda for everyone, a division makes it internal
 * to that division. A shared agenda becomes company-wide; an internal one keeps its organising division, else its
 * first target division, else its creator's division. calendar_events requires a creator, so an agenda without
 * one is credited to the first Admin account.
 */
class LegacyAgendaMapper
{
    private ?int $fallbackCreatorId = null;

    private bool $fallbackCreatorLoaded = false;

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
            $divisionId = $this->divisionFor($agenda);
            $creatorId = $agenda->creator_id ?? $this->fallbackCreatorId();

            if ($divisionId === false) {
                $problems[$agenda->id] = "\"{$agenda->title}\" agenda internal tanpa divisi";
            } elseif ($creatorId === null) {
                $problems[$agenda->id] = "\"{$agenda->title}\" tanpa pembuat dan tidak ada akun Admin";
            } else {
                $rows[$agenda->id] = [
                    'id' => (int) $agenda->id,
                    'division_id' => $divisionId,
                    'creator_id' => (int) $creatorId,
                    'title' => $agenda->title,
                    'description' => $agenda->description,
                    'start_at' => substr((string) $agenda->start_date, 0, 10).' 00:00:00',
                    'end_at' => $agenda->end_date === null ? null : substr((string) $agenda->end_date, 0, 10).' 00:00:00',
                    'created_at' => $agenda->created_at,
                    'updated_at' => $agenda->updated_at,
                ];
            }
        }

        if ($problems !== []) {
            throw UnmappableAgendaException::forAgendas($problems);
        }

        return $rows;
    }

    /**
     * Internal agendas meant for a division other than the one they keep: those divisions no longer see them.
     *
     * @return Collection<int, int> The schedule_events ids.
     */
    public function narrowedAgendaIds(): Collection
    {
        return DB::table('schedule_events')
            ->where('is_shared', false)
            ->orderBy('id')
            ->get()
            ->filter(function (object $agenda) {
                $divisionId = $this->divisionFor($agenda);

                return $this->targetsOf($agenda->id)->contains(fn (int $target) => $target !== $divisionId);
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values();
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
            'is_shared' => $event->division_id === null,
            'creator_id' => $event->creator_id,
            'created_at' => $event->created_at,
            'updated_at' => $event->updated_at,
        ];
    }

    /**
     * The target divisions an agenda gets in schedule_events: none when company-wide, else its division.
     *
     * @return array<int, int>
     */
    public function legacyTargetsFor(object $event): array
    {
        return $event->division_id === null ? [] : [(int) $event->division_id];
    }

    /**
     * @return int|false|null A division id, null for company-wide, or false when an internal agenda has none.
     */
    private function divisionFor(object $agenda): int|false|null
    {
        if ($agenda->is_shared) {
            return null;
        }

        $divisionId = $agenda->owner_division_id
            ?? $this->targetsOf($agenda->id)->first()
            ?? ($agenda->creator_id === null ? null : DB::table('accounts')->where('id', $agenda->creator_id)->value('division_id'));

        return $divisionId === null ? false : (int) $divisionId;
    }

    /**
     * @return Collection<int, int>
     */
    private function targetsOf(int $agendaId): Collection
    {
        return DB::table('schedule_event_divisions')->where('schedule_event_id', $agendaId)
            ->orderBy('division_id')->pluck('division_id')->map(fn ($id) => (int) $id);
    }

    private function fallbackCreatorId(): ?int
    {
        if (! $this->fallbackCreatorLoaded) {
            $id = DB::table('accounts')
                ->join('roles', 'roles.id', '=', 'accounts.role_id')
                ->where('roles.code_role', Role::ADMIN)
                ->orderBy('accounts.id')
                ->value('accounts.id');
            $this->fallbackCreatorId = $id === null ? null : (int) $id;
            $this->fallbackCreatorLoaded = true;
        }

        return $this->fallbackCreatorId;
    }
}
