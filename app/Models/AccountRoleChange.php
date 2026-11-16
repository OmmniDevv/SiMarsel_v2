<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One role an account was given: the role before (null at creation), the role after, who changed it, and when.
 * Append-only audit; the application never updates or deletes these rows.
 */
#[Fillable(['account_id', 'old_role_id', 'new_role_id', 'changed_by', 'changed_at'])]
class AccountRoleChange extends Model
{
    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'changed_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function oldRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'old_role_id');
    }

    public function newRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'new_role_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'changed_by');
    }
}
