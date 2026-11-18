<?php

namespace App\Services;

use App\Exceptions\AccountHasTaskHistoryException;
use App\Exceptions\CannotChangeOwnRoleException;
use App\Exceptions\CannotDeleteOwnAccountException;
use App\Models\Account;
use App\Models\AccountRoleChange;
use App\Repositories\AccountRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class AccountService
{
    public function __construct(private AccountRepository $accountRepository) {}

    /**
     * @return Collection<int, Account>
     */
    public function list(): Collection
    {
        return $this->accountRepository->all();
    }

    /**
     * Create the account and record its first role in the role audit.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, Account $actor): Account
    {
        return DB::transaction(function () use ($data, $actor) {
            $account = $this->accountRepository->create($data);
            $this->recordRoleChange($account, null, $actor);

            return $account;
        });
    }

    /**
     * The account row is locked and its current role read inside the transaction, so of two role changes made at
     * nearly the same time the second waits for the first and its audit starts from the role the first one saved.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws CannotChangeOwnRoleException
     */
    public function update(Account $account, array $data, Account $actor): Account
    {
        return DB::transaction(function () use ($account, $data, $actor) {
            $account = $this->accountRepository->lockForUpdate($account);
            $oldRoleId = $account->role_id;

            if ($account->is($actor) && array_key_exists('role_id', $data) && (int) $data['role_id'] !== (int) $oldRoleId) {
                throw new CannotChangeOwnRoleException;
            }

            $account = $this->accountRepository->update($account, $data);

            // A role change takes effect on the next request, since permissions are read from the role; task history
            // is left as it is.
            if ((int) $account->role_id !== (int) $oldRoleId) {
                $this->recordRoleChange($account, $oldRoleId, $actor);
            }

            return $account;
        });
    }

    /**
     * @throws CannotDeleteOwnAccountException
     * @throws AccountHasTaskHistoryException When the account appears in task history; it should be deactivated instead.
     */
    public function delete(Account $account, Account $actor): void
    {
        if ($account->is($actor)) {
            throw new CannotDeleteOwnAccountException;
        }

        if ($this->accountRepository->hasTaskHistory($account)) {
            throw new AccountHasTaskHistoryException;
        }

        $this->accountRepository->delete($account);
    }

    private function recordRoleChange(Account $account, ?int $oldRoleId, Account $actor): void
    {
        AccountRoleChange::create([
            'account_id' => $account->id,
            'account_fullname' => $account->fullname,
            'account_username' => $account->username,
            'old_role_id' => $oldRoleId,
            'new_role_id' => $account->role_id,
            'changed_by' => $actor->id,
            'changed_by_fullname' => $actor->fullname,
            'changed_by_username' => $actor->username,
            'changed_at' => now(),
        ]);
    }
}
