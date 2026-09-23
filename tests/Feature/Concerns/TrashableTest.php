<?php

use App\Exceptions\PermanentDeleteNotAllowed;
use App\Exceptions\TrashNotAllowed;
use App\Models\ActivityLog;
use Tests\Fixtures\Models\FixtureChild;
use Tests\Fixtures\Models\FixtureParent;

function parentWithChildren(int $children = 2, array $attributes = []): FixtureParent
{
    $parent = FixtureParent::create(['name' => 'Parent', ...$attributes]);
    for ($i = 1; $i <= $children; $i++) {
        $parent->children()->create(['name' => "Child {$i}"]);
    }

    return $parent;
}

it('trashes a record: fills the columns and hides it by default', function () {
    $admin = loginSuperAdmin();
    $parent = parentWithChildren(0);

    $parent->trash('Duplicate');

    $parent->refresh();
    expect($parent->isTrashed())->toBeTrue()
        ->and($parent->deleted_by)->toBe($admin->id)
        ->and($parent->delete_reason)->toBe('Duplicate')
        ->and($parent->trash_batch)->not->toBeNull()
        ->and(FixtureParent::count())->toBe(0)
        ->and(FixtureParent::withTrashed()->count())->toBe(1)
        ->and(FixtureParent::onlyTrashed()->count())->toBe(1)
        ->and(FixtureParent::withoutTrashed()->count())->toBe(0);

    $this->assertDatabaseHas('fixture_parents', ['id' => $parent->id]); // never removed
});

it('cascades to children with the same batch and restores them together', function () {
    $parent = parentWithChildren(2);
    $parent->trash();

    expect(FixtureChild::count())->toBe(0)
        ->and(FixtureChild::onlyTrashed()->pluck('trash_batch')->unique()->all())->toBe([$parent->fresh()->trash_batch]);

    $parent->fresh()->restoreFromTrash();

    expect(FixtureChild::count())->toBe(2)
        ->and(FixtureParent::first()->deleted_at)->toBeNull();
});

it('does not bring back children that were trashed separately', function () {
    $parent = parentWithChildren(2);
    $parent->children()->first()->trash('Wrong item');
    $parent->trash();

    $parent->fresh()->restoreFromTrash();

    expect(FixtureChild::count())->toBe(1)
        ->and(FixtureChild::onlyTrashed()->first()->delete_reason)->toBe('Wrong item');
});

it('refuses to restore a child while its parent is trashed', function () {
    $parent = parentWithChildren(1);
    $parent->trash();

    FixtureChild::onlyTrashed()->first()->restoreFromTrash();
})->throws(TrashNotAllowed::class, 'first');

it('applies the model business rule before trashing', function () {
    $parent = parentWithChildren(1, ['locked' => true]);

    expect(fn () => $parent->trash())->toThrow(TrashNotAllowed::class, 'This record is locked.');
    expect(FixtureParent::count())->toBe(1)->and(FixtureChild::count())->toBe(1);
});

it('blocks every hard delete path', function (string $path) {
    $parent = parentWithChildren(1);

    $delete = match ($path) {
        'delete' => fn () => $parent->delete(),
        'forceDelete' => fn () => $parent->forceDelete(),
        'destroy' => fn () => FixtureParent::destroy($parent->id),
        'bulk delete' => fn () => FixtureParent::query()->delete(),
        'bulk forceDelete' => fn () => FixtureParent::query()->forceDelete(),
        'relation delete' => fn () => $parent->children()->delete(),
    };

    expect($delete)->toThrow(PermanentDeleteNotAllowed::class);
    $this->assertDatabaseHas('fixture_parents', ['id' => $parent->id, 'deleted_at' => null]);
    $this->assertDatabaseCount('fixture_children', 1);
})->with(['delete', 'forceDelete', 'destroy', 'bulk delete', 'bulk forceDelete', 'relation delete']);

it('trashes and restores in bulk through the model', function () {
    parentWithChildren(1);
    parentWithChildren(1);

    expect(FixtureParent::query()->trash('Cleanup'))->toBe(2)
        ->and(FixtureParent::count())->toBe(0)
        ->and(FixtureChild::count())->toBe(0);

    expect(FixtureParent::onlyTrashed()->restoreFromTrash())->toBe(2)
        ->and(FixtureChild::count())->toBe(2);
});

it('writes trash and restore to the activity log', function () {
    loginSuperAdmin();
    $parent = parentWithChildren(0);

    $parent->trash('Old');
    $parent->restoreFromTrash();

    expect(ActivityLog::where('subject_type', FixtureParent::class)->pluck('event')->all())
        ->toBe(['trashed', 'restored'])
        ->and(ActivityLog::where('event', 'trashed')->first()->properties)->toBe(['reason' => 'Old']);
});
