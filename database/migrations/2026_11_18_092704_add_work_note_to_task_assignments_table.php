<?php

use App\Exceptions\UnsafeTasklistRollbackException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Work note of each PIC (TASK-13): what the PIC writes about their own work, kept on their assignment so it never
 * overwrites the task's main instruction (tasks.description) or another PIC's note. A released PIC's note stays on
 * the ended assignment. `note` remains the note written when the PIC was assigned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_assignments', function (Blueprint $table) {
            $table->text('work_note')->nullable()->after('note');
            $table->timestamp('work_note_updated_at')->nullable()->after('work_note');
        });
    }

    public function down(): void
    {
        if (DB::table('task_assignments')->whereNotNull('work_note')->exists() && ! config('tasklist.allow_destructive_rollback')) {
            throw new UnsafeTasklistRollbackException(
                'Rollback dibatalkan karena task_assignments berisi catatan kerja PIC. '
                .'Set TASKLIST_ALLOW_DESTRUCTIVE_ROLLBACK=true hanya bila catatan tersebut memang boleh dibuang.'
            );
        }

        Schema::table('task_assignments', function (Blueprint $table) {
            $table->dropColumn(['work_note', 'work_note_updated_at']);
        });
    }
};
