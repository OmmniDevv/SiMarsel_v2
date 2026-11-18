<?php

use App\Support\LegacyAgendaMapper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * calendar_events becomes the only source of agendas. Every schedule_events agenda is copied with its id, dates,
 * timestamps, is_shared, organising division, and all of its target divisions (see LegacyAgendaMapper). Nothing is
 * copied when an agenda cannot be mapped. schedule_events is no longer read or written by the application but
 * stays in place, untouched, until the copy has been verified in production.
 */
return new class extends Migration
{
    public function up(): void
    {
        $mapper = new LegacyAgendaMapper;
        $existing = DB::table('calendar_events')->pluck('id')->map(fn ($id) => (int) $id)->flip();
        $rows = $mapper
            ->toCalendarRows(DB::table('schedule_events')->orderBy('id')->get())
            ->reject(fn (array $row) => $existing->has($row['id']));

        DB::transaction(function () use ($rows, $mapper) {
            foreach ($rows->chunk(500) as $chunk) {
                DB::table('calendar_events')->insert($chunk->values()->all());
            }

            foreach ($rows->keys() as $id) {
                $mapper->syncTargets($id, $mapper->legacyTargets($id));
            }
        });
    }

    public function down(): void
    {
        // Only copies are removed (their target divisions cascade); the originals are still in schedule_events.
        // Agendas created or changed after the move are protected by the rollback guard (2026_11_16_090100), which
        // refuses the rollback while they exist.
        DB::table('calendar_events')
            ->whereIn('id', DB::table('schedule_events')->select('id'))
            ->delete();
    }
};
