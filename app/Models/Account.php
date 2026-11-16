<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

#[Fillable(['role_id', 'division_id', 'fullname', 'username', 'password', 'is_active'])]
#[Hidden(['password'])]
class Account extends Authenticatable
{
    public const USERNAME_FORMAT = '/^[a-zA-Z0-9._]{4,25}$/';

    /**
     * The accounts table has no remember_token column.
     */
    protected $rememberTokenName = '';

    /**
     * Same default as the column, so a new account is active before it is reloaded.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    /**
     * Every PIC assignment of the account, active and released.
     */
    public function taskAssignments(): HasMany
    {
        return $this->hasMany(TaskAssignment::class);
    }

    /**
     * Tasks the account is an active PIC of.
     */
    public function assignedTasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'task_assignments')
            ->wherePivotNull('unassigned_at')
            ->withPivot(['id', 'assigned_by', 'assigned_at']);
    }

    public function taskComments(): HasMany
    {
        return $this->hasMany(TaskComment::class, 'author_id');
    }

    /**
     * Accounts that can be PIC of a task: active Staff, Freelance, and Supervisors of any division, so a task can
     * be assigned across divisions.
     */
    public function scopeAssignableAsPic(Builder $query): void
    {
        $query->where('is_active', true)
            ->whereHas('role', fn (Builder $query) => $query->whereIn('code_role', Role::PIC_ROLES));
    }

    public function isAdmin(): bool
    {
        return $this->role?->code_role === Role::ADMIN;
    }

    public function isManager(): bool
    {
        return $this->role?->code_role === Role::MANAGER;
    }

    public function isSupervisor(): bool
    {
        return $this->role?->code_role === Role::SUPERVISOR;
    }

    /**
     * Whether the account's role grants the permission (see Permission). A permission only says what the role may do
     * in general; division, PIC, status, and field rules are still checked separately.
     */
    public function hasPermission(string $code): bool
    {
        return (bool) $this->role?->hasPermission($code);
    }

    /**
     * Codes of every permission the account's role grants, sorted.
     *
     * @return array<int, string>
     */
    public function permissionCodes(): array
    {
        return $this->role?->permissions->pluck('code')->sort()->values()->all() ?? [];
    }
}
