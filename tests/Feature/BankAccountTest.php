<?php

use App\Exceptions\PermanentDeleteNotAllowed;
use App\Models\BankAccount;
use App\Models\BankAccountBranch;
use App\Models\Branch;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->home = Branch::factory()->create(['name' => 'Gulberg']);
    $this->other = Branch::factory()->create(['name' => 'DHA']);
});

function accountForm(array $overrides = []): array
{
    return [
        'bank_name' => 'Meezan Bank',
        'account_title' => 'Karahi House Pvt Ltd',
        'account_number' => '0101-0012345678',
        'iban' => 'pk36 mezn 0001 0100 1234 5678',
        'show_on_receipt' => true,
        'is_active' => true,
        ...$overrides,
    ];
}

it('adds a bank account available at picked branches, with a normalized IBAN', function () {
    loginSuperAdmin();

    $this->post(route('bank-accounts.store'), accountForm(['branches' => [$this->home->uuid, $this->other->uuid]]))
        ->assertSessionHasNoErrors();

    $account = BankAccount::sole();
    expect($account->iban)->toBe('PK36MEZN0001010012345678')
        ->and($account->branches->pluck('name')->sort()->values()->all())->toBe(['DHA', 'Gulberg'])
        ->and(BankAccount::query()->availableAt($this->home)->count())->toBe(1);

    $response = $this->get(route('bank-accounts.index'))->assertInertia(fn (Assert $page) => $page
        ->component('bank-accounts/Index')
        ->has('accounts.data', 1)
        ->has('accounts.data.0.branches', 2));

    expectNoNumericIds($response->inertiaProps());
});

it('requires a branch and a unique account number (also in the trash)', function () {
    loginSuperAdmin();
    BankAccount::factory()->create(['account_number' => '0101-0012345678'])->trash();

    $this->post(route('bank-accounts.store'), accountForm(['branches' => []]))
        ->assertSessionHasErrors(['branches', 'account_number' => 'A bank account in the trash already uses this account number — restore it from the Trash tab instead.']);
});

it('removes a branch by trashing the link, not deleting it', function () {
    loginSuperAdmin();
    $account = BankAccount::factory()->forBranches($this->home, $this->other)->create();

    $this->put(route('bank-accounts.update', $account), accountForm(['branches' => [$this->home->uuid]]))
        ->assertSessionHasNoErrors();

    expect($account->branches()->pluck('name')->all())->toBe(['Gulberg'])
        ->and(BankAccountBranch::onlyTrashed()->count())->toBe(1);
});

it('lets a branch admin see and link only their own branches, keeping the others', function () {
    loginAdminWithRoutes(['bank-accounts.index', 'bank-accounts.update'], $this->home);
    $shared = BankAccount::factory()->forBranches($this->home, $this->other)->create();
    $foreign = BankAccount::factory()->forBranches($this->other)->create();

    $this->get(route('bank-accounts.index'))->assertInertia(fn (Assert $page) => $page
        ->has('accounts.data', 1)
        ->where('accounts.data.0.id', $shared->uuid)
        ->has('branches', 1));

    $this->put(route('bank-accounts.update', $foreign), accountForm(['branches' => [$this->home->uuid]]))->assertNotFound();
    $this->put(route('bank-accounts.update', $shared), accountForm(['branches' => [$this->other->uuid]]))
        ->assertSessionHasErrors('branches');

    // unticking nothing of theirs keeps DHA (outside their scope)
    $this->put(route('bank-accounts.update', $shared), accountForm(['branches' => [$this->home->uuid]]))
        ->assertSessionHasNoErrors();
    expect($shared->branches()->count())->toBe(2);
});

it('trashes and restores a bank account', function () {
    loginSuperAdmin();
    $account = BankAccount::factory()->forBranches($this->home)->create();

    $this->delete(route('bank-accounts.destroy', $account))->assertSessionHas('success');
    expect($account->fresh()->isTrashed())->toBeTrue();

    $this->post(route('bank-accounts.restore', $account))->assertSessionHas('success');
    expect($account->fresh()->isTrashed())->toBeFalse()
        ->and(fn () => $account->delete())->toThrow(PermanentDeleteNotAllowed::class);
});
