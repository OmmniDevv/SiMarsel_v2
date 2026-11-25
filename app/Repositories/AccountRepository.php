<?php

namespace App\Repositories;

use App\Models\Account;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class AccountRepository
{
    /**
     * Columns that tie an account to task history. Soft-deleted rows count too, since they are still history.
     *
     * @var array<string, array<int, string>>
     */
    private const TASK_HISTORY_REFERENCES = [
        'tasks' => ['creator_id', 'deleted_by'],
        'task_assignments' => ['account_id', 'assigned_by', 'unassigned_by'],
        'task_activities' => ['account_id'],
        'task_evidences' => ['submitter_id', 'deleted_by'],
        'task_reviews' => ['reviewer_id'],
        'task_comments' => ['author_id'],
        'task_divisions' => ['added_by'],
        'calendar_events' => ['creator_id'],
    ];

    public function hasTaskHistory(Account $account): bool
    {
        foreach (self::TASK_HISTORY_REFERENCES as $table => $columns) {
            $referenced = DB::table($table)
                ->where(function ($query) use ($columns, $account) {
                    foreach ($columns as $column) {
                        $query->orWhere($column, $account->id);
                    }
                })
                ->exists();

            if ($referenced) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return Collection<int, Account>
     */
    public function all(): Collection
    {
        return Account::with(['role', 'division'])->orderBy('id')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Account
    {
        return Account::create($data)->load(['role', 'division']);
    }

    /**
     * The account as it is now in the database, locked until the surrounding transaction ends.
     */
    public function lockForUpdate(Account $account): Account
    {
        return Account::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Account $account, array $data): Account
    {
        $account->update($data);

        return $account->load(['role', 'division']);
    }

    public function delete(Account $account): void
    {
        $account->delete();
    }
}
