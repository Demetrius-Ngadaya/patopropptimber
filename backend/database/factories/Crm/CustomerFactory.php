<?php

namespace Database\Factories\Crm;

use App\Models\Crm\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Customer> */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'code' => 'CUS-'.fake()->unique()->numerify('######'),
            'name' => fake()->name(),
            'phone' => '2557'.fake()->unique()->numerify('########'),
            'is_active' => true,
            'allow_credit' => false,
            'credit_limit' => 0,
        ];
    }

    public function withCredit(float $limit = 0): static
    {
        return $this->state(fn () => ['allow_credit' => true, 'credit_limit' => $limit]);
    }
}
