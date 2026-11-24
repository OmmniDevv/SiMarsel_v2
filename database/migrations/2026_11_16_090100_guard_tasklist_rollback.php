<?php

use App\Exceptions\UnsafeTasklistRollbackException;
use App\Services\TasklistRecoveryService;
use Illuminate\Database\Migrations\Migration;

/**
 * The last TASK-19 migration, so it is the first one a rollback reaches. It changes nothing on the way up; on the
 * way down it stops the rollback while data exists that the older schema cannot hold, because dropping the new
 * tables would delete it. PIC assignments are preserved only in task_assignments.
 */
return new class extends Migration
{
    public function up(): void {}

    public function down(): void
    {
        $recovery = app(TasklistRecoveryService::class);

        if ($recovery->hasNewData() && ! config('tasklist.allow_destructive_rollback')) {
            throw UnsafeTasklistRollbackException::withReport($recovery->newDataReport());
        }
    }
};
