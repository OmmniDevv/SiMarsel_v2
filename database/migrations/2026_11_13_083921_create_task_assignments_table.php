<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A task may have several active PICs. An assignment is active while unassigned_at is null; ending it keeps the row,
     * so replacing a PIC never removes the assignment history.
     *
     * active_flag is 1 for an active assignment and NULL otherwise. A unique index allows many NULLs, so the index on
     * (task_id, account_id, active_flag) stops the same account being an active PIC twice while keeping ended rows.
     */
    public function up(): void
    {
        Schema::create('task_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('accounts')->nullOnDelete();
            $table->timestamp('assigned_at');
            $table->foreignId('unassigned_by')->nullable()->constrained('accounts')->nullOnDelete();
            $table->timestamp('unassigned_at')->nullable();
            $table->text('note')->nullable();
            $table->unsignedTinyInteger('active_flag')->nullable()->storedAs('CASE WHEN unassigned_at IS NULL THEN 1 END');
            $table->unique(['task_id', 'account_id', 'active_flag'], 'task_assignments_active_pic_unique');
            $table->index(['task_id', 'unassigned_at']);
            $table->index(['account_id', 'unassigned_at']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('task_assignments');
    }
};
