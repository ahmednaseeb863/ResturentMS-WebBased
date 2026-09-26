<?php

namespace App\Support;

use App\Enums\CashMovementType;
use App\Models\CashMovement;
use App\Models\Shift;

/**
 * What should be in a shift's drawer (PLAN §6 "Expected cash"):
 *
 *   expected = opening cash + cash in + rider settlements
 *            − cash out − paid-out expenses − supplier payments − safe drops
 *
 * Cash sales and cash refunds join these lines when payments arrive (Phase 10).
 * Always calculated on the server; the X / Z reports and the close use the same lines.
 */
class ShiftSummary
{
    /** @param array<string, array{total: float, count: int}> $movements by CashMovementType value */
    private function __construct(public readonly Shift $shift, private readonly array $movements) {}

    public static function of(Shift $shift): self
    {
        $movements = CashMovement::query()->withoutGlobalScope('branch')
            ->where('shift_id', $shift->id)
            ->toBase()
            ->selectRaw('type, sum(amount) as total, count(*) as n')
            ->groupBy('type')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->type => ['total' => (float) $row->total, 'count' => (int) $row->n]])
            ->all();

        return new self($shift, $movements);
    }

    /**
     * Drawer lines with signed amounts. Hand-entered types are always listed; the others
     * only when something was recorded.
     *
     * @return list<array{key: string, label: string, amount: float, count: int}>
     */
    public function lines(): array
    {
        $lines = [['key' => 'opening', 'label' => 'Opening cash', 'amount' => (float) $this->shift->opening_cash, 'count' => 0]];

        foreach (CashMovementType::cases() as $type) {
            $row = $this->movements[$type->value] ?? ['total' => 0.0, 'count' => 0];

            if ($type->isManual() || $row['count'] > 0) {
                $lines[] = [
                    'key' => $type->value,
                    'label' => $type->label(),
                    'amount' => round($type->direction() * $row['total'], 2),
                    'count' => $row['count'],
                ];
            }
        }

        return $lines;
    }

    public function total(CashMovementType $type): float
    {
        return $this->movements[$type->value]['total'] ?? 0.0;
    }

    public function expectedCash(): float
    {
        return round(array_sum(array_column($this->lines(), 'amount')), 2);
    }
}
