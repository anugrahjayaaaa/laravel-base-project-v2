<?php

namespace Database\Factories;

use App\Models\Member;
use App\Models\MemberPointHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MemberPointHistory> */
class MemberPointHistoryFactory extends Factory
{
    protected $model = MemberPointHistory::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'member_id' => Member::factory(),
            'delta' => fake()->numberBetween(1, 500),
            'balance_after' => fake()->numberBetween(1, 500),
            'reason' => 'Manual adjustment',
            'actor_id' => null,
            'reference_type' => null,
            'reference_id' => null,
        ];
    }
}
