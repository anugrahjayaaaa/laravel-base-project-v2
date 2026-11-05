<?php

namespace Database\Factories;

use App\Models\Member;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Member> */
class MemberFactory extends Factory
{
    protected $model = Member::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'member_number' => 'MBR-'.fake()->unique()->numerify('######'),
            'points_balance' => 0,
            'joined_at' => now(),
            'notes' => null,
        ];
    }
}
