<?php

namespace Database\Factories\Core;

use App\Models\Core\Branch;
use App\Models\Core\Role;
use App\Models\Core\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'type' => User::TYPE_STAFF,
            'name' => fake()->name(),
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '2557'.fake()->unique()->numerify('########'),
            'password' => 'Password123',
            'branch_id' => Branch::factory(),
            'is_active' => true,
        ];
    }

    public function role(string $slug): static
    {
        return $this->state(fn () => ['role_id' => Role::query()->where('slug', $slug)->value('id')]);
    }

    public function customer(): static
    {
        return $this->state(fn () => ['type' => User::TYPE_CUSTOMER, 'branch_id' => null, 'role_id' => null]);
    }
}
