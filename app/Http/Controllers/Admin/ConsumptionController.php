<?php

namespace App\Http\Controllers\Admin;

use App\Actions\AdjustConsumption;
use App\Actions\AutoConfirmConsumption;
use App\Actions\ConfirmConsumption;
use App\Enums\ConsumptionStatus;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ConsumptionReviewRequest;
use App\Models\OrderItem;
use App\Models\OrderItemConsumption;
use App\Models\RawMaterial;
use App\Support\BusinessDate;
use App\Support\Qty;
use App\Support\RecipeUse;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pending consumption (PLAN §4.16): order lines whose raw materials nobody confirmed yet
 * (kitchens working from printed tickets) — confirm them at the recipe or adjusted
 * quantities — and the lines auto-confirmed lately, which a manager can correct.
 */
class ConsumptionController extends Controller
{
    public function index(Request $request): Response
    {
        $view = $request->query('view') === 'auto' ? 'auto' : 'pending';
        $since = CarbonImmutable::parse(BusinessDate::for())->subDays(7)->toDateString();

        $pending = AutoConfirmConsumption::pending()
            ->whereHas('order', fn ($q) => $q->whereNotIn('status', [OrderStatus::Draft, OrderStatus::Cancelled]))
            ->with('order', 'modifiers', 'variant', 'sellable')
            ->latest('id')->limit(200)->get()
            ->map(fn (OrderItem $item) => [
                ...$this->row($item),
                'kitchen' => $item->kitchen_status?->label(),
                'materials' => ConfirmConsumption::plan($item),
            ])
            ->filter(fn ($row) => $row['materials'] !== [])
            ->values();

        $auto = $view === 'auto'
            ? OrderItem::query()->where('consumption_status', ConsumptionStatus::AutoConfirmed)
                ->whereHas('order', fn ($q) => $q->whereDate('business_date', '>=', $since))
                ->with(['order', 'modifiers', 'consumptions.rawMaterial', 'consumptions.unit'])
                ->latest('id')->limit(200)->get()
                ->map(fn (OrderItem $item) => [...$this->row($item), 'materials' => $this->used($item)])
                ->values()
            : [];

        return Inertia::render('consumptions/Index', [
            'view' => $view,
            'pending' => $pending,
            'auto' => $auto,
            'pendingCount' => $pending->count(),
            'rule' => setting('inventory.auto_confirm_consumption'),
            'materials' => fn () => RawMaterial::query()->active()->with('stockUnit')->orderBy('name')->get()
                ->map(fn (RawMaterial $m) => ['id' => $m->uuid, 'name' => $m->name, 'unit' => $m->stockUnit?->short_name])->all(),
        ]);
    }

    /** Confirm pending lines: with the quantities given, or at the recipe (`items`). */
    public function confirm(ConsumptionReviewRequest $request, ConfirmConsumption $confirm): RedirectResponse
    {
        $given = $request->consumption();
        $uuids = array_values(array_unique([...array_keys($given), ...($request->validated('items') ?? [])]));
        $lines = ConsumptionReviewRequest::lines($uuids);
        $admin = $request->user('admin');

        DB::transaction(function () use ($uuids, $lines, $given, $confirm, $admin) {
            foreach ($uuids as $uuid) {
                $item = $lines->get($uuid) ?? throw ValidationException::withMessages(['consumption' => 'That order line no longer exists — reload the page.']);
                if ($item->consumption_status !== ConsumptionStatus::Pending) {
                    throw ValidationException::withMessages(['consumption' => "{$item->fullName()} is already confirmed."]);
                }
                // a manager's "confirm at the recipe" is still a person confirming, not automatic
                $confirm->handle($item, $given[$uuid] ?? $this->atRecipe($item), $admin);
            }
        });

        $n = count($uuids);

        return back()->with('success', $n === 1 ? 'Raw materials confirmed.' : "Raw materials of {$n} lines confirmed.");
    }

    /** Correct what an auto-confirmed / confirmed line used. */
    public function adjust(ConsumptionReviewRequest $request, OrderItem $item, AdjustConsumption $adjust): RedirectResponse
    {
        $given = $request->consumption();
        abort_unless($item->order()->exists(), 404);

        $changes = $adjust->handle($item, $given[$item->uuid] ?? [], $request->user('admin'));

        return back()->with('success', $changes === 1 ? '1 raw material corrected.' : "{$changes} raw materials corrected.");
    }

    private function row(OrderItem $item): array
    {
        return [
            'id' => $item->uuid,
            'name' => $item->fullName(),
            'quantity' => $item->quantity,
            'extras' => $item->modifiers->pluck('name')->all(),
            'order' => ['id' => $item->order->uuid, 'code' => $item->order->code(), 'status' => $item->order->status->label()],
            'sent_at' => $item->sent_at?->format(DATE_ATOM),
        ];
    }

    /** Recipe quantities as a "cook's answer" (the recipe unit of each material). */
    private function atRecipe(OrderItem $item): array
    {
        return RecipeUse::of($item)->map(fn ($use) => [
            'material' => $use['material'],
            'quantity' => $use['quantity'],
            'reason' => null,
        ])->all();
    }

    /** What a confirmed line used, per raw material (all rows, corrections included). */
    private function used(OrderItem $item): array
    {
        return $item->consumptions->groupBy('raw_material_id')->map(function ($rows) {
            /** @var OrderItemConsumption $first */
            $first = $rows->first();
            $unit = $first->unit?->short_name;
            $expected = round((float) $rows->sum('expected_qty'), 3);
            $actual = round((float) $rows->sum('actual_qty'), 3);

            return [
                'id' => $first->rawMaterial?->uuid,
                'name' => $first->rawMaterial?->name,
                'unit' => $unit,
                'expected' => $actual, // the dialog starts from what was used
                'expected_text' => 'recipe '.Qty::format($expected)." {$unit}",
                'recipe' => $expected,
                'used' => $actual,
                'corrected' => $rows->count() > 1,
            ];
        })->values()->all();
    }
}
