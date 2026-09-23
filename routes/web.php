<?php

use App\Http\Controllers\Admin\ActivityController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\BranchController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\TrashController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BranchSwitchController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Dev\UiKitController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('guest:admin')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:20,1')->name('login.store');
    Route::post('/login/pin', [LoginController::class, 'store'])->middleware('throttle:20,1')->name('login.pin');
});

/*
| Every signed-in route sits behind `permission`: the route name must be granted
| by the admin's role (App\Support\Permissions\PermissionCatalog) or whitelisted
| in config/permissions.php. Super admins pass everything.
*/
Route::middleware(['auth:admin', 'permission'])->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::post('/branch/switch', BranchSwitchController::class)->name('branch.switch');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    // Administration
    Route::resource('branches', BranchController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('branches/{branch}/restore', [BranchController::class, 'restore'])->withTrashed()->name('branches.restore');

    Route::resource('admins', AdminController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('admins/{admin}/restore', [AdminController::class, 'restore'])->withTrashed()->name('admins.restore');

    Route::resource('roles', RoleController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('roles/{role}/restore', [RoleController::class, 'restore'])->withTrashed()->name('roles.restore');

    Route::get('trash', [TrashController::class, 'index'])->name('trash.index');
    Route::post('trash/{module}/{uuid}/restore', [TrashController::class, 'restore'])->name('trash.restore');

    Route::get('activity', [ActivityController::class, 'index'])->name('activity.index');
});

// Design-system gallery for side-by-side checks with pos-react. Local only.
if (app()->isLocal() || app()->runningUnitTests()) {
    Route::get('/dev/ui', UiKitController::class)->name('dev.ui');
}
