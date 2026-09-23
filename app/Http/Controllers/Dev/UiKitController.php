<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class UiKitController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('dev/UiKit');
    }
}
