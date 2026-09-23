<?php

namespace App\Exceptions;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A business rule refused a trash / restore (e.g. "role is assigned to 3 admins").
 * On a web request it goes back with the reason as an error flash, so controllers
 * can simply call `$model->trash($reason)`.
 */
class TrashNotAllowed extends RuntimeException
{
    public function render(Request $request): RedirectResponse|false
    {
        if ($request->expectsJson()) {
            return false;
        }

        return back()->with('error', $this->getMessage());
    }
}
