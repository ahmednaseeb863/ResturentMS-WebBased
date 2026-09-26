<?php

use App\Http\Controllers\Admin\ActivityController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\AreaController;
use App\Http\Controllers\Admin\BankAccountController;
use App\Http\Controllers\Admin\BillingController;
use App\Http\Controllers\Admin\BranchController;
use App\Http\Controllers\Admin\CashCounterController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\ConsumptionController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DealController;
use App\Http\Controllers\Admin\DeliveryController;
use App\Http\Controllers\Admin\DeliveryZoneController;
use App\Http\Controllers\Admin\DesignationController;
use App\Http\Controllers\Admin\DiningTableController;
use App\Http\Controllers\Admin\DiscountController;
use App\Http\Controllers\Admin\EmployeeController;
use App\Http\Controllers\Admin\ExpenseController;
use App\Http\Controllers\Admin\KitchenController;
use App\Http\Controllers\Admin\KitchenStationController;
use App\Http\Controllers\Admin\LowStockController;
use App\Http\Controllers\Admin\MenuItemController;
use App\Http\Controllers\Admin\ModifierGroupController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\PosController;
use App\Http\Controllers\Admin\PrinterController;
use App\Http\Controllers\Admin\PrintJobController;
use App\Http\Controllers\Admin\PurchaseController;
use App\Http\Controllers\Admin\QzController;
use App\Http\Controllers\Admin\RawMaterialCategoryController;
use App\Http\Controllers\Admin\RawMaterialController;
use App\Http\Controllers\Admin\ReadyItemController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\ReservationController;
use App\Http\Controllers\Admin\RiderController;
use App\Http\Controllers\Admin\RiderPanelController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\ShiftController;
use App\Http\Controllers\Admin\ShiftTypeController;
use App\Http\Controllers\Admin\StockController;
use App\Http\Controllers\Admin\StockCountController;
use App\Http\Controllers\Admin\SupplierController;
use App\Http\Controllers\Admin\TrashController;
use App\Http\Controllers\Admin\UnitController;
use App\Http\Controllers\Admin\WaiterController;
use App\Http\Controllers\Admin\WasteController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BranchSwitchController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Dev\UiKitController;
use App\Http\Controllers\LivePollController;
use App\Reports\Report;
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
    Route::get('/live', LivePollController::class)->middleware('throttle:240,1')->name('live.poll');

    // POS & orders
    Route::get('pos', [PosController::class, 'index'])->name('pos.index');
    Route::post('pos/orders', [PosController::class, 'store'])->name('pos.orders.store');
    Route::put('pos/orders/{order}', [PosController::class, 'update'])->name('pos.orders.update');
    Route::put('pos/orders/{order}/discard', [PosController::class, 'discard'])->name('pos.orders.discard');
    Route::post('pos/customers', [PosController::class, 'storeCustomer'])->name('pos.customers.store');

    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::put('orders/{order}/cancel', [OrderController::class, 'cancel'])->middleware('throttle:30,1')->name('orders.cancel');
    Route::put('orders/{order}/discount', [OrderController::class, 'discount'])->middleware('throttle:30,1')->name('orders.discount');
    Route::put('orders/{order}/service-charge', [OrderController::class, 'serviceCharge'])->middleware('throttle:30,1')->name('orders.service-charge');
    Route::put('orders/{order}/items/{item}/void', [OrderController::class, 'void'])->middleware('throttle:30,1')->scopeBindings()->name('orders.items.void');

    // Billing
    Route::post('orders/{order}/payments', [BillingController::class, 'pay'])->middleware('throttle:60,1')->name('orders.payments.store');
    Route::put('orders/{order}/split', [BillingController::class, 'split'])->name('orders.split');
    Route::post('orders/{order}/print/bill', [BillingController::class, 'printBill'])->name('orders.print.bill');
    Route::post('orders/{order}/print/receipt', [BillingController::class, 'printReceipt'])->name('orders.print.receipt');
    Route::get('orders/{order}/bill', [BillingController::class, 'page'])->name('orders.bill');
    Route::post('orders/{order}/payments/{payment}/refund', [BillingController::class, 'refund'])->middleware('throttle:30,1')->scopeBindings()->name('orders.payments.refund');
    Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');

    // Waiter app (phones / tablets)
    Route::get('waiter', [WaiterController::class, 'index'])->name('waiter.index');
    Route::get('waiter/tables/{table}', [WaiterController::class, 'table'])->name('waiter.table');
    Route::post('waiter/tables/{table}/orders', [WaiterController::class, 'store'])->name('waiter.orders.store');
    Route::put('waiter/orders/{order}', [WaiterController::class, 'update'])->name('waiter.orders.update');
    Route::put('waiter/orders/{order}/serve', [WaiterController::class, 'serve'])->name('waiter.orders.serve');
    Route::post('waiter/orders/{order}/bill', [WaiterController::class, 'requestBill'])->middleware('throttle:30,1')->name('waiter.orders.bill');

    // Deliveries & riders
    Route::get('deliveries', [DeliveryController::class, 'index'])->name('deliveries.index');
    Route::put('deliveries/{delivery}/rider', [DeliveryController::class, 'assign'])->name('deliveries.assign');
    Route::put('deliveries/{delivery}/status', [DeliveryController::class, 'status'])->name('deliveries.status');
    Route::get('riders', [RiderController::class, 'index'])->name('riders.index');
    Route::post('riders/{rider}/settle', [RiderController::class, 'settle'])->middleware('throttle:30,1')->name('riders.settle');
    Route::resource('delivery-zones', DeliveryZoneController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('delivery-zones/{delivery_zone}/restore', [DeliveryZoneController::class, 'restore'])->withTrashed()->name('delivery-zones.restore');

    // Rider panel (phones)
    Route::get('rider', [RiderPanelController::class, 'index'])->name('rider.index');
    Route::put('rider/deliveries/{delivery}/status', [RiderPanelController::class, 'status'])->name('rider.deliveries.status');

    // Kitchen display
    Route::get('kitchen', [KitchenController::class, 'index'])->name('kitchen.index');
    Route::put('kitchen/tickets/{ticket}/start', [KitchenController::class, 'start'])->name('kitchen.tickets.start');
    Route::put('kitchen/tickets/{ticket}/ready', [KitchenController::class, 'ready'])->name('kitchen.tickets.ready');
    Route::put('kitchen/tickets/{ticket}/serve', [KitchenController::class, 'serve'])->name('kitchen.tickets.serve');
    Route::put('kitchen/tickets/{ticket}/recall', [KitchenController::class, 'recall'])->name('kitchen.tickets.recall');
    Route::post('kitchen/tickets/{ticket}/reprint', [KitchenController::class, 'reprint'])->middleware('throttle:30,1')->name('kitchen.tickets.reprint');

    // Printing from this device (print agent) + the print queue
    Route::get('print-jobs', [PrintJobController::class, 'index'])->name('print-jobs.index');
    Route::get('print-jobs/pending', [PrintJobController::class, 'pending'])->name('print-jobs.pending');
    Route::post('print-jobs/{job}/claim', [PrintJobController::class, 'claim'])->name('print-jobs.claim');
    Route::get('print-jobs/{job}', [PrintJobController::class, 'show'])->name('print-jobs.show');
    Route::post('print-jobs/{job}/done', [PrintJobController::class, 'done'])->name('print-jobs.done');
    Route::post('print-jobs/{job}/failed', [PrintJobController::class, 'failed'])->name('print-jobs.failed');
    Route::post('print-jobs/{job}/retry', [PrintJobController::class, 'retry'])->middleware('throttle:30,1')->name('print-jobs.retry');
    Route::get('qz/certificate', [QzController::class, 'certificate'])->name('qz.certificate');
    Route::post('qz/sign', [QzController::class, 'sign'])->middleware('throttle:120,1')->name('qz.sign');

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

    // Suppliers, purchases, waste, stock counts, consumption review, low stock
    Route::resource('suppliers', SupplierController::class)->only(['index', 'show', 'store', 'update', 'destroy']);
    Route::post('suppliers/{supplier}/restore', [SupplierController::class, 'restore'])->withTrashed()->name('suppliers.restore');
    Route::post('suppliers/{supplier}/payments', [SupplierController::class, 'pay'])->middleware('throttle:30,1')->name('suppliers.pay');
    Route::get('purchases', [PurchaseController::class, 'index'])->name('purchases.index');
    Route::get('purchases/create', [PurchaseController::class, 'create'])->name('purchases.create');
    Route::post('purchases', [PurchaseController::class, 'store'])->name('purchases.store');
    Route::get('purchases/{purchase}', [PurchaseController::class, 'show'])->name('purchases.show');
    Route::post('purchases/{purchase}/returns', [PurchaseController::class, 'returnItems'])->name('purchases.return');
    Route::get('waste', [WasteController::class, 'index'])->name('waste.index');
    Route::post('waste', [WasteController::class, 'store'])->name('waste.store');
    Route::get('stock-counts', [StockCountController::class, 'index'])->name('stock-counts.index');
    Route::post('stock-counts', [StockCountController::class, 'store'])->name('stock-counts.store');
    Route::get('stock-counts/{stock_count}', [StockCountController::class, 'show'])->name('stock-counts.show');
    Route::put('stock-counts/{stock_count}', [StockCountController::class, 'update'])->name('stock-counts.update');
    Route::put('stock-counts/{stock_count}/approve', [StockCountController::class, 'approve'])->name('stock-counts.approve');
    Route::put('stock-counts/{stock_count}/cancel', [StockCountController::class, 'cancel'])->name('stock-counts.cancel');
    Route::get('consumptions', [ConsumptionController::class, 'index'])->name('consumptions.pending');
    Route::post('consumptions/confirm', [ConsumptionController::class, 'confirm'])->name('consumptions.confirm');
    Route::put('consumptions/{item}', [ConsumptionController::class, 'adjust'])->name('consumptions.adjust');
    Route::get('low-stock', [LowStockController::class, 'index'])->name('low-stock.index');

    // Reservations & expenses
    Route::get('reservations', [ReservationController::class, 'index'])->name('reservations.index');
    Route::post('reservations', [ReservationController::class, 'store'])->name('reservations.store');
    Route::put('reservations/{reservation}', [ReservationController::class, 'update'])->name('reservations.update');
    Route::put('reservations/{reservation}/status', [ReservationController::class, 'status'])->name('reservations.status');
    Route::get('expenses', [ExpenseController::class, 'index'])->name('expenses.index');
    Route::post('expenses', [ExpenseController::class, 'store'])->middleware('throttle:30,1')->name('expenses.store');
    Route::put('expenses/{expense}/void', [ExpenseController::class, 'void'])->name('expenses.void');
    Route::post('expense-categories', [ExpenseController::class, 'storeCategory'])->name('expense-categories.store');
    Route::put('expense-categories/{expense_category}', [ExpenseController::class, 'updateCategory'])->name('expense-categories.update');
    Route::delete('expense-categories/{expense_category}', [ExpenseController::class, 'destroyCategory'])->name('expense-categories.destroy');
    Route::post('expense-categories/{expense_category}/restore', [ExpenseController::class, 'restoreCategory'])->withTrashed()->name('expense-categories.restore');

    // Dashboard figures (polled every minute) & reports — one permission per report group
    Route::get('dashboard/stats', [DashboardController::class, 'stats'])->middleware('throttle:60,1')->name('dashboard.stats');
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    foreach (array_keys(Report::GROUPS) as $reportGroup) {
        Route::get("reports/{$reportGroup}/{report}", [ReportController::class, 'show'])->defaults('group', $reportGroup)->name("reports.{$reportGroup}");
    }
    Route::get('reports/{group}/{report}/export', [ReportController::class, 'export'])->middleware('throttle:30,1')->name('reports.export');

    // People
    Route::resource('customers', CustomerController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('customers/{customer}/restore', [CustomerController::class, 'restore'])->withTrashed()->name('customers.restore');

    Route::resource('employees', EmployeeController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('employees/{employee}/restore', [EmployeeController::class, 'restore'])->withTrashed()->name('employees.restore');

    Route::resource('designations', DesignationController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('designations/{designation}/restore', [DesignationController::class, 'restore'])->withTrashed()->name('designations.restore');

    // Branch setup
    Route::get('shifts', [ShiftController::class, 'index'])->name('shifts.index');
    Route::post('shifts', [ShiftController::class, 'open'])->name('shifts.open');
    Route::get('shifts/{shift}', [ShiftController::class, 'show'])->name('shifts.show');
    Route::get('shifts/{shift}/report', [ShiftController::class, 'report'])->name('shifts.report');
    Route::post('shifts/{shift}/cash', [ShiftController::class, 'cash'])->name('shifts.cash');
    Route::put('shifts/{shift}/close', [ShiftController::class, 'close'])->middleware('throttle:30,1')->name('shifts.close');
    Route::put('shifts/{shift}/reopen', [ShiftController::class, 'reopen'])->middleware('throttle:30,1')->name('shifts.reopen');
    Route::post('shifts/{shift}/staff', [ShiftController::class, 'addStaff'])->name('shifts.staff.store');
    Route::scopeBindings()->group(function () {
        Route::put('shifts/{shift}/staff/{staff}/check-out', [ShiftController::class, 'checkOut'])->name('shifts.staff.checkout');
        Route::delete('shifts/{shift}/staff/{staff}', [ShiftController::class, 'removeStaff'])->name('shifts.staff.destroy');
    });

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
