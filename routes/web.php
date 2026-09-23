<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Dev\UiKitController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');

// Phase 0: screens only. The admin guard + auth middleware arrive in Phase 1.
Route::get('/login', [LoginController::class, 'create'])->name('login');
Route::post('/login', [LoginController::class, 'store'])->name('login.store');

Route::get('/dashboard', DashboardController::class)->name('dashboard');

// Design-system gallery for side-by-side checks with pos-react. Local only.
if (app()->isLocal() || app()->runningUnitTests()) {
    Route::get('/dev/ui', UiKitController::class)->name('dev.ui');
}
