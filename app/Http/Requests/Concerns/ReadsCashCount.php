<?php

namespace App\Http\Requests\Concerns;

use App\Models\Shift;
use Illuminate\Validation\Validator;

/**
 * A drawer count sent as `count: [{ denomination, quantity }]` (the denominations of the
 * setting `shifts.denominations`). The total is always worked out here, never trusted.
 */
trait ReadsCashCount
{
    protected function countRules(): array
    {
        return [
            'count' => ['nullable', 'array', 'max:30'],
            'count.*.denomination' => ['required', 'numeric', 'min:0.01'],
            'count.*.quantity' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ];
    }

    protected function checkDenominations(Validator $validator): void
    {
        $allowed = Shift::denominations();

        foreach ($this->input('count', []) as $i => $line) {
            if (! in_array((float) ($line['denomination'] ?? 0), $allowed, true)) {
                $validator->errors()->add("count.{$i}.denomination", 'Unknown note or coin.');
            }
        }
    }

    /** Was anything counted note by note? */
    public function hasCount(): bool
    {
        return collect($this->input('count', []))->sum(fn ($line) => (int) ($line['quantity'] ?? 0)) > 0;
    }

    /** @return array<string, int> denomination => quantity (quantities above 0) */
    public function countMap(): array
    {
        return collect($this->input('count', []))
            ->filter(fn ($line) => (int) ($line['quantity'] ?? 0) > 0)
            ->mapWithKeys(fn ($line) => [(string) (float) $line['denomination'] => (int) $line['quantity']])
            ->all();
    }

    public function countTotal(): float
    {
        return round(collect($this->countMap())->sum(fn (int $qty, string $denomination) => (float) $denomination * $qty), 2);
    }
}
