<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\CalendarEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An agenda on the shared schedule (Timeline and Calendar), next to the tasks' own dates. The only source of
 * agendas since TASK-19; schedule_events is kept read-only until the move is verified.
 *
 * division_id decides who may see it: NULL is a company-wide agenda visible to every active account, a division
 * makes it internal to that division. Admin and Manager see every agenda.
 */
#[Fillable(['division_id', 'creator_id', 'title', 'description', 'start_at', 'end_at'])]
class CalendarEvent extends Model
{
    /** @use HasFactory<CalendarEventFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
        ];
    }

    /**
     * The division the agenda is for, or null for a company-wide agenda.
     */
    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'creator_id');
    }

    public function isCompanyWide(): bool
    {
        return $this->division_id === null;
    }

    /**
     * Agendas that overlap the period; one without an end lasts its start day only.
     */
    public function scopeWithin(Builder $query, CarbonInterface $from, CarbonInterface $to): void
    {
        $query->where('start_at', '<=', $to->copy()->endOfDay()->toDateTimeString())
            ->whereRaw('coalesce(end_at, start_at) >= ?', [$from->copy()->startOfDay()->toDateTimeString()]);
    }

    /**
     * Focus on a division: its own agendas plus the company-wide ones. No division keeps every agenda.
     * Visibility is applied separately, so the focus never widens it.
     */
    public function scopeForDivision(Builder $query, ?string $division): void
    {
        $query->when($division, fn (Builder $query, string $division) => $query->where(fn (Builder $query) => $query
            ->whereNull('division_id')
            ->orWhereHas('division', fn (Builder $query) => $query->where('code_division', $division))));
    }

    /**
     * Admin and Manager see every agenda; any other active account sees the company-wide agendas and those of
     * its own division.
     */
    public function scopeVisibleTo(Builder $query, Account $account): void
    {
        if (! $account->is_active) {
            $query->whereRaw('1 = 0');

            return;
        }

        if ($account->isAdmin() || $account->isManager()) {
            return;
        }

        $query->where(fn (Builder $query) => $query
            ->whereNull('division_id')
            ->when($account->division_id, fn (Builder $query, int $divisionId) => $query->orWhere('division_id', $divisionId)));
    }
}
