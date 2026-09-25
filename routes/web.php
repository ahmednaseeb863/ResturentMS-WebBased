<?php

use App\Http\Controllers\Admin\ActivityController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\AreaController;
use App\Http\Controllers\Admin\BankAccountController;
use App\Http\Controllers\Admin\BranchController;
use App\Http\Controllers\Admin\CashCounterController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DealController;
use App\Http\Controllers\Admin\DesignationController;
use App\Http\Controllers\Admin\DiningTableController;
use App\Http\Controllers\Admin\DiscountController;
use App\Http\Controllers\Admin\EmployeeController;
use App\Http\Controllers\Admin\KitchenStationController;
use App\Http\Controllers\Admin\MenuItemController;
use App\Http\Controllers\Admin\ModifierGroupController;
use App\Http\Controllers\Admin\PrinterController;
use App\Http\Controllers\Admin\RawMaterialCategoryController;
use App\Http\Controllers\Admin\RawMaterialController;
use App\Http\Controllers\Admin\ReadyItemController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\ShiftTypeController;
use App\Http\Controllers\Admin\StockController;
use App\Http\Controllers\Admin\TrashController;
use App\Http\Controllers\Admin\UnitController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BranchSwitchController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Dev\UiKitController;
use App\Support\Settings\SettingsRegistry;
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

    // Menu
    Route::resource('categories', CategoryController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('categories/{category}/restore', [CategoryController::class, 'restore'])->withTrashed()->name('categories.restore');

    Route::post('menu-items/copy', [MenuItemController::class, 'copy'])->name('menu-items.copy');
    Route::resource('menu-items', MenuItemController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('menu-items/{menu_item}/restore', [MenuItemController::class, 'restore'])->withTrashed()->name('menu-items.restore');

    Route::resource('ready-items', ReadyItemController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('ready-items/{ready_item}/restore', [ReadyItemController::class, 'restore'])->withTrashed()->name('ready-items.restore');

    Route::resource('modifier-groups', ModifierGroupController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('modifier-groups/{modifier_group}/restore', [ModifierGroupController::class, 'restore'])->withTrashed()->name('modifier-groups.restore');

    Route::resource('kitchen-stations', KitchenStationController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('kitchen-stations/{kitchen_station}/restore', [KitchenStationController::class, 'restore'])->withTrashed()->name('kitchen-stations.restore');

    Route::resource('deals', DealController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('deals/{deal}/restore', [DealController::class, 'restore'])->withTrashed()->name('deals.restore');

    Route::resource('discounts', DiscountController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('discounts/{discount}/restore', [DiscountController::class, 'restore'])->withTrashed()->name('discounts.restore');

    // Floor
    Route::get('tables/floor', [DiningTableController::class, 'floor'])->name('tables.floor');
    Route::put('tables/layout', [DiningTableController::class, 'layout'])->name('tables.layout');
    Route::put('tables/{table}/status', [DiningTableController::class, 'status'])->name('tables.status');
    Route::resource('tables', DiningTableController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('tables/{table}/restore', [DiningTableController::class, 'restore'])->withTrashed()->name('tables.restore');

    Route::resource('areas', AreaController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('areas/{area}/restore', [AreaController::class, 'restore'])->withTrashed()->name('areas.restore');

    // Inventory
    Route::resource('raw-materials', RawMaterialController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('raw-materials/{raw_material}/restore', [RawMaterialController::class, 'restore'])->withTrashed()->name('raw-materials.restore');

    Route::resource('raw-material-categories', RawMaterialCategoryController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('raw-material-categories/{raw_material_category}/restore', [RawMaterialCategoryController::class, 'restore'])->withTrashed()->name('raw-material-categories.restore');

    Route::resource('units', UnitController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('units/{unit}/restore', [UnitController::class, 'restore'])->withTrashed()->name('units.restore');

    Route::post('stock/add', [StockController::class, 'store'])->name('stock.add');
    Route::get('stock-ledger', [StockController::class, 'ledger'])->name('stock-ledger.index');

    // People
    Route::resource('customers', CustomerController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('customers/{customer}/restore', [CustomerController::class, 'restore'])->withTrashed()->name('customers.restore');

    Route::resource('employees', EmployeeController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('employees/{employee}/restore', [EmployeeController::class, 'restore'])->withTrashed()->name('employees.restore');

    Route::resource('designations', DesignationController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('designations/{designation}/restore', [DesignationController::class, 'restore'])->withTrashed()->name('designations.restore');

    // Branch setup
    Route::resource('counters', CashCounterController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('counters/{counter}/restore', [CashCounterController::class, 'restore'])->withTrashed()->name('counters.restore');

    Route::resource('printers', PrinterController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('printers/{printer}/restore', [PrinterController::class, 'restore'])->withTrashed()->name('printers.restore');
    Route::get('printers/{printer}/test', [PrinterController::class, 'test'])->name('printers.test');

    Route::resource('shift-types', ShiftTypeController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('shift-types/{shift_type}/restore', [ShiftTypeController::class, 'restore'])->withTrashed()->name('shift-types.restore');

    Route::resource('bank-accounts', BankAccountController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('bank-accounts/{bank_account}/restore', [BankAccountController::class, 'restore'])->withTrashed()->name('bank-accounts.restore');

    // Settings: the current branch (one save route per group = per-group permission) and global
    Route::get('settings', [SettingsController::class, 'branch'])->name('settings.index');
    foreach (SettingsRegistry::groupKeys() as $group) {
        Route::put("settings/branch/{$group}", [SettingsController::class, 'updateBranch'])
            ->defaults('group', $group)
            ->name("settings.branch.{$group}");
    }
    Route::get('settings/global', [SettingsController::class, 'global'])->name('settings.global');
    Route::put('settings/global/{group}', [SettingsController::class, 'updateGlobal'])
        ->whereIn('group', SettingsRegistry::groupKeys())
        ->name('settings.global.update');

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
