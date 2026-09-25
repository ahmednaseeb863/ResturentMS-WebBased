<?php

namespace App\Http\Controllers\Admin;

use App\Actions\SaveBankAccount;
use App\Http\Controllers\Concerns\ListsWithTrash;
use App\Http\Controllers\Controller;
use App\Http\Requests\BankAccountRequest;
use App\Http\Resources\BankAccountResource;
use App\Models\BankAccount;
use App\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Shared bank accounts; a normal admin sees the ones available at their branches. */
class BankAccountController extends Controller
{
    use ListsWithTrash;

    public function index(Request $request): Response
    {
        $actor = $request->user('admin');
        $tab = $this->listTab($request, 'bank-accounts.restore');
        $search = trim((string) $request->query('search'));
        $status = $request->query('status', 'all');
        $visible = fn () => BankAccount::query()->visibleTo($actor);

        $accounts = $this->applyTab($visible(), $tab)
            ->with('branches')
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('bank_name', 'like', "%{$search}%")
                ->orWhere('account_title', 'like', "%{$search}%")
                ->orWhere('account_number', 'like', "%{$search}%")
                ->orWhere('iban', 'like', "%{$search}%")))
            ->when($status !== 'all', fn ($q) => $q->where('is_active', $status === 'active'))
            ->orderBy('bank_name')
            ->orderBy('account_title')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('bank-accounts/Index', [
            'accounts' => BankAccountResource::collection($accounts),
            'filters' => ['tab' => $tab, 'search' => $search, 'status' => $status],
            'counts' => ['active' => $visible()->count(), 'trash' => $visible()->onlyTrashed()->count()],
            'branches' => $actor->accessibleBranches()->map(fn (Branch $b) => ['value' => $b->uuid, 'label' => $b->name])->values(),
        ]);
    }

    public function store(BankAccountRequest $request, SaveBankAccount $save): RedirectResponse
    {
        $account = $save->handle($request);

        return back()->with('success', "Bank account “{$account->trashLabel()}” added.");
    }

    public function update(BankAccountRequest $request, BankAccount $bankAccount, SaveBankAccount $save): RedirectResponse
    {
        $this->ensureVisible($request, $bankAccount);
        $save->handle($request, $bankAccount);

        return back()->with('success', "Bank account “{$bankAccount->trashLabel()}” saved.");
    }

    public function destroy(Request $request, BankAccount $bankAccount): RedirectResponse
    {
        $this->ensureVisible($request, $bankAccount);
        $bankAccount->trash($this->trashReason($request));

        return back()->with('success', "Bank account “{$bankAccount->trashLabel()}” moved to trash.");
    }

    public function restore(Request $request, BankAccount $bankAccount): RedirectResponse
    {
        $this->ensureVisible($request, $bankAccount);
        $bankAccount->restoreFromTrash();

        return back()->with('success', "Bank account “{$bankAccount->trashLabel()}” restored.");
    }

    private function ensureVisible(Request $request, BankAccount $account): void
    {
        abort_unless(
            BankAccount::withTrashed()->visibleTo($request->user('admin'))->whereKey($account->id)->exists(),
            404,
        );
    }
}
