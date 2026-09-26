<?php

namespace App\Http\Controllers\Admin;

use App\Actions\CloseShift;
use App\Actions\OpenShift;
use App\Actions\RecordCashMovement;
use App\Actions\ReopenShift;
use App\Enums\CashCountType;
use App\Enums\CashMovementType;
use App\Enums\EmployeeStatus;
use App\Enums\ShiftStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\CashMovementRequest;
use App\Http\Requests\CloseShiftRequest;
use App\Http\Requests\OpenShiftRequest;
use App\Http\Requests\ShiftStaffRequest;
use App\Http\Resources\CashMovementResource;
use App\Http\Resources\ShiftEmployeeResource;
use App\Http\Resources\ShiftResource;
use App\Models\CashCounter;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\ShiftEmployee;
use App\Models\ShiftType;
use App\Support\Activity;
use App\Support\BusinessDate;
use App\Support\CurrentBranch;
use App\Support\Settings\SettingsResolver;
use App\Support\ShiftSummary;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Shifts of the current branch: open, cash in / out, close with a count, reopen, X / Z reports (PLAN §6). */
class ShiftController extends Controller
{
    private const LIST_WITH = ['counter', 'type', 'openedBy', 'closedBy'];

    public function index(Request $request): Response
    {
        $status = ShiftStatus::tryFrom((string) $request->query('status'));
        $counter = $request->filled('counter')
            ? CashCounter::query()->withTrashed()->where('uuid', $request->query('counter'))->first()
            : null;
        $from = $this->date($request->query('from'));
        $to = $this->date($request->query('to'));

        $filtered = fn (Builder $q) => $q
            ->when($counter, fn ($q) => $q->where('cash_counter_id', $counter->id))
            ->when($from, fn ($q) => $q->whereDate('business_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('business_date', '<=', $to));

        $shifts = Shift::query()->tap($filtered)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->with(self::LIST_WITH)
            ->latest('number')
            ->paginate(20)
            ->withQueryString();

        // stats of the filtered range (closed shifts)
        $closed = Shift::query()->tap($filtered)->where('status', ShiftStatus::Closed)->toBase()
            ->selectRaw('count(*) as shifts')
            ->selectRaw('coalesce(sum(case when difference > 0 then difference end), 0) as over_total')
            ->selectRaw('coalesce(sum(case when difference < 0 then difference end), 0) as short_total')
            ->selectRaw('sum(case when difference <> 0 then 1 else 0 end) as off_count')
            ->first();

        $open = Shift::query()->open()->with(self::LIST_WITH)->orderBy('number')->get();
        $admin = $request->user('admin');

        return Inertia::render('shifts/Index', [
            'shifts' => ShiftResource::collection($shifts),
            'openShifts' => ShiftResource::collection($open)->resolve(),
            'filters' => [
                'status' => $status?->value ?? '',
                'counter' => $counter?->uuid ?? '',
                'from' => $from ?? '',
                'to' => $to ?? '',
            ],
            'stats' => [
                'closed' => (int) $closed->shifts,
                'open' => $open->count(),
                'over' => (float) $closed->over_total,
                'short' => (float) $closed->short_total,
                'off' => (int) $closed->off_count,
            ],
            'statuses' => ShiftStatus::options(),
            'counterOptions' => CashCounter::query()->orderBy('name')->get()
                ->map(fn (CashCounter $c) => ['value' => $c->uuid, 'label' => $c->name]),
            'myShift' => $open->firstWhere('opened_by', $admin->id)?->uuid,
            ...$request->user('admin')->canRoute('shifts.open') ? $this->openForm($open) : [],
        ]);
    }

    public function show(Request $request, Shift $shift): Response
    {
        $shift->load([...self::LIST_WITH, 'approvedBy', 'reopenedBy']);
        $admin = $request->user('admin');
        $showCash = ShiftResource::cashVisible($shift, $admin);
        $summary = ShiftSummary::of($shift);
        $canHandle = $shift->canBeHandledBy($admin);

        $newer = Shift::query()->where('cash_counter_id', $shift->cash_counter_id)->where('number', '>', $shift->number)->exists();

        return Inertia::render('shifts/Show', [
            'shift' => (new ShiftResource($shift))->resolve(),
            'lines' => $showCash ? $summary->lines() : [],
            'movements' => CashMovementResource::collection($shift->movements()->with(['admin', 'rider'])->reorder('id', 'desc')->get())->resolve(),
            'counts' => [
                'opening' => $this->countLines($shift, CashCountType::Opening),
                'closing' => $this->countLines($shift, CashCountType::Closing),
            ],
            'staff' => ShiftEmployeeResource::collection($shift->staff()->with('employee.designation')->get())->resolve(),
            'staffOptions' => $shift->isOpen() && $canHandle ? $this->staffOptions() : [],
            'movementTypes' => CashMovementType::manualOptions(),
            'denominations' => Shift::denominations(),
            'canHandle' => $canHandle,
            'canReopen' => ! $shift->isOpen() && ! $newer,
            'rules' => [
                'require_denominations' => (bool) setting('shifts.require_denominations'),
                'blind_close' => (bool) setting('shifts.blind_close'),
                'max_difference' => (float) setting('shifts.max_difference'),
                'pin_reopen' => (bool) setting('approvals.pin_reopen_shift'),
            ],
        ]);
    }

    public function open(OpenShiftRequest $request, OpenShift $open): RedirectResponse
    {
        $shift = $open->handle(
            $request->user('admin'),
            $request->counter(),
            $request->shiftType(),
            $request->openingCash(),
            $request->countMap(),
            $request->validated('notes'),
            $request->employees()->pluck('id')->all(),
        );

        return to_route('shifts.show', $shift)->with('success', "Shift {$shift->code()} opened on “{$shift->counter->name}” with ".money($shift->opening_cash).'.');
    }

    public function cash(CashMovementRequest $request, Shift $shift, RecordCashMovement $record): RedirectResponse
    {
        $movement = $record->handle($shift, $request->type(), (float) $request->validated('amount'), $request->validated('reason'));

        return back()->with('success', "{$movement->type->label()} of ".money($movement->amount).' recorded.');
    }

    public function close(CloseShiftRequest $request, Shift $shift, CloseShift $close): RedirectResponse
    {
        $shift = $close->handle(
            $shift,
            $request->countedCash(),
            $request->countMap(),
            round((float) $request->validated('float_left'), 2),
            $request->validated('notes'),
            $request->validated('pin'),
        );

        $difference = (float) $shift->difference;
        $result = match (true) {
            $difference > 0 => 'over by '.money($difference),
            $difference < 0 => 'short by '.money(abs($difference)),
            default => 'balanced',
        };

        return to_route('shifts.show', $shift)->with('success', "Shift {$shift->code()} closed — {$result}.");
    }

    public function reopen(Request $request, Shift $shift, ReopenShift $reopen): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'pin' => ['nullable', 'digits_between:4,6'],
        ], ['reason.required' => 'Say why the shift is reopened.', 'pin.digits_between' => 'A PIN is 4 to 6 digits.']);

        $reopen->handle($shift, $data['reason'], $data['pin'] ?? null);

        return back()->with('success', "Shift {$shift->code()} reopened.");
    }

    public function report(Shift $shift, CurrentBranch $current, SettingsResolver $settings): View
    {
        $shift->load([...self::LIST_WITH, 'approvedBy', 'counter.receiptPrinter']);
        $summary = ShiftSummary::of($shift);
        $kind = $shift->isOpen() ? 'X' : 'Z';

        Activity::log('shift_report', $shift, ['report' => "{$kind}-report"]);

        return view('print.shift-report', [
            'shift' => $shift,
            'kind' => $kind,
            'lines' => $summary->lines(),
            'expected' => $shift->isOpen() ? $summary->expectedCash() : (float) $shift->expected_cash,
            'movements' => $shift->movements()->with('admin')->get(),
            'counts' => $shift->counts()->where('type', CashCountType::Closing)->get(),
            'staff' => $shift->staff()->with('employee')->get(),
            'branch' => $current->get(),
            'businessName' => $settings->get('general.business_name'),
            'paper' => $shift->counter?->receiptPrinter?->paper_width ?? 80,
        ]);
    }

    public function addStaff(ShiftStaffRequest $request, Shift $shift): RedirectResponse
    {
        $employees = $request->employees();

        foreach ($employees as $employee) {
            $shift->staff()->create(['employee_id' => $employee->id, 'checked_in_at' => now()]);
        }

        Activity::log('shift_staff', $shift, ['checked_in' => $employees->pluck('name')->all()]);

        return back()->with('success', $employees->pluck('name')->join(', ', ' and ').' checked in.');
    }

    public function checkOut(Request $request, Shift $shift, ShiftEmployee $staff): RedirectResponse
    {
        $this->authorizeStaffChange($request, $shift);

        if (! $staff->isOnDuty()) {
            return back()->with('error', "{$staff->employee->name} is already checked out.");
        }

        $staff->update(['checked_out_at' => now()]);
        Activity::log('shift_staff', $shift, ['checked_out' => [$staff->employee->name]]);

        return back()->with('success', "{$staff->employee->name} checked out.");
    }

    public function removeStaff(Request $request, Shift $shift, ShiftEmployee $staff): RedirectResponse
    {
        $this->authorizeStaffChange($request, $shift);

        $staff->trash('Added by mistake');
        Activity::log('shift_staff', $shift, ['removed' => [$staff->employee->name]]);

        return back()->with('success', "{$staff->employee->name} removed from the shift.");
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** Props of the "Open Shift" dialog: free counters with their float, types, staff, notes & coins. */
    private function openForm($open): array
    {
        $busy = $open->pluck('cash_counter_id')->all();
        $now = CarbonImmutable::now()->setTimezone(setting('general.timezone'))->format('H:i');
        $types = ShiftType::query()->active()->orderBy('start_time')->get();

        return [
            'counters' => CashCounter::query()->active()->orderBy('name')->get()
                ->map(fn (CashCounter $c) => [
                    'value' => $c->uuid,
                    'label' => $c->name,
                    'busy' => in_array($c->id, $busy, true),
                    'float' => $c->lastFloat(),
                ]),
            'shiftTypes' => $types->map(fn (ShiftType $t) => [
                'value' => $t->uuid,
                'label' => "{$t->name} · {$t->startsAt()}–{$t->endsAt()}",
            ]),
            'suggestedType' => $types->first(fn (ShiftType $t) => $t->covers($now))?->uuid,
            'staffOptions' => $this->staffOptions(),
            'denominations' => Shift::denominations(),
            'businessDate' => BusinessDate::for(),
        ];
    }

    private function staffOptions(): array
    {
        return Employee::query()->where('status', EmployeeStatus::Active)->with('designation')->orderBy('name')->get()
            ->map(fn (Employee $e) => ['value' => $e->uuid, 'label' => $e->name, 'sub' => $e->designation?->name])
            ->all();
    }

    /** @return list<array{denomination: string, quantity: int, amount: string}> */
    private function countLines(Shift $shift, CashCountType $type): array
    {
        return $shift->counts()->where('type', $type)->get()
            ->map(fn ($c) => ['denomination' => $c->denomination, 'quantity' => $c->quantity, 'amount' => $c->amount])
            ->all();
    }

    private function authorizeStaffChange(Request $request, Shift $shift): void
    {
        abort_unless($shift->canBeHandledBy($request->user('admin')), 403);

        if (! $shift->isOpen()) {
            abort(back()->with('error', "Shift {$shift->code()} is closed."));
        }
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
