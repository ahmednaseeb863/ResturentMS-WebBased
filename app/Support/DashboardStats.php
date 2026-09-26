<?php

namespace App\Support;

use App\Enums\DeliveryStatus;
use App\Enums\KitchenStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\ReservationStatus;
use App\Enums\ShiftStatus;
use App\Enums\TableStatus;
use App\Models\Branch;
use App\Models\Shift;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard's figures (PLAN §4.19) for one branch or several: today's sales and orders,
 * payments, open shifts and the cash in their drawers, rider cash, tables, kitchen queue,
 * stock alerts, reservations, expenses, supplier dues, top items, sales by hour and the last
 * 7 days. "Today" is each branch's business date. The page asks for them again every minute.
 */
class DashboardStats
{
    /** @var array<int, string> branch id => today's business date */
    private array $today = [];

    /** @param  Collection<int, Branch>  $branches */
    public function __construct(private Collection $branches)
    {
        foreach ($branches as $branch) {
            $this->today[$branch->id] = BusinessDate::for($branch->id);
        }
    }

    public function toArray(): array
    {
        $main = $this->branches->first()->id;
        $sales = $this->salesOn(0);
        $yesterday = $this->salesOn(1);
        $shifts = $this->openShifts();

        return [
            'business_date' => $this->today[$main],
            'updated_at' => now(setting('general.timezone', $main))->format('H:i:s'),
            'sales' => [
                'total' => $sales['total'],
                'orders' => $sales['orders'],
                'average' => $sales['orders'] ? round($sales['total'] / $sales['orders'], 2) : 0,
                'guests' => $sales['guests'],
                'yesterday' => $yesterday['total'],
                'change' => $yesterday['total'] > 0 ? round(($sales['total'] - $yesterday['total']) / $yesterday['total'] * 100, 1) : null,
                'by_type' => $sales['by_type'],
                'discounts' => $sales['discounts'],
                'tax' => $sales['tax'],
            ],
            'payments' => $this->payments(),
            'orders' => $this->openOrders(),
            'tables' => $this->tables(),
            'kitchen' => $this->kitchen(),
            'shifts' => $shifts,
            'cash_in_drawers' => round(array_sum(array_column($shifts, 'expected')), 2),
            'riders' => $this->riders(),
            'stock' => $this->stock(),
            'reservations' => $this->reservations(),
            'expenses_today' => (float) $this->today(DB::table('expenses'))->whereNull('voided_at')->sum('amount'),
            'supplier_dues' => (float) $this->branchScope(DB::table('purchases'))->selectRaw('coalesce(sum(total - returned_total - paid_total), 0) as due')->value('due'),
            'top_items' => $this->topItems(),
            'hourly' => $this->hourly(),
            'week' => $this->week(),
            'activity' => $this->activity(),
        ];
    }

    private function branchScope(Builder $query, string $column = 'branch_id'): Builder
    {
        return $query->whereIn($column, $this->branches->pluck('id'));
    }

    /** Rows of each branch's business date `$daysAgo` days back. */
    private function today(Builder $query, int $daysAgo = 0, string $branch = 'branch_id', string $date = 'business_date'): Builder
    {
        return $query->where(function ($q) use ($daysAgo, $branch, $date) {
            foreach ($this->today as $id => $day) {
                $q->orWhere(fn ($q) => $q->where($branch, $id)->where($date, CarbonImmutable::parse($day)->subDays($daysAgo)->toDateString()));
            }
        });
    }

    private function salesOrders(int $daysAgo = 0): Builder
    {
        return $this->today(DB::table('orders'), $daysAgo, 'orders.branch_id', 'orders.business_date')
            ->whereNotIn('orders.status', [OrderStatus::Draft->value, OrderStatus::Cancelled->value]);
    }

    private function salesOn(int $daysAgo): array
    {
        $rows = $this->salesOrders($daysAgo)
            ->selectRaw('type, count(*) as n, sum(grand_total) as total, sum(coalesce(guests, 0)) as guests, sum(discount_total) as disc, sum(tax_total) as tax')
            ->groupBy('type')
            ->get();

        return [
            'total' => round((float) $rows->sum('total'), 2),
            'orders' => (int) $rows->sum('n'),
            'guests' => (int) $rows->sum('guests'),
            'discounts' => round((float) $rows->sum('disc'), 2),
            'tax' => round((float) $rows->sum('tax'), 2),
            'by_type' => $rows->mapWithKeys(fn ($r) => [$r->type => ['orders' => (int) $r->n, 'total' => (float) $r->total]])->all(),
        ];
    }

    private function payments(): array
    {
        $sum = fn (string $table, PaymentMethod $method) => (float) $this->today(DB::table($table))->where('method', $method->value)->sum('amount');
        $cash = $sum('payments', PaymentMethod::Cash);
        $bank = $sum('payments', PaymentMethod::BankTransfer);
        $refunds = $sum('refunds', PaymentMethod::Cash) + $sum('refunds', PaymentMethod::BankTransfer);

        return [
            'cash' => round($cash, 2),
            'bank' => round($bank, 2),
            'refunds' => round($refunds, 2),
            'received' => round($cash + $bank - $refunds, 2),
            'unpaid' => (float) $this->branchScope(DB::table('orders'))
                ->whereIn('status', array_map(fn ($s) => $s->value, array_filter(OrderStatus::open(), fn ($s) => $s !== OrderStatus::Draft)))
                ->selectRaw('coalesce(sum(grand_total - paid_total), 0) as due')->value('due'),
        ];
    }

    private function openOrders(): array
    {
        $open = $this->branchScope(DB::table('orders'))
            ->whereIn('status', array_map(fn ($s) => $s->value, OrderStatus::open()))
            ->selectRaw('status, count(*) as n, sum(case when bill_requested_at is not null then 1 else 0 end) as bills')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $count = fn (OrderStatus ...$statuses) => (int) collect($statuses)->sum(fn ($s) => $open[$s->value]->n ?? 0);

        return [
            'open' => $count(...array_filter(OrderStatus::open(), fn ($s) => $s !== OrderStatus::Draft)),
            'held' => $count(OrderStatus::Draft),
            'cooking' => $count(OrderStatus::Placed, OrderStatus::Preparing),
            'ready' => $count(OrderStatus::Ready),
            'on_the_way' => $count(OrderStatus::OutForDelivery),
            'bills' => (int) $open->sum('bills'),
        ];
    }

    private function tables(): array
    {
        $tables = $this->branchScope(DB::table('tables'))->whereNull('deleted_at')->where('is_active', true)
            ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return [
            'total' => (int) $tables->sum(),
            'occupied' => (int) ($tables[TableStatus::Occupied->value] ?? 0),
            'reserved' => (int) ($tables[TableStatus::Reserved->value] ?? 0),
            'cleaning' => (int) ($tables[TableStatus::Cleaning->value] ?? 0),
        ];
    }

    private function kitchen(): array
    {
        $lines = $this->branchScope(DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id'), 'orders.branch_id')
            ->whereNull('order_items.voided_at')
            ->whereIn('order_items.kitchen_status', [KitchenStatus::Pending->value, KitchenStatus::Preparing->value, KitchenStatus::Ready->value])
            ->selectRaw('order_items.kitchen_status as s, sum(order_items.quantity) as n, min(order_items.sent_at) as oldest')
            ->groupBy('order_items.kitchen_status')
            ->get()
            ->keyBy('s');

        $waiting = collect([KitchenStatus::Pending, KitchenStatus::Preparing])->map(fn ($s) => $lines[$s->value]->oldest ?? null)->filter()->min();

        return [
            'queued' => (int) ($lines[KitchenStatus::Pending->value]->n ?? 0),
            'cooking' => (int) ($lines[KitchenStatus::Preparing->value]->n ?? 0),
            'ready' => (int) ($lines[KitchenStatus::Ready->value]->n ?? 0),
            'oldest_minutes' => $waiting ? (int) CarbonImmutable::parse($waiting, 'UTC')->diffInMinutes(now(), true) : null,
            'pending_consumption' => (int) $this->branchScope(DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id'), 'orders.branch_id')
                ->whereNull('order_items.voided_at')->where('order_items.consumption_status', 'pending')->count(),
        ];
    }

    /** @return list<array{counter: string, cashier: string, code: string, opened: string, expected: float, overdue: bool, branch: string}> */
    private function openShifts(): array
    {
        return Shift::query()->withoutGlobalScope('branch')
            ->whereIn('branch_id', $this->branches->pluck('id'))
            ->where('status', ShiftStatus::Open)
            ->with(['counter', 'openedBy', 'type'])
            ->orderBy('opened_at')
            ->get()
            ->map(fn (Shift $s) => [
                'code' => $s->code(),
                'counter' => $s->counter?->name ?? '—',
                'type' => $s->type?->name,
                'cashier' => $s->openedBy?->name ?? '—',
                'opened' => $s->opened_at->setTimezone(setting('general.timezone', $s->branch_id))->format('H:i'),
                'expected' => ShiftSummary::of($s)->expectedCash(),
                'overdue' => $s->isOverdue(),
                'branch' => $this->branches->firstWhere('id', $s->branch_id)?->name,
            ])
            ->all();
    }

    private function riders(): array
    {
        $deliveries = $this->branchScope(DB::table('deliveries'));

        return [
            'cash_held' => round((float) (clone $deliveries)->where('status', DeliveryStatus::Delivered->value)->whereNull('settled_at')->sum('cash_collected'), 2),
            'active' => (int) (clone $deliveries)->whereIn('status', array_map(fn ($s) => $s->value, DeliveryStatus::active()))->count(),
            'out' => (int) (clone $deliveries)->where('status', DeliveryStatus::OutForDelivery->value)->count(),
            'unassigned' => (int) (clone $deliveries)->where('status', DeliveryStatus::Pending->value)->count(),
        ];
    }

    private function stock(): array
    {
        $low = fn (string $table, string $kind) => $this->branchScope(DB::table("{$table} as i"), 'i.branch_id')
            ->whereNull('i.deleted_at')->where('i.is_active', true)
            ->whereNotNull('i.alert_level')->whereColumn('i.current_stock', '<=', 'i.alert_level')
            ->leftJoin('units as u', 'u.id', '=', 'i.stock_unit_id')
            ->selectRaw("i.name, i.current_stock, i.alert_level, u.short_name as unit, '{$kind}' as kind")
            ->get();

        $items = $low('raw_materials', 'Raw material')->concat($low('ready_items', 'Ready item'))
            ->sortBy(fn ($i) => (float) $i->alert_level > 0 ? (float) $i->current_stock / (float) $i->alert_level : 0)
            ->values();

        return [
            'low' => $items->count(),
            'out' => $items->filter(fn ($i) => (float) $i->current_stock <= 0)->count(),
            'items' => $items->take(6)->map(fn ($i) => [
                'name' => $i->name,
                'stock' => (float) $i->current_stock,
                'alert' => (float) $i->alert_level,
                'unit' => $i->unit,
                'kind' => $i->kind,
            ])->all(),
        ];
    }

    private function reservations(): array
    {
        $rows = $this->branchScope(DB::table('reservations as r'), 'r.branch_id')
            ->where(function ($q) {
                foreach ($this->today as $id => $day) {
                    $q->orWhere(fn ($q) => $q->where('r.branch_id', $id)->whereDate('r.reserved_at', CarbonImmutable::parse($day)->toDateString()));
                }
            })
            ->whereIn('r.status', [...array_map(fn ($s) => $s->value, ReservationStatus::upcoming()), ReservationStatus::Seated->value])
            ->leftJoin('tables as t', 't.id', '=', 'r.table_id')
            ->select('r.guest_name', 'r.party_size', 'r.reserved_at', 'r.status', 't.name as table_name')
            ->orderBy('r.reserved_at')
            ->get();

        $upcoming = $rows->filter(fn ($r) => in_array($r->status, array_map(fn ($s) => $s->value, ReservationStatus::upcoming()), true));

        return [
            'today' => $rows->count(),
            'guests' => (int) $rows->sum('party_size'),
            'next' => $upcoming->take(4)->map(fn ($r) => [
                'guest' => $r->guest_name,
                'party' => (int) $r->party_size,
                'time' => substr((string) $r->reserved_at, 11, 5),
                'table' => $r->table_name,
            ])->values()->all(),
        ];
    }

    private function topItems(): array
    {
        return DB::table('order_items')
            ->joinSub($this->salesOrders()->select('orders.id'), 'o', 'o.id', '=', 'order_items.order_id')
            ->whereNull('order_items.voided_at')
            ->whereNull('order_items.parent_order_item_id')
            ->selectRaw('order_items.item_name as name, sum(order_items.quantity) as qty, sum(order_items.line_total) as total')
            ->groupBy('order_items.item_name')
            ->orderByDesc('qty')
            ->limit(6)
            ->get()
            ->map(fn ($r) => ['name' => $r->name, 'qty' => (int) $r->qty, 'total' => (float) $r->total])
            ->all();
    }

    /** Sales by hour today (branch time), from the first to the last busy hour. */
    private function hourly(): array
    {
        $offset = CarbonImmutable::now(setting('general.timezone', $this->branches->first()->id))->format('P');
        $rows = $this->salesOrders()->whereNotNull('placed_at')
            ->selectRaw("HOUR(CONVERT_TZ(placed_at, '+00:00', ?)) as h, count(*) as n, sum(grand_total) as total", [$offset])
            ->groupBy('h')
            ->pluck('total', 'h');

        if ($rows->isEmpty()) {
            return [];
        }

        $cutoff = (int) substr((string) setting('orders.business_day_cutoff', $this->branches->first()->id), 0, 2);
        $order = fn (int $h) => ($h - $cutoff + 24) % 24; // business-day order: e.g. 05:00 … 04:00
        $hours = $rows->keys()->map(fn ($h) => (int) $h)->sortBy($order)->values();

        $out = [];
        for ($i = $order($hours->first()); $i <= $order($hours->last()); $i++) {
            $h = ($i + $cutoff) % 24;
            $out[] = ['label' => sprintf('%02d', $h), 'value' => round((float) ($rows[$h] ?? 0), 2)];
        }

        return $out;
    }

    /** The last 7 business days (today included). */
    private function week(): array
    {
        $days = [];
        for ($ago = 6; $ago >= 0; $ago--) {
            $total = (float) $this->salesOrders($ago)->sum('grand_total');
            $date = CarbonImmutable::parse($this->today[$this->branches->first()->id])->subDays($ago);
            $days[] = ['label' => $date->format('D'), 'date' => $date->toDateString(), 'value' => round($total, 2)];
        }

        return $days;
    }

    private function activity(): array
    {
        $tz = setting('general.timezone', $this->branches->first()->id);

        return $this->branchScope(DB::table('activity_logs as l'), 'l.branch_id')
            ->leftJoin('admins as a', 'a.id', '=', 'l.admin_id')
            ->select('l.event', 'l.subject_label', 'l.description', 'l.created_at', 'a.name as admin')
            ->orderByDesc('l.id')
            ->limit(8)
            ->get()
            ->map(fn ($l) => [
                'text' => trim(str(str_replace('_', ' ', $l->event))->ucfirst().($l->subject_label ? ' — '.$l->subject_label : '')),
                'time' => CarbonImmutable::parse($l->created_at, 'UTC')->setTimezone($tz)->format('H:i').($l->admin ? " · {$l->admin}" : ''),
                'tone' => match (true) {
                    str_contains($l->event, 'void'), str_contains($l->event, 'cancel'), str_contains($l->event, 'refund'), str_contains($l->event, 'trash') => 'light',
                    str_contains($l->event, 'order'), str_contains($l->event, 'payment'), str_contains($l->event, 'paid') => 'accent',
                    default => 'neutral',
                },
            ])
            ->all();
    }
}
