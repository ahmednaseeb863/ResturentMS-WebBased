<?php

use App\Exceptions\PermanentDeleteNotAllowed;
use App\Models\Branch;
use Inertia\Testing\AssertableInertia as Assert;

it('lists branches with active and trash tabs', function () {
    loginSuperAdmin();
    Branch::factory()->create(['name' => 'Gulberg', 'code' => 'GUL']);
    Branch::factory()->create(['name' => 'DHA', 'code' => 'DHA'])->trash('Closed');

    $this->get(route('branches.index', ['search' => 'gul']))->assertInertia(fn (Assert $page) => $page
        ->component('branches/Index')
        ->has('branches.data', 1)
        ->where('branches.data.0.code', 'GUL')
        ->where('counts.trash', 1));

    $response = $this->get(route('branches.index', ['tab' => 'trash']))->assertInertia(fn (Assert $page) => $page
        ->where('filters.tab', 'trash')
        ->has('branches.data', 1)
        ->where('branches.data.0.delete_reason', 'Closed')
        ->whereType('branches.data.0.deleted_by', 'string'));

    expectNoNumericIds($response->inertiaProps());
});

it('adds and edits a branch', function () {
    loginSuperAdmin();

    $this->post(route('branches.store'), ['code' => 'gul', 'name' => 'Gulberg', 'is_active' => true])
        ->assertSessionHasNoErrors();
    $branch = Branch::where('code', 'GUL')->firstOrFail();

    $this->put(route('branches.update', $branch), ['code' => 'GUL', 'name' => 'Gulberg III', 'is_active' => true])
        ->assertSessionHasNoErrors();

    expect($branch->fresh()->name)->toBe('Gulberg III');
});

it('uses uuids in urls, never ids', function () {
    loginSuperAdmin();
    $branch = Branch::factory()->create();

    expect(route('branches.update', $branch))->toContain($branch->uuid)->not->toEndWith("/{$branch->id}");
    $this->put("/branches/{$branch->id}", ['code' => 'X', 'name' => 'X'])->assertNotFound();
});

it('checks unique codes against trashed branches too', function () {
    loginSuperAdmin();
    Branch::factory()->create(['code' => 'OLD'])->trash('Closed');

    $this->post(route('branches.store'), ['code' => 'OLD', 'name' => 'Again'])
        ->assertSessionHasErrors(['code' => 'A branch in the trash already uses this code — restore it from the Trash tab instead.']);
});

it('moves a branch to trash with a reason and restores it', function () {
    loginSuperAdmin();
    $branch = Branch::factory()->create();

    $this->delete(route('branches.destroy', $branch))->assertSessionHasErrors('reason');

    $this->delete(route('branches.destroy', $branch), ['reason' => 'Closed down'])->assertSessionHas('success');
    expect($branch->fresh()->isTrashed())->toBeTrue();

    $this->post(route('branches.restore', $branch))->assertSessionHas('success');
    expect($branch->fresh()->isTrashed())->toBeFalse();
});

it('refuses to trash or deactivate the only active branch', function () {
    loginSuperAdmin();
    $only = Branch::first();

    $this->delete(route('branches.destroy', $only), ['reason' => 'x'])->assertSessionHas('error');
    $this->put(route('branches.update', $only), ['code' => $only->code, 'name' => $only->name, 'is_active' => false])
        ->assertSessionHasErrors('is_active');

    expect($only->fresh()->isTrashed())->toBeFalse();
});

it('can never be hard deleted', function () {
    Branch::factory()->create()->delete();
})->throws(PermanentDeleteNotAllowed::class);
