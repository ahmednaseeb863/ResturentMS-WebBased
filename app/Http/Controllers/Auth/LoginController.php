<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('auth/Login');
    }

    /**
     * Phase 0 stub: validates the form so the UI states can be checked.
     * Real sign-in against the `admin` guard is implemented in Phase 1.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        throw ValidationException::withMessages([
            'username' => 'Sign-in is not available yet — admin accounts arrive in Phase 1.',
        ]);
    }
}
