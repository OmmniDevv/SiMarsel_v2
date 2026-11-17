<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * task_activities.created_at is required, as in the ERD: history is ordered by it. An entry recorded without a
 * time takes its task's creation time, or the time of this migration when that is unknown too.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('task_activities')->whereNull('created_at')->orderBy('id')->chunkById(500, function ($activities) {
            foreach ($activities as $activity) {
                DB::table('task_activities')->where('id', $activity->id)->update([
                    'created_at' => DB::table('tasks')->where('id', $activity->task_id)->value('created_at') ?? now(),
                ]);
            }
        });

        Schema::table('task_activities', function (Blueprint $table) {
            $table->timestamp('created_at')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('task_activities', function (Blueprint $table) {
            $table->timestamp('created_at')->nullable()->change();
        });
    }
};
