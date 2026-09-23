<?php

use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\Role;
use Inertia\Testing\AssertableInertia as Assert;

function staffAccount(array $attributes = []): Admin
{
    return Admin::factory()->withRole(Role::factory()->create())->forBranches()->create([
        'username' => 'sara',
        'email' => 'sara@example.com',
        ...$attributes,
    ]);
}

it('signs in with username and password', function () {
    $admin = staffAccount();

    $this->post(route('login.store'), ['username' => 'sara', 'password' => 'password'])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($admin, 'admin');
    expect($admin->fresh()->last_login_at)->not->toBeNull()
        ->and(ActivityLog::where('event', 'login')->where('subject_id', $admin->id)->exists())->toBeTrue();
});

it('signs in with email and password', function () {
    staffAccount();

    $this->post(route('login.store'), ['username' => 'sara@example.com', 'password' => 'password'])
        ->assertRedirect(route('dashboard'));
    $this->assertAuthenticated('admin');
});

it('signs in with username and PIN', function () {
    staffAccount();

    $this->post(route('login.pin'), ['username' => 'sara', 'pin' => '1234'])->assertRedirect(route('dashboard'));
    $this->assertAuthenticated('admin');
});

it('rejects wrong credentials', function (string $route, array $data) {
    staffAccount();

    $this->post(route($route), ['username' => 'sara', ...$data])->assertSessionHasErrors('username');
    $this->assertGuest('admin');
})->with([
    'password' => ['login.store', ['password' => 'wrong-password']],
    'pin' => ['login.pin', ['pin' => '9999']],
]);

it('does not allow PIN sign-in when no PIN is set', function () {
    staffAccount(['pin' => null]);

    $this->post(route('login.pin'), ['username' => 'sara', 'pin' => '1234'])->assertSessionHasErrors('username');
    $this->assertGuest('admin');
});

it('blocks inactive, trashed and branch-less accounts', function (string $state) {
    $admin = staffAccount();
    match ($state) {
        'inactive' => $admin->update(['is_active' => false]),
        'trashed' => $admin->trash(),
        'no branch' => $admin->branches()->first()->update(['is_active' => false]),
    };

    $this->post(route('login.store'), ['username' => 'sara', 'password' => 'password'])
        ->assertSessionHasErrors('username');
    $this->assertGuest('admin');
})->with(['inactive', 'trashed', 'no branch']);

it('rate-limits repeated failures', function () {
    staffAccount();

    foreach (range(1, 5) as $ignored) {
        $this->post(route('login.store'), ['username' => 'sara', 'password' => 'nope']);
    }

    $this->post(route('login.store'), ['username' => 'sara', 'password' => 'password'])
        ->assertSessionHasErrors('username');

    expect(session('errors')->first('username'))->toContain('Too many attempts');
    $this->assertGuest('admin');
});

it('signs out, and switch-user goes to PIN sign-in', function () {
    $this->actingAs(staffAccount(), 'admin');
    $this->post(route('logout'), ['switch' => 1])->assertRedirect(route('login', ['mode' => 'pin']));
    $this->assertGuest('admin');

    $this->actingAs(Admin::first(), 'admin');
    $this->post(route('logout'))->assertRedirect(route('login'));
});

it('ends the session at once when an account is deactivated', function () {
    $admin = staffAccount();
    $this->actingAs($admin, 'admin');
    $admin->update(['is_active' => false]);

    $this->get(route('dashboard'))->assertRedirect(route('login'))->assertSessionHas('error');
    $this->assertGuest('admin');
});

it('keeps signed-in admins away from the login screen', function () {
    loginSuperAdmin();

    $this->get(route('login'))->assertRedirect(route('dashboard'));
});

it('switches only to branches the admin can access', function () {
    [$mine, $other, $notMine] = Branch::factory()->count(3)->create();
    loginAdminWithRoutes([], $mine, $other);

    $this->post(route('branch.switch'), ['branch' => $other->uuid])->assertRedirect(route('dashboard'));
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('context.branch.id', $other->uuid)
        ->has('context.branches', 2));

    $this->post(route('branch.switch'), ['branch' => $notMine->uuid])->assertForbidden();
});
