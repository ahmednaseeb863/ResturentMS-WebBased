<?php

namespace App\Actions;

use App\Http\Requests\BankAccountRequest;
use App\Models\BankAccount;
use Illuminate\Support\Facades\DB;

/**
 * Add / edit a bank account and the branches it is available at. A normal admin
 * only picks among their own branches, so links to other branches are kept.
 */
class SaveBankAccount
{
    public function handle(BankAccountRequest $request, ?BankAccount $account = null): BankAccount
    {
        return DB::transaction(function () use ($request, $account) {
            $account ??= new BankAccount;
            $account->fill($request->accountData())->save();

            $ids = $request->branchIds();
            $actor = $request->actor();

            if (! $actor->is_super_admin) {
                $outside = $account->branches()->pluck('branches.id')->diff($actor->accessibleBranches()->pluck('id'));
                $ids = [...$ids, ...$outside];
            }

            $account->syncBranches($ids);

            return $account;
        });
    }
}
