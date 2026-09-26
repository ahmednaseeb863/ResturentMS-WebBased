<?php

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\HasPublicUuid;
use App\Models\Concerns\NeverDeleted;
use App\Models\Concerns\Trashable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * Project rules (CLAUDE.md §1–2) checked for every model in app/Models:
 * nothing can be hard-deleted, and every table with a uuid uses HasPublicUuid.
 */

function appModels(): array
{
    return collect(glob(app_path('Models/*.php')))
        ->map(fn (string $file) => 'App\\Models\\'.Str::before(basename($file), '.php'))
        ->filter(fn (string $class) => is_subclass_of($class, Model::class))
        ->values()
        ->all();
}

it('makes every model trashable, append-only or never-deleted', function () {
    foreach (appModels() as $class) {
        $traits = class_uses_recursive($class);

        expect(in_array(Trashable::class, $traits, true) || in_array(AppendOnly::class, $traits, true) || in_array(NeverDeleted::class, $traits, true))
            ->toBeTrue("{$class} must use Trashable (AppendOnly for ledgers, NeverDeleted for shifts / orders)");
    }
});

it('gives every model with a uuid column the HasPublicUuid trait', function () {
    foreach (appModels() as $class) {
        $table = (new $class)->getTable();

        if (Schema::hasColumn($table, 'uuid')) {
            expect(in_array(HasPublicUuid::class, class_uses_recursive($class), true))
                ->toBeTrue("{$class} needs HasPublicUuid");
        }
    }
});

it('never uses Laravel SoftDeletes', function () {
    foreach (appModels() as $class) {
        expect(in_array(SoftDeletes::class, class_uses_recursive($class), true))
            ->toBeFalse("{$class} must not use SoftDeletes");
    }
});
