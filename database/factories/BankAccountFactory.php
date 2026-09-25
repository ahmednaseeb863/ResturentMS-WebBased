<?php

namespace Database\Factories;

use App\Models\BankAccount;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BankAccount> */
class BankAccountFactory extends Factory
{
    protected $model = BankAccount::class;

    public function definition(): array
    {
        return [
            'bank_name' => fake()->randomElement(['Meezan Bank', 'HBL', 'UBL', 'Bank Alfalah', 'MCB']),
            'account_title' => fake()->company(),
            'account_number' => fake()->unique()->numerify('##############'),
            'is_active' => true,
            'show_on_receipt' => false,
        ];
    }

    /** Available at these branches. */
    public function forBranches(Branch ...$branches): static
    {
        return $this->afterCreating(fn (BankAccount $account) => $account->syncBranches(array_map(fn (Branch $b) => $b->id, $branches)));
    }
}
