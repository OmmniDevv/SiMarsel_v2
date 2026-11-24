<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('schedule_event_divisions');
        Schema::dropIfExists('schedule_events');
    }

    public function down(): void {}
};
