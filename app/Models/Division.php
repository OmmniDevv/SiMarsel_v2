<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code_division', 'name'])]
class Division extends Model
{
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /**
     * Tasks this division owns (tasks.division_id).
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * Tasks owned by another division that this division collaborates on.
     */
    public function collaboratingTasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'task_divisions')
            ->using(TaskDivision::class)
            ->withPivot(['added_by']);
    }

    /**
     * Divisions the account may create tasks for: every division for Admin and Managers, the managed one for a
     * Supervisor, none for an account without task.create.
     */
    public function scopeTaskCreatableBy(Builder $query, Account $account): void
    {
        if (! $account->is_active || ! $account->hasPermission(Permission::TASK_CREATE)) {
            $query->whereKey(0);

            return;
        }

        if ($account->isAdmin() || $account->isManager()) {
            return;
        }

        $query->whereKey($account->isSupervisor() ? ($account->division_id ?? 0) : 0);
    }
}
