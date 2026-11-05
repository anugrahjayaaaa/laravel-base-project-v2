<?php

namespace Database\Seeders;

use App\Models\MemberTier;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * The three tiers a fresh install starts with (Phase 18).
 *
 * Prices are stored as bare numbers — the currency they are read in is the
 * `currency_code` setting, not a column here, so changing the app currency
 * re-labels every tier at once instead of leaving half of them behind.
 *
 * `role_name` is null for all three: a tier role is applied on activation, and
 * `member` — the only role that makes sense here — is already on the account by
 * the time a subscription exists, so writing it would sync nothing.
 */
class MemberTierSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        foreach ($this->tiers() as $attributes) {
            // `withTrashed()`, not the default scope: a tier an operator deleted
            // still occupies its unique slug, so `updateOrCreate` would try to
            // INSERT a duplicate. Re-seeding brings the defaults back — which is
            // what a defaults seeder is for.
            $tier = MemberTier::withTrashed()->firstOrNew(['slug' => $attributes['slug']]);

            $tier->fill($attributes)->save();
            $tier->restore();
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function tiers(): array
    {
        return [
            ['name' => 'Bronze', 'slug' => 'bronze', 'role_name' => null, 'price' => 35000, 'duration_days' => 30, 'point_multiplier' => '1.00', 'is_active' => true, 'sort_order' => 1],
            ['name' => 'Silver', 'slug' => 'silver', 'role_name' => null, 'price' => 70000, 'duration_days' => 60, 'point_multiplier' => '1.50', 'is_active' => true, 'sort_order' => 2],
            ['name' => 'Gold', 'slug' => 'gold', 'role_name' => null, 'price' => 100000, 'duration_days' => 100, 'point_multiplier' => '2.00', 'is_active' => true, 'sort_order' => 3],
        ];
    }
}
