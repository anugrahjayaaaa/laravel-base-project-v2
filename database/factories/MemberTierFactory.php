<?php

namespace Database\Factories;

use App\Models\MemberTier;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MemberTier> */
class MemberTierFactory extends Factory
{
    protected $model = MemberTier::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => ucwords($name),
            'slug' => $name,
            'role_name' => null,
            'price' => fake()->randomFloat(2, 0, 1_000_000),
            'duration_days' => fake()->randomElement([30, 90, 180, 365]),
            'point_multiplier' => fake()->randomElement(['1.00', '1.50', '2.00']),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
