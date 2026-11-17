<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agendas that stand on their own. Task start dates and deadlines are read from tasks by the Calendar and are
     * not copied here.
     *
     * Beyond the ERD, to keep the agenda access rules of the existing Schedule/Calendar: is_shared marks an agenda
     * every active account sees, and calendar_event_divisions holds the divisions an internal agenda is meant for.
     * division_id is the organising division.
     */
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('division_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('creator_id')->constrained('accounts');
            $table->string('title');
            $table->text('description')->nullable();
            $table->boolean('is_shared')->default(false);
            $table->dateTime('start_at');
            $table->dateTime('end_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('start_at');
        });

        Schema::create('calendar_event_divisions', function (Blueprint $table) {
            $table->foreignId('calendar_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('division_id')->constrained()->cascadeOnDelete();

            $table->primary(['calendar_event_id', 'division_id']);
            $table->index('division_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_event_divisions');
        Schema::dropIfExists('calendar_events');
    }
};
