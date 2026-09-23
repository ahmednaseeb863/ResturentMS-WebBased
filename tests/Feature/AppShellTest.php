<?php

use App\Models\Branch;
use Inertia\Testing\AssertableInertia as Assert;

it('sends guests to the login screen', function () {
    $this->get('/')->assertRedirect('/dashboard');
    $this->get('/dashboard')->assertRedirect('/login');
});

it('renders the login screen without the app shell', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/Login')
            ->where('mode', 'password')
            ->where('auth.user', null)
            ->where('app.name', config('app.name'))
            ->where('app.version', config('app.version')));
});

it('opens the login screen in PIN mode', function () {
    $this->get(route('login', ['mode' => 'pin']))
        ->assertInertia(fn (Assert $page) => $page->where('mode', 'pin'));
});

it('renders the dashboard with the shared shell props', function () {
    $admin = loginSuperAdmin();
    $branch = Branch::first();

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('stats', 4)
            ->where('auth.user.id', $admin->uuid)
            ->where('auth.user.role', 'Super Admin')
            ->where('auth.permissions.all', true)
            ->where('context.branch.id', $branch->uuid)
            ->has('context.branches', 1)
            ->where('context.shift', null)
            ->has('flash'));
});

it('never sends numeric ids to the frontend', function (string $routeName) {
    loginSuperAdmin();

    expectNoNumericIds($this->get(route($routeName))->assertOk()->inertiaProps());
})->with(['dashboard', 'branches.index', 'admins.index', 'roles.index', 'trash.index', 'activity.index']);

it('never sends numeric ids on the login screen', function () {
    expectNoNumericIds($this->get(route('login'))->assertOk()->inertiaProps());
});

it('serves the design-system gallery outside production', function () {
    $this->get(route('dev.ui'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('dev/UiKit'));
});
