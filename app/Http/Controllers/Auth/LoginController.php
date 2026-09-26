<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('auth/Login', [
            'mode' => $request->query('mode') === 'pin' ? 'pin' : 'password',
        ]);
    }

    /** Password (login.store) or PIN (login.pin) — the request knows which. */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();
        $request->session()->regenerate();

        return redirect()->intended(route($request->user('admin')->homeRoute()));
    }

    /** Sign out. `?switch=1` (lock / switch user) goes straight to PIN sign-in. */
    public function destroy(Request $request): RedirectResponse
    {
        $admin = $request->user('admin');
        if ($admin) {
            Activity::log('logout', $admin);
        }

        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->boolean('switch')) {
            return redirect()->route('login', ['mode' => 'pin']);
        }

        return redirect()->route('login');
    }
}
