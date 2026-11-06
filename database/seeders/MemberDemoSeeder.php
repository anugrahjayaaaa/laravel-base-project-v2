<?php

namespace Database\Seeders;

use App\Models\Member;
use App\Models\MemberSubscription;
use App\Models\MemberTier;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Ten known member accounts for manual screens (member1…member10).
 *
 * Deliberately NOT wired into DatabaseSeeder — demo rows must never land in a
 * production seed. Run after RoleSeeder + PermissionSeeder + SystemSettingSeeder:
 *
 *   php artisan db:seed --class=Database\Seeders\MemberDemoSeeder
 *
 * The layout is fixed so each dashboard state is always on screen:
 * member1-3 active (period far away), member4-6 inactive (period expired),
 * member7-8 expiring inside 14 days, member9-10 inside 7 days. Tiers are
 * random among the seeded defaults; passwords are the shared dev password.
 */
class MemberDemoSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        (new MemberTierSeeder())->run();

        $tierIds = MemberTier::query()->pluck('id')->all();

        for ($i = 1; $i <= 10; $i++) {
            $user = User::query()->firstOrNew(['email' => "member{$i}@member.com"]);
            $user->fill([
                'name' => "Member {$i}",
                'username' => "member{$i}",
                'password' => Hash::make('#Password123'),
                'email_verified_at' => now(),
                'is_active' => ! ($i >= 4 && $i <= 6),
            ])->save();

            $user->syncRoles(['member']);

            // firstOrNew + "create a period only when none exists" keeps this
            // re-runnable after a partial run: it repairs, never duplicates.
            $member = Member::query()->firstOrNew(['user_id' => $user->id]);

            if (! $member->exists) {
                $member->fill([
                    'member_number' => Member::nextMemberNumber(),
                    'points_balance' => 0,
                    'joined_at' => now()->subDays(30 * $i),
                    'is_active' => ! ($i >= 4 && $i <= 6),
                ])->save();
            }

            if ($member->subscriptions()->exists()) {
                continue;
            }

            $expiresAt = match (true) {
                $i >= 4 && $i <= 6 => now()->subDays(rand(1, 30)),
                $i >= 7 && $i <= 8 => now()->addDays(rand(8, 13)),
                $i >= 9 && $i <= 10 => now()->addDays(rand(1, 6)),
                default => now()->addDays(rand(30, 300)),
            };

            MemberSubscription::query()->create([
                'member_id' => $member->id,
                'member_tier_id' => $tierIds[array_rand($tierIds)],
                'status' => $i >= 4 && $i <= 6
                    ? MemberSubscription::STATUS_EXPIRED
                    : MemberSubscription::STATUS_ACTIVE,
                'starts_at' => $expiresAt->copy()->subDays(30),
                'expires_at' => $expiresAt,
            ]);
        }
    }
}
