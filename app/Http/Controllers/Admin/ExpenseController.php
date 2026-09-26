<?php

namespace App\Http\Controllers\Admin;

use App\Actions\RecordExpense;
use App\Actions\VoidExpense;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\ExpenseRequest;
use App\Http\Requests\SupplierPaymentRequest;
use App\Http\Resources\ExpenseResource;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Shift;
use App\Rules\UniqueWithTrash;
use App\Support\BusinessDate;
use App\Support\CurrentBranch;
use App\Support\PosMenu;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Expenses of the branch (PLAN §4.18): paid from my shift's drawer or a bank account, voided
 * instead of deleted. Categories are shared by every branch and managed from the same page.
 */
class ExpenseController extends Controller
{
    public function index(Request $request): Response
    {
        $branchId = app(CurrentBranch::class)->id();
        $today = CarbonImmutable::parse(BusinessDate::for($branchId));

        $filters = [
            'search' => trim((string) $request->query('search')),
            'category' => (string) $request->query('category'),
            'method' => PaymentMethod::tryFrom((string) $request->query('method'))?->value ?? '',
            'status' => in_array($request->query('status'), ['voided', 'all'], true) ? $request->query('status') : '',
            'from' => (string) $request->query('from'),
            'to' => (string) $request->query('to'),
        ];

        $expenses = Expense::query()
            ->with(['category', 'bankAccount', 'shift', 'createdBy', 'voidedBy'])
            ->when($filters['status'] === '', fn ($q) => $q->kept())
            ->when($filters['status'] === 'voided', fn ($q) => $q->whereNotNull('voided_at'))
            ->when($filters['category'], fn ($q, $uuid) => $q->whereHas('category', fn ($q) => $q->where('uuid', $uuid)))
            ->when($filters['method'], fn ($q, $m) => $q->where('paid_from', $m))
            ->when($filters['from'], fn ($q, $d) => $q->whereDate('business_date', '>=', $d))
            ->when($filters['to'], fn ($q, $d) => $q->whereDate('business_date', '<=', $d))
            ->when($filters['search'] !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('description', 'like', "%{$filters['search']}%")
                ->orWhere('reference_no', 'like', "%{$filters['search']}%")
                ->orWhere('number', ltrim(preg_replace('/\D/', '', $filters['search']), '0') ?: -1)))
            ->orderByDesc('business_date')->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $month = fn (CarbonImmutable $d) => Expense::query()->kept()->whereBetween('business_date', [$d->startOfMonth()->toDateString(), $d->endOfMonth()->toDateString()]);
        $top = $month($today)->toBase()->selectRaw('expense_category_id, sum(amount) as total')->groupBy('expense_category_id')->orderByDesc('total')->first();

        $shift = Shift::openFor($request->user('admin'));

        return Inertia::render('expenses/Index', [
            'expenses' => ExpenseResource::collection($expenses),
            'filters' => $filters,
            'stats' => [
                'month_label' => $today->format('M'),
                'last_month_label' => $today->subMonthNoOverflow()->format('M'),
                'this_month' => (float) $month($today)->sum('amount'),
                'last_month' => (float) $month($today->subMonthNoOverflow())->sum('amount'),
                'cash_this_month' => (float) $month($today)->where('paid_from', PaymentMethod::Cash)->sum('amount'),
                'today' => (float) Expense::query()->kept()->whereDate('business_date', $today->toDateString())->sum('amount'),
                'top_category' => $top ? ['name' => ExpenseCategory::withTrashed()->find($top->expense_category_id)?->name, 'total' => (float) $top->total] : null,
            ],
            'categories' => ExpenseCategory::query()->ordered()->withCount('expenses')->get()
                ->map(fn (ExpenseCategory $c) => ['id' => $c->uuid, 'name' => $c->name, 'is_active' => $c->is_active, 'expenses_count' => $c->expenses_count]),
            'bankAccounts' => PosMenu::bankAccounts(),
            'myShift' => $shift ? ['id' => $shift->uuid, 'code' => $shift->code()] : null,
            'today' => $today->toDateString(),
        ]);
    }

    public function store(ExpenseRequest $request, RecordExpense $record): RedirectResponse
    {
        $admin = $request->user('admin');
        $method = $request->method();
        $attachment = $request->file('attachment')?->store('expenses', 'public');

        $expense = $record->handle(
            $request->category(),
            (float) $request->validated('amount'),
            $method,
            $method === PaymentMethod::Cash ? Shift::openFor($admin) : null,
            SupplierPaymentRequest::bankOf($request),
            $request->validated('business_date'),
            trim($request->validated('description')),
            $request->validated('reference') ?: null,
            $attachment,
            $admin,
        );

        return back()->with('success', "{$expense->code()} recorded — ".money((float) $expense->amount).'.');
    }

    public function void(Request $request, Expense $expense, VoidExpense $void): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], ['reason.required' => 'Say why it is voided.']);
        $void->handle($expense, trim($data['reason']), $request->user('admin'));

        return back()->with('success', "{$expense->code()} voided.");
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $category = ExpenseCategory::create($this->categoryData($request, null));

        return back()->with('success', "Category “{$category->name}” added.");
    }

    public function updateCategory(Request $request, ExpenseCategory $expenseCategory): RedirectResponse
    {
        $expenseCategory->update($this->categoryData($request, $expenseCategory));

        return back()->with('success', "Category “{$expenseCategory->name}” saved.");
    }

    public function destroyCategory(Request $request, ExpenseCategory $expenseCategory): RedirectResponse
    {
        $expenseCategory->trash($request->input('reason'));

        return back()->with('success', "Category “{$expenseCategory->name}” moved to trash.");
    }

    public function restoreCategory(ExpenseCategory $expenseCategory): RedirectResponse
    {
        $expenseCategory->restoreFromTrash();

        return back()->with('success', "Category “{$expenseCategory->name}” restored.");
    }

    private function categoryData(Request $request, ?ExpenseCategory $category): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', new UniqueWithTrash('expense_categories', 'name', $category?->id, 'category')],
            'is_active' => ['boolean'],
        ]);

        return ['name' => trim($data['name']), 'is_active' => $request->boolean('is_active', true)];
    }
}
