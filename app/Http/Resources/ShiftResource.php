<?php

namespace App\Http\Resources;

use App\Models\Admin;
use App\Models\Shift;
use App\Support\ShiftSummary;
use Illuminate\Http\Request;

/**
 * A shift. Load `counter`, `type`, `openedBy`, `closedBy` (+ `approvedBy`, `reopenedBy` on
 * the detail page). An open shift carries its live expected cash — hidden from the cashier
 * when blind close is on (shift managers still see it).
 */
class ShiftResource extends Resource
{
    public function toArray(Request $request): array
    {
        /** @var Shift $shift */
        $shift = $this->resource;
        $showCash = static::cashVisible($shift, $request->user('admin'));
        $end = $shift->scheduledEnd();

        return [
            'id' => $shift->uuid,
            'code' => $shift->code(),
            'status' => ['value' => $shift->status->value, 'label' => $shift->status->label()],
            'is_open' => $shift->isOpen(),
            'business_date' => $shift->business_date->toDateString(),
            'counter' => $this->ref('counter'),
            'type' => $shift->type ? [
                'id' => $shift->type->uuid,
                'name' => $shift->type->name,
                'hours' => $shift->type->startsAt().'–'.$shift->type->endsAt(),
            ] : null,
            'opened_by' => $this->ref('openedBy'),
            'opened_at' => static::iso($shift->opened_at),
            'opening_cash' => $shift->opening_cash,
            'notes' => $shift->notes,
            'scheduled_end' => static::iso($end),
            'is_overdue' => $shift->isOverdue(),
            'is_mine' => $shift->opened_by === $request->user('admin')?->id,
            'cash_visible' => $showCash,
            'expected_cash' => ! $showCash ? null : ($shift->isOpen() ? (string) ShiftSummary::of($shift)->expectedCash() : $shift->expected_cash),
            'closed_by' => $this->ref('closedBy'),
            'closed_at' => static::iso($shift->closed_at),
            'counted_cash' => $shift->counted_cash,
            'difference' => $shift->difference,
            'float_left' => $shift->float_left,
            'handed_over_amount' => $shift->handed_over_amount,
            'closing_notes' => $shift->closing_notes,
            'approved_by' => $this->ref('approvedBy'),
            'reopened_by' => $this->ref('reopenedBy'),
            'reopened_at' => static::iso($shift->reopened_at),
            'reopen_count' => $shift->reopen_count,
        ];
    }

    /** Blind close hides the expected cash of an open shift from everyone but shift managers. */
    public static function cashVisible(Shift $shift, ?Admin $admin): bool
    {
        return ! $shift->isOpen()
            || ! setting('shifts.blind_close', $shift->branch_id)
            || (bool) $admin?->canRoute(Shift::MANAGER_ROUTE);
    }
}
