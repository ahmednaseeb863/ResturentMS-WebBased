<?php

use App\Exceptions\PermanentDeleteNotAllowed;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Setting;
use App\Support\CurrentBranch;
use App\Support\Settings\SettingsRegistry;
use App\Support\Settings\SettingsResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->home = Branch::factory()->create(['name' => 'Gulberg']);
    $this->other = Branch::factory()->create(['name' => 'DHA']);
    $this->atHome = [CurrentBranch::SESSION_KEY => $this->home->id];
});

/** Full tax group payload for the global form. */
function taxForm(array $values = []): array
{
    return ['values' => ['enabled' => false, 'name' => 'GST', 'rate' => 16, ...$values]];
}

it('resolves branch override, then global, then the built-in default', function () {
    expect(setting('tax.rate', $this->home))->toBe(16);

    Setting::create(['branch_id' => null, 'group' => 'tax', 'key' => 'rate', 'value' => 17]);
    app(SettingsResolver::class)->forget(null);
    expect(setting('tax.rate', $this->home))->toBe(17);

    Setting::create(['branch_id' => $this->home->id, 'group' => 'tax', 'key' => 'rate', 'value' => 5]);
    app(SettingsResolver::class)->forget($this->home->id);

    expect(setting('tax.rate', $this->home))->toBe(5)
        ->and(setting('tax.rate', $this->other))->toBe(17)
        ->and(fn () => setting('tax.nope'))->toThrow(InvalidArgumentException::class);
});

it('saves global settings and reaches every branch that does not override', function () {
    loginSuperAdmin();
    expect(setting('tax.enabled', $this->other))->toBeFalse(); // cached now

    $this->withSession($this->atHome)
        ->put(route('settings.global.update', 'tax'), taxForm(['enabled' => true, 'rate' => '18.5']))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Tax settings saved for global.');

    expect(setting('tax.enabled', $this->other))->toBeTrue()
        ->and(setting('tax.rate', $this->other))->toBe(18.5)
        // unchanged defaults are not stored
        ->and(Setting::query()->global()->pluck('key')->sort()->values()->all())->toBe(['enabled', 'rate']);

    $log = ActivityLog::where('event', 'settings')->firstOrFail();
    expect($log->properties['attributes'])->toEqual(['Charge tax' => 'On', 'Rate' => 18.5])
        ->and($log->properties['old'])->toEqual(['Charge tax' => 'Off', 'Rate' => 16]);
});

it('overrides a field for the current branch and goes back to global by trashing the row', function () {
    loginSuperAdmin();

    $this->withSession($this->atHome)
        ->put(route('settings.branch.tax'), ['values' => ['rate' => 5], 'overrides' => ['rate']])
        ->assertSessionHasNoErrors();

    expect(setting('tax.rate', $this->home))->toBe(5)
        ->and(setting('tax.rate', $this->other))->toBe(16);

    // back to "use global"
    $this->withSession($this->atHome)->put(route('settings.branch.tax'), ['overrides' => []])->assertSessionHasNoErrors();

    $row = Setting::withTrashed()->where('branch_id', $this->home->id)->where('key', 'rate')->sole();
    expect($row->isTrashed())->toBeTrue()
        ->and(setting('tax.rate', $this->home))->toBe(16);

    // overriding again restores the same row (history kept, no duplicate)
    $this->withSession($this->atHome)
        ->put(route('settings.branch.tax'), ['values' => ['rate' => 7], 'overrides' => ['rate']])
        ->assertSessionHasNoErrors();

    expect(Setting::withTrashed()->where('branch_id', $this->home->id)->count())->toBe(1)
        ->and(setting('tax.rate', $this->home))->toBe(7)
        ->and(ActivityLog::where('event', 'settings')->count())->toBe(3);
});

it('shows branch settings with the global value and where it comes from, without ids', function () {
    loginSuperAdmin();
    Setting::create(['branch_id' => null, 'group' => 'tax', 'key' => 'rate', 'value' => 17]);
    Setting::create(['branch_id' => $this->home->id, 'group' => 'tax', 'key' => 'name', 'value' => 'PST']);

    $response = $this->withSession($this->atHome)->get(route('settings.index', ['group' => 'tax']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Index')
            ->where('scope', 'branch')
            ->where('branch.name', 'Gulberg')
            ->where('group', 'tax')
            ->has('groups', count(SettingsRegistry::groupKeys()))
            ->where('groups.2.key', 'tax')
            ->where('groups.2.sections.0.fields.1.value', 'PST')
            ->where('groups.2.sections.0.fields.1.overridden', true)
            ->where('groups.2.sections.0.fields.2.value', 17)
            ->where('groups.2.sections.0.fields.2.inherited', 17)
            ->where('groups.2.sections.0.fields.2.overridden', false));

    expectNoNumericIds($response->inertiaProps());

    $this->get(route('settings.global'))->assertInertia(fn (Assert $page) => $page
        ->where('scope', 'global')
        ->where('groups.2.sections.0.fields.1.override_count', 1));
});

it('validates values', function () {
    loginSuperAdmin();

    $this->withSession($this->atHome)
        ->put(route('settings.global.update', 'tax'), taxForm(['rate' => 120]))
        ->assertSessionHasErrors('values.rate');

    $this->put(route('settings.global.update', 'kitchen'), ['values' => ['amber_after' => 20, 'red_after' => 10, 'confirm_consumption' => true]])
        ->assertSessionHasErrors(['values.red_after' => 'Red must come after amber.']);

    $this->put(route('settings.global.update', 'nope'), [])->assertNotFound();
});

it('lets a role edit only the branch groups it was given', function () {
    loginAdminWithRoutes(['settings.index', 'settings.branch.receipt'], $this->home);

    $this->withSession($this->atHome)->get(route('settings.index'))->assertOk();
    $this->put(route('settings.branch.receipt'), ['values' => ['copies' => 2], 'overrides' => ['copies']])
        ->assertSessionHasNoErrors();
    $this->put(route('settings.branch.tax'), ['values' => ['rate' => 0], 'overrides' => ['rate']])->assertForbidden();
    $this->get(route('settings.global'))->assertForbidden();
    $this->put(route('settings.global.update', 'receipt'), [])->assertForbidden();

    expect(setting('receipt.copies', $this->home))->toBe(2);
});

it('uploads a logo and keeps the old file when it is replaced', function () {
    Storage::fake('public');
    loginSuperAdmin();

    $this->withSession($this->atHome)->post(route('settings.global.update', 'general'), [
        '_method' => 'put',
        'values' => ['business_name' => 'Karahi House', 'currency_code' => 'PKR', 'currency_symbol' => 'Rs', 'timezone' => 'Asia/Karachi', 'date_format' => 'd M Y', 'time_format' => '12h'],
        'files' => ['logo' => UploadedFile::fake()->image('logo.png')],
    ])->assertSessionHasNoErrors();

    $first = setting('general.logo');
    Storage::disk('public')->assertExists($first);
    expect(setting('general.business_name'))->toBe('Karahi House');

    // branch overrides the logo with its own file
    $this->post(route('settings.branch.general'), [
        '_method' => 'put',
        'overrides' => ['logo'],
        'files' => ['logo' => UploadedFile::fake()->image('branch.png')],
    ])->assertSessionHasNoErrors();

    expect(setting('general.logo', $this->home))->not->toBe($first)
        ->and(setting('general.logo', $this->other))->toBe($first);
    Storage::disk('public')->assertExists($first);
});

it('shares display settings with every page', function () {
    loginSuperAdmin();
    Setting::create(['branch_id' => $this->home->id, 'group' => 'general', 'key' => 'currency_symbol', 'value' => 'PKR']);

    $this->withSession($this->atHome)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('context.settings.currency_symbol', 'PKR')
        ->where('context.settings.time_format', '12h'));
});

it('never hard-deletes a setting', function () {
    $row = Setting::create(['branch_id' => null, 'group' => 'tax', 'key' => 'rate', 'value' => 17]);

    expect(fn () => $row->delete())->toThrow(PermanentDeleteNotAllowed::class);
});
