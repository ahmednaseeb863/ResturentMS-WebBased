<?php

use App\Exceptions\PermanentDeleteNotAllowed;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerAddress;
use Inertia\Testing\AssertableInertia as Assert;

it('lists customers with addresses, without ids', function () {
    loginSuperAdmin();
    Customer::factory()->withAddress(['area' => 'Gulberg'])->create(['name' => 'Zara Ahmed', 'phone' => '03001234567']);
    Customer::factory()->create();

    $response = $this->get(route('customers.index', ['search' => '0300-123']))->assertInertia(fn (Assert $page) => $page
        ->component('customers/Index')
        ->has('customers.data', 1)
        ->where('customers.data.0.name', 'Zara Ahmed')
        ->where('customers.data.0.addresses.0.area', 'Gulberg'));

    expectNoNumericIds($response->inertiaProps());
});

it('adds a customer with a normalized phone and addresses', function () {
    loginSuperAdmin();

    $this->post(route('customers.store'), [
        'name' => 'Bilal Farooq',
        'phone' => '+92 300-765 4321',
        'addresses' => [
            ['label' => 'Home', 'address' => 'House 1, Street 2', 'area' => 'DHA'],
            ['label' => 'Office', 'address' => 'Plaza 5', 'is_default' => true],
        ],
    ])->assertSessionHasNoErrors();

    $customer = Customer::where('phone', '+923007654321')->firstOrFail();
    expect($customer->addresses)->toHaveCount(2)
        ->and($customer->addresses->firstWhere('is_default', true)->label)->toBe('Office');
});

it('checks phone uniqueness against trashed customers too', function () {
    loginSuperAdmin();
    Customer::factory()->create(['phone' => '03001112222'])->trash();

    $this->post(route('customers.store'), ['name' => 'X', 'phone' => '0300-111-2222'])
        ->assertSessionHasErrors(['phone' => 'A customer in the trash already uses this phone — restore it from the Trash tab instead.']);
});

it('trashes removed addresses instead of deleting them', function () {
    loginSuperAdmin();
    $customer = Customer::factory()->withAddress(['label' => 'Home'])->create();
    $address = $customer->addresses()->first();

    $this->put(route('customers.update', $customer), [
        'name' => $customer->name,
        'phone' => $customer->phone,
        'addresses' => [['address' => 'New place', 'label' => 'Office']],
    ])->assertSessionHasNoErrors();

    expect($address->fresh()->isTrashed())->toBeTrue()
        ->and(CustomerAddress::withTrashed()->count())->toBe(2)
        ->and($customer->addresses()->pluck('label')->all())->toBe(['Office'])
        ->and(ActivityLog::where('event', 'addresses')->first()->properties)->toBe(['added' => ['Office'], 'removed' => ['Home']]);
});

it('rejects address uuids of another customer', function () {
    loginSuperAdmin();
    $customer = Customer::factory()->create();
    $foreign = Customer::factory()->withAddress()->create()->addresses()->first();

    $this->put(route('customers.update', $customer), [
        'name' => $customer->name,
        'phone' => $customer->phone,
        'addresses' => [['id' => $foreign->uuid, 'address' => 'Hijack']],
    ])->assertSessionHasErrors('addresses.0.address');

    expect($foreign->fresh()->address)->not->toBe('Hijack');
});

it('trashes a customer with their addresses and restores them together', function () {
    loginSuperAdmin();
    $customer = Customer::factory()->withAddress()->create();

    $this->delete(route('customers.destroy', $customer))->assertSessionHas('success');
    expect($customer->fresh()->isTrashed())->toBeTrue()
        ->and(CustomerAddress::onlyTrashed()->count())->toBe(1);

    $this->post(route('customers.restore', $customer))->assertSessionHas('success');
    expect($customer->fresh()->isTrashed())->toBeFalse()
        ->and($customer->addresses()->count())->toBe(1)
        ->and(fn () => $customer->delete())->toThrow(PermanentDeleteNotAllowed::class);
});

it('needs the customers permission', function () {
    loginAdminWithRoutes(['customers.index']);

    $this->get(route('customers.index'))->assertOk();
    $this->post(route('customers.store'), ['name' => 'X', 'phone' => '03000000000'])->assertForbidden();
});
