<?php

use App\Models\Branch;
use App\Support\CurrentBranch;
use Illuminate\Support\Str;
use Tests\Fixtures\Models\FixtureBranchRecord;
use Tests\Fixtures\Models\FixtureParent;

it('gives every record a v7 uuid that never changes', function () {
    $parent = FixtureParent::create(['name' => 'A']);
    $uuid = $parent->uuid;

    expect(Str::isUuid($uuid))->toBeTrue()
        ->and($uuid[14])->toBe('7');

    $parent->update(['uuid' => (string) Str::uuid7(), 'name' => 'B']);

    expect($parent->fresh()->uuid)->toBe($uuid)
        ->and($parent->getRouteKeyName())->toBe('uuid')
        ->and(FixtureParent::findByUuidOrFail($uuid)->is($parent))->toBeTrue();
});

it('hides the numeric id and foreign keys from arrays and json', function () {
    $branch = Branch::factory()->create();
    $parent = FixtureParent::create(['name' => 'A', 'branch_id' => $branch->id]);
    $child = $parent->children()->create(['name' => 'C']);

    expect($parent->toArray())->not->toHaveKeys(['id', 'branch_id'])->toHaveKey('uuid')
        ->and($child->toArray())->not->toHaveKeys(['id', 'fixture_parent_id']);
});

it('fills branch_id from the current branch and scopes queries to it', function () {
    [$a, $b] = Branch::factory()->count(2)->create();
    $current = app(CurrentBranch::class);

    $current->actingAs($a, fn () => FixtureBranchRecord::create(['name' => 'In A']));
    $current->actingAs($b, fn () => FixtureBranchRecord::create(['name' => 'In B']));

    expect($current->actingAs($a, fn () => FixtureBranchRecord::pluck('name')->all()))->toBe(['In A'])
        ->and($current->actingAs($b, fn () => FixtureBranchRecord::pluck('name')->all()))->toBe(['In B'])
        ->and(FixtureBranchRecord::allBranches()->count())->toBe(2)
        ->and(FixtureBranchRecord::forBranch($b)->pluck('name')->all())->toBe(['In B']);
});

it('returns nothing when a signed-in request has no branch', function () {
    $branch = Branch::factory()->create();
    app(CurrentBranch::class)->actingAs($branch, fn () => FixtureBranchRecord::create(['name' => 'X']));

    app(CurrentBranch::class)->enforce();

    expect(FixtureBranchRecord::count())->toBe(0);
});

it('never moves a record to another branch', function () {
    [$a, $b] = Branch::factory()->count(2)->create();
    $record = app(CurrentBranch::class)->actingAs($a, fn () => FixtureBranchRecord::create(['name' => 'X']));

    $record->update(['branch_id' => $b->id]);
})->throws(LogicException::class, 'cannot be moved');

it('refuses to create branch data without a branch', function () {
    FixtureBranchRecord::create(['name' => 'Orphan']);
})->throws(LogicException::class, 'No current branch');
