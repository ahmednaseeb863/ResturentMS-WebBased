<?php

use Inertia\Testing\AssertableInertia as Assert;

it('redirects the home page to login', function () {
    $this->get('/')->assertRedirect('/login');
});

it('renders the login screen without the app shell', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/Login')
            ->where('app.name', config('app.name'))
            ->where('app.version', config('app.version')));
});

it('validates the login form', function () {
    $this->post(route('login.store'), [])
        ->assertSessionHasErrors(['username', 'password']);
});

it('does not sign anyone in before admin accounts exist', function () {
    $this->post(route('login.store'), ['username' => 'admin', 'password' => 'secret'])
        ->assertSessionHasErrors('username');

    $this->assertGuest();
});

it('renders the dashboard with the shared shell props', function () {
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('stats', 4)
            ->has('week', 7)
            ->where('auth.user', null)
            ->has('context', fn (Assert $c) => $c
                ->where('branch', null)
                ->where('branches', [])
                ->where('shift', null)
                ->where('business_date', null))
            ->has('flash'));
});

it('never sends numeric ids to the frontend', function (string $routeName) {
    $response = $this->get(route($routeName))->assertOk();

    expectNoNumericIds($response->inertiaProps());
})->with(['login', 'dashboard']);

it('serves the design-system gallery outside production', function () {
    $this->get(route('dev.ui'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('dev/UiKit'));
});
