<?php

namespace App\Http\Controllers\Admin;

use App\Actions\SaveCustomer;
use App\Http\Controllers\Concerns\ListsWithTrash;
use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Customers are shared by every branch (no branch scope). */
class CustomerController extends Controller
{
    use ListsWithTrash;

    public function index(Request $request): Response
    {
        $tab = $this->listTab($request, 'customers.restore');
        $search = trim((string) $request->query('search'));
        $digits = preg_replace('/\D/', '', $search);

        $customers = $this->applyTab(Customer::query(), $tab)
            ->with('addresses')
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->when($digits !== '', fn ($q) => $q->orWhere('phone', 'like', "%{$digits}%"))))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('customers/Index', [
            'customers' => CustomerResource::collection($customers),
            'filters' => ['tab' => $tab, 'search' => $search],
            'counts' => $this->tabCounts(Customer::class),
        ]);
    }

    public function store(CustomerRequest $request, SaveCustomer $save): RedirectResponse
    {
        $customer = $save->handle($request);

        return back()->with('success', "Customer “{$customer->name}” added.");
    }

    public function update(CustomerRequest $request, Customer $customer, SaveCustomer $save): RedirectResponse
    {
        $save->handle($request, $customer);

        return back()->with('success', "Customer “{$customer->name}” saved.");
    }

    public function destroy(Request $request, Customer $customer): RedirectResponse
    {
        $customer->trash($this->trashReason($request));

        return back()->with('success', "Customer “{$customer->name}” moved to trash.");
    }

    public function restore(Customer $customer): RedirectResponse
    {
        $customer->restoreFromTrash();

        return back()->with('success', "Customer “{$customer->name}” restored.");
    }
}
