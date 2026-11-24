<?php

namespace Tests\Feature\Database;

use App\Models\CalendarEvent;
use App\Models\Division;
use App\Services\TasklistRecoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LegacyScheduleRemovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_removal_preserves_calendar_agendas_and_target_divisions(): void
    {
        $division = Division::create(['code_division' => 'CAL', 'name' => 'Calendar']);
        $event = CalendarEvent::factory()->internalTo($division)->create();

        Schema::create('schedule_events', fn ($table) => $table->id());
        Schema::create('schedule_event_divisions', fn ($table) => $table->id());

        (require database_path('migrations/2026_11_24_100000_drop_legacy_schedule_tables.php'))->up();

        $this->assertFalse(Schema::hasTable('schedule_events'));
        $this->assertFalse(Schema::hasTable('schedule_event_divisions'));
        $this->assertNotNull($event->fresh());
        $this->assertSame(1, DB::table('calendar_event_divisions')->where('calendar_event_id', $event->id)->count());
        $this->assertSame(1, app(TasklistRecoveryService::class)->newDataReport()['Agenda kalender']);
    }
}
