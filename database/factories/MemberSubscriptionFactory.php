<?php

namespace Database\Factories;

use App\Models\Member;
use App\Models\MemberSubscription;
use App\Models\MemberTier;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MemberSubscription> */
class MemberSubscriptionFactory extends Factory
{
    protected $model = MemberSubscription::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $startsAt = now()->subDays(fake()->numberBetween(1, 300));

        return [
            'member_id' => Member::factory(),
            'member_tier_id' => MemberTier::factory(),
            'status' => MemberSubscription::STATUS_ACTIVE,
            'starts_at' => $startsAt,
            'expires_at' => $startsAt->copy()->addYear(),
            'role_name' => null,
        ];
    }

    /** A subscription whose window has passed. */
    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => MemberSubscription::STATUS_EXPIRED,
            'expires_at' => now()->subDay(),
        ]);
    }

    /** A subscription still inside its window. */
    public function live(): static
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->addYear(),
        ]);
    }
}
