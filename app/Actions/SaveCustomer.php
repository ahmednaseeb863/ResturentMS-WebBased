<?php

namespace App\Actions;

use App\Http\Requests\CustomerRequest;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Support\Activity;
use Illuminate\Support\Facades\DB;

/** Saves a customer and syncs their addresses (removed ones are trashed, never deleted). */
class SaveCustomer
{
    public function handle(CustomerRequest $request, ?Customer $customer = null): Customer
    {
        return DB::transaction(function () use ($request, $customer) {
            $customer ??= new Customer;
            $customer->fill($request->customerData())->save();

            $this->syncAddresses($customer, $request->addresses());

            return $customer;
        });
    }

    private function syncAddresses(Customer $customer, array $addresses): void
    {
        $existing = $customer->addresses()->get()->keyBy('uuid');
        $keep = [];
        $changes = ['added' => [], 'updated' => [], 'removed' => []];

        foreach ($addresses as $data) {
            $address = $data['id'] ? $existing->get($data['id']) : null;
            $fields = collect($data)->except('id')->all();

            if ($address) {
                $address->fill($fields);
                if ($address->isDirty()) {
                    $address->save();
                    $changes['updated'][] = $address->trashLabel();
                }
                $keep[] = $address->uuid;
            } else {
                $address = CustomerAddress::create([...$fields, 'user_id' => $customer->id]);
                $changes['added'][] = $address->trashLabel();
            }
        }

        foreach ($existing->except($keep) as $address) {
            $address->trash();
            $changes['removed'][] = $address->trashLabel();
        }

        $changes = array_filter($changes);

        // a new customer's "created" entry is enough
        if ($changes && ! $customer->wasRecentlyCreated) {
            Activity::log('addresses', $customer, $changes);
        }
    }
}
