<?php

use App\Exceptions\UnsafeTasklistRollbackException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit of role changes (TASK-12): one row each time an account gets a role, from creation onwards. Permissions are
 * read from the role at request time, so this table is the record of when an account's permissions changed. Task
 * history is never rewritten by a role change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_role_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            // null when the account was created with its first role.
            $table->foreignId('old_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->foreignId('new_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('accounts')->nullOnDelete();
            $table->timestamp('changed_at');

            $table->index(['account_id', 'changed_at']);
        });
    }

    public function down(): void
    {
        if (DB::table('account_role_changes')->exists() && ! config('tasklist.allow_destructive_rollback')) {
            throw new UnsafeTasklistRollbackException(
                'Rollback dibatalkan karena account_role_changes berisi audit perubahan role. '
                .'Set TASKLIST_ALLOW_DESTRUCTIVE_ROLLBACK=true hanya bila audit tersebut memang boleh dibuang.'
            );
        }

        Schema::dropIfExists('account_role_changes');
    }
};
