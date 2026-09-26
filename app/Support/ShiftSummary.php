<?php

namespace App\Support;

use App\Enums\CashMovementType;
use App\Enums\PaymentMethod;
use App\Models\CashMovement;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Shift;

/**
 * What should be in a shift's drawer (PLAN §6 "Expected cash"):
 *
 *   expected = opening cash + cash sales − cash refunds + cash in + rider settlements
 *            − cash out − paid-out expenses − supplier payments − safe drops
 *
 * Cash sales are the cash payments taken in the shift (change already given back); bank
 * transfers are listed per account, not counted in the drawer. Always calculated on the
 * server; the X / Z reports and the close use the same lines.
 */
class ShiftSummary
{
    /**
     * @param  array<string, array{total: float, count: int}>  $movements  by CashMovementType value
     * @param  array<string, array{total: float, count: int}>  $payments  by PaymentMethod value
     * @param  array<string, array{total: float, count: int}>  $refunds  by PaymentMethod value
     */
    private function __construct(
        public readonly Shift $shift,
        private readonly array $movements,
        private readonly array $payments,
        private readonly array $refunds,
    ) {}

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

        $byMethod = fn (string $model) => $model::query()->withoutGlobalScope('branch')
            ->where('shift_id', $shift->id)
            ->toBase()
            ->selectRaw('method, sum(amount) as total, count(*) as n')
            ->groupBy('method')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->method => ['total' => (float) $row->total, 'count' => (int) $row->n]])
            ->all();

        return new self($shift, $movements, $byMethod(Payment::class), $byMethod(Refund::class));
    }

    /**
     * Drawer lines with signed amounts. Hand-entered types are always listed; the others
     * only when something was recorded.
     *
     * @return list<array{key: string, label: string, amount: float, count: int}>
     */
    public function lines(): array
    {
        $cash = PaymentMethod::Cash->value;
        $sales = $this->payments[$cash] ?? ['total' => 0.0, 'count' => 0];
        $refunds = $this->refunds[$cash] ?? ['total' => 0.0, 'count' => 0];

        $lines = [
            ['key' => 'opening', 'label' => 'Opening cash', 'amount' => (float) $this->shift->opening_cash, 'count' => 0],
            ['key' => 'cash_sales', 'label' => 'Cash sales', 'amount' => round($sales['total'], 2), 'count' => $sales['count']],
        ];
        if ($refunds['count'] > 0) {
            $lines[] = ['key' => 'cash_refunds', 'label' => 'Cash refunds', 'amount' => -round($refunds['total'], 2), 'count' => $refunds['count']];
        }

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

    /** Payments taken in the shift by method (cash and transfers), before refunds. */
    public function paymentsBy(PaymentMethod $method): array
    {
        return $this->payments[$method->value] ?? ['total' => 0.0, 'count' => 0];
    }

    public function refundsBy(PaymentMethod $method): array
    {
        return $this->refunds[$method->value] ?? ['total' => 0.0, 'count' => 0];
    }

    /**
     * Bank transfers received in the shift, per account (Z-report; not in the drawer).
     *
     * @return list<array{account: string, total: float, count: int}>
     */
    public function transfers(): array
    {
        return Payment::query()->withoutGlobalScope('branch')
            ->where('shift_id', $this->shift->id)
            ->where('method', PaymentMethod::BankTransfer)
            ->with('bankAccount')
            ->get()
            ->groupBy('bank_account_id')
            ->map(fn ($payments) => [
                'account' => $payments->first()->bankAccount?->trashLabel() ?? 'Bank',
                'total' => round($payments->sum(fn (Payment $p) => (float) $p->amount), 2),
                'count' => $payments->count(),
            ])
            ->values()
            ->all();
    }

    public function expectedCash(): float
    {
        return round(array_sum(array_column($this->lines(), 'amount')), 2);
    }
}
