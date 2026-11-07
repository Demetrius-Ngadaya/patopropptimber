<?php

namespace Database\Factories\Core;

use App\Models\Core\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Branch> */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    public function definition(): array
    {
        return [
            'name' => fake()->city().' Branch',
            'code' => strtoupper(fake()->unique()->bothify('BR###')),
            'location' => fake()->city(),
            'is_active' => true,
            'accepts_online_orders' => true,
        ];
    }
}
