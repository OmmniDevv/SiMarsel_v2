<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * calendar_events becomes the only source of agendas. It gains what schedule_events already stored (shared or
 * internal, the divisions an internal agenda is meant for, the related task, an optional creator) and every
 * agenda is copied with its id, dates, and timestamps. schedule_events is no longer read or written by the
 * application but stays in place, untouched, until the copy has been verified in production.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            // Agendas created before TASK-19 may have no recorded creator.
            $table->foreignId('creator_id')->nullable()->change();
            // Shared agendas are visible to everyone; internal ones to Admin and their target divisions only.
            $table->boolean('is_shared')->default(true)->after('description');
            $table->foreignId('task_id')->nullable()->after('is_shared')->constrained()->nullOnDelete();
            // The schedule_events row this agenda was copied from, so the copy can be verified and recovered.
            $table->unsignedBigInteger('legacy_schedule_event_id')->nullable()->unique()->after('task_id');
        });

        Schema::create('calendar_event_divisions', function (Blueprint $table) {
            $table->foreignId('calendar_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('division_id')->constrained()->cascadeOnDelete();

            $table->primary(['calendar_event_id', 'division_id']);
            $table->index('division_id');
        });

        DB::table('schedule_events')->orderBy('id')->chunkById(500, function ($agendas) {
            foreach ($agendas as $agenda) {
                $this->copy($agenda);
            }
        });
    }

    public function down(): void
    {
        // Only copies are removed; their originals are still in schedule_events. Agendas created after the move are
        // protected by the rollback guard (2026_11_16_090100), which refuses the rollback while they exist.
        DB::table('calendar_events')->whereNotNull('legacy_schedule_event_id')->delete();

        Schema::dropIfExists('calendar_event_divisions');

        Schema::table('calendar_events', function (Blueprint $table) {
            $table->dropUnique(['legacy_schedule_event_id']);
            $table->dropConstrainedForeignId('task_id');
            $table->dropColumn(['is_shared', 'legacy_schedule_event_id']);
        });

        Schema::table('calendar_events', function (Blueprint $table) {
            $table->foreignId('creator_id')->nullable(false)->change();
        });
    }

    /**
     * Copy one agenda, keeping its id when it is free so links and API ids stay the same. An agenda already
     * copied is skipped, so running the copy again never duplicates it.
     */
    private function copy(object $agenda): void
    {
        if (DB::table('calendar_events')->where('legacy_schedule_event_id', $agenda->id)->exists()) {
            return;
        }

        $row = [
            'division_id' => $agenda->owner_division_id,
            'creator_id' => $agenda->creator_id,
            'title' => $agenda->title,
            'description' => $agenda->description,
            'is_shared' => (bool) $agenda->is_shared,
            'task_id' => $agenda->task_id,
            'legacy_schedule_event_id' => $agenda->id,
            'start_at' => substr((string) $agenda->start_date, 0, 10).' 00:00:00',
            'end_at' => $agenda->end_date === null ? null : substr((string) $agenda->end_date, 0, 10).' 00:00:00',
            'created_at' => $agenda->created_at,
            'updated_at' => $agenda->updated_at,
        ];

        if (! DB::table('calendar_events')->where('id', $agenda->id)->exists()) {
            $row['id'] = $agenda->id;
        }

        DB::table('calendar_events')->insert($row);
        $eventId = $row['id'] ?? DB::table('calendar_events')->where('legacy_schedule_event_id', $agenda->id)->value('id');

        DB::table('calendar_event_divisions')->insert(
            DB::table('schedule_event_divisions')
                ->where('schedule_event_id', $agenda->id)
                ->pluck('division_id')
                ->map(fn ($divisionId) => ['calendar_event_id' => $eventId, 'division_id' => $divisionId])
                ->all(),
        );
    }
};
